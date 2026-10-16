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

use Amp\DeferredFuture;
use Amp\Pipeline\DisposedException;
use Ramsey\Uuid\Uuid;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Mixin\SerializableState;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Listener\MediaStreamTrackEndedListener;

/**
 * Represents a media stream track (audio, video, etc.) in WebRTC.
 *
 * This abstract class serves as the base for creating specific media stream tracks
 * (like audio or video). It provides common functionality such as managing track
 * identification, tracking whether the track has ended, and emitting events when
 * the track state changes.
 */
abstract class MediaStreamTrack
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

    private bool $stopped = false;

    /**
     * Takes the frames pushed with {@see self::push()}, see {@see self::setSink()}.
     *
     * Held weakly, not to keep its owner (an RTP sender) alive; not serialized: the sink attaches
     * itself again.
     *
     * @var \WeakReference<MediaStreamTrackSink>|null
     */
    private ?\WeakReference $sink = null;

    /** Completed when a sink is attached (or the track stops), for a producer waiting for one. */
    private ?DeferredFuture $sinkAttached = null;

    /** @var \WeakMap<MediaStreamTrackEndedListener, null> Listeners notified when the track ends. */
    private \WeakMap $endedListeners;

    /**
     * MediaStreamTrack constructor.
     *
     * Initializes the track with a unique ID generated using UUID.
     */
    public function __construct(MediaKind $kind, ?string $id = null)
    {
        $this->id = $id ?? Uuid::uuid4()->toString();
        $this->kind = $kind;
        /** @var \WeakMap<MediaStreamTrackEndedListener, null> */
        $this->endedListeners = new \WeakMap();
    }

    /**
     * Register a listener notified when the track ends.
     *
     * Typed replacement for on('ended'); the listener is a plain object captured by serialization.
     */
    public function addEndedListener(MediaStreamTrackEndedListener $listener): void
    {
        $this->endedListeners[$listener] = null;
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
     * This method stops the track and notifies its "ended" listeners. It also clears
     * any listeners attached to the track.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->sink = null;
        $this->wakeProducer();
        $this->notifyEnded();
        /** @var \WeakMap<MediaStreamTrackEndedListener, null> */
        $this->endedListeners = new \WeakMap();
    }

    private function notifyEnded(): void
    {
        foreach ($this->endedListeners as $listener => $_) {
            $listener->onMediaStreamTrackEnded();
        }
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
     * Hand the frames pushed with {@see self::push()} to $sink, right away, as they are pushed (or stop
     * handing them to the current one, with null), like an RTP sender does.
     *
     * The sink is held weakly: the producer is told that its consumer went away once it's collected.
     */
    final public function setSink(?MediaStreamTrackSink $sink): void
    {
        $this->sink = $sink === null ? null : \WeakReference::create($sink);
        if ($sink !== null) {
            $this->wakeProducer();
        }
    }

    /**
     * Whether a sink is attached, see {@see self::setSink()}.
     */
    final protected function hasSink(): bool
    {
        return $this->sink?->get() !== null;
    }

    /**
     * Hand a frame to the sink (see {@see self::setSink()}), waiting for one to be attached if there's none.
     *
     * @throws DisposedException If the track was stopped, or its sink went away or takes no more frames.
     */
    final protected function push(FrameInterface|EncodedPacket $frame): void
    {
        $reference = $this->sink;
        while ($reference === null) {
            if ($this->stopped) {
                throw new DisposedException();
            }
            $this->sinkAttached ??= new DeferredFuture();
            $this->sinkAttached->getFuture()->await();
            $reference = $this->sink;
        }
        $sink = $reference->get();
        if ($sink === null || !$sink->onFrame($frame)) {
            $this->sink = null;
            throw new DisposedException();
        }
    }

    private function wakeProducer(): void
    {
        if ($this->sinkAttached !== null) {
            $deferred = $this->sinkAttached;
            $this->sinkAttached = null;
            $deferred->complete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $state = SerializableState::export($this, [
            'sink' => null,
            'sinkAttached' => null,
            // WeakMaps cannot be serialized; snapshot their keys and rebuild on the far side.
            'endedListeners' => ['__uninitialized' => true],
        ]);
        $state['__endedListeners'] = SerializableState::weakMapToList($this->endedListeners);

        return $state;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /** @var list<MediaStreamTrackEndedListener> $endedListeners */
        $endedListeners = $data['__endedListeners'] ?? [];
        unset($data['__endedListeners']);
        SerializableState::import($this, $data);
        /** @var \WeakMap<MediaStreamTrackEndedListener, null> */
        $this->endedListeners = SerializableState::listToWeakMap($endedListeners);
    }
}
