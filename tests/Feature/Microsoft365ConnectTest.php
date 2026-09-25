<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use App\Models\User;
use App\Services\Graph\GraphIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class Microsoft365ConnectTest extends TestCase
{
    use RefreshDatabase;

    private const string TENANT_ID = '0b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft365.client_id' => 'shared-client-id',
            'services.microsoft365.client_secret' => 'shared-client-secret',
        ]);
    }

    public function test_connect_redirects_to_microsoft_admin_consent_with_a_state(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('admin.microsoft365.connect', 'mailbox'));

        $location = $response->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/v2.0/adminconsent?', $location);
        $this->assertSame('shared-client-id', $query['client_id']);
        $this->assertSame(route('admin.microsoft365.callback'), $query['redirect_uri']);
        $this->assertSame(session('microsoft365_connect.state'), $query['state']);
        $this->assertSame('mailbox', session('microsoft365_connect.target'));
    }

    public function test_connect_explains_when_the_shared_app_is_not_configured(): void
    {
        config(['services.microsoft365.client_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.microsoft365.connect', 'sending'))
            ->assertRedirect(route('admin.microsoft365-send-account'))
            ->assertSessionHas('microsoft365_connect_error');
    }

    public function test_only_admins_can_connect(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get(route('admin.microsoft365.connect', 'mailbox'))
            ->assertForbidden();
    }

    public function test_the_callback_hands_the_consented_tenant_to_the_requesting_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['microsoft365_connect' => ['state' => 'expected-state', 'target' => 'sending']])
            ->get(route('admin.microsoft365.callback', ['tenant' => self::TENANT_ID, 'admin_consent' => 'True', 'state' => 'expected-state']))
            ->assertRedirect(route('admin.microsoft365-send-account'))
            ->assertSessionHas('microsoft365_connected_tenant', self::TENANT_ID);

        $this->assertTrue(AuditLog::where('action', 'microsoft365.tenant_connected')->exists());
    }

    public function test_the_callback_rejects_a_mismatched_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['microsoft365_connect' => ['state' => 'expected-state', 'target' => 'mailbox']])
            ->get(route('admin.microsoft365.callback', ['tenant' => self::TENANT_ID, 'admin_consent' => 'True', 'state' => 'forged-state']))
            ->assertRedirect(route('admin.microsoft365-mail-accounts'))
            ->assertSessionHas('microsoft365_connect_error')
            ->assertSessionMissing('microsoft365_connected_tenant');
    }

    public function test_the_callback_reports_a_declined_consent(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['microsoft365_connect' => ['state' => 'expected-state', 'target' => 'mailbox']])
            ->get(route('admin.microsoft365.callback', ['error' => 'access_denied', 'error_description' => 'AADSTS65004: User declined to consent.', 'state' => 'expected-state']))
            ->assertRedirect(route('admin.microsoft365-mail-accounts'))
            ->assertSessionHas('microsoft365_connect_error', fn (string $message) => str_contains($message, 'Global Administrator'))
            ->assertSessionMissing('microsoft365_connected_tenant');
    }

    public function test_after_connecting_the_form_opens_prefilled_and_saves_without_app_credentials(): void
    {
        session()->flash('microsoft365_connected_tenant', self::TENANT_ID);

        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-mail-accounts')
            ->assertSet('tenant_id', self::TENANT_ID)
            ->assertSet('use_shared_app', true)
            ->set('label', 'Shared DMARC mailbox')
            ->set('mailbox', 'dmarc@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $account = Microsoft365MailAccount::firstWhere('mailbox', 'dmarc@example.com');
        $this->assertSame(self::TENANT_ID, $account->tenant_id);
        $this->assertNull($account->client_id);
        $this->assertNull($account->client_secret);
    }

    public function test_switching_a_shared_app_account_to_its_own_app_requires_a_secret(): void
    {
        $account = Microsoft365SendAccount::factory()->sharedApp()->create();

        Volt::actingAs(User::factory()->create())->test('admin.microsoft365-send-account')
            ->call('edit', $account->id)
            ->assertSet('use_shared_app', true)
            ->set('use_shared_app', false)
            ->set('client_id', 'own-client-id')
            ->call('save')
            ->assertHasErrors(['client_secret'])
            ->set('client_secret', 'own-secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('own-client-id', $account->fresh()->client_id);
        $this->assertFalse($account->fresh()->usesSharedApp());
    }

    public function test_shared_app_accounts_authenticate_with_the_shared_app_credentials(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);

        $account = Microsoft365MailAccount::factory()->sharedApp()->create(['tenant_id' => self::TENANT_ID]);

        (new GraphIngestionService)->pollAccount($account);

        Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/'.self::TENANT_ID.'/oauth2/v2.0/token'
            && $request['client_id'] === 'shared-client-id'
            && $request['client_secret'] === 'shared-client-secret');
    }

    public function test_a_shared_app_account_fails_clearly_when_the_shared_app_is_not_configured(): void
    {
        config(['services.microsoft365.client_secret' => null]);

        $account = Microsoft365SendAccount::factory()->sharedApp()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MICROSOFT365_CLIENT_ID');

        $account->graphCredentials();
    }
}
