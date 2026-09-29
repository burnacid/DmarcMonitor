<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAuthorizationExportTest extends TestCase
{
    use RefreshDatabase;

    private function domainWithAuthorizations(string $fqdn, array $authorizations, array $attributes = []): Domain
    {
        return Domain::factory()->create(array_merge([
            'fqdn' => $fqdn,
            'is_active' => true,
            'dmarc_report_authorizations' => collect($authorizations)->map(fn (bool $authorized, string $reportDomain) => [
                'report_domain' => $reportDomain,
                'host' => "{$fqdn}._report._dmarc.{$reportDomain}",
                'authorized' => $authorized,
                'in_record' => true,
            ])->values()->all(),
        ], $attributes));
    }

    public function test_zone_export_lists_only_missing_records_grouped_by_receiving_domain(): void
    {
        $this->domainWithAuthorizations('gameforce.nl', ['burnacid.com' => false, 'vendor.test' => true]);
        $this->domainWithAuthorizations('client.test', ['burnacid.com' => false, 'vendor.test' => false]);
        $this->domainWithAuthorizations('inactive.test', ['burnacid.com' => false], ['is_active' => false]);

        $response = $this->actingAs(User::factory()->create())->get(route('admin.domains.report-authorizations'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $lines = explode("\n", $response->streamedContent());

        $this->assertSame([
            '$ORIGIN burnacid.com.',
            "client.test._report._dmarc\tIN\tTXT\t\"v=DMARC1\"",
            "gameforce.nl._report._dmarc\tIN\tTXT\t\"v=DMARC1\"",
            '',
            '; Records to publish in vendor.test',
            '$ORIGIN vendor.test.',
            "client.test._report._dmarc\tIN\tTXT\t\"v=DMARC1\"",
            '',
        ], array_slice($lines, 3));
    }

    public function test_csv_export_has_one_row_per_missing_record(): void
    {
        $this->domainWithAuthorizations('gameforce.nl', ['burnacid.com' => false, 'vendor.test' => true]);

        $response = $this->actingAs(User::factory()->create())->get(route('admin.domains.report-authorizations', ['format' => 'csv']));

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));
        $this->assertSame([
            ['Receiving Domain', 'Host', 'Type', 'Value', 'Client Domain', 'In DMARC Record'],
            ['burnacid.com', 'gameforce.nl._report._dmarc.burnacid.com', 'TXT', 'v=DMARC1', 'gameforce.nl', 'yes'],
        ], $rows);
    }

    public function test_a_scoped_user_only_exports_their_organisations_domains(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        $this->domainWithAuthorizations('a.test', ['burnacid.com' => false], ['organisation_id' => $orgA->id]);
        $this->domainWithAuthorizations('b.test', ['burnacid.com' => false], ['organisation_id' => $orgB->id]);
        $user = User::factory()->editor()->create();
        $user->organisations()->attach($orgA->id);

        $content = $this->actingAs($user)->get(route('admin.domains.report-authorizations'))->streamedContent();

        $this->assertStringContainsString('a.test._report._dmarc', $content);
        $this->assertStringNotContainsString('b.test._report._dmarc', $content);
    }

    public function test_viewers_cannot_export(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('admin.domains.report-authorizations'))
            ->assertForbidden();
    }
}
