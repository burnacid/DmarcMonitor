<?php

namespace Tests\Feature;

use App\Models\DashboardAllowedIp;
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
