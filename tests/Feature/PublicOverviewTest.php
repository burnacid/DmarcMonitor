<?php

namespace Tests\Feature;

use App\Models\DashboardAllowedIp;
use App\Models\Domain;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_from_an_allowed_ip_sees_all_organisations_and_domains(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);
        $organisation = Organisation::factory()->create(['name' => 'Acme']);
        Domain::factory()->create(['organisation_id' => $organisation->id, 'fqdn' => 'acme-mail.test']);
        Domain::factory()->create(['organisation_id' => null, 'fqdn' => 'loose.test']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get(route('public.overview'))
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('acme-mail.test')
            ->assertSee('Unassigned domains')
            ->assertSee('loose.test');
    }

    public function test_cidr_ranges_are_matched(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '198.51.100.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
            ->get(route('public.overview'))
            ->assertOk();
    }

    public function test_ips_not_on_the_list_are_forbidden(): void
    {
        DashboardAllowedIp::factory()->create(['address' => '198.51.100.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get(route('public.overview'))
            ->assertForbidden();
    }

    public function test_page_is_unavailable_while_the_list_is_empty(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get(route('public.overview'))
            ->assertForbidden();
    }
}
