<?php
/**
 * Prints the SQL that creates the first administrator, for cPanel accounts without SSH
 * (the interactive `php artisan nexus:admin-create` cannot be answered from a cron job).
 *
 *   php scripts/new-admin-sql.php admin@school.edu
 *
 * Run it on your own PC, paste the output into phpMyAdmin > SQL with the production
 * database selected, and click Go. The password is hashed here (bcrypt, the same format
 * Laravel uses), so it never appears in the SQL. The statements do nothing if an
 * administrator already exists.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || str_contains($email, "'") || str_contains($email, '\\')) {
    fwrite(STDERR, "Usage: php scripts/new-admin-sql.php admin@school.edu\n");
    exit(2);
}

function promptHidden(string $label): string
{
    fwrite(STDERR, $label);
    $windows = DIRECTORY_SEPARATOR === '\\';
    if (!$windows) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if (!$windows) {
        shell_exec('stty echo');
    }
    fwrite(STDERR, "\n");

    return $value;
}

if (DIRECTORY_SEPARATOR === '\\') {
    fwrite(STDERR, "Note: input is visible on Windows. Clear the terminal afterwards.\n");
}
$password = promptHidden('Administrator password (minimum 12 characters): ');
$confirmation = promptHidden('Confirm password: ');
if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords must match and contain at least 12 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$bytes = random_bytes(16);
$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
$id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

echo <<<SQL
-- Creates the first Nexus SBI administrator ({$email}).
-- Run once in phpMyAdmin with the production database selected.
-- Does nothing if an administrator or this email already exists.
START TRANSACTION;

INSERT INTO `users` (`id`, `email`, `password`, `role`, `refreshCounter`, `must_reset_password`, `createdAt`, `updatedAt`)
SELECT '{$id}', '{$email}', '{$hash}', 'admin', 0, 0, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `role` = 'admin')
  AND NOT EXISTS (SELECT 1 FROM `users` WHERE LOWER(`email`) = '{$email}');

INSERT INTO `user_role_assignments` (`userId`, `permission`, `granteeId`, `grantedAt`)
SELECT '{$id}', 'admin', '{$id}', NOW()
WHERE EXISTS (SELECT 1 FROM `users` WHERE `id` = '{$id}');

COMMIT;

-- Check: this should return one row with role = admin.
SELECT `id`, `email`, `role` FROM `users` WHERE `id` = '{$id}';

SQL;
echo "\n";
