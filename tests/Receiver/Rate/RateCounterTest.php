<?php

namespace Tests\Webrtc\RTP\Receiver\Rate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\RTP\Receiver\Rate\RateBucket;
use Webrtc\RTP\Receiver\Rate\RateCounter;
use PHPUnit\Framework\TestCase;

#[UsesClass(RateBucket::class)]
#[CoversClass(RateCounter::class)]
class RateCounterTest extends TestCase
{
    public function testConstructor()
    {
        $counter = new RateCounter(10);

        $this->assertEquals(
            array_fill(0, 10, new RateBucket()),
            $counter->getBuckets()
        );

        $this->assertNull($counter->getOriginMs());
        $this->assertEquals(0, $counter->getOriginIndex());
        $this->assertEquals(new RateBucket(), $counter->getTotal());
        $this->assertNull($counter->rate(0));
    }

    public function testAdd()
    {
        $counter = new RateCounter(10);

        $counter->add(500, 123);
        $this->assertEquals(
            [
                new RateBucket(1, 500), new RateBucket(), new RateBucket(),
                new RateBucket(), new RateBucket(), new RateBucket(),
                new RateBucket(), new RateBucket(), new RateBucket(), new RateBucket()
            ],
            $counter->getBuckets()
        );
        $this->assertEquals(0, $counter->getOriginIndex());
        $this->assertEquals(123, $counter->getOriginMs());
        $this->assertEquals(new RateBucket(1, 500), $counter->getTotal());
        $this->assertNull($counter->rate(123));

        $counter->add(501, 123);
        $this->assertEquals(
            [
                new RateBucket(2, 1001), new RateBucket(), new RateBucket(),
                new RateBucket(), new RateBucket(), new RateBucket(),
                new RateBucket(), new RateBucket(), new RateBucket(), new RateBucket()
            ],
            $counter->getBuckets()
        );
        $this->assertEquals(new RateBucket(2, 1001), $counter->getTotal());
        $this->assertNull($counter->rate(123));

        $counter->add(502, 125);
        $this->assertEquals(4008000, $counter->rate(125));

        $counter->add(503, 128);
        $this->assertEquals(2674667, $counter->rate(128));

        $counter->add(504, 132);
        $this->assertEquals(2008000, $counter->rate(132));

        $counter->add(505, 134);
        $this->assertEquals(1611200, $counter->rate(134));

        $counter->add(506, 135);
        $this->assertEquals(1614400, $counter->rate(135));
    }
}

