<?php

namespace Tests\Webrtc\RTP\Receiver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Bridge\PhpUnit\ClockMock;
use Tests\Webrtc\RTP\RTCDtlsTransportMock;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Codecs\Codec;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTCP\RtcpPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\HeaderExtension\HeaderExtensions;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\Jitter\JitterBuffer;
use Webrtc\RTP\Jitter\JitterFrame;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\RTP\Receiver\DecoderQueue;
use Webrtc\RTP\Receiver\NackGenerator;
use Webrtc\RTP\Receiver\Rate\InterArrival;
use Webrtc\RTP\Receiver\Rate\RateBucket;
use Webrtc\RTP\Receiver\Rate\RateCounter;
use Webrtc\RTP\Receiver\Rate\RemoteBitrateEstimator;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\Receiver\StreamStatistics;
use Webrtc\RTP\Receiver\TimestampMapper;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTPParameter\RTCRtpCapabilities;
use Webrtc\RTPParameter\RTCRtpCodecCapability;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpDecodingParameters;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionCapability;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpRtxParameters;
use Webrtc\RTPParameter\RTCRtpSynchronizationSource;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCInboundRtpStreamStats;
use Webrtc\Stats\RTCRemoteOutboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Stats\RTCTransportStats;
use function PHPUnit\Framework\assertEquals;
use function Amp\delay;

#[UsesClass(JitterBuffer::class)]
#[UsesClass(MediaStreamTrack::class)]
#[UsesClass(RemoteStreamTrack::class)]
#[UsesClass(DecoderQueue::class)]
#[UsesClass(InterArrival::class)]
#[UsesClass(RateBucket::class)]
#[UsesClass(RateCounter::class)]
#[UsesClass(RemoteBitrateEstimator::class)]
#[UsesClass(RtpPacket::class)]
#[UsesClass(HeaderExtensions::class)]
#[UsesClass(HeaderExtensionsMap::class)]
#[UsesClass(JitterFrame::class)]
#[UsesClass(TimestampMapper::class)]
#[UsesClass(StreamStatistics::class)]
#[UsesClass(NackGenerator::class)]
#[CoversClass(RTCRtpReceiver::class)]
class RTCRtpReceiverTest extends TestCase
{
    private RTCDtlsTransportMock $transportMock;

    protected function setUp(): void
    {
        $this->transportMock = new RTCDtlsTransportMock();
        ClockMock::register(StreamStatistics::class);
    }

    public function testCapabilities()
    {
        // Audio capabilities
        $audioCapabilities = RTCRtpReceiver::getCapabilities("audio");
        $this->assertInstanceOf(RTCRtpCapabilities::class, $audioCapabilities);
        $this->assertEquals(
            [
                new RTCRtpCodecCapability("audio/opus", 48000, 2),
                new RTCRtpCodecCapability("audio/PCMU", 8000, 1),
                new RTCRtpCodecCapability("audio/PCMA", 8000, 1),
            ],
            $audioCapabilities->codecs
        );
        $this->assertEquals(
            [
                new RTCRtpHeaderExtensionCapability("urn:ietf:params:rtp-hdrext:sdes:mid"),
                new RTCRtpHeaderExtensionCapability("urn:ietf:params:rtp-hdrext:ssrc-audio-level"),
            ],
            $audioCapabilities->headerExtensions
        );

        // Video capabilities
        $videoCapabilities = RTCRtpReceiver::getCapabilities("video");
        $this->assertInstanceOf(RTCRtpCapabilities::class, $videoCapabilities);
        $this->assertEquals(
            [
                new RTCRtpCodecCapability("video/VP8", 90000),
                new RTCRtpCodecCapability("video/rtx", 90000),
                new RTCRtpCodecCapability("video/VP9", 90000, parameters: [
                    "profile-id" => "0",
                ]),
                new RTCRtpCodecCapability("video/H264", 90000, parameters: [
                    "level-asymmetry-allowed" => "1",
                    "packetization-mode" => "1",
                    "profile-level-id" => "42001f",
                ]),
                new RTCRtpCodecCapability("video/H264", 90000, parameters: [
                    "level-asymmetry-allowed" => "1",
                    "packetization-mode" => "1",
                    "profile-level-id" => "42e01f",
                ]),
            ],
            $videoCapabilities->codecs
        );
        $this->assertEquals(
            [
                new RTCRtpHeaderExtensionCapability("urn:ietf:params:rtp-hdrext:sdes:mid"),
                new RTCRtpHeaderExtensionCapability("http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time"),
            ],
            $videoCapabilities->headerExtensions
        );

        // Bogus type
        $this->expectException(InvalidArgumentException::class);
        RTCRtpReceiver::getCapabilities("bogus");
    }

    public function testConnectionError()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Audio, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Audio));
        $receiver->setRtcpSsrc(1234);
        $rtpParametersAudio = $this->getRTCRtpReceiveParametersAudio();

        $receiver->start($rtpParametersAudio);

        // Simulate receiving a packet
        $packet = RtpPacket::decode($this->getBinary("rtp.bin"));
        $receiver->handleRtpPacket($packet, 0);

        $receiver->stop();
        self::assertTrue(true);
    }

    public function testRtpAndRtcp()
    {
        if (!AVCodec::isAvailable()) {
            self::markTestSkipped(
                'Transcoding needs the FFI extension and an FFmpeg build matching the bundled headers.'
            );
        }

        $receiver = new RTCRtpReceiver(MediaKind::Audio, $this->transportMock);
        $remoteTrack = new RemoteStreamTrack(MediaKind::Audio);
        $receiver->setTrack($remoteTrack);
        $this->assertFalse($receiver->getTrack()->isEnded());
        $receiver->setRtcpSsrc(1234);

        // Decoded frames are delivered as a stream, so drain them off the track's consumer
        // in the background as the decoder produces them.
        $frames = [];
        EventLoop::queue(function () use ($remoteTrack, &$frames) {
            foreach ($remoteTrack->getConsumer() as $frame) {
                $frames[] = $frame;
            }
        });

        $rtpParametersAudio = $this->getRTCRtpReceiveParametersAudio();

        $receiver->start($rtpParametersAudio);

        // Simulate receiving RTP packets
        for ($i = 0; $i < 10; $i++) {
            $packet = RtpPacket::decode($this->getBinary("rtp.bin"));
            $packet->setSequenceNumber($packet->getSequenceNumber() + $i);
            $packet->setTimestamp($packet->getTimestamp() + $i * 160);
            $receiver->handleRtpPacket($packet, $i * 20);
        }

        // Wait 100 ms asynchronously for decoding frames
        delay(.1);

        // Simulate receiving RTCP SR packets
        $rtcpPackets = RtcpPacket::decode($this->getBinary("rtcp_sr.bin"));
        foreach ($rtcpPackets as $packet) {
            $receiver->handleRtcpPacket($packet);
        }

        // Check stats
        $report = $receiver->getStats();
        $this->assertInstanceOf(RTCStatsReport::class, $report);
        $stats = array_values($report->getStats());
        self::assertCount(3, $stats);
        self::assertInstanceOf(RTCRemoteOutboundRtpStreamStats::class, $stats[0]);
        self::assertInstanceOf(RTCInboundRtpStreamStats::class, $stats[1]);
        self::assertInstanceOf(RTCTransportStats::class, $stats[2]);

        // Check sources
        $sources = $receiver->getSynchronizationSources();
        $this->assertCount(1, $sources);
        $this->assertInstanceOf(RTCRtpSynchronizationSource::class, $sources[0]);
        $this->assertEquals(4028317929, $sources[0]->source);

        // Check remote track
        $frame = $frames[0]; // First frame
        $this->assertEquals(0, $frame->getPts());
        $this->assertEquals(8000, $frame->getSampleRate());
        $this->assertEquals(1, $frame->getTimebase()->num);
        $this->assertEquals(8000, $frame->getTimebase()->den);

        $frame = $frames[1]; // Second frame
        $this->assertEquals(160, $frame->getPts());
        $this->assertEquals(8000, $frame->getSampleRate());
        $this->assertEquals(1, $frame->getTimebase()->num);
        $this->assertEquals(8000, $frame->getTimebase()->den);

        // Shutdown
        $receiver->stop();
    }

    public function testRtpMissingVideoPacket()
    {
        if (!AVCodec::isAvailable()) {
            self::markTestSkipped(
                'Transcoding needs the FFI extension and an FFmpeg build matching the bundled headers.'
            );
        }

        AVCodec::init(true);
        $this->transportMock->resetRtcpPackets();
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        $receiver->setRtcpSsrc(1234);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Generate some packets
        $packets = $this->createRtpVideoPackets($this->getVp8Codec(), 129);

        // Receive RTP with a gap
        $receiver->handleRtpPacket($packets[0], 0);
        $receiver->handleRtpPacket($packets[128], 0);

        $nacks = RtcpPacket::decode($this->transportMock->getRtcpPackets()[0]);
        $pli = RtcpPacket::decode($this->transportMock->getRtcpPackets()[1]);

        // Check NACK was triggered
        $lostPackets = range(1, 127);
        $this->assertEquals([1234, $lostPackets], [$nacks[0]->getSsrc(), $nacks[0]->getLost()]);

        // Check PLI was triggered
        $this->assertEquals(1234, $pli[0]->getSsrc());

        $receiver->stop();
    }

    public function testRtpEmptyVideoPacket()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Receive RTP with empty payload
        $packet = new RtpPacket();
        $packet->setSsrc(100);
        $receiver->handleRtpPacket($packet, 0);
        $receiver->stop();
    }

    public function testRtpInvalidPayload()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Receive RTP with empty payload
        $packet = new RtpPacket();
        $packet->setSsrc(100);
        $packet->setPayload("\x80");
        $receiver->handleRtpPacket($packet, 0);
        $receiver->stop();
    }

    public function testRtpUnknownPayloadType()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Receive RTP with empty payload
        $packet = new RtpPacket();
        $packet->setPayloadType(123);
        $receiver->handleRtpPacket($packet, 0);
        $receiver->stop();
    }

    public function testRtpDisabled()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);
        $receiver->setEnabled(false);
        $this->assertFalse($receiver->isEnabled());

        // Receive RTP while disabled
        $packet = new RtpPacket();
        $receiver->handleRtpPacket($packet, 0);

        // Check stats
        $report = $receiver->getStats();
        $this->assertInstanceOf(RTCStatsReport::class, $report);
        $stats = array_values($report->getStats());
        self::assertInstanceOf(RTCTransportStats::class, $stats[0]);
        $receiver->stop();
    }

    public function testRtpRtx()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpRtxReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Receive RTX with payload
        $packet = new RtpPacket();
        $packet->setSsrc(1234);
        $packet->setPayloadType(101);
        $packet->setPayload("\x00\x00");
        $receiver->handleRtpPacket($packet, 0);

        // Receive RTX without payload
        $packet = new RtpPacket();
        $packet->setSsrc(1234);
        $packet->setPayloadType(101);
        $receiver->handleRtpPacket($packet, 0);

        $receiver->stop();
    }

    public function testRtpRtxUnknownSsrc()
    {
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        assertEquals("video", $receiver->getKind()->value);
        $rtpParametersVideo = $this->getRTCRtpRtxUnknownSsrcReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Receive RTX with payload
        $packet = new RtpPacket();
        $packet->setSsrc(1234);
        $packet->setPayloadType(101);
        $receiver->handleRtpPacket($packet, 0);

        $receiver->stop();
    }

    public function testSendRtcpNack()
    {
        $this->transportMock->resetRtcpPackets();
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        $receiver->setRtcpSsrc(1234);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();

        $receiver->start($rtpParametersVideo);

        // Send RTCP feedback NACK
        $receiver->sendRtcpNack(5678, [7654]);

        $nacks = RtcpPacket::decode($this->transportMock->getRtcpPackets()[0]);

        // Check NACK was triggered
        $this->assertEquals([5678, [7654]], [$nacks[0]->getMediaSsrc(), $nacks[0]->getLost()]);

        $receiver->stop();
    }

    public function testSendRtcpPli()
    {
        $this->transportMock->resetRtcpPackets();
        $receiver = new RTCRtpReceiver(MediaKind::Video, $this->transportMock);
        $receiver->setTrack(new RemoteStreamTrack(MediaKind::Video));
        $receiver->setRtcpSsrc(1234);
        $rtpParametersVideo = $this->getRTCRtpReceiveParametersVideo();
        $receiver->start($rtpParametersVideo);

        // Send RTCP feedback PLI
        $receiver->sendRtcpPli(5678);
        $pli = RtcpPacket::decode($this->transportMock->getRtcpPackets()[0]);

        $this->assertEquals(5678, $pli[0]->getMediaSsrc());

        $receiver->stop();
    }

    public function testInvalidDtlsTransportState()
    {
        $closedTransportMock = $this->getMockBuilder(RTCDtlsTransportMock::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getState'])
            ->getMock();

        $closedTransportMock->expects($this->once())
            ->method('getState')
            ->willReturn(TLSState::CLOSED);

        $this->expectException(InvalidArgumentException::class);
        new RTCRtpReceiver(MediaKind::Audio, $closedTransportMock);
    }

    public function createRtpVideoPackets(RTCRtpCodecParameters $codec, int $count, int $seq = 0): array
    {
        $encoder = Codec::getEncoder($codec);
        $packets = [];

        foreach ($this->createVideoFrames(640, 480, $count) as $frame) {
            [$payloads, $timestamp] = $encoder->encode($frame);
            self::assertCount(1, $payloads);

            $packet = new RtpPacket();
            $packet->setpayloadType($codec->payloadType);
            $packet->setSequenceNumber($seq);
            $packet->setSsrc(1234);
            $packet->setTimestamp($timestamp);
            $packet->setPayload($payloads[0]);
            $packet->setMarker(1);
            $packets[] = $packet;

            $seq = $this->uint16Add($seq, 1);
        }
        return $packets;
    }

    public function createVideoFrame(int $width, int $height, int $pts, $format = "yuv420p"): VideoFrame
    {
        // Create a blank VideoFrame object
        $frame = new VideoFrame($width, $height, $format);

        foreach ($frame->planes() as $plane) {
            $plane->putData(str_repeat("\0", $plane->getSize()));
        }

        $frame->setPts($pts);
        $frame->setTimeBase(1, 90000);

        return $frame;
    }

    public function createVideoFrames(int $width, int $height, int $count, float $timeBase = 1 / 90000): \Generator
    {
        $i = 0;
        while ($i < $count) {
            $i++;
            $frame = $this->createVideoFrame($width, $height, intval($i / $timeBase / 30));
            yield $frame;
            unset($frame); // Explicitly destroy the frame
        }
    }

    private function uint16Add($value, $increment): int
    {
        return ($value + $increment) & 0xFFFF; // Ensure it wraps around at 16 bits
    }

    private function getRTCRtpReceiveParametersAudio(): RTCRtpReceiveParameters
    {
        $pcmUCodec = new RTCRtpCodecParameters(mimeType: "audio/PCMU", clockRate: 8000, channels: 1, payloadType: 0);

        return new RTCRtpReceiveParameters(codecs: [$pcmUCodec]);
    }

    private function getBinary(string $filename): string
    {
        return file_get_contents(__DIR__ . "/../fixture/$filename");
    }

    private function getRTCRtpReceiveParametersVideo(): RTCRtpReceiveParameters
    {
        return new RTCRtpReceiveParameters(codecs: [$this->getVp8Codec()]);
    }

    private function getVp8Codec(): RTCRtpCodecParameters
    {
        return new RTCRtpCodecParameters(mimeType: "video/VP8", clockRate: 90000, payloadType: 100);
    }

    private function getRTCRtpRtxReceiveParametersVideo(): RTCRtpReceiveParameters
    {
        return new RTCRtpReceiveParameters(codecs: [
            $this->getVp8Codec(),
            new RTCRtpCodecParameters(
                mimeType: "video/rtx",
                clockRate: 90000,
                payloadType: 101,
                parameters: ["apt" => 100],
            ),
        ], encodings: [
            new RTCRtpDecodingParameters(1234, 100, new RTCRtpRtxParameters(2345)),
        ]);
    }

    private function getRTCRtpRtxUnknownSsrcReceiveParametersVideo(): RTCRtpReceiveParameters
    {
        return new RTCRtpReceiveParameters(codecs: [
            $this->getVp8Codec(),
            new RTCRtpCodecParameters(
                mimeType: "video/rtx",
                clockRate: 90000,
                payloadType: 101,
                parameters: ["apt" => 100],
            ),
        ]);
    }
}
