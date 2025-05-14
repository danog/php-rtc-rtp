<?php

namespace Tests\Webrtc\RTP\Receiver\Rate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\RTP\Enum\BandwidthUsage;
use Webrtc\RTP\Receiver\Rate\InterArrival;
use Webrtc\RTP\Receiver\Rate\InterArrivalDelta;
use Webrtc\RTP\Receiver\Rate\OveruseDetector;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Receiver\Rate\OveruseEstimator;
use Webrtc\RTP\Receiver\Rate\TimestampGroup;

#[UsesClass(InterArrival::class)]
#[UsesClass(InterArrivalDelta::class)]
#[UsesClass(OveruseEstimator::class)]
#[UsesClass(TimestampGroup::class)]
#[CoversClass(OveruseDetector::class)]
class OveruseDetectorTest extends TestCase
{
    private OveruseDetector $detector;
    private OveruseEstimator $estimator;
    private InterArrival $interArrival;
    private int $packetSize;
    private int $nowMs;
    private int $receiveTimeMs;
    private int $rtpTimestamp;

    protected function setUp(): void
    {
        $this->detector = new OveruseDetector();
        $this->estimator = new OveruseEstimator();
        $this->interArrival = new InterArrival(5 * 90, 1 / 9);

        $this->packetSize = 1200;
        $this->nowMs = 0;
        $this->receiveTimeMs = 0;
        $this->rtpTimestamp = 900;

        mt_srand(21); // Equivalent to random.seed(21)
    }

    public function testSimpleNonOveruse30Fps(): void
    {
        $frameDurationMs = 33;

        for ($i = 0; $i < 1000; $i++) {
            $this->updateDetector($this->rtpTimestamp, $this->nowMs);
            $this->nowMs += $frameDurationMs;
            $this->rtpTimestamp += $frameDurationMs * 90;
        }

        $this->assertEquals(BandwidthUsage::NORMAL, $this->detector->state());
    }

    public function testSimpleNonOveruseWithReceiveVariance(): void
    {
        $frameDurationMs = 10;

        for ($i = 0; $i < 1000; $i++) {
            $this->updateDetector($this->rtpTimestamp, $this->nowMs);
            $this->rtpTimestamp += $frameDurationMs * 90;

            if ($i % 2) {
                $this->nowMs += $frameDurationMs - 5;
            } else {
                $this->nowMs += $frameDurationMs + 5;
            }

            $this->assertEquals(BandwidthUsage::NORMAL, $this->detector->state());
        }
    }

    public function testSimpleNonOveruseWithRtpTimestampVariance(): void
    {
        $frameDurationMs = 10;

        for ($i = 0; $i < 1000; $i++) {
            $this->updateDetector($this->rtpTimestamp, $this->nowMs);
            $this->nowMs += $frameDurationMs;

            if ($i % 2) {
                $this->rtpTimestamp += ($frameDurationMs - 5) * 90;
            } else {
                $this->rtpTimestamp += ($frameDurationMs + 5) * 90;
            }

            $this->assertEquals(BandwidthUsage::NORMAL, $this->detector->state());
        }
    }

    public function testSimpleOveruse2000KBit30Fps(): void
    {
        $packetsPerFrame = 6;
        $frameDurationMs = 33;
        $driftPerFrameMs = 1;
        $sigmaMs = 0;

        $uniqueOveruse = $this->run100000Samples($packetsPerFrame, $frameDurationMs, $sigmaMs);
        $this->assertEquals(0, $uniqueOveruse);

        $framesUntilOveruse = $this->runUntilOveruse($packetsPerFrame, $frameDurationMs, $sigmaMs, $driftPerFrameMs);
        $this->assertEquals(7, $framesUntilOveruse);
    }

    public function testSimpleOveruse100KBit10Fps(): void
    {
        $packetsPerFrame = 1;
        $frameDurationMs = 100;
        $driftPerFrameMs = 1;
        $sigmaMs = 0;

        $uniqueOveruse = $this->run100000Samples($packetsPerFrame, $frameDurationMs, $sigmaMs);
        $this->assertEquals(0, $uniqueOveruse);

        $framesUntilOveruse = $this->runUntilOveruse($packetsPerFrame, $frameDurationMs, $sigmaMs, $driftPerFrameMs);
        $this->assertEquals(7, $framesUntilOveruse);
    }

    public function testOveruseWithLowVariance2000KBit30Fps(): void
    {
        $frameDurationMs = 33;
        $driftPerFrameMs = 1;
        $this->rtpTimestamp = $frameDurationMs * 90;
        $offset = 0;

        // Run 1000 samples to reach steady state
        for ($i = 0; $i < 1000; $i++) {
            for ($j = 0; $j < 6; $j++) {
                $this->updateDetector($this->rtpTimestamp, $this->nowMs);
            }
            $this->rtpTimestamp += $frameDurationMs * 90;
            if ($i % 2) {
                $offset = mt_rand(0, 1);
                $this->nowMs += $frameDurationMs - $offset;
            } else {
                $this->nowMs += $frameDurationMs + $offset;
            }
            $this->assertEquals(BandwidthUsage::NORMAL, $this->detector->state());
        }

        // Simulate higher send pace, too high
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 6; $j++) {
                $this->updateDetector($this->rtpTimestamp, $this->nowMs);
            }
            $this->nowMs += $frameDurationMs + $driftPerFrameMs * 6;
            $this->rtpTimestamp += $frameDurationMs * 90;
            $this->assertEquals(BandwidthUsage::NORMAL, $this->detector->state());
        }

        $this->updateDetector($this->rtpTimestamp, $this->nowMs);
        $this->assertEquals(BandwidthUsage::OVERUSING, $this->detector->state());
    }

    public function testLowGaussianVarianceFastDrift30KBit3Fps(): void
    {
        $packetsPerFrame = 1;
        $frameDurationMs = 333;
        $driftPerFrameMs = 100;
        $sigmaMs = 3;

        $uniqueOveruse = $this->run100000Samples($packetsPerFrame, $frameDurationMs, $sigmaMs);
        $this->assertEquals(0, $uniqueOveruse);

        $framesUntilOveruse = $this->runUntilOveruse($packetsPerFrame, $frameDurationMs, $sigmaMs, $driftPerFrameMs);
        $this->assertEquals(4, $framesUntilOveruse);
    }

    public function testHighGaussianVariance30KBit3Fps(): void
    {
        $packetsPerFrame = 1;
        $frameDurationMs = 333;
        $driftPerFrameMs = 1;
        $sigmaMs = 10;

        $uniqueOveruse = $this->run100000Samples($packetsPerFrame, $frameDurationMs, $sigmaMs);
        $this->assertEquals(0, $uniqueOveruse);

        $framesUntilOveruse = $this->runUntilOveruse($packetsPerFrame, $frameDurationMs, $sigmaMs, $driftPerFrameMs);
        $this->assertEquals(51, $framesUntilOveruse);
    }

    private function run100000Samples($packetsPerFrame, $meanMs, $standardDeviationMs): int
    {
        $uniqueOveruse = 0;
        $lastOveruse = -1;

        for ($i = 0; $i < 100000; $i++) {
            for ($j = 0; $j < $packetsPerFrame; $j++) {
                $this->updateDetector($this->rtpTimestamp, $this->receiveTimeMs);
            }
            $this->rtpTimestamp += $meanMs * 90;
            $this->nowMs += $meanMs;
            $this->receiveTimeMs = max(
                $this->receiveTimeMs,
                (int) ($this->nowMs + $this->normalRandom($standardDeviationMs) + 0.5)
            );

            if ($this->detector->state() === BandwidthUsage::OVERUSING) {
                if ($lastOveruse + 1 !== $i) {
                    $uniqueOveruse++;
                }
                $lastOveruse = $i;
            }
        }

        return $uniqueOveruse;
    }

    private function runUntilOveruse($packetsPerFrame, $meanMs, $standardDeviationMs, $driftPerFrameMs): int
    {
        for ($i = 0; $i < 100000; $i++) {
            for ($j = 0; $j < $packetsPerFrame; $j++) {
                $this->updateDetector($this->rtpTimestamp, $this->receiveTimeMs);
            }
            $this->rtpTimestamp += $meanMs * 90;
            $this->nowMs += $meanMs + $driftPerFrameMs;
            $randomOffset = $this->normalRandom($standardDeviationMs);
            $this->receiveTimeMs = max(
                $this->receiveTimeMs,
                (int) ($this->nowMs + $randomOffset + 0.5)
            );

            if ($this->detector->state() === BandwidthUsage::OVERUSING) {
                return $i + 1;
            }
        }

        return -1;
    }

    private function updateDetector($timestamp, $receiveTimeMs): void
    {
        $deltas = $this->interArrival->computeDeltas($timestamp, $receiveTimeMs, $this->packetSize);
        if ($deltas !== null) {
            $timestampDeltaMs = $deltas->timestamp / 90;
            $this->estimator->update(
                $deltas->arrivalTime,
                $timestampDeltaMs,
                $deltas->size,
                $this->detector->state(),
            );
            $this->detector->detect(
                $this->estimator->getOffset(),
                $timestampDeltaMs,
                $this->estimator->getNumOfDeltas(),
                $receiveTimeMs
            );
        }
    }

    private function normalRandom(int $stdDev = 1): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z0 = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return $z0 * $stdDev;
    }
}

