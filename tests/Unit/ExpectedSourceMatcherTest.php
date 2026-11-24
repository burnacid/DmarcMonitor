<?php

namespace Tests\Unit;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Services\Analytics\ExpectedSourceMatcher;
use App\Support\ExpectedSourceMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpectedSourceMatcherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{ExpectedSourceMatch, string, array<string, mixed>, bool}>
     */
    public static function matchCases(): array
    {
        return [
            'helo domain itself' => [ExpectedSourceMatch::HeloDomain, 'ipw.inkyphishfence.com', ['spf_domain' => 'ipw.inkyphishfence.com'], true],
            'helo parent domain' => [ExpectedSourceMatch::HeloDomain, 'InkyPhishFence.com', ['spf_domain' => 'ipw.inkyphishfence.com'], true],
            'helo lookalike domain' => [ExpectedSourceMatch::HeloDomain, 'inkyphishfence.com', ['spf_domain' => 'xinkyphishfence.com'], false],
            'ptr suffix' => [ExpectedSourceMatch::PtrSuffix, 'compute.amazonaws.com', ['ptr_hostname' => 'ec2-34-249-87-65.eu-west-1.compute.amazonaws.com'], true],
            'ptr other host' => [ExpectedSourceMatch::PtrSuffix, 'compute.amazonaws.com', ['ptr_hostname' => 'mail.example.net'], false],
            'ip in range' => [ExpectedSourceMatch::IpOrCidr, '34.249.0.0/16', ['source_ip' => '34.249.87.65'], true],
            'ip outside range' => [ExpectedSourceMatch::IpOrCidr, '34.248.0.0/16', ['source_ip' => '34.249.87.65'], false],
            'asn with prefix' => [ExpectedSourceMatch::Asn, 'AS16509', ['asn' => 16509], true],
            'other asn' => [ExpectedSourceMatch::Asn, '15169', ['asn' => 16509], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $recordAttributes
     */
    #[DataProvider('matchCases')]
    public function test_each_match_type_recognises_its_records(ExpectedSourceMatch $type, string $value, array $recordAttributes, bool $expected): void
    {
        $record = AggregateReportRecord::factory()->make($recordAttributes);
        $source = ExpectedSource::factory()->make(['match_type' => $type, 'match_value' => $value]);

        $this->assertSame($expected, $source->matches($record));
    }

    public function test_a_null_sender_only_source_skips_records_with_an_envelope_sender(): void
    {
        $source = ExpectedSource::factory()->make(['match_value' => 'inkyphishfence.com', 'null_sender_only' => true]);

        $this->assertTrue($source->matches(AggregateReportRecord::factory()->make(['spf_domain' => 'ipw.inkyphishfence.com', 'is_null_sender' => true])));
        $this->assertFalse($source->matches(AggregateReportRecord::factory()->make(['spf_domain' => 'ipw.inkyphishfence.com', 'is_null_sender' => false])));
    }

    public function test_the_most_specific_active_source_is_linked_to_matching_records(): void
    {
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);
        $inky = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'spf_domain' => 'ipw.inkyphishfence.com']);
        $other = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'spf_domain' => 'example.com']);

        ExpectedSource::factory()->create(['organisation_id' => null, 'match_value' => 'inkyphishfence.com']);
        ExpectedSource::factory()->create(['organisation_id' => $organisation->id, 'match_value' => 'inkyphishfence.com']);
        $domainSource = ExpectedSource::factory()->create(['organisation_id' => $organisation->id, 'domain_id' => $domain->id, 'match_value' => 'inkyphishfence.com']);
        ExpectedSource::factory()->create(['organisation_id' => $organisation->id, 'domain_id' => $domain->id, 'match_value' => 'example.com', 'is_active' => false]);
        ExpectedSource::factory()->create(['organisation_id' => Organisation::factory(), 'match_value' => 'example.com']);

        app(ExpectedSourceMatcher::class)->applyToReport($report);

        $this->assertSame($domainSource->id, $inky->fresh()->expected_source_id);
        $this->assertNull($other->fresh()->expected_source_id);
    }

    public function test_reapplying_after_a_source_is_removed_unlinks_its_records(): void
    {
        $domain = Domain::factory()->create(['organisation_id' => Organisation::factory()]);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);
        $record = AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'ptr_hostname' => 'gw.inkyphishfence.com']);
        $source = ExpectedSource::factory()->create(['organisation_id' => $domain->organisation_id, 'match_type' => ExpectedSourceMatch::PtrSuffix, 'match_value' => 'inkyphishfence.com']);

        app(ExpectedSourceMatcher::class)->reapply([$domain->id]);
        $this->assertSame($source->id, $record->fresh()->expected_source_id);

        $source->update(['is_active' => false]);
        app(ExpectedSourceMatcher::class)->reapply([$domain->id]);
        $this->assertNull($record->fresh()->expected_source_id);
    }
}
