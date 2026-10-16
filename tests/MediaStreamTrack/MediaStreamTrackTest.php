<?php

namespace Tests\Webrtc\RTP\MediaStreamTrack;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\Listener\MediaStreamTrackEndedListener;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrackSink;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\AVCodec\Frame\FrameInterface;
use Amp\Pipeline\DisposedException;
use PHPUnit\Framework\Attributes\UsesClass;

use function Amp\async;
use function Amp\delay;

#[CoversClass(MediaStreamTrack::class)]
#[CoversClass(RemoteStreamTrack::class)]
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

    public function testStopIsIdempotentAndCompletesTheConsumer(): void
    {
        $track = new RemoteStreamTrack(MediaKind::Audio);
        $consumer = $track->getConsumer();
        $listener = new class implements MediaStreamTrackEndedListener {
            public int $endedEvents = 0;

            public function onMediaStreamTrackEnded(): void
            {
                ++$this->endedEvents;
            }
        };
        $track->addEndedListener($listener);

        $track->stop();
        $track->stop();

        $this->assertFalse($consumer->continue());
        $this->assertSame(1, $listener->endedEvents);
    }

    private static function sink(bool $accept = true): object
    {
        return new class($accept) implements MediaStreamTrackSink {
            /** @var list<EncodedPacket> */
            public array $frames = [];

            public function __construct(private bool $accept)
            {
            }

            public function onFrame(FrameInterface|EncodedPacket $frame): bool
            {
                \assert($frame instanceof EncodedPacket);
                $this->frames[] = $frame;
                return $this->accept;
            }
        };
    }

    public function testAPushedFrameWaitsForASink(): void
    {
        $track = new PushingStreamTrack();
        $pushed = async($track->send(...), new EncodedPacket("a", 0));
        delay(0.01);
        $this->assertFalse($pushed->isComplete());

        $sink = self::sink();
        $track->setSink($sink);
        $pushed->await();
        $track->send(new EncodedPacket("b", 1));

        $this->assertSame(["a", "b"], array_map(static fn (EncodedPacket $p) => $p->getData(), $sink->frames));
    }

    public function testStoppingTheTrackReleasesAProducerWaitingForASink(): void
    {
        $track = new PushingStreamTrack();
        $pushed = async($track->send(...), new EncodedPacket("a", 0));
        delay(0.01);
        $track->stop();

        $this->expectException(DisposedException::class);
        $pushed->await();
    }

    public function testTheProducerIsToldWhenTheSinkTakesNoMoreFrames(): void
    {
        $track = new PushingStreamTrack();
        $track->setSink(self::sink(accept: false));

        $this->expectException(DisposedException::class);
        $track->send(new EncodedPacket("a", 0));
    }

    public function testTheProducerIsToldWhenTheSinkIsCollected(): void
    {
        $track = new PushingStreamTrack();
        $sink = self::sink();
        $track->setSink($sink);
        unset($sink);

        $this->expectException(DisposedException::class);
        $track->send(new EncodedPacket("a", 0));
    }

    public function testTheSinkIsNotSerialized(): void
    {
        $track = new PushingStreamTrack();
        $sink = self::sink();
        $track->setSink($sink);

        $copy = unserialize(serialize($track));
        $pushed = async($copy->send(...), new EncodedPacket("a", 0));
        delay(0.01);
        $this->assertFalse($pushed->isComplete(), 'the copy waits for a sink of its own');
        $copy->setSink($sink);
        $pushed->await();
        $this->assertCount(1, $sink->frames);
    }

    public function testARemoteTrackSentByASenderGoesToTheSender(): void
    {
        $track = new RemoteStreamTrack(MediaKind::Video);
        $sink = self::sink();
        $track->setSink($sink);
        $track->queueFrame(new EncodedPacket("a", 0));

        $this->assertCount(1, $sink->frames);
    }
}
