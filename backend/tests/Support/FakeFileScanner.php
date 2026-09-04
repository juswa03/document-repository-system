<?php

namespace Tests\Support;

use App\Scanning\Contracts\FileScanner;
use App\Scanning\ScanResult;

/**
 * Test double for the upload malware scanner. Bind with
 * `$this->app->instance(FileScanner::class, $fake)`.
 */
class FakeFileScanner implements FileScanner
{
    public function __construct(private ScanResult $result) {}

    public static function clean(): self
    {
        return new self(ScanResult::clean());
    }

    public static function infected(string $reason = 'Test.Eicar FOUND'): self
    {
        return new self(ScanResult::infected($reason));
    }

    public static function unavailable(string $reason = 'clamd unreachable'): self
    {
        return new self(ScanResult::unavailable($reason));
    }

    public function scan(string $path): ScanResult
    {
        return $this->result;
    }
}
