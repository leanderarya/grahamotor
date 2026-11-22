<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        Vite::useCspNonce();

        $response = $next($request);
        $nonce = Vite::cspNonce();
        $isAdminPanel = $request->is('admin', 'admin/*');

        $scriptSources = ["'self'"];
        $styleSources = ["'self'", 'https://fonts.bunny.net'];
        $styleElementSources = ["'self'"];
        $connectSources = ["'self'"];

        if ($isAdminPanel) {
            // Filament v3's Livewire/Alpine runtime uses eval and vendor inline scripts.
            $scriptSources[] = "'unsafe-inline'";
            $scriptSources[] = "'unsafe-eval'";
            $scriptSources[] = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
            $styleSources[] = "'unsafe-inline'";
            $styleElementSources[] = "'unsafe-inline'";
        } else {
            $scriptSources[] = "'nonce-{$nonce}'";
            $styleElementSources[] = "'nonce-{$nonce}'";
            $styleElementSources[] = "'sha256-JPiV+zbElb6F8GfxmDt1fq5wNR89mwpjxdpPeFaGQ1M='";
            $styleElementSources[] = "'sha256-j7MDc5w84jijhNNXv1VRK3APJe7+vzJgvb28436L3ec='";
        }

        if (config('app.env') === 'local') {
            $viteOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173'];
            $scriptSources = [...$scriptSources, ...$viteOrigins];
            $styleElementSources = [...$styleElementSources, ...$viteOrigins];
            $connectSources = [
                ...$connectSources,
                'ws://localhost:5173',
                'ws://127.0.0.1:5173',
            ];
        }

        $csp = [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            'style-src-elem '.implode(' ', [...$styleElementSources, 'https://fonts.bunny.net']),
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' https://fonts.bunny.net",
            'connect-src '.implode(' ', $connectSources),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
