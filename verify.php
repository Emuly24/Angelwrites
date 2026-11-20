<?php
/**
 * Email verification codes are retired: new members pass a built-in human check when they join
 * (includes/human_check.php) and are signed in straight away; older unconfirmed accounts are
 * confirmed the next time they sign in. Old links to this page (and resend_verification.php) land
 * on the login page with a short note.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . SITE_URL . (isAdmin() ? '/admin/dashboard.php' : '/dashboard.php'));
    exit;
}
$_SESSION['aw_social_msg'] = 'No code is needed any more: just sign in with your email or username and password.';
header('Location: ' . SITE_URL . '/login.php?social=link');
exit;
