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
    // F106: also delete the token row; it used to stay valid in the database after logout
    // (and the token now logs people in, F101).
    try {
        require_once __DIR__ . '/includes/db.php';
        $db->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([(string)$_COOKIE['remember_token']]);
    } catch (Throwable $e) {
        error_log('logout: ' . $e->getMessage());
    }
    setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
if (isset($_COOKIE['user_id'])) {
    setcookie('user_id', '', time() - 3600, '/');
}

// Redirect to home page or login page
header('Location: ' . SITE_URL . '/index.php');
exit;
?>