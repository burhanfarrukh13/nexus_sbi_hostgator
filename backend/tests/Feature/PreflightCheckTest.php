<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class PreflightCheckTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryPrivateStorage();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://api.school.test',
            'app.debug' => false,
            'auth.jwt.access_secret' => bin2hex(random_bytes(32)),
            'auth.jwt.refresh_secret' => bin2hex(random_bytes(32)),
            'auth.storage_signing_secret' => bin2hex(random_bytes(32)),
            'auth.cors_origins' => ['https://www.school.test'],
        ]);
    }

    /** @return array{0: int, 1: array<string, array{level: string, detail: string}>} */
    private function preflight(array $options = []): array
    {
        $exit = Artisan::call('nexus:preflight', ['--json' => true] + $options);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $byCheck = [];
        foreach ($report['results'] as $row) {
            $byCheck[$row['check']] = ['level' => $row['level'], 'detail' => $row['detail']];
        }

        return [$exit, $byCheck];
    }

    private function productionEnvironment(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_a_correctly_configured_server_passes_the_configuration_checks(): void
    {
        $this->productionEnvironment();

        [, $checks] = $this->preflight();

        foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'JWT_ACCESS_SECRET', 'JWT_REFRESH_SECRET',
            'STORAGE_SIGNING_SECRET', 'Secrets are distinct', 'CORS_ALLOWED_ORIGINS', 'Private storage',
            'Database connection', 'Database tables', 'Grading queue schema', 'GRADING_RUN_SECONDS'] as $check) {
            self::assertSame('OK', $checks[$check]['level'] ?? 'missing', "{$check}: ".($checks[$check]['detail'] ?? 'not reported'));
        }
        // No administrator exists yet in the test database; that is a warning, not a failure.
        self::assertSame('WARN', $checks['Administrator account']['level']);
    }

    public function test_unsafe_production_settings_fail_the_run(): void
    {
        $this->productionEnvironment();
        $shared = str_repeat('a', 40);
        config([
            'app.debug' => true,
            'app.key' => '',
            'app.url' => 'https://api.example.com',
            'auth.jwt.access_secret' => $shared,
            'auth.jwt.refresh_secret' => $shared,
            'auth.storage_signing_secret' => 'change-me',
            'auth.cors_origins' => ['*', 'http://www.school.test', 'https://www.school.test/'],
        ]);

        [$exit, $checks] = $this->preflight();

        self::assertSame(1, $exit);
        foreach (['APP_DEBUG', 'APP_KEY', 'APP_URL', 'STORAGE_SIGNING_SECRET', 'Secrets are distinct', 'CORS_ALLOWED_ORIGINS'] as $check) {
            self::assertSame('FAIL', $checks[$check]['level'], $check);
        }
        // Same value used twice is caught even though each secret is long enough.
        self::assertStringContainsString('share the same value', $checks['Secrets are distinct']['detail']);
    }

    public function test_the_same_settings_are_only_warnings_on_a_development_machine(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://127.0.0.1:8000', 'auth.cors_origins' => ['http://localhost:5173']]);

        [, $checks] = $this->preflight();

        self::assertSame('WARN', $checks['APP_ENV']['level']);
        self::assertSame('WARN', $checks['APP_DEBUG']['level']);
        self::assertSame('OK', $checks['CORS_ALLOWED_ORIGINS']['level']);
    }

    public function test_private_storage_inside_a_web_served_folder_fails(): void
    {
        $inside = public_path('nexus-preflight-test');
        config(['filesystems.private_root' => $inside]);

        try {
            [$exit, $checks] = $this->preflight();
        } finally {
            @rmdir($inside);
        }

        self::assertSame(1, $exit);
        self::assertSame('FAIL', $checks['Private storage']['level']);
        self::assertStringContainsString('web-served', $checks['Private storage']['detail']);
    }

    public function test_an_old_schema_without_the_grading_queue_index_is_reported(): void
    {
        Schema::table('test_attempts', static function ($table): void {
            $table->dropIndex('idx_test_attempts_grading_queue');
        });

        [$exit, $checks] = $this->preflight();

        self::assertSame(1, $exit);
        self::assertSame('FAIL', $checks['Grading queue schema']['level']);
        self::assertStringContainsString('grading-queue-upgrade.sql', $checks['Grading queue schema']['detail']);
    }

    public function test_provider_keys_and_outbound_https_are_checked_with_the_network_option(): void
    {
        config([
            'services.ai_chat.url' => 'https://reachable.test/v1/chat/completions',
            'services.ai_chat.key' => 'secret-key',
            'services.probe_down' => ['url' => 'https://blocked.test/v1', 'key' => ''],
        ]);
        Http::fake([
            'blocked.test/*' => static fn () => throw new ConnectionException('Connection timed out'),
            '*' => Http::response('', 401),
        ]);

        [$exit, $checks] = $this->preflight(['--network' => true]);

        self::assertSame(1, $exit);
        self::assertSame('OK', $checks['Outbound HTTPS to reachable.test']['level']);
        self::assertStringContainsString('HTTP 401', $checks['Outbound HTTPS to reachable.test']['detail']);
        self::assertSame('FAIL', $checks['Outbound HTTPS to blocked.test']['level']);
        self::assertSame('FAIL', $checks['services.probe_down API key']['level']);
        self::assertSame('OK', $checks['services.ai_chat API key']['level']);
    }

    public function test_without_the_network_option_no_request_is_made(): void
    {
        Http::fake();

        [, $checks] = $this->preflight();

        Http::assertNothingSent();
        self::assertSame('WARN', $checks['Outbound HTTPS']['level']);
    }
}
