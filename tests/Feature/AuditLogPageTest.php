<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_editors_cannot_reach_the_audit_log_page(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get('/admin/audit-log')->assertForbidden();
    }

    public function test_admins_can_reach_the_audit_log_page(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin/audit-log')->assertOk();
    }

    public function test_it_lists_entries(): void
    {
        $admin = User::factory()->create();
        AuditLog::create(['action' => 'domain.created', 'description' => 'Created domain example.com']);

        Volt::actingAs($admin)->test('admin.audit-log')
            ->assertSee('Created domain example.com');
    }

    public function test_unscoped_admin_sees_entries_from_every_organisation_and_org_agnostic_ones(): void
    {
        $admin = User::factory()->create();
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();

        AuditLog::create(['action' => 'domain.created', 'description' => 'Org A entry', 'organisation_id' => $orgA->id]);
        AuditLog::create(['action' => 'domain.created', 'description' => 'Org B entry', 'organisation_id' => $orgB->id]);
        AuditLog::create(['action' => 'auth.login', 'description' => 'System entry']);

        Volt::actingAs($admin)->test('admin.audit-log')
            ->assertSee('Org A entry')
            ->assertSee('Org B entry')
            ->assertSee('System entry');
    }

    public function test_scoped_admin_only_sees_their_organisations_and_org_agnostic_entries(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        $admin = User::factory()->create();
        $admin->organisations()->attach($orgA->id);

        AuditLog::create(['action' => 'domain.created', 'description' => 'Org A entry', 'organisation_id' => $orgA->id]);
        AuditLog::create(['action' => 'domain.created', 'description' => 'Org B entry', 'organisation_id' => $orgB->id]);
        AuditLog::create(['action' => 'auth.login', 'description' => 'System entry']);

        Volt::actingAs($admin)->test('admin.audit-log')
            ->assertSee('Org A entry')
            ->assertDontSee('Org B entry')
            ->assertSee('System entry');
    }

    public function test_category_filter_narrows_results(): void
    {
        $admin = User::factory()->create();
        AuditLog::create(['action' => 'domain.created', 'description' => 'Domain entry']);
        AuditLog::create(['action' => 'auth.login', 'description' => 'Login entry']);

        Volt::actingAs($admin)->test('admin.audit-log')
            ->set('category', 'auth')
            ->assertSee('Login entry')
            ->assertDontSee('Domain entry');
    }
}
