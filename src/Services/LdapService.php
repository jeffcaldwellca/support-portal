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
        
        // Debug logging for LDAP configuration (remove sensitive info in production)
        error_log("LDAP Config: " . json_encode([
            'host' => $config['host'] ?? 'NOT_SET',
            'port' => $config['port'] ?? 'NOT_SET',
            'base_dn' => $config['base_dn'] ?? 'NOT_SET',
            'bind_dn' => $config['bind_dn'] ?? 'NOT_SET',
            'bind_password' => !empty($config['bind_password']) ? '[SET]' : 'NOT_SET',
            'user_filter' => $config['user_filter'] ?? 'NOT_SET'
        ]));
    }
    
    public function authenticate(string $username, string $password): ?array
    {
        // Ensure adequate execution time for LDAP operations
        set_time_limit(600);
        
        error_log("LDAP Authentication attempt for user: $username");
        
        try {
            // Ensure host doesn't have protocol prefix for Symfony LDAP
            $host = $this->config['host'];
            $host = preg_replace('/^ldaps?:\/\//', '', $host);
            
            error_log("Connecting to LDAP host: $host:{$this->config['port']}");
            
            $this->ldap = Ldap::create('ext_ldap', [
                'host' => $host,
                'port' => $this->config['port'],
                'encryption' => 'none',
                'options' => [
                    'network_timeout' => 300,  // 5 minutes network timeout
                    'timelimit' => 300,        // 5 minutes operation timeout
                    'referrals' => false       // Disable referrals to prevent additional timeouts
                ]
            ]);
            
            // Try anonymous bind first, or use service account if provided
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                // Bind with service account credentials for user search
                $this->ldap->bind($this->config['bind_dn'], $this->config['bind_password']);
            } else {
                // Try anonymous bind for user search
                $this->ldap->bind();
            }
            
            // Search for the user
            $filter = sprintf($this->config['user_filter'], $username);
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
    
    public function searchUsers(string $query): array
    {
        // Ensure adequate execution time for LDAP operations
        set_time_limit(600);
        
        if (!$this->ldap) {
            // Ensure host doesn't have protocol prefix for Symfony LDAP
            $host = $this->config['host'];
            $host = preg_replace('/^ldaps?:\/\//', '', $host);
            
            $this->ldap = Ldap::create('ext_ldap', [
                'host' => $host,
                'port' => $this->config['port'],
                'encryption' => 'none',
                'options' => [
                    'network_timeout' => 300,  // 5 minutes network timeout
                    'timelimit' => 300,        // 5 minutes operation timeout
                    'referrals' => false       // Disable referrals to prevent additional timeouts
                ]
            ]);
            
            $this->ldap->bind($this->config['bind_dn'], $this->config['bind_password']);
        }
        
        $filter = sprintf('(|(cn=*%s*)(mail=*%s*)(displayName=*%s*))', $query, $query, $query);
        $ldapQuery = $this->ldap->query($this->config['base_dn'], $filter);
        $results = $ldapQuery->execute();
        
        $users = [];
        foreach ($results as $user) {
            $users[] = [
                'name' => $this->getAttribute($user, 'displayName') ?? $this->getAttribute($user, 'cn'),
                'email' => $this->getAttribute($user, 'mail'),
                'department' => $this->getAttribute($user, 'department'),
                'title' => $this->getAttribute($user, 'title')
            ];
        }
        
        return $users;
    }
    
    public function getUserByEmail(string $email): ?array
    {
        if (!$this->ldap) {
            $this->ldap = Ldap::create('ext_ldap', [
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => 'none'
            ]);
            
            $this->ldap->bind($this->config['user_dn'], $this->config['password']);
        }
        
        $filter = sprintf('(mail=%s)', $email);
        $query = $this->ldap->query($this->config['base_dn'], $filter);
        $result = $query->execute();
        
        if (count($result) === 0) {
            return null;
        }
        
        $user = $result[0];
        return [
            'email' => $this->getAttribute($user, 'mail'),
            'name' => $this->getAttribute($user, 'displayName') ?? $this->getAttribute($user, 'cn'),
            'department' => $this->getAttribute($user, 'department'),
            'title' => $this->getAttribute($user, 'title'),
            'dn' => $user->getDn()
        ];
    }
    
    private function getAttribute($entry, string $attribute): ?string
    {
        $values = $entry->getAttribute($attribute);
        return $values ? $values[0] : null;
    }
        
    public function isConnectionHealthy(): bool
    {
        try {
            $ldap = Ldap::create('ext_ldap', [
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => 'none'
            ]);
            
            $ldap->bind($this->config['user_dn'], $this->config['password']);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
