<?php

declare(strict_types=1);

namespace Webrtc\RTP\Crypto;

use Webrtc\RTP\Enum\MediaKind;

/**
 * Transforms encoded media frames on their way onto and off the wire, for end-to-end encryption that
 * sits on top of the transport (not SRTP): the sender encrypts each full encoded frame before it is
 * packetized, and the receiver decrypts the reassembled frame before it is decoded or handed on raw.
 *
 * A frame is the concatenation of the payloads of all RTP packets sharing one timestamp; the sender
 * fragments the ciphertext generically and the receiver reassembles it by timestamp, so an
 * implementation only has to be a symmetric byte transform keyed by media kind and SSRC.
 */
interface FrameCryptorInterface
{
    /**
     * Encrypt one outgoing encoded frame.
     *
     * @param MediaKind $kind  The media kind of the sending track.
     * @param int       $ssrc  The SSRC the frame is sent on.
     * @param string    $frame The full encoded frame.
     *
     * @return string The bytes to fragment and send in place of the frame.
     */
    public function encryptFrame(MediaKind $kind, int $ssrc, string $frame): string;

    /**
     * Decrypt one reassembled incoming frame.
     *
     * @param MediaKind $kind  The media kind of the receiving track.
     * @param int       $ssrc  The SSRC the frame arrived on.
     * @param string    $frame The reassembled frame bytes as produced by the sender's transform.
     *
     * @return string The original encoded frame.
     */
    public function decryptFrame(MediaKind $kind, int $ssrc, string $frame): string;
}
