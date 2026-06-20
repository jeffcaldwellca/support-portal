<?php
declare(strict_types=1);

namespace HelpdeskForm\Tests\Services;

use PHPUnit\Framework\TestCase;
use HelpdeskForm\Services\DatabaseService;

class DatabaseServiceTest extends TestCase
{
    private DatabaseService $db;

    protected function setUp(): void
    {
        // In-memory SQLite so each test runs against a fresh schema.
        $this->db = new DatabaseService(':memory:');
    }

    public function testHasRecentSubmissionDetectsRecentEntry(): void
    {
        $this->db->logSubmission([
            'request_type' => 'problem',
            'requester_email' => 'user@example.com',
            'requester_name' => 'Test User',
            'form_data' => ['subject' => 'Help'],
        ]);

        $this->assertTrue($this->db->hasRecentSubmission('user@example.com', 5));
        $this->assertFalse($this->db->hasRecentSubmission('other@example.com', 5));
    }

    public function testLoginAttemptThrottlingLifecycle(): void
    {
        $id = hash('sha256', 'attacker');

        $this->assertSame(0, $this->db->countRecentLoginAttempts($id, 900));

        $this->db->recordLoginAttempt($id);
        $this->db->recordLoginAttempt($id);
        $this->db->recordLoginAttempt($id);

        $this->assertSame(3, $this->db->countRecentLoginAttempts($id, 900));

        $this->db->clearLoginAttempts($id);
        $this->assertSame(0, $this->db->countRecentLoginAttempts($id, 900));
    }

    public function testCleanupRemovesExpiredSessions(): void
    {
        $this->db->createSession('sess-expired', ['email' => 'a@b.c', 'name' => 'A'], -10);
        $this->db->createSession('sess-valid', ['email' => 'a@b.c', 'name' => 'A'], 3600);

        $this->db->cleanupExpired();

        $this->assertNull($this->db->getSession('sess-expired'));
        $this->assertNotNull($this->db->getSession('sess-valid'));
    }
}
