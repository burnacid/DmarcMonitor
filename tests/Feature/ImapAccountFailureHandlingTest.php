<?php

namespace Tests\Feature;

use App\Models\ImapAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ImapAccountFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function unreachableAccount(): ImapAccount
    {
        return ImapAccount::create([
            'label' => 'Unreachable mailbox',
            'host' => '127.0.0.1',
            'port' => 1, // nothing listens here
            'encryption' => 'none',
            'username' => 'nobody',
            'password' => 'nope',
            'is_active' => true,
        ]);
    }

    public function test_polling_an_unreachable_account_records_the_error_without_crashing(): void
    {
        $account = $this->unreachableAccount();

        $this->artisan('imap:poll', ['account' => $account->id])
            ->assertSuccessful();

        $account->refresh();
        $this->assertNotNull($account->last_error);
        $this->assertNotNull($account->last_polled_at);
    }

    public function test_test_connection_button_surfaces_the_failure_in_the_ui(): void
    {
        $user = User::factory()->create();
        $account = $this->unreachableAccount();

        Volt::actingAs($user)->test('admin.imap-accounts')
            ->call('testConnection', $account->id)
            ->assertSee('Connection failed');
    }

    public function test_fetch_now_button_surfaces_a_summary_without_crashing(): void
    {
        $user = User::factory()->create();
        $account = $this->unreachableAccount();

        Volt::actingAs($user)->test('admin.imap-accounts')
            ->call('fetchNow', $account->id)
            ->assertOk();

        $account->refresh();
        $this->assertNotNull($account->last_error);
    }
}
