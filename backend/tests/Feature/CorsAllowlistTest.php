<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://www.school.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.cors_origins' => [self::SITE],
            'cors.allowed_origins' => [self::SITE],
        ]);
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);
    }

    public function test_the_configured_origin_is_echoed_back_exactly(): void
    {
        $response = $this->preflight(self::SITE);

        self::assertSame(self::SITE, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsStringIgnoringCase('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_other_origins_are_never_echoed_back_and_never_get_a_wildcard(): void
    {
        foreach (['https://evil.example', 'http://www.school.test', 'https://www.school.test.evil.example', 'null'] as $origin) {
            // Laravel may answer with the single allowed origin; a browser on $origin still rejects it.
            $preflight = $this->preflight($origin)->headers->get('Access-Control-Allow-Origin');
            self::assertContains($preflight, [null, self::SITE], "preflight from {$origin}");

            $actual = $this->getJson('/api/health', ['Origin' => $origin])->headers->get('Access-Control-Allow-Origin');
            self::assertContains($actual, [null, self::SITE], "request from {$origin}");
        }
    }

    public function test_real_requests_from_the_site_carry_the_header_too(): void
    {
        $response = $this->getJson('/api/health', ['Origin' => self::SITE]);

        $response->assertOk();
        self::assertSame(self::SITE, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_empty_allowlist_allows_nothing(): void
    {
        config(['auth.cors_origins' => [], 'cors.allowed_origins' => []]);

        self::assertNull($this->preflight(self::SITE)->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_shipped_config_has_no_wildcard_default(): void
    {
        $shipped = require base_path('config/cors.php');

        self::assertNotContains('*', $shipped['allowed_origins']);
        self::assertFalse($shipped['supports_credentials']);
    }
}
