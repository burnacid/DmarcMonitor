<?php

namespace App\Services\Graph;

use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use Illuminate\Support\Facades\Http;
use Throwable;

class GraphConnectionTester
{
    public function __construct(private readonly GraphTokenService $tokenService = new GraphTokenService) {}

    /**
     * Verifies a collecting mailbox using only what polling itself needs
     * (Mail.ReadWrite): the configured inbox folder must be resolvable.
     * Looking up the user object instead would require User.Read.All,
     * which the app registration is not asked to have.
     */
    public function test(Microsoft365MailAccount $account): string
    {
        try {
            $token = $this->tokenService->getAccessToken($account->tenant_id, $account->client_id, $account->client_secret);
            $mailbox = rawurlencode($account->mailbox);

            $response = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders", [
                '$filter' => "displayName eq '".str_replace("'", "''", $account->folder_inbox)."'",
                '$select' => 'id,displayName',
            ]);

            if ($response->failed()) {
                return $response->json('error.message') ?? $response->body();
            }

            if ($response->json('value.0.id') === null) {
                return "Inbox folder [{$account->folder_inbox}] not found.";
            }

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Verifies a sending account. Mail.Send grants no read access, so there is
     * no mailbox endpoint to probe; instead, confirm the credentials work and
     * that admin consent for Mail.Send is present in the token's roles claim.
     * Use "Send test email" to verify delivery from the mailbox itself.
     */
    public function testSendAccount(Microsoft365SendAccount $account): string
    {
        try {
            $token = $this->tokenService->getAccessToken($account->tenant_id, $account->client_id, $account->client_secret);

            if (! in_array('Mail.Send', $this->tokenRoles($token), true)) {
                return 'The app registration has no admin-consented Mail.Send application permission.';
            }

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<int, string>
     */
    private function tokenRoles(string $token): array
    {
        $payload = explode('.', $token)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims['roles'] ?? null) ? $claims['roles'] : [];
    }
}
