<?php

namespace App\Scanning;

use App\Scanning\Contracts\FileScanner;

/**
 * No-op scanner (SCAN_DRIVER=null). Every file passes. Used in
 * development and CI where no clamd is available.
 */
class NullScanner implements FileScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::clean();
    }
}
