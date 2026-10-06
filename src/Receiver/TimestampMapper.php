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

/**
 * TimestampMapper normalizes RTP timestamps to a continuous timeline.
 *
 * This class handles 32-bit RTP timestamp wraparounds and produces a stable,
 * monotonic timestamp sequence starting from the first observed timestamp.
 * Useful for synchronizing media streams or aligning packet timing data.
 */
final class TimestampMapper
{
    /** The last timestamp received. */
    private ?int $last = null;
    /** The first timestamp received, on the continuous timeline. */
    private ?int $origin = null;
    /** The last timestamp received, on the continuous timeline. */
    private ?int $extended = null;

    /**
     * Maps an RTP timestamp to a continuous timeline.
     *
     * Timestamps are compared with serial number arithmetic (RFC 3550): a timestamp is placed at the
     * point of the timeline closest to the last one, modulo 2^32. A timestamp slightly before the last
     * one (a reordered packet, or a sender that restarted from a saved state) is not mistaken for a
     * wraparound, which would put it and every following one 2^32 ticks later.
     *
     * @param int $timestamp The RTP timestamp.
     * @return int The mapped timestamp relative to the first received timestamp.
     */
    public function map(int $timestamp): int
    {
        if ($this->origin === null || $this->last === null) {
            // First timestamp received, set as origin
            $this->origin = $this->extended = $this->last = $timestamp;
            return 0;
        }

        $delta = ($timestamp - $this->last) & 0xFFFFFFFF;
        if ($delta >= 0x80000000) {
            $delta -= 0x100000000;
        }
        // The last timestamp is on the continuous timeline already, when restored from a state without it.
        $this->extended = ($this->extended ?? $this->last) + $delta;
        $this->last = $timestamp;

        return $this->extended - $this->origin;
    }
}
