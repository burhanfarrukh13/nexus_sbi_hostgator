<?php

namespace App\Http\Controllers;

use App\Services\PrivateFileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class StorageController
{
    public function __construct(private readonly PrivateFileStorage $storage)
    {
    }

    public function download(Request $request)
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'max:240'],
            'expires' => ['required', 'integer'],
            'userId' => ['required', 'uuid'],
            'nonce' => ['required', 'regex:/^[a-f0-9]{32}$/'],
            'signature' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $identity = $request->attributes->get('apiIdentity');
        if (!hash_equals((string) $identity['userId'], $input['userId'])) {
            throw new BadRequestHttpException('Signed-URL userId mismatch');
        }
        if (!$this->storage->verifySignedUrl(
            $input['key'],
            (int) $input['expires'],
            $input['userId'],
            $input['nonce'],
            $input['signature']
        )) {
            throw new BadRequestHttpException('Invalid or expired storage signature');
        }

        try {
            $path = $this->storage->resolve($input['key']);
        } catch (Throwable) {
            throw new BadRequestHttpException('Invalid storage key');
        }
        if (!is_file($path)) {
            throw new NotFoundHttpException('File not found');
        }

        return response()->streamDownload(
            static function () use ($path): void {
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    return;
                }
                fpassthru($stream);
                fclose($stream);
            },
            basename($path),
            [
                'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }
}
