<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Set on every response. Kept in one place so the policy stays consistent
     * between the app and guest layouts and future pages don't need to opt in.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bound before the view renders so the one inline script the layouts
        // still need (the pre-paint dark-mode toggle) can carry a matching
        // nonce instead of forcing 'unsafe-inline' onto script-src.
        $nonce = base64_encode(random_bytes(16));
        app()->instance('csp-nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // `npm run dev`/`composer run dev` serves assets from Vite's own dev
        // server (a different origin, on a port that isn't fixed) rather than
        // the built, same-origin bundle `npm run build` produces — the two
        // are irreconcilable under one static policy, so the strict, nonce-
        // based CSP below only applies outside local development.
        if (! app()->environment('local')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * All app JS/CSS is bundled locally via Vite (Chart.js, Alpine/Livewire,
     * flag-icons) except the bunny.net font, and flag-icons ships its flag
     * glyphs as data-URI backgrounds inside its CSS — hence img-src data:.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
