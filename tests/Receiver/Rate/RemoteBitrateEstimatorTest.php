<?php

namespace Tests\Webrtc\RTP\Receiver\Rate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Receiver\Rate\AimdRateControl;
use Webrtc\RTP\Receiver\Rate\InterArrival;
use Webrtc\RTP\Receiver\Rate\InterArrivalDelta;
use Webrtc\RTP\Receiver\Rate\OveruseDetector;
use Webrtc\RTP\Receiver\Rate\OveruseEstimator;
use Webrtc\RTP\Receiver\Rate\RateBucket;
use Webrtc\RTP\Receiver\Rate\RateCounter;
use Webrtc\RTP\Receiver\Rate\RemoteBitrateEstimator;
use Webrtc\RTP\Receiver\Rate\TimestampGroup;

#[UsesClass(AimdRateControl::class)]
#[UsesClass(InterArrival::class)]
#[UsesClass(InterArrivalDelta::class)]
#[UsesClass(OveruseDetector::class)]
#[UsesClass(OveruseEstimator::class)]
#[UsesClass(RateBucket::class)]
#[UsesClass(RateCounter::class)]
#[UsesClass(TimestampGroup::class)]
#[CoversClass(RemoteBitrateEstimator::class)]
class RemoteBitrateEstimatorTest extends TestCase
{
    public function testCapacityDrop()
    {
        $estimator = new RemoteBitrateEstimator();
        $stream = new Stream(500000);
        $targetBitrate = null;

        foreach ($stream->generateFrames(1000) as $frame) {
            list($absSendTime, $arrivalTimeMs, $payloadSize) = $frame;
            $res = $estimator->add(arrivalTimeMs: $arrivalTimeMs, absSendTime: $absSendTime, payloadSize: $payloadSize, ssrc: 1234);
            if ($res !== null) {
                $targetBitrate = $res[0];
            }
        }
        $this->assertEquals(550000, $targetBitrate);

        // Reduce capacity
        $stream = new Stream(250000);

        foreach ($stream->generateFrames(1000) as $frame) {
            list($absSendTime, $arrivalTimeMs, $payloadSize) = $frame;
            $res = $estimator->add(arrivalTimeMs: $arrivalTimeMs, absSendTime: $absSendTime, payloadSize: $payloadSize, ssrc: 1234);
            if ($res !== null) {
                $targetBitrate = $res[0];
            }
        }
        $this->assertEquals(214200, $targetBitrate);
    }
}