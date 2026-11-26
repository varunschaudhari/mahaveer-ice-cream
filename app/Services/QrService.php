<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR codes for printed posters and table stickers (M4), as SVG data URIs for an <img>. */
final class QrService
{
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
