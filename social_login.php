<?php
/**
 * Sign in with a provider (see includes/social_auth.php for the keys and the account rules).
 *   social_login.php?provider=Google   start: remember the provider, go to the provider
 *   social_login.php?code=...          the provider sends the person back here
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/social_auth.php';
require_once 'includes/twofactor.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) require_once __DIR__ . '/vendor/autoload.php';

function aw_social_fail($why, $log = '') {
    if ($log !== '') error_log('social login: ' . $log);
    $_SESSION['aw_social_msg'] = $why;
    header('Location: ' . SITE_URL . '/login.php?social=failed');
    exit;
}

if (!class_exists('Hybridauth\\Hybridauth')) aw_social_fail('Signing in with another account is not available right now. Please use your email and password.', 'Hybridauth missing');
aw_social_table($db);

// 1. start: which provider, and where to go afterwards
if (isset($_GET['provider'])) {
    $key = (string)$_GET['provider'];
    if (!aw_social_config($key)) aw_social_fail('That way of signing in is not set up yet. Please use your email and password, or another option.');
    $_SESSION['aw_social_provider'] = $key;
    $back = (string)($_GET['redirect'] ?? '');
    $_SESSION['aw_social_back'] = ($back !== '' && $back[0] === '/' && strpos($back, '//') !== 0) ? $back : '';
}
$key = (string)($_SESSION['aw_social_provider'] ?? '');
$config = $key !== '' ? aw_social_config($key) : null;
if (!$config) aw_social_fail('Please choose how you would like to sign in again.');

// 2. go to the provider, or finish when it sends the person back
try {
    $ha = new Hybridauth\Hybridauth($config);
    $adapter = $ha->authenticate($key); // redirects away the first time
    $p = $adapter->getUserProfile();
    $adapter->disconnect();
} catch (Throwable $e) {
    unset($_SESSION['aw_social_provider']);
    $cancel = stripos($e->getMessage(), 'denied') !== false || isset($_GET['error']);
    aw_social_fail($cancel ? 'Signing in was cancelled. You can try again, or use your email and password.' : 'We could not sign you in with ' . (aw_social_providers()[$key]['label'] ?? $key) . ' just now. Please try again, or use your email and password.', $key . ': ' . $e->getMessage());
}
unset($_SESSION['aw_social_provider']);
$uid = (string)($p->identifier ?? '');
if ($uid === '') aw_social_fail('That account did not share enough to sign you in. Please try another way.', $key . ': empty identifier');
$email = strtolower(trim((string)($p->email ?? '')));
$verified = $email !== '' && strtolower(trim((string)($p->emailVerified ?? ''))) === $email;
$label = aw_social_providers()[$key]['label'] ?? $key;
$back = (string)($_SESSION['aw_social_back'] ?? '');
unset($_SESSION['aw_social_back']);

function aw_social_signin(PDO $db, $user_id, $back) {
    $st = $db->prepare("SELECT id, username, email, role, name, first_name, last_name FROM users WHERE id = ?");
    $st->execute([(int)$user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) aw_social_fail('That account no longer exists. Please sign up again.');
    if (function_exists('aw_is_staff_role') && aw_is_staff_role($u['role']) && function_exists('aw_2fa_enabled') && aw_2fa_enabled($db, (int)$u['id'])) {
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['uid' => (int)$u['id'], 'remember' => false, 'redirect' => $back, 'at' => time(), 'tries' => 0];
        header('Location: ' . SITE_URL . '/login_2fa.php');
        exit;
    }
    aw_finish_login($db, $u, false);
    header('Location: ' . SITE_URL . ($back !== '' ? $back : (in_array($u['role'], ['owner', 'admin'], true) ? '/admin/dashboard.php' : '/dashboard.php')));
    exit;
}

try {
    // a provider account we already know
    $st = $db->prepare("SELECT user_id FROM user_social WHERE provider = ? AND provider_uid = ?");
    $st->execute([$key, $uid]);
    if ($known = $st->fetchColumn()) {
        $db->prepare("UPDATE user_social SET last_used_at = CURRENT_TIMESTAMP WHERE provider = ? AND provider_uid = ?")->execute([$key, $uid]);
        aw_social_signin($db, $known, $back);
    }
    // the same email as an existing account
    if ($email !== '') {
        $st = $db->prepare("SELECT id FROM users WHERE email = ? COLLATE NOCASE");
        $st->execute([$email]);
        if ($existing = $st->fetchColumn()) {
            if ($verified) {
                aw_social_link($db, $existing, $key, $uid, $email);
                aw_social_signin($db, $existing, $back);
            }
            // the provider did not confirm the email: the person proves the account with its password once
            $_SESSION['aw_social_pending_link'] = ['provider' => $key, 'uid' => $uid, 'email' => $email, 'label' => $label, 'at' => time()];
            $_SESSION['aw_social_msg'] = 'You already have an AngelWrites account with ' . $email . '. Sign in with your password once, and your ' . $label . ' account will be linked to it.';
            header('Location: ' . SITE_URL . '/login.php?social=link');
            exit;
        }
    }
} catch (Throwable $e) {
    aw_social_fail('Something went wrong while signing you in. Please try again.', $e->getMessage());
}

// someone new: ask only what the provider did not tell us
$_SESSION['aw_social_new'] = [
    'provider' => $key, 'label' => $label, 'uid' => $uid, 'at' => time(),
    'email' => $email, 'email_verified' => $verified,
    'first_name' => trim((string)($p->firstName ?? '')), 'last_name' => trim((string)($p->lastName ?? '')),
    'display' => trim((string)($p->displayName ?? '')), 'photo' => (string)($p->photoURL ?? ''),
    'back' => $back,
];
header('Location: ' . SITE_URL . '/register.php?social=1');
exit;
