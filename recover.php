<?php
/**
 * recover.php: reset a password without email, using details given at sign-up.
 *
 *   Step 1  username or email + country + phone number + the human check.
 *   Step 2  (only when those match an account that has a recovery question) the member's question,
 *           their answer and a new password.
 *
 * Safeguards: one vague message whenever details do not match (this page never says who has an
 * account); 5 tries an hour per connection; 3 wrong answers lock recovery for that account for 24
 * hours and tell the member; step 2 expires after 10 minutes; team accounts (admins, moderators,
 * volunteers) must use the emailed code, because they can see members' details. A successful reset
 * signs the account out everywhere and tells the member on the site and by email.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';
require_once 'includes/notifications.php';
require_once 'includes/human_check.php';
require_once 'includes/member_profile.php';
require_once 'includes/twofactor.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }
aw_profile_columns($db);

$error = ''; $done = false;
$state = $_SESSION['aw_recover'] ?? null;
if ($state && time() - (int)$state['at'] > 600) { unset($_SESSION['aw_recover']); $state = null; if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) $error = 'That took more than 10 minutes, so please start again.'; }
$nomatch = 'Those details do not match an account that can be recovered this way. Check them and try again, or use the emailed code. If you still cannot get in, contact the team.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find'])) {
    unset($_SESSION['aw_recover']); $state = null;
    $who = trim((string)($_POST['who'] ?? ''));
    $country = trim((string)($_POST['country'] ?? ''));
    $phone = aw_phone_normalize($country, (string)($_POST['phone'] ?? ''));
    $hc = aw_hc_verify('recover', $_POST);
    if ($hc !== '') $error = $hc;
    elseif (!aw_hc_limit('recover', 5, 3600)) $error = 'Several tries were made from your connection. Please wait an hour, or contact the team.';
    elseif ($who === '' || $phone === '') $error = 'Please enter your username or email, your country and your phone number.';
    else {
        try {
            $st = $db->prepare("SELECT id, role, contact_number, whatsapp_number, recovery_question, recovery_answer, recovery_locked_until FROM users WHERE email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE LIMIT 2");
            $st->execute([strtolower($who), ltrim($who, '@')]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('recover find: ' . $e->getMessage()); $rows = []; }
        $u = count($rows) === 1 ? $rows[0] : null;
        $phone_ok = $u && (hash_equals((string)$u['contact_number'], $phone) || ((string)$u['whatsapp_number'] !== '' && hash_equals((string)$u['whatsapp_number'], $phone)));
        if (!$u || !$phone_ok || empty($u['recovery_question']) || empty($u['recovery_answer']) || !isset(AW_RECOVERY_QUESTIONS[$u['recovery_question']])) {
            $error = $nomatch;
        } elseif (aw_is_staff_role($u['role'], (int)$u['id'])) {
            $error = $nomatch; // team accounts reset by email only (same message, so this page does not reveal who is on the team)
            if (function_exists('aw_audit')) aw_audit('recovery_refused_team', 'user', (int)$u['id'], '');
        } elseif (!empty($u['recovery_locked_until']) && strtotime($u['recovery_locked_until'] . ' UTC') > time()) {
            $error = 'Recovery for this account is paused for a day after several wrong answers. Use the emailed code, or contact the team.';
        } else {
            $_SESSION['aw_recover'] = $state = ['uid' => (int)$u['id'], 'q' => $u['recovery_question'], 'at' => time()];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer']) && $state) {
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['confirm_password'] ?? '');
    if (strlen($pw) < 8) $error = 'Please choose a password of at least 8 characters.';
    elseif ($pw !== $pw2) $error = 'The two passwords do not match.';
    else {
        $st = $db->prepare("SELECT recovery_answer, COALESCE(recovery_attempts, 0) AS tries, email, first_name FROM users WHERE id = ?");
        $st->execute([$state['uid']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify(aw_recovery_norm($_POST['answer']), (string)$u['recovery_answer'])) {
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $state['uid']]);
            aw_after_password_reset($db, $state['uid'], 'recovery');
            unset($_SESSION['aw_recover']); $state = null; $done = true;
        } else {
            $tries = (int)($u['tries'] ?? 0) + 1;
            if ($tries >= 3) {
                $db->prepare("UPDATE users SET recovery_attempts = 0, recovery_locked_until = datetime('now', '+1 day') WHERE id = ?")->execute([$state['uid']]);
                if (function_exists('aw_audit')) aw_audit('recovery_locked', 'user', $state['uid'], '');
                if ($u && !empty($u['email'])) sendEmail($u['email'], 'Someone tried to reset your AngelWrites password',
                    'Hello ' . htmlspecialchars((string)$u['first_name']) . ',<br><br>Someone gave the wrong answer to your recovery question three times. Recovery without email is paused for your account for 24 hours. Your password has not changed.<br><br>If this was you, you can use the emailed code on the <a href="' . SITE_URL . '/forgot_password.php">forgot password</a> page. If it was not, you may want to change your recovery question in Account security.');
                unset($_SESSION['aw_recover']); $state = null;
                $error = 'That answer is not right. Recovery for this account is now paused for a day. Use the emailed code, or contact the team.';
            } else {
                $db->prepare("UPDATE users SET recovery_attempts = ? WHERE id = ?")->execute([$tries, $state['uid']]);
                $error = 'That answer is not right. ' . (3 - $tries) . ' ' . (3 - $tries === 1 ? 'try' : 'tries') . ' left.';
            }
        }
    }
}

$pageTitle = 'Reset your password without email';
$aw_noindex = true;
require_once 'includes/header.php';
?>
<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card" style="max-width:520px;">
                <div class="auth-header">
                    <h1>Reset without email</h1>
                    <p><?php echo $state ? 'One more step: answer your recovery question and choose a new password.' : 'Use the phone number and recovery question you gave when you joined.'; ?></p>
                </div>
                <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <?php if ($done): ?>
                    <div class="alert alert-success" role="status">Your password is changed and you have been signed out everywhere else. We have let you know by email too.</div>
                    <p style="text-align:center"><a class="btn btn-primary" href="<?php echo SITE_URL; ?>/login.php">Log in</a></p>
                <?php elseif ($state): ?>
                    <form method="POST" class="auth-form" autocomplete="off">
                        <div class="form-group"><label for="answer"><?php echo htmlspecialchars(AW_RECOVERY_QUESTIONS[$state['q']]); ?></label>
                            <input type="text" id="answer" name="answer" required maxlength="100" autofocus><small class="field-hint">Capitals and spaces do not matter.</small></div>
                        <div class="form-group"><label for="password">New password</label><input type="password" id="password" name="password" minlength="8" required autocomplete="new-password"></div>
                        <div class="form-group"><label for="confirm_password">New password again</label><input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
                        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-key" aria-hidden="true"></i> Reset my password</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="auth-form" autocomplete="off">
                        <div class="form-group"><label for="who">Username or email</label><input type="text" id="who" name="who" required maxlength="190" value="<?php echo htmlspecialchars((string)($_POST['who'] ?? '')); ?>"></div>
                        <?php // country and phone, the same fields as at sign-up (only these two are used here) ?>
                        <div class="form-group"><label for="rc_country">Country</label><select id="rc_country" name="country" required data-pf-country>
                            <option value="">Choose your country</option>
                            <?php foreach (AW_COUNTRIES as $n => $c): ?><option value="<?php echo htmlspecialchars($n); ?>" data-code="<?php echo htmlspecialchars($c); ?>"<?php echo ($_POST['country'] ?? '') === $n ? ' selected' : ''; ?>><?php echo htmlspecialchars($n . ' (' . $c . ')'); ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="form-group"><label for="rc_phone">Phone number you gave us</label><div class="pf-phone"><span class="pf-code" data-pf-code aria-hidden="true">+</span><input type="tel" id="rc_phone" name="phone" required inputmode="tel"></div></div>
                        <?php echo aw_hc_field('recover'); ?>
                        <button type="submit" name="find" value="1" class="btn btn-primary btn-block">Continue</button>
                    </form>
                    <div class="pf-block" hidden></div><?php echo aw_profile_assets(); ?>
                    <script>(function(){var s=document.getElementById('rc_country'),c=document.querySelector('[data-pf-code]');function u(){var o=s.options[s.selectedIndex];c.textContent=(o&&o.getAttribute('data-code'))||'+';}s.addEventListener('change',u);u();})();</script>
                    <p class="field-hint" style="margin-top:14px">Team accounts reset with the emailed code only. Never set a recovery question? Use <a href="<?php echo SITE_URL; ?>/forgot_password.php">the emailed code</a>, or <a href="<?php echo SITE_URL; ?>/contact.php">contact the team</a>.</p>
                <?php endif; ?>
                <div class="auth-footer"><p><a href="<?php echo SITE_URL; ?>/login.php">Back to Login</a></p></div>
            </div>
        </div>
    </div>
</div>
<style>
.auth-page { padding: 40px 0; } .auth-wrapper { display: flex; justify-content: center; }
.auth-card { width: 100%; background: var(--card-bg); border-radius: 16px; padding: 32px; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }
.auth-header { text-align: center; margin-bottom: 20px; } .auth-header h1 { font-size: 1.7rem; margin: 0 0 4px; } .auth-header p { color: var(--text-light); }
.form-group { margin-bottom: 16px; } .form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: .95rem; background: var(--input-bg); color: var(--text); min-height: 44px; }
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }
.auth-footer { text-align: center; margin-top: 20px; } .auth-footer a { color: var(--rose); font-weight: 600; }
</style>
<?php require_once 'includes/footer.php'; ?>
