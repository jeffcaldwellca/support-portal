<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

class LocalAuthService
{
    private DatabaseService $databaseService;
    
    public function __construct(DatabaseService $databaseService)
    {
        $this->databaseService = $databaseService;
    }
    
    /**
     * Authenticate a user with local credentials
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @return array|null User data if authentication successful, null otherwise
     */
    public function authenticate(string $username, string $password): ?array
    {
        // Get user by username or email
        $user = $this->databaseService->getLocalUserByUsername($username);
        
        if (!$user) {
            // Try by email
            $user = $this->databaseService->getLocalUserByEmail($username);
        }
        
        if (!$user) {
            error_log("Local auth: User not found: {$username}");
            return null;
        }
        
        // Check if account is active
        if (!$user['is_active']) {
            error_log("Local auth: Account disabled: {$username}");
            return null;
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            error_log("Local auth: Invalid password for: {$username}");
            return null;
        }
        
        // Update last login
        $this->databaseService->updateLocalUserLastLogin($user['id']);
        
        error_log("Local auth: Successful login: {$username}");
        
        // Return user data in the same format as LDAP
        return [
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['name'],
            'department' => $user['department'],
            'title' => $user['title'],
            'auth_type' => 'local'
        ];
    }
    
    /**
     * Create a new local user
     * 
     * @param array $userData User data including username, email, password, name
     * @return int User ID
     * @throws \RuntimeException if user creation fails
     */
    public function createUser(array $userData): int
    {
        // Validate required fields
        $required = ['username', 'email', 'password', 'name'];
        foreach ($required as $field) {
            if (empty($userData[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Validate email format
        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format");
        }
        
        // Check if username already exists
        if ($this->databaseService->getLocalUserByUsername($userData['username'])) {
            throw new \RuntimeException("Username already exists: {$userData['username']}");
        }
        
        // Check if email already exists
        if ($this->databaseService->getLocalUserByEmail($userData['email'])) {
            throw new \RuntimeException("Email already exists: {$userData['email']}");
        }
        
        // Validate password strength
        if (strlen($userData['password']) < 8) {
            throw new \InvalidArgumentException("Password must be at least 8 characters");
        }
        
        // Hash password
        $passwordHash = password_hash($userData['password'], PASSWORD_DEFAULT);
        
        // Create user
        return $this->databaseService->createLocalUser([
            'username' => $userData['username'],
            'email' => $userData['email'],
            'password_hash' => $passwordHash,
            'name' => $userData['name'],
            'department' => $userData['department'] ?? null,
            'title' => $userData['title'] ?? null,
            'is_active' => $userData['is_active'] ?? true
        ]);
    }
    
    /**
     * Update a user's password
     * 
     * @param int $userId User ID
     * @param string $newPassword New plain text password
     * @return bool Success
     */
    public function updatePassword(int $userId, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException("Password must be at least 8 characters");
        }
        
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->databaseService->updateLocalUserPassword($userId, $passwordHash);
    }
    
    /**
     * Update user information
     * 
     * @param int $userId User ID
     * @param array $userData User data to update
     * @return bool Success
     */
    public function updateUser(int $userId, array $userData): bool
    {
        // Validate email if provided
        if (isset($userData['email']) && !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format");
        }
        
        return $this->databaseService->updateLocalUser($userId, $userData);
    }
    
    /**
     * Enable or disable a user account
     * 
     * @param int $userId User ID
     * @param bool $isActive Active status
     * @return bool Success
     */
    public function setUserActive(int $userId, bool $isActive): bool
    {
        return $this->databaseService->updateLocalUser($userId, ['is_active' => $isActive]);
    }
    
    /**
     * Get a user by ID
     * 
     * @param int $userId User ID
     * @return array|null User data
     */
    public function getUserById(int $userId): ?array
    {
        return $this->databaseService->getLocalUserById($userId);
    }
    
    /**
     * Get all local users
     * 
     * @return array List of users
     */
    public function getAllUsers(): array
    {
        return $this->databaseService->getAllLocalUsers();
    }
    
    /**
     * Delete a user
     * 
     * @param int $userId User ID
     * @return bool Success
     */
    public function deleteUser(int $userId): bool
    {
        return $this->databaseService->deleteLocalUser($userId);
    }
}
