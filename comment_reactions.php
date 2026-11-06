<?php
// F139: the like / love / pray buttons on blog comments (blog_post.php) post here, but this file
// did not exist, so nothing was saved and the counts were always 0. A logged-in member toggles a
// reaction on a blog comment (reflection_comments); the three counts are returned.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please log in to react.']);
    exit;
}

$comment_id = (int)($_POST['comment_id'] ?? 0);
$type = (string)($_POST['reaction_type'] ?? '');
if ($comment_id <= 0 || !in_array($type, ['like', 'love', 'pray'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}
$exists = $db->prepare("SELECT 1 FROM reflection_comments WHERE id = ?");
$exists->execute([$comment_id]);
if (!$exists->fetchColumn()) {
    echo json_encode(['success' => false, 'error' => 'Comment not found.']);
    exit;
}
$exists->closeCursor();

$user_id = (int)$_SESSION['user_id'];
$del = $db->prepare("DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction_type = ?");
$del->execute([$comment_id, $user_id, $type]);
if ($del->rowCount() === 0) {
    $db->prepare("INSERT INTO comment_reactions (comment_id, user_id, reaction_type) VALUES (?, ?, ?)")->execute([$comment_id, $user_id, $type]);
}

$counts = ['like' => 0, 'love' => 0, 'pray' => 0];
$c = $db->prepare("SELECT reaction_type, COUNT(*) FROM comment_reactions WHERE comment_id = ? GROUP BY reaction_type");
$c->execute([$comment_id]);
foreach ($c->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $n) {
    if (isset($counts[$t])) $counts[$t] = (int)$n;
}
echo json_encode(['success' => true, 'likes' => $counts['like'], 'loves' => $counts['love'], 'prays' => $counts['pray']]);
