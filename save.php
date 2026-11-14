<?php
// F1: one endpoint for the Save control (includes/saves.php). POST + CSRF.
// Answers JSON to the script; without JavaScript it goes back to the page it came from.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/saves.php';

$json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
function save_answer($json, array $r) {
    if ($json) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($r); exit; }
    $back = (string)($_POST['back'] ?? '/');
    if (!preg_match('#^/[^/\\\\]#', $back)) $back = '/library.php'; // only a path on this site
    $sep = strpos($back, '?') === false ? '?' : '&';
    header('Location: ' . SITE_URL . $back . (empty($r['ok']) ? $sep . 'save_error=1' : ''));
    exit;
}
if (!isLoggedIn()) save_answer($json, ['ok' => false, 'message' => 'Please sign in to save.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? '')) {
    save_answer($json, ['ok' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
}
$uid = (int)$_SESSION['user_id'];
$type = (string)($_POST['type'] ?? '');
$key = aw_save_key($type, $_POST['key'] ?? '');
$act = (string)($_POST['action'] ?? 'save');

if ($act === 'collections') { // the popover's list
    $cur = $key !== '' ? aw_save_get($db, $uid, $type, $key) : null;
    save_answer(true, ['ok' => true, 'saved' => (bool)$cur, 'collection_id' => $cur ? (int)$cur['collection_id'] : 0,
        'collections' => array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => $c['name'], 'n' => (int)$c['n']]; }, aw_collections($db, $uid))]);
}
if ($key === '') save_answer($json, ['ok' => false, 'message' => 'This cannot be saved.']);

if ($act === 'remove') {
    $ok = aw_save_remove($db, $uid, $type, $key);
    save_answer($json, ['ok' => $ok, 'saved' => false, 'message' => $ok ? 'Removed from your library.' : 'That did not work. Please try again.']);
}
$cid = (int)($_POST['collection_id'] ?? 0);
if (trim((string)($_POST['new_collection'] ?? '')) !== '') {
    $err = '';
    $cid = aw_collection_create($db, $uid, $_POST['new_collection'], $err);
    if (!$cid) save_answer($json, ['ok' => false, 'message' => $err]);
}
$r = aw_save_put($db, $uid, $type, $key, $cid);
if ($r['ok'] && $cid) {
    foreach (aw_collections($db, $uid) as $c) if ((int)$c['id'] === $cid) { $r['message'] = 'Saved to “' . $c['name'] . '”.'; break; }
}
$r['collection_id'] = $cid;
save_answer($json, $r);
