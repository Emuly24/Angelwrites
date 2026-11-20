<?php
/**
 * The picture puzzle behind "I'm not a robot" (includes/human_check.php).
 *   POST action=puzzle&id=…          a new puzzle (or "passed" for someone solved recently)
 *   POST action=answer&id=…&picked[] check the answer
 *   GET  ?tile=…&id=…                one picture of the current puzzle (PNG, never cached)
 * Everything is tied to the visitor's session; nothing is stored elsewhere.
 */
require_once 'includes/config.php';
require_once 'includes/human_check.php';

aw_hc_session();
$id = (string)($_REQUEST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $id)) { http_response_code(400); exit; }

if (isset($_GET['tile'])) {
    $png = preg_match('/^[a-f0-9]{12}$/', (string)$_GET['tile']) ? aw_hc_tile_png($id, (string)$_GET['tile']) : '';
    if ($png === '') { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    echo $png;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
if (!aw_hc_limit('hc_puzzle', 60, 3600)) { echo json_encode(['ok' => false, 'msg' => 'Too many puzzles from this connection. Please wait a little and try again.']); exit; }

$action = (string)($_POST['action'] ?? '');
if ($action === 'puzzle') {
    if (aw_hc_trust($id)) { echo json_encode(['ok' => true, 'passed' => true]); exit; }
    echo json_encode(aw_hc_new_puzzle($id));
} elseif ($action === 'answer') {
    $r = aw_hc_check_puzzle($id, (array)($_POST['picked'] ?? []));
    if (!$r['ok'] && strpos($r['msg'], 'reload') !== false) $r['reload'] = true;
    echo json_encode($r);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false]);
}
