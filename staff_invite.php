<?php
/**
 * staff_invite.php?id=N: the invited member reads the invitation (what the role involves and what it
 * does not) and accepts or declines. Only the invited member can open it. Nothing changes until they
 * answer. See includes/staff_positions.php.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/mail_helper.php';
require_once 'includes/notifications.php';
require_once 'includes/staff_positions.php';

redirectIfNotLoggedIn();
$si_me = (int)$_SESSION['user_id'];
$si_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$si_flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $si_flash = ['ok' => false, 'msg' => 'Your session expired, so nothing was changed. Please try again.'];
    } elseif (isset($_POST['accept']) || isset($_POST['decline'])) {
        $si_flash = aw_invite_answer($db, $si_id, $si_me, isset($_POST['accept']));
        if ($si_flash['ok'] && isset($_POST['accept'])) { header('Location: ' . SITE_URL . '/admin/team.php'); exit; } // the Care Team tour starts there
        $_SESSION['aw_si_flash'] = $si_flash;
        header('Location: ' . SITE_URL . '/staff_invite.php?id=' . $si_id);
        exit;
    } elseif (isset($_POST['step_down'])) {
        // a volunteer steps down from an area they hold
        $area = (string)($_POST['area'] ?? '');
        if (in_array($area, aw_user_areas($si_me), true) && aw_area_revoke($db, $si_me, $area)) {
            $_SESSION['aw_si_flash'] = ['ok' => true, 'msg' => 'You have stepped down as ' . AW_POSITIONS[$area]['title'] . '. Thank you for all your help.'];
        } else {
            $_SESSION['aw_si_flash'] = ['ok' => false, 'msg' => 'That could not be changed. Please try again.'];
        }
        header('Location: ' . SITE_URL . '/staff_invite.php');
        exit;
    }
}
if (!empty($_SESSION['aw_si_flash'])) { $si_flash = $_SESSION['aw_si_flash']; unset($_SESSION['aw_si_flash']); }

$si_inv = $si_id ? aw_invite_get($db, $si_id) : null;
if ($si_inv && (int)$si_inv['user_id'] !== $si_me) $si_inv = null; // someone else's invitation: as if it did not exist
$si_pos = $si_inv ? (AW_POSITIONS[$si_inv['position']] ?? null) : null;
$si_from = $si_inv ? awDisplayName(aw_staff_user_row($db, (int)$si_inv['invited_by'])) : '';
$si_areas = aw_user_areas($si_me);

$pageTitle = $si_pos ? 'Invitation: ' . $si_pos['title'] : 'Your team roles';
$aw_noindex = true;
require_once 'includes/header.php';
?>
<main class="si-page" id="main">
    <?php if ($si_flash): ?><div class="si-alert <?php echo $si_flash['ok'] ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($si_flash['msg']); ?></div><?php endif; ?>

    <?php if ($si_inv && $si_pos): $expired = aw_invite_expired($si_inv); ?>
        <article class="si-card">
            <header class="si-head">
                <span class="si-ico" aria-hidden="true"><i class="fas <?php echo $si_pos['icon']; ?>"></i></span>
                <div>
                    <p class="si-kicker">Invitation to the AngelWrites team</p>
                    <h1><?php echo htmlspecialchars($si_pos['title']); ?></h1>
                    <p class="si-from">From <?php echo htmlspecialchars($si_from); ?> · <?php echo htmlspecialchars(date('j F Y', strtotime($si_inv['created_at'] . ' UTC'))); ?></p>
                </div>
            </header>
            <p class="si-summary"><?php echo htmlspecialchars($si_pos['summary']); ?></p>
            <?php if (trim((string)$si_inv['note']) !== ''): ?>
                <blockquote class="si-note"><?php echo nl2br(htmlspecialchars((string)$si_inv['note'])); ?><footer>— <?php echo htmlspecialchars($si_from); ?></footer></blockquote>
            <?php endif; ?>
            <div class="si-cols">
                <section><h2><i class="fas fa-check" aria-hidden="true"></i> What you would do</h2><ul><?php foreach ($si_pos['do'] as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul></section>
                <section><h2><i class="fas fa-ban" aria-hidden="true"></i> What this role does not include</h2><ul><?php foreach ($si_pos['dont'] as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul></section>
            </div>
            <section class="si-rules"><h2>For everyone on the team</h2><ul><?php foreach (AW_STAFF_RULES as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul></section>
            <p class="si-meta"><strong>Time:</strong> <?php echo htmlspecialchars($si_pos['time']); ?>. <strong>Where:</strong> your Team desk and <?php echo htmlspecialchars(implode(', ', array_column($si_pos['pages'], 0))); ?>.</p>

            <?php if ($si_inv['status'] === 'pending' && !$expired): ?>
                <p class="si-meta">This is a volunteer role and saying no is completely fine. Nothing changes until you answer.</p>
                <form method="POST" class="si-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$si_inv['id']; ?>">
                    <button type="submit" name="accept" value="1" class="si-btn si-btn-primary"><i class="fas fa-handshake" aria-hidden="true"></i> Accept and join the team</button>
                    <button type="submit" name="decline" value="1" class="si-btn"><i class="fas fa-xmark" aria-hidden="true"></i> No, thank you</button>
                </form>
            <?php else: ?>
                <p class="si-state"><?php
                    if ($si_inv['status'] === 'accepted') echo 'You accepted this invitation. <a href="' . SITE_URL . '/admin/team.php">Open your Team desk</a>';
                    elseif ($si_inv['status'] === 'declined') echo 'You declined this invitation.';
                    elseif ($si_inv['status'] === 'withdrawn') echo 'This invitation was withdrawn.';
                    else echo 'This invitation has expired. Ask the owner if you would still like to help.';
                ?></p>
            <?php endif; ?>
        </article>
    <?php elseif ($si_id): ?>
        <article class="si-card"><h1>Invitation not found</h1><p>This invitation is not for this account, or it no longer exists. <a href="<?php echo SITE_URL; ?>/dashboard.php">Back to your dashboard</a></p></article>
    <?php endif; ?>

    <?php if ($si_areas): ?>
        <section class="si-card" aria-labelledby="siMine">
            <h2 id="siMine">Your volunteer roles</h2>
            <p>Thank you for helping. If you need to stop, you can step down here at any time.</p>
            <ul class="si-mine">
                <?php foreach ($si_areas as $a): ?>
                    <li><span><i class="fas <?php echo AW_POSITIONS[$a]['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars(AW_POSITIONS[$a]['title']); ?></span>
                        <form method="POST" onsubmit="return confirm('Step down as <?php echo htmlspecialchars(addslashes(AW_POSITIONS[$a]['title']), ENT_QUOTES); ?>?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="area" value="<?php echo htmlspecialchars($a); ?>">
                            <button type="submit" name="step_down" value="1" class="si-btn si-btn-sm">Step down</button>
                        </form></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php elseif (!$si_id): ?>
        <article class="si-card"><h1>Your team roles</h1><p>You do not have a volunteer role at the moment. <a href="<?php echo SITE_URL; ?>/dashboard.php">Back to your dashboard</a></p></article>
    <?php endif; ?>
</main>
<style>
.si-page{max-width:820px;margin:0 auto;padding:32px 16px 64px;color:var(--text,#3d2e2e)}
.si-card{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:20px;padding:28px;margin:0 0 20px;box-shadow:0 6px 24px rgba(61,46,46,.06)}
.si-head{display:flex;gap:16px;align-items:center;margin:0 0 14px}
.si-ico{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:#9c5457;color:#fff;font-size:1.4rem;flex:none}
.si-kicker{margin:0;font-size:.85rem;letter-spacing:.04em;text-transform:uppercase;color:#9c5457;font-weight:600}
.si-head h1{margin:2px 0;font-family:'Playfair Display',Georgia,serif;font-size:1.7rem}
.si-from{margin:0;color:var(--text-light,#6b5757);font-size:.92rem}
.si-summary{font-size:1.08rem;line-height:1.6}
.si-note{margin:16px 0;padding:14px 18px;border-left:4px solid #DBA1A2;background:rgba(219,161,162,.12);border-radius:0 12px 12px 0;font-style:italic}
.si-note footer{font-style:normal;margin-top:6px;color:var(--text-light,#6b5757)}
.si-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:18px 0}
.si-cols section,.si-rules{background:rgba(219,161,162,.08);border-radius:14px;padding:16px 18px}
.si-cols h2,.si-rules h2,.si-card > h2{font-size:1.02rem;margin:0 0 8px;font-family:inherit}
.si-cols section:first-child h2 i{color:#2f7a4a}.si-cols section:last-child h2 i{color:#a8432f}
.si-cols ul,.si-rules ul{margin:0;padding-left:20px;line-height:1.6}
.si-meta{color:var(--text-light,#6b5757);line-height:1.6}
.si-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
.si-btn{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:10px 20px;border-radius:999px;border:1px solid #9c5457;background:transparent;color:#9c5457;font-weight:600;font-size:1rem;cursor:pointer}
.si-btn-primary{background:#9c5457;color:#fff}
.si-btn-sm{min-height:36px;padding:6px 14px;font-size:.9rem}
.si-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.si-state{font-weight:600;margin-top:16px}
.si-alert{padding:12px 16px;border-radius:12px;margin:0 0 16px;border:1px solid}
.si-alert.is-ok{background:#eaf6ee;border-color:#9fd3ae;color:#1d5130}.si-alert.is-error{background:#fdecea;border-color:#f1aaa0;color:#7a2318}
.si-mine{list-style:none;padding:0;margin:12px 0 0}.si-mine li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--border,#eadede)}
[data-theme="dark"] .si-card{background:#2a2122;border-color:#4a3739;box-shadow:none}
[data-theme="dark"] .si-page{color:#f3e6e6}
[data-theme="dark"] .si-kicker,[data-theme="dark"] .si-btn{color:#f0b9ba;border-color:#f0b9ba}
[data-theme="dark"] .si-btn-primary{background:#c97c7f;border-color:#c97c7f;color:#1f1516}
[data-theme="dark"] .si-from,[data-theme="dark"] .si-meta,[data-theme="dark"] .si-note footer{color:#d8c3c3}
[data-theme="dark"] .si-alert.is-ok{background:#16301f;border-color:#2f6b43;color:#cdeed7}
[data-theme="dark"] .si-alert.is-error{background:#3a1b17;border-color:#7a3a30;color:#f6cfc8}
@media (max-width:600px){.si-card{padding:20px}.si-head h1{font-size:1.4rem}.si-actions .si-btn{width:100%;justify-content:center}}
</style>
<?php require_once 'includes/footer.php'; ?>
