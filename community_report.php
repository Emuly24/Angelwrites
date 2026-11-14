<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
header('Content-Type: application/json; charset=utf-8'); // F152

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login to report.']);
    exit;
}

$user_id = $_SESSION['user_id'];
// F152: unguarded reads printed "Undefined array key" warnings before the JSON when a field was
// missing, which broke the report modal's response parsing.
// N4: every report now goes through the shared function (one queue, outcomes reach the reporter)
require_once 'includes/csrf.php';
require_once 'includes/mail_helper.php';
require_once 'includes/moderation.php';
$aw_reason_map = ['spam' => 'spam', 'harassment' => 'harassment', 'inappropriate' => 'hurtful', 'offensive' => 'hurtful', 'misinformation' => 'other', 'other' => 'other'];
$aw_reason = (string)($_POST['reason'] ?? '');
$r = aw_report_create($db, (int)$user_id, (string)($_POST['target_type'] ?? ''), (int)($_POST['target_id'] ?? 0),
    $aw_reason_map[$aw_reason] ?? (isset(AW_REPORT_REASONS[$aw_reason]) ? $aw_reason : 'other'), (string)($_POST['details'] ?? ''));
echo json_encode(['success' => $r['ok'], 'error' => $r['ok'] ? null : $r['message'], 'message' => $r['message']]);
exit;
