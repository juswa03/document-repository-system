<?php

namespace App\Extraction;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Pulls the readable text out of an uploaded document so it can be
 * searched (Phase 9), summarised and compared (Phase 10). Best-effort —
 * every path returns null rather than throwing, and the result is capped
 * so a huge file can't bloat the row or a later prompt.
 *
 * PDF via smalot/pdfparser; DOCX by reading word/document.xml straight
 * out of the zip (no heavy Office library). Legacy binary .doc is not
 * supported — it needs an external converter — and returns null.
 */
class TextExtractor
{
    /** Hard cap on stored characters (~50k tokens of headroom). */
    public const MAX_CHARS = 200_000;

    public function extract(string $absolutePath, string $format): ?string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        try {
            $text = match (strtolower($format)) {
                'pdf' => $this->fromPdf($absolutePath),
                'docx' => $this->fromDocx($absolutePath),
                'txt', 'md', 'csv' => (string) file_get_contents($absolutePath),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $this->tidy($text);
    }

    private function fromPdf(string $path): ?string
    {
        return (new PdfParser)->parseFile($path)->getText() ?: null;
    }

    private function fromDocx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the DOCX archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        // Paragraph and line breaks become newlines; drop every other tag.
        $xml = preg_replace('#<w:(p|br|tab)\b[^>]*/?>#i', "\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
    }

    private function tidy(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
