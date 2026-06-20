<?php
declare(strict_types=1);

namespace HelpdeskForm\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;

class ValidationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Add CSRF token validation for POST requests
        if ($request->getMethod() === 'POST') {
            // Skip CSRF validation only for entry points that cannot carry a
            // session-bound token yet. State-changing API endpoints are NOT
            // exempt — the front-end already submits the token with them.
            $uri = $request->getUri()->getPath();
            $skipCsrfRoutes = [
                '/auth/login',
                '/auth/logout',
            ];

            if (!in_array($uri, $skipCsrfRoutes)) {
                $this->validateCsrfToken($request);
            }
        }

        // NOTE: Input is intentionally NOT HTML-escaped here. Escaping at the
        // input layer mangles passwords and causes cumulative double-encoding of
        // stored data. Output escaping is handled where it belongs: Twig
        // auto-escapes on render, and FreeScoutService escapes ticket bodies.
        return $handler->handle($request);
    }
    
    private function validateCsrfToken(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();
        $headers = $request->getHeaders();

        // Get CSRF token from form data or header
        $token = (is_array($body) ? ($body['csrf_token'] ?? null) : null)
            ?? $headers['X-CSRF-Token'][0]
            ?? null;

        // Throw HttpBadRequestException so the error middleware returns a clean
        // 400 response rather than leaking an uncaught exception/stack trace.
        if (!$token) {
            throw new HttpBadRequestException($request, 'Missing CSRF token.');
        }

        if (!$this->isValidCsrfToken($token, $request)) {
            throw new HttpBadRequestException($request, 'Invalid CSRF token.');
        }
    }
    
    private function isValidCsrfToken(string $token, ServerRequestInterface $request): bool
    {
        // Get session ID from cookie (since AuthMiddleware hasn't run yet)
        $cookies = $request->getCookieParams();
        $sessionId = $cookies['helpdesk_session'] ?? null;
        
        // If no session cookie, check Authorization header
        if (!$sessionId) {
            $authHeader = $request->getHeaderLine('Authorization');
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $sessionId = $matches[1];
            }
        }
        
        // Fallback for development mode or login
        if (!$sessionId) {
            // Check if auth is disabled
            if (($_ENV['DISABLE_AUTH'] ?? '') === 'true') {
                $sessionId = 'dev-session-' . date('Y-m-d');
            } else {
                // For login page or initial requests
                $sessionId = 'login-' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            }
        }

        // Generate expected token using the same method as controllers.
        // CSRF_SECRET is validated at bootstrap (public/index.php), so it is
        // always present and non-default here.
        $expectedToken = hash_hmac('sha256', $sessionId, $_ENV['CSRF_SECRET']);
        return hash_equals($expectedToken, $token);
    }
}
