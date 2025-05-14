<?php

namespace Tests\Webrtc\RTP\Receiver;


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bridge\PhpUnit\ClockMock;
use Webrtc\RTP\Receiver\StreamStatistics;
use Webrtc\RTP\RtpPacket;

#[UsesClass(RtpPacket::class)]
#[CoversClass(StreamStatistics::class)]
class StreamStatisticsTest extends AbstractRtpReceiver
{
    private StreamStatistics $streamStatistics;

    public function setUp(): void
    {
        $this->streamStatistics = new StreamStatistics(8000);
        ClockMock::register(StreamStatistics::class);
    }

    protected function tearDown(): void
    {
        // Disable ClockMock after the test
        ClockMock::withClockMock(false);
    }

    public function testNoLoss()
    {
        $packets = $this->createRtpPackets(20, 0);

        // Receive 10 packets
        for ($i = 0; $i < 10; $i++) {
            $this->streamStatistics->add($packets[$i]);
        }

        $this->assertEquals(9, $this->streamStatistics->getMaxSeq());
        $this->assertEquals(10, $this->streamStatistics->getPacketsReceived());
        $this->assertEquals(0, $this->streamStatistics->getPacketsLost());
        $this->assertEquals(0, $this->streamStatistics->getFractionLost());

        // Receive 10 more packets
        for ($i = 10; $i < 20; $i++) {
            $this->streamStatistics->add($packets[$i]);
        }

        $this->assertEquals(19, $this->streamStatistics->getMaxSeq());
        $this->assertEquals(20, $this->streamStatistics->getPacketsReceived());
        $this->assertEquals(0, $this->streamStatistics->getPacketsLost());
        $this->assertEquals(0, $this->streamStatistics->getFractionLost());
    }

    public function testNoLossCycle()
    {
        // Receive 10 packets (with sequence cycle)
        $packets = $this->createRtpPackets(10, 65530);
        foreach ($packets as $packet) {
            $this->streamStatistics->add($packet);
        }

        $this->assertEquals(3, $this->streamStatistics->getMaxSeq());
        $this->assertEquals(10, $this->streamStatistics->getPacketsReceived());
        $this->assertEquals(0, $this->streamStatistics->getPacketsLost());
        $this->assertEquals(0, $this->streamStatistics->getFractionLost());
    }

    public function testWithLoss()
    {
        $packets = $this->createRtpPackets(20, 0);
        array_splice($packets, 1, 1); // Simulate packet loss

        // Receive 9 packets (one missing)
        for ($i = 0; $i < 9; $i++) {
            $this->streamStatistics->add($packets[$i]);
        }

        $this->assertEquals(9, $this->streamStatistics->getMaxSeq());
        $this->assertEquals(9, $this->streamStatistics->getPacketsReceived());
        $this->assertEquals(1, $this->streamStatistics->getPacketsLost());
        $this->assertEquals(25, $this->streamStatistics->getFractionLost());

        // Receive 10 more packets
        for ($i = 9; $i < 19; $i++) {
            $this->streamStatistics->add($packets[$i]);
        }

        $this->assertEquals(19, $this->streamStatistics->getMaxSeq());
        $this->assertEquals(19, $this->streamStatistics->getPacketsReceived());
        $this->assertEquals(1, $this->streamStatistics->getPacketsLost());
        $this->assertEquals(0, $this->streamStatistics->getFractionLost());
    }

    public function testNoJitter()
    {
        $packets = $this->createRtpPackets(3, 0);

        // Mock time to 1531562330.00
        ClockMock::withClockMock(1531562330.00);
        $this->streamStatistics->add($packets[0], microtime(true));
        $this->assertEquals(0, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(0, $this->streamStatistics->getJitter());

        // Mock time to 1531562330.02
        ClockMock::withClockMock(1531562330.02);
        $this->streamStatistics->add($packets[1], microtime(true));
        $this->assertEquals(0, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(0, $this->streamStatistics->getJitter());

        // Mock time to 1531562330.04
        ClockMock::withClockMock(1531562330.04);
        $this->streamStatistics->add($packets[2], microtime(true));
        $this->assertEquals(0, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(0, $this->streamStatistics->getJitter());
    }

    public function testWithJitter()
    {
        $packets = $this->createRtpPackets(3, 0);

        ClockMock::withClockMock(1531562330.00);
        $this->streamStatistics->add($packets[0]);
        $this->assertEquals(0, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(0, $this->streamStatistics->getJitter());


        ClockMock::withClockMock(1531562330.03);
        $this->streamStatistics->add($packets[1], microtime(true));
        $this->assertEquals(80, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(5, $this->streamStatistics->getJitter());

        ClockMock::withClockMock(1531562330.05);
        $this->streamStatistics->add($packets[2], microtime(true));
        $this->assertEquals(75, $this->streamStatistics->getJitterQ4());
        $this->assertEquals(4, $this->streamStatistics->getJitter());
    }
}
