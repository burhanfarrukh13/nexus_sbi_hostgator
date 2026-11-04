<?php

namespace Tests\Unit;

use App\Services\JwtTokenService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JwtTokenServiceTest extends TestCase
{
    public function test_it_round_trips_signed_claims(): void
    {
        $service = new JwtTokenService();
        $secret = str_repeat('s', 40);
        $token = $service->issue(['sub' => 'user-123', 'typ' => 'access'], $secret, '60m');

        $claims = $service->verify($token, $secret);

        self::assertSame('user-123', $claims['sub']);
        self::assertSame('access', $claims['typ']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function test_it_rejects_tampered_tokens(): void
    {
        $service = new JwtTokenService();
        $secret = str_repeat('s', 40);
        $token = $service->issue(['sub' => 'user-123'], $secret, '60m');
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"attacker","iat":1,"exp":4102444800}'), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        $service->verify(implode('.', $parts), $secret);
    }
}
