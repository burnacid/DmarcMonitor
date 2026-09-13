<?php

namespace App\Services\Graph;

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
        $cacheKey = 'microsoft365:token:'.md5($tenantId.'|'.$clientId.'|'.$clientSecret);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($tenantId, $clientId, $clientSecret) {
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

    private function errorMessage(Response $response): string
    {
        return $response->json('error_description') ?? $response->body();
    }
}
