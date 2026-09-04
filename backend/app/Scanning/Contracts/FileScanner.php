<?php

namespace App\Scanning\Contracts;

use App\Scanning\ScanResult;

interface FileScanner
{
    /**
     * Scan the file at $path for malware. Never throws — a scanner that
     * is unreachable returns ScanResult::unavailable().
     */
    public function scan(string $path): ScanResult;
}
