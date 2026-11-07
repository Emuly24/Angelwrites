<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
// N6: unsubscribing is a two-step action. Opening the link only shows a "Yes, unsubscribe me"
// button, because mail scanners open links on their own and used to unsubscribe people. Mail
// apps' own "Unsubscribe" button (RFC 8058 one-click, a POST of List-Unsubscribe=One-Click) works
// directly. Asking for a link by email gives the same answer whether or not the address is on the
// list (it used to say so), and is rate-limited. "Resubscribe" now works (it linked to a missing page).
require_once 'includes/newsletter.php';
require_once 'includes/mail_helper.php';

$error = '';
$success = '';
$state = 'form';           // form | confirm | done | resubscribed | already
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$subscriber = null;
if ($token !== '') {
    $st = $db->prepare("SELECT id, email, name, is_active FROM newsletter WHERE unsubscribe_token = ?");
    $st->execute([$token]);
    $subscriber = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$mask = function ($email) {
    [$u, $d] = array_pad(explode('@', (string)$email, 2), 2, '');
    return mb_substr($u, 0, 2) . str_repeat('•', max(1, mb_strlen($u) - 2)) . '@' . $d;
};
$reasons = ['too_many' => 'Too many emails', 'not_relevant' => 'Not what I expected', 'never_signed_up' => 'I never signed up', 'other' => 'Another reason'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $one_click = ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
    $action = $one_click ? 'unsubscribe' : ($_POST['action'] ?? 'unsubscribe');
    if (!$subscriber) {
        if ($one_click) { http_response_code(404); exit; }
        $error = 'This link is not valid any more. Enter your email below and we will send you a new one.';
    } elseif ($action === 'resubscribe') {
        $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$subscriber['id']]);
        aw_nl_audit($db, 'resubscribe', $subscriber['email'] . ' via link');
        $state = 'resubscribed';
    } else {
        if ((int)$subscriber['is_active'] === 1) {
            $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$subscriber['id']]);
            $reason = $reasons[$_POST['reason'] ?? ''] ?? '';
            aw_nl_audit($db, 'unsubscribe', $subscriber['email'] . ($one_click ? ' (one-click from the mail app)' : ' via link') . ($reason ? " – $reason" : ''));
            sendEmail(aw_admin_email(), 'A reader unsubscribed from the newsletter',
                "{$subscriber['email']} unsubscribed" . ($reason ? " ($reason)" : '') . '.', 'angelwrites@zohomail.com', 'AngelWrites', false);
        }
        if ($one_click) { header('Content-Type: text/plain; charset=utf-8'); exit('Unsubscribed.'); }
        $state = 'done';
    }
} elseif ($token !== '') {
    if (!$subscriber) $error = 'This link is not valid any more. Enter your email below and we will send you a new one.';
    else $state = (int)$subscriber['is_active'] === 1 ? 'confirm' : 'already';
} elseif (isset($_GET['email'])) {
    $email = trim((string)$_GET['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!aw_nl_rate_ok('unsub_ip_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600) || !aw_nl_rate_ok('unsub_mail_' . md5(strtolower($email)), 1, 600)) {
        $error = 'Please wait a few minutes before asking again.';
    } else {
        $sub = aw_nl_subscriber($db, $email);
        if ($sub && (int)$sub['is_active'] === 1) {
            if (empty($sub['unsubscribe_token'])) {
                $sub['unsubscribe_token'] = bin2hex(random_bytes(32));
                $db->prepare("UPDATE newsletter SET unsubscribe_token = ? WHERE id = ?")->execute([$sub['unsubscribe_token'], $sub['id']]);
            }
            $link = aw_nl_unsubscribe_url($sub['unsubscribe_token']);
            $m = aw_nl_render($db, 'Unsubscribe from the AngelWrites newsletter', '<p>Hello {{first_name}},</p><p>Someone (hopefully you) asked to stop receiving the AngelWrites newsletter at this address.</p><p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#DBA1A2;color:#fff;padding:10px 18px;border-radius:999px;text-decoration:none;">Unsubscribe</a></p><p>If you did not ask for this, simply ignore this email and nothing will change.</p>', $sub, ['preview' => true, 'signature' => true]);
            aw_nl_mail($sub['email'], 'Unsubscribe from the AngelWrites newsletter', $m['html'], $m['text']);
        }
        $success = 'If this address receives our newsletter, we have emailed it a link to unsubscribe. Please check your inbox.';
        $state = 'sent';
    }
}

$pageTitle = 'Unsubscribe';
?>
<?php require_once 'includes/header.php'; ?>

<div class="unsubscribe-page">
    <div class="container">
        <div class="unsubscribe-wrapper">
            <div class="unsubscribe-header">
                <h1><?php echo $state === 'resubscribed' ? 'Welcome back' : 'Newsletter preferences'; ?></h1>
                <p><?php echo $state === 'resubscribed' ? 'We are glad you are staying with us.' : 'You are always free to leave, and welcome to come back.'; ?></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($state === 'confirm'): ?>
                <div class="unsubscribe-form-container">
                    <div class="info-box"><p>Unsubscribe <strong><?php echo htmlspecialchars($mask($subscriber['email'])); ?></strong> from the AngelWrites newsletter?</p></div>
                    <form method="POST" class="unsubscribe-form">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        <input type="hidden" name="action" value="unsubscribe">
                        <div class="form-group">
                            <label for="reason">Would you tell us why? (optional)</label>
                            <select id="reason" name="reason">
                                <option value="">Prefer not to say</option>
                                <?php foreach ($reasons as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-times-circle"></i> Yes, unsubscribe me</button>
                    </form>
                    <div class="unsubscribe-footer"><p><a href="<?php echo SITE_URL; ?>/index.php"><i class="fas fa-arrow-left"></i> No, keep me subscribed</a></p></div>
                </div>
            <?php elseif (in_array($state, ['done', 'already'], true)): ?>
                <div class="alert alert-success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $state === 'done' ? 'You have been unsubscribed. You will not receive any more newsletters. Thank you for reading with us.' : 'This address is already unsubscribed.'; ?>
                    <div class="success-actions">
                        <a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-primary"><i class="fas fa-home"></i> Return home</a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <input type="hidden" name="action" value="resubscribe">
                            <button type="submit" class="btn btn-outline"><i class="fas fa-undo"></i> Resubscribe</button>
                        </form>
                    </div>
                </div>
            <?php elseif ($state === 'resubscribed'): ?>
                <div class="alert alert-success" role="status">
                    <i class="fas fa-heart"></i> You are subscribed again and will receive the next newsletter.
                    <div class="success-actions"><a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-primary">Read the latest reflections</a></div>
                </div>
            <?php elseif ($state === 'sent'): ?>
                <div class="alert alert-success" role="status"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($success); ?>
                    <div class="success-actions"><a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-primary"><i class="fas fa-home"></i> Return home</a></div>
                </div>
            <?php else: ?>
                <div class="unsubscribe-form-container">
                    <div class="info-box"><p><i class="fas fa-info-circle" style="color: var(--rose);"></i> Every newsletter has an unsubscribe link at the bottom. Lost it? Enter your email and we will send you one.</p></div>
                    <form method="GET" class="unsubscribe-form">
                        <div class="form-group">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="email">
                        </div>
                        <div class="form-actions"><button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send me an unsubscribe link</button></div>
                    </form>
                    <div class="unsubscribe-footer"><p><a href="<?php echo SITE_URL; ?>/index.php"><i class="fas fa-arrow-left"></i> Return home</a></p></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.unsubscribe-page { padding: 32px 0 60px; }
.unsubscribe-wrapper { max-width: 480px; margin: 0 auto; }
.unsubscribe-header { text-align: center; margin-bottom: 32px; }
.unsubscribe-header h1 { font-size: 2.2rem; margin-bottom: 4px; }
.unsubscribe-header p { color: var(--text-light); font-size: 1.05rem; }
.unsubscribe-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.info-box { background: var(--vanilla); border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; text-align: center; color: var(--text); font-size: 0.95rem; }
.info-box i { margin-right: 6px; }
.unsubscribe-form .form-group { margin-bottom: 20px; }
.unsubscribe-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.unsubscribe-form select { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; background: var(--input-bg); color: var(--text); }
.unsubscribe-form input { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; background: var(--input-bg); color: var(--text); }
.unsubscribe-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.unsubscribe-form .btn-block { width: 100%; padding: 14px; font-size: 1.05rem; justify-content: center; }
.unsubscribe-footer { text-align: center; margin-top: 20px; }
.unsubscribe-footer a { color: var(--text-light); transition: color var(--transition); font-size: 0.95rem; }
.unsubscribe-footer a:hover { color: var(--rose); }
.unsubscribe-footer a i { margin-right: 4px; }
.success-actions { margin-top: 16px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.alert-success .success-actions .btn { margin-top: 8px; }
@media (max-width: 480px) { .unsubscribe-form-container { padding: 20px; } .unsubscribe-header h1 { font-size: 1.8rem; } .success-actions { flex-direction: column; align-items: center; } .success-actions .btn { width: 100%; } }
</style>

<?php require_once 'includes/footer.php'; ?><?php require_once 'includes/footer.php'; ?>
