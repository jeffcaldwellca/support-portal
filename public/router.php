<?php
/**
 * Router script for PHP built-in server
 * This handles URL rewriting since the built-in server doesn't support .htaccess
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$publicPath = __DIR__;

// Remove query string for file checks
$filePath = $publicPath . $requestPath;

// Check if it's a static file that exists
if (is_file($filePath)) {
    // Serve static files directly
    return false;
}

// Check for assets directory
if (strpos($requestPath, '/assets/') === 0) {
    $assetPath = $publicPath . $requestPath;
    if (is_file($assetPath)) {
        return false;
    }
}

// For all other requests, route to index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once $publicPath . '/index.php';
