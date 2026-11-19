<?php
/**
 * Page text editing (see includes/site_texts.php).
 *   GET  ?toggle=1|0&back=/page.php   switch "Edit page text" on or off, then return to the page
 *   POST key, mode, value | reset=1    save a text (or put back its original wording); answers JSON
 * Owners and admins only; aw_require() checks the CSRF token on every POST.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/site_texts.php';

aw_require('manage_content');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['aw_edit_text'] = (($_GET['toggle'] ?? '0') === '1');
    $back = (string)($_GET['back'] ?? '/index.php');
    if ($back === '' || $back[0] !== '/' || strpos($back, '//') === 0) $back = '/index.php';
    header('Location: ' . SITE_URL . $back);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$key = (string)($_POST['key'] ?? '');
$mode = (string)($_POST['mode'] ?? 'text');
$r = aw_text_save($db, $key, !empty($_POST['reset']) ? null : (string)($_POST['value'] ?? ''), $mode, (int)$_SESSION['user_id']);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
