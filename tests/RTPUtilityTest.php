<?php

namespace Tests\Webrtc\RTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTP\HeaderExtension\HeaderExtensions;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\RTPParameter\RTCRtpParameters;

#[UsesClass(HeaderExtensions::class)]
#[UsesClass(HeaderExtensionsMap::class)]
#[UsesClass(RtpPacket::class)]
#[CoversClass(RtpUtility::class)]
class RTPUtilityTest extends TestCase
{

    public function testClampPacketsLost()
    {
        $this->assertEquals(-8388608, RtpUtility::clampPacketsLost(-8388609));
        $this->assertEquals(-8388608, RtpUtility::clampPacketsLost(-8388608));
        $this->assertEquals(0, RtpUtility::clampPacketsLost(0));
        $this->assertEquals(8388607, RtpUtility::clampPacketsLost(8388607));
        $this->assertEquals(8388607, RtpUtility::clampPacketsLost(8388608));
    }

    public function testPackPacketsLost()
    {
        $this->assertEquals("\x80\x00\x00", RtpUtility::packPacketsLost(-8388608));
        $this->assertEquals("\xff\xff\xff", RtpUtility::packPacketsLost(-1));
        $this->assertEquals("\x00\x00\x00", RtpUtility::packPacketsLost(0));
        $this->assertEquals("\x00\x00\x01", RtpUtility::packPacketsLost(1));
        $this->assertEquals("\x7f\xff\xff", RtpUtility::packPacketsLost(8388607));
    }

    public function testUnpackPacketsLost()
    {
        $this->assertEquals(-8388608, RtpUtility::unpackPacketsLost("\x80\x00\x00"));
        $this->assertEquals(-1, RtpUtility::unpackPacketsLost("\xff\xff\xff"));
        $this->assertEquals(0, RtpUtility::unpackPacketsLost("\x00\x00\x00"));
        $this->assertEquals(1, RtpUtility::unpackPacketsLost("\x00\x00\x01"));
        $this->assertEquals(8388607, RtpUtility::unpackPacketsLost("\x7f\xff\xff"));
    }

    public function testPackRembFci()
    {
        $this->assertEquals("REMB\x01\x00\x00\x00\x96\xbe\x96\xcf", RtpUtility::packRembFci(0, [2529072847]));
        $this->assertEquals("REMB\x01\x03\xff\xff\x96\xbe\x96\xcf", RtpUtility::packRembFci(0x3FFFF, [2529072847]));
        $this->assertEquals("REMB\x01\x06\x00\x00\x96\xbe\x96\xcf", RtpUtility::packRembFci(0x40000, [2529072847]));
        $this->assertEquals("REMB\x01\x13\xf7\xa0\x96\xbe\x96\xcf", RtpUtility::packRembFci(4160000, [2529072847]));
        $this->assertEquals("REMB\x01\xff\xff\xff\x96\xbe\x96\xcf", RtpUtility::packRembFci(0x3FFFF << 63, [2529072847]));
    }

    public function testUnpackRembFci()
    {
        $this->expectException(InvalidArgumentException::class);
        RtpUtility::unpackRembFci("JUNK");
        $this->assertEquals([0, [2529072847]], RtpUtility::unpackRembFci("REMB\x01\x00\x00\x00\x96\xbe\x96\xcf"));
        $this->assertEquals([0x3FFFF, [2529072847]], RtpUtility::unpackRembFci("REMB\x01\x03\xff\xff\x96\xbe\x96\xcf"));
        $this->assertEquals([0x40000, [2529072847]], RtpUtility::unpackRembFci("REMB\x01\x06\x00\x00\x96\xbe\x96\xcf"));
        $this->assertEquals([4160000, [2529072847]], RtpUtility::unpackRembFci("REMB\x01\x13\xf7\xa0\x96\xbe\x96\xcf"));
    }

    public function testUnpackHeaderExtensions()
    {
        $this->assertEquals([], RtpUtility::unpackHeaderExtensions(0, null));
        $this->assertEquals([[9, "0"]], RtpUtility::unpackHeaderExtensions(0xBEDE, "\x900"));
        $this->assertEquals([[9, "0"], [3, "1"]], RtpUtility::unpackHeaderExtensions(0xBEDE, "\x900\x00\x00\x301"));
        $this->assertEquals([[1, "\xc1"], [3, "sdparta_0"]], RtpUtility::unpackHeaderExtensions(0xBEDE, "\x10\xc18sdparta_0"));
        $this->assertEquals([[255, "0"]], RtpUtility::unpackHeaderExtensions(0x1000, "\xff\x010"));
        $this->assertEquals([[255, "0"], [240, "12"]], RtpUtility::unpackHeaderExtensions(0x1000, "\xff\x010\x00\xf0\x0212"));
    }

    public function testUnpackHeaderExtensionsBad()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("RTP one-byte header extension value is truncated");
        RtpUtility::unpackHeaderExtensions(0xBEDE, "\x90");

        $this->expectExceptionMessage("RTP two-byte header extension is truncated");
        RtpUtility::unpackHeaderExtensions(0x1000, "\xff");

        $this->expectExceptionMessage("RTP two-byte header extension value is truncated");
        RtpUtility::unpackHeaderExtensions(0x1000, "\xff\x020");
    }

    public function testPackHeaderExtensions()
    {
        $this->assertEquals([0, ""], RtpUtility::packHeaderExtensions([]));
        $this->assertEquals([0xBEDE, "\x900\x00\x00"], RtpUtility::packHeaderExtensions([[9, "0"]]));
        $this->assertEquals([0xBEDE, "\x10\xc18sdparta_0"], RtpUtility::packHeaderExtensions([[1, "\xc1"], [3, "sdparta_0"]]));
        $this->assertEquals([0x1000, "\xff\x010\x00"], RtpUtility::packHeaderExtensions([[255, "0"]]));
    }

    public function testMapHeaderExtensions()
    {
        $data = pack("C*",
            0x90, 0x64, 0x00, 0x58, 0x65, 0x43, 0x12, 0x78,
            0x12, 0x34, 0x56, 0x78, 0xBE, 0xDE, 0x00, 0x08,
            0x40, 0xDA, 0x22, 0x01, 0x56, 0xCE, 0x62, 0x12,
            0x34, 0x56, 0x81, 0xCE, 0xAB, 0xA0, 0x03, 0xB2,
            0x12, 0x48, 0x76, 0xC2, 0x72, 0x74, 0x78, 0xD5,
            0x73, 0x74, 0x72, 0x65, 0x61, 0x6D, 0x00, 0x00
        );

        $extensionsMap = new HeaderExtensionsMap();
        $extensionsMap->configure(new RTCRtpParameters(
            headerExtensions: [
                new RTCRtpHeaderExtensionParameters(2, "urn:ietf:params:rtp-hdrext:toffset"),
                new RTCRtpHeaderExtensionParameters(4, "urn:ietf:params:rtp-hdrext:ssrc-audio-level"),
                new RTCRtpHeaderExtensionParameters(6, "http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time"),
                new RTCRtpHeaderExtensionParameters(8, "http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01"),
                new RTCRtpHeaderExtensionParameters(12, "urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id"),
                new RTCRtpHeaderExtensionParameters(13, "urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id"),
            ]
        ));

        $packet = RtpPacket::decode($data, $extensionsMap);

        $this->assertEquals(0x123456, $packet->getExtensions()->getAbsSendTime());
        $this->assertEquals([true, 90], $packet->getExtensions()->getAudioLevel());
        $this->assertNull($packet->getExtensions()->getMid());
        $this->assertEquals("stream", $packet->getExtensions()->getRepairedRtpStreamId());
        $this->assertEquals("rtx", $packet->getExtensions()->getRtpStreamId());
        $this->assertEquals(0x156CE, $packet->getExtensions()->getTransmissionOffset());
        $this->assertEquals(0xCEAB, $packet->getExtensions()->getTransportSequenceNumber());
    }

    public function testRtx()
    {
        $extensionsMap = new HeaderExtensionsMap();
        $extensionsMap->configure(new RTCRtpParameters(
            headerExtensions: [
                new RTCRtpHeaderExtensionParameters(9, "urn:ietf:params:rtp-hdrext:sdes:mid")
            ]
        ));

        $data = file_get_contents(__DIR__ . "/fixture/rtp_with_sdes_mid.bin");
        $packet = RtpPacket::decode($data, $extensionsMap);

        // Wrap / Unwrap RTX
        $rtx = RtpUtility::wrapRtx($packet, 112, 12345, 1234);
        $recovered = RtpUtility::unwrapRtx($rtx, 111, 4084547440);

        $this->assertEquals($recovered->getVersion(), $packet->getVersion());
        $this->assertEquals($recovered->getMarker(), $packet->getMarker());
        $this->assertEquals($recovered->getPayloadType(), $packet->getPayloadType());
        $this->assertEquals($recovered->getSequenceNumber(), $packet->getSequenceNumber());
        $this->assertEquals($recovered->getTimestamp(), $packet->getTimestamp());
        $this->assertEquals($recovered->getSsrc(), $packet->getSsrc());
        $this->assertEquals($recovered->getCsrc(), $packet->getCsrc());
        $this->assertEquals($recovered->getExtensions(), $packet->getExtensions());
        $this->assertEquals($recovered->getPayload(), $packet->getPayload());
    }

    public function testComputeAudioLevelDbov()
    {
        if (!AVCodec::isAvailable()) {
            self::markTestSkipped(
                'Transcoding needs the FFI extension and an FFmpeg build matching the bundled headers.'
            );
        }

        self::assertFalse(false);
        AVCodec::init();
        $numSamples = 960; // 20ms @ 48kHz

        // FIXME
        // Test a frame of all zeroes (-9 dBov, the minimum value)
//        $silentFrame = $this->createAudioFrame(function ($n) {
//            return 0.0;
//        }, $numSamples, 0);
//        $this->assertEquals(0, RtpUtility::computeAudioLevelDbov($silentFrame->getPlanes()[0]->getData(), $numSamples));

        // Test a 50Hz square wave (0 dBov, the maximum value)
        $squareFrame = $this->createAudioFrame(function ($n) use ($numSamples) {
            return $n < $numSamples / 2 ? 1.0 : -1.0;
        }, $numSamples, 0);
        $this->assertEquals(0, RtpUtility::computeAudioLevelDbov($squareFrame->getPlanes()[0]->getData(), $numSamples));

        // Test a 50Hz sine wave (-3 dBov, the maximum value for a sine wave)
//        $sineFrame = $this->createAudioFrame(function ($n) use ($numSamples) {
//            return sin(2 * M_PI * $n / $numSamples);
//        }, $numSamples, 0);
//        $this->assertEquals(-3, RtpUtility::computeAudioLevelDbov($sineFrame->getPlanes()[0]->getData(), $numSamples));
    }

    function createAudioFrame($sample_func, $samples, $pts, $layout = "mono", $sampleRate = 48000): AudioFrame
    {
        $frame = new AudioFrame(layout: $layout, samples: $sampleRate);

        foreach ($frame->getPlanes() as $p) {
            $buf = '';
            for ($i = 0; $i < $samples; $i++) {
                $sample = (int)($sample_func($i) * 32767);
                $buf .= pack('s', $sample); // packing as signed 16-bit integer
            }
            $p->putData($buf);
        }

        $frame->setPts($pts);
        $frame->setSampleRate($sampleRate);
        $frame->setTimeBase(1, $sampleRate);

        return $frame;
    }

}
