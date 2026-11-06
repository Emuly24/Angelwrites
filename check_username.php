<?php
// F237: register.php and profile.php check usernames as you type, but this file did not exist, so
// the check always failed (a 404 page instead of JSON) and never said whether a name was free.
// Returns {"available": true|false}. A logged-in member's own username counts as available.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$username = trim((string)($_GET['username'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    echo json_encode(['available' => false, 'reason' => 'format']);
    exit;
}
$stmt = $db->prepare("SELECT id FROM users WHERE username = ? COLLATE NOCASE AND id != ?");
$stmt->execute([$username, isLoggedIn() ? (int)$_SESSION['user_id'] : 0]);
echo json_encode(['available' => !$stmt->fetchColumn()]);
