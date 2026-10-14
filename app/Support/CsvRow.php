<?php

namespace App\Support;

/**
 * Makes CSV export rows safe to open in a spreadsheet. Report data comes from
 * whoever sends a report, so a reporting organisation or hostname such as
 * =HYPERLINK("…") would otherwise run as a formula in Excel or Calc.
 */
class CsvRow
{
    /**
     * Characters a spreadsheet treats as the start of a formula.
     */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Prefixes text cells that would start a formula with an apostrophe, which
     * spreadsheets show as plain text. Numbers are left as they are.
     *
     * @param  array<int, mixed>  $cells
     * @return array<int, mixed>
     */
    public static function safe(array $cells): array
    {
        return array_map(
            fn (mixed $cell) => is_string($cell) && $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'".$cell : $cell,
            $cells,
        );
    }
}
