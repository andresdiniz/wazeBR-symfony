<?php

declare(strict_types=1);

namespace App\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware para adicionar headers de segurança HTTP
 * 
 * Implementa as melhores práticas de segurança web:
 * - Content Security Policy (CSP)
 * - X-Frame-Options (Clickjacking protection)
 * - X-Content-Type-Options (MIME sniffing protection)
 * - Strict-Transport-Security (HSTS)
 * - Referrer-Policy
 * - Permissions-Policy
 */
class SecurityHeadersMiddleware implements HttpKernelInterface
{
    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
        private readonly string $environment,
    ) {}

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $response = $this->httpKernel->handle($request, $type, $catch);

        // Content Security Policy
        $csp = $this->buildCSP();
        $response->headers->set('Content-Security-Policy', $csp);

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable browser XSS protection
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy (formerly Feature Policy)
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()'
        );

        // HSTS (HTTP Strict Transport Security)
        if ('prod' === $this->environment) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    private function buildCSP(): string
    {
        $directives = [
            "default-src" => "'self'",
            "script-src" => "'self' 'unsafe-inline'", // Ideally remove unsafe-inline in production
            "style-src" => "'self' 'unsafe-inline'",
            "img-src" => "'self' data: https:",
            "font-src" => "'self' data:",
            "connect-src" => "'self'",
            "frame-ancestors" => "'none'",
            "base-uri" => "'self'",
            "form-action" => "'self'",
            "upgrade-insecure-requests" => "",
        ];

        $cspString = '';
        foreach ($directives as $directive => $sources) {
            if ($sources === '') {
                $cspString .= "$directive; ";
            } else {
                $cspString .= "$directive $sources; ";
            }
        }

        return trim($cspString);
    }
}
