<?php

namespace Tests\Feature;

use App\Models\Microsoft365MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Microsoft365MailAccountFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithUnreachableTenant(): Microsoft365MailAccount
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], 401),
        ]);

        return Microsoft365MailAccount::factory()->create();
    }

    public function test_polling_an_account_with_bad_credentials_records_the_error_without_crashing(): void
    {
        $account = $this->accountWithUnreachableTenant();

        $this->artisan('graph-mail:poll', ['account' => $account->id])
            ->assertSuccessful();

        $account->refresh();
        $this->assertNotNull($account->last_error);
        $this->assertNotNull($account->last_polled_at);
    }

    public function test_test_connection_button_surfaces_the_failure_in_the_ui(): void
    {
        $user = User::factory()->create();
        $account = $this->accountWithUnreachableTenant();

        Volt::actingAs($user)->test('admin.microsoft365-mail-accounts')
            ->call('testConnection', $account->id)
            ->assertSee('Connection failed');
    }

    public function test_fetch_now_button_surfaces_a_summary_without_crashing(): void
    {
        $user = User::factory()->create();
        $account = $this->accountWithUnreachableTenant();

        Volt::actingAs($user)->test('admin.microsoft365-mail-accounts')
            ->call('fetchNow', $account->id)
            ->assertOk();

        $account->refresh();
        $this->assertNotNull($account->last_error);
    }
}
