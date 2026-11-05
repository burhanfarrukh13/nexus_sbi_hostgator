<?php

namespace App\Services;

use RuntimeException;

class ImageCompactor
{
    private const MAX_WIDTH = 1400;
    private const MAX_BYTES = 350 * 1024;
    private const JPEG_QUALITY = 80;

    /**
     * @return array{buffer: string, mimeType: string}
     */
    public function compact(string $buffer): array
    {
        $image = @getimagesizefromstring($buffer);
        if ($image === false) {
            throw new RuntimeException('An uploaded answer image is invalid.');
        }
        $detectedMimeType = (string) ($image['mime'] ?? '');
        if (!in_array($detectedMimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Upload an answer image in JPEG, PNG, or WEBP format.');
        }
        if ($image[0] < 1 || $image[1] < 1 || $image[0] * $image[1] > 40000000) {
            throw new RuntimeException('The uploaded image dimensions are too large to process safely.');
        }
        if ($image[0] <= self::MAX_WIDTH && strlen($buffer) <= self::MAX_BYTES) {
            return ['buffer' => $buffer, 'mimeType' => $detectedMimeType];
        }

        if (class_exists(\Imagick::class)) {
            return $this->compactWithImagick($buffer);
        }
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            return $this->compactWithGd($buffer);
        }

        throw new RuntimeException('Image optimization is unavailable on this server; upload a smaller image.');
    }

    private function compactWithImagick(string $buffer): array
    {
        $source = new \Imagick();
        $flattened = null;
        try {
            $source->readImageBlob($buffer);
            $source->setIteratorIndex(0);
            $source->setImageBackgroundColor('white');
            $flattened = $source->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image = $flattened;
            $image->stripImage();
            $width = $image->getImageWidth();
            if ($width > self::MAX_WIDTH) {
                $image->resizeImage(self::MAX_WIDTH, 0, \Imagick::FILTER_LANCZOS, 1);
            }
            $image->setImageFormat('jpeg');
            for ($quality = self::JPEG_QUALITY; $quality >= 50; $quality -= 5) {
                $image->setImageCompressionQuality($quality);
                $result = $image->getImageBlob();
                if (strlen($result) <= self::MAX_BYTES || $quality === 50) {
                    if (strlen($result) > self::MAX_BYTES) {
                        throw new RuntimeException('The uploaded image is too large to optimize safely.');
                    }
                    return ['buffer' => $result, 'mimeType' => 'image/jpeg'];
                }
            }
        } catch (\ImagickException $error) {
            throw new RuntimeException('The uploaded answer image could not be optimized.', previous: $error);
        } finally {
            if ($flattened instanceof \Imagick) {
                $flattened->clear();
                $flattened->destroy();
            }
            $source->clear();
            $source->destroy();
        }

        throw new RuntimeException('The uploaded answer image could not be optimized.');
    }

    private function compactWithGd(string $buffer): array
    {
        $source = @imagecreatefromstring($buffer);
        if ($source === false) {
            throw new RuntimeException('The uploaded answer image could not be optimized.');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_WIDTH / max(1, $width));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        try {
            for ($quality = self::JPEG_QUALITY; $quality >= 50; $quality -= 5) {
                ob_start();
                imagejpeg($resized, null, $quality);
                $result = (string) ob_get_clean();
                if (strlen($result) <= self::MAX_BYTES || $quality === 50) {
                    if (strlen($result) > self::MAX_BYTES) {
                        throw new RuntimeException('The uploaded image is too large to optimize safely.');
                    }
                    return ['buffer' => $result, 'mimeType' => 'image/jpeg'];
                }
            }
        } finally {
            imagedestroy($resized);
        }

        throw new RuntimeException('The uploaded answer image could not be optimized.');
    }
}
