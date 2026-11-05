<?php

namespace App\Services;

class ArithmeticVerifier
{
    public function verifyAnswerKey(string $text): array
    {
        $warnings = [];
        foreach (preg_split('/\r\n?|\n/', mb_substr($text, 0, 200000)) as $line) {
            if (mb_strlen($line) > 2000 ||
                !preg_match('/([0-9][0-9.() +\-*\/×÷]{2,120})\s*=\s*(\(?\s*-?[0-9][0-9.]*\s*\)?)/u', $line, $match)) {
                continue;
            }
            $expression = trim(str_replace(['×', '÷'], ['*', '/'], $match[1]));
            $computed = $this->evaluate($expression);
            $claimedRaw = preg_replace('/\s+/', '', $match[2]) ?? '';
            $claimed = (float) trim($claimedRaw, '()');
            if ($computed === null || !is_finite($claimed)) {
                continue;
            }
            $expected = str_starts_with($claimedRaw, '(') && str_ends_with($claimedRaw, ')')
                ? abs($computed)
                : $computed;
            if (abs($expected - $claimed) <= max(0.01, abs($expected) * 0.005)) {
                continue;
            }
            $warnings[] = '"'.$expression.'" evaluates to '.$this->formatNumber($computed).
                ', but the key states '.$claimedRaw.'.';
            if (count($warnings) >= 12) {
                break;
            }
        }

        return $warnings;
    }

    public function verifiedFacts(string $text): array
    {
        $facts = [];
        foreach (preg_split('/\r\n?|\n/', mb_substr($text, 0, 200000)) as $line) {
            if (mb_strlen($line) > 2000 ||
                !preg_match('/([0-9][0-9.() +\-*\/×÷]{2,120})\s*=\s*(\(?\s*-?[0-9][0-9.]*\s*\)?)/u', $line, $match)) {
                continue;
            }
            $expression = trim(str_replace(['×', '÷'], ['*', '/'], $match[1]));
            $computed = $this->evaluate($expression);
            $claimedRaw = preg_replace('/\s+/', '', $match[2]) ?? '';
            $claimed = (float) trim($claimedRaw, '()');
            if ($computed !== null && is_finite($claimed) &&
                abs($computed - $claimed) <= max(0.01, abs($computed) * 0.005)) {
                $facts[] = $expression.' = '.$this->formatNumber($computed);
            }
            if (count($facts) >= 12) {
                break;
            }
        }

        foreach ($this->verifyTables($text) as $fact) {
            if (count($facts) >= 20) {
                break;
            }
            $facts[] = $fact;
        }

        return array_values(array_unique($facts));
    }

    public function verifyTables(string $text): array
    {
        $facts = [];
        foreach (preg_split('/\r\n?|\n/', mb_substr($text, 0, 200000)) as $line) {
            if (!str_contains($line, '|') || !preg_match('/\b(total|sum)\b/i', $line)) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, " \t|")));
            $numbers = [];
            foreach ($cells as $cell) {
                $number = $this->parseNumber($cell);
                if ($number !== null) {
                    $numbers[] = $number;
                }
            }
            if (count($numbers) < 2) {
                continue;
            }
            $expected = array_sum(array_slice($numbers, 0, -1));
            $reported = $numbers[count($numbers) - 1];
            if (abs($expected - $reported) <= max(0.01, abs($expected) * 0.005)) {
                $facts[] = 'Table total verified: '.$this->formatNumber($reported).'.';
            } else {
                $facts[] = 'Table total check: listed values sum to '.$this->formatNumber($expected).
                    ', not the reported '.$this->formatNumber($reported).'.';
            }
            if (count($facts) >= 12) {
                break;
            }
        }

        return $facts;
    }

    public function formatFacts(string $text): string
    {
        $facts = $this->verifiedFacts($text);

        return $facts === [] ? '' : implode("\n", $facts);
    }

    private function evaluate(string $expression): ?float
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
        $expressionParser = null;
        $term = null;
        $factor = null;
        $factor = static function () use (&$factor, &$expressionParser, &$index, $tokens): ?float {
            $sign = 1.0;
            while (($tokens[$index] ?? null) === '+' || ($tokens[$index] ?? null) === '-') {
                if ($tokens[$index] === '-') {
                    $sign *= -1;
                }
                $index++;
            }
            if (($tokens[$index] ?? null) === '(') {
                $index++;
                $value = $expressionParser();
                if (($tokens[$index] ?? null) !== ')') {
                    return null;
                }
                $index++;
                return $value === null ? null : $sign * $value;
            }
            $token = $tokens[$index] ?? null;
            if (!is_float($token)) {
                return null;
            }
            $index++;
            return $sign * $token;
        };
        $term = static function () use (&$term, &$factor, &$index, $tokens): ?float {
            $value = $factor();
            while ($value !== null && in_array($tokens[$index] ?? null, ['*', '/'], true)) {
                $operator = $tokens[$index++];
                $right = $factor();
                if ($right === null || ($operator === '/' && abs($right) < 1e-12)) {
                    return null;
                }
                $value = $operator === '*' ? $value * $right : $value / $right;
            }
            return $value;
        };
        $expressionParser = static function () use (&$expressionParser, &$term, &$index, $tokens): ?float {
            $value = $term();
            while ($value !== null && in_array($tokens[$index] ?? null, ['+', '-'], true)) {
                $operator = $tokens[$index++];
                $right = $term();
                if ($right === null) {
                    return null;
                }
                $value = $operator === '+' ? $value + $right : $value - $right;
            }
            return $value;
        };
        $value = $expressionParser();

        return $value !== null && $index === count($tokens) && is_finite($value) ? $value : null;
    }

    private function parseNumber(string $value): ?float
    {
        $value = trim($value);
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }
        $number = (float) $value;
        return $negative ? -abs($number) : $number;
    }

    private function formatNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }
}
