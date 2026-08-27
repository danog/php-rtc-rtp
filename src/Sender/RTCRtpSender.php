<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Sender;

use DateMalformedStringException;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use Revolt\EventLoop;
use Throwable;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Codecs\Codec;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\Codecs\CodecUtility;
use Webrtc\Codecs\EncoderInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\RTCP\Exception\RtcpExceptionInterface;
use Webrtc\RTCP\RtcpByePacket;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPacketInterface;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTCP\RtcpSdesPacket;
use Webrtc\RTCP\RtcpSenderInfo;
use Webrtc\RTCP\RtcpSourceInfo;
use Webrtc\RTCP\RtcpSrPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\RTCRTPDtlsTransportInterface;
use Webrtc\RTP\RtpConstants;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Stats\enum\StatType;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCOutboundRtpStreamStats;
use Webrtc\Stats\RTCRemoteInboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;

/**
 * RTCRtpSender handles the sending of media data over RTP/RTCP.
 *
 * This class is responsible for:
 * - Encoding and packetizing media frames
 * - Sending RTP packets
 * - Generating and processing RTCP packets
 * - Handling retransmissions (RTX)
 * - Collecting and reporting statistics
 * - Managing the media track and transport
 */
final class RTCRtpSender implements RtpSenderInterface
{
    /** @var float Smoothing factor for RTT calculations */
    private const RTT_ALPHA = 0.85;

    /** @var MediaKind The kind of media (e.g., "audio" or "video") */
    private MediaKind $kind;

    /** @var MediaStreamTrack|null The media track being sent */
    private ?MediaStreamTrack $track = null;

    /** @var string|null The CNAME for RTCP */
    private ?string $cname = null;

    /** @var int The SSRC for RTP */
    private int $ssrc;

    /** @var int The SSRC for RTX (retransmissions) */
    private int $rtxSsrc;

    /** @var string The stream ID */
    private string $streamId;

    /** @var bool Whether the sender is enabled */
    private bool $enabled = true;

    /**
     * Loudness, in -dBov, at or below which a packet still counts as carrying speech.
     *
     * Used to fill in the voice activity bit of the RFC 6464 audio level extension. Levels are
     * inverted, so a smaller number is louder and 127 is digital silence; -60dBov is quiet enough
     * to be inaudible in practice, which makes it a reasonable floor for a sender that has no real
     * voice activity detector.
     */
    private const SILENCE_LEVEL_DBOV = 60;

    /** @var EncoderInterface|null The encoder for media frames */
    private ?EncoderInterface $encoder = null;

    /** @var bool Whether to force the next frame to be a keyframe */
    private bool $useKeyframe = false;

    /** @var string|null The media ID (mid) for this sender */
    private ?string $mid = null;

    /** @var array<int, RtpPacket> History of sent RTP packets for retransmissions */
    private array $rtpHistory = [];

    /** @var RTCStatsReport Statistics for this sender */
    private RTCStatsReport $stats;

    /** @var float|null The last sender report timestamp */
    private ?float $lsrTime = null;

    /** @var int|null The last sender report NTP timestamp */
    private ?int $lsr = null;

    private int $ntpTimestampHigh = 0;
    private int $ntpTimestampLow = 0;

    /** @var int The RTP timestamp */
    private int $rtpTimestamp = 0;

    /** @var int The total number of octets sent */
    private int $octetCount = 0;

    /** @var int The total number of packets sent */
    private int $packetCount = 0;

    /** @var float|null The round-trip time (RTT) estimate */
    private ?float $rtt = null;

    /** @var bool Whether the sender has started */
    private bool $started = false;

    /** @var string The track ID */
    private string $trackId;

    /** @var HeaderExtensionsMap Map of RTP header extensions */
    private HeaderExtensionsMap $headerExtensionsMap;

    /** @var int|null The payload type for RTX packets */
    private ?int $rtxPayloadType = null;



    /** @var RTCRtpCodecParameters The codec parameters */
    private RTCRtpCodecParameters $codec;

    /** @var LoggerInterface|null The logger instance */
    private ?LoggerInterface $logger = null;

    /** @var string Handle of The RTCP timer task */
    private string $rtcpTask;

    /** @var int The current RTP sequence number */
    private int $sequenceNumber;

    /** @var int The current RTX sequence number */
    private int $rtxSequenceNumber;

    /** @var int The original timestamp offset */
    private int $orgTimestamp;

    /**
     * Constructs a new RTCRtpSender instance.
     *
     * @param MediaKind|MediaStreamTrack $trackOrKind Either a MediaStreamTrack or a media kind ("audio" or "video")
     * @param RTCRTPDtlsTransportInterface $transport The transport for sending media
     * @throws InvalidArgumentException If the transport is closed
     * @throws RandomException If random number generation fails
     */
    public function __construct(MediaKind|MediaStreamTrack $trackOrKind, private RTCRTPDtlsTransportInterface $transport)
    {
        if ($transport->getState() === TLSState::CLOSED) {
            throw new InvalidArgumentException("Transport is closed");
        }

        if ($trackOrKind instanceof MediaStreamTrack) {
            $this->kind = $trackOrKind->getKind();
            $this->replaceTrack($trackOrKind);
        } else {
            $this->kind = $trackOrKind;
            $this->replaceTrack(null);
        }

        $this->ssrc = random_int(0, 0xFFFFFFFF);
        $this->rtxSsrc = random_int(0, 0xFFFFFFFF);
        $this->orgTimestamp = random_int(0, 0xFFFFFFFF);
        $this->sequenceNumber = $this->generateSequenceNumber();
        $this->rtxSequenceNumber = random_int(0, 0xFFFF);
        $this->streamId = Uuid::uuid4()->toString();
        $this->stats = new RTCStatsReport();
        $this->headerExtensionsMap = new HeaderExtensionsMap();
    }

    /**
     * Gets the kind of media for this sender.
     *
     * @return MediaKind The media kind ("audio" or "video")
     */
    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    /**
     * Gets the media track being sent.
     *
     * @return MediaStreamTrack|null The media track or null if not set
     */
    public function getTrack(): ?MediaStreamTrack
    {
        return $this->track;
    }

    /**
     * Gets the transport used for sending media.
     *
     * @return RTCRTPDtlsTransportInterface The transport
     */
    public function getTransport(): RTCRTPDtlsTransportInterface
    {
        return $this->transport;
    }

    /**
     * Replaces the media track being sent.
     *
     * @param MediaStreamTrack|null $track The new media track or null to clear
     */
    public function replaceTrack(?MediaStreamTrack $track): void
    {
        $this->track = $track;
        $this->trackId = $track ? $track->getId() : Uuid::uuid4()->toString();
    }

    /**
     * Sets the transport used for sending media.
     *
     * @param RTCRTPDtlsTransportInterface $transport The new transport
     */
    public function setTransport(RTCRTPDtlsTransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Starts sending media with the given parameters.
     *
     * @param RTCRtpSendParameters $parameters The parameters for sending media
     * @throws RandomException If random number generation fails
     */
    public function start(RTCRtpSendParameters $parameters): void
    {
        if ($this->started) {
            return;
        }

        $this->cname = $parameters->rtcp->cname;
        $this->mid = $parameters->muxId;
        $this->codec = $parameters->codecs[0];
        $this->transport->setRtpSender($this, $parameters);
        $this->headerExtensionsMap->configure($parameters);

        foreach ($parameters->codecs as $codec) {
            if (CodecUtility::isRtx($codec) && CodecUtility::apt($codec) === $parameters->codecs[0]->payloadType) {
                $this->rtxPayloadType = $codec->payloadType;
                break;
            }
        }

        // Start RTP and RTCP tasks
        $this->startRtpTask();
        $this->startRtcpTask();
        $this->started = true;
        $this->logger->debug(" RTP Sender started");
    }

    /**
     * Shuts down RTP and RTCP tasks.
     *
     * @throws SrtpException If there's an error with SRTP processing
     */
    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        $this->transport->removeRtpSender($this);

        // Stop RTCP and RTP Tasks
        $this->stopRtcpTask();
        $this->stopRtpTask();
    }

    /**
     * Stops the RTCP task and sends a BYE packet.
     *
     * @throws SrtpException If there's an error with SRTP processing
     */
    private function stopRtcpTask(): void
    {
        EventLoop::cancel($this->rtcpTask);
        $byePacket = new RtcpByePacket([$this->ssrc]);
        $this->sendRtcpPacket([$byePacket]);

        $this->logger->debug(" RTCP has ended.");
    }

    /**
     * Stops the RTP task and cleans up resources.
     */
    private function stopRtpTask(): void
    {
        if ($this->track) {
            $this->track->stop();
            $this->track = null;
        }
        $this->encoder = null;
        $this->logger->debug(" RTP has ended.");
    }

    /**
     * Starts the periodic RTP packet sending a task.
     *
     * @throws RandomException
     */
    public function startRtpTask(): void
    {
        $this->logger->debug("Start RTP task");
        $this->sequenceNumber = $this->generateSequenceNumber();
        $this->orgTimestamp = random_int(0, 0xFFFFFFFF);
        EventLoop::queue(function () {
            foreach ($this->track->getConsumer() as $data) {
                // While the sender is paused (e.g. the transceiver direction dropped the
                // outgoing media, or the track was muted), drain frames off the consumer
                // without putting anything on the wire. This keeps the encoder from
                // building up a backlog that would burst out the moment we resume.
                if (!$this->enabled) {
                    continue;
                }

                $audioLevel = null;
                if ($data instanceof EncodedPacket) {
                    $audioLevel = $data->getAudioLevel();
                }
                if ($data instanceof FrameInterface) {
                    if ($data instanceof AudioFrame) {
                        $audioLevel = RtpUtility::computeAudioLevelDbov($data->getPlanes()[0]->getData(), $data->getSamples());
                    }
                    $useKeyFrame = $this->useKeyframe;
                    $this->useKeyframe = false;
                    $this->encoder ??= Codec::getEncoder($this->codec);
                    [$payloads, $timestamp] = $this->encoder->encode($data, $useKeyFrame);
                } else {
                    $this->encoder ??= Codec::getEncoder($this->codec);
                    [$payloads, $timestamp] = $this->encoder->pack($data);
                }

                if (empty($payloads)) {
                    continue;
                }

                $data = new RTCEncodedFrame($payloads, $timestamp, $audioLevel);
                for ($i = 0; $i < count($data->getPayloads()); $i++) {
                    $rtpPacket = $this->generateRtpPacket($data, $i);
                    $this->sendRtpPacket($rtpPacket);
                    $this->updateStatistics($rtpPacket, $data->getPayloads()[$i]);
                    $this->sequenceNumber = ($this->sequenceNumber + 1) & 0xFFFF;
                }
            }
        });
    }

    /**
     * Generates an RTP packet from an encoded frame.
     *
     * @param RTCEncodedFrame $encodedFrame The encoded frame
     * @param int $i The payload index
     * @return RtpPacket The generated RTP packet
     * @throws DateMalformedStringException If there's an error with timestamp generation
     */
    private function generateRtpPacket(RTCEncodedFrame $encodedFrame, int $i): RtpPacket
    {
        $packet = new RtpPacket();
        $packet->setPayloadType($this->codec->payloadType);
        $packet->setSequenceNumber($this->sequenceNumber);
        $packet->setTimestamp(($this->orgTimestamp + $encodedFrame->getTimestamp()) & 0xFFFFFFFF);
        $packet->setSsrc($this->ssrc);
        $packet->setPayload($encodedFrame->getPayloads()[$i]);
        $packet->setMarker(($i === count($encodedFrame->getPayloads()) - 1) ? 1 : 0);

        // Set header extensions
        [$ntpTimeHi, $ntpTimeLo] = NetworkTimeProtocol::currentNtpTime();
        // abs-send-time is a 6.18 fixed point value: bits 14..37 of the raw NTP timestamp.
        $packet->getExtensions()->setAbsSendTime((($ntpTimeLo >> 14) & 0x00FFFFFF));
        $packet->getExtensions()->setMid($this->mid);
        if ($encodedFrame->getAudioLevel()) {
            // RFC 6464 carries the loudness as 0..127 in -dBov, 0 being the loudest. Clamp rather
            // than let an out of range value wrap into a nonsensical one.
            $level = min(127, max(0, -$encodedFrame->getAudioLevel()));
            // The V bit reports voice activity, and the extension is negotiated with "vad=on"
            // unless the attribute says otherwise, so a sender that leaves it clear is telling the
            // receiver it is silent on every single packet. Selective forwarding units use exactly
            // that to decide whose audio to relay, so anything above the silence floor has to
            // report activity.
            $packet->getExtensions()->setAudioLevel([$level <= self::SILENCE_LEVEL_DBOV, $level]);
        }

        return $packet;
    }

    /**
     * Sends an RTP packet over the transport.
     *
     * @param RtpPacket $packet The RTP packet to send
     */
    private function sendRtpPacket(RtpPacket $packet): void
    {
        $this->logger->debug("Sending packet: $packet");
        $this->rtpHistory[$packet->getSequenceNumber() % RtpConstants::RTP_HISTORY_SIZE] = $packet;
        $packetBytes = $packet->encode($this->headerExtensionsMap);
        $this->transport->sendRtp($packetBytes);
    }

    /**
     * Updates statistics after sending an RTP packet.
     *
     * @param RtpPacket $packet The scent RTP packet
     * @param string $payload The packet payload
     * @throws DateMalformedStringException
     */
    private function updateStatistics(RtpPacket $packet, string $payload): void
    {
        [$this->ntpTimestampHigh, $this->ntpTimestampLow] = NetworkTimeProtocol::currentNtpTime();
        $this->rtpTimestamp = $packet->getTimestamp();
        $this->octetCount += strlen($payload);
        $this->packetCount++;
    }

    /**
     * Starts the periodic RTCP packet sending a task.
     *
     * @throws RandomException If random number generation fails
     */
    private function startRtcpTask(): void
    {
        $this->logger->debug("RTCP started");

        $this->rtcpTask = EventLoop::repeat(0.5 + (random_int(0, 1000) / 1000), function () {
            try {
                $rtcpPackets = $this->generateRtcpPackets();
                $this->sendRtcpPacket($rtcpPackets);
            } catch (RtcpExceptionInterface $e) {
                $this->logger->warning("RTCP error: " . $e->getMessage());
            }
        });
    }

    /**
     * Generates RTCP packets for the current state.
     *
     * @return RtcpPacketInterface[] Array of RTCP packets to send
     */
    private function generateRtcpPackets(): array
    {
        $packets = [$this->generateRtcpSrPacket()];

        // The LSR field carries the middle 32 bits of the raw NTP timestamp.
        $this->lsr = (($this->ntpTimestampHigh & 0xFFFF) << 16) | ($this->ntpTimestampLow >> 16);
        $this->lsrTime = microtime(true);

        // Generate RTCP SDES packet
        if ($this->cname !== null) {
            $packets[] = $this->generateRtcpSdesPacket();
        }

        return $packets;
    }

    /**
     * Generates an RTCP sender report (SR) packet.
     *
     * @return RtcpSrPacket The generated SR packet
     */
    private function generateRtcpSrPacket(): RtcpSrPacket
    {
        return new RtcpSrPacket(
            ssrc: $this->ssrc,
            senderInfo: new RtcpSenderInfo(
                ntpTimestampHigh: $this->ntpTimestampHigh,
                ntpTimestampLow: $this->ntpTimestampLow,
                rtpTimestamp: $this->rtpTimestamp,
                packetCount: $this->packetCount,
                octetCount: $this->octetCount
            )
        );
    }

    /**
     * Generates an RTCP source description (SDES) packet.
     *
     * @return RtcpSdesPacket The generated SDES packet
     */
    private function generateRtcpSdesPacket(): RtcpSdesPacket
    {
        return new RtcpSdesPacket([
            new RtcpSourceInfo(
                ssrc: $this->ssrc,
                items: [[1, $this->cname]]
            )
        ]);
    }

    /**
     * Sends RTCP packets over the transport.
     *
     * @param array<RtcpPacketInterface> $packets The RTCP packets to send
     */
    private function sendRtcpPacket(array $packets): void
    {
        $payload = "";
        foreach ($packets as $packet) {
            $this->logger->debug("Sending RTCP packet: $packet");
            $payload .= $packet->encode();
        }

        try {
            $this->transport->sendRtcp($payload);
        } catch (Throwable $e) {
            $this->logger->warning("Failed to send RTCP: " . $e->getMessage());
        }
    }

    /**
     * Handles an incoming RTCP packet.
     *
     * @param RtcpPacketInterface $packet The received RTCP packet
     * @throws Throwable If there's an error with DTLS
     * @throws SrtpException If there's an error with SRTP
     */
    public function handleRtcpPacket(RtcpPacketInterface $packet): void
    {
        if ($packet instanceof RtcpRrPacket || $packet instanceof RtcpSrPacket) {
            $this->handleReportRtcpPacket($packet);
        } elseif ($packet instanceof RtcpRtpfbPacket && $packet->getFmt() === RtcpConstants::RTCP_RTPFB_NACK) {
            foreach ($packet->getLost() as $seq) {
                $this->retransmit($seq);
            }
        } elseif ($packet instanceof RtcpPsfbPacket && $packet->getFmt() === RtcpConstants::RTCP_PSFB_PLI) {
            $this->sendKeyframe();
        } elseif ($packet instanceof RtcpPsfbPacket && $packet->getFmt() === RtcpConstants::RTCP_PSFB_APP) {
            $this->changeMediaBitrate($packet);
        }
    }

    /**
     * Handles RTCP report packets (RR or SR).
     *
     * @param RtcpSrPacket|RtcpRrPacket $packet The report packet
     */
    private function handleReportRtcpPacket(RtcpSrPacket|RtcpRrPacket $packet): void
    {
        foreach ($packet->getReports() as $report) {
            if ($report->getSsrc() === $this->ssrc && $report->getDlsr() !== null) {
                // Estimate round-trip time
                $rtt = microtime(true) - $this->lsrTime - ($report->getDlsr() / 65536);
                $this->rtt = $this->rtt === null ? $rtt : self::RTT_ALPHA * $this->rtt + (1 - self::RTT_ALPHA) * $rtt;

                // Update statistics
                $this->stats->add(new RTCRemoteInboundRtpStreamStats(
                    id: "remote_inbound_rtp_stream_" . spl_object_id($this),
                    ssrc: $packet->getSsrc(),
                    kind: $this->kind->value,
                    transportId: $this->transport->getReportTransport()->id,
                    packetsReceived: $this->packetCount - $report->getPacketsLost(),
                    packetsLost: $report->getPacketsLost(),
                    jitter: $report->getJitter(),
                    roundTripTime: $this->rtt,
                    fractionLost: $report->getFractionLost()
                ));
            }
        }
    }

    /**
     * Retransmits an RTP packet identified by sequence number.
     *
     * @param int $sequenceNumber The sequence number of the packet to retransmit
     * @throws RandomException
     * @throws SrtpException
     * @throws Throwable
     */
    public function retransmit(int $sequenceNumber): void
    {
        $packet = $this->rtpHistory[$sequenceNumber % RtpConstants::RTP_HISTORY_SIZE] ?? null;
        if ($packet && $packet->getSequenceNumber() === $sequenceNumber) {
            if ($this->rtxPayloadType !== null) {
                $packet = RtpUtility::wrapRtx(
                    $packet,
                    payloadType: $this->rtxPayloadType,
                    sequenceNumber: $this->rtxSequenceNumber,
                    ssrc: $this->rtxSsrc
                );
                $this->rtxSequenceNumber = ($this->rtxSequenceNumber + 1) & 0xFFFF;
            }

            $this->logger->debug("Retransmitting packet: $packet");
            $packetBytes = $packet->encode($this->headerExtensionsMap);
            $this->transport->sendRtp($packetBytes);
        }
    }

    /**
     * Adjusts media bitrate based on REMB feedback.
     *
     * @param RtcpPsfbPacket $packet The REMB feedback packet
     */
    private function changeMediaBitrate(RtcpPsfbPacket $packet): void
    {
        try {
            [$bitrate, $ssrcs] = RtpUtility::unpackRembFci($packet->getFci());
            if (in_array($this->ssrc, $ssrcs)) {
                $this->logger->debug("Receiver estimated maximum bitrate: $bitrate bps");
                $this->encoder?->setBitrate($bitrate);
            }
        } catch (Exception) {
            // Ignore invalid REMB packets
        }
    }


    /**
     * Checks if the next frame should be a keyframe.
     *
     * @return bool True if the next frame should be a keyframe
     */
    public function isUseKeyframe(): bool
    {
        return $this->useKeyframe;
    }

    /**
     * Gets the last sender report NTP timestamp.
     *
     * @return int|null The NTP timestamp or null if not available
     */
    public function getLsr(): ?int
    {
        return $this->lsr;
    }

    /**
     * Gets the track ID.
     *
     * @return string The track ID
     */
    public function getTrackId(): string
    {
        return $this->trackId;
    }

    /**
     * Gets the header extensions map.
     *
     * @return HeaderExtensionsMap The header extensions map
     */
    public function getHeaderExtensionsMap(): HeaderExtensionsMap
    {
        return $this->headerExtensionsMap;
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface|null $logger The logger instance
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $logger = new class((string) $this->kind, $logger) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly string $kind, private readonly LoggerInterface $logger) {}
            public function log($level, $message, array $context = array()): void {
                assert(is_string($message));
                $this->logger->log($level, "RTCRtpSender($this->kind): $message", $context);
            }
        };
        $this->logger = $logger;
    }

    /**
     * Checks if the sender is enabled.
     *
     * @return bool True if the sender is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables or disables (pauses) the sender.
     *
     * While disabled the RTP task keeps consuming frames from the track but sends nothing,
     * effectively pausing the outgoing media. On resume a keyframe is forced so a video
     * receiver can start decoding again without waiting for the next natural keyframe.
     *
     * @param bool $enabled True to resume sending, false to pause
     */
    public function setEnabled(bool $enabled): void
    {
        if ($enabled === $this->enabled) {
            return;
        }

        $this->enabled = $enabled;

        if ($enabled && $this->kind === MediaKind::Video) {
            $this->sendKeyframe();
        }
    }

    /**
     * Gets the SSRC for RTP packets.
     *
     * @return int The SSRC
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Requests the next frame to be a keyframe.
     */
    public function sendKeyframe(): void
    {
        $this->useKeyframe = true;
    }

    /**
     * Gets the stream ID.
     *
     * @return string The stream ID
     */
    public function getStreamId(): string
    {
        return $this->streamId;
    }

    /**
     * Destructor - stops the sender when the object is destroyed.
     * @throws SrtpException
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Gets the current statistics report.
     *
     * @return RTCStatsReport The statistics report
     */
    public function getStats(): RTCStatsReport
    {
        $this->stats->add(new RTCOutboundRtpStreamStats(
            id: "outbound_rtp_stream_" . spl_object_id($this),
            type: StatType::OutboundRtpStream,
            ssrc: $this->ssrc,
            kind: $this->kind->value,
            transportId: $this->transport->getReportTransport()->id,
            packetsSent: $this->packetCount,
            bytesSent: $this->octetCount,
            trackId: $this->trackId
        ));
        $this->stats->add($this->transport->getReportTransport());

        return $this->stats;
    }

    /**
     * Sets the SSRC for RTP packets.
     *
     * @param int $ssrc The SSRC to set
     */
    public function setSsrc(int $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    /**
     * Sets the SSRC for RTX packets.
     *
     * @param int $rtxSsrc The RTX SSRC to set
     */
    public function setRtxSsrc(int $rtxSsrc): void
    {
        $this->rtxSsrc = $rtxSsrc;
    }

    /**
     * Sets the stream ID.
     *
     * @param string $streamId The stream ID to set
     */
    public function setStreamId(string $streamId): void
    {
        $this->streamId = $streamId;
    }

    /**
     * Gets the SSRC for RTX packets.
     *
     * @return int The RTX SSRC
     */
    public function getRtxSsrc(): int
    {
        return $this->rtxSsrc;
    }

    /**
     * Generates a random sequence number for RTP packets.
     *
     * @return int The generated sequence number
     * @throws RandomException
     */
    private function generateSequenceNumber(): int
    {
        $bytes = random_bytes(2);
        $unpacked = unpack('n', $bytes);

        return $unpacked[1] % 32768;
    }
}
