<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\AlertEvent;
use App\Models\DashboardAllowedIp;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\PublicDashboardSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PublicDashboardSetting::factory()->create();
    }

    private function getWall(string $ip = '203.0.113.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(route('public.overview'));
    }

    public function test_nobody_can_open_it_while_it_is_disabled(): void
    {
        PublicDashboardSetting::setEnabled(false);
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        $this->getWall()->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('public.overview'))->assertNotFound();
    }

    public function test_a_spoofed_forwarded_for_header_does_not_pass_the_allowlist_without_trusted_proxies(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get(route('public.overview'))
            ->assertForbidden();
    }

    public function test_it_is_disabled_until_an_admin_enables_it(): void
    {
        PublicDashboardSetting::query()->delete();

        $this->actingAs(User::factory()->create())->get(route('public.overview'))->assertNotFound();
    }

    public function test_problem_domains_are_listed_and_all_good_organisations_are_collapsed(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);
        $broken = Organisation::factory()->create(['name' => 'Broken BV']);
        $healthy = Organisation::factory()->create(['name' => 'Healthy Ltd']);
        Domain::factory()->create(['organisation_id' => $broken->id, 'fqdn' => 'no-dmarc.test', 'dmarc_status' => 'missing']);
        Domain::factory()->create(['organisation_id' => $broken->id, 'fqdn' => 'fine-sibling.test', 'created_at' => now()]);
        Domain::factory()->create(['organisation_id' => $healthy->id, 'fqdn' => 'healthy-domain.test', 'created_at' => now()]);

        $this->getWall()
            ->assertOk()
            ->assertSee('Broken BV')
            ->assertSee('no-dmarc.test')
            ->assertDontSee('fine-sibling.test')
            ->assertSee('Healthy Ltd')
            ->assertDontSee('healthy-domain.test');
    }

    public function test_inactive_domains_and_their_alerts_are_left_out(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);
        $active = Domain::factory()->create(['fqdn' => 'active-alerting.test']);
        $inactive = Domain::factory()->create(['fqdn' => 'not-live-yet.test', 'is_active' => false, 'dmarc_status' => 'missing']);
        AlertEvent::factory()->create(['domain_id' => $active->id]);
        AlertEvent::factory()->create(['domain_id' => $inactive->id]);
        AlertEvent::factory()->resolved()->create(['domain_id' => $active->id]);

        $response = $this->getWall()
            ->assertOk()
            ->assertSee('active-alerting.test')
            ->assertDontSee('not-live-yet.test');

        $this->assertSame(1, $response->viewData('alertCount'));
        $this->assertSame(1, $response->viewData('totals')['domains']);
    }

    public function test_top_failing_sources_are_shown_for_active_domains_only(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        foreach (['198.51.100.1' => true, '198.51.100.2' => false] as $sourceIp => $isActive) {
            $domain = Domain::factory()->create(['is_active' => $isActive]);
            $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_begin' => now()]);
            AggregateReportRecord::factory()->create([
                'aggregate_report_id' => $report->id,
                'source_ip' => $sourceIp,
                'count' => 20,
                'spf_result' => 'fail',
                'dkim_result' => 'fail',
            ]);
        }

        $sources = $this->getWall()->assertOk()->viewData('failingSources');

        $this->assertSame(['198.51.100.1'], $sources->pluck('ips')->flatten(1)->pluck('source_ip')->all());
    }

    public function test_the_updated_time_carries_its_timezone_for_the_browser_to_convert(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 14:05:00', 'UTC'));
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        $this->getWall()->assertOk()->assertSee('<time id="updated-at" datetime="2026-10-13T14:05:00+00:00">14:05</time>', false);
    }

    public function test_the_organisations_panel_only_auto_scrolls_while_enabled(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        $this->getWall()->assertOk()->assertSee('id="organisations-scroll"', false);

        PublicDashboardSetting::setAutoScroll(false);

        $this->getWall()->assertOk()->assertDontSee('id="organisations-scroll"', false);
    }

    public function test_it_uses_the_configured_default_range_unless_the_address_picks_one(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);
        PublicDashboardSetting::setDefaultDays(7);

        $this->getWall()->assertOk()->assertViewHas('days', 7);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get(route('public.overview', ['days' => 90]))
            ->assertOk()
            ->assertViewHas('days', 90);
    }

    public function test_cidr_ranges_are_matched(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '198.51.100.0/24']);

        $this->getWall('198.51.100.200')->assertOk();
    }

    public function test_ips_not_on_the_list_are_forbidden(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '198.51.100.0/24']);

        $this->getWall('203.0.113.7')->assertForbidden();
    }

    public function test_guests_are_forbidden_while_the_list_is_empty(): void
    {
        $this->getWall('127.0.0.1')->assertForbidden();
    }

    public function test_logged_in_users_can_open_it_from_any_ip_limited_to_their_organisations(): void
    {
        $own = Organisation::factory()->create(['name' => 'Own Org']);
        $other = Organisation::factory()->create(['name' => 'Other Org']);
        Domain::factory()->create(['organisation_id' => $own->id, 'fqdn' => 'own-broken.test', 'dmarc_status' => 'missing']);
        $otherDomain = Domain::factory()->create(['organisation_id' => $other->id, 'fqdn' => 'other-broken.test', 'dmarc_status' => 'missing']);
        AlertEvent::factory()->create(['domain_id' => $otherDomain->id]);
        $viewer = User::factory()->viewer()->create();
        $viewer->update(['all_organisations' => false]);
        $viewer->organisations()->attach($own->id);

        $response = $this->actingAs($viewer)->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get(route('public.overview'))
            ->assertOk()
            ->assertSee('own-broken.test')
            ->assertDontSee('Other Org')
            ->assertDontSee('other-broken.test');

        $this->assertSame(0, $response->viewData('alertCount'));
    }
}
