<?php

namespace App\Services\Graph;

use App\Models\Microsoft365MailAccount;
use Illuminate\Support\Facades\Http;
use Throwable;

class GraphConnectionTester
{
    public function __construct(private readonly GraphTokenService $tokenService = new GraphTokenService) {}

    public function test(Microsoft365MailAccount $account): string
    {
        try {
            $token = $this->tokenService->getAccessToken($account->tenant_id, $account->client_id, $account->client_secret);
            $mailbox = rawurlencode($account->mailbox);

            $response = Http::withToken($token)->get("https://graph.microsoft.com/v1.0/users/{$mailbox}");

            if ($response->failed()) {
                return $response->json('error.message') ?? $response->body();
            }

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
