<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Rate limiting (10 requests per minute per IP)
rate_limit('get_reactions', 10, 60);

$target_type = $_GET['target_type'] ?? '';
$target_id = (int)$_GET['target_id'] ?? 0;
if (!$target_type || !$target_id) { exit('[]'); }

$reactions = ['like','love','laugh','wow','sad','angry'];
$result = [];

foreach ($reactions as $r) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=?");
    $stmt->execute([$target_type, $target_id, $r]);
    $count = $stmt->fetchColumn();
    $active = false;
    if (isLoggedIn()) {
        $stmt = $db->prepare("SELECT 1 FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?");
        $stmt->execute([$target_type, $target_id, $r, $_SESSION['user_id']]);
        $active = (bool)$stmt->fetchColumn();
    }
    $result[$r] = ['count' => $count, 'active' => $active];
}
header('Content-Type: application/json');
echo json_encode($result);