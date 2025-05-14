<?php

namespace Tests\Webrtc\RTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Exception\RtpPacketException;
use Webrtc\RTP\HeaderExtension\HeaderExtensions;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\RTPParameter\RTCRtpParameters;

#[UsesClass(HeaderExtensions::class)]
#[UsesClass(HeaderExtensionsMap::class)]
#[UsesClass(RtpUtility::class)]
#[CoversClass(RtpPacket::class)]
class RtpPacketTest extends TestCase
{
    public function testDtmf()
    {
        $data = $this->load("rtp_dtmf.bin");
        $packet = RtpPacket::decode($data);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(1, $packet->getMarker());
        $this->assertEquals(101, $packet->getPayloadType());
        $this->assertEquals(24152, $packet->getSequenceNumber());
        $this->assertEquals(4021352124, $packet->getTimestamp());
        $this->assertEquals([], $packet->getCsrc());
        $this->assertEquals(new HeaderExtensions(), $packet->getExtensions());
        $this->assertEquals(4, strlen($packet->getPayload()));
        $this->assertEquals($data, $packet->encode());
    }

    public function testNoSsrc()
    {
        $data = $this->load("rtp.bin");
        $packet = RtpPacket::decode($data);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(0, $packet->getMarker());
        $this->assertEquals(0, $packet->getPayloadType());
        $this->assertEquals(15743, $packet->getSequenceNumber());
        $this->assertEquals(3937035252, $packet->getTimestamp());
        $this->assertEquals([], $packet->getCsrc());
        $this->assertEquals(new HeaderExtensions(), $packet->getExtensions());
        $this->assertEquals(160, strlen($packet->getPayload()));
        $this->assertEquals($data, $packet->encode());
    }

    public function testPaddingOnly()
    {
        $data = $this->load("rtp_only_padding.bin");
        $packet = RtpPacket::decode($data);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(0, $packet->getMarker());
        $this->assertEquals(120, $packet->getPayloadType());
        $this->assertEquals(27759, $packet->getSequenceNumber());
        $this->assertEquals(4044047131, $packet->getTimestamp());
        $this->assertEquals([], $packet->getCsrc());
        $this->assertEquals(new HeaderExtensions(), $packet->getExtensions());
        $this->assertEquals(0, strlen($packet->getPayload()));
        $this->assertEquals(224, $packet->getPaddingSize());

        $encoded = $packet->encode();
        $this->assertEquals(strlen($data), strlen($encoded));
        $this->assertEquals(substr($data, 0, 12), substr($encoded, 0, 12));
        $this->assertEquals($data[strlen($data) - 1], $encoded[strlen($encoded) - 1]);
    }

    public function testPaddingTooLong()
    {
        $data = substr($this->load("rtp_only_padding.bin"), 0, 12) . "\x02";
        $this->expectException(RtpPacketException::class);
        $this->expectExceptionMessage("RTP packet padding length is invalid");
        RtpPacket::decode($data);
    }

    public function testTruncated()
    {
        $data = substr($this->load("rtp.bin"), 0, 11);
        $this->expectException(RtpPacketException::class);
        $this->expectExceptionMessage("RTP packet length is less than 12 bytes");
        RtpPacket::decode($data);
    }

    public function testPaddingZero()
    {
        $data = substr($this->load("rtp_only_padding.bin"), 0, 12) . "\x00";
        $this->expectException(RtpPacketException::class);
        $this->expectExceptionMessage("RTP packet padding length is invalid");
        RtpPacket::decode($data);
    }

    public function testWithCsrc()
    {
        $data = $this->load("rtp_with_csrc.bin");
        $packet = RtpPacket::decode($data);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(0, $packet->getMarker());
        $this->assertEquals(0, $packet->getPayloadType());
        $this->assertEquals(16082, $packet->getSequenceNumber());
        $this->assertEquals(144, $packet->getTimestamp());
        $this->assertEquals([2882400001, 3735928559], $packet->getCsrc());
        $this->assertEquals(new HeaderExtensions(), $packet->getExtensions());
        $this->assertEquals(160, strlen($packet->getPayload()));
        $this->assertEquals($data, $packet->encode());
    }

    public function testBadVersion()
    {
        $data = "\xc0" . substr($this->load("rtp.bin"), 1);
        $this->expectException(RtpPacketException::class);
        $this->expectExceptionMessage("RTP packet has invalid version");
        RtpPacket::decode($data);
    }

    public function testWithCsrcTruncated()
    {
        $data = $this->load("rtp_with_csrc.bin");
        for ($length = 12; $length < 20; $length++) {
            $this->expectException(RtpPacketException::class);
            $this->expectExceptionMessage("RTP packet has truncated CSRC");
            RtpPacket::decode(substr($data, 0, $length));
        }
    }

    public function testPaddingOnlyWithHeaderExtensions()
    {
        $extensionsMap = new HeaderExtensionsMap();
        $extensionsMap->configure(
            new RTCRtpParameters(headerExtensions: [new RTCRtpHeaderExtensionParameters(2,  "http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time")])
        );

        $data = $this->load("rtp_only_padding_with_header_extensions.bin");
        $packet = RtpPacket::decode($data, $extensionsMap);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(0, $packet->getMarker());
        $this->assertEquals(98, $packet->getPayloadType());
        $this->assertEquals(22138, $packet->getSequenceNumber());
        $this->assertEquals(3171065731, $packet->getTimestamp());
        $this->assertEquals([], $packet->getCsrc());
        $expectedHeader = new HeaderExtensions();
        $expectedHeader->setAbsSendTime(15846540);
        $this->assertEquals($expectedHeader, $packet->getExtensions());
        $this->assertEquals(0, strlen($packet->getPayload()));

        $encoded = $packet->encode($extensionsMap);
        $this->assertEquals(strlen($data), strlen($encoded));
        $this->assertEquals(substr($data, 0, 20), substr($encoded, 0, 20));
        $this->assertEquals($data[strlen($data) - 1], $encoded[strlen($encoded) - 1]);
    }

    public function testWithSdesMid()
    {
        $extensionsMap = new HeaderExtensionsMap();
        $extensionsMap->configure(
            new RTCRtpParameters(headerExtensions: [new RTCRtpHeaderExtensionParameters(9,  "urn:ietf:params:rtp-hdrext:sdes:mid")])
        );

        $data = $this->load("rtp_with_sdes_mid.bin");
        $packet = RtpPacket::decode($data, $extensionsMap);

        $this->assertEquals(2, $packet->getVersion());
        $this->assertEquals(1, $packet->getMarker());
        $this->assertEquals(111, $packet->getPayloadType());
        $this->assertEquals(14156, $packet->getSequenceNumber());
        $this->assertEquals(1327210925, $packet->getTimestamp());
        $this->assertEquals([], $packet->getCsrc());
        $expectedHeader = new HeaderExtensions();
        $expectedHeader->setMid(0);
        $this->assertEquals($expectedHeader, $packet->getExtensions());
        $this->assertEquals(54, strlen($packet->getPayload()));
        $this->assertEquals($data, $packet->encode($extensionsMap));
    }

    public function testWithSdesMidTruncated()
    {
        $data = $this->load("rtp_with_sdes_mid.bin");
        for ($length = 12; $length < 20; $length++) {
            $this->expectException(RtpPacketException::class);
            if ($length < 16) {
                $this->expectExceptionMessage("RTP packet has truncated extension profile/length");
            } else {
                $this->expectExceptionMessage("RTP packet has truncated extension value");
            }
            RtpPacket::decode(substr($data, 0, $length));
        }
    }

    private function load($filename)
    {
        return file_get_contents(__DIR__ . "/fixture/$filename");
    }
}
