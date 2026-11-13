<?php
// N4: one endpoint to report anything a member wrote (includes/moderation.php). POST + CSRF, JSON.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/mail_helper.php';
require_once 'includes/moderation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn()) { echo json_encode(['ok' => false, 'message' => 'Please sign in to report.']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please reload the page and try again.']); exit;
}
// D1: a Stories & Help post or reply can only be reported by someone who can see it
$aw_rt = (string)($_POST['target_type'] ?? '');
if ($aw_rt === 'community_post' || $aw_rt === 'community_reply') {
    require_once 'includes/community_posts.php';
    $aw_pid = (int)($_POST['target_id'] ?? 0);
    if ($aw_rt === 'community_reply') { try { $q = $db->prepare("SELECT post_id FROM community_replies WHERE id = ? AND status = 'published'"); $q->execute([$aw_pid]); $aw_pid = (int)$q->fetchColumn(); } catch (Throwable $e) { $aw_pid = 0; } }
    $aw_post = $aw_pid ? aw_cp_get($db, $aw_pid) : null;
    if (!$aw_post || !aw_cp_can_view($aw_post)) { echo json_encode(['ok' => false, 'message' => 'This has already been removed.']); exit; }
}
echo json_encode(aw_report_create($db, (int)$_SESSION['user_id'], (string)($_POST['target_type'] ?? ''), (int)($_POST['target_id'] ?? 0),
    (string)($_POST['reason'] ?? ''), (string)($_POST['details'] ?? '')));
