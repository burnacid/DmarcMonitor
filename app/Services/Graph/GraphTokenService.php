<?php

namespace App\Services\Graph;

use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GraphTokenService
{
    /**
     * Acquire an app-only (client credentials) Microsoft Graph access token,
     * cached until shortly before it expires so a poll/send cycle doesn't
     * request a fresh one on every call.
     */
    public function getAccessToken(string $tenantId, string $clientId, string $clientSecret): string
    {
        return Cache::remember($this->cacheKey($tenantId, $clientId, $clientSecret), now()->addMinutes(50), function () use ($tenantId, $clientId, $clientSecret) {
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Failed to acquire Microsoft Graph access token: '.$this->errorMessage($response));
            }

            return $response->json('access_token');
        });
    }

    /**
     * Acquire a token for a Microsoft 365 account, using its own app
     * registration or the shared "Connect with Microsoft" app.
     */
    public function getAccessTokenFor(Microsoft365MailAccount|Microsoft365SendAccount $account): string
    {
        $credentials = $account->graphCredentials();

        return $this->getAccessToken($credentials['tenant_id'], $credentials['client_id'], $credentials['client_secret']);
    }

    /**
     * Drop an account's cached token, e.g. after a failed test, so a token
     * issued before admin consent finished propagating isn't reused.
     */
    public function forgetAccessTokenFor(Microsoft365MailAccount|Microsoft365SendAccount $account): void
    {
        $credentials = $account->graphCredentials();

        Cache::forget($this->cacheKey($credentials['tenant_id'], $credentials['client_id'], $credentials['client_secret']));
    }

    private function cacheKey(string $tenantId, string $clientId, string $clientSecret): string
    {
        return 'microsoft365:token:'.md5($tenantId.'|'.$clientId.'|'.$clientSecret);
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('error_description') ?? $response->body();
    }
}
