<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver;

use DateInterval;
use DateInvalidOperationException;
use DateMalformedStringException;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Revolt\EventLoop;
use Webrtc\Codecs\Codec;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\Codecs\CodecUtility;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\RTCP\Exception\RtcpExceptionInterface;
use Webrtc\RTCP\RtcpByePacket;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPacketInterface;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpReceiverInfo;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTCP\RtcpSrPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Jitter\JitterBuffer;
use Webrtc\RTP\Jitter\JitterFrame;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\RTP\Receiver\Rate\RemoteBitrateEstimator;
use Webrtc\RTP\RTCRTPDtlsTransportInterface;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpCapabilities;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpSynchronizationSource;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCInboundRtpStreamStats;
use Webrtc\Stats\RTCRemoteOutboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;

/**
 * RTCRtpReceiver is responsible for receiving and processing RTP media streams,
 * including decoding, statistics gathering, handling RTCP feedback, and managing jitter buffers.
 *
 * This class plays a critical role in the WebRTC stack, managing the flow of audio or video media
 * from the DTLS transport layer to the remote MediaStreamTrack.
 */
final class RTCRtpReceiver implements RtpReceiverInterface

{
    private bool $enabled = true;
    /** @var array<int, DateTimeImmutable> */
    private array $activeSsrc = [];
    /** @var array<int, RTCRtpCodecParameters> */
    private array $codecs = [];
    private ?JitterBuffer $jitterBuffer;
    private ?NackGenerator $nackGenerator;
    private ?RemoteBitrateEstimator $remoteBitrateEstimator;
    private ?RemoteStreamTrack $track = null;
    /** @var array<int, int> */
    private array $rtxSsrc = [];
    private bool $started = false;
    private RTCStatsReport $stats;
    private TimestampMapper $timestampMapper;
    /** @var array<int, int> */
    private array $lsr = [];
    /** @var array<int, float> */
    private array $lsrTime = [];
    /** @var StreamStatistics[] */
    private array $remoteStreams = [];
    private ?int $rtcpSsrc = null;
    private ?LoggerInterface $logger = null;
    private ?DecoderQueue $decoderQueue = null;

    /**
     * Whether assembled frames are delivered still encoded, instead of being decoded to raw media.
     */
    private bool $rawMode = false;
    private string $rtcpTask = "";

    /**
     * Constructor for RTCRtpReceiver.
     *
     * Initializes jitter buffers, optional NACK generator and bitrate estimator,
     * statistics collector, and transport handling.
     *
     * @param MediaKind $kind The kind of media being received (audio or video).
     * @param RTCRTPDtlsTransportInterface $transport The DTLS transport used for secure media delivery.
     * @throws InvalidArgumentException If the DTLS transport is closed.
     */
    public function __construct(private readonly MediaKind $kind, private RTCRTPDtlsTransportInterface $transport)
    {
        if ($transport->getState() === TLSState::CLOSED) {
            throw new InvalidArgumentException("Transport is closed");
        }
        $this->jitterBuffer = $kind === MediaKind::Audio ? new JitterBuffer(16, 4) : new JitterBuffer(128, 0, true);
        $this->nackGenerator = $kind === MediaKind::Video ? new NackGenerator() : null;
        $this->remoteBitrateEstimator = $kind === MediaKind::Video ? new RemoteBitrateEstimator() : null;
        $this->stats = new RTCStatsReport();
        $this->timestampMapper = new TimestampMapper();
    }

    /**
     * Retrieves RTP capabilities supported for the given media kind.
     *
     * @param string $kind 'audio' or 'video'.
     * @return RTCRtpCapabilities|null The RTP capabilities or null if unavailable.
     */
    public static function getCapabilities(string $kind): ?RTCRtpCapabilities

    {
        $codec = new Codec();
        return $codec->getCapabilities($kind);
    }

    /**
     * Gets the remote MediaStreamTrack associated with the receiver.
     *
     * @return ?MediaStreamTrack The media track or null if not set.
     */
    /**
     * Deliver assembled frames without decoding them.
     *
     * Must be set before {@see self::start()}. In this mode the receiver never instantiates a
     * decoder, so no native codec library is required.
     */
    public function setRawMode(bool $rawMode): void
    {
        $this->rawMode = $rawMode;
    }

    /**
     * Whether assembled frames are delivered still encoded.
     */
    public function isRawMode(): bool
    {
        return $this->rawMode;
    }

    public function getTrack(): ?MediaStreamTrack
    {
        return $this->track;
    }

    /**
     * Gets the RTCDtlsTransport used by this receiver.
     *
     * @return RTCRTPDtlsTransportInterface The transport instance.
     */
    public function getTransport(): RTCRTPDtlsTransportInterface
    {
        return $this->transport;
    }

    /**
     * Collects and returns a statistics report for the current inbound RTP stream.
     *
     * @return RTCStatsReport A report containing inbound RTP and transport statistics.
     */
    public function getStats(): RTCStatsReport
    {
        foreach ($this->remoteStreams as $ssrc => $stream) {
            $this->stats->add(new RTCInboundRtpStreamStats(
                id: "inbound_rtp_stream_" . spl_object_id($this),
                ssrc: (int) $ssrc,
                kind: $this->kind->value,
                transportId: $this->transport->getReportTransport()->id,
                packetsReceived: $stream->getPacketsReceived(),
                packetsLost: $stream->getPacketsLost(),
                jitter: $stream->getJitter()
            ));
        }
        $this->stats->add($this->transport->getReportTransport());

        return $this->stats;
    }

    /**
     * Gets a list of synchronization sources (SSRCs) that have been active within the last 10 seconds.
     *
     * @return RTCRtpSynchronizationSource[] An array of synchronization source entries.
     * @throws DateMalformedStringException
     * @throws DateInvalidOperationException
     */
    public function getSynchronizationSources(): array
    {
        $cutoff = NetworkTimeProtocol::currentDateTime()->sub(new DateInterval('PT10S'));
        $sources = [];
        foreach ($this->activeSsrc as $source => $timestamp) {
            if ($timestamp >= $cutoff) {
                $sources[] = new RTCRtpSynchronizationSource($timestamp, $source);
            }
        }
        return $sources;
    }

    /**
     * Starts the RTP receiver with the provided parameters.
     *
     * This includes codec setup, RTX mapping, transport binding, and RTCP reporting.
     *
     * @param RTCRtpReceiveParameters $parameters The receiver's codec and encoding parameters.
     * @throws RandomException
     */
    public function start(RTCRtpReceiveParameters $parameters): void
    {
        $this->codecs = [];
        $this->rtxSsrc = [];
        foreach ($parameters->codecs as $codec) {
            if ($codec->payloadType === null) {
                continue;
            }
            $this->codecs[$codec->payloadType] = $codec;
        }

        foreach ($parameters->encodings as $encoding) {
            if ($encoding->rtx) {
                $this->rtxSsrc[$encoding->rtx->ssrc] = $encoding->ssrc;
            }
        }

        $this->transport->setRtpReceiver($this, $parameters);
        if ($this->started) {
            $this->decoderQueue?->stop();
            $this->decoderQueue = null;
            $this->logger?->debug(" RTP Receiver reconfigured");
            return;
        }
        $this->runRtcp();
        $this->started = true;
    }

    /**
     * Updates the transport used by the receiver.
     *
     * @param RTCRTPDtlsTransportInterface $transport The new transport.
     */
    public function setTransport(RTCRTPDtlsTransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Stops the receiver and cleans up associated resources.
     *
     * Cancels the RTCP timer and removes the receiver from the transport.
     */
    #[\Override]
    public function stop(): void

    {
        if (!$this->started) {
            return;
        }
        $this->transport->removeRtpReceiver($this);

        // Cancel RTCP periodic task
        EventLoop::cancel($this->rtcpTask);
        $this->logger?->debug(" RTCP has ended.");
        $this->finishUpRtp();
    }

    /**
     * Handles incoming RTCP packets and delegates to appropriate handlers.
     *
     * @param RtcpPacketInterface $packet The RTCP packet to handle.
     * @throws DateMalformedStringException
     */
    #[\Override]
    public function handleRtcpPacket(RtcpPacketInterface $packet): void
    {
        $this->logger?->debug("Received RTCP packet: " . (string) $packet);

        if ($packet instanceof RtcpSrPacket) {
            $this->handleRtcpSrPacket($packet);
        } elseif ($packet instanceof RtcpByePacket) {
            $this->stop();
        }
    }

    /**
     * Handle the reception of an RTCP SR packet.
     *
     * @param RtcpSrPacket $packet The RTCP SR packet to handle.
     * @throws DateMalformedStringException
     */
    private function handleRtcpSrPacket(RtcpSrPacket $packet): void
    {
        $senderInfo = $packet->getSenderInfo();
        $ntpHigh = $senderInfo->getNtpTimestampHigh();
        $ntpLow = $senderInfo->getNtpTimestampLow();

        // The LSR field carries the middle 32 bits of the raw 64-bit NTP timestamp.
        $ntpTimestamp = (($ntpHigh & 0xFFFF) << 16) | ($ntpLow >> 16);

        $this->stats->add(
            new RTCRemoteOutboundRtpStreamStats(
            // RTCStats
                id: "remote_outbound_rtp_stream_" . spl_object_id($this),
                // RTCStreamStats
                ssrc: $packet->getSsrc(),
                kind: $this->kind->value,
                transportId: $this->transport->getReportTransport()->id,
                // RTCSentRtpStreamStats
                packetsSent: $packet->getSenderInfo()->getPacketCount(),
                bytesSent: $packet->getSenderInfo()->getOctetCount(),
                // RTCRemoteOutboundRtpStreamStats
                remoteTimestamp: NetworkTimeProtocol::toDatetime(
                    $ntpHigh,
                    $ntpLow,
                ),
            )
        );


        $this->lsr[$packet->getSsrc()] = $ntpTimestamp;
        $this->lsrTime[$packet->getSsrc()] = microtime(true);
    }

    /**
     * Handles incoming RTP packets and processes them through decoding, jitter buffering,
     * retransmission handling, and statistics collection.
     *
     * @param RtpPacket $packet The RTP packet received.
     * @param int $arrivalTimeMs Time in milliseconds when the packet arrived.
     * @throws DateMalformedStringException
     */
    #[\Override]
    public function handleRtpPacket(RtpPacket $packet, int $arrivalTimeMs): void
    {
        $this->logger?->debug("Received RTP packet: " . (string) $packet);

        if (!$this->enabled) {
            return;
        }

        $this->feedBitrateEstimator($packet, $arrivalTimeMs);
        $this->trackActiveSource($packet);
        $codec = $this->validateCodec($packet);
        if (!$codec) return;

        $this->updateRtcpStatistics($packet, $codec);
        $packet = $this->handleRetransmission($packet, $codec);
        if (!$packet) return;

        $this->handleMissingPackets($packet);
        $decoded = $this->parsePayload($packet, $codec);
        if (!$decoded) return;

        $this->processEncodedFrame($packet, $codec);
    }

    /**
     * Feeds bitrate estimator with packet metadata to help calculate receive bitrate.
     *
     * @param RtpPacket $packet The RTP packet.
     * @param int $arrivalTimeMs Time in milliseconds of arrival.
     */
    private function feedBitrateEstimator(RtpPacket $packet, int $arrivalTimeMs): void
    {
        if ($this->remoteBitrateEstimator !== null && $packet->getExtensions()->getAbsSendTime() !== null) {
            $remb = $this->remoteBitrateEstimator->add(
                $packet->getExtensions()->getAbsSendTime(),
                $arrivalTimeMs,
                strlen($packet->getPayload()) + $packet->getPaddingSize(),
                $packet->getSsrc()
            );

            if ($this->rtcpSsrc !== null && $remb !== null) {
                /** @var array{0: int, 1: int[]} $remb */
                $rtcpPacket = new RtcpPsfbPacket(
                    fmt: RtcpConstants::RTCP_PSFB_APP,
                    ssrc: $this->rtcpSsrc,
                    mediaSsrc: 0,
                    fci: RtpUtility::packRembFci($remb[0], $remb[1])
                );

                $this->sendRtcp($rtcpPacket);
            }
        }
    }

    /**
     * Tracks which synchronization source (SSRC) was active most recently.
     *
     * @param RtpPacket $packet The incoming RTP packet.
     * @throws DateMalformedStringException
     */
    private function trackActiveSource(RtpPacket $packet): void
    {
        $this->activeSsrc[$packet->getSsrc()] = NetworkTimeProtocol::currentDatetime();
    }

    /**
     * Validates the codec based on the RTP payload type.
     *
     * @param RtpPacket $packet The RTP packet.
     * @return RTCRtpCodecParameters|null Codec parameters or null if unrecognized.
     */
    private function validateCodec(RtpPacket $packet): ?RTCRtpCodecParameters
    {
        if (!isset($this->codecs[$packet->getPayloadType()])) {
            $this->logger?->debug(sprintf("x RTP packet with unknown payload type %d", $packet->getPayloadType()));
            return null;
        }
        return $this->codecs[$packet->getPayloadType()];
    }

    /**
     * Updates internal RTCP statistics based on the received RTP packet.
     *
     * @param RtpPacket $packet The RTP packet.
     * @param RTCRtpCodecParameters $codec Codec associated with the packet.
     */
    private function updateRtcpStatistics(RtpPacket $packet, RTCRtpCodecParameters $codec): void

    {
        if (!isset($this->remoteStreams[$packet->getSsrc()])) {
            $this->remoteStreams[$packet->getSsrc()] = new StreamStatistics($codec->clockRate);
        }
        $this->remoteStreams[$packet->getSsrc()]->add($packet);
    }

    /**
     * Handles RTX (Retransmission) packets and rewrites them to original format.
     *
     * @param RtpPacket $packet The RTP packet, possibly RTX.
     * @param RTCRtpCodecParameters $codec Codec of the packet.
     * @return RtpPacket|null Original packet or null if decoding failed.
     */
    private function handleRetransmission(RtpPacket $packet, RTCRtpCodecParameters $codec): ?RtpPacket
    {
        if (CodecUtility::isRtx($codec)) {
            $originalSsrc = $this->rtxSsrc[$packet->getSsrc()] ?? null;
            if ($originalSsrc === null) {
                $this->logger?->debug(sprintf("x RTX packet from unknown SSRC %d", $packet->getSsrc()));
                return null;
            }

            if (strlen($packet->getPayload()) < 2) {
                return null;
            }

            $apt = $codec->parameters["apt"] ?? null;
            if (!is_int($apt)) {
                return null;
            }
            $codec = $this->codecs[$apt];
            $packet = RtpUtility::unwrapRtx($packet, (int) $codec->payloadType, $originalSsrc);
        }

        return $packet;
    }

    /**
     * Checks and processes missing RTP packets using NACK.
     *
     * @param RtpPacket $packet The RTP packet.
     */
    private function handleMissingPackets(RtpPacket $packet): void
    {
        if ($this->nackGenerator && $this->nackGenerator->add($packet)) {
            $missing = $this->nackGenerator->getMissing();
            sort($missing);
            $this->sendRtcpNack($packet->getSsrc(), $missing);
        }
    }

    /**
     * Parses the payload of an RTP packet using the appropriate codec.
     *
     * @param RtpPacket $packet The RTP packet.
     * @param RTCRtpCodecParameters $codec Codec used to parse payload.
     * @return bool True if successfully parsed, false otherwise.
     */
    private function parsePayload(RtpPacket $packet, RTCRtpCodecParameters $codec): bool
    {
        try {
            [, $decoded] = Codec::depayload($codec, $packet->payload);
            $packet->setDecodedData((string) $decoded);
            return true;
        } catch (Exception $e) {
            $this->logger?->debug(sprintf(" x RTP payload parsing failed: %s", $e->getMessage()));
            return false;
        }
    }

    /**
     * Adds the packet to the jitter buffer and handles any PLI triggering.
     *
     * @param RtpPacket $packet The RTP packet.
     * @param RTCRtpCodecParameters $codec The codec.
     */
    private function processEncodedFrame(RtpPacket $packet, RTCRtpCodecParameters $codec): void
    {
        if ($this->jitterBuffer === null) {
            return;
        }
        [$pliFlag, $encodedFrame] = $this->jitterBuffer->add($packet);

        if ($pliFlag) {
            $this->sendRtcpPli($packet->getSsrc());
        }

        $this->decodeFrame($encodedFrame, $codec);
    }

    /**
     * Start the RTCP communication for the receiver, sending periodic packets.
     *
     * @throws RandomException
     */
    private function runRtcp(): void
    {
        $this->logger?->debug(" RTCP started");

        $this->rtcpTask = EventLoop::repeat(0.5 + ((float) random_int(0, 1000) / 1000.0), function () {
            try {
                $rtcpPackets = $this->generateRtcpRrPacket();
                if ($rtcpPackets) {
                    $this->sendRtcp($rtcpPackets);
                }
            } catch (RtcpExceptionInterface $e) {
                $this->logger?->warning(sprintf("RTCP error: %s", $e->getMessage()));
            }
        });
    }

    /**
     * Generate an RTCP RR (Receiver Report) packet with statistics.
     *
     * @return RtcpRrPacket|null The generated RTCP RR packet, or null if no report was generated.
     */
    private function generateRtcpRrPacket(): ?RtcpRrPacket
    {
        // RTCP RR
        $reports = [];
        foreach ($this->remoteStreams as $ssrc => $stream) {
            $ssrc = (int) $ssrc;
            $lsr = 0;
            $dlsr = 0;
            if (isset($this->lsr[$ssrc], $this->lsrTime[$ssrc])) {
                $lsr = $this->lsr[$ssrc];
                $delay = microtime(true) - $this->lsrTime[$ssrc];
                if ($delay > 0 && $delay < 65536) {
                    $dlsr = intval($delay * 65536.0);
                }
            }

            $reports[] = new RtcpReceiverInfo(
                $ssrc,
                $stream->getFractionLost(),
                $stream->getPacketsLost(),
                $stream->getMaxSeq() ?? 0,
                $stream->getJitter(),
                $lsr,
                $dlsr
            );
        }

        if ($this->rtcpSsrc !== null && !empty($reports)) {
            return new RtcpRrPacket($this->rtcpSsrc, $reports);
        }

        return null;
    }

    /**
     * Send an RTCP packet over the transport.
     *
     * @param RtcpPacketInterface $packet The RTCP packet to send.
     */
    private function sendRtcp(RtcpPacketInterface $packet): void
    {
        $this->logger?->debug(sprintf(" Sent Rtcp packet: %s", $packet));
        try {
            $this->transport->sendRtcp($packet->encode());
        } catch (Exception) {
        }
    }

    /**
     * Set a logger instance for logging.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $logger = new class($this->kind->value, $logger) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly string $kind, private readonly LoggerInterface $logger) {}
            #[\Override]
            public function log(mixed $level, string|\Stringable $message, array $context = array()): void {
                $this->logger->log($level, "RTCRtpReceiver($this->kind): " . (string) $message, $context);
            }
        };
        $this->logger = $logger;
    }

    /**
     * Send an RTCP NACK (Negative Acknowledgment) to report missing RTP packets.
     *
     * @param int $mediaSsrc The SSRC of the media stream.
     * @param int[] $lost An array of lost packet sequence numbers.
     */
    public function sendRtcpNack(int $mediaSsrc, array $lost): void
    {
        if ($this->rtcpSsrc !== null) {
            $packet = new RtcpRtpfbPacket(
                fmt: RtcpConstants::RTCP_RTPFB_NACK,
                ssrc: $this->rtcpSsrc,
                mediaSsrc: $mediaSsrc,
                lost: $lost
            );

            $this->sendRtcp($packet);
        }
    }

    /**
     * Set the MediaStreamTrack for the receiver.
     *
     * @param RemoteStreamTrack|null $track The MediaStreamTrack to associate with the receiver.
     */
    public function setTrack(?RemoteStreamTrack $track): void
    {
        $this->track = $track;
    }

    /**
     * Send an RTCP PLI (Picture Loss Indication) packet.
     *
     * @param int $mediaSsrc The SSRC of the media stream.
     */
    public function sendRtcpPli(int $mediaSsrc): void
    {
        if ($this->rtcpSsrc !== null) {
            $packet = new RtcpPsfbPacket(
                fmt: RtcpConstants::RTCP_PSFB_PLI,
                ssrc: $this->rtcpSsrc,
                mediaSsrc: $mediaSsrc
            );

            $this->sendRtcp($packet);
        }
    }

    /**
     * Decode the encoded frame from the jitter buffer.
     *
     * @param JitterFrame|null $encodedFrame The encoded frame to decode.
     * @param RTCRtpCodecParameters $codec The codec to use for decoding.
     */
    private function decodeFrame(?JitterFrame $encodedFrame, RTCRtpCodecParameters $codec): void
    {
        if (!$encodedFrame) {
            return;
        }

        $encodedFrame->setTimestamp($this->timestampMapper->map($encodedFrame->getTimestamp()));

        if ($this->rawMode) {
            // Hand the assembled, still-encoded frame straight to the track: this avoids loading
            // any codec at all, which is what lets calls work without the FFI extension.
            $this->track?->queueFrame(new EncodedPacket(
                $encodedFrame->getData(),
                $encodedFrame->getTimestamp(),
            ));
            return;
        }

        $track = $this->track;
        if ($track === null) {
            return;
        }

        if ($this->decoderQueue === null) {
            $this->decoderQueue = new DecoderQueue(Codec::getDecoder($codec));
            $this->decoderQueue->start($track);
        }
        $this->decoderQueue->addFrame($encodedFrame);
    }

    /**
     * Clean up and stop RTP processing.
     */
    private function finishUpRtp(): void
    {
        if ($this->track) {
            $this->track->stop();
            $this->track = null;
            $this->decoderQueue?->stop();
        }

        $this->decoderQueue = null;

        $this->logger?->debug(" RTP has ended.");
    }

    /**
     * @return int|null
     */
    public function getRtcpSsrc(): ?int
    {
        return $this->rtcpSsrc;
    }

    /**
     * @param int|null $rtcpSsrc
     * @return void
     */
    public function setRtcpSsrc(?int $rtcpSsrc): void
    {
        $this->rtcpSsrc = $rtcpSsrc;
    }

    /**
     * @return MediaKind
     */
    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    /**
     * @param bool $status
     * @return void
     */
    public function setEnabled(bool $status): void
    {
        $this->enabled = $status;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
