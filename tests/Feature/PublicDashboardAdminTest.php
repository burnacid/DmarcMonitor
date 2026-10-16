<?php

namespace Tests\Feature;

use App\Models\DashboardAllowedIp;
use App\Models\PublicDashboardSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PublicDashboardAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->editor()->create())->get(route('admin.public-dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('admin.public-dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.public-dashboard'))->assertOk();
    }

    public function test_it_warns_when_any_proxy_is_trusted_while_an_allowlist_is_in_use(): void
    {
        $admin = User::factory()->create();
        DashboardAllowedIp::factory()->create(['address' => '203.0.113.7']);

        config(['app.trusted_proxies' => '10.0.0.2']);
        Volt::actingAs($admin)->test('admin.public-dashboard')->assertDontSee('TRUSTED_PROXIES is set to');

        config(['app.trusted_proxies' => '*']);
        Volt::actingAs($admin)->test('admin.public-dashboard')->assertSee('TRUSTED_PROXIES is set to');
    }

    public function test_admin_can_enable_and_disable_the_dashboard(): void
    {
        $admin = User::factory()->create();

        $this->assertFalse(PublicDashboardSetting::isEnabled());

        Volt::actingAs($admin)->test('admin.public-dashboard')->call('toggleEnabled');

        $this->assertTrue(PublicDashboardSetting::isEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'public_dashboard.enabled', 'user_id' => $admin->id]);

        Volt::actingAs($admin)->test('admin.public-dashboard')->call('toggleEnabled');

        $this->assertFalse(PublicDashboardSetting::isEnabled());
        $this->assertSame(1, PublicDashboardSetting::count());
    }

    public function test_admin_can_toggle_auto_scrolling(): void
    {
        $admin = User::factory()->create();

        $this->assertTrue(PublicDashboardSetting::autoScrolls());

        Volt::actingAs($admin)->test('admin.public-dashboard')->call('toggleAutoScroll');

        $this->assertFalse(PublicDashboardSetting::autoScrolls());
        $this->assertDatabaseHas('audit_logs', ['action' => 'public_dashboard.auto_scroll_disabled', 'user_id' => $admin->id]);

        Volt::actingAs($admin)->test('admin.public-dashboard')->call('toggleAutoScroll');

        $this->assertTrue(PublicDashboardSetting::autoScrolls());
        $this->assertFalse(PublicDashboardSetting::isEnabled());
        $this->assertSame(1, PublicDashboardSetting::count());
    }

    public function test_admin_can_add_and_remove_addresses(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.public-dashboard')
            ->call('create')
            ->set('address', ' 2001:db8::/32 ')
            ->set('description', 'Office wall screen')
            ->call('save')
            ->assertHasNoErrors();

        $allowedIp = DashboardAllowedIp::firstWhere('address', '2001:db8::/32');
        $this->assertNotNull($allowedIp);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dashboard_ip.created', 'user_id' => $admin->id]);

        Volt::actingAs($admin)->test('admin.public-dashboard')->call('delete', $allowedIp->id);

        $this->assertModelMissing($allowedIp);
    }

    public function test_invalid_and_duplicate_addresses_are_rejected(): void
    {
        $admin = User::factory()->create();
        DashboardAllowedIp::factory()->create(['address' => '192.0.2.10']);

        foreach (['not-an-ip', '192.0.2.0/33', '192.0.2.0/', '192.0.2.10'] as $address) {
            Volt::actingAs($admin)->test('admin.public-dashboard')
                ->call('create')
                ->set('address', $address)
                ->call('save')
                ->assertHasErrors('address');
        }

        $this->assertSame(1, DashboardAllowedIp::count());
    }
}
