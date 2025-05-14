<?php

namespace Tests\Webrtc\RTP\Receiver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\RtpPacket;

#[CoversClass(AbstractRtpReceiver::class)]
abstract class AbstractRtpReceiver extends TestCase
{
    protected function createRtpPackets(int $count, int $sequence): array
    {
        $packets = [];
        for ($i = 0; $i < $count; $i++) {
            $packet = new RtpPacket();
            $packet->setPayload(0);
            $packet->setSequenceNumber((($sequence + $i) & 0xFFFF));
            $packet->setSsrc(1234);
            $packet->setTimestamp($i * 160);

            $packets [] = $packet;
        }
        return $packets;
    }

    public function testTrue()
    {
        $this->assertTrue(true);
    }
}