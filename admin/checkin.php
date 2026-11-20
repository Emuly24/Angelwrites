<?php
/**
 * admin/checkin.php?id=N: a Care Team member answers their monthly check-in (includes/team_care.php).
 * Only their own check-in. The owner is told the answer; "step down" ends volunteer areas at once.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/mail_helper.php';
require_once '../includes/notifications.php';
require_once '../includes/team_care.php';

aw_require('staff_dashboard'); // the Care Team; checks the CSRF token on every POST

$me = (int)$_SESSION['user_id'];
$cid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$flash = null;
$c = null;
if (aw_care_tables($db)) {
    try { $st = $db->prepare("SELECT * FROM staff_checkins WHERE id = ? AND user_id = ?"); $st->execute([$cid, $me]); $c = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('checkin page: ' . $e->getMessage()); }
}
if ($c && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $flash = aw_checkin_answer($db, $cid, $me, (string)$_POST['answer'], (string)($_POST['note'] ?? ''));
    if ($flash['ok']) { $_SESSION['aw_ci_flash'] = $flash['msg']; header('Location: ' . SITE_URL . '/admin/checkin.php?id=' . $cid); exit; }
}
$done = (string)($_SESSION['aw_ci_flash'] ?? ''); unset($_SESSION['aw_ci_flash']);

$pageTitle = 'Your check-in';
require_once '../includes/header.php';
?>
<main class="ci-page" id="main">
    <?php if (!$c): ?>
        <h1>Check-in not found</h1><p>This check-in is not for your account. <a href="team.php">Back to your Team desk</a></p>
    <?php else: ?>
        <p class="ci-kicker"><i class="fas fa-heart" aria-hidden="true"></i> Monthly check-in</p>
        <h1>How is your role going?</h1>
        <?php if ($done !== ''): ?><div class="ci-ok" role="status"><?php echo htmlspecialchars($done); ?> <a href="team.php">Back to your Team desk</a></div><?php endif; ?>
        <?php if ($flash && !$flash['ok']): ?><div class="ci-err" role="alert"><?php echo htmlspecialchars($flash['msg']); ?></div><?php endif; ?>
        <?php if ($c['answered_at'] && $done === ''): ?>
            <p>You answered on <?php echo htmlspecialchars(date('j F Y', strtotime($c['answered_at'] . ' UTC'))); ?>: <strong><?php echo htmlspecialchars(AW_CHECKIN_ANSWERS[$c['answer']] ?? ''); ?></strong>. Thank you. You can change your answer below.</p>
        <?php elseif (!$c['answered_at']): ?>
            <p class="ci-lead">Thank you for serving the community. Serving should never become a weight, so every month we simply ask how you are. There is no wrong answer.</p>
        <?php endif; ?>
        <form method="POST" class="ci-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo $cid; ?>">
            <fieldset><legend class="sr-only">Your answer</legend>
                <?php foreach (AW_CHECKIN_ANSWERS as $k => $l): ?>
                    <label class="ci-opt"><input type="radio" name="answer" value="<?php echo $k; ?>" required<?php echo $c['answer'] === $k ? ' checked' : ''; ?>> <span><?php echo htmlspecialchars($l); ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <label for="ciNote" class="ci-label">Anything you would like the owner to know? <small>(optional)</small></label>
            <textarea id="ciNote" name="note" rows="4" maxlength="1000"><?php echo htmlspecialchars((string)$c['note']); ?></textarea>
            <p class="ci-hint">“Step down” ends your volunteer roles straight away. You are always welcome back.</p>
            <button type="submit" class="ci-btn">Send my answer</button>
        </form>
    <?php endif; ?>
</main>
<style>
.ci-page{max-width:640px;margin:0 auto;padding:36px 16px 64px;color:var(--text,#3d2e2e)}
.ci-kicker{margin:0;color:#9c5457;font-weight:600;text-transform:uppercase;letter-spacing:.04em;font-size:.85rem}
.ci-page h1{font-family:'Playfair Display',Georgia,serif;margin:4px 0 10px}
.ci-lead{font-size:1.05rem;line-height:1.6}
.ci-form fieldset{border:0;padding:0;margin:16px 0;display:grid;gap:10px}
.ci-opt{display:flex;gap:12px;align-items:center;min-height:52px;padding:10px 16px;border:1px solid var(--border,#eadede);border-radius:14px;background:var(--card-bg,#fff);cursor:pointer;font-weight:500}
.ci-opt:has(input:checked){border-color:#9c5457;box-shadow:0 0 0 2px rgba(156,84,87,.25)}
.ci-opt input{width:20px;height:20px}
.ci-label{display:block;font-weight:600;margin:6px 0}
.ci-form textarea{width:100%;box-sizing:border-box;border-radius:12px;border:1px solid var(--border,#eadede);padding:10px 12px;font:inherit;background:var(--card-bg,#fff);color:var(--text,#3d2e2e)}
.ci-hint{color:var(--text-light,#6b5757);font-size:.9rem}
.ci-btn{min-height:46px;padding:0 24px;border:0;border-radius:999px;background:#9c5457;color:#fff;font-weight:600;font-size:1rem;cursor:pointer}
.ci-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.ci-ok{padding:12px 16px;border-radius:12px;background:#eaf6ee;border:1px solid #9fd3ae;color:#1d5130;margin:0 0 14px}
.ci-err{padding:12px 16px;border-radius:12px;background:#fdecea;border:1px solid #f1aaa0;color:#7a2318;margin:0 0 14px}
[data-theme="dark"] .ci-page{color:#f3e6e6}[data-theme="dark"] .ci-opt,[data-theme="dark"] .ci-form textarea{background:#2a2122;border-color:#4a3739;color:#f3e6e6}
[data-theme="dark"] .ci-kicker{color:#f0b9ba}[data-theme="dark"] .ci-ok{background:#16301f;border-color:#2f6b43;color:#cdeed7}
</style>
<?php require_once '../includes/footer.php'; ?>
