<?php

namespace Webrtc\RTP;

/**
 * A transport that numbers the RTP packets sent over it, for the transport-wide congestion control
 * header extension: the packets of all of its senders share the same sequence.
 */
interface TransportSequenceNumbers
{
    /**
     * The transport-wide sequence number of the next packet sent over the transport.
     *
     * @return int A 16-bit number.
     */
    public function nextTransportSequenceNumber(): int;
}
