<?php
// K5: the second step of signing in for staff with two-step sign-in. POST + CSRF; 5 tries, 10 minutes.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/twofactor.php';

$p = $_SESSION['pending_2fa'] ?? null;
if (!$p || time() - (int)$p['at'] > 600 || (int)$p['tries'] >= 5) {
    unset($_SESSION['pending_2fa']);
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please sign in again.';
    } elseif (aw_2fa_verify($db, (int)$p['uid'], (string)($_POST['code'] ?? ''))) {
        $st = $db->prepare("SELECT id, username, email, role, name, first_name, last_name FROM users WHERE id = ?");
        $st->execute([(int)$p['uid']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $back = (string)($p['redirect'] ?? '');
            aw_finish_login($db, $user, !empty($p['remember']));
            header('Location: ' . ($back !== '' ? $back : SITE_URL . '/admin/dashboard.php'));
            exit;
        }
        $error = 'That account no longer exists.';
    } else {
        $_SESSION['pending_2fa']['tries'] = (int)$p['tries'] + 1;
        error_log('2fa: wrong code for user #' . (int)$p['uid']);
        $left = 5 - (int)$_SESSION['pending_2fa']['tries'];
        $error = $left > 0 ? 'That code did not work. Check the time on your phone and try the newest code. ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left.' : 'Too many tries. Please sign in again.';
        if ($left <= 0) { unset($_SESSION['pending_2fa']); }
    }
}
$pageTitle = 'Two-step sign-in';
require_once 'includes/header.php';
?>
<div class="container" style="max-width:460px;padding:48px 20px 80px;">
    <div class="card"><div class="card-body" style="padding:28px;">
        <h1 style="font-family:'Playfair Display',Georgia,serif;font-size:1.7rem;margin:0 0 8px;">Enter your code</h1>
        <p style="color:var(--text-light);margin:0 0 18px;">Open your authenticator app and type the 6-digit code for AngelWrites. Lost your phone? Use one of your recovery codes instead.</p>
        <?php echo aw_alert('error', $error); ?>
        <?php if (!empty($_SESSION['pending_2fa'])): ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <div class="form-group">
                <label for="code">Code</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="12" style="font-size:1.4rem;letter-spacing:.2em;min-height:52px;">
            </div>
            <?php echo aw_btn('Sign in', 'primary', ['type' => 'submit']); ?>
        </form>
        <?php else: ?>
            <p><?php echo aw_link_btn(SITE_URL . '/login.php', 'Sign in again', 'primary'); ?></p>
        <?php endif; ?>
    </div></div>
</div>
<?php require_once 'includes/footer.php'; ?>
