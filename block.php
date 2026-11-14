<?php
// N4: block or unblock a member. POST + CSRF. JSON when asked, otherwise back to the notifications page.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/moderation.php';
$json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false;
function aw_block_out($ok, $msg, $json) {
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok, 'message' => $msg]); exit; }
    $_SESSION['aw_notif_flash'] = $msg;
    header('Location: ' . SITE_URL . '/notifications.php#blocked');
    exit;
}
if (!isLoggedIn()) aw_block_out(false, 'Please sign in first.', $json);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? '')) aw_block_out(false, 'Your session expired. Please reload and try again.', $json);
$target = (int)($_POST['user_id'] ?? 0);
$on = !empty($_POST['block']);
$ok = aw_block($db, (int)$_SESSION['user_id'], $target, $on);
aw_block_out($ok, $ok ? ($on ? 'Blocked. You will not hear from this member, and their comments are folded away for you.' : 'Unblocked.') : 'That did not work. (Members of the AngelWrites team cannot be blocked.)', $json);
