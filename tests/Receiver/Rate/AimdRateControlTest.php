<?php

namespace Tests\Webrtc\RTP\Receiver\Rate;

use PHPUnit\Framework\Attributes\CoversClass;
use Webrtc\RTP\Enum\BandwidthUsage;
use Webrtc\RTP\Enum\RateControlState;
use Webrtc\RTP\Receiver\Rate\AimdRateControl;
use PHPUnit\Framework\TestCase;

#[CoversClass(AimdRateControl::class)]
class AimdRateControlTest extends TestCase {
    private AimdRateControl $rateControl;

    protected function setUp(): void {
        $this->rateControl = new AimdRateControl();
    }

    public function testUpdateNormal(): void {
        $bitrate = 300000;
        $nowMs = 0;
        $this->rateControl->setEstimate($bitrate, $nowMs);
        $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $bitrate, $nowMs);
        $this->assertEquals(301000, $estimate);

        $this->assertEquals(RateControlState::INCREASE, $this->rateControl->getState());
        $this->assertNull($this->rateControl->getAvgMaxBitrateKbps());
        $this->assertEquals(0.4, $this->rateControl->getVarMaxBitrateKbps());
    }

    public function testUpdateNormalNoEstimatedThroughput(): void {
        $bitrate = 300000;
        $nowMs = 0;
        $this->rateControl->setEstimate($bitrate, $nowMs);
        $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, null, $nowMs);
        $this->assertEquals(301000, $estimate);
    }

    public function testUpdateOveruse(): void {
        $bitrate = 300000;
        $nowMs = 0;
        $this->rateControl->setEstimate($bitrate, $nowMs);
        $estimate = $this->rateControl->update(BandwidthUsage::OVERUSING, $bitrate, $nowMs);
        $this->assertEquals(255000, $estimate);

        $this->assertEquals(RateControlState::HOLD, $this->rateControl->getState());
        $this->assertEquals(300.0, $this->rateControl->getAvgMaxBitrateKbps());
        $this->assertEquals(0.4, $this->rateControl->getVarMaxBitrateKbps());
    }

    public function testUpdateUnderUse(): void {
        $bitrate = 300000;
        $nowMs = 0;
        $this->rateControl->setEstimate($bitrate, $nowMs);
        $estimate = $this->rateControl->update(BandwidthUsage::UNDERUSING, $bitrate, $nowMs);
        $this->assertEquals(300000, $estimate);

        $this->assertEquals(RateControlState::HOLD, $this->rateControl->getState());
        $this->assertNull($this->rateControl->getAvgMaxBitrateKbps());
        $this->assertEquals(0.4, $this->rateControl->getVarMaxBitrateKbps());
    }

    public function testAdditiveRateIncrease(): void {
        $ackedBitrate = 100000;
        $this->rateControl->setEstimate($ackedBitrate, 0);
        for ($nowMs = 0; $nowMs < 20000; $nowMs += 100) {
            $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate, $nowMs);
        }
        $this->assertEquals(160000, $estimate);
        $this->assertFalse($this->rateControl->isNearMax());

        // overuse -> hold
        $estimate = $this->rateControl->update(BandwidthUsage::OVERUSING, $ackedBitrate, $nowMs);
        $this->assertEquals(85000, $estimate);
        $this->assertTrue($this->rateControl->isNearMax());
        $nowMs += 1000;

        // back to normal -> hold
        $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate, $nowMs);
        $this->assertEquals(85000, $estimate);
        $this->assertTrue($this->rateControl->isNearMax());
        $nowMs += 1000;

        // still normal -> additive increase
        $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate, $nowMs);
        $this->assertEquals(94444, $estimate);
        $this->assertTrue($this->rateControl->isNearMax());
        $nowMs += 1000;

        // overuse -> hold
        $estimate = $this->rateControl->update(BandwidthUsage::OVERUSING, $ackedBitrate, $nowMs);
        $this->assertEquals(85000, $estimate);
        $this->assertTrue($this->rateControl->isNearMax());
    }

    public function testClearMaxThroughput(): void {
        $normalBitrate = 100000;
        $highBitrate = 150000;
        $nowMs = 0;
        $this->rateControl->setEstimate($normalBitrate, $nowMs);
        $this->rateControl->update(BandwidthUsage::NORMAL, $normalBitrate, $nowMs);
        $nowMs += 1000;

        // overuse
        $this->rateControl->update(BandwidthUsage::OVERUSING, $normalBitrate, $nowMs);
        $this->assertEquals(100.0, $this->rateControl->getAvgMaxBitrateKbps());
        $nowMs += 1000;

        // stable
        $this->rateControl->update(BandwidthUsage::NORMAL, $normalBitrate, $nowMs);
        $this->assertEquals(100.0, $this->rateControl->getAvgMaxBitrateKbps());
        $nowMs += 1000;

        // large increase in throughput
        $this->rateControl->update(BandwidthUsage::NORMAL, $highBitrate, $nowMs);
        $this->assertNull($this->rateControl->getAvgMaxBitrateKbps());
        $nowMs += 1000;

        // overuse
        $this->rateControl->update(BandwidthUsage::OVERUSING, $highBitrate, $nowMs);
        $this->assertEquals(150.0, $this->rateControl->getAvgMaxBitrateKbps());
        $nowMs += 1000;

        // overuse and large decrease in throughput
        $this->rateControl->update(BandwidthUsage::OVERUSING, $normalBitrate, $nowMs);
        $this->assertEquals(100.0, $this->rateControl->getAvgMaxBitrateKbps());
    }

    public function testBweLimitedByAckedBitrate(): void {
        $ackedBitrate = 10000;
        $this->rateControl->setEstimate($ackedBitrate, 0);
        for ($nowMs = 0; $nowMs < 20000; $nowMs += 100) {
            $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate, $nowMs);
        }
        $this->assertEquals(25000, $estimate);
    }

    public function testBweNotLimitedByDecreasingAckedBitrate(): void {
        $ackedBitrate = 100000;
        $this->rateControl->setEstimate($ackedBitrate, 0);
        for ($nowMs = 0; $nowMs < 20000; $nowMs += 100) {
            $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate, $nowMs);
        }
        $this->assertEquals(160000, $estimate);

        // estimate doesn't change
        $estimate = $this->rateControl->update(BandwidthUsage::NORMAL, $ackedBitrate / 2, $nowMs);
        $this->assertEquals(160000, $estimate);
    }
}
