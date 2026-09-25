<?php

namespace App\Models\Concerns;

use App\Support\Microsoft365App;
use RuntimeException;

/**
 * Resolves the app credentials a Microsoft 365 account authenticates with:
 * its own app registration when it has one, otherwise the shared app that the
 * tenant consented to through "Connect with Microsoft".
 *
 * @property string $tenant_id
 * @property ?string $client_id
 * @property ?string $client_secret
 */
trait HasMicrosoft365Credentials
{
    public function usesSharedApp(): bool
    {
        return blank($this->client_id);
    }

    /**
     * @return array{tenant_id: string, client_id: string, client_secret: string}
     */
    public function graphCredentials(): array
    {
        if (! $this->usesSharedApp()) {
            return [
                'tenant_id' => $this->tenant_id,
                'client_id' => (string) $this->client_id,
                'client_secret' => (string) $this->client_secret,
            ];
        }

        if (! Microsoft365App::isConfigured()) {
            throw new RuntimeException('This account uses "Connect with Microsoft", but MICROSOFT365_CLIENT_ID and MICROSOFT365_CLIENT_SECRET are not set.');
        }

        return [
            'tenant_id' => $this->tenant_id,
            'client_id' => (string) Microsoft365App::clientId(),
            'client_secret' => (string) Microsoft365App::clientSecret(),
        ];
    }
}
