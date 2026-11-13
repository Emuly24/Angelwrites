<?php
// P-ED: poems are added and edited in one place, the full-page editor in manage_poems.php.
// This older editor saved only title, purpose, text, image and recording (no status, date or author)
// and differed from the other one; its links now open the same editor.
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
header('Location: ' . SITE_URL . '/admin/manage_poems.php?' . ($id > 0 ? 'edit=' . $id : 'new=1'));
exit;
