<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class WebManifestController extends Controller
{
    /**
     * The web app manifest that makes the app installable. Served from a
     * route rather than a static file so it carries the configured app name.
     * Browsers fetch it without cookies, so it must not require a login.
     */
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'description' => __('DMARC, SPF and DKIM monitoring for your domains.'),
            'id' => '/',
            'start_url' => route('dashboard', absolute: false),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f3f4f6',
            'theme_color' => '#4f46e5',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json');
    }
}
