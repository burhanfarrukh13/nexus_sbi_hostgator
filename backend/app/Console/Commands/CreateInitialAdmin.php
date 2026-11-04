<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInitialAdmin extends Command
{
    protected $signature = 'nexus:admin-create';
    protected $description = 'Interactively create the first administrator on an empty deployment';

    public function handle(): int
    {
        if (DB::table('users')->where('role', 'admin')->exists()) {
            $this->error('An administrator already exists; this command only creates the first account.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->ask('Administrator email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('Administrator password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm administrator password');
        if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
            $this->error('Passwords must match and contain at least 12 characters.');

            return self::FAILURE;
        }

        $lockName = 'nexus_sbi_initial_admin';
        $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            $this->error('Could not acquire the initial-administrator provisioning lock.');

            return self::FAILURE;
        }
        try {
            $created = DB::transaction(function () use ($email, $password): bool {
                if (DB::table('users')->where('role', 'admin')->exists()) {
                    return false;
                }
                if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new RuntimeException('That email address already belongs to an account.');
                }
                $id = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $id,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => 'admin',
                    'refreshCounter' => 0,
                    'must_reset_password' => false,
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);
                DB::table('user_role_assignments')->insert([
                    'userId' => $id,
                    'permission' => 'admin',
                    'granteeId' => $id,
                    'grantedAt' => now(),
                ]);

                return true;
            });
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }

        if (!$created) {
            $this->error('Another administrator was created concurrently; no account was added.');

            return self::FAILURE;
        }
        $this->info('Initial administrator created.');

        return self::SUCCESS;
    }
}
