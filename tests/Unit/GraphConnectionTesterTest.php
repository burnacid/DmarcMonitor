<?php

namespace Tests\Unit;

use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use App\Services\Graph\GraphConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mailbox_passes_with_only_mail_permissions(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->tokenWithRoles(['Mail.ReadWrite']), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/mailFolders*' => Http::response(['value' => [['id' => 'inbox-id', 'displayName' => 'Inbox']]]),
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Insufficient privileges to complete the operation.']], 403),
        ]);

        $account = Microsoft365MailAccount::factory()->create(['folder_inbox' => 'Inbox']);

        $this->assertSame('ok', (new GraphConnectionTester)->test($account));
    }

    public function test_a_mailbox_fails_when_the_inbox_folder_does_not_exist(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->tokenWithRoles(['Mail.ReadWrite']), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/mailFolders*' => Http::response(['value' => []]),
        ]);

        $account = Microsoft365MailAccount::factory()->create(['folder_inbox' => 'DMARC']);

        $this->assertSame('Inbox folder [DMARC] not found.', (new GraphConnectionTester)->test($account));
    }

    public function test_a_sending_account_passes_when_mail_send_is_consented(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->tokenWithRoles(['Mail.Send']), 'expires_in' => 3600]),
        ]);

        $account = Microsoft365SendAccount::factory()->create();

        $this->assertSame('ok', (new GraphConnectionTester)->testSendAccount($account));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
    }

    public function test_a_sending_account_fails_without_mail_send_consent(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->tokenWithRoles([]), 'expires_in' => 3600]),
        ]);

        $account = Microsoft365SendAccount::factory()->create();

        $this->assertStringContainsString('Mail.Send', (new GraphConnectionTester)->testSendAccount($account));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function tokenWithRoles(array $roles): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['roles' => $roles]).'.signature';
    }
}
