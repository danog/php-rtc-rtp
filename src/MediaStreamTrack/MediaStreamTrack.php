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

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Evenement\EventEmitter;
use Ramsey\Uuid\Uuid;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\RTP\Enum\MediaKind;

/**
 * Represents a media stream track (audio, video, etc.) in WebRTC.
 *
 * This abstract class serves as the base for creating specific media stream tracks
 * (like audio or video). It provides common functionality such as managing track
 * identification, tracking whether the track has ended, and emitting events when
 * the track state changes.
 */
abstract class MediaStreamTrack extends EventEmitter
{
    /**
     * The kind of media track (e.g., "audio", "video").
     *
     * @var MediaKind
     */
    protected MediaKind $kind;

    /**
     * A unique ID for the track.
     *
     * @var string
     */
    protected string $id;

    /**
     * A queue to hold frames for the track.
     *
     * @var Queue<FrameInterface|EncodedPacket>
     */
    protected Queue $frameQueue;

    private bool $stopped = false;

    /**
     * MediaStreamTrack constructor.
     *
     * Initializes the track with a unique ID generated using UUID.
     */
    public function __construct(MediaKind $kind, ?string $id = null)
    {
        $this->id = $id ?? Uuid::uuid4()->toString();
        /** @var Queue<FrameInterface|EncodedPacket> */
        $this->frameQueue = new Queue();
        $this->kind = $kind;
    }

    /**
     * Gets the unique ID of the track.
     *
     * @return string The unique ID of the track.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Marks the track as ended and resolves any pending receive operations.
     *
     * This method stops the track and emits an "ended" event. It also removes
     * any listeners attached to the track.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->frameQueue->complete();
        $this->emit("ended");
        $this->removeAllListeners();
    }

    public function isEnded(): bool
    {
        return $this->stopped;
    }

    /**
     * Gets the kind of media track (audio, video, etc.).
     *
     * @return MediaKind The kind of the media track.
     */
    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    /**
     * Sets a custom ID for the track.
     *
     * @param string $id The ID to set for the track.
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * Receives and returns the next frame or packet from the track.
     *
     * This is an abstract method that subclasses must implement.
     * It handles the retrieval or generation of the next frame or packet.
     *
     * @return ConcurrentIterator<FrameInterface|EncodedPacket> The next frame or packet
     */
    final public function getConsumer(): ConcurrentIterator {
        return $this->frameQueue->iterate();
    }
}
