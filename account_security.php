<?php
// Account security: every member sets their recovery question (reset a password without email);
// the team (admins, moderators, volunteers) also turns two-step sign-in on or off and gets recovery codes.
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/twofactor.php';
require_once 'includes/member_profile.php';

redirectIfNotLoggedIn();
$uid = (int)$_SESSION['user_id'];
$is_team = aw_is_staff_role($_SESSION['role'] ?? '', $uid);
// members who joined with Google/Facebook never chose a password, so they are not asked for one here
$via_social = false;
try { $vs = $db->prepare("SELECT 1 FROM user_social WHERE user_id = ? LIMIT 1"); $vs->execute([$uid]); $via_social = (bool)$vs->fetchColumn(); } catch (Throwable $e) { /* no table yet */ }
aw_profile_columns($db);
$error = ''; $notice = ''; $codes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = $db->prepare("SELECT password FROM users WHERE id = ?"); $pw->execute([$uid]); $hash = (string)$pw->fetchColumn();
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['save_recovery'])) {
        $q = (string)($_POST['recovery_question'] ?? ''); $a = aw_recovery_norm($_POST['recovery_answer'] ?? '');
        if (!$via_social && !password_verify((string)($_POST['password'] ?? ''), $hash)) $error = 'That password is not correct.';
        elseif (!isset(AW_RECOVERY_QUESTIONS[$q])) $error = 'Please choose a question.';
        elseif (mb_strlen($a) < 3) $error = 'Please give an answer of at least 3 letters or numbers.';
        elseif (aw_profile_save($db, $uid, ['recovery_question' => $q, 'recovery_answer' => password_hash($a, PASSWORD_DEFAULT)])) { $notice = 'Your recovery question is saved.'; if (function_exists('aw_audit')) aw_audit('recovery_question_set', 'user', $uid, ''); }
        else $error = 'That could not be saved. Please try again.';
    } elseif (!$is_team) {
        $error = 'Two-step sign-in is for the AngelWrites team.';
    } elseif (isset($_POST['begin']) || isset($_POST['disable'])) {
        if (!password_verify((string)($_POST['password'] ?? ''), $hash)) $error = 'That password is not correct.';
        elseif (isset($_POST['begin'])) { aw_2fa_begin($db, $uid); }
        elseif ((string)aw_setting('staff_2fa_required', '0') === '1') $error = 'Two-step sign-in is required for staff, so it cannot be turned off.';
        else { aw_2fa_disable($db, $uid); $notice = 'Two-step sign-in is off.'; }
    } elseif (isset($_POST['confirm'])) {
        $codes = aw_2fa_confirm($db, $uid, (string)($_POST['code'] ?? ''));
        if (!$codes) $error = 'That code did not match. Check that the time on your phone is set automatically, then try the newest code.';
    }
}
$row = $is_team ? aw_2fa_row($db, $uid) : null;
$rq = $db->prepare("SELECT recovery_question FROM users WHERE id = ?"); $rq->execute([$uid]); $my_rq = (string)$rq->fetchColumn();
$on = $row && !empty($row['enabled_at']);
$pending = $row && empty($row['enabled_at']);
$left = $on ? count(json_decode((string)$row['recovery'], true) ?: []) : 0;
$label = rawurlencode('AngelWrites:' . ($_SESSION['username'] ?? ('staff' . $uid)));
$uri = $pending ? 'otpauth://totp/' . $label . '?secret=' . $row['secret'] . '&issuer=AngelWrites&digits=6&period=30' : '';

$pageTitle = 'Account security';
require_once 'includes/header.php';
?>
<div class="container" style="max-width:640px;padding:36px 20px 80px;">
    <h1 style="font-family:'Playfair Display',Georgia,serif;">Account security</h1>
    <?php if (isset($_GET['required']) && !$on): ?><?php echo aw_alert('warning', 'The owner asks every staff member to use two-step sign-in. Please turn it on to continue to the staff pages.'); ?><?php endif; ?>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

    <div class="card" id="recovery" style="margin-bottom:20px;"><div class="card-body" style="padding:24px;">
        <h2 style="font-size:1.25rem;margin:0 0 6px;">Account recovery</h2>
        <p style="color:var(--text-light);">If you lose access to your email, your phone number and the answer to this question let you reset your password on the <a href="<?php echo SITE_URL; ?>/recover.php">recovery page</a>.</p>
        <p><strong><?php echo $my_rq !== '' && isset(AW_RECOVERY_QUESTIONS[$my_rq]) ? 'Your question: ' . htmlspecialchars(AW_RECOVERY_QUESTIONS[$my_rq]) : 'Not set yet.'; ?></strong></p>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <div class="form-group"><label for="rq">Question</label><select id="rq" name="recovery_question" required style="min-height:44px;max-width:100%;">
                <option value="">Choose a question</option>
                <?php foreach (AW_RECOVERY_QUESTIONS as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $my_rq === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label for="ra">Your answer</label><input type="text" id="ra" name="recovery_answer" required maxlength="100" autocomplete="off" style="max-width:320px;"><small class="field-hint" style="display:block">Capitals and spaces do not matter. It is stored scrambled; nobody can read it.</small></div>
            <?php if (!$via_social): ?><div class="form-group"><label for="pw0">Your password</label><input type="password" id="pw0" name="password" required autocomplete="current-password" style="max-width:320px;"></div><?php endif; ?>
            <?php echo aw_btn($my_rq !== '' ? 'Change recovery question' : 'Save recovery question', 'primary', ['type' => 'submit', 'name' => 'save_recovery', 'value' => '1', 'small' => true]); ?>
        </form>
    </div></div>

    <?php if ($is_team): ?>
    <div class="card"><div class="card-body" style="padding:24px;">
        <h2 style="font-size:1.25rem;margin:0 0 6px;">Two-step sign-in</h2>
        <p style="color:var(--text-light);">Team accounts can see private messages and members' details. With two-step sign-in, a stolen password alone is not enough: signing in also needs a code from an app on your phone (Google Authenticator, Microsoft Authenticator, Authy or similar).</p>

        <?php if ($codes): ?>
            <?php echo aw_alert('success', 'Two-step sign-in is on.'); ?>
            <h3 style="font-size:1.05rem;">Your recovery codes</h3>
            <p>If you lose your phone, each of these lets you sign in once. Write them down or print them now and keep them somewhere safe. They will not be shown again.</p>
            <pre style="font-size:1.05rem;line-height:1.8;background:var(--fantasy);padding:14px;border-radius:10px;columns:2;"><?php echo htmlspecialchars(implode("\n", $codes)); ?></pre>
            <p><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Continue to the staff dashboard', 'primary'); ?></p>
        <?php elseif ($pending): ?>
            <ol style="line-height:1.8;padding-left:20px;">
                <li>In your authenticator app, choose <strong>Add account</strong> → <strong>Enter a setup key</strong>.</li>
                <li>Account name: <strong>AngelWrites</strong>. Key: <code style="font-size:1.05rem;word-break:break-all;"><?php echo htmlspecialchars(trim(chunk_split($row['secret'], 4, ' '))); ?></code> (time based).</li>
                <li>Type the 6-digit code the app shows.</li>
            </ol>
            <p style="font-size:.88rem;color:var(--text-light);">On this phone you can also <a href="<?php echo htmlspecialchars($uri); ?>">open it in your authenticator app</a>.</p>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="form-group"><label for="code">Code from the app</label>
                    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required style="font-size:1.3rem;letter-spacing:.2em;max-width:200px;"></div>
                <?php echo aw_btn('Turn on', 'primary', ['type' => 'submit', 'name' => 'confirm', 'value' => '1']); ?>
            </form>
        <?php elseif ($on): ?>
            <p><strong>On</strong> since <?php echo htmlspecialchars(date('F j, Y', strtotime($row['enabled_at']))); ?>. <?php echo $left; ?> recovery code<?php echo $left === 1 ? '' : 's'; ?> left.</p>
            <form method="POST" autocomplete="off" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="form-group"><label for="pw1">Your password</label><input type="password" id="pw1" name="password" required autocomplete="current-password" style="max-width:320px;"></div>
                <?php echo aw_btn('Set up again (new key and codes)', 'outline', ['type' => 'submit', 'name' => 'begin', 'value' => '1', 'small' => true]); ?>
                <?php if ((string)aw_setting('staff_2fa_required', '0') !== '1'): ?><?php echo aw_btn('Turn off', 'outline', ['type' => 'submit', 'name' => 'disable', 'value' => '1', 'small' => true]); ?><?php endif; ?>
            </form>
        <?php else: ?>
            <p><strong>Off.</strong></p>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <div class="form-group"><label for="pw2">Your password</label><input type="password" id="pw2" name="password" required autocomplete="current-password" style="max-width:320px;"></div>
                <?php echo aw_btn('Set up two-step sign-in', 'primary', ['type' => 'submit', 'name' => 'begin', 'value' => '1']); ?>
            </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
<?php require_once 'includes/footer.php'; ?>
