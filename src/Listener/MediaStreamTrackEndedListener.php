<?php

namespace Webrtc\RTP\Listener;

/**
 * Notified when a {@see \Webrtc\RTP\MediaStreamTrack\MediaStreamTrack} has ended.
 *
 * Typed replacement for the former Evenement "ended" event. The listener is a plain object
 * captured verbatim by serialization.
 */
interface MediaStreamTrackEndedListener
{
    public function onMediaStreamTrackEnded(): void;
}
