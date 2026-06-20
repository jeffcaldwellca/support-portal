<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

use PDO;
use PDOException;

class DatabaseService
{
    private PDO $pdo;
    
    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        try {
            $this->pdo = new PDO("sqlite:{$dbPath}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initializeDatabase();
        } catch (PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function initializeDatabase(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                request_type TEXT NOT NULL,
                requester_email TEXT NOT NULL,
                requester_name TEXT NOT NULL,
                form_data TEXT NOT NULL,
                freescout_ticket_id INTEGER,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS file_uploads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_uuid TEXT NOT NULL,
                original_filename TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (submission_uuid) REFERENCES submissions(uuid)
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT UNIQUE NOT NULL,
                user_email TEXT NOT NULL,
                user_name TEXT NOT NULL,
                user_data TEXT,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS autosave_data (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                request_type TEXT NOT NULL,
                form_data TEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(session_id, request_type)
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS local_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                department TEXT,
                title TEXT,
                is_active INTEGER DEFAULT 1,
                last_login_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_submissions_email ON submissions(requester_email)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_submissions_ticket ON submissions(freescout_ticket_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(identifier, attempted_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON user_sessions(expires_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_autosave_expires ON autosave_data(expires_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_local_users_username ON local_users(username)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_local_users_email ON local_users(email)");
    }
    
    public function logSubmission(array $data): string
    {
        $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO submissions (uuid, request_type, requester_email, requester_name, form_data, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $uuid,
            $data['request_type'],
            $data['requester_email'],
            $data['requester_name'],
            json_encode($data['form_data']),
            'pending'
        ]);
        
        return $uuid;
    }
    
    public function updateSubmissionStatus(string $uuid, string $status, ?int $ticketId = null): void
    {
        $sql = "UPDATE submissions SET status = ?, updated_at = CURRENT_TIMESTAMP";
        $params = [$status];
        
        if ($ticketId !== null) {
            $sql .= ", freescout_ticket_id = ?";
            $params[] = $ticketId;
        }
        
        $sql .= " WHERE uuid = ?";
        $params[] = $uuid;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    public function getSubmission(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM submissions WHERE uuid = ?");
        $stmt->execute([$uuid]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['form_data'] = json_decode($result['form_data'], true);
        }
        
        return $result ?: null;
    }
    
    public function getSubmissionByTicketId(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM submissions WHERE freescout_ticket_id = ?");
        $stmt->execute([$ticketId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['form_data'] = json_decode($result['form_data'], true);
        }
        
        return $result ?: null;
    }
    
    public function logFileUpload(string $submissionUuid, array $fileData): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO file_uploads (submission_uuid, original_filename, stored_filename, mime_type, file_size)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $submissionUuid,
            $fileData['original_filename'],
            $fileData['stored_filename'],
            $fileData['mime_type'],
            $fileData['file_size']
        ]);
    }
    
    public function getFileUploads(string $submissionUuid): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM file_uploads WHERE submission_uuid = ?");
        $stmt->execute([$submissionUuid]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function createSession(string $sessionId, array $userData, int $expiresIn = 3600): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO user_sessions (session_id, user_email, user_name, user_data, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $sessionId,
            $userData['email'],
            $userData['name'],
            json_encode($userData),
            $expiresAt
        ]);
    }
    
    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM user_sessions 
            WHERE session_id = ? AND expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([$sessionId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['user_data'] = json_decode($result['user_data'], true);
        }
        
        return $result ?: null;
    }
    
    public function deleteSession(string $sessionId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
    }
    
    public function saveAutosaveData(?string $sessionId, string $requestType, array $formData, int $expiresIn = 86400): void
    {
        // Skip saving if no session ID (e.g., in development mode without proper sessions)
        if ($sessionId === null) {
            return;
        }
        
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO autosave_data (session_id, request_type, form_data, expires_at, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            $sessionId,
            $requestType,
            json_encode($formData),
            $expiresAt
        ]);
    }
    
    public function getAutosaveData(?string $sessionId, string $requestType): ?array
    {
        // Return null if no session ID (e.g., in development mode without proper sessions)
        if ($sessionId === null) {
            return null;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT form_data FROM autosave_data 
            WHERE session_id = ? AND request_type = ? AND expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute([$sessionId, $requestType]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? json_decode($result['form_data'], true) : null;
    }
    
    /**
     * Check whether the given email submitted a request within the last N seconds.
     * Used for server-side submission throttling (replaces the non-functional
     * $_SESSION-based cooldown).
     */
    public function hasRecentSubmission(string $email, int $withinSeconds): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM submissions
            WHERE requester_email = ?
              AND created_at > datetime('now', ?)
            LIMIT 1
        ");
        $stmt->execute([$email, "-{$withinSeconds} seconds"]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record a failed login attempt for an identifier (e.g. hash of username+IP).
     */
    public function recordLoginAttempt(string $identifier): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO login_attempts (identifier) VALUES (?)");
        $stmt->execute([$identifier]);
    }

    /**
     * Count failed login attempts for an identifier within the given window (seconds).
     */
    public function countRecentLoginAttempts(string $identifier, int $withinSeconds): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE identifier = ?
              AND attempted_at > datetime('now', ?)
        ");
        $stmt->execute([$identifier, "-{$withinSeconds} seconds"]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Clear recorded login attempts for an identifier (called after a successful login).
     */
    public function clearLoginAttempts(string $identifier): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->execute([$identifier]);
    }

    public function cleanupExpired(): void
    {
        $this->pdo->exec("DELETE FROM user_sessions WHERE expires_at <= CURRENT_TIMESTAMP");
        $this->pdo->exec("DELETE FROM autosave_data WHERE expires_at <= CURRENT_TIMESTAMP");
        // Login attempts older than 24h are no longer relevant for lockout.
        $this->pdo->exec("DELETE FROM login_attempts WHERE attempted_at <= datetime('now', '-1 day')");
    }
    
    // Local user authentication methods
    
    /**
     * Create a new local user
     */
    public function createLocalUser(array $userData): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO local_users (username, email, password_hash, name, department, title, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userData['username'],
            $userData['email'],
            $userData['password_hash'],
            $userData['name'],
            $userData['department'] ?? null,
            $userData['title'] ?? null,
            $userData['is_active'] ? 1 : 0
        ]);
        
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * Get a local user by username
     */
    public function getLocalUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM local_users WHERE username = ?");
        $stmt->execute([$username]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['is_active'] = (bool) $result['is_active'];
        }
        
        return $result ?: null;
    }
    
    /**
     * Get a local user by email
     */
    public function getLocalUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM local_users WHERE email = ?");
        $stmt->execute([$email]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['is_active'] = (bool) $result['is_active'];
        }
        
        return $result ?: null;
    }
    
    /**
     * Get a local user by ID
     */
    public function getLocalUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM local_users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['is_active'] = (bool) $result['is_active'];
        }
        
        return $result ?: null;
    }
    
    /**
     * Get all local users
     */
    public function getAllLocalUsers(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM local_users ORDER BY name");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$result) {
            $result['is_active'] = (bool) $result['is_active'];
        }
        
        return $results;
    }
    
    /**
     * Update a local user's password
     */
    public function updateLocalUserPassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE local_users 
            SET password_hash = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        
        return $stmt->execute([$passwordHash, $userId]);
    }
    
    /**
     * Update a local user's information
     */
    public function updateLocalUser(int $userId, array $userData): bool
    {
        $fields = [];
        $params = [];
        
        $allowedFields = ['email', 'name', 'department', 'title', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $userData)) {
                $fields[] = "{$field} = ?";
                $params[] = $field === 'is_active' ? ($userData[$field] ? 1 : 0) : $userData[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $userId;
        
        $sql = "UPDATE local_users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($params);
    }
    
    /**
     * Update a local user's last login timestamp
     */
    public function updateLocalUserLastLogin(int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE local_users 
            SET last_login_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        
        return $stmt->execute([$userId]);
    }
    
    /**
     * Delete a local user
     */
    public function deleteLocalUser(int $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM local_users WHERE id = ?");
        return $stmt->execute([$userId]);
    }
}
