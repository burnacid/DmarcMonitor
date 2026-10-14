<?php

namespace Tests\Unit;

use App\Support\CsvRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvRowTest extends TestCase
{
    #[DataProvider('formulaProvider')]
    public function test_cells_that_start_a_formula_are_prefixed(string $cell): void
    {
        $this->assertSame(["'".$cell], CsvRow::safe([$cell]));
    }

    public static function formulaProvider(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+1'],
            'minus' => ['-1'],
            'at' => ['@SUM(A1)'],
            'tab' => ["\t=1"],
            'carriage return' => ["\r=1"],
        ];
    }

    public function test_ordinary_text_numbers_and_empty_cells_are_left_alone(): void
    {
        $this->assertSame(
            ['example.com', 'v=DMARC1; p=none', 42, -5, 99.5, '', null],
            CsvRow::safe(['example.com', 'v=DMARC1; p=none', 42, -5, 99.5, '', null]),
        );
    }
}
