<?php
/**
 * Maruf Traders - Entry Point Router
 */

define('APP_INIT', true);
require_once __DIR__ . '/config/app.php';

if (!empty($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/modules/dashboard/index.php");
} else {
    header("Location: " . BASE_URL . "/login.php");
}
exit;
