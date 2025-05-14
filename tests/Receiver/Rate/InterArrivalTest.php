<?php

namespace Tests\Webrtc\RTP\Receiver\Rate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Receiver\Rate\InterArrival;
use Webrtc\RTP\Receiver\Rate\InterArrivalDelta;
use Webrtc\RTP\Receiver\Rate\TimestampGroup;

#[UsesClass(TimestampGroup::class)]
#[UsesClass(InterArrivalDelta::class)]
#[CoversClass(InterArrival::class)]
class InterArrivalTest extends TestCase
{
    private const int TIMESTAMP_GROUP_LENGTH_US = 5000;
    private const int BURST_THRESHOLD_MS = 5;
    private const int MIN_STEP_US = 20;
    private const int START_RTP_TIMESTAMP_WRAP_US = 47721858827;
    private const int START_ABS_SEND_TIME_WRAP_US = 63999995;
    private const int TRIGGER_NEW_GROUP_US = self::TIMESTAMP_GROUP_LENGTH_US + self::MIN_STEP_US;
    private InterArrival $interArrivalAst;
    private InterArrival $interArrivalRtp;

    protected function setUp(): void
    {
        $this->interArrivalAst = new InterArrival(
            $this->absSendTime(self::TIMESTAMP_GROUP_LENGTH_US), 1000 / (1 << 26)
        );
        $this->interArrivalRtp = new InterArrival(
            $this->rtpTimestamp(self::TIMESTAMP_GROUP_LENGTH_US), 1 / 9
        );
    }

    private function absSendTime(int $us): int
    {
        $absoluteSendTime = intval((($us << 18) + 500000) / 1000000) & 0xFFFFFF;
        return $absoluteSendTime << 8;
    }

    private function rtpTimestamp(int $us): int
    {
        return intval(($us * 90 + 500) / 1000) & 0xFFFFFFFF;
    }

    private function assertComputed(
        int $timestampUs,
        int $arrivalTimeMs,
        int $packetSize,
        int $timestampDeltaUs,
        int $arrivalTimeDeltaMs,
        int $packetSizeDelta,
        int $timestampNear = 0
    ): void
    {
        // $this->absSendTime
        $deltas = $this->interArrivalAst->computeDeltas(
            $this->absSendTime($timestampUs), $arrivalTimeMs, $packetSize
        );
        $this->assertNotNull($deltas);
        $this->assertEqualsWithDelta(
            $this->absSendTime($timestampDeltaUs),
            $deltas->timestamp,
            $timestampNear << 8
        );
        $this->assertEquals($arrivalTimeDeltaMs, $deltas->arrivalTime);
        $this->assertEquals($packetSizeDelta, $deltas->size);

        // $this->rtpTimestamp
        $deltas = $this->interArrivalRtp->computeDeltas(
            $this->rtpTimestamp($timestampUs), $arrivalTimeMs, $packetSize
        );
        $this->assertNotNull($deltas);
        $this->assertEqualsWithDelta(
            $this->rtpTimestamp($timestampDeltaUs),
            $deltas->timestamp,
            $timestampNear
        );
        $this->assertEquals($arrivalTimeDeltaMs, $deltas->arrivalTime);
        $this->assertEquals($packetSizeDelta, $deltas->size);
    }

    private function assertNotComputed(int $timestampUs, int $arrivalTimeMs, int $packetSize): void
    {
        $this->assertNull(
            $this->interArrivalAst->computeDeltas(
                $this->absSendTime($timestampUs), $arrivalTimeMs, $packetSize
            )
        );
        $this->assertNull(
            $this->interArrivalRtp->computeDeltas(
                $this->rtpTimestamp($timestampUs), $arrivalTimeMs, $packetSize
            )
        );
    }

    private function wrapTest(int $wrapStartUs, bool $unOrderlyWithinGroup): void
    {
        $timestampNear = 1;

        // G1
        $arrivalTime = 17;
        $this->assertNotComputed(0, $arrivalTime, 1);

        // G2
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed(intval($wrapStartUs / 4), $arrivalTime, 1);

        // G3
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            intval($wrapStartUs / 2), $arrivalTime, 1, intval($wrapStartUs / 4), 6, 0
        );

        // G4
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            intval($wrapStartUs / 2 + $wrapStartUs / 4),
            $arrivalTime,
            1,
            intval($wrapStartUs / 4),
            6,
            0,
            $timestampNear
        );
        $g4ArrivalTime = $arrivalTime;

        // G5
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $wrapStartUs, $arrivalTime, 2, intval($wrapStartUs / 4), 6, 0, $timestampNear
        );
        for ($i = 0; $i < 10; $i++) {
            $arrivalTime += self::BURST_THRESHOLD_MS + 1;
            if ($unOrderlyWithinGroup) {
                $this->assertNotComputed(
                    $wrapStartUs + (9 - $i) * self::MIN_STEP_US, $arrivalTime, 1
                );
            } else {
                $this->assertNotComputed($wrapStartUs + $i * self::MIN_STEP_US, $arrivalTime, 1);
            }
        }
        $g5ArrivalTime = $arrivalTime;

        // out of order
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed($wrapStartUs - 100, $arrivalTime, 100);

        // G6
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $wrapStartUs + self::TRIGGER_NEW_GROUP_US,
            $arrivalTime,
            10,
            intval($wrapStartUs / 4 + 9 * self::MIN_STEP_US),
            $g5ArrivalTime - $g4ArrivalTime,
            11,
            $timestampNear
        );
        $g6ArrivalTime = $arrivalTime;

        // out of order
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed(
            $wrapStartUs + self::TIMESTAMP_GROUP_LENGTH_US, $arrivalTime, 100
        );

        // G7
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $wrapStartUs + 2 * self::TRIGGER_NEW_GROUP_US,
            $arrivalTime,
            10,
            self::TRIGGER_NEW_GROUP_US - 9 * self::MIN_STEP_US,
            $g6ArrivalTime - $g5ArrivalTime,
            -2,
            $timestampNear
        );
    }

    public function testFirstPacket(): void
    {
        $this->assertNotComputed(0, 17, 1);
    }

    public function testFirstGroup(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed($timestamp, $arrivalTime, 2);
        $g2ArrivalTime = $arrivalTime;

        // G3
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            1,
            self::TRIGGER_NEW_GROUP_US,
            $g2ArrivalTime - $g1ArrivalTime,
            1
        );
    }

    public function testSecondGroup(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed($timestamp, $arrivalTime, 2);
        $g2ArrivalTime = $arrivalTime;

        // G3
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            1,
            self::TRIGGER_NEW_GROUP_US,
            $g2ArrivalTime - $g1ArrivalTime,
            1
        );
        $g3ArrivalTime = $arrivalTime;

        // G4
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            2,
            self::TRIGGER_NEW_GROUP_US,
            $g3ArrivalTime - $g2ArrivalTime,
            -1
        );
    }

    public function testAccumulatedGroup(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1Timestamp = $timestamp;
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertNotComputed($timestamp, 28, 2);
        for ($i = 0; $i < 10; $i++) {
            $timestamp += self::MIN_STEP_US;
            $arrivalTime += self::BURST_THRESHOLD_MS + 1;
            $this->assertNotComputed($timestamp, $arrivalTime, 1);
        }
        $g2Timestamp = $timestamp;
        $g2ArrivalTime = $arrivalTime;

        // G3
        $timestamp = 2 * self::TRIGGER_NEW_GROUP_US;
        $arrivalTime = 500;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            100,
            $g2Timestamp - $g1Timestamp,
            $g2ArrivalTime - $g1ArrivalTime,
            11
        );
    }

    public function testOutOfOrderPacket(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1Timestamp = $timestamp;
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += 11;
        $this->assertNotComputed($timestamp, 28, 2);
        for ($i = 0; $i < 10; $i++) {
            $timestamp += self::MIN_STEP_US;
            $arrivalTime += self::BURST_THRESHOLD_MS + 1;
            $this->assertNotComputed($timestamp, $arrivalTime, 1);
        }
        $g2Timestamp = $timestamp;
        $g2ArrivalTime = $arrivalTime;

        // out of order packet
        $arrivalTime = 281;
        $this->assertNotComputed($g1Timestamp, $arrivalTime, 1);

        // G3
        $timestamp = 2 * self::TRIGGER_NEW_GROUP_US;
        $arrivalTime = 500;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            100,
            $g2Timestamp - $g1Timestamp,
            $g2ArrivalTime - $g1ArrivalTime,
            11
        );
    }

    public function testOutOfOrderWithinGroup(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1Timestamp = $timestamp;
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime += 11;
        $this->assertNotComputed($timestamp, 28, 2);
        $timestamp += 10 * self::MIN_STEP_US;
        $g2Timestamp = $timestamp;
        for ($i = 0; $i < 10; $i++) {
            $arrivalTime += self::BURST_THRESHOLD_MS + 1;
            $this->assertNotComputed($timestamp, $arrivalTime, 1);
            $timestamp -= self::MIN_STEP_US;
        }
        $g2ArrivalTime = $arrivalTime;

        // out of order packet
        $arrivalTime = 281;
        $this->assertNotComputed($g1Timestamp, $arrivalTime, 1);

        // G3
        $timestamp = 2 * self::TRIGGER_NEW_GROUP_US;
        $arrivalTime = 500;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            100,
            $g2Timestamp - $g1Timestamp,
            $g2ArrivalTime - $g1ArrivalTime,
            11
        );
    }

    public function testTwoBursts(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1Timestamp = $timestamp;
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime = 100;
        for ($i = 0; $i < 10; $i++) {
            $timestamp += 30000;
            $arrivalTime += self::BURST_THRESHOLD_MS;
            $this->assertNotComputed($timestamp, $arrivalTime, 1);
        }
        $g2Timestamp = $timestamp;
        $g2ArrivalTime = $arrivalTime;

        // G3
        $timestamp += 30000;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            100,
            $g2Timestamp - $g1Timestamp,
            $g2ArrivalTime - $g1ArrivalTime,
            9
        );
    }

    public function testNoBursts(): void
    {
        // G1
        $timestamp = 0;
        $arrivalTime = 17;
        $this->assertNotComputed($timestamp, $arrivalTime, 1);
        $g1Timestamp = $timestamp;
        $g1ArrivalTime = $arrivalTime;

        // G2
        $timestamp += self::TRIGGER_NEW_GROUP_US;
        $arrivalTime = 28;
        $this->assertNotComputed($timestamp, $arrivalTime, 2);
        $g2Timestamp = $timestamp;
        $g2ArrivalTime = $arrivalTime;

        // G3
        $timestamp += 30000;
        $arrivalTime += self::BURST_THRESHOLD_MS + 1;
        $this->assertComputed(
            $timestamp,
            $arrivalTime,
            100,
            $g2Timestamp - $g1Timestamp,
            $g2ArrivalTime - $g1ArrivalTime,
            1
        );
    }

    public function testWrapAbsSendTime(): void
    {
        $this->wrapTest(self::START_ABS_SEND_TIME_WRAP_US, false);
    }

    public function testWrapAbsSendTimeOutOfOrderWithinGroup(): void
    {
        $this->wrapTest(self::START_ABS_SEND_TIME_WRAP_US, true);
    }

    public function testWrapRtpTimestamp(): void
    {
        $this->wrapTest(self::START_RTP_TIMESTAMP_WRAP_US, false);
    }

    public function testWrapRtpTimestampOutOfOrderWithinGroup(): void
    {
        $this->wrapTest(self::START_RTP_TIMESTAMP_WRAP_US, true);
    }
}
