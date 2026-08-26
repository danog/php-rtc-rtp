<?php

namespace Tests\Webrtc\RTP\MediaStreamTrack;

use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;

final class VideoStreamTrack extends MediaStreamTrack
{
    public function __construct()
    {
        parent::__construct(MediaKind::Video);
    }
}
