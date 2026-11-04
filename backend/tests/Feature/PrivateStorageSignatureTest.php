<?php

namespace Tests\Feature;

use App\Services\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivateStorageSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_download_signature_is_user_bound_and_single_use(): void
    {
        Config::set('auth.storage_signing_secret', str_repeat('storage-secret-', 4));
        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'storage@example.test',
            'password' => 'unused',
            'role' => 'admin',
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        $storage = app(PrivateFileStorage::class);
        $signed = $storage->signedUrl('files/report.pdf', $userId);
        parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $query);

        self::assertTrue($storage->verifySignedUrl(
            $query['key'],
            (int) $query['expires'],
            $query['userId'],
            $query['nonce'],
            $query['signature']
        ));
        self::assertFalse($storage->verifySignedUrl(
            $query['key'],
            (int) $query['expires'],
            $query['userId'],
            $query['nonce'],
            $query['signature']
        ));
    }
}
