<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DmarcRecordExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dmarc.rua_address' => 'dmarc@msp.test']);
    }

    private function domain(string $fqdn, ?string $record, array $attributes = []): Domain
    {
        return Domain::factory()->create(array_merge(['fqdn' => $fqdn, 'is_active' => true, 'dmarc_record' => $record], $attributes));
    }

    public function test_zone_export_lists_domains_not_reporting_here_with_the_record_to_publish(): void
    {
        $this->domain('elsewhere.test', 'v=DMARC1; p=quarantine; rua=mailto:other@vendor.test');
        $this->domain('missing.test', null);
        $this->domain('here.test', 'v=DMARC1; p=none; rua=mailto:dmarc@msp.test');
        $this->domain('inactive.test', null, ['is_active' => false]);

        $response = $this->actingAs(User::factory()->create())->get(route('admin.domains.dmarc-records'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame([
            '',
            '; Current: v=DMARC1; p=quarantine; rua=mailto:other@vendor.test',
            '$ORIGIN elsewhere.test.',
            "_dmarc\tIN\tTXT\t\"v=DMARC1; p=quarantine; rua=mailto:other@vendor.test,mailto:dmarc@msp.test\"",
            '',
            '; Current: none',
            '$ORIGIN missing.test.',
            "_dmarc\tIN\tTXT\t\"v=DMARC1; p=none; rua=mailto:dmarc@msp.test\"",
            '',
        ], array_slice(explode("\n", $response->streamedContent()), 1));
    }

    public function test_csv_export_has_one_row_per_domain(): void
    {
        $organisation = Organisation::factory()->create(['name' => 'Acme']);
        $this->domain('missing.test', null, ['organisation_id' => $organisation->id]);

        $response = $this->actingAs(User::factory()->create())->get(route('admin.domains.dmarc-records', ['format' => 'csv']));

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));
        $this->assertSame([
            ['Domain', 'Organisation', 'Host', 'Type', 'Value', 'Current Record'],
            ['missing.test', 'Acme', '_dmarc.missing.test', 'TXT', 'v=DMARC1; p=none; rua=mailto:dmarc@msp.test', ''],
        ], $rows);
    }

    public function test_nothing_is_exported_without_a_report_address(): void
    {
        config(['dmarc.rua_address' => null]);
        $this->domain('missing.test', null);

        $content = $this->actingAs(User::factory()->create())->get(route('admin.domains.dmarc-records'))->streamedContent();

        $this->assertStringContainsString('; Nothing to publish.', $content);
        $this->assertStringNotContainsString('missing.test', $content);
    }

    public function test_a_scoped_user_only_exports_their_organisations_domains(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        $this->domain('a.test', null, ['organisation_id' => $orgA->id]);
        $this->domain('b.test', null, ['organisation_id' => $orgB->id]);
        $user = User::factory()->editor()->create();
        $user->organisations()->attach($orgA->id);

        $content = $this->actingAs($user)->get(route('admin.domains.dmarc-records'))->streamedContent();

        $this->assertStringContainsString('$ORIGIN a.test.', $content);
        $this->assertStringNotContainsString('b.test', $content);
    }

    public function test_viewers_cannot_export(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('admin.domains.dmarc-records'))
            ->assertForbidden();
    }
}
