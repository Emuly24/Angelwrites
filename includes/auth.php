<?php
/**
 * Authentication & Authorization Functions
 */

// ===== FIX: Set custom session save path =====
$session_path = __DIR__ . '/../tmp/sessions';
if (!is_dir($session_path)) {
    mkdir($session_path, 0755, true);
}
//session_save_path($session_path);
// =============================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// F101: "Remember me" wrote a token and cookie at login but nothing ever read them, so members
// were logged out when the session expired (about 24 h). A visitor with no session but a valid,
// unexpired remember_token cookie for a verified account is logged back in here. Pages that load
// auth.php without db.php (logout.php) skip this.
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token']) && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    try {
        $aw_rm = $GLOBALS['db']->prepare("SELECT u.id, u.username, u.name, u.role, u.is_verified
                                          FROM remember_tokens t JOIN users u ON u.id = t.user_id
                                          WHERE t.token = ? AND t.expires_at > ?");
        $aw_rm->execute([(string)$_COOKIE['remember_token'], date('Y-m-d H:i:s')]);
        $aw_ru = $aw_rm->fetch(PDO::FETCH_ASSOC);
        $aw_rm->closeCursor();
        if ($aw_ru && (int)$aw_ru['is_verified'] === 1) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $aw_ru['id'];
            $_SESSION['username'] = $aw_ru['username'];
            $_SESSION['name'] = $aw_ru['name'];
            $_SESSION['role'] = $aw_ru['role'];
        }
    } catch (Throwable $e) {
        error_log('remember-me login: ' . $e->getMessage());
    }
}

/**
 * Check if a user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the logged-in user is an admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Redirect to login if not logged in
 */
function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        // F103: pass the page the visitor asked for, so login.php returns them there (only for
        // GET; a POST cannot be replayed after login).
        $back = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        header('Location: ' . SITE_URL . '/login.php' . ($back !== '' ? '?redirect=' . urlencode($back) : ''));
        exit;
    }
}

/**
 * Redirect to dashboard if user is not an admin
 */
function redirectIfNotAdmin() {
    redirectIfNotLoggedIn();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

/**
 * Get the current user's ID (returns 0 if not logged in)
 */
function getUserId() {
    return $_SESSION['user_id'] ?? 0;
}

/**
 * Get the current user's name (returns 'Guest' if not logged in)
 */
function getUserName() {
    return $_SESSION['name'] ?? 'Guest';
}

/**
 * Get the current user's role (returns 'guest' if not logged in)
 */
function getUserRole() {
    return $_SESSION['role'] ?? 'guest';
}
?>