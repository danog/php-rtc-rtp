<?php

namespace Tests\Webrtc\RTP\MediaStreamTrack;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Webrtc\AVCodec\AVCodec;
use Webrtc\RTP\MediaStreamTrack\AudioStreamTrack;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\VideoStreamTrack;

#[UsesClass(VideoStreamTrack::class)]
#[UsesClass(AudioStreamTrack::class)]
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
}