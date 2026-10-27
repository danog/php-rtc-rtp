<?php

namespace Tests\Webrtc\RTP\Sender;

use Webrtc\RTCP\RtcpPacket;

use Webrtc\RTCP\RtcpByePacket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Webrtc\RTP\MediaStreamTrack\AudioStreamTrack;
use Tests\Webrtc\RTP\MediaStreamTrack\PushingStreamTrack;
use Tests\Webrtc\RTP\MediaStreamTrack\VideoStreamTrack;
use Tests\Webrtc\RTP\RTCDtlsTransportMock;
use Tests\Webrtc\RTP\NumberingTransportMock;
use Webrtc\AVCodec\AVCodec;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpReceiverInfo;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCRemoteInboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Stats\RTCTransportStats;

#[UsesClass(HeaderExtensionsMap::class)]
#[UsesClass(MediaStreamTrack::class)]
#[UsesClass(RtpUtility::class)]
#[CoversClass(RTCRtpSender::class)]
class RTCRtpSenderTest extends TestCase
{
    private RTCDtlsTransportMock $transportMock;

    protected function setUp(): void
    {
        $this->transportMock = new RTCDtlsTransportMock();
    }

    public function testConstruct(): void
    {
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);
        $this->assertEquals("audio", $sender->getKind()->value);
        $this->assertEquals($this->transportMock, $sender->getTransport());
    }

    public function testConstructInvalidDtlsTransportState(): void
    {
        $closedTransportMock = $this->getMockBuilder(RTCDtlsTransportMock::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getState'])
            ->getMock();

        $closedTransportMock->expects($this->once())
            ->method('getState')
            ->willReturn(TLSState::CLOSED);
        $this->expectException(InvalidArgumentException::class);
        new RTCRtpSender(MediaKind::Audio, $closedTransportMock);
    }

    public function testConnectionError(): void
    {
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);
        $this->assertEquals("audio", $sender->getKind()->value);

        $rtpParameters = $this->getRTCRtpAudioSendParameters();
        $sender->start($rtpParameters);
        $sender->stop();
    }

    /**
     * Count the BYE packets sent so far.
     */
    private function countByes(): int
    {
        $byes = 0;
        foreach ($this->transportMock->getRtcpPackets() as $data) {
            foreach (RtcpPacket::decode($data) as $packet) {
                if ($packet instanceof RtcpByePacket) {
                    $byes++;
                }
            }
        }
        return $byes;
    }

    public function testStopSendsByeOnce(): void
    {
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);
        $sender->start($this->getRTCRtpAudioSendParameters());
        $sender->stop();
        $sender->stop();
        unset($sender);

        $this->assertSame(1, $this->countByes());
    }

    public function testDestroyingASenderDoesNotSendBye(): void
    {
        // As when the process exits to be restarted, with the session going on in the next one.
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);
        $sender->start($this->getRTCRtpAudioSendParameters());
        // Called explicitly: the running tasks keep the sender alive here.
        $sender->__destruct();

        $this->assertSame(0, $this->countByes());
    }

    public function testHandleRtcpNack(): void
    {
        $rtpParameters = $this->getRTCRtpSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $packet = new RtcpRtpfbPacket(RtcpConstants::RTCP_RTPFB_NACK, 1234, $sender->getSsrc(), [7654]);
        $sender->handleRtcpPacket($packet);
        $sender->stop();
    }

    public function testHandleRtcpPli(): void
    {
        $rtpParameters = $this->getRTCRtpSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $packet = new RtcpPsfbPacket(RtcpConstants::RTCP_PSFB_PLI, 1234, $sender->getSsrc());
        $sender->handleRtcpPacket($packet);
        $sender->stop();
    }

    public function testHandleRtcpRemb(): void
    {
        $rtpParameters = $this->getRTCRtpSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $packet = new RtcpPsfbPacket(RtcpConstants::RTCP_PSFB_APP, 1234, 0, RtpUtility::packRembFci(4160000, [$sender->getSsrc()]));
        $sender->handleRtcpPacket($packet);
        $packet = new RtcpPsfbPacket(RtcpConstants::RTCP_PSFB_APP, 1234, 0, "JUNK");
        $sender->handleRtcpPacket($packet);
        $sender->stop();
    }

    public function testHandleRtcpRr(): void
    {
        $rtpParameters = $this->getRTCRtpSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $packet = new RtcpRrPacket(1234, [
            new RtcpReceiverInfo(
                ssrc: $sender->getSsrc(),
                fractionLost: 0,
                packetsLost: 0,
                highestSequence: 630,
                jitter: 1906,
                lsr: 0,
                dlsr: 0
            )
        ]);
        $sender->handleRtcpPacket($packet);
        $report = $sender->getStats();
        $this->assertInstanceOf(RTCStatsReport::class, $report);
        $this->assertInstanceOf(
            RTCRemoteInboundRtpStreamStats::class,
            array_values($report->getStats())[0]
        );
        $sender->stop();
    }

    public function testSendKeyframe(): void
    {
        $rtpParameters = $this->getRTCRtpSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $sender->sendKeyframe();
        $sender->stop();
    }

    public function testRetransmitWithRtx(): void
    {
        if (!AVCodec::isAvailable()) {
            self::markTestSkipped(
                'Transcoding needs the FFI extension and an FFmpeg build matching the bundled headers.'
            );
        }

        AVCodec::init(true);
        $rtpRtxParameters = $this->getRTCRtpRtxSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpRtxParameters);
        $sender->setSsrc(1234);
        $sender->setRtxSsrc(2345);
//        $rtpPacket = RtpPacket::decode($rtpPackets[0][0]);
//        $sender->retransmit($rtpPacket->getSequenceNumber());
//        $rtpRtxPacket = RtpPacket::decode($rtpPackets[1][0]);
//        $this->assertNotNull($rtpRtxPacket);
//        $this->assertEquals(101, $rtpRtxPacket->getPayloadType());
//        $this->assertEquals(2345, $rtpRtxPacket->getSsrc());
//        $this->assertEquals(substr($rtpRtxPacket->getPayload(), 0, 2), pack("n", $rtpPacket->getSequenceNumber()));
        $sender->stop();
    }

    /**
     * Start a VP8 sender for a track that pushes its frames, with the mid and abs-send-time extensions.
     *
     * @return array{RTCRtpSender, PushingStreamTrack, HeaderExtensionsMap}
     */
    private function startPushingSender(bool $rtx = false, ?RTCDtlsTransportMock $transport = null, MediaKind $kind = MediaKind::Video): array
    {
        $transport ??= $this->transportMock;
        $codecs = [$kind === MediaKind::Video
            ? new RTCRtpCodecParameters(mimeType: "video/VP8", clockRate: 90000, payloadType: 100)
            : new RTCRtpCodecParameters(mimeType: "audio/opus", clockRate: 48000, channels: 2, payloadType: 111)];
        if ($rtx) {
            $codecs[] = new RTCRtpCodecParameters(mimeType: "video/rtx", clockRate: 90000, payloadType: 101, parameters: ["apt" => 100]);
        }
        $parameters = new RTCRtpSendParameters(
            codecs: $codecs,
            headerExtensions: [
                new RTCRtpHeaderExtensionParameters(1, "urn:ietf:params:rtp-hdrext:sdes:mid"),
                new RTCRtpHeaderExtensionParameters(2, "http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time"),
                new RTCRtpHeaderExtensionParameters(3, "http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01"),
            ],
            muxId: "0",
        );
        $track = new PushingStreamTrack($kind);
        $sender = new RTCRtpSender($track, $transport);
        $sender->setSsrc(1234);
        $sender->setRtxSsrc(2345);
        $sender->start($parameters);
        // Let the sender attach to the track.
        \Amp\delay(0);
        $map = new HeaderExtensionsMap();
        $map->configure($parameters);

        return [$sender, $track, $map];
    }

    public function testThePushedFramesAreSentRightAway(): void
    {
        [$sender, $track, $map] = $this->startPushingSender();

        [$ntpHigh, $ntpLow] = NetworkTimeProtocol::currentNtpTime();
        $track->send(new EncodedPacket(str_repeat("\x01", 3000), 9000, true));
        $track->send(new EncodedPacket(str_repeat("\x02", 100), 12000, false));

        $packets = array_map(static fn (string $p): RtpPacket => RtpPacket::decode($p, $map), $this->transportMock->rtpPackets);
        $this->assertGreaterThan(2, \count($packets), 'the first frame is split into several packets');
        $first = $packets[0]->getSequenceNumber();
        $last = \count($packets) - 1;
        foreach ($packets as $i => $packet) {
            $this->assertSame(100, $packet->getPayloadType());
            $this->assertSame(1234, $packet->getSsrc());
            $this->assertSame(($first + $i) & 0xFFFF, $packet->getSequenceNumber());
            $this->assertSame("0", $packet->getExtensions()->getMid());
            // The last packet of each frame is marked.
            $this->assertSame($i >= $last - 1 ? 1 : 0, $packet->getMarker(), "the marker of packet $i");
            // A 6.18 fixed point number of seconds, that wraps every 64 seconds.
            $absSendTime = $packet->getExtensions()->getAbsSendTime();
            $expected = (($ntpHigh & 0x3F) << 18) | ($ntpLow >> 14);
            $this->assertLessThan(1 << 17, ($absSendTime - $expected) & 0xFFFFFF, 'the abs-send-time is the time the packet was sent at');
        }
        $this->assertSame($packets[0]->getTimestamp(), $packets[$last - 1]->getTimestamp());
        $this->assertSame(($packets[0]->getTimestamp() + 3000) & 0xFFFFFFFF, $packets[$last]->getTimestamp());
        $this->assertSame(str_repeat("\x02", 100), substr($packets[$last]->getPayload(), -100));
        $sender->stop();
    }

    /**
     * A frame the frame cryptor can't send is dropped, without counting as a failure to send: the sender stopped for
     * good after 100 of them in a row (an E2E conference epoch in which no H.264 frame can be encrypted).
     */
    public function testFramesTheCryptorDropsDoNotStopTheSender(): void
    {
        [$sender, $track, $map] = $this->startPushingSender();
        $sender->setFrameCryptor(new class implements \Webrtc\RTP\Crypto\FrameCryptorInterface {
            public int $dropped = 0;
            public function encryptFrame(MediaKind $kind, int $ssrc, string $frame): string
            {
                if ($frame[0] === "\x01") {
                    $this->dropped++;
                    throw new \Webrtc\RTP\Crypto\DropFrameException('cannot send it');
                }
                return $frame;
            }
            public function decryptFrame(MediaKind $kind, int $ssrc, string $frame): string
            {
                return $frame;
            }
        });

        for ($i = 0; $i < 150; $i++) {
            $track->send(new EncodedPacket(str_repeat("\x01", 100), 9000 + $i * 3000, false));
        }
        $this->assertSame([], $this->transportMock->rtpPackets);
        $track->send(new EncodedPacket(str_repeat("\x02", 100), 9000 + 150 * 3000, false));

        $packets = array_map(static fn (string $p): RtpPacket => RtpPacket::decode($p, $map), $this->transportMock->rtpPackets);
        $this->assertCount(1, $packets);
        $this->assertSame(str_repeat("\x02", 100), substr($packets[0]->getPayload(), -100));
        $sender->stop();
    }

    public function testANackRetransmitsThePacket(): void
    {
        [$sender, $track] = $this->startPushingSender();
        $track->send(new EncodedPacket(str_repeat("\x01", 3000), 9000, true));
        $sent = $this->transportMock->rtpPackets;
        $packet = RtpPacket::decode($sent[1]);

        $sender->handleRtcpPacket(new RtcpRtpfbPacket(RtcpConstants::RTCP_RTPFB_NACK, 1, 1234, [$packet->getSequenceNumber(), ($packet->getSequenceNumber() + 1000) & 0xFFFF]));

        // The packet that wasn't sent is ignored.
        $this->assertSame([...$sent, $sent[1]], $this->transportMock->rtpPackets);
        $sender->stop();
    }

    public function testANackRetransmitsThePacketOnTheRtxStream(): void
    {
        [$sender, $track, $map] = $this->startPushingSender(rtx: true);
        $track->send(new EncodedPacket(str_repeat("\x01", 3000), 9000, true));
        $sent = $this->transportMock->rtpPackets;
        $packet = RtpPacket::decode($sent[\count($sent) - 1], $map);

        $sender->handleRtcpPacket(new RtcpRtpfbPacket(RtcpConstants::RTCP_RTPFB_NACK, 1, 1234, [$packet->getSequenceNumber()]));

        $this->assertCount(\count($sent) + 1, $this->transportMock->rtpPackets);
        $rtx = RtpPacket::decode($this->transportMock->rtpPackets[\count($sent)], $map);
        $this->assertSame(101, $rtx->getPayloadType());
        $this->assertSame(2345, $rtx->getSsrc());
        $this->assertSame($packet->getTimestamp(), $rtx->getTimestamp());
        $this->assertSame(1, $rtx->getMarker());
        $this->assertSame($packet->getExtensions()->getAbsSendTime(), $rtx->getExtensions()->getAbsSendTime());
        $this->assertSame("0", $rtx->getExtensions()->getMid());
        $this->assertSame(pack("n", $packet->getSequenceNumber()).$packet->getPayload(), $rtx->getPayload());
        $this->assertSame(RtpUtility::wrapRtx($packet, 101, $rtx->getSequenceNumber(), 2345)->encode($map), $this->transportMock->rtpPackets[\count($sent)]);
        $sender->stop();
    }

    public function testThePacketsAreNumberedOnTheTransport(): void
    {
        $transport = new NumberingTransportMock();
        [$video, $videoTrack, $map] = $this->startPushingSender(transport: $transport);
        [$audio, $audioTrack] = $this->startPushingSender(transport: $transport, kind: MediaKind::Audio);

        $videoTrack->send(new EncodedPacket(str_repeat("\x01", 3000), 9000, true));
        $audioTrack->send(new EncodedPacket("\xFC\x01", 960));
        $videoTrack->send(new EncodedPacket(str_repeat("\x02", 100), 12000, false));

        $numbers = [];
        foreach ($transport->rtpPackets as $data) {
            $packet = RtpPacket::decode($data, $map);
            $numbers[] = $packet->getExtensions()->getTransportSequenceNumber();
            // The other extensions are still there.
            $this->assertSame("0", $packet->getExtensions()->getMid());
            $this->assertNotNull($packet->getExtensions()->getAbsSendTime());
        }
        // One sequence for all of the packets sent on the transport, wrapping around.
        $expected = [];
        for ($i = 0; $i < \count($numbers); $i++) {
            $expected[] = (65534 + $i) & 0xFFFF;
        }
        $this->assertSame($expected, $numbers);
        $video->stop();
        $audio->stop();
    }

    public function testARetransmissionIsNumberedAgain(): void
    {
        foreach ([false, true] as $rtx) {
            $transport = new NumberingTransportMock();
            [$sender, $track, $map] = $this->startPushingSender(rtx: $rtx, transport: $transport);
            $track->send(new EncodedPacket(str_repeat("\x01", 3000), 9000, true));
            $sent = $transport->rtpPackets;
            $packet = RtpPacket::decode($sent[0], $map);

            $sender->handleRtcpPacket(new RtcpRtpfbPacket(RtcpConstants::RTCP_RTPFB_NACK, 1, 1234, [$packet->getSequenceNumber()]));

            $retransmission = RtpPacket::decode($transport->rtpPackets[\count($sent)], $map);
            $this->assertSame($transport->transportSequenceNumber, $retransmission->getExtensions()->getTransportSequenceNumber());
            $this->assertNotSame($packet->getExtensions()->getTransportSequenceNumber(), $retransmission->getExtensions()->getTransportSequenceNumber());
            $this->assertSame($rtx ? pack("n", $packet->getSequenceNumber()).$packet->getPayload() : $packet->getPayload(), $retransmission->getPayload());
            $sender->stop();
        }
    }

    public function testThePacketsAreNotNumberedIfTheTransportDoesNot(): void
    {
        [$sender, $track, $map] = $this->startPushingSender();
        $track->send(new EncodedPacket(str_repeat("\x01", 100), 9000, true));

        $packet = RtpPacket::decode($this->transportMock->rtpPackets[0], $map);
        $this->assertNull($packet->getExtensions()->getTransportSequenceNumber());
        $sender->stop();
    }

    public function testDisabled(): void
    {
        $rtpParameters = $this->getRTCRtpAudioSendParameters();
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);

        $this->assertEquals("audio", $sender->getKind()->value);
        $this->assertTrue($sender->isEnabled());
        $sender->start($rtpParameters);
        $sender->setEnabled(false);
        $this->assertFalse($sender->isEnabled());
        $report = $sender->getStats();
        $this->assertInstanceOf(RTCStatsReport::class, $report);
        $sender->stop();
    }

    public function testStartReconfiguresRunningSender(): void
    {
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);
        $first = $this->getRTCRtpSendParameters();
        $second = new RTCRtpSendParameters(codecs: [
            new RTCRtpCodecParameters(mimeType: "video/H264", clockRate: 90000, payloadType: 120),
        ]);

        $sender->start($first);
        $sender->start($second);

        $this->assertSame([$first, $second], $this->transportMock->senderParameters);
        $sender->stop();
    }

    public function testStop(): void
    {
        $rtpParameters = $this->getRTCRtpAudioSendParameters();
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);
        $this->assertEquals("audio", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $sender->stop();
    }

    public function testStopBeforeSend(): void
    {
        $sender = new RTCRtpSender(new AudioStreamTrack(), $this->transportMock);

        $this->assertEquals("audio", $sender->getKind()->value);
        $sender->stop();
    }

    public function testTrackEnded(): void
    {
        $rtpParameters = $this->getRTCRtpAudioSendParameters();
        $track = new AudioStreamTrack();
        $sender = new RTCRtpSender($track, $this->transportMock);

        $this->assertEquals("audio", $sender->getKind()->value);
        $sender->start($rtpParameters);
        $track->stop();
        $sender->stop();
    }

    private function getRTCRtpSendParameters(): RTCRtpSendParameters
    {
        $vp8Codec = new RTCRtpCodecParameters(mimeType: "video/VP8", clockRate: 90000, payloadType: 100);
        $h264Codec = new RTCRtpCodecParameters(mimeType: "video/H264", clockRate: 90000, payloadType: 98);

        return new RTCRtpSendParameters(codecs: [$vp8Codec, $h264Codec]);
    }

    private function getRTCRtpAudioSendParameters(): RTCRtpSendParameters
    {
        $pcmUCodec = new RTCRtpCodecParameters(mimeType: "audio/PCMU", clockRate: 8000, channels: 1, payloadType: 0);
        return new RTCRtpSendParameters(codecs: [$pcmUCodec]);
    }

    private function getRTCRtpRtxSendParameters(): RTCRtpSendParameters
    {
        $vp8Codec = new RTCRtpCodecParameters(mimeType: "video/VP8", clockRate: 90000, payloadType: 100);
        $rtxCodec = new RTCRtpCodecParameters(mimeType: "video/rtx", clockRate: 90000, payloadType: 101, parameters: ["apt" => 100]);

        return new RTCRtpSendParameters(codecs: [$vp8Codec, $rtxCodec]);
    }
}
