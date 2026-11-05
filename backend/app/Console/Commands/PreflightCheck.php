<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Run once after uploading to HostGator (and after any config change):
 *
 *   php artisan config:clear && php artisan nexus:preflight --network
 *
 * It checks the PHP build, the production .env, private storage, the database schema and,
 * with --network, whether this server can reach the AI providers. Exit code 1 = a FAIL.
 */
class PreflightCheck extends Command
{
    protected $signature = 'nexus:preflight
        {--network : Also test outbound HTTPS to every configured AI provider}
        {--json : Print machine-readable JSON instead of a table}';

    protected $description = 'Check this server is ready to run Nexus SBI (PHP, .env, storage, database, providers)';

    private const REQUIRED_EXTENSIONS = [
        'ctype', 'curl', 'fileinfo', 'filter', 'hash', 'json', 'mbstring', 'openssl',
        'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip',
    ];

    private const REQUIRED_TABLES = [
        'users', 'user_role_assignments', 'audit_logs', 'student_queries', 'subjects',
        'subject_user_access', 'tests', 'test_attempts', 'test_source_files', 'test_attempt_files',
        'ai_notebook_sources', 'ai_notebook_chunks', 'ai_notebook_chat_sessions',
        'ai_notebook_chat_messages', 'ai_notebook_chat_tickets', 'ai_notebook_prompts', 'ai_notebook_user_prompts',
        'ai_notebook_daily_usage', 'ai_grading_settings',
    ];

    /** @var list<array{level: string, check: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkPhp();
        $this->checkEnvironment();
        $this->checkSecrets();
        $this->checkCors();
        $this->checkStorage();
        $this->checkDatabase();
        $this->checkGradingSettings();
        $this->checkProviders((bool) $this->option('network'));

        $failures = count(array_filter($this->results, static fn (array $r): bool => $r['level'] === 'FAIL'));
        $warnings = count(array_filter($this->results, static fn (array $r): bool => $r['level'] === 'WARN'));

        if ($this->option('json')) {
            $this->line(json_encode(['failures' => $failures, 'warnings' => $warnings, 'results' => $this->results], JSON_PRETTY_PRINT));
        } else {
            $this->table(['', 'Check', 'Detail'], array_map(
                static fn (array $r): array => [$r['level'], $r['check'], $r['detail']],
                $this->results
            ));
            $summary = "{$failures} failed, {$warnings} warning(s).";
            $failures > 0 ? $this->error($summary) : $this->info($summary);
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $level, string $check, string $detail): void
    {
        $this->results[] = ['level' => $level, 'check' => $check, 'detail' => $detail];
    }

    private function checkPhp(): void
    {
        $version = PHP_VERSION;
        $this->add(version_compare($version, '8.2.0', '>=') ? 'OK' : 'FAIL', 'PHP version', $version.(version_compare($version, '8.2.0', '>=') ? '' : ' (8.2 or newer is required; change it in cPanel MultiPHP Manager)'));

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $e): bool => !extension_loaded($e)));
        $this->add($missing === [] ? 'OK' : 'FAIL', 'PHP extensions', $missing === [] ? 'all required extensions are loaded' : 'missing: '.implode(', ', $missing).' (enable them in cPanel > Select PHP Version)');

        $renderers = [];
        if (extension_loaded('imagick')) {
            $renderers[] = 'Imagick';
        }
        $binary = (string) config('services.pdf_renderer.binary', 'pdftoppm');
        if ($this->binaryAvailable($binary)) {
            $renderers[] = $binary;
        }
        $this->add(
            $renderers === [] ? 'WARN' : 'OK',
            'Server PDF page renderer',
            $renderers === []
                ? 'none found. Students\' browsers convert PDFs to photos, so this is optional, but server-side PDF page rendering will be unavailable'
                : implode(' + ', $renderers)
        );

        $memory = (string) ini_get('memory_limit');
        $bytes = $this->iniBytes($memory);
        $this->add($bytes !== -1 && $bytes < 256 * 1024 * 1024 ? 'WARN' : 'OK', 'PHP memory_limit', $memory.($bytes !== -1 && $bytes < 256 * 1024 * 1024 ? ' (256M or more is safer for photo and PDF processing)' : ''));
        $this->add('OK', 'PHP upload limits', 'upload_max_filesize='.ini_get('upload_max_filesize').', post_max_size='.ini_get('post_max_size'));
    }

    private function binaryAvailable(string $binary): bool
    {
        if ($binary === '' || !function_exists('proc_open')) {
            return false;
        }
        try {
            $probe = new \Symfony\Component\Process\Process([$binary, '-v']);
            $probe->setTimeout(5);
            $probe->run();

            // pdftoppm prints its version banner on stderr and exits 0 or 99 depending on the build.
            return str_contains(strtolower($probe->getErrorOutput().$probe->getOutput()), 'pdftoppm');
        } catch (Throwable) {
            return false;
        }
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function checkEnvironment(): void
    {
        $production = app()->environment('production');
        $this->add($production ? 'OK' : 'WARN', 'APP_ENV', app()->environment().($production ? '' : ' (use production on HostGator)'));

        $debug = (bool) config('app.debug');
        $this->add($debug ? ($production ? 'FAIL' : 'WARN') : 'OK', 'APP_DEBUG', $debug ? 'true (must be false in production: errors would show secrets)' : 'false');

        $this->add(config('app.key') ? 'OK' : 'FAIL', 'APP_KEY', config('app.key') ? 'set' : 'empty (run php artisan key:generate --force)');

        $url = (string) config('app.url');
        $placeholder = $this->looksLikePlaceholder($url);
        $https = str_starts_with($url, 'https://');
        $this->add(
            $placeholder ? ($production ? 'FAIL' : 'WARN') : ($https || !$production ? 'OK' : 'WARN'),
            'APP_URL',
            $url.($placeholder ? ' (still a placeholder)' : ($https ? '' : ' (use https:// in production)'))
        );
    }

    private function looksLikePlaceholder(string $value): bool
    {
        return $value === '' || preg_match('/example\.|\.invalid|change[-_ ]?me|replace[-_ ]?me|your[-_ ]/i', $value) === 1;
    }

    private function checkSecrets(): void
    {
        $secrets = [
            'JWT_ACCESS_SECRET' => (string) config('auth.jwt.access_secret'),
            'JWT_REFRESH_SECRET' => (string) config('auth.jwt.refresh_secret'),
            'STORAGE_SIGNING_SECRET' => (string) config('auth.storage_signing_secret'),
        ];
        foreach ($secrets as $name => $value) {
            if ($value === '') {
                $this->add('FAIL', $name, 'empty');
            } elseif (strlen($value) < 32 || $this->looksLikePlaceholder($value)) {
                $this->add('FAIL', $name, 'too short or a placeholder (use at least 32 random characters, e.g. php -r "echo bin2hex(random_bytes(32));")');
            } else {
                $this->add('OK', $name, 'set ('.strlen($value).' characters)');
            }
        }
        $filled = array_filter($secrets, static fn (string $v): bool => $v !== '');
        $this->add(
            count($filled) === count(array_unique($filled)) ? 'OK' : 'FAIL',
            'Secrets are distinct',
            count($filled) === count(array_unique($filled)) ? 'each secret is different' : 'two secrets share the same value; generate each one separately'
        );
    }

    private function checkCors(): void
    {
        $origins = (array) config('auth.cors_origins', []);
        $production = app()->environment('production');
        if ($origins === []) {
            $this->add($production ? 'FAIL' : 'WARN', 'CORS_ALLOWED_ORIGINS', 'empty: the browser app will be blocked');

            return;
        }
        $bad = array_values(array_filter($origins, fn (string $origin): bool => $origin === '*'
            || ($production && (!str_starts_with($origin, 'https://') || $this->looksLikePlaceholder($origin)))
            || str_ends_with($origin, '/')));
        $this->add($bad === [] ? 'OK' : 'FAIL', 'CORS_ALLOWED_ORIGINS', $bad === []
            ? implode(', ', $origins)
            : 'fix: '.implode(', ', $bad).' (exact https:// origin, no wildcard, no trailing slash)');
    }

    private function checkStorage(): void
    {
        $root = (string) config('filesystems.private_root');
        if ($root === '') {
            $this->add('FAIL', 'PRIVATE_STORAGE_PATH', 'empty');

            return;
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            $this->add('FAIL', 'Private storage', "cannot create {$root}");
        } else {
            $real = realpath($root) ?: $root;
            $publicRoots = array_filter([realpath(public_path()), realpath(dirname(base_path()).DIRECTORY_SEPARATOR.'public_html')]);
            $inside = false;
            foreach ($publicRoots as $publicRoot) {
                if ($real === $publicRoot || str_starts_with($real, $publicRoot.DIRECTORY_SEPARATOR)) {
                    $inside = true;
                }
            }
            $probe = @tempnam($real, 'nexus-preflight-');
            $writable = $probe !== false;
            if ($probe !== false) {
                @unlink($probe);
            }
            $level = $inside || !$writable ? 'FAIL' : (str_contains(str_replace('\\', '/', $real), '/public_html') ? 'WARN' : 'OK');
            $this->add($level, 'Private storage', $real.($inside ? ' is inside a web-served folder: move it above public_html' : ($writable ? ' (writable, outside the web root)' : ' is not writable')));
        }

        foreach ([storage_path('logs'), storage_path('framework/cache'), storage_path('framework/views'), base_path('bootstrap/cache')] as $directory) {
            $ok = is_dir($directory) && is_writable($directory);
            $this->add($ok ? 'OK' : 'FAIL', 'Writable '.basename(dirname($directory)).'/'.basename($directory), $ok ? 'yes' : "{$directory} is missing or not writable (chmod 755/775)");
        }

        $driver = (string) config('cache.default');
        $this->add(
            in_array($driver, ['file', 'database', 'redis', 'memcached'], true) ? 'OK' : 'WARN',
            'Cache store',
            $driver.(in_array($driver, ['file', 'database', 'redis', 'memcached'], true) ? ' (supports the scheduler overlap lock)' : ' (does not persist between cron runs; use file)')
        );
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $error) {
            $this->add('FAIL', 'Database connection', 'cannot connect: '.$error->getMessage());

            return;
        }
        $this->add('OK', 'Database connection', config('database.default').' / '.config('database.connections.'.config('database.default').'.database'));

        $missing = array_values(array_filter(self::REQUIRED_TABLES, static fn (string $t): bool => !Schema::hasTable($t)));
        if ($missing !== []) {
            $this->add('FAIL', 'Database tables', 'missing: '.implode(', ', $missing).' (import database.sql into the empty database)');

            return;
        }
        $this->add('OK', 'Database tables', count(self::REQUIRED_TABLES).' required tables exist');

        $hasColumn = Schema::hasColumn('test_attempts', 'grading_attempts');
        $indexes = array_column(Schema::getIndexes('test_attempts'), 'name');
        $this->add(
            $hasColumn && in_array('idx_test_attempts_grading_queue', $indexes, true) ? 'OK' : 'FAIL',
            'Grading queue schema',
            $hasColumn && in_array('idx_test_attempts_grading_queue', $indexes, true)
                ? 'grading_attempts column and queue index present'
                : 'old schema: run database-updates/grading-queue-upgrade.sql'
        );

        $this->add(
            DB::table('users')->where('role', 'admin')->exists() ? 'OK' : 'WARN',
            'Administrator account',
            DB::table('users')->where('role', 'admin')->exists() ? 'exists' : 'none yet (php artisan nexus:admin-create, or scripts/new-admin-sql.php)'
        );
    }

    private function checkGradingSettings(): void
    {
        $seconds = (int) config('services.grading.run_seconds');
        $this->add($seconds <= 840 ? 'OK' : 'WARN', 'GRADING_RUN_SECONDS', $seconds.($seconds <= 840 ? '' : ' (should stay under 840 so one run ends before the next 15-minute cron tick)'));
        $workers = (int) config('services.grading.workers');
        $this->add('OK', 'GRADING_WORKERS', $workers.' at a time'.($workers > 2 ? ' (HostGator limits processes; 2 is safer)' : ''));

        try {
            if (Schema::hasTable('ai_grading_settings')) {
                $delay = DB::table('ai_grading_settings')->value('result_release_delay_hours');
                $this->add('OK', 'Result release delay', $delay === null ? 'no settings row yet (defaults apply)' : $delay.' hour(s) after grading');
            }
        } catch (Throwable) {
            // The database check above already reported the problem.
        }
    }

    private function checkProviders(bool $network): void
    {
        $hosts = [];
        foreach ((array) config('services', []) as $name => $service) {
            if (!is_array($service) || !isset($service['url'])) {
                continue;
            }
            $key = (string) ($service['key'] ?? '');
            $this->add($key === '' ? 'FAIL' : 'OK', "services.{$name} API key", $key === '' ? 'empty' : 'set');
            $host = parse_url((string) $service['url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[$host] = (string) $service['url'];
            }
        }
        if (!$network) {
            $this->add('WARN', 'Outbound HTTPS', 'not tested: re-run with --network from the server');

            return;
        }
        foreach ($hosts as $host => $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            try {
                // Any HTTP answer, even 401 or 404, proves the server can reach the provider.
                $response = Http::timeout(10)->connectTimeout(6)->head($scheme.'://'.$host.'/');
                $this->add('OK', "Outbound HTTPS to {$host}", 'reachable (HTTP '.$response->status().')');
            } catch (ConnectionException $error) {
                $this->add('FAIL', "Outbound HTTPS to {$host}", 'blocked or unreachable: '.$error->getMessage());
            } catch (Throwable $error) {
                $this->add('WARN', "Outbound HTTPS to {$host}", $error->getMessage());
            }
        }
    }
}
