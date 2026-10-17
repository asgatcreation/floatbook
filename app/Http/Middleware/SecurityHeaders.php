<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that tell the browser how to treat FloatBook: don't let another site frame it,
 * don't guess file types, don't leak the page address to other sites, and only load
 * scripts, styles and images from us.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Nothing here needs a camera, microphone or location.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Not while developing: assets then come from Vite's dev server, whose address
        // (an IPv6 literal) a policy cannot express, and a local policy protects nobody.
        if (! app()->isLocal()) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    /**
     * Everything is served from our own domain: the fonts are bundled, so no outside
     * origin is needed. `data:` covers the inline SVG icons and the manifest's images.
     */
    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            // Tailwind writes a few inline styles at runtime.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
