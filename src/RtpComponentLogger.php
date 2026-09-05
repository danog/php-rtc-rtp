<?php

namespace Webrtc\RTP;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Prefixes log lines with the RTP component and media kind.
 *
 * Named (not anonymous) so a sender/receiver survives serialize/unserialize.
 */
final class RtpComponentLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $component,
        private readonly string $kind,
        private readonly ?LoggerInterface $logger,
    ) {
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->logger?->log($level, sprintf('%s(%s): %s', $this->component, $this->kind, (string) $message), $context);
    }
}
