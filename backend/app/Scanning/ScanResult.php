<?php

namespace App\Scanning;

/**
 * The outcome of scanning one uploaded file (PF-03, Phase 7.3).
 */
final class ScanResult
{
    private function __construct(
        public readonly bool $clean,
        public readonly bool $scannerAvailable,
        public readonly ?string $reason = null,
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, scannerAvailable: true);
    }

    public static function infected(string $reason): self
    {
        return new self(clean: false, scannerAvailable: true, reason: $reason);
    }

    public static function unavailable(string $reason): self
    {
        return new self(clean: false, scannerAvailable: false, reason: $reason);
    }
}
