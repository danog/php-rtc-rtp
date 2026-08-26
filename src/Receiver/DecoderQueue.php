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
    /**
     * @var Queue|null Queue for storing incoming jitter frames.
     */
    private readonly Queue $queue;

    /**
     * @var DecoderInterface|null Decoder used for decoding frames.
     */
    private ?DecoderInterface $decoder = null;

    private bool $running = false;

    /**
     * DecoderQueue constructor.
     *
     * Initializes the frame queue and event loop.
     */
    public function __construct()
    {
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
        if (!$this->decoder) {
            throw new \RuntimeException('Decoder is not set. Please set a decoder before starting the queue.');
        }
        $this->running = true;
        EventLoop::queue(function () use ($track) {
            foreach ($this->queue as $frame) {
                $decodedFrame = $this->decoder->decode($frame);
                foreach ($decodedFrame as $decoded) {
                    $track->queueFrame($decoded);
                }
            }
            $this->running = false;
        });
    }

    /**
     * Gets the current decoder instance.
     *
     * @return DecoderInterface|null The current decoder or null if is not set.
     */
    public function getDecoder(): ?DecoderInterface
    {
        return $this->decoder;
    }

    /**
     * Sets the decoder instance.
     *
     * @param DecoderInterface|null $decoder The decoder to use for decoding frames.
     */
    public function setDecoder(?DecoderInterface $decoder): void
    {
        $this->decoder = $decoder;
    }

    /**
     * Stops the processing of the frame queue and cancels the scheduled task.
     */
    public function stop(): void
    {
        $this->queue->complete();
    }
}
