<?php

namespace Tests\Webrtc\RTP;

use Webrtc\RTP\TransportSequenceNumbers;

/** A transport numbering its packets for transport-wide congestion control. */
class NumberingTransportMock extends RTCDtlsTransportMock implements TransportSequenceNumbers
{
    public int $transportSequenceNumber = 65533;

    public function nextTransportSequenceNumber(): int
    {
        return $this->transportSequenceNumber = ($this->transportSequenceNumber + 1) & 0xFFFF;
    }
}
