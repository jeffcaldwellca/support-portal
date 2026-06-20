<?php
declare(strict_types=1);

namespace HelpdeskForm\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security-related response headers.
 *
 * This replaces the previous permissive CORS middleware (Allow-Origin: * with
 * credentials, which is both invalid and unnecessary for this same-origin,
 * server-rendered app). It also ensures headers are present under the PHP
 * built-in server, which does not read the Apache .htaccess config.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Content-Security-Policy. 'unsafe-inline' is required because the
        // templates use inline <script> blocks and inline event handlers
        // (e.g. onerror). It still forbids object/base hijacking and framing,
        // and constrains where scripts/styles may load from.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "font-src 'self' https://cdn.jsdelivr.net",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        return $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-XSS-Protection', '0');
    }
}
