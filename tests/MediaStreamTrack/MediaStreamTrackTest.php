<?php

namespace Tests\Webrtc\RTP\MediaStreamTrack;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;

#[CoversClass(MediaStreamTrack::class)]
class MediaStreamTrackTest extends TestCase
{
    public function testAudio()
    {
        $track = new AudioStreamTrack();
        $this->assertEquals("audio", $track->getKind()->value);
        $this->assertEquals(36, strlen($track->getId()));
    }

    public function testVideo()
    {
        $track = new VideoStreamTrack();
        $this->assertEquals("video", $track->getKind()->value);
        $this->assertEquals(36, strlen($track->getId()));
    }

    public function testStopIsIdempotentAndCompletesConsumer(): void
    {
        $track = new AudioStreamTrack();
        $consumer = $track->getConsumer();
        $endedEvents = 0;
        $track->on('ended', static function () use (&$endedEvents): void {
            ++$endedEvents;
        });

        $track->stop();
        $track->stop();

        $this->assertFalse($consumer->continue());
        $this->assertSame(1, $endedEvents);
    }
}
