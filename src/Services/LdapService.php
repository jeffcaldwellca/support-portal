<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;

class LdapService
{
    private array $config;
    private ?Ldap $ldap = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build the connection options array for Symfony Ldap.
     */
    private function connectionConfig(): array
    {
        // Strip any protocol prefix; Symfony Ldap wants a bare host plus an
        // explicit encryption mode ('none' | 'ssl' | 'tls').
        $host = preg_replace('/^ldaps?:\/\//', '', (string) $this->config['host']);
        $encryption = $this->config['encryption'] ?? 'tls';

        return [
            'host' => $host,
            'port' => $this->config['port'],
            'encryption' => $encryption,
            'options' => [
                'network_timeout' => 10,   // seconds
                'timelimit' => 10,         // seconds
                'referrals' => false,
            ],
        ];
    }

    public function authenticate(string $username, string $password): ?array
    {
        try {
            $this->ldap = Ldap::create('ext_ldap', $this->connectionConfig());

            // Try anonymous bind first, or use service account if provided
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                // Bind with service account credentials for user search
                $this->ldap->bind($this->config['bind_dn'], $this->config['bind_password']);
            } else {
                // Try anonymous bind for user search
                $this->ldap->bind();
            }

            // Search for the user. The username is escaped to prevent LDAP
            // filter injection (e.g. "*)(uid=*").
            $safeUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
            $filter = sprintf($this->config['user_filter'], $safeUsername);
            $query = $this->ldap->query($this->config['base_dn'], $filter);
            $result = $query->execute();
            
            if (count($result) === 0) {
                return null; // User not found
            }
            
            $user = $result[0];
            $userDn = $user->getDn();
            
            // Try to bind with user's actual credentials
            try {
                $this->ldap->bind($userDn, $password);
            } catch (InvalidCredentialsException $e) {
                return null; // Invalid password
            }
            
            // Extract user information
            $attributes = $this->config['user_attributes'] ?? ['uid', 'cn', 'mail', 'displayName'];
            $defaultEmailDomain = $_ENV['LDAP_DEFAULT_EMAIL_DOMAIN'] ?? $_ENV['SUPPORT_EMAIL'] ?? 'example.com';
            // Extract just the domain from email if it's a full email address
            if (strpos($defaultEmailDomain, '@') !== false) {
                $defaultEmailDomain = substr($defaultEmailDomain, strpos($defaultEmailDomain, '@') + 1);
            }
            
            $userData = [
                'username' => $username,
                'email' => $this->getAttribute($user, 'mail') ?? $username . '@' . $defaultEmailDomain,
                'name' => $this->getAttribute($user, 'displayName') ?? 
                         $this->getAttribute($user, 'cn') ?? 
                         $username,
                'department' => $this->getAttribute($user, 'department'),
                'title' => $this->getAttribute($user, 'title'),
                'dn' => $userDn
            ];
            
            return $userData;
            
        } catch (ConnectionException $e) {
            throw new \RuntimeException("LDAP connection failed: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException("LDAP authentication error: " . $e->getMessage());
        }
    }
    
    private function getAttribute($entry, string $attribute): ?string
    {
        $values = $entry->getAttribute($attribute);
        return $values ? $values[0] : null;
    }
}
