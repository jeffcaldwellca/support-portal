<?php
declare(strict_types=1);

namespace HelpdeskForm\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use HelpdeskForm\Services\DatabaseService;

class AuthMiddleware implements MiddlewareInterface
{
    private ContainerInterface $container;
    
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if authentication is disabled via environment variable
        if ($_ENV['DISABLE_AUTH'] === 'true') {
            // Create a mock user session for disabled auth mode
            $request = $request->withAttribute('user', [
                'username' => 'dev-user',
                'email' => 'admin@localhost',
                'name' => 'Development User',
                'department' => 'IT',
                'title' => 'Administrator'
            ]);
            // Add a mock session ID for development mode
            $request = $request->withAttribute('session_id', 'dev-session-' . date('Y-m-d'));
            return $handler->handle($request);
        }
        
        // Get the request path
        $path = $request->getUri()->getPath();
        
        // Skip authentication for specific paths
        $skipAuthPaths = [
            '/auth/login',
            '/auth/logout', 
            '/health',
        ];
        
        // Check if this is an exact match for skip paths
        if (in_array($path, $skipAuthPaths)) {
            return $handler->handle($request);
        }
        
        // Check if this is an assets path (starts with /assets/)
        if (strpos($path, '/assets/') === 0) {
            return $handler->handle($request);
        }
        
        // Check if this is an auth path (starts with /auth/)
        if (strpos($path, '/auth/') === 0) {
            return $handler->handle($request);
        }
        
        // Check for session
        $sessionId = $this->getSessionId($request);
        
        if (!$sessionId) {
            return $this->redirectToLogin();
        }
        
        // Validate session
        $databaseService = $this->container->get(DatabaseService::class);
        $session = $databaseService->getSession($sessionId);
        
        if (!$session) {
            return $this->redirectToLogin();
        }
        
        // Add user data to request attributes
        $request = $request->withAttribute('user', $session['user_data']);
        $request = $request->withAttribute('session_id', $sessionId);
        
        return $handler->handle($request);
    }
    
    private function getSessionId(ServerRequestInterface $request): ?string
    {
        // Check for session cookie
        $cookies = $request->getCookieParams();
        if (isset($cookies['helpdesk_session'])) {
            return $cookies['helpdesk_session'];
        }
        
        // Check for Authorization header (for API requests)
        $authHeader = $request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    private function redirectToLogin(): ResponseInterface
    {
        $response = new Response();
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/auth/login');
    }
}
