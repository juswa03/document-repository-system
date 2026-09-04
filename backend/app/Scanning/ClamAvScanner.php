<?php

namespace App\Scanning;

use App\Scanning\Contracts\FileScanner;

/**
 * Streams a file to a running clamd over its TCP (or unix) socket using
 * the INSTREAM command. Any connection / protocol problem is reported as
 * ScanResult::unavailable() so the caller can apply its fail-open /
 * fail-closed policy — this class never throws.
 */
class ClamAvScanner implements FileScanner
{
    /** @param array{socket: ?string, host: string, port: int, timeout: int} $config */
    public function __construct(private readonly array $config) {}

    public function scan(string $path): ScanResult
    {
        if (! is_readable($path)) {
            return ScanResult::unavailable("File not readable: {$path}");
        }

        $address = $this->config['socket']
            ? 'unix://'.$this->config['socket']
            : 'tcp://'.$this->config['host'].':'.$this->config['port'];

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($address, $errno, $errstr, $this->config['timeout']);

        if ($socket === false) {
            return ScanResult::unavailable("Cannot reach clamd at {$address}: {$errstr}");
        }

        try {
            stream_set_timeout($socket, $this->config['timeout']);
            fwrite($socket, "zINSTREAM\0");

            $handle = fopen($path, 'rb');
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($handle);
            fwrite($socket, pack('N', 0));

            $response = trim((string) fgets($socket));
        } finally {
            fclose($socket);
        }

        if ($response === '' ) {
            return ScanResult::unavailable('Empty response from clamd');
        }

        if (str_ends_with($response, 'OK')) {
            return ScanResult::clean();
        }

        if (str_ends_with($response, 'FOUND')) {
            return ScanResult::infected($response);
        }

        return ScanResult::unavailable("Unexpected clamd response: {$response}");
    }
}
