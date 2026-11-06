<?php
// F237: register.php and profile.php check email addresses as you type, but this file did not exist,
// so the check always failed. Returns {"available": true|false} (registration already tells a visitor
// when an address is taken). A logged-in member's own address counts as available.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$email = trim((string)($_GET['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['available' => false, 'reason' => 'format']);
    exit;
}
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? COLLATE NOCASE AND id != ?");
$stmt->execute([$email, isLoggedIn() ? (int)$_SESSION['user_id'] : 0]);
echo json_encode(['available' => !$stmt->fetchColumn()]);
