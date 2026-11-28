<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR codes for printed posters and table stickers (M4), as SVG data URIs for an <img>. */
final class QrService
{
    /**
     * How a printed QR may be used (decision 2026-11-28):
     *   'live'    production over https: a clean code, for the shop
     *   'test'    staging over https: a clean, scannable code with a "TEST – not for customers"
     *             band above and below it, to test the whole flow with real phones
     *   'blocked' anywhere else (local, http): a stamp across the code so it can never be scanned
     */
    public static function mode(string $env, string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            return 'blocked';
        }
        return match ($env) {
            'production' => 'live',
            'staging' => 'test',
            default => 'blocked',
        };
    }

    public static function svgDataUri(string $url): string
    {
        $options = new QROptions();
        $options->outputBase64 = true;      // data:image/svg+xml;base64,… — fits the CSP (img-src data:)
        $options->drawLightModules = false; // white background comes from the paper
        $options->connectPaths = true;      // smaller SVG
        $options->addQuietzone = true;
        return (new QRCode($options))->render($url);
    }
}
