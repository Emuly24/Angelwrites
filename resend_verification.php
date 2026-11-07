<?php
// Q25: this page was a second copy of the "send me a new verification code" flow that verify.php
// already has (email field, code entry and Resend), and it said "No account found" for unknown
// addresses. Old links and bookmarks now go to verify.php (with the address if one was given).
require_once __DIR__ . '/includes/config.php';
$email = trim((string)($_POST['email'] ?? $_GET['email'] ?? ''));
header('Location: ' . SITE_URL . '/verify.php' . ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? '?email=' . urlencode($email) : ''));
exit;
