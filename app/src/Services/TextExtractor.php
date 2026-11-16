<?php
declare(strict_types=1);

namespace Eshcol\Ats\Services;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Turns a CV file into plain text, free and on this server.
 * Scans and photos have no text layer: they come back as needs_ocr, and are
 * read later in the recruiter's own browser.
 */
final class TextExtractor
{
    public const ALLOWED = ['pdf', 'docx', 'doc', 'txt', 'rtf', 'jpg', 'jpeg', 'png'];

    /** @return array{text: string, status: string} status is ok|needs_ocr|failed */
    public function extract(string $path, string $ext): array
    {
        try {
            $text = match ($ext) {
                'pdf' => $this->pdf($path),
                'docx' => $this->docx($path),
                'doc' => $this->doc($path),
                'txt' => $this->toUtf8((string) file_get_contents($path)),
                'rtf' => $this->rtf((string) file_get_contents($path)),
                default => '',
            };
        } catch (\Throwable) {
            return ['text' => '', 'status' => in_array($ext, ['pdf'], true) ? 'needs_ocr' : 'failed'];
        }

        $text = $this->tidy($text);
        // Links behind clickable words ("LinkedIn", "Portfolio") are not in the text: add them at the end.
        $links = array_values(array_filter($this->links($path, $ext), fn ($u) => !str_contains($text, $u)));
        if ($links) {
            $text .= "\n\nLinks in the CV: " . implode(' ', $links);
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 80) {
            return ['text' => $text, 'status' => in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? 'needs_ocr' : 'failed'];
        }
        return ['text' => $text, 'status' => 'ok'];
    }

    private function pdf(string $path): string
    {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);
        $doc = (new Parser([], $config))->parseFile($path);
        $out = [];
        foreach (array_slice($doc->getPages(), 0, 12) as $page) {
            $out[] = $page->getText();
        }
        return implode("\n", $out);
    }

    private function docx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Not a valid .docx');
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:cr/>'], "\n", $xml);
        $xml = str_replace('<w:tab/>', "\t", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Web links stored in the file (hyperlinks in Word, link annotations in a PDF), so a
     * LinkedIn profile behind the word "LinkedIn" is found. Best effort; never fails the read.
     * @return string[]
     */
    public function links(string $path, string $ext): array
    {
        $found = [];
        try {
            if ($ext === 'docx') {
                $zip = new \ZipArchive();
                if ($zip->open($path) === true) {
                    $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
                    $zip->close();
                    preg_match_all('/Target="(https?:[^"]+)"[^>]*TargetMode="External"|TargetMode="External"[^>]*Target="(https?:[^"]+)"/i', $rels, $m);
                    $found = array_merge($m[1], $m[2]);
                }
            } elseif ($ext === 'pdf') {
                $bin = (string) file_get_contents($path, false, null, 0, 8 * 1024 * 1024);
                preg_match_all('#/URI\s*\((https?:[^)]{4,300})\)#i', $bin, $m);
                $found = $m[1];
                if (!$found) {
                    // Links inside compressed object streams: let the parser unpack them.
                    $doc = (new Parser([], new Config()))->parseContent($bin);
                    foreach ($doc->getObjects() as $o) {
                        $raw = (string) $o->getContent();
                        if (preg_match_all('#/URI\s*\((https?:[^)]{4,300})\)#i', $raw, $mm)) {
                            array_push($found, ...$mm[1]);
                        }
                        $a = $o->getHeader()->get('A');
                        if (is_object($a) && method_exists($a, 'getHeader') && ($u = $a->getHeader()->get('URI')) && is_object($u)) {
                            $found[] = (string) $u->getContent();
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($found as $u) {
            $u = trim(html_entity_decode(str_replace(['\\(', '\\)'], ['(', ')'], (string) $u), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($u !== '' && preg_match('#^https?://#i', $u) && !preg_match('#^https?://(?:www\.)?(?:google\.com/maps|aka\.ms|go\.microsoft)#i', $u)) {
                $out[$u] = true;
            }
        }
        return array_slice(array_keys($out), 0, 10);
    }

    /** Old binary .doc: Word 97-2003 stores its text as UTF-16 runs. Best effort. */
    private function doc(string $path): string
    {
        $bin = (string) file_get_contents($path);
        preg_match_all('/(?:[\x20-\x7E\x0A\x0D\x09]\x00){6,}/', $bin, $m);
        $text = implode("\n", array_map(fn ($s) => mb_convert_encoding($s, 'UTF-8', 'UTF-16LE'), $m[0]));
        if (preg_match_all('/\p{L}/u', $text) < 80) {
            preg_match_all('/[\x20-\x7E\x0A\x0D\x09]{6,}/', $bin, $m2);
            $text = implode("\n", $m2[0]);
        }
        return $text;
    }

    private function rtf(string $rtf): string
    {
        $rtf = preg_replace('/\\\\par[d]?/', "\n", $rtf);
        $rtf = preg_replace("/\\\\'([0-9a-f]{2})/i", '', $rtf);
        $rtf = preg_replace('/\\\\[a-z]+-?\d* ?/i', '', $rtf);
        return $this->toUtf8(str_replace(['{', '}'], '', $rtf));
    }

    private function toUtf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    private function tidy(string $t): string
    {
        $t = mb_scrub(str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $t), 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }
}
