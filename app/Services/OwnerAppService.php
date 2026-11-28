<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The owner app (Android APK, DEPLOYMENT §7b) offered for download in admin. Published files live in
 * order/downloads/ as mahavir-owner-<env>-<version>.apk. Staging and production deploy the same
 * folder, so the name says which address the app opens and each server offers only its own.
 */
final class OwnerAppService
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/order/downloads';
    }

    /**
     * The newest published app for this server's environment, or null when there is none.
     *
     * @return array{url: string, version: string, size_mb: string}|null
     */
    public static function latest(string $env, ?string $dir = null): ?array
    {
        if (!preg_match('/^[a-z]+$/', $env)) {
            return null;
        }
        $best = null;
        foreach (glob(($dir ?? self::dir()) . '/mahavir-owner-' . $env . '-*.apk') ?: [] as $path) {
            if (!preg_match('/-(\d+(?:\.\d+){0,3})\.apk$/', $path, $m)) {
                continue;
            }
            if ($best === null || version_compare($m[1], $best['version'], '>')) {
                $best = ['version' => $m[1], 'file' => basename($path), 'bytes' => (int) filesize($path)];
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'url' => '/downloads/' . rawurlencode($best['file']),
            'version' => $best['version'],
            'size_mb' => number_format($best['bytes'] / 1048576, 1),
        ];
    }
}
