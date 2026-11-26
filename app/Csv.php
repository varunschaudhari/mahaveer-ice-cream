<?php

declare(strict_types=1);

namespace App;

/**
 * CSV downloads that open correctly in Excel (BUILD_PLAN M7): UTF-8 with a BOM (so Marathi
 * shows), comma separated, CRLF line ends, RFC 4180 quoting.
 *
 * Text that Excel would run as a formula (starting with = + - @ tab or CR, e.g. a customer
 * name typed on the public order page) gets a leading apostrophe. Numbers are written as plain
 * numbers (amounts as rupees "1600.00") so Excel can add them up.
 */
final class Csv
{
    private const BOM = "\xEF\xBB\xBF";

    /** @param iterable<array<int, mixed>> $rows */
    public static function build(array $header, iterable $rows): string
    {
        $out = self::BOM . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }
        return $out;
    }

    /** Sends the file as a download and ends the request. $name: file name without ".csv". */
    public static function send(string $name, array $header, iterable $rows): never
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '-', $name) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo self::build($header, $rows);
        exit;
    }

    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $text = (string) $value;
        if ($text !== '' && !is_int($value) && !is_float($value) && !is_numeric($text) && strpbrk($text[0], "=+-@\t\r") !== false) {
            $text = "'" . $text;
        }
        if (strpbrk($text, ",\"\r\n") !== false) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }
        return $text;
    }

    private static function line(array $row): string
    {
        return implode(',', array_map([self::class, 'cell'], $row)) . "\r\n";
    }
}
