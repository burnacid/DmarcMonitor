<?php

namespace Tests\Feature;

use App\Models\Microsoft365SendAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Microsoft365SendAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_sending_account(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.microsoft365-send-account')
            ->set('label', 'Primary sender')
            ->set('tenant_id', 'tenant-123')
            ->set('client_id', 'client-123')
            ->set('client_secret', 'super-secret')
            ->set('mailbox', 'notifications@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('microsoft365_send_accounts', [
            'label' => 'Primary sender',
            'mailbox' => 'notifications@example.com',
        ]);
    }

    public function test_it_requires_a_client_secret_when_creating(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('admin.microsoft365-send-account')
            ->set('label', 'Primary sender')
            ->set('tenant_id', 'tenant-123')
            ->set('client_id', 'client-123')
            ->set('mailbox', 'notifications@example.com')
            ->call('save')
            ->assertHasErrors(['client_secret']);
    }

    public function test_it_can_edit_without_re_entering_the_secret(): void
    {
        $user = User::factory()->create();
        $account = Microsoft365SendAccount::factory()->create(['label' => 'Old label']);

        Volt::actingAs($user)->test('admin.microsoft365-send-account')
            ->call('edit', $account->id)
            ->set('label', 'New label')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New label', $account->fresh()->label);
    }

    public function test_it_deletes_an_account(): void
    {
        $user = User::factory()->create();
        $account = Microsoft365SendAccount::factory()->create();

        Volt::actingAs($user)->test('admin.microsoft365-send-account')
            ->call('delete', $account->id);

        $this->assertDatabaseMissing('microsoft365_send_accounts', ['id' => $account->id]);
    }
}
