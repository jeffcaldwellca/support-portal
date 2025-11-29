#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Local User Management CLI Tool
 * 
 * Usage:
 *   php bin/manage-local-users.php create <username> <email> <name>
 *   php bin/manage-local-users.php list
 *   php bin/manage-local-users.php reset-password <username>
 *   php bin/manage-local-users.php disable <username>
 *   php bin/manage-local-users.php enable <username>
 *   php bin/manage-local-users.php delete <username>
 */

require __DIR__ . '/../vendor/autoload.php';

use HelpdeskForm\Services\DatabaseService;
use HelpdeskForm\Services\LocalAuthService;

// Check if .env file exists
$envPath = __DIR__ . '/..';
$envFile = $envPath . '/.env';

if (!file_exists($envFile)) {
    echo "Error: .env file not found\n";
    echo "Please create a .env file first:\n";
    echo "  cp .env.example .env\n";
    echo "  # Then edit .env with your configuration\n";
    exit(1);
}

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable($envPath);
$dotenv->load();

// Initialize services
$dbService = new DatabaseService($_ENV['DB_PATH'] ?? './data/helpdesk.db');
$authService = new LocalAuthService($dbService);

// Parse command line arguments
$command = $argv[1] ?? null;

if (!$command) {
    showHelp();
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            createUser($argv, $authService);
            break;
            
        case 'list':
            listUsers($authService);
            break;
            
        case 'reset-password':
            resetPassword($argv, $authService, $dbService);
            break;
            
        case 'disable':
            setUserStatus($argv, $authService, false);
            break;
            
        case 'enable':
            setUserStatus($argv, $authService, true);
            break;
            
        case 'delete':
            deleteUser($argv, $authService, $dbService);
            break;
            
        default:
            echo "Unknown command: {$command}\n";
            showHelp();
            exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function showHelp(): void
{
    echo <<<HELP
Local User Management CLI Tool

Usage:
  php bin/manage-local-users.php <command> [arguments]

Commands:
  create <username> <email> <name>    Create a new local user
  list                                 List all local users
  reset-password <username>            Reset a user's password
  disable <username>                   Disable a user account
  enable <username>                    Enable a user account
  delete <username>                    Delete a user account

Examples:
  php bin/manage-local-users.php create jdoe john.doe@example.com "John Doe"
  php bin/manage-local-users.php list
  php bin/manage-local-users.php reset-password jdoe
  php bin/manage-local-users.php disable jdoe
  php bin/manage-local-users.php enable jdoe
  php bin/manage-local-users.php delete jdoe

HELP;
}

function createUser(array $argv, LocalAuthService $authService): void
{
    if (!isset($argv[2], $argv[3], $argv[4])) {
        echo "Error: Missing arguments\n";
        echo "Usage: create <username> <email> <name>\n";
        exit(1);
    }
    
    $username = $argv[2];
    $email = $argv[3];
    $name = $argv[4];
    $department = $argv[5] ?? null;
    $title = $argv[6] ?? null;
    
    // Prompt for password
    echo "Enter password for {$username}: ";
    $password = readline();
    
    if (strlen($password) < 8) {
        echo "Error: Password must be at least 8 characters\n";
        exit(1);
    }
    
    // Confirm password
    echo "Confirm password: ";
    $passwordConfirm = readline();
    
    if ($password !== $passwordConfirm) {
        echo "Error: Passwords do not match\n";
        exit(1);
    }
    
    $userData = [
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'name' => $name,
        'department' => $department,
        'title' => $title,
        'is_active' => true
    ];
    
    $userId = $authService->createUser($userData);
    
    echo "✓ User created successfully!\n";
    echo "  ID: {$userId}\n";
    echo "  Username: {$username}\n";
    echo "  Email: {$email}\n";
    echo "  Name: {$name}\n";
}

function listUsers(LocalAuthService $authService): void
{
    $users = $authService->getAllUsers();
    
    if (empty($users)) {
        echo "No local users found.\n";
        return;
    }
    
    echo "\nLocal Users:\n";
    echo str_repeat('=', 100) . "\n";
    printf("%-5s %-15s %-30s %-30s %-10s %-20s\n", 
        "ID", "Username", "Email", "Name", "Status", "Last Login");
    echo str_repeat('-', 100) . "\n";
    
    foreach ($users as $user) {
        printf("%-5s %-15s %-30s %-30s %-10s %-20s\n",
            $user['id'],
            $user['username'],
            $user['email'],
            substr($user['name'], 0, 28),
            $user['is_active'] ? 'Active' : 'Disabled',
            $user['last_login_at'] ?? 'Never'
        );
    }
    
    echo str_repeat('=', 100) . "\n";
    echo "Total: " . count($users) . " user(s)\n\n";
}

function resetPassword(array $argv, LocalAuthService $authService, DatabaseService $dbService): void
{
    if (!isset($argv[2])) {
        echo "Error: Missing username\n";
        echo "Usage: reset-password <username>\n";
        exit(1);
    }
    
    $username = $argv[2];
    
    // Get user
    $user = $dbService->getLocalUserByUsername($username);
    if (!$user) {
        echo "Error: User not found: {$username}\n";
        exit(1);
    }
    
    // Prompt for new password
    echo "Enter new password for {$username}: ";
    $password = readline();
    
    if (strlen($password) < 8) {
        echo "Error: Password must be at least 8 characters\n";
        exit(1);
    }
    
    // Confirm password
    echo "Confirm new password: ";
    $passwordConfirm = readline();
    
    if ($password !== $passwordConfirm) {
        echo "Error: Passwords do not match\n";
        exit(1);
    }
    
    $authService->updatePassword($user['id'], $password);
    
    echo "✓ Password updated successfully for user: {$username}\n";
}

function setUserStatus(array $argv, LocalAuthService $authService, bool $isActive): void
{
    if (!isset($argv[2])) {
        echo "Error: Missing username\n";
        echo "Usage: " . ($isActive ? 'enable' : 'disable') . " <username>\n";
        exit(1);
    }
    
    $username = $argv[2];
    
    // Get user by username
    $user = $authService->getUserById(0); // First, find by username
    $allUsers = $authService->getAllUsers();
    $targetUser = null;
    
    foreach ($allUsers as $u) {
        if ($u['username'] === $username) {
            $targetUser = $u;
            break;
        }
    }
    
    if (!$targetUser) {
        echo "Error: User not found: {$username}\n";
        exit(1);
    }
    
    $authService->setUserActive($targetUser['id'], $isActive);
    
    $status = $isActive ? 'enabled' : 'disabled';
    echo "✓ User {$status} successfully: {$username}\n";
}

function deleteUser(array $argv, LocalAuthService $authService, DatabaseService $dbService): void
{
    if (!isset($argv[2])) {
        echo "Error: Missing username\n";
        echo "Usage: delete <username>\n";
        exit(1);
    }
    
    $username = $argv[2];
    
    // Get user
    $user = $dbService->getLocalUserByUsername($username);
    if (!$user) {
        echo "Error: User not found: {$username}\n";
        exit(1);
    }
    
    // Confirm deletion
    echo "Are you sure you want to delete user '{$username}'? (yes/no): ";
    $confirm = readline();
    
    if (strtolower($confirm) !== 'yes') {
        echo "Deletion cancelled.\n";
        exit(0);
    }
    
    $authService->deleteUser($user['id']);
    
    echo "✓ User deleted successfully: {$username}\n";
}
