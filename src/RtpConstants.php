<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP;

final class RtpConstants {
    // Used for NACK and retransmission
    public const int RTP_HISTORY_SIZE = 128;

    // Reserved to avoid confusion with RTCP
    public const array FORBIDDEN_PAYLOAD_TYPES = [72, 73, 74, 75, 76];
    public const array DYNAMIC_PAYLOAD_TYPES = [96, 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127];

    // Packets lost range
    public const int PACKETS_LOST_MIN = -(1 << 23);
    public const int PACKETS_LOST_MAX = (1 << 23) - 1;
}
