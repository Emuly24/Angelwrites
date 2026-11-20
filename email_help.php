<?php
/**
 * email_help.php: how to keep AngelWrites emails out of Junk or Spam, step by step for each email
 * service (includes/inbox_help.php). Open to everyone; members can say "Done" here too.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/mail_helper.php';
require_once 'includes/inbox_help.php';

$me = (int)($_SESSION['user_id'] ?? 0);
$flash = '';
if ($me && $_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $v = (string)($_POST['inbox_help'] ?? '');
    if (aw_inbox_help_mark($db, $me, $v)) {
        $back = (string)($_POST['back'] ?? '');
        if ($back === 'dashboard') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }
        $flash = $v === 'done' ? 'Thank you! Our emails should now reach your inbox.' : 'We will remind you in two weeks.';
    }
}
$addr = aw_inbox_address();
$pageTitle = 'Keep our emails out of Junk';
require_once 'includes/header.php';
?>
<main class="eh-page" id="main">
    <p class="eh-kicker"><i class="fas fa-envelope-open-text" aria-hidden="true"></i> A small favour</p>
    <h1>Please help our emails reach your inbox</h1>
    <p class="eh-lead">AngelWrites is a small, young site, so some mailboxes put our emails in <strong>Junk</strong> or <strong>Spam</strong> until they get to know us. That can mean missing a reply from the team, a password code, an event reminder or the newsletter.</p>
    <div class="eh-key">
        <p><strong>It takes one minute, once:</strong></p>
        <ol><li>Find an AngelWrites email in your Junk or Spam folder.</li><li>Mark it <strong>Not spam</strong> (or move it to your Inbox).</li><li>Add <strong class="eh-addr"><?php echo htmlspecialchars($addr); ?></strong> to your contacts.</li></ol>
        <button type="button" class="eh-btn eh-copy" data-copy="<?php echo htmlspecialchars($addr); ?>"><i class="fas fa-copy" aria-hidden="true"></i> Copy our address</button>
        <span class="eh-copied" role="status" aria-live="polite"></span>
    </div>
    <?php if ($flash): ?><p class="eh-flash" role="status"><?php echo htmlspecialchars($flash); ?></p><?php endif; ?>

    <h2>Step by step for your email</h2>
    <div class="eh-grid">
        <?php foreach (aw_inbox_help_steps() as [$name, $icon, $steps]): ?>
            <details class="eh-card"><summary><i class="<?php echo $icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($name); ?></summary>
                <ol><?php foreach ($steps as $s): ?><li><?php echo htmlspecialchars($s); ?></li><?php endforeach; ?></ol></details>
        <?php endforeach; ?>
    </div>
    <p class="eh-note">No email from us yet? That is fine: next time one arrives, look in Junk first. We never ask for your password by email.</p>

    <?php if ($me): ?>
    <form method="POST" class="eh-acts">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <button type="submit" name="inbox_help" value="done" class="eh-btn eh-primary"><i class="fas fa-check" aria-hidden="true"></i> I have done it</button>
        <button type="submit" name="inbox_help" value="later" class="eh-btn">Remind me later</button>
    </form>
    <?php endif; ?>
</main>
<style>
.eh-page{max-width:820px;margin:0 auto;padding:36px 16px 64px;color:var(--text,#3d2e2e)}
.eh-kicker{margin:0;color:#9c5457;font-weight:700;letter-spacing:.05em;text-transform:uppercase;font-size:.85rem}
.eh-page h1{font-family:'Playfair Display',Georgia,serif;margin:4px 0 10px}.eh-page h2{font-size:1.2rem;margin:26px 0 10px}
.eh-lead{font-size:1.06rem;line-height:1.65}
.eh-key{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-left:5px solid #9c5457;border-radius:16px;padding:16px 20px;margin:16px 0}
.eh-key ol{margin:6px 0 12px;padding-left:22px;line-height:1.8}.eh-addr{word-break:break-all}
.eh-grid{display:grid;gap:10px}
.eh-card{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:14px;padding:4px 16px}
.eh-card summary{cursor:pointer;font-weight:600;min-height:48px;display:flex;align-items:center;gap:10px}
.eh-card ol{margin:0 0 12px;padding-left:22px;line-height:1.7}
.eh-btn{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 18px;border-radius:999px;border:1px solid #9c5457;background:transparent;color:#9c5457;font:inherit;font-weight:600;cursor:pointer}
.eh-primary{background:#9c5457;color:#fff}.eh-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.eh-acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.eh-copied{margin-left:8px;color:#2f7a4a;font-weight:600}
.eh-note{color:var(--text-light,#6b5757)}.eh-flash{padding:12px 16px;border-radius:12px;background:#eaf6ee;color:#1d5130}
[data-theme="dark"] .eh-page{color:#f3e6e6}[data-theme="dark"] .eh-key,[data-theme="dark"] .eh-card{background:#2a2122;border-color:#4a3739}
[data-theme="dark"] .eh-kicker{color:#f0b9ba}[data-theme="dark"] .eh-btn{border-color:#f0b9ba;color:#f0b9ba}[data-theme="dark"] .eh-primary{background:#c97c7f;color:#1f1516}
</style>
<script>
document.querySelectorAll('.eh-copy').forEach(function (b) { b.addEventListener('click', function () {
  var t = b.getAttribute('data-copy'), out = b.parentNode.querySelector('.eh-copied');
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { out.textContent = 'Copied'; }).catch(function () { out.textContent = t; });
}); });
</script>
<?php require_once 'includes/footer.php'; ?>
