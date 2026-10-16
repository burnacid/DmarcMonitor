<?php

namespace Tests\Unit;

use App\Support\SpfRecord;
use PHPUnit\Framework\TestCase;

class SpfRecordTest extends TestCase
{
    public function test_parse_reads_qualifiers_names_and_arguments(): void
    {
        $terms = SpfRecord::parse('v=spf1 ip4:192.0.2.0/24 -A:mail.example.com/24 ~all redirect=_spf.example.com');

        $this->assertSame([
            ['qualifier' => '+', 'name' => 'ip4', 'argument' => ':192.0.2.0/24'],
            ['qualifier' => '-', 'name' => 'a', 'argument' => ':mail.example.com/24'],
            ['qualifier' => '~', 'name' => 'all', 'argument' => ''],
            ['qualifier' => '+', 'name' => 'redirect', 'argument' => '=_spf.example.com'],
        ], $terms);
        $this->assertSame('mail.example.com', SpfRecord::domainSpec($terms[1]));
        $this->assertSame([24, null], SpfRecord::cidrLengths($terms[1]));
    }

    public function test_build_removes_duplicates_and_puts_all_before_modifiers(): void
    {
        $record = SpfRecord::build(SpfRecord::parse('v=spf1 -all include:a.test redirect=b.test +include:a.test mx'));

        $this->assertSame('v=spf1 include:a.test mx -all redirect=b.test', $record);
    }

    public function test_dns_strings_splits_long_records_between_terms(): void
    {
        $record = 'v=spf1 '.implode(' ', array_map(fn (int $i) => "ip4:192.0.2.{$i}", range(1, 30))).' -all';

        $strings = SpfRecord::dnsStrings($record);

        $this->assertGreaterThan(1, count($strings));
        $this->assertSame($record, implode('', $strings));

        foreach (array_slice($strings, 0, -1) as $string) {
            $this->assertLessThanOrEqual(SpfRecord::MAX_STRING_LENGTH, strlen($string));
            $this->assertStringEndsWith(' ', $string);
        }

        $this->assertSame(['v=spf1 -all'], SpfRecord::dnsStrings('v=spf1 -all'));
    }
}
