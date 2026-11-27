<?php
// ============================================================
//  AJAX_TOGGLE_REACTION.PHP – like / love / pray on a poem or a poem comment
// ============================================================
// Members only. A reaction is a toggle per member. Every answer is JSON with a plain message, so the
// page can say what went wrong instead of failing silently (the old file let a 5-per-minute limit
// keyed on the IP address, or any database error, answer with a bare text or error page).
//  - limit: 40 taps a minute per member
//  - the poem or comment must exist (a published poem)
//  - works with an older reactions table that allowed only one reaction per member and item
//    (the member's reaction then changes from one kind to the other)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/newsletter.php'; // aw_nl_rate_ok(), CSRF helpers

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $a, $code = 200) { http_response_code($code); echo json_encode($a); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['error' => 'Please use the buttons on the page.'], 405);
if (!isLoggedIn()) $out(['error' => 'Please sign in to react.', 'login' => true]);
// same-site only (a page on another website cannot react for a member)
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') $out(['error' => 'Please react from the poem page.'], 403);
$token = (string)($_POST['csrf_token'] ?? '');
if ($token !== '' && !validate_csrf_token($token)) $out(['error' => 'This page has expired. Please reload it and try again.', 'reload' => true], 400);

$uid = (int)$_SESSION['user_id'];
if (!aw_nl_rate_ok('poem_react_' . $uid, 40, 60)) $out(['error' => 'That was a lot of taps. Please wait a moment and try again.'], 429);

$type = (string)($_POST['target_type'] ?? '');
$tid = (int)($_POST['target_id'] ?? 0);
$reaction = (string)($_POST['reaction'] ?? '');
if (!in_array($type, ['poem', 'comment'], true) || $tid <= 0 || !in_array($reaction, ['like', 'love', 'pray'], true)) {
    $out(['error' => 'That reaction could not be saved. Please reload the page.'], 400);
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS reactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, target_type TEXT NOT NULL,
               target_id INTEGER NOT NULL, reaction_type TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
               UNIQUE(user_id, target_type, target_id, reaction_type))");
    if ($type === 'poem') {
        $st = $db->prepare("SELECT 1 FROM poems WHERE id = ?");
    } else {
        $st = $db->prepare("SELECT 1 FROM reviews WHERE id = ?");
    }
    $st->execute([$tid]);
    if (!$st->fetchColumn()) $out(['error' => 'That ' . ($type === 'poem' ? 'poem' : 'comment') . ' is no longer here.'], 404);

    $st = $db->prepare("SELECT id FROM reactions WHERE target_type = ? AND target_id = ? AND reaction_type = ? AND user_id = ?");
    $st->execute([$type, $tid, $reaction, $uid]);
    if ($rid = $st->fetchColumn()) {
        $db->prepare("DELETE FROM reactions WHERE id = ?")->execute([$rid]);
        $active = false;
    } else {
        try {
            $db->prepare("INSERT INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?, ?, ?, ?)")->execute([$uid, $type, $tid, $reaction]);
        } catch (PDOException $e) {
            // an older table with one reaction per member and item: change it to this one
            $db->prepare("DELETE FROM reactions WHERE user_id = ? AND target_type = ? AND target_id = ?")->execute([$uid, $type, $tid]);
            $db->prepare("INSERT INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?, ?, ?, ?)")->execute([$uid, $type, $tid, $reaction]);
        }
        $active = true;
    }
    // all three counts and which ones are the member's, so every button on the item stays right
    $counts = ['like' => 0, 'love' => 0, 'pray' => 0]; $mine = [];
    $st = $db->prepare("SELECT reaction_type, COUNT(*) n, MAX(user_id = ?) m FROM reactions WHERE target_type = ? AND target_id = ? GROUP BY reaction_type");
    $st->execute([$uid, $type, $tid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($counts[$r['reaction_type']])) continue;
        $counts[$r['reaction_type']] = (int)$r['n'];
        if ((int)$r['m'] === 1) $mine[] = $r['reaction_type'];
    }
    $out(['active' => $active, 'count' => $counts[$reaction], 'counts' => $counts, 'mine' => $mine]);
} catch (Throwable $e) {
    error_log('ajax_toggle_reaction: ' . $e->getMessage());
    $out(['error' => 'Your reaction could not be saved just now. Please try again.'], 500);
}
