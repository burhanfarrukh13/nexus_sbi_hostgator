<?php

namespace Tests\Unit;

use App\Services\ImageCompactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImageCompactorTest extends TestCase
{
    public function test_it_resizes_large_images_to_a_provider_friendly_jpeg(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD is not installed.');
        }

        $image = imagecreatetruecolor(1501, 2);
        ob_start();
        imagejpeg($image, null, 90);
        $buffer = (string) ob_get_clean();
        imagedestroy($image);

        $result = (new ImageCompactor())->compact($buffer);
        $metadata = getimagesizefromstring($result['buffer']);

        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertSame(1400, $metadata[0]);
    }

    public function test_it_rejects_non_image_data(): void
    {
        $this->expectException(RuntimeException::class);

        (new ImageCompactor())->compact('not an image');
    }
}
