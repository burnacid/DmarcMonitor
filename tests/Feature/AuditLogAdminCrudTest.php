<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuditLogAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_domain_is_audited(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.domains')
            ->call('create')
            ->set('fqdn', 'new.example.com')
            ->call('save');

        $domain = Domain::firstWhere('fqdn', 'new.example.com');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'domain.created',
            'user_id' => $admin->id,
            'subject_type' => $domain->getMorphClass(),
            'subject_id' => $domain->id,
        ]);
    }

    public function test_updating_a_domain_is_audited_with_the_changed_fields(): void
    {
        $admin = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'old.example.com', 'is_active' => true]);

        Volt::actingAs($admin)->test('admin.domains')
            ->call('edit', $domain->id)
            ->set('is_active', false)
            ->call('save');

        $entry = AuditLog::where('action', 'domain.updated')->firstOrFail();
        $this->assertEquals(false, $entry->context['is_active']);
    }

    public function test_deleting_a_domain_is_audited(): void
    {
        $admin = User::factory()->create();
        $domain = Domain::factory()->create(['fqdn' => 'gone.example.com']);

        Volt::actingAs($admin)->test('admin.domains')->call('delete', $domain->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'domain.deleted',
            'user_id' => $admin->id,
        ]);
    }

    public function test_creating_a_user_is_audited(): void
    {
        $admin = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('create')
            ->set('name', 'New User')
            ->set('email', 'new-user@example.com')
            ->set('password', 'password123')
            ->call('save');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'user_id' => $admin->id,
        ]);
    }

    public function test_deleting_a_user_is_audited_without_leaking_the_password(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->viewer()->create();

        Volt::actingAs($admin)->test('admin.users')->call('delete', $target->id);

        $entry = AuditLog::where('action', 'user.deleted')->firstOrFail();
        $this->assertEquals($target->email, $entry->context['email']);
        $this->assertArrayNotHasKey('password', $entry->context);
    }
}
