<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\HeaderExtension;

use Webrtc\Mixin\DataClass;

#[DataClass]
final class HeaderExtensions
{
    private int|string|null $mid = null;
    private int|string|null $repairedRtpStreamId = null;
    private int|string|null $rtpStreamId = null;
    private ?int $absSendTime = null;
    private ?int $transmissionOffset = null;
    /** @var array{0: bool, 1: int}|int|null */
    private array|int|null $audioLevel = null;
    private ?int $transportSequenceNumber = null;

    /**
     * @return int|string|null
     */
    public function getMid(): int|string|null
    {
        return $this->mid;
    }

    /**
     * @param int|null|string $mid
     */
    public function setMid(int|string|null $mid): void
    {
        $this->mid = $mid;
    }

    /**
     * @return int|string|null
     */
    public function getRepairedRtpStreamId(): int|string|null
    {
        return $this->repairedRtpStreamId;
    }

    /**
     * @param int|null|string $repairedRtpStreamId
     */
    public function setRepairedRtpStreamId(int|string|null $repairedRtpStreamId): void
    {
        $this->repairedRtpStreamId = $repairedRtpStreamId;
    }

    /**
     * @return int|string|null
     */
    public function getRtpStreamId(): int|string|null
    {
        return $this->rtpStreamId;
    }

    /**
     * @param int|null|string $rtpStreamId
     */
    public function setRtpStreamId(int|string|null $rtpStreamId): void
    {
        $this->rtpStreamId = $rtpStreamId;
    }

    public function getAbsSendTime(): ?int
    {
        return $this->absSendTime;
    }

    public function setAbsSendTime(?int $absSendTime): void
    {
        $this->absSendTime = $absSendTime;
    }

    public function getTransmissionOffset(): ?int
    {
        return $this->transmissionOffset;
    }

    public function setTransmissionOffset(?int $transmissionOffset): void
    {
        $this->transmissionOffset = $transmissionOffset;
    }

    /**
     * @return array{0: bool, 1: int}|int|null
     */
    public function getAudioLevel(): array|int|null
    {
        return $this->audioLevel;
    }

    /**
     * @param array{0: bool, 1: int}|int $audioLevel
     */
    public function setAudioLevel(array|int $audioLevel): void
    {
        $this->audioLevel = $audioLevel;
    }

    public function getTransportSequenceNumber(): ?int
    {
        return $this->transportSequenceNumber;
    }

    public function setTransportSequenceNumber(?int $transportSequenceNumber): void
    {
        $this->transportSequenceNumber = $transportSequenceNumber;
    }

}
