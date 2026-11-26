<?php

declare(strict_types=1);

namespace Tests;

use App\Csv;
use PHPUnit\Framework\TestCase;

/** CSV that opens correctly in Excel with Marathi names (BUILD_PLAN M7). */
final class CsvTest extends TestCase
{
    public function testUtf8WithBomCommaSeparatedCrlfLines(): void
    {
        $csv = Csv::build(['नाव', 'Amount'], [['बदाम शेक (फुल)', '960.00'], ['Vanilla', 45]]);

        self::assertSame("\xEF\xBB\xBF", substr($csv, 0, 3), 'BOM so Excel reads UTF-8');
        self::assertSame("\xEF\xBB\xBFनाव,Amount\r\nबदाम शेक (फुल),960.00\r\nVanilla,45\r\n", $csv);
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    public function testQuoting(): void
    {
        self::assertSame('"Shah, Ravi"', Csv::cell('Shah, Ravi'));
        self::assertSame('"He said ""hi"""', Csv::cell('He said "hi"'));
        self::assertSame("\"line 1\nline 2\"", Csv::cell("line 1\nline 2"));
        self::assertSame('', Csv::cell(null));
        self::assertSame('plain', Csv::cell('plain'));
    }

    public function testTextExcelWouldRunAsAFormulaIsDefused(): void
    {
        // Names and notes are typed by customers on the public order pages.
        self::assertSame("'=HYPERLINK(\"\"x\"\")", substr(Csv::cell('=HYPERLINK("x")'), 1, -1));
        self::assertSame("'=1+1", Csv::cell('=1+1'));
        self::assertSame("'+91 98765", Csv::cell('+91 98765'));
        self::assertSame("'-cmd", Csv::cell('-cmd'));
        self::assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        self::assertSame("'\tx", Csv::cell("\tx"));
    }

    public function testNumbersStayNumbers(): void
    {
        self::assertSame('-5', Csv::cell(-5));
        self::assertSame('-12.50', Csv::cell('-12.50'));
        self::assertSame('1600.00', Csv::cell('1600.00'));
        self::assertSame('9876543210', Csv::cell('9876543210'));
        self::assertSame('2026-11-26', Csv::cell('2026-11-26'));
    }
}
