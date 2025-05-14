<?php

namespace Tests\Webrtc\RTP;

use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTCP\RtcpByePacket;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpReceiverInfo;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTCP\RtcpSenderInfo;
use Webrtc\RTCP\RtcpSrPacket;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpRouter;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTP\Sender\RTCRtpSender;

#[UsesClass(RtpUtility::class)]
#[UsesClass(RtpPacket::class)]
#[CoversClass(RtpRouter::class)]
class RtpRouterTest extends TestCase
{
    public function testRouteRtcp()
    {
        $rtpSenderMock = Mockery::mock(RTCRtpSender::class);
        $rtpReceiverMock = Mockery::mock(RTCRtpReceiver::class);

        $router = new RtpRouter();
        $router->setReceiver($rtpReceiverMock, [1234, 2345], [96, 97]);
        $router->setSender($rtpSenderMock, 3456);

        // BYE
        $packet = new RtcpByePacket([1234, 2345]);
        $this->assertInstanceOf(RTCRtpReceiver::class, $router->routeRtcp($packet)[0]);

        // RR
        $packet = new RtcpRrPacket(
            1234,
            [
                new RtcpReceiverInfo(3456, 0, 0, 630, 1906, 0, 0)
            ]
        );
        $this->assertEquals([$rtpSenderMock], $router->routeRtcp($packet));

        // SR
        $packet = new RtcpSrPacket(
            1234,
            new RtcpSenderInfo(0, 0, 0, 0),
            [
                new RtcpReceiverInfo(3456, 0, 0, 630, 1906, 0, 0)
            ]
        );
        $this->assertEquals([$rtpReceiverMock, $rtpSenderMock], $router->routeRtcp($packet));

        // PSFB - PLI
        $packet = new RtcpPsfbPacket(RtcpConstants::RTCP_PSFB_PLI, 1234, 3456);
        $this->assertEquals([$rtpSenderMock], $router->routeRtcp($packet));

        // PSFB - REMB
        $packet = new RtcpPsfbPacket(
            RtcpConstants::RTCP_PSFB_APP,
            1234,
            0,
            RtpUtility::packRembFci(4160000, [3456])
        );
        $this->assertEquals([$rtpSenderMock], $router->routeRtcp($packet));

        // RTPFB
        $packet = new RtcpRtpfbPacket(RtcpConstants::RTCP_RTPFB_NACK, 1234, 3456);
        $this->assertEquals([$rtpSenderMock], $router->routeRtcp($packet));

        // PSFB - JUNK
        $packet = new RtcpPsfbPacket(RtcpConstants::RTCP_PSFB_APP, 1234, 0, "JUNK");
        $this->expectException(InvalidArgumentException::class);
        $this->assertEquals([], $router->routeRtcp($packet));
    }

    public function testRouteRtp()
    {
        $rtpReceiverMock1 = Mockery::mock(RTCRtpReceiver::class);
        $rtpReceiverMock2 = Mockery::mock(RTCRtpReceiver::class);

        $router = new RtpRouter();
        $router->setReceiver($rtpReceiverMock1, [1234, 2345], [96, 97]);
        $router->setReceiver($rtpReceiverMock2, [3456, 4567], [98, 99]);

        // Known SSRC and payload type
        $this->assertEquals($rtpReceiverMock1, $router->routeRtp($this->createRtpPacket(1234, 96)));
        $this->assertEquals($rtpReceiverMock1, $router->routeRtp($this->createRtpPacket(2345, 97)));
        $this->assertEquals($rtpReceiverMock2, $router->routeRtp($this->createRtpPacket(3456, 98)));
        $this->assertEquals($rtpReceiverMock2, $router->routeRtp($this->createRtpPacket(4567, 99)));

        // Unknown SSRC, known payload type
        $this->assertEquals($rtpReceiverMock1, $router->routeRtp($this->createRtpPacket(5678, 96)));
        $this->assertEquals($rtpReceiverMock2, $router->getSsrcTable()[5678]);

        // Unknown SSRC and payload type
        $this->assertNull($router->routeRtp($this->createRtpPacket(6789, 100)));
    }

    public function testRouteRtpAmbiguousPayloadType()
    {
        $rtpReceiverMock1 = Mockery::mock(RTCRtpReceiver::class);
        $rtpReceiverMock2 = Mockery::mock(RTCRtpReceiver::class);

        $router = new RtpRouter();
        $router->setReceiver($rtpReceiverMock1, [1234, 2345], [96, 97]);
        $router->setReceiver($rtpReceiverMock2, [3456, 4567], [96, 97]);

        // Known SSRC and payload type
        $this->assertEquals($rtpReceiverMock1, $router->routeRtp($this->createRtpPacket(1234, 96)));
        $this->assertEquals($rtpReceiverMock1, $router->routeRtp($this->createRtpPacket(2345, 97)));
        $this->assertEquals($rtpReceiverMock2, $router->routeRtp($this->createRtpPacket(3456, 96)));
        $this->assertEquals($rtpReceiverMock2, $router->routeRtp($this->createRtpPacket(4567, 97)));

        // Unknown SSRC, ambiguous payload type
        $this->assertNull($router->routeRtp($this->createRtpPacket(5678, 96)));
        $this->assertNull($router->routeRtp($this->createRtpPacket(5678, 97)));
    }

    private function createRtpPacket(int $ssrc, int $payloadType): RtpPacket
    {
        $rtpPacket = new RtpPacket();
        $rtpPacket->setSsrc($ssrc);
        $rtpPacket->setPayloadType($payloadType);

        return $rtpPacket;
    }
}
