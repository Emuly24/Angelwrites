<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
if (!isLoggedIn()) { exit(json_encode(['error' => 'Not logged in'])); }

// Rate limiting (5 toggles per minute per IP)
rate_limit('toggle_reaction', 5, 60);

$target_type = $_POST['target_type'] ?? '';
$target_id = (int)$_POST['target_id'] ?? 0;
$reaction = $_POST['reaction'] ?? '';
if (!$target_type || !$target_id || !$reaction) { exit(json_encode(['error' => 'Invalid params'])); }

$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT id FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?");
$stmt->execute([$target_type, $target_id, $reaction, $user_id]);
if ($stmt->fetchColumn()) {
    $db->prepare("DELETE FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?")
        ->execute([$target_type, $target_id, $reaction, $user_id]);
    $active = false;
} else {
    $db->prepare("INSERT INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?,?,?,?)")
        ->execute([$user_id, $target_type, $target_id, $reaction]);
    $active = true;
}
$stmt = $db->prepare("SELECT COUNT(*) FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=?");
$stmt->execute([$target_type, $target_id, $reaction]);
$count = $stmt->fetchColumn();

echo json_encode(['active' => $active, 'count' => $count]);