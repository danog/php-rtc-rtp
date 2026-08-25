# RTP Library for PHP

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-BSD-blue.svg)](LICENSE)

A PHP library for handling RTP (Real-time Transport Protocol) packets. This package provides tools for encoding, decoding, parsing, and analyzing RTP streams—useful for WebRTC, VoIP, and media streaming applications.

## About this fork

This is the `danog/php-rtc-rtp` fork used by MadelineProto. It targets PHP 8.2+, ports media scheduling from ReactPHP promises to Amp v3 fibers and Revolt timers, avoids busy-loop polling, and reports voice activity through the negotiated RTP audio-level extension. Already-encoded media can be packetized without FFI.

The forked stack keeps the upstream `quasarstream/*` dependency constraints for compatibility. Each `danog/php-rtc-*` package replaces its upstream counterpart, so consumers select the complete maintained stack by requiring the corresponding danog packages together.

##  Features

- Encode and decode RTP packets
- Extract RTP header fields
- Support for common RTP payload types


## Requirements

- **PHP ≥ 8.2**
- FFI and native codec libraries only when encoding, decoding, or transcoding media
- Linux (Windows and macOS support planned for future releases)
- FFmpeg/libav shared libraries (libavcodec, libavfilter, etc.)
  - Compatible with FFmpeg **version 7.1.1**
- libopus development libraries
- libvpx development libraries
  - Compatible with libvpx **version 1.15.0**

## Documentation

This package is part of the PHP WebRTC library. For complete documentation, examples, and API reference, visit:

[PHP WebRTC Documentation](https://www.quasarstream.com/php-webrtc)

## Credits

### Authors

- **Amin Yazdanpanah**  
  - Website: [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
  - Email: [github@aminyazdanpanah.com](mailto:github@aminyazdanpanah.com)

- **Sana Moniri**  
  - GtiHub: [sanamoniri](https://github.com/sanamoniri)

## Reporting Issues

Found a bug? Please report it on our [issues](https://github.com/php-webrtc/rtp/issues).

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.

## References

- [RFC 3550 - RTP: A Transport Protocol for Real-Time Applications](https://tools.ietf.org/html/rfc3550)

- [RFC 3551 – RTP Profile for Audio and Video Conferences with Minimal Control](https://datatracker.ietf.org/doc/html/rfc3551)

- [RFC 4585 – Extended RTP Profile for RTCP-Based Feedback (RTP/AVPF)](https://datatracker.ietf.org/doc/html/rfc4585)

- [RFC 8285 – RTP Header Extension for Mid and RID (used in WebRTC)](https://datatracker.ietf.org/doc/html/rfc8285)

- [RFC 5761 – Multiplexing RTP and RTCP on a Single Port](https://datatracker.ietf.org/doc/html/rfc5761)
