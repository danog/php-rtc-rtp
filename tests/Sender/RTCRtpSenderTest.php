<?php

namespace Tests\Webrtc\RTP\Sender;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Webrtc\RTP\RTCDtlsTransportMock;
use Webrtc\AVCodec\AVCodec;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpReceiverInfo;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\MediaStreamTrack\AudioStreamTrack;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\VideoStreamTrack;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCRemoteInboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Stats\RTCTransportStats;

#[UsesClass(HeaderExtensionsMap::class)]
#[UsesClass(MediaStreamTrack::class)]
#[UsesClass(VideoStreamTrack::class)]
#[UsesClass(AudioStreamTrack::class)]
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
        AVCodec::init(true);
        $rtpRtxParameters = $this->getRTCRtpRtxSendParameters();
        $sender = new RTCRtpSender(new VideoStreamTrack(), $this->transportMock);

        $this->assertEquals("video", $sender->getKind()->value);
        $sender->start($rtpRtxParameters);
        $sender->setSsrc(1234);
        $sender->setRtxSsrc(2345);
//        $sender->send((new VideoStreamTrack)->generateBlankFrame());
//
//        $rtpPacket = RtpPacket::decode($rtpPackets[0][0]);
//        $sender->retransmit($rtpPacket->getSequenceNumber());
//        $rtpRtxPacket = RtpPacket::decode($rtpPackets[1][0]);
//        $this->assertNotNull($rtpRtxPacket);
//        $this->assertEquals(101, $rtpRtxPacket->getPayloadType());
//        $this->assertEquals(2345, $rtpRtxPacket->getSsrc());
//        $this->assertEquals(substr($rtpRtxPacket->getPayload(), 0, 2), pack("n", $rtpPacket->getSequenceNumber()));
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