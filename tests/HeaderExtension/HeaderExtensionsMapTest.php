<?php

namespace Tests\Webrtc\RTP\HeaderExtension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\RTP\HeaderExtension\HeaderExtensions;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;

#[CoversClass(HeaderExtensionsMap::class)]
#[UsesClass(RtpUtility::class)]
#[UsesClass(HeaderExtensions::class)]
final class HeaderExtensionsMapTest extends TestCase
{
    /** @return array<string, array{int, int}> */
    public static function formats(): array
    {
        return [
            'one-byte' => [3, 0xBEDE],
            // An ID above 14 needs the two-byte format.
            'two-byte' => [15, 0x1000],
        ];
    }

    #[DataProvider('formats')]
    public function testTheTransportSequenceNumberOfAFrameIsFilledIn(int $id, int $profile): void
    {
        $map = new HeaderExtensionsMap();
        $map->configure(new RTCRtpSendParameters(headerExtensions: [
            new RTCRtpHeaderExtensionParameters(1, "urn:ietf:params:rtp-hdrext:sdes:mid"),
            new RTCRtpHeaderExtensionParameters(2, "urn:ietf:params:rtp-hdrext:ssrc-audio-level"),
            new RTCRtpHeaderExtensionParameters($id, "http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01"),
        ]));
        $values = new HeaderExtensions();
        $values->setMid("audio0");
        $values->setAudioLevel([true, 20]);

        [$extensionProfile, $value, $offset] = $map->setForFrame($values, true);
        $this->assertSame($profile, $extensionProfile);
        $this->assertNotNull($offset);
        $this->assertSame(0, strlen($value) % 4);
        $parsed = $map->get($extensionProfile, substr_replace($value, pack("n", 0xABCD), $offset, 2));

        $this->assertSame(0xABCD, $parsed->getTransportSequenceNumber());
        $this->assertSame("audio0", $parsed->getMid());
        $this->assertSame([true, 20], $parsed->getAudioLevel());

        // Without numbering, like set().
        $this->assertSame([...$map->set($values), null], $map->setForFrame($values, false));
    }
}
