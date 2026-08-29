<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver;

use Amp\Pipeline\Queue;
use Revolt\EventLoop;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\RTP\Jitter\JitterFrame;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;

/**
 * Class DecoderQueue
 *
 * Manages a queue of frames for asynchronous decoding and playback.
 */
final class DecoderQueue
{
    /** @var Queue<JitterFrame> Queue for storing incoming jitter frames. */
    private readonly Queue $queue;

    private bool $running = false;

    /**
     * DecoderQueue constructor.
     *
     * @param DecoderInterface $decoder Decoder used for every frame added to this queue.
     */
    public function __construct(private readonly DecoderInterface $decoder)
    {
        /** @var Queue<JitterFrame> */
        $this->queue = new Queue();
    }

    /**
     * Adds a frame to the processing queue.
     *
     * @param JitterFrame $frame The frame to enqueue for decoding.
     */
    public function addFrame(JitterFrame $frame): void
    {
        $this->queue->push($frame);
    }

    /**
     * Starts processing the frame queue and sends decoded frames to the track.
     *
     * @param RemoteStreamTrack $track The track to send decoded frames to.
     */
    public function start(RemoteStreamTrack $track): void
    {
        if ($this->running) {
            throw new \RuntimeException('DecoderQueue is already started.');
        }
        $this->running = true;
        EventLoop::queue(function () use ($track) {
            foreach ($this->queue->iterate() as $frame) {
                $decodedFrame = $this->decoder->decode($frame);
                /** @var FrameInterface[] $decodedFrame */
                foreach ($decodedFrame as $decoded) {
                    $track->queueFrame($decoded);
                }
            }
            $this->running = false;
        });
    }

    /**
     * Stops the processing of the frame queue and cancels the scheduled task.
     */
    public function stop(): void
    {
        $this->queue->complete();
    }
}
