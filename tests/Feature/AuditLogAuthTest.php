<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuditLogAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->id,
        ]);
    }

    public function test_a_failed_login_is_audited(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.failed',
        ]);

        $entry = AuditLog::where('action', 'auth.failed')->firstOrFail();
        $this->assertEquals($user->email, $entry->context['email']);
    }

    public function test_a_logout_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('layout.navigation')->call('logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'user_id' => $user->id,
        ]);
    }
}
