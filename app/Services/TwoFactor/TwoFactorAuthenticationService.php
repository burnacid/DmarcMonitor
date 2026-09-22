<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Wraps pragmarx/google2fa (the TOTP engine) and bacon/bacon-qr-code (the QR
 * renderer) — google2fa-laravel itself doesn't support Laravel 13 yet, and
 * its only job beyond these two libraries is a thin facade/middleware this
 * app doesn't need, since the setup/challenge UI is hand-built in Volt.
 */
class TwoFactorAuthenticationService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /**
     * Inline SVG QR code for the given secret, scannable by any TOTP
     * authenticator app. The manual-entry secret is shown alongside it in
     * the UI as a fallback, so no PNG/Imagick backend is needed.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->otpAuthUrl($user, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(192),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($url);

        // Strip the XML prolog bacon/qr-code prepends — invalid where this
        // is embedded directly into an HTML document via {!! !!}.
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }

    private function otpAuthUrl(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('app.name'));
        $label = rawurlencode("{$issuer}:{$user->email}");

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Ten single-use recovery codes for when the user's authenticator app
     * isn't available. Shown to the user exactly once, at confirmation time.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return Collection::times(10, fn () => Str::random(10).'-'.Str::random(10))->all();
    }
}
