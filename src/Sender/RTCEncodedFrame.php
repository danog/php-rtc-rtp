<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Sender;

readonly class RTCEncodedFrame
{
    public function __construct(
        private array $payloads,
        private int   $timestamp,
        private ?int   $audioLevel
    )
    {
    }

    /**
     * @return ?int
     */
    public function getAudioLevel(): ?int
    {
        return $this->audioLevel;
    }

    /**
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * @return string[]
     */
    public function getPayloads(): array
    {
        return $this->payloads;
    }
}