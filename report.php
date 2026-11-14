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
echo json_encode(aw_report_create($db, (int)$_SESSION['user_id'], (string)($_POST['target_type'] ?? ''), (int)($_POST['target_id'] ?? 0),
    (string)($_POST['reason'] ?? ''), (string)($_POST['details'] ?? '')));
