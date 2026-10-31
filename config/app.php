<?php
/**
 * Maruf Traders - Application Configuration
 */

// Define execution entry guard
defined('APP_INIT') or define('APP_INIT', true);

// Set default timezone for Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Error reporting settings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Security Headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Start secure session if not started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Enable cookie_secure if HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Application Metadata
define('APP_NAME', 'Maruf Traders');
define('APP_TAGLINE', 'Cement Dealership Management & Accounting Software');
define('APP_VERSION', '2.0.0');
define('BUSINESS_LOCATION', 'সুন্দরপুর বাজার, সাটিয়াজুরি, চুনারুঘাট, হবিগঞ্জ');
define('DEFAULT_CURRENCY', 'BDT');
define('DEFAULT_CURRENCY_SYMBOL', '৳');

// Dynamic & Accurate Base URL Calculation
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Get clean script directory relative to document root
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = dirname($scriptName);

// Normalize base directory
$baseDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/');

// Trim subdirectories if app config is required from inside internal modules
if (preg_match('#/(modules|ajax|config|includes|assets|database)(/.*)?$#i', $baseDir, $matches)) {
    $baseDir = substr($baseDir, 0, strpos($baseDir, $matches[1]));
    $baseDir = rtrim($baseDir, '/');
}

define('BASE_URL', $protocol . $hostName . $baseDir);
define('ROOT_PATH', realpath(__DIR__ . '/..'));

// Autoload core configs and helpers
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/functions.php';