<?php

namespace Tests\Webrtc\RTP\Receiver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\RTP\Receiver\NackGenerator;
use Webrtc\RTP\RtpPacket;

#[UsesClass(RtpPacket::class)]
#[CoversClass(NackGenerator::class)]
class NackGeneratorTest extends AbstractRtpReceiver
{
    public function testNoLoss()
    {
        $generator = new NackGenerator();

        foreach ($this->createRtpPackets(20, 0) as $packet) {
            $missed = $generator->add($packet);
            $this->assertFalse($missed);
        }

        $this->assertEquals([], $generator->getMissing());
    }

    public function testWithLoss()
    {
        $generator = new NackGenerator();

        // receive packets: 0, <1 missing>, 2
        $packets = $this->createRtpPackets(3, 0);
        $missing = $packets[1];
        unset($packets[1]);

        foreach ($packets as $packet) {
            $missed = $generator->add($packet);
            $this->assertEquals($missed, $packet->getSequenceNumber() == 2);
        }

        $this->assertEquals([1], $generator->getMissing());

        // late arrival
        $missed = $generator->add($missing);
        $this->assertFalse($missed);
        $this->assertEquals([], $generator->getMissing());
    }

    public function testWithLossTruncate()
    {
        $generator = new NackGenerator();
        $packets = $this->createRtpPackets(259, 0);

        $generator->add($packets[0]);
        $generator->add($packets[129]);
        $this->assertEquals(range(1, 128), $generator->getMissing());

        $generator->add($packets[258]);
        $this->assertEquals(range(130, 257), $generator->getMissing());
    }
}

