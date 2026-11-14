<?php
// G1: planned reads from the reader (includes/reading_plans.php). POST + CSRF, JSON.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/mail_helper.php';
require_once 'includes/reading_plans.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn()) { echo json_encode(['ok' => false, 'message' => 'Please sign in to plan a read.']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please reload the page and try again.']); exit;
}
$uid = (int)$_SESSION['user_id'];
$book = (int)($_POST['book_id'] ?? 0);
$act = (string)($_POST['action'] ?? 'list');
$out = ['ok' => true];
if ($act === 'add') {
    $out = aw_plan_add($db, $uid, $book, (string)($_POST['date'] ?? ''), (int)($_POST['page'] ?? 0), (string)($_POST['label'] ?? ''), (string)($_POST['note'] ?? ''), !empty($_POST['email']));
} elseif ($act === 'done' || $act === 'delete') {
    $ok = aw_plan_update($db, $uid, (int)($_POST['id'] ?? 0), $act);
    $out = ['ok' => $ok, 'message' => !$ok ? 'That plan was not found.' : ($act === 'done' ? 'Marked as read.' : 'Removed.')];
}
$out['plans'] = array_map(function ($p) {
    return ['id' => (int)$p['id'], 'when' => aw_plan_when($p['plan_date']), 'date' => $p['plan_date'], 'page' => (int)$p['page'], 'label' => (string)$p['label'], 'note' => (string)$p['note'], 'email' => (int)$p['email'] === 1];
}, aw_plans_for_book($db, $uid, $book));
echo json_encode($out);
