<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\MediaStreamTrack;

use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Revolt\EventLoop;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\RTP\Enum\MediaKind;

/**
 * Represents a remote media stream track (audio, video, etc.) in WebRTC.
 *
 * This class extends `MediaStreamTrack` and is used for handling media tracks
 * from remote sources. It manages the queue of frames and allows for receiving
 * and processing the next frame in the stream.
 */
final class RemoteStreamTrack extends MediaStreamTrack
{
    /**
     * The received frames, for the consumer of the track (see {@see self::getConsumer()}).
     *
     * @var Queue<FrameInterface|EncodedPacket>
     */
    private Queue $frameQueue;

    public function __construct(MediaKind $kind, ?string $id = null)
    {
        parent::__construct($kind, $id);
        /** @var Queue<FrameInterface|EncodedPacket> */
        $this->frameQueue = new Queue();
    }

    /**
     * The received frames.
     *
     * @return ConcurrentIterator<FrameInterface|EncodedPacket>
     */
    public function getConsumer(): ConcurrentIterator
    {
        return $this->frameQueue->iterate();
    }

    /**
     * Hand a received frame to the consumer, waiting for it to be consumed: to the sink instead, if
     * one is attached (when the track is sent by an RTP sender too, see {@see self::setSink()}).
     *
     * @param FrameInterface|EncodedPacket $frame The frame to add to the queue.
     */
    public function queueFrame(FrameInterface|EncodedPacket $frame): void
    {
        if ($this->hasSink()) {
            try {
                $this->push($frame);
            } catch (DisposedException) {
                // The sender stopped: like a consumer that doesn't read the frames any more.
            }
            return;
        }
        $this->frameQueue->push($frame);
    }

    /**
     * Adds a frame to the frame queue without waiting for it to be consumed.
     *
     * For the last frames, once no more will arrive: whoever stops the track mustn't wait for someone to read them.
     *
     * @param FrameInterface|EncodedPacket $frame The frame to add to the queue.
     */
    public function queueLastFrame(FrameInterface|EncodedPacket $frame): void
    {
        if ($this->hasSink()) {
            EventLoop::queue($this->queueFrame(...), $frame);
            return;
        }
        $this->frameQueue->pushAsync($frame)->ignore();
    }

    #[\Override]
    public function stop(): void
    {
        if (!$this->isEnded()) {
            $this->frameQueue->complete();
        }
        parent::stop();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __serialize(): array
    {
        $state = parent::__serialize();
        // The frames waiting to be consumed are not saved.
        unset($state[self::class."\0frameQueue"]);

        return $state;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        /** @var Queue<FrameInterface|EncodedPacket> */
        $this->frameQueue = new Queue();
    }
}
