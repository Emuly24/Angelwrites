<?php
// Shared CSRF helpers. Same session key and behaviour as the guarded copies
// already defined in admin/comments.php, dashboard.php, index.php and poem_view.php,
// so including this file next to them is harmless.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
