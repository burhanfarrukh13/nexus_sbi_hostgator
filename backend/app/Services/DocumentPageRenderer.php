<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentPageRenderer
{
    public const MAX_PAGES = 30;

    public function pageCount(string $path): int
    {
        try {
            $document = (new \Smalot\PdfParser\Parser())->parseFile($path);
            $count = count($document->getPages());
        } catch (\Throwable $error) {
            throw new RuntimeException('The PDF could not be read.', previous: $error);
        }
        if ($count < 1 || $count > self::MAX_PAGES) {
            throw new RuntimeException('PDF files must contain between 1 and 30 pages.');
        }

        return $count;
    }

    /**
     * @return array{buffer: string, mimeType: string}
     */
    public function renderPage(string $path, string $mimeType, int $pageNumber): array
    {
        if ($mimeType !== 'application/pdf') {
            if ($pageNumber !== 1) {
                throw new RuntimeException('Image files contain one page.');
            }
            $buffer = file_get_contents($path);
            $image = is_string($buffer) ? @getimagesizefromstring($buffer) : false;
            if (!is_string($buffer) || $image === false) {
                throw new RuntimeException('The image file could not be read.');
            }

            return ['buffer' => $buffer, 'mimeType' => image_type_to_mime_type($image[2])];
        }

        $pageCount = $this->pageCount($path);
        if ($pageNumber < 1 || $pageNumber > $pageCount) {
            throw new RuntimeException('The requested PDF page does not exist.');
        }
        if (class_exists(\Imagick::class)) {
            return $this->renderWithImagick($path, $pageNumber);
        }

        return $this->renderWithPdftoppm($path, $pageNumber);
    }

    /**
     * @return list<array{buffer: string, mimeType: string, pageNumber: int}>
     */
    public function renderAll(string $path): array
    {
        $pageCount = $this->pageCount($path);
        $pages = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $pages[] = $this->renderPage($path, 'application/pdf', $page) + ['pageNumber' => $page];
        }

        return $pages;
    }

    private function renderWithImagick(string $path, int $pageNumber): array
    {
        $image = new \Imagick();
        try {
            $image->setResolution(150, 150);
            $image->readImage($path.'['.($pageNumber - 1).']');
            $image->setImageBackgroundColor('white');
            $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->clear();
            $image->destroy();
            $image = $flattened;
            $image->thumbnailImage(1800, 1800, true, true);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(80);
            $buffer = $image->getImageBlob();
            if ($buffer === '') {
                throw new RuntimeException('The PDF page could not be rendered.');
            }

            return ['buffer' => $buffer, 'mimeType' => 'image/jpeg'];
        } catch (\ImagickException $error) {
            throw new RuntimeException('The PDF page could not be rendered.', previous: $error);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private function renderWithPdftoppm(string $path, int $pageNumber): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-pdf-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('PDF page rendering is unavailable.');
        }
        $output = $directory.DIRECTORY_SEPARATOR.'page';
        try {
            $binary = (string) config('services.pdf_renderer.binary', 'pdftoppm');
            $process = new Process([
                $binary,
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-scale-to', '1800',
                '-jpeg',
                '-jpegopt', 'quality=80',
                $path,
                $output,
            ]);
            $process->setTimeout(90);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new RuntimeException('PDF page rendering is unavailable.');
            }
            $renderedPath = $output.'-'.$pageNumber.'.jpg';
            $buffer = is_file($renderedPath) ? file_get_contents($renderedPath) : false;
            if (!is_string($buffer) || $buffer === '') {
                throw new RuntimeException('The PDF page could not be rendered.');
            }

            return ['buffer' => $buffer, 'mimeType' => 'image/jpeg'];
        } catch (\Throwable $error) {
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('PDF page rendering is unavailable.', previous: $error);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file) && !is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }
}
