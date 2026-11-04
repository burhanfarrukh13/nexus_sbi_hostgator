<?php

namespace App\Services;

use RuntimeException;

class JwtTokenService
{
    public function issue(array $claims, string $secret, string $expiry): string
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT signing secrets must contain at least 32 characters.');
        }

        $now = time();
        $claims['iat'] = $now;
        $claims['exp'] = $now + $this->durationSeconds($expiry);
        $header = $this->encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $content = $header.'.'.$body;
        $signature = hash_hmac('sha256', $content, $secret, true);

        return $content.'.'.$this->encode($signature);
    }

    public function verify(string $token, string $secret): array
    {
        if (strlen($secret) < 32 || strlen($token) > 8192) {
            throw new RuntimeException('Invalid token.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token.');
        }

        [$headerPart, $claimsPart, $signaturePart] = $parts;
        $header = json_decode($this->decode($headerPart), true);
        $claims = json_decode($this->decode($claimsPart), true);
        $signature = $this->decode($signaturePart);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' ||
            !is_array($claims) || !is_string($claims['sub'] ?? null) ||
            !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)) {
            throw new RuntimeException('Invalid token.');
        }

        $expected = hash_hmac('sha256', $headerPart.'.'.$claimsPart, $secret, true);
        if (!hash_equals($expected, $signature) || $claims['exp'] <= time()) {
            throw new RuntimeException('Invalid token.');
        }

        return $claims;
    }

    private function durationSeconds(string $duration): int
    {
        if (!preg_match('/^([1-9][0-9]*)(s|m|h|d)$/', $duration, $match)) {
            return 3600;
        }

        $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$match[2]];

        return min((int) $match[1] * $unit, 31_536_000);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid token.');
        }

        return $decoded;
    }
}
