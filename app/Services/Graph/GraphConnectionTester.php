<?php

namespace App\Services\Graph;

use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use RuntimeException;
use Throwable;

class GraphConnectionTester
{
    public function __construct(
        private readonly GraphTokenService $tokenService = new GraphTokenService,
        private readonly GraphFolderResolver $folderResolver = new GraphFolderResolver,
    ) {}

    /**
     * Verifies a collecting mailbox using only what polling itself needs
     * (Mail.ReadWrite): the configured inbox folder must be resolvable.
     * Looking up the user object instead would require User.Read.All,
     * which the app registration is not asked to have.
     */
    public function test(Microsoft365MailAccount $account): string
    {
        try {
            $token = $this->tokenService->getAccessTokenFor($account);
            $mailbox = rawurlencode($account->mailbox);

            try {
                $folderId = $this->folderResolver->resolve($mailbox, $token, $account->folder_inbox);
            } catch (RuntimeException $e) {
                $this->tokenService->forgetAccessTokenFor($account);

                return $e->getMessage();
            }

            if ($folderId === null) {
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
            $token = $this->tokenService->getAccessTokenFor($account);

            if (! in_array('Mail.Send', $this->tokenRoles($token), true)) {
                $this->tokenService->forgetAccessTokenFor($account);

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
