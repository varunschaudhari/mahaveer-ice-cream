<?php

declare(strict_types=1);

namespace App\Services;

use App\ValidationException;
use GdImage;

/**
 * Photo uploads (item photos now, gallery in M8). Accepts JPG/PNG/WebP up to 5 MB, checks the real
 * content type, fixes phone-camera rotation, shrinks to max 1200 px and re-encodes as WebP with a
 * random file name in storage/uploads. Re-encoding also strips anything hidden in the file.
 */
final class ImageService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 1200;
    private const MAX_PIXELS = 40_000_000; // refuse giant images before GD runs out of memory
    private const QUALITY = 80;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? APP_ROOT . '/storage/uploads';
    }

    /** @param array $file one entry of $_FILES. @return string stored file name */
    public function storeUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::one('photo', 'image.error.too_big');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw ValidationException::one('photo', 'image.error.upload');
        }
        return $this->storeFile((string) $file['tmp_name']);
    }

    /** Converts an image file on disk; storeUpload() calls this after checking the upload. */
    public function storeFile(string $path): string
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw ValidationException::one('photo', 'image.error.too_big');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);
        if (!in_array($mime, self::TYPES, true) || $size === false) {
            throw ValidationException::one('photo', 'image.error.type');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::one('photo', 'image.error.too_big');
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };
        if (!$image instanceof GdImage) {
            throw ValidationException::one('photo', 'image.error.type');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->fixOrientation($image, $path);
        }
        $image = $this->shrink($image);

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        $name = bin2hex(random_bytes(16)) . '.webp';
        if (!imagewebp($image, $this->dir . '/' . $name, self::QUALITY)) {
            throw new \RuntimeException('Could not save WebP image');
        }
        return $name;
    }

    public function delete(?string $name): void
    {
        if ($name !== null && self::isValidName($name) && is_file($this->dir . '/' . $name)) {
            unlink($this->dir . '/' . $name);
        }
    }

    public function path(string $name): ?string
    {
        $path = $this->dir . '/' . $name;
        return self::isValidName($name) && is_file($path) ? $path : null;
    }

    /** Only names this class generates: 32 hex characters + .webp (no paths, no tricks). */
    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.webp$/', $name);
    }

    private function shrink(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        if ($scale >= 1) {
            return $image;
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $resized;
    }

    /** Phone cameras store rotation in EXIF instead of rotating pixels; GD ignores it. */
    private function fixOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
