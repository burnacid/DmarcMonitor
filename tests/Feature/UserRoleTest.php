<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewers_cannot_access_admin_pages(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get('/admin/organisations')->assertForbidden();
        $this->actingAs($viewer)->get('/admin/alert-rules')->assertForbidden();
        $this->actingAs($viewer)->get('/admin/users')->assertForbidden();
    }

    public function test_viewers_can_access_dashboard_and_reports(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $this->actingAs($viewer)->get('/reports')->assertOk();
    }

    public function test_editors_can_access_admin_pages_but_not_user_management(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get('/admin/organisations')->assertOk();
        $this->actingAs($editor)->get('/admin/alert-rules')->assertOk();
        $this->actingAs($editor)->get('/admin/users')->assertForbidden();
    }

    public function test_admins_can_access_everything_including_user_management(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin/organisations')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_admin_can_promote_a_viewer_to_editor(): void
    {
        $admin = User::factory()->create();
        $viewer = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $viewer->id)
            ->set('role', 'editor')
            ->call('save');

        $this->assertEquals('editor', $viewer->fresh()->role);
    }

    public function test_admin_cannot_demote_the_last_remaining_admin(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $admin->id)
            ->set('role', 'viewer')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertEquals('admin', $admin->fresh()->role);
    }

    public function test_admin_can_demote_themself_when_another_admin_exists(): void
    {
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $admin->id)
            ->set('role', 'viewer')
            ->call('save');

        $this->assertEquals('viewer', $admin->fresh()->role);
    }

    public function test_admin_can_create_a_new_user(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('create')
            ->set('name', 'New Person')
            ->set('email', 'new-person@example.com')
            ->set('password', 'password123')
            ->set('role', 'editor')
            ->call('save');

        $user = User::firstWhere('email', 'new-person@example.com');
        $this->assertNotNull($user);
        $this->assertEquals('editor', $user->role);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('delete', $admin->id);

        $this->assertNotNull($admin->fresh());
    }

    public function test_admin_can_delete_another_user(): void
    {
        $admin = User::factory()->create();
        $viewer = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('delete', $viewer->id);

        $this->assertNull($viewer->fresh());
    }
}
