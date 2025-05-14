<?php

namespace Tests\Webrtc\RTP\Jitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\RTP\Jitter\JitterBuffer;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Jitter\JitterFrame;
use Webrtc\RTP\RtpPacket;

#[UsesClass(RtpPacket::class)]
#[UsesClass(JitterFrame::class)]
#[CoversClass(JitterBuffer::class)]
class JitterBufferTest extends TestCase
{
    private function assertPackets(JitterBuffer $jBuffer, array $expected): void
    {
        $found = array_map(function ($packet) {
            return $packet?->getSequenceNumber();
        }, $jBuffer->getPackets());
        $this->assertEquals($expected, $found);
    }

    public function testCreate(): void
    {
        $jBuffer = new JitterBuffer(2);
        $this->assertEquals([null, null], $jBuffer->getPackets());
        $this->assertNull($jBuffer->getOrigin());

        $jBuffer = new JitterBuffer(4);
        $this->assertEquals([null, null, null, null], $jBuffer->getPackets());
        $this->assertNull($jBuffer->getOrigin());
    }

    public function testAddOrdered(): void
    {
        $jBuffer = new JitterBuffer(4);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [0, null, null, null]);
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [0, 1, null, null]);
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [0, 1, 2, null]);
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [0, 1, 2, 3]);
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);
    }

    public function testAddUnordered(): void
    {
        $jBuffer = new JitterBuffer(4);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, 1, null, null]);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, 1, null, 3]);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, 1, 2, 3]);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);
    }

    public function testAddSeqTooLowDrop(): void
    {
        $jBuffer = new JitterBuffer(4);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, null, 2, null]);
        $this->assertEquals(2, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, null, 2, null]);
        $this->assertEquals(2, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);
    }

    public function testAddSeqTooLowReset(): void
    {
        $jBuffer = new JitterBuffer(4);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2000, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [2000, null, null, null]);
        $this->assertEquals(2000, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertNull($frame);
        $this->assertPackets($jBuffer, [null, 1, null, null]);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);
    }

    public function testAddSeqTooHighDiscardOne(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(4, 1234));
        $this->assertEquals(4, $jBuffer->getOrigin());

        $this->assertPackets($jBuffer, [4, null, null, null]);
    }

    public function testAddSeqTooHighDiscardOneV2(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(3, 1235));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(4, 1235));
        $this->assertEquals(3, $jBuffer->getOrigin());

        $this->assertPackets($jBuffer, [4, null, null, 3]);
    }

    public function testAddSeqTooHighDiscardFour(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(7, 1235));
        $this->assertEquals(7, $jBuffer->getOrigin());

        $this->assertPackets($jBuffer, [null, null, null, 7]);
    }

    public function testAddSeqTooHighDiscardMore(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(2, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());

        $jBuffer->add($this->createRtpPacket(8, 1234));
        $this->assertEquals(8, $jBuffer->getOrigin());

        $this->assertPackets($jBuffer, [8, null, null, null]);
    }

    public function testAddSeqTooHighReset(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [0, null, null, null]);

        $jBuffer->add($this->createRtpPacket(3000, 1234));
        $this->assertEquals(3000, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [3000, null, null, null]);
    }

    public function testRemove(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $jBuffer->add($this->createRtpPacket(1, 1234));
        $jBuffer->add($this->createRtpPacket(2, 1234));
        $jBuffer->add($this->createRtpPacket(3, 1234));
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [0, 1, 2, 3]);

        // Remove 1 packet
        $jBuffer->remove(1);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [null, 1, 2, 3]);

        // Remove 2 packets
        $jBuffer->remove(2);
        $this->assertEquals(3, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [null, null, null, 3]);
    }

    public function testSmartRemove(): void
    {
        $jBuffer = new JitterBuffer(4);

        $jBuffer->add($this->createRtpPacket(0, 1234));
        $jBuffer->add($this->createRtpPacket(1, 1234));
        $jBuffer->add($this->createRtpPacket(3, 1235));
        $this->assertEquals(0, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [0, 1, null, 3]);

        // Remove 1 packet
        $jBuffer->smartRemove(1);
        $this->assertEquals(3, $jBuffer->getOrigin());
        $this->assertPackets($jBuffer, [null, null, null, 3]);
    }

    public function testRemoveAudioFrame(): void
    {
        $jBuffer = new JitterBuffer(16, 4);

        $packet = $this->createRtpPacket(0, 1234);
        $packet->setDecodedData("\x00\x00");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(1, 1235);
        $packet->setDecodedData("\x00\x01");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(2, 1236);
        $packet->setDecodedData("\x00\x02");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(3, 1237);
        $packet->setDecodedData("\x00\x03");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(4, 1238);
        $packet->setDecodedData("\x00\x03");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNotNull($frame);
        $this->assertEquals("\x00\x00", $frame->getData());
        $this->assertEquals(1234, $frame->getTimestamp());

        $packet = $this->createRtpPacket(5, 1239);
        $packet->setDecodedData("\x00\x04");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNotNull($frame);
        $this->assertEquals("\x00\x01", $frame->getData());
        $this->assertEquals(1235, $frame->getTimestamp());
    }

    public function testRemoveVideoFrame(): void
    {
        $jBuffer = new JitterBuffer(128, 0, true);

        $packet = $this->createRtpPacket(0, 1234);
        $packet->setDecodedData("\x00\x00");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(1, 1234);
        $packet->setDecodedData("\x00\x01");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(2, 1234);
        $packet->setDecodedData("\x00\x02");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNull($frame);

        $packet = $this->createRtpPacket(3, 1235);
        $packet->setDecodedData("\x00\x03");
        [, $frame] = $jBuffer->add($packet);
        $this->assertNotNull($frame);
        $this->assertEquals("\x00\x00\x00\x01\x00\x02", $frame->getData());
        $this->assertEquals(1234, $frame->getTimestamp());
    }

    public function testPliFlag(): void
    {
        $jBuffer = new JitterBuffer(128, 0, true);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2000, 1234));
        $this->assertNull($frame);
        $this->assertEquals(2000, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        // Test add sequence too low reset for video (capacity >= 128)
        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(1, 1234));
        $this->assertNull($frame);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertTrue($pliFlag);

        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(128, 1235));
        $this->assertNull($frame);
        $this->assertEquals(1, $jBuffer->getOrigin());
        $this->assertFalse($pliFlag);

        // Test add sequence too high discard one for video (capacity >= 128)
        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(129, 1235));
        $this->assertNull($frame);
        $this->assertEquals(128, $jBuffer->getOrigin());
        $this->assertTrue($pliFlag);

        // Test add sequence too high reset for video (capacity >= 128)
        list($pliFlag, $frame) = $jBuffer->add($this->createRtpPacket(2000, 2345));
        $this->assertNull($frame);
        $this->assertEquals(2000, $jBuffer->getOrigin());
        $this->assertTrue($pliFlag);
    }

    private function createRtpPacket(int $sequenceNumber, int $timeStamp): RtpPacket
    {
        $rtpPacket = new RtpPacket();
        $rtpPacket->setSequenceNumber($sequenceNumber);
        $rtpPacket->setTimestamp($timeStamp);
        
        return $rtpPacket;
    }
}
