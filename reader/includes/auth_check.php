<?php
// ============================================================
//  AUTH_CHECK.PHP – Authentication check for the reader
//  Ensures the reader has a valid session and user.
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Redirect to login if not logged in (optional for guest readers)
if (!isLoggedIn() && !isset($_GET['guest'])) {
    // Guest mode: allow reading without login (no progress saved)
    // Uncomment the next line to force login:
    // header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    // exit;
}

// If logged in, verify user still exists
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

// Set global reader variables
$is_guest = !isLoggedIn();
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
$user_name = isLoggedIn() ? ($_SESSION['name'] ?? 'Reader') : 'Guest';