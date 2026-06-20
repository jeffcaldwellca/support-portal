#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Maintenance cleanup task. Intended to be run periodically (e.g. via cron):
 *
 *   * /15 * * * *  php /var/www/html/bin/cleanup.php
 *
 * Removes expired sessions/autosave/login-attempt rows, old uploaded files,
 * and orphaned temporary upload files (temp_* / reply_* never attached to a
 * completed submission).
 */

require __DIR__ . '/../vendor/autoload.php';

use HelpdeskForm\Services\DatabaseService;
use HelpdeskForm\Services\FileUploadService;

$envPath = __DIR__ . '/..';
if (!file_exists($envPath . '/.env')) {
    fwrite(STDERR, "Error: .env file not found\n");
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable($envPath);
$dotenv->load();

$db = new DatabaseService($_ENV['DB_PATH'] ?? './data/helpdesk.db');
$db->cleanupExpired();
echo "Expired sessions, autosave drafts and login attempts cleaned up.\n";

$uploads = new FileUploadService(
    __DIR__ . '/../uploads',
    (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760),
    explode(',', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'pdf,doc,docx,txt,png,jpg,jpeg,gif')
);

$retentionDays = (int) ($_ENV['UPLOAD_RETENTION_DAYS'] ?? 30);
$deleted = $uploads->cleanupOldFiles($retentionDays);
echo "Removed {$deleted} uploaded file(s) older than {$retentionDays} days.\n";

// Remove stale temporary upload artifacts (older than 1 day) that were never
// attached to a submission.
$tempDeleted = 0;
foreach (glob(__DIR__ . '/../uploads/{temp_,reply_}*', GLOB_BRACE) ?: [] as $file) {
    if (is_file($file) && (time() - filemtime($file)) > 86400) {
        if (@unlink($file)) {
            $tempDeleted++;
        }
    }
}
echo "Removed {$tempDeleted} stale temporary upload(s).\n";

// Prune expired FreeScout conversation cache files.
$cacheDeleted = 0;
$cacheTtl = (int) ($_ENV['FREESCOUT_CACHE_TTL'] ?? 30);
foreach (glob(__DIR__ . '/../tmp/cache/freescout_conv_*.json') ?: [] as $file) {
    if (is_file($file) && (time() - filemtime($file)) > max($cacheTtl, 60)) {
        if (@unlink($file)) {
            $cacheDeleted++;
        }
    }
}
echo "Removed {$cacheDeleted} expired cache file(s).\n";

echo "Cleanup complete.\n";
