<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Analytics\OrganisationManagementReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OrganisationManagementReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_a_pdf_for_an_organisation_the_user_can_access(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create(['name' => 'Acme Corp']);
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $this->addRecords($domain, now()->subDays(2), pass: 40, fail: 2);
        $this->addRecords($domain, now()->subDay(), pass: 50, fail: 0);

        $response = $this->actingAs($user)->get(route('organisations.management-report-pdf', $organisation));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_it_renders_for_an_organisation_without_domains_or_data(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create();

        $this->actingAs($user)
            ->get(route('organisations.management-report-pdf', [
                'organisation' => $organisation,
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk();
    }

    public function test_a_scoped_user_cannot_view_another_organisations_report(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();

        $user = User::factory()->create();
        $user->organisations()->attach($orgA->id);

        $this->actingAs($user)
            ->get(route('organisations.management-report-pdf', $orgB))
            ->assertNotFound();
    }

    public function test_the_verdict_is_healthy_when_mail_passes_and_no_domain_has_issues(): void
    {
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $this->addRecords($domain, now()->subDay(), pass: 100, fail: 0);

        $this->assertSame('healthy', $this->build($organisation)['verdict']['status']);
    }

    public function test_the_verdict_needs_attention_when_the_pass_rate_is_below_target(): void
    {
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $this->addRecords($domain, now()->subDay(), pass: 90, fail: 10);

        $this->assertSame('attention', $this->build($organisation)['verdict']['status']);
    }

    public function test_the_verdict_is_at_risk_when_a_domain_has_no_dmarc_record(): void
    {
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id, 'dmarc_status' => 'missing']);
        $this->addRecords($domain, now()->subDay(), pass: 100, fail: 0);

        $this->assertSame('at_risk', $this->build($organisation)['verdict']['status']);
    }

    public function test_kpis_compare_against_the_previous_period_of_equal_length(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00'));

        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $this->addRecords($domain, Carbon::parse('2026-08-20'), pass: 50, fail: 50);
        $this->addRecords($domain, Carbon::parse('2026-09-20'), pass: 100, fail: 0);

        $report = $this->build($organisation, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')->endOfDay());
        [$passRate, $messages, $unauthenticated] = $report['kpis'];

        $this->assertSame('2026-08-02', $report['previousFrom']->toDateString());
        $this->assertSame('2026-08-31', $report['previousTo']->toDateString());
        $this->assertSame('+50.0 pt', $passRate['delta']);
        $this->assertSame('good', $passRate['sentiment']);
        $this->assertSame('0.0%', $messages['delta']);
        $this->assertSame('-50', $unauthenticated['delta']);
        $this->assertSame('good', $unauthenticated['sentiment']);

        Carbon::setTestNow();
    }

    public function test_actions_are_prioritised_and_capped(): void
    {
        $organisation = Organisation::factory()->create();
        Domain::factory()->create(['organisation_id' => $organisation->id, 'fqdn' => 'spf.test', 'spf_status' => 'missing']);
        Domain::factory()->count(6)->create(['organisation_id' => $organisation->id, 'dmarc_status' => 'missing']);

        $report = $this->build($organisation);

        $this->assertCount(OrganisationManagementReport::MAX_ACTIONS, $report['actions']);
        $this->assertSame(2, $report['moreActions']);
        $this->assertStringContainsString('Publish a DMARC record', $report['actions'][0]);
    }

    public function test_there_are_no_actions_or_chart_without_issues_or_data(): void
    {
        $organisation = Organisation::factory()->create();
        Domain::factory()->create(['organisation_id' => $organisation->id]);

        $report = $this->build($organisation);

        $this->assertSame([], $report['actions']);
        $this->assertSame('', $report['chartSvg']);
    }

    public function test_the_report_modal_links_to_the_management_pdf(): void
    {
        $admin = User::factory()->create();
        $organisation = Organisation::factory()->create();

        Volt::actingAs($admin)->test('admin.organisations')
            ->call('openReportModal', $organisation->id)
            ->assertSee(route('organisations.management-report-pdf', $organisation), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Organisation $organisation, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return app(OrganisationManagementReport::class)->build(
            $organisation,
            $from ?? now()->subDays(29)->startOfDay(),
            $to ?? now()->endOfDay(),
        );
    }

    private function addRecords(Domain $domain, Carbon $begin, int $pass, int $fail): void
    {
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => $begin]);

        if ($pass > 0) {
            AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => $pass]);
        }

        if ($fail > 0) {
            AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'count' => $fail, 'spf_result' => 'fail', 'dkim_result' => 'fail']);
        }
    }
}
