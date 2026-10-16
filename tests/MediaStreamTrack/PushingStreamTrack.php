<?php

namespace Tests\Webrtc\RTP\MediaStreamTrack;

use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;

/** A track whose frames are pushed with {@see MediaStreamTrack::push()}. */
final class PushingStreamTrack extends MediaStreamTrack
{
    public function __construct(MediaKind $kind = MediaKind::Video)
    {
        parent::__construct($kind);
    }

    public function send(EncodedPacket $packet): void
    {
        $this->push($packet);
    }
}
