<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Closure;
use RuntimeException;

class PrivateFileStorage
{
    private function root(): string
    {
        $root = rtrim((string) config('filesystems.private_root'), DIRECTORY_SEPARATOR);
        $isAbsolute = str_starts_with($root, DIRECTORY_SEPARATOR) ||
            preg_match('/^[A-Za-z]:[\\\\\/]/', $root) === 1;
        if ($root === '' || !$isAbsolute || is_link($root)) {
            throw new RuntimeException('Private storage must use an absolute path outside the web root.');
        }

        $public = realpath(public_path());
        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedPublic = $public ? rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : null;
        if ($normalizedPublic && strncasecmp($normalizedRoot, $normalizedPublic, strlen($normalizedPublic)) === 0) {
            throw new RuntimeException('Private storage cannot be inside the public web root.');
        }

        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Private storage is unavailable.');
        }
        $candidate = realpath($root);
        if (!$candidate || is_link($root) ||
            ($public && strncasecmp(rtrim($candidate, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, strlen(rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) === 0)) {
            throw new RuntimeException('Private storage cannot be inside the public web root.');
        }
        @chmod($root, 0700);

        return $candidate;
    }

    public function store(UploadedFile $file, string $namespace = 'files'): string
    {
        if ($file->getSize() === false || $file->getSize() > 100 * 1024 * 1024) {
            throw new RuntimeException('Uploaded file exceeds the 100 MiB application limit.');
        }
        $safeNamespace = trim($namespace, '/');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/', $safeNamespace)) {
            throw new RuntimeException('Invalid storage namespace.');
        }
        $extension = strtolower((string) $file->guessExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.'.$extension : '';
        $key = $safeNamespace.'/'.Str::uuid().$extension;
        $directory = $this->root().DIRECTORY_SEPARATOR.$safeNamespace;
        if (is_link($directory)) {
            throw new RuntimeException('Private storage directory is invalid.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private storage is unavailable.');
        }

        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.Str::uuid().'.part';
        $destination = $this->resolve($key);
        if (is_link($destination)) {
            throw new RuntimeException('Private storage destination is invalid.');
        }
        try {
            if (!copy($file->getRealPath(), $temporary) || !rename($temporary, $destination)) {
                throw new RuntimeException('Unable to persist uploaded file.');
            }
            @chmod($destination, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $key;
    }

    public function resolve(string $key): string
    {
        if ($key === '' || strlen($key) > 240 || str_contains($key, '..') ||
            str_contains($key, '\\') || str_starts_with($key, '/') ||
            !preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#', $key)) {
            throw new RuntimeException('Invalid private storage key.');
        }

        $path = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
        $namespacePath = dirname($path);
        if (is_link($namespacePath) || is_link($path)) {
            throw new RuntimeException('Invalid private storage path.');
        }

        return $path;
    }

    public function signedUrl(string $key, string $userId, int $ttlSeconds = 300): array
    {
        $secret = (string) config('auth.storage_signing_secret');
        if (strlen($secret) < 32) {
            throw new RuntimeException('Storage signing secret is not configured.');
        }
        $expires = time() + min(300, max(30, $ttlSeconds));
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', "{$key}:{$expires}:{$userId}:{$nonce}", $secret);
        $this->registerNonce($nonce, $expires);

        return [
            'url' => '/api/storage/file?'.http_build_query([
                'key' => $key,
                'expires' => $expires,
                'userId' => $userId,
                'nonce' => $nonce,
                'signature' => $signature,
            ]),
            'expiresAt' => $expires,
            'userId' => $userId,
            'nonce' => $nonce,
            'requiresAuth' => true,
        ];
    }

    public function verifySignedUrl(string $key, int $expires, string $userId, string $nonce, string $signature): bool
    {
        if ($expires <= time() || $expires > time() + 300 ||
            !preg_match('/^[a-f0-9]{32}$/', $nonce) ||
            !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }
        $secret = (string) config('auth.storage_signing_secret');
        if (strlen($secret) < 32) {
            return false;
        }

        $signatureValid = hash_equals(
            hash_hmac('sha256', "{$key}:{$expires}:{$userId}:{$nonce}", $secret),
            $signature
        );
        if (!$signatureValid) {
            return false;
        }

        return $this->consumeNonce($nonce);
    }

    public function delete(string $key): void
    {
        $path = $this->resolve($key);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to delete private file.');
        }
    }

    public function withGradingSlot(Closure $operation, ?int $slotCount = null): mixed
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.'.grading-slots';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('The grading queue is unavailable.');
        }
        $slotCount = max(1, min(4, $slotCount ?? (int) config('services.grading.workers', 2)));
        $handle = null;
        $deadline = microtime(true) + 300;
        do {
            for ($slot = 0; $slot < $slotCount; $slot++) {
                $path = $directory.DIRECTORY_SEPARATOR.'slot-'.$slot.'.lock';
                if (is_link($path)) {
                    throw new RuntimeException('The grading queue is unavailable.');
                }
                $candidate = @fopen($path, 'c');
                if ($candidate !== false && flock($candidate, LOCK_EX | LOCK_NB)) {
                    $handle = $candidate;
                    @chmod($path, 0600);
                    break 2;
                }
                if (is_resource($candidate)) {
                    fclose($candidate);
                }
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        if (!is_resource($handle)) {
            throw new RuntimeException('AI grading is busy. Please retry the saved submission shortly.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Claims one chat slot without waiting. The caller must release the handle
     * after the provider request (including failure) has finished.
     *
     * @return resource|null
     */
    public function acquireChatSlot(?int $slotCount = null)
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.'.notebook-chat-slots';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('The Notebook chat queue is unavailable.');
        }

        $slotCount = max(1, min(8, $slotCount ?? (int) config('services.ai_notebook.max_concurrent', 8)));
        for ($slot = 0; $slot < $slotCount; $slot++) {
            $path = $directory.DIRECTORY_SEPARATOR.'slot-'.$slot.'.lock';
            if (is_link($path)) {
                throw new RuntimeException('The Notebook chat queue is unavailable.');
            }
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                @chmod($path, 0600);

                return $handle;
            }
            fclose($handle);
        }

        return null;
    }

    /**
     * @param resource|null $handle
     */
    public function releaseChatSlot($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Claims one of the background grading worker locks, or returns null when every
     * worker is already running. Because the number of workers never exceeds the
     * number of grading slots, a worker that holds a lock always gets a slot at once.
     *
     * @return resource|null
     */
    public function acquireWorkerLock(?int $maxWorkers = null)
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.'.grading-workers';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('The grading queue is unavailable.');
        }
        $maxWorkers = max(1, min(4, $maxWorkers ?? (int) config('services.grading.workers', 2)));
        for ($worker = 0; $worker < $maxWorkers; $worker++) {
            $path = $directory.DIRECTORY_SEPARATOR.'worker-'.$worker.'.lock';
            if (is_link($path)) {
                throw new RuntimeException('The grading queue is unavailable.');
            }
            $candidate = @fopen($path, 'c');
            if ($candidate === false) {
                continue;
            }
            if (flock($candidate, LOCK_EX | LOCK_NB)) {
                @chmod($path, 0600);

                return $candidate;
            }
            fclose($candidate);
        }

        return null;
    }

    /**
     * @param resource|null $handle
     */
    public function releaseWorkerLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function registerNonce(string $nonce, int $expires): void
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.'.download-nonces';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('Private download signatures are unavailable.');
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.nonce') ?: [] as $path) {
            if (is_link($path)) {
                throw new RuntimeException('Invalid private download signature state.');
            }
            if (filemtime($path) !== false && filemtime($path) < time() - 300) {
                @unlink($path);
            }
        }

        $path = $directory.DIRECTORY_SEPARATOR.$nonce.'.nonce';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to register private download signature.');
        }
        try {
            if (fwrite($handle, (string) $expires) === false) {
                throw new RuntimeException('Unable to register private download signature.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function consumeNonce(string $nonce): bool
    {
        $path = $this->root().DIRECTORY_SEPARATOR.'.download-nonces'.DIRECTORY_SEPARATOR.$nonce.'.nonce';
        if (is_link($path) || !is_file($path)) {
            return false;
        }

        $registeredExpiry = file_get_contents($path);
        if ($registeredExpiry === false || (int) $registeredExpiry <= time()) {
            @unlink($path);
            return false;
        }

        return unlink($path);
    }
}
