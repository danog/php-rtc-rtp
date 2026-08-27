<?php

namespace Tests\Webrtc\RTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\RTCRtpTransceiver;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\RTPParameter\RTCRtpCodecCapability;

#[CoversClass(RTCRtpTransceiver::class)]
#[UsesClass(\Webrtc\Codecs\Codec::class)]
#[UsesClass(\Webrtc\Codecs\CodecUtility::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtcpFeedback::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtpCapabilities::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtpCodecCapability::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtpCodecParameters::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtpHeaderExtensionCapability::class)]
#[UsesClass(\Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters::class)]
#[UsesClass(\Webrtc\RTP\Sender\RTCRtpSender::class)]
class RTCRtpTransceiverTest extends TestCase {
    public function testCodecPreferences(): void {
        $rtpSenderMock = $this->createStub(RTCRtpSender::class);
        $rtpReceiverMock = $this->createStub(RTCRtpReceiver::class);
        $transceiver = new RTCRtpTransceiver(MediaKind::Audio, $rtpReceiverMock, $rtpSenderMock);

        // Test initial state
        $this->assertEquals([], $transceiver->getPreferredCodecs());

        // Set empty preferences
        $transceiver->setCodecPreferences([]);
        $this->assertEquals([], $transceiver->getPreferredCodecs());

        // Set single codec
        $codec = new RTCRtpCodecCapability("audio/PCMU", 8000, 1);
        $transceiver->setCodecPreferences([$codec]);
        $this->assertEquals([$codec], $transceiver->getPreferredCodecs());

        // Set single codec (duplicated)
        $transceiver->setCodecPreferences([$codec, $codec]);
        $this->assertEquals([$codec], $transceiver->getPreferredCodecs());

        // Set single codec (invalid)
        $invalidCodec = new RTCRtpCodecCapability("audio/bogus", 8000, 1);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Codec is not in capabilities");
        $transceiver->setCodecPreferences([$invalidCodec]);
    }
}
