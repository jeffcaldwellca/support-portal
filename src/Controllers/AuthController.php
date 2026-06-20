<?php
declare(strict_types=1);

namespace HelpdeskForm\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Psr\Log\LoggerInterface;
use HelpdeskForm\Services\LdapService;
use HelpdeskForm\Services\LocalAuthService;
use HelpdeskForm\Services\DatabaseService;

class AuthController
{
    /** Session lifetime in seconds (8 hours). Used for both the DB record and the cookie. */
    private const SESSION_LIFETIME = 3600 * 8;

    /** Max failed login attempts per identifier within the lockout window. */
    private const MAX_LOGIN_ATTEMPTS = 10;

    /** Lockout window in seconds (15 minutes). */
    private const LOGIN_LOCKOUT_WINDOW = 900;

    private Twig $twig;
    private LdapService $ldapService;
    private LocalAuthService $localAuthService;
    private DatabaseService $databaseService;
    private LoggerInterface $logger;
    private bool $enableLdap;
    private bool $enableLocalAuth;
    
    public function __construct(
        Twig $twig,
        LdapService $ldapService,
        LocalAuthService $localAuthService,
        DatabaseService $databaseService,
        LoggerInterface $logger
    ) {
        $this->twig = $twig;
        $this->ldapService = $ldapService;
        $this->localAuthService = $localAuthService;
        $this->databaseService = $databaseService;
        $this->logger = $logger;
        
        // Check which authentication methods are enabled
        $this->enableLdap = ($_ENV['ENABLE_LDAP_AUTH'] ?? 'true') === 'true';
        $this->enableLocalAuth = ($_ENV['ENABLE_LOCAL_AUTH'] ?? 'false') === 'true';
    }
    
    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // If authentication is disabled, redirect to main form
        if (($_ENV['DISABLE_AUTH'] ?? '') === 'true') {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }
        
        // Check if already logged in
        $sessionId = $this->getSessionId($request);
        if ($sessionId && $this->databaseService->getSession($sessionId)) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }
        
        $error = $request->getQueryParams()['error'] ?? null;
        
        return $this->twig->render($response, 'auth/login.html', [
            'error' => $error,
            'csrf_token' => $this->generateCsrfToken($request),
            'enable_ldap' => $this->enableLdap,
            'enable_local_auth' => $this->enableLocalAuth,
            'show_auth_method_selector' => $this->enableLdap && $this->enableLocalAuth
        ]);
    }
    
    public function processLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $authMethod = $data['auth_method'] ?? 'ldap'; // Default to LDAP for backward compatibility
        
        if (empty($username) || empty($password)) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=missing_credentials');
        }
        
        // Validate auth method is enabled
        if ($authMethod === 'ldap' && !$this->enableLdap) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=ldap_disabled');
        }

        if ($authMethod === 'local' && !$this->enableLocalAuth) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=local_auth_disabled');
        }

        // Throttle brute-force attempts (keyed on username + client IP).
        $attemptId = $this->loginAttemptIdentifier($username);
        if ($this->databaseService->countRecentLoginAttempts($attemptId, self::LOGIN_LOCKOUT_WINDOW) >= self::MAX_LOGIN_ATTEMPTS) {
            $this->logger->warning('Login blocked: too many attempts', ['username' => $username]);
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=too_many_attempts');
        }

        try {
            $userData = null;

            // Authenticate based on selected method
            if ($authMethod === 'local') {
                $userData = $this->localAuthService->authenticate($username, $password);
                $this->logger->info('Local authentication attempt', ['username' => $username, 'success' => $userData !== null]);
            } else {
                // LDAP authentication
                $userData = $this->ldapService->authenticate($username, $password);
                $this->logger->info('LDAP authentication attempt', ['username' => $username, 'success' => $userData !== null]);
            }
            
            if (!$userData) {
                $this->databaseService->recordLoginAttempt($attemptId);
                $this->logger->warning('Failed login attempt', ['username' => $username, 'method' => $authMethod]);
                return $response
                    ->withStatus(302)
                    ->withHeader('Location', '/auth/login?error=invalid_credentials');
            }

            // Successful login clears the throttle counter.
            $this->databaseService->clearLoginAttempts($attemptId);

            // Create session
            $sessionId = $this->generateSessionId();
            $this->databaseService->createSession($sessionId, $userData, self::SESSION_LIFETIME);

            $this->logger->info('User logged in', [
                'username' => $username,
                'email' => $userData['email'],
                'method' => $authMethod
            ]);

            // Set cookie and redirect
            $response = $response
                ->withStatus(302)
                ->withHeader('Location', '/')
                ->withHeader('Set-Cookie', $this->buildSessionCookie($sessionId, self::SESSION_LIFETIME, $request));

            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Login error', [
                'username' => $username,
                'method' => $authMethod,
                'error' => $e->getMessage()
            ]);
            
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=system_error');
        }
    }
    
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = $this->getSessionId($request);
        
        if ($sessionId) {
            $this->databaseService->deleteSession($sessionId);
            $this->logger->info('User logged out', ['session_id' => $sessionId]);
        }
        
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/auth/login')
            ->withHeader('Set-Cookie', $this->buildSessionCookie('', 0, $request));
    }
    
    public function showRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Only allow registration if local auth is enabled
        if (!$this->enableLocalAuth) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=registration_disabled');
        }
        
        // If authentication is disabled, redirect to main form
        if (($_ENV['DISABLE_AUTH'] ?? '') === 'true') {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }
        
        // Check if already logged in
        $sessionId = $this->getSessionId($request);
        if ($sessionId && $this->databaseService->getSession($sessionId)) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }
        
        $error = $request->getQueryParams()['error'] ?? null;
        $success = $request->getQueryParams()['success'] ?? null;
        
        return $this->twig->render($response, 'auth/register.html', [
            'error' => $error,
            'success' => $success,
            'csrf_token' => $this->generateCsrfToken($request)
        ]);
    }
    
    public function processRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Only allow registration if local auth is enabled
        if (!$this->enableLocalAuth) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/login?error=registration_disabled');
        }
        
        $data = $request->getParsedBody();
        
        // Validate required fields
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $name = trim($data['name'] ?? '');
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';
        $department = trim($data['department'] ?? '');
        $title = trim($data['title'] ?? '');
        
        // Store old values for form repopulation (except passwords)
        $oldValues = [
            'username' => $username,
            'email' => $email,
            'name' => $name,
            'department' => $department,
            'title' => $title
        ];
        
        if (empty($username) || empty($email) || empty($name) || empty($password)) {
            return $this->renderRegisterError($request, $response, 'missing_fields', $oldValues);
        }
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderRegisterError($request, $response, 'invalid_email', $oldValues);
        }
        
        // Validate password length
        if (strlen($password) < 8) {
            return $this->renderRegisterError($request, $response, 'password_too_short', $oldValues);
        }
        
        // Validate passwords match
        if ($password !== $passwordConfirm) {
            return $this->renderRegisterError($request, $response, 'password_mismatch', $oldValues);
        }
        
        try {
            // Create the user
            $userId = $this->localAuthService->createUser([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'department' => $department ?: null,
                'title' => $title ?: null
            ]);
            
            $this->logger->info('New local account created', [
                'user_id' => $userId,
                'username' => $username,
                'email' => $email
            ]);
            
            // Redirect to success page
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/auth/register?success=1');
            
        } catch (\RuntimeException $e) {
            // Handle specific errors from service
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, 'Username already exists') !== false) {
                return $this->renderRegisterError($request, $response, 'username_exists', $oldValues);
            } elseif (strpos($errorMessage, 'Email already exists') !== false) {
                return $this->renderRegisterError($request, $response, 'email_exists', $oldValues);
            }
            
            $this->logger->error('Registration error', [
                'username' => $username,
                'error' => $errorMessage
            ]);
            
            return $this->renderRegisterError($request, $response, 'system_error', $oldValues);
            
        } catch (\Exception $e) {
            $this->logger->error('Registration system error', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
            
            return $this->renderRegisterError($request, $response, 'system_error', $oldValues);
        }
    }
    
    private function renderRegisterError(ServerRequestInterface $request, ResponseInterface $response, string $error, array $oldValues = []): ResponseInterface
    {
        return $this->twig->render($response, 'auth/register.html', [
            'error' => $error,
            'old' => $oldValues,
            // Must match the token the ValidationMiddleware expects on resubmit,
            // not a fresh random value (which would fail CSRF validation).
            'csrf_token' => $this->generateCsrfToken($request)
        ]);
    }

    private function getSessionId(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        return $cookies['helpdesk_session'] ?? null;
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Build the session cookie string. Adds the Secure flag unless explicitly
     * disabled (COOKIE_SECURE=false) or the request is plain HTTP on localhost,
     * so the session cookie is not exposed over cleartext in production.
     */
    private function buildSessionCookie(string $sessionId, int $maxAge, ServerRequestInterface $request): string
    {
        $cookie = "helpdesk_session={$sessionId}; Path=/; HttpOnly; SameSite=Strict; Max-Age={$maxAge}";

        $cookieSecure = strtolower($_ENV['COOKIE_SECURE'] ?? 'true');
        $isHttps = strtolower($request->getUri()->getScheme()) === 'https';
        if ($cookieSecure !== 'false' && ($isHttps || $cookieSecure === 'true')) {
            $cookie .= '; Secure';
        }

        return $cookie;
    }

    /**
     * Stable per-(username, client IP) identifier used for login throttling.
     */
    private function loginAttemptIdentifier(string $username): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return hash('sha256', strtolower(trim($username)) . '|' . $ip);
    }

    private function generateCsrfToken(ServerRequestInterface $request): string
    {
        // For login/register pages (no session yet), use a temporary identifier
        // from the cookie or the client IP.
        $cookies = $request->getCookieParams();
        $tempId = $cookies['helpdesk_session'] ?? 'login-' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return hash_hmac('sha256', $tempId, $_ENV['CSRF_SECRET']);
    }
}
