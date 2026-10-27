<?php

declare(strict_types=1);

namespace Webrtc\RTP\Crypto;

/**
 * Thrown by {@see FrameCryptorInterface::encryptFrame()} for a frame that can't be sent (e.g. an E2E-encrypted H.264
 * frame whose packet would contain a start code whatever its ciphertext): the sender drops it, as WebRTC's frame
 * transformers do, without counting it as a failure to send.
 */
final class DropFrameException extends \RuntimeException
{
}
