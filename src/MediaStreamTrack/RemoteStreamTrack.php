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
use SplQueue;
use Webrtc\AVCodec\Data\Packet;
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
     * Adds a frame to the frame queue.
     *
     * This method includes a frame that will be processed later when `receiveData()` is called.
     *
     * @param FrameInterface|EncodedPacket $frame The frame to add to the queue.
     */
    public function queueFrame(FrameInterface|EncodedPacket $frame): void
    {
        $this->frameQueue->push($frame);
    }
}
