<?php

namespace Webrtc\RTP\MediaStreamTrack;

use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Codecs\EncodedPacket;

/**
 * Takes the frames of a track as they are pushed, like an RTP sender: see {@see MediaStreamTrack::setSink()}.
 */
interface MediaStreamTrackSink
{
    /**
     * Handle a frame of the track, right away.
     *
     * @return bool False if no more frames will be taken: the producer of the track is told that its
     *              consumer went away.
     */
    public function onFrame(FrameInterface|EncodedPacket $frame): bool;
}
