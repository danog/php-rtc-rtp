<?php

namespace  Tests\Webrtc\RTP\Receiver\Rate;

use Generator;

class Stream
{
    private int $capacity;
    private int $framerate = 30;
    private int $payloadSize = 1500;
    private int $sendTimeUs = 0;
    private int $arrivalTimeUs = 0;

    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }

    public function generateFrames($count): Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $absSendTime = (int) ($this->sendTimeUs * (1 << 18) / 1000000);
            $this->arrivalTimeUs = max($this->arrivalTimeUs, $this->sendTimeUs) + round(
                    ($this->payloadSize * 8000000) / $this->capacity
                );
            $this->sendTimeUs += intval(1000000 / $this->framerate);
            yield [$absSendTime, (int)($this->arrivalTimeUs / 1000), $this->payloadSize];
        }
    }
}