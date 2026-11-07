<?php
// ============================================================
//  REFLECTIONS_REACTIONS.PHP – like / love / pray on reflections (RF1)
// ============================================================
// reflections.php called this file, but it did not exist, so the reaction buttons never worked.
// Members only, CSRF-checked and rate-limited. A reaction is a toggle per member (stored in
// `reactions` with target_type 'reflection'), and the counters on the post are recounted from it,
// so they can never drift or be inflated by repeated clicks.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/newsletter.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false]); exit; }
if (!isLoggedIn()) { echo json_encode(['success' => false, 'login' => true]); exit; }
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'This page has expired. Please reload it.']); exit; }
if (!aw_nl_rate_ok('refl_react_' . $_SESSION['user_id'], 30, 60)) { http_response_code(429); echo json_encode(['success' => false, 'error' => 'Slow down a little, please.']); exit; }

$id = (int)($_POST['reflection_id'] ?? 0);
$reaction = $_POST['reaction'] ?? '';
if (!in_array($reaction, ['like', 'love', 'pray'], true)) { echo json_encode(['success' => false, 'error' => 'Unknown reaction.']); exit; }
$st = $db->prepare("SELECT id FROM blog_posts WHERE id = ? AND status = 'published' AND category = 'Christian Reflections'");
$st->execute([$id]);
if (!$st->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Reflection not found.']); exit; }

$uid = (int)$_SESSION['user_id'];
$st = $db->prepare("SELECT id FROM reactions WHERE target_type = 'reflection' AND target_id = ? AND reaction_type = ? AND user_id = ?");
$st->execute([$id, $reaction, $uid]);
if ($rid = $st->fetchColumn()) {
    $db->prepare("DELETE FROM reactions WHERE id = ?")->execute([$rid]);
    $active = false;
} else {
    $db->prepare("INSERT INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?, 'reflection', ?, ?)")->execute([$uid, $id, $reaction]);
    $active = true;
}
$counts = ['like' => 0, 'love' => 0, 'pray' => 0];
$st = $db->prepare("SELECT reaction_type, COUNT(*) FROM reactions WHERE target_type = 'reflection' AND target_id = ? GROUP BY reaction_type");
$st->execute([$id]);
foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) if (isset($counts[$k])) $counts[$k] = (int)$n;
$db->prepare("UPDATE blog_posts SET likes = ?, loves = ?, prays = ? WHERE id = ?")->execute([$counts['like'], $counts['love'], $counts['pray'], $id]);
echo json_encode(['success' => true, 'active' => $active, 'likes' => $counts['like'], 'loves' => $counts['love'], 'prays' => $counts['pray']]);
