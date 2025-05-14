<?php

namespace Tests\Webrtc\RTP;

use Webrtc\RTP\Receiver\RtpReceiverInterface;
use Webrtc\RTP\RTCRTPDtlsTransportInterface;
use Webrtc\RTP\Sender\RtpSenderInterface;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCTransportStats;

class RTCDtlsTransportMock implements RTCRTPDtlsTransportInterface
{
    private array $rtcpPackets = [];

    public function getState(): TLSState
    {
        return TLSState::CONNECTED;
    }

    public function getReportTransport(): RTCTransportStats
    {
        return new RTCTransportStats(1);
    }

    public function setRtpReceiver(RtpReceiverInterface $receiver, RTCRtpReceiveParameters $parameters): void
    {
        // TODO: Implement setRtpReceiver() method.
    }

    public function removeRtpReceiver(RtpReceiverInterface $receiver): void
    {
        // TODO: Implement removeRtpReceiver() method.
    }

    public function sendRtcp(string $data): void
    {
        $this->rtcpPackets[] = $data;
    }

    public function setRtpSender(RtpSenderInterface $sender, RTCRtpSendParameters $parameters): void
    {
        // TODO: Implement setRtpSender() method.
    }

    public function removeRtpSender(RtpSenderInterface $sender): void
    {
        // TODO: Implement removeRtpSender() method.
    }

    public function sendRtp(string $data): void
    {
        // TODO: Implement sendRtp() method.
    }

    public function getRtcpPackets(): array
    {
        return $this->rtcpPackets;
    }

    public function resetRtcpPackets(): void
    {
        $this->rtcpPackets = [];
    }
}