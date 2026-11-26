<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ImageService;
use App\ValidationException;
use PHPUnit\Framework\TestCase;

final class ImageServiceTest extends TestCase
{
    private string $dir;
    private string $source;
    private ImageService $images;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/mahavir-image-test-' . bin2hex(random_bytes(4));
        $this->dir = $base . '/uploads';
        $this->source = $base . '/source';
        mkdir($this->source, 0777, true);
        $this->images = new ImageService($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->source] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->dir));
    }

    private function makeImage(string $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 194, 24, 91));
        $path = $this->source . '/photo.' . $type;
        match ($type) {
            'png' => imagepng($image, $path),
            'jpg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
        };
        return $path;
    }

    public function testLargePngIsShrunkToTwelveHundredPixelsAndSavedAsWebp(): void
    {
        $name = $this->images->storeFile($this->makeImage('png', 2000, 1000));

        self::assertTrue(ImageService::isValidName($name));
        $path = $this->images->path($name);
        self::assertNotNull($path);
        self::assertSame('image/webp', mime_content_type($path));
        [$width, $height] = getimagesize($path);
        self::assertSame([1200, 600], [$width, $height]);
    }

    public function testSmallJpegKeepsItsSize(): void
    {
        $name = $this->images->storeFile($this->makeImage('jpg', 300, 200));
        self::assertSame([300, 200], array_slice(getimagesize($this->images->path($name)), 0, 2));
    }

    public function testEachUploadGetsANewRandomName(): void
    {
        $file = $this->makeImage('webp', 50, 50);
        self::assertNotSame($this->images->storeFile($file), $this->images->storeFile($file));
    }

    public function testNonImageIsRejected(): void
    {
        $path = $this->source . '/fake.png';
        file_put_contents($path, '<?php echo "not an image";');

        $this->expectException(ValidationException::class);
        $this->images->storeFile($path);
    }

    public function testDeleteRemovesTheFile(): void
    {
        $name = $this->images->storeFile($this->makeImage('png', 10, 10));
        $this->images->delete($name);
        self::assertNull($this->images->path($name));
    }

    public function testOnlyGeneratedNamesAreAccepted(): void
    {
        self::assertTrue(ImageService::isValidName(str_repeat('a1', 16) . '.webp'));
        foreach (['../config.php', 'abc.webp', str_repeat('a', 32) . '.php', str_repeat('A', 32) . '.webp', '/' . str_repeat('a', 32) . '.webp'] as $bad) {
            self::assertFalse(ImageService::isValidName($bad), $bad);
            self::assertNull($this->images->path($bad));
        }
    }
}
