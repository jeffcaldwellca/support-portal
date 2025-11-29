<?php
declare(strict_types=1);

namespace HelpdeskForm\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ValidationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Add CSRF token validation for POST requests
        if ($request->getMethod() === 'POST') {
            // Skip CSRF validation for certain routes
            $uri = $request->getUri()->getPath();
            $skipCsrfRoutes = [
                '/auth/login', 
                '/auth/logout', 
                '/api/autosave', 
                '/api/upload',
                '/api/validate'
            ];
            
            if (!in_array($uri, $skipCsrfRoutes)) {
                $this->validateCsrfToken($request);
            }
        }
        
        // Sanitize input data
        $request = $this->sanitizeRequest($request);
        
        return $handler->handle($request);
    }
    
    private function validateCsrfToken(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();
        $headers = $request->getHeaders();
        $uri = $request->getUri()->getPath();
        
        // Get CSRF token from form data or header
        $token = $body['csrf_token'] ?? $headers['X-CSRF-Token'][0] ?? null;
        
        if (!$token) {
            throw new \RuntimeException("CSRF token missing for {$request->getMethod()} request to {$uri}");
        }
        
        // Validate token (implement your CSRF validation logic here)
        if (!$this->isValidCsrfToken($token, $request)) {
            throw new \RuntimeException("Invalid CSRF token for {$request->getMethod()} request to {$uri}");
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
            if ($_ENV['DISABLE_AUTH'] === 'true') {
                $sessionId = 'dev-session-' . date('Y-m-d');
            } else {
                // For login page or initial requests
                $sessionId = 'login-' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            }
        }
        
        // Generate expected token using the same method as controllers
        $expectedToken = hash_hmac('sha256', $sessionId, $_ENV['CSRF_SECRET'] ?? 'default_secret');
        return hash_equals($expectedToken, $token);
    }
    
    private function sanitizeRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $body = $request->getParsedBody();
        
        if (is_array($body)) {
            $sanitized = $this->sanitizeArray($body);
            $request = $request->withParsedBody($sanitized);
        }
        
        return $request;
    }
    
    private function sanitizeArray(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                // Basic XSS protection
                $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
