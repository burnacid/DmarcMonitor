<?php

namespace App\Support;

/**
 * The installation-wide, multi-tenant Microsoft Entra app registration used by
 * "Connect with Microsoft". A tenant admin consents to it once, after which
 * accounts in that tenant authenticate with these credentials instead of an
 * app registration of their own.
 */
class Microsoft365App
{
    public static function isConfigured(): bool
    {
        return filled(static::clientId()) && filled(static::clientSecret());
    }

    public static function clientId(): ?string
    {
        return config('services.microsoft365.client_id');
    }

    public static function clientSecret(): ?string
    {
        return config('services.microsoft365.client_secret');
    }

    /**
     * The redirect URI that must be registered (as a "Web" platform URI) on the
     * app registration.
     */
    public static function redirectUri(): string
    {
        return route('admin.microsoft365.callback');
    }

    /**
     * Microsoft's admin consent endpoint: a tenant admin signs in, approves the
     * app's application permissions for their tenant, and is sent back to
     * redirectUri() with the tenant ID.
     */
    public static function adminConsentUrl(string $state): string
    {
        return 'https://login.microsoftonline.com/organizations/v2.0/adminconsent?'.http_build_query([
            'client_id' => static::clientId(),
            'scope' => 'https://graph.microsoft.com/.default',
            'redirect_uri' => static::redirectUri(),
            'state' => $state,
        ]);
    }
}
