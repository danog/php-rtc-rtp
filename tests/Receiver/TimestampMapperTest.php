<?php

namespace Tests\Webrtc\RTP\Receiver;

use PHPUnit\Framework\Attributes\CoversClass;
use Webrtc\RTP\Receiver\TimestampMapper;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimestampMapper::class)]
class TimestampMapperTest extends TestCase {
    public function testSimple(): void {
        $mapper = new TimestampMapper();

        $this->assertEquals(0, $mapper->map(1000));
        $this->assertEquals(1, $mapper->map(1001));
        $this->assertEquals(3, $mapper->map(1003));
        $this->assertEquals(4, $mapper->map(1004));
        $this->assertEquals(10, $mapper->map(1010));
    }

    public function testWrap(): void {
        $mapper = new TimestampMapper();

        $this->assertEquals(0, $mapper->map(4294967293));
        $this->assertEquals(1, $mapper->map(4294967294));
        $this->assertEquals(2, $mapper->map(4294967295));
        $this->assertEquals(3, $mapper->map(0));
        $this->assertEquals(4, $mapper->map(1));
    }
}
