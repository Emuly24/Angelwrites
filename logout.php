<?php
/**
 * Logout Script
 * Destroys session and clears all authentication data
 */

require_once 'includes/config.php';
require_once 'includes/auth.php';

// Clear session data
$_SESSION = array();

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Clear remember me cookies if they exist
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}
if (isset($_COOKIE['user_id'])) {
    setcookie('user_id', '', time() - 3600, '/');
}

// Redirect to home page or login page
header('Location: ' . SITE_URL . '/index.php');
exit;
?>