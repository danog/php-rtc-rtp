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

use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpParameters;

/**
 * Maps RTP header extension URIs to their corresponding IDs and values.
 *
 * This class helps configure and extract RTP header extensions based on SDP
 * negotiation or other signaling information, and then allows parsing and packing
 * of the actual header extension values during RTP processing.
 */
final class HeaderExtensionsMap
{
    private HeaderExtensions $ids;

    /**
     * Constructs a new HeaderExtensionsMap with an empty extension ID registry.
     */
    public function __construct()
    {
        $this->ids = new HeaderExtensions();
    }

    /**
     * Configures the extension ID mappings from RTCRtpParameters.
     *
     * @param RTCRtpParameters $parameters The RTP parameters with negotiated extensions.
     */
    public function configure(RTCRtpParameters $parameters): void
    {
        foreach ($parameters->headerExtensions as $ext) {
            switch ($ext->uri) {
                case "urn:ietf:params:rtp-hdrext:sdes:mid":
                    $this->ids->setMid($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id":
                    $this->ids->setRepairedRtpStreamId($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id":
                    $this->ids->setRtpStreamId($ext->id);
                    break;
                case "http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time":
                    $this->ids->setAbsSendTime($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:toffset":
                    $this->ids->setTransmissionOffset($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:ssrc-audio-level":
                    $this->ids->setAudioLevel($ext->id);
                    break;
                case "http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01":
                    $this->ids->setTransportSequenceNumber($ext->id);
                    break;
            }
        }
    }

    /**
     * Parses incoming RTP header extensions from binary data.
     *
     * @param int $extensionProfile The RTP extension profile ID.
     * @param string $extensionValue The raw RTP header extension bytes.
     * @return HeaderExtensions        The parsed extensions with mapped values.
     */
    public function get(int $extensionProfile, string $extensionValue): HeaderExtensions
    {
        $headerExtensions = new HeaderExtensions();
        $extensions = RtpUtility::unpackHeaderExtensions($extensionProfile, $extensionValue);

        foreach ($extensions as [$xId, $xValue]) {
            switch ($xId) {
                case $this->ids->getMid():
                    $headerExtensions->setMid($xValue);
                    break;
                case $this->ids->getRepairedRtpStreamId():
                    $headerExtensions->setRepairedRtpStreamId($xValue);
                    break;
                case $this->ids->getRtpStreamId():
                    $headerExtensions->setRtpStreamId($xValue);
                    break;
                case $this->ids->getAbsSendTime():
                    $absSendTime = unpack("N", "\x00" . $xValue);
                    assert($absSendTime !== false);
                    $headerExtensions->setAbsSendTime((int) $absSendTime[1]);
                    break;
                case $this->ids->getTransmissionOffset():
                    $transmissionOffset = unpack("N", $xValue . "\x00");
                    assert($transmissionOffset !== false);
                    $headerExtensions->setTransmissionOffset(((int) $transmissionOffset[1]) >> 8);
                    break;
                case $this->ids->getAudioLevel():
                    $vadLevel = unpack("C", $xValue);
                    assert($vadLevel !== false);
                    $level = (int) $vadLevel[1];
                    $headerExtensions->setAudioLevel([(bool)($level & 0x80), $level & 0x7F]);
                    break;
                case $this->ids->getTransportSequenceNumber():
                    $transportSeq = unpack("n", $xValue);
                    assert($transportSeq !== false);
                    $headerExtensions->setTransportSequenceNumber((int) $transportSeq[1]);
                    break;
            }
        }

        return $headerExtensions;
    }

    /**
     * Packs RTP header extension values into binary format for outgoing packets.
     *
     * @param HeaderExtensions $values The extension values to encode.
     * @return array{0: int, 1: string} Encoded RTP header extensions as (profile, value).
     */
    public function set(HeaderExtensions $values): array
    {
        $extensions = [];

        if (!is_null($values->getMid()) && !is_null($this->ids->getMid())) {
            $extensions[] = [(int) $this->ids->getMid(), (string) $values->getMid()];
        }
        if (!is_null($values->getRepairedRtpStreamId()) && !is_null($this->ids->getRepairedRtpStreamId())) {
            $extensions[] = [(int) $this->ids->getRepairedRtpStreamId(), (string) $values->getRepairedRtpStreamId()];
        }
        if (!is_null($values->getRtpStreamId()) && !is_null($this->ids->getRtpStreamId())) {
            $extensions[] = [(int) $this->ids->getRtpStreamId(), (string) $values->getRtpStreamId()];
        }
        if (!is_null($values->getAbsSendTime()) && !is_null($this->ids->getAbsSendTime())) {
            $extensions[] = [$this->ids->getAbsSendTime(), substr(pack("N", $values->getAbsSendTime()), 1)];
        }
        if (!is_null($values->getTransmissionOffset()) && !is_null($this->ids->getTransmissionOffset())) {
            $extensions[] = [$this->ids->getTransmissionOffset(), substr(pack("l", $values->getTransmissionOffset() << 8), 0, 2)];
        }
        $audioLevel = $values->getAudioLevel();
        $audioLevelId = $this->ids->getAudioLevel();
        if (is_array($audioLevel) && is_int($audioLevelId)) {
            $extensions[] = [$audioLevelId, pack("C", (($audioLevel[0] ? 0x80 : 0) | ($audioLevel[1] & 0x7F)))];
        }
        if (!is_null($values->getTransportSequenceNumber()) && !is_null($this->ids->getTransportSequenceNumber())) {
            $extensions[] = [$this->ids->getTransportSequenceNumber(), pack("n", $values->getTransportSequenceNumber())];
        }

        return RtpUtility::packHeaderExtensions($extensions);
    }
}
