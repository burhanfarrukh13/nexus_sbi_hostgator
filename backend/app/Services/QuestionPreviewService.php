<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use ZipArchive;

class QuestionPreviewService
{
    private const MAX_FILES = 10;
    private const MAX_FILE_BYTES = 30 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 100 * 1024 * 1024;
    private const MAX_PDF_PAGES = 30;

    public function __construct(private readonly QuestionImportParser $parser)
    {
    }

    public function preview(array $uploads): array
    {
        if (count($uploads) < 1 || count($uploads) > self::MAX_FILES) {
            throw new BadRequestHttpException('Upload between 1 and 10 PDF or DOCX files.');
        }
        $totalBytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $uploads));
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            throw new BadRequestHttpException('Combined test source files may not exceed 100 MiB.');
        }

        $documents = [];
        $totalPages = 0;
        foreach ($uploads as $file) {
            $name = $file->getClientOriginalName();
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf', 'docx'], true)) {
                throw new BadRequestHttpException("{$name}: only PDF and DOCX files are supported.");
            }
            $bytes = file_get_contents($file->getRealPath());
            $size = strlen($bytes);
            if ($size < 1 || $size > self::MAX_FILE_BYTES) {
                throw new BadRequestHttpException("{$name}: file size must be between 1 byte and 30 MiB.");
            }
            if ($extension === 'pdf') {
                if (!str_contains(substr($bytes, 0, 1024), '%PDF-')) {
                    throw new BadRequestHttpException("{$name}: invalid PDF file.");
                }
                $pages = $this->pdfPages($bytes, $name);
                $totalPages += count($pages);
                if ($totalPages > self::MAX_PDF_PAGES) {
                    throw new BadRequestHttpException('Combined PDF source documents may contain at most 30 pages per extraction.');
                }
                $pageText = [];
                foreach ($pages as $index => $text) {
                    $pageText[] = '-- '.($index + 1).' of '.count($pages)." --\n".$text;
                }
                $document = implode("\n", $pageText);
                if (trim(preg_replace('/--\s*\d+\s+of\s+\d+\s*--/', '', $document) ?? '') === '') {
                    throw new UnprocessableEntityHttpException(
                        "{$name}: no readable text layer was found; scanned-PDF OCR is not available in this HostGator port."
                    );
                }
                $documents[] = $document;
            } else {
                if (substr($bytes, 0, 2) !== 'PK') {
                    throw new BadRequestHttpException("{$name}: invalid DOCX file.");
                }
                $documents[] = $this->docxText($file->getRealPath(), $name);
            }
        }

        $parsed = $this->parser->parse($documents, count($documents) > 1);
        $groups = array_map(function (array $group): array {
            $warnings = $this->answerKeyWarnings($group['solution']);
            $group['answerKeyWarnings'] = $warnings;
            return $group;
        }, $parsed['groups']);
        $allWarnings = [];
        foreach ($groups as $group) {
            foreach ($group['answerKeyWarnings'] as $warning) {
                $allWarnings[] = 'Question '.$group['groupNumber'].': '.$warning;
            }
        }

        return [
            'groups' => $groups,
            'errors' => $parsed['errors'],
            'answerKeyWarnings' => $allWarnings,
        ];
    }

    private function pdfPages(string $bytes, string $name): array
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new ServiceUnavailableHttpException(null, 'PDF parsing dependency is unavailable.');
        }
        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseContent($bytes);
            $pages = $pdf->getPages();
        } catch (\Throwable) {
            throw new BadRequestHttpException("{$name}: invalid or unreadable PDF file.");
        }
        return array_map(static fn ($page): string => $page->getText(), $pages);
    }

    private function docxText(string $path, string $name): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ServiceUnavailableHttpException(null, 'DOCX parsing requires the PHP zip extension.');
        }
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new BadRequestHttpException("{$name}: invalid DOCX file.");
        }
        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        if ($xml === false) {
            throw new BadRequestHttpException("{$name}: invalid DOCX file.");
        }
        $document = new \DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new BadRequestHttpException("{$name}: invalid DOCX document content.");
        }
        $xpath = new \DOMXPath($document);
        $paragraphs = [];
        foreach ($xpath->query('//*[local-name()="p"]') ?: [] as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//*[local-name()="t"]', $paragraph) ?: [] as $node) {
                $text .= $node->textContent;
            }
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
        return implode("\n", $paragraphs);
    }

    private function answerKeyWarnings(string $text): array
    {
        $warnings = [];
        foreach (preg_split('/\r\n?|\n/', mb_substr($text, 0, 200000)) as $line) {
            if (mb_strlen($line) > 2000 ||
                !preg_match('/([0-9][0-9.() +\-*\/×÷]{2,120})\s*=\s*(\(?\s*-?[0-9][0-9.]*\s*\)?)/u', $line, $match)) {
                continue;
            }
            $expression = trim(str_replace(['×', '÷'], ['*', '/'], $match[1]));
            $computed = $this->evaluateArithmetic($expression);
            $claimedRaw = preg_replace('/\s+/', '', $match[2]) ?? '';
            $parenthesized = str_starts_with($claimedRaw, '(') && str_ends_with($claimedRaw, ')');
            $claimed = (float) trim($claimedRaw, '()');
            if ($computed === null || !is_finite($claimed)) {
                continue;
            }
            $expected = $parenthesized ? abs($computed) : $computed;
            if (abs($expected - $claimed) <= max(0.01, abs($expected) * 0.005)) {
                continue;
            }
            $warnings[] = '"'.$expression.'" evaluates to '.rtrim(rtrim(number_format($computed, 6, '.', ''), '0'), '.').
                ', but the key states '.$claimedRaw.'.';
            if (count($warnings) === 12) {
                break;
            }
        }
        return $warnings;
    }

    private function evaluateArithmetic(string $expression): ?float
    {
        $tokens = [];
        preg_match_all('/\d+(?:\.\d+)?|[()+\-*\/]/', $expression, $matches);
        if ($matches[0] === [] || implode('', $matches[0]) !== preg_replace('/\s+/', '', $expression)) {
            return null;
        }
        foreach ($matches[0] as $token) {
            $tokens[] = in_array($token, ['+', '-', '*', '/', '(', ')'], true) ? $token : (float) $token;
        }
        $index = 0;
        $sum = null;
        $factor = null;
        $factor = function () use (&$factor, &$sum, &$index, $tokens): ?float {
            $sign = 1.0;
            while (($tokens[$index] ?? null) === '+' || ($tokens[$index] ?? null) === '-') {
                if ($tokens[$index] === '-') {
                    $sign *= -1;
                }
                $index++;
            }
            $token = $tokens[$index] ?? null;
            if ($token === '(') {
                $index++;
                $value = $sum();
                if ($value === null || ($tokens[$index] ?? null) !== ')') {
                    return null;
                }
                $index++;
                return $sign * $value;
            }
            if (!is_float($token)) {
                return null;
            }
            $index++;
            return $sign * $token;
        };
        $product = null;
        $product = function () use (&$product, &$factor, &$index, $tokens): ?float {
            $value = $factor();
            while ($value !== null && in_array($tokens[$index] ?? null, ['*', '/'], true)) {
                $operator = $tokens[$index++];
                $right = $factor();
                if ($right === null || ($operator === '/' && $right == 0.0)) {
                    return null;
                }
                $value = $operator === '*' ? $value * $right : $value / $right;
            }
            return $value;
        };
        $sum = null;
        $sum = function () use (&$sum, &$product, &$index, $tokens): ?float {
            $value = $product();
            while ($value !== null && in_array($tokens[$index] ?? null, ['+', '-'], true)) {
                $operator = $tokens[$index++];
                $right = $product();
                if ($right === null) {
                    return null;
                }
                $value = $operator === '+' ? $value + $right : $value - $right;
            }
            return $value;
        };
        $value = $sum();
        return $index === count($tokens) && is_finite($value ?? INF) ? $value : null;
    }
}
