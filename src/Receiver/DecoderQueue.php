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

use Revolt\EventLoop;
use SplQueue;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\RTP\Jitter\JitterFrame;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;

/**
 * Class DecoderQueue
 *
 * Manages a queue of frames for asynchronous decoding and playback.
 */
class DecoderQueue
{
    /**
     * @var SplQueue|null Queue for storing incoming jitter frames.
     */
    private ?SplQueue $queue;

    /**
     * @var string Handle of the task processing the frame queue periodically.
     */
    private string $queueTask;

    /**
     * @var DecoderInterface|null Decoder used for decoding frames.
     */
    /** How often the queue is drained; see RTCRtpSender::POLL_INTERVAL. */
    private const POLL_INTERVAL = 0.001;

    private ?DecoderInterface $decoder = null;


    /**
     * DecoderQueue constructor.
     *
     * Initializes the frame queue and event loop.
     */
    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    /**
     * Adds a frame to the processing queue.
     *
     * @param JitterFrame $frame The frame to enqueue for decoding.
     */
    public function addFrame(JitterFrame $frame): void
    {
        $this->queue->enqueue($frame);
    }

    /**
     * Starts processing the frame queue and sends decoded frames to the track.
     *
     * @param RemoteStreamTrack $track The track to send decoded frames to.
     */
    public function start(RemoteStreamTrack $track): void
    {
        $this->queueTask = EventLoop::repeat(self::POLL_INTERVAL, function () use ($track) {
            if ($this->queue->isEmpty()) {
                return;
            }

            $encodedData = $this->queue->dequeue();
            $this->decodeFrameAsync($encodedData)->then(function ($decodedFrame) use ($track) {
                if (is_array($decodedFrame)) {
                    foreach ($decodedFrame as $frame) {
                        $track->queueFrame($frame);
                    }
                    return;
                }
                $track->queueFrame($decodedFrame);
            });
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
     * Decodes a frame asynchronously.
     *
     * @param JitterFrame $frame The frame to decode.
     * @return Promise A promise that resolves with the decoded data.
     */
    private function decodeFrameAsync(JitterFrame $frame): Promise
    {
        return new Promise(function ($resolve) use ($frame) {
            EventLoop::queue(function () use ($resolve, $frame) {
                $resolve($this->decoder->decode($frame));
            });
        });
    }

    /**
     * Stops the processing of the frame queue and cancels the scheduled task.
     */
    public function stop(): void
    {
        $this->queue = null;
        // The task is only scheduled by start(), which is skipped in raw (no-decode) mode.
        if (isset($this->queueTask)) {
            EventLoop::cancel($this->queueTask);
        }
    }
}
