<?php
/**
 * Mentoring (includes/mentoring.php): ask for a mentor, offer to mentor, answer the team's proposals,
 * and see your mentoring at a glance. Conversations happen in AngelWrites messages.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/messaging.php';
require_once 'includes/mentoring.php';
if (is_file(__DIR__ . '/includes/notifications.php')) require_once 'includes/notifications.php';

if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/mentoring.php')); exit; }
$mt_uid = (int)$_SESSION['user_id'];
aw_mentor_tables($db);
$mt_self = SITE_URL . '/mentoring.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = ['ok' => false, 'error' => 'This page had been open a long time. Please try again.'];
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        switch ((string)($_POST['action'] ?? '')) {
            case 'request': $r = aw_mentor_request($db, $mt_uid, $_POST); if (!empty($r['ok'])) $r['message'] = 'Thank you. The team will look for the right person and let you know.'; break;
            case 'offer': $r = aw_mentor_offer($db, $mt_uid, $_POST); if (!empty($r['ok'])) $r['message'] = 'Thank you for offering to mentor! The team will be in touch.'; break;
            case 'accept': case 'decline': $r = aw_mentor_answer($db, $mt_uid, (int)$_POST['id'], $_POST['action'] === 'accept'); break;
            case 'close': $r = aw_mentor_close($db, (int)$_POST['id'], $mt_uid, (string)($_POST['note'] ?? '')) ? ['ok' => true, 'message' => 'Done. Thank you for the time you shared.'] : ['ok' => false, 'error' => 'That could not be done.']; break;
            case 'pause':
                $db->prepare("UPDATE mentors SET status = CASE status WHEN 'approved' THEN 'paused' WHEN 'paused' THEN 'approved' ELSE status END WHERE user_id = ?")->execute([$mt_uid]);
                $r = ['ok' => true, 'message' => 'Saved.']; break;
        }
    }
    $_SESSION['aw_mt_flash'] = [!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok']) ? ($r['message'] ?? 'Saved.') : ($r['error'] ?? 'That did not work.')];
    if (empty($r['ok'])) $_SESSION['aw_mt_form'] = $_POST;
    header('Location: ' . $mt_self); exit;
}
$mt_flash = $_SESSION['aw_mt_flash'] ?? null; unset($_SESSION['aw_mt_flash']);
$mt_form = $_SESSION['aw_mt_form'] ?? []; unset($_SESSION['aw_mt_form']);
$mt_req = aw_mentor_my_request($db, $mt_uid);
$mt_me = aw_mentor_get($db, $mt_uid);
$mt_token = generate_csrf_token();
$mt_mine = [];
if ($mt_me) {
    $st = $db->prepare("SELECT r.*, u.name, u.first_name, u.last_name, u.username FROM mentor_requests r JOIN users u ON u.id = r.user_id WHERE r.mentor_id = ? AND r.status IN ('proposed', 'active') ORDER BY r.status DESC, r.proposed_at");
    $st->execute([$mt_uid]);
    $mt_mine = $st->fetchAll(PDO::FETCH_ASSOC);
}
$mt_v = function ($k, $d = '') use ($mt_form) { return htmlspecialchars((string)($mt_form[$k] ?? $d)); };

$pageTitle = 'Mentoring';
require_once 'includes/header.php';
?>
<main class="mt">
    <header class="mt-hero">
        <p class="mt-kicker"><i class="fas fa-hands-holding-circle" aria-hidden="true"></i> Mentoring</p>
        <h1>Someone to walk alongside you</h1>
        <p class="mt-lead">Ask for a mentor, someone further along the road who will listen, pray and encourage you, or offer to be one. The team introduces you, and you talk safely in AngelWrites messages.</p>
    </header>
    <?php if ($mt_flash): ?><div class="mt-alert <?php echo $mt_flash[0] === 'ok' ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($mt_flash[1]); ?></div><?php endif; ?>

    <?php if ($mt_mine): ?>
    <section class="mt-card" aria-labelledby="mtMineT">
        <h2 id="mtMineT">People you walk with</h2>
        <?php foreach ($mt_mine as $r): ?>
        <div class="mt-pair">
            <div><strong><?php echo $r['status'] === 'proposed' ? 'A member would like a mentor' : htmlspecialchars(awDisplayName($r)); ?></strong>
                <small><?php echo htmlspecialchars(AW_MENTOR_AREAS[$r['area']] ?? ''); ?> · <?php echo htmlspecialchars(AW_MENTOR_FREQ[$r['frequency']] ?? ''); ?></small>
                <?php if ($r['status'] === 'proposed'): ?><p class="mt-hopes">“<?php echo nl2br(htmlspecialchars($r['hopes'])); ?>”</p><?php endif; ?></div>
            <form method="post" class="mt-acts"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mt_token); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <?php if ($r['status'] === 'proposed'): ?>
                    <button class="mt-btn mt-btn-primary" name="action" value="accept">Yes, I will mentor</button>
                    <button class="mt-btn" name="action" value="decline">Not this time</button>
                <?php else: ?>
                    <a class="mt-btn mt-btn-primary" href="<?php echo SITE_URL; ?>/messages.php<?php echo $r['thread_id'] ? '?t=' . (int)$r['thread_id'] : ''; ?>"><i class="fas fa-comments" aria-hidden="true"></i> Messages</a>
                    <button class="mt-btn" name="action" value="close" onclick="return confirm('End this mentoring? You can still message each other if you both wish.');">End</button>
                <?php endif; ?>
            </form>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="mt-grid">
        <section class="mt-card" aria-labelledby="mtAskT">
            <h2 id="mtAskT"><i class="fas fa-seedling" aria-hidden="true"></i> I would like a mentor</h2>
            <?php if ($mt_req): ?>
                <div class="mt-status is-<?php echo htmlspecialchars($mt_req['status']); ?>">
                    <?php if ($mt_req['status'] === 'active'): ?>
                        <p><strong><?php echo htmlspecialchars(awDisplayName($mt_req)); ?></strong> is walking alongside you (<?php echo htmlspecialchars(AW_MENTOR_AREAS[$mt_req['area']] ?? ''); ?>).</p>
                        <a class="mt-btn mt-btn-primary" href="<?php echo SITE_URL; ?>/messages.php<?php echo $mt_req['thread_id'] ? '?t=' . (int)$mt_req['thread_id'] : ''; ?>"><i class="fas fa-comments" aria-hidden="true"></i> Open your conversation</a>
                    <?php else: ?>
                        <p>Your request (<?php echo htmlspecialchars(AW_MENTOR_AREAS[$mt_req['area']] ?? ''); ?>) is with the team<?php echo $mt_req['status'] === 'proposed' ? ', and a mentor has been asked' : ''; ?>. We will let you know as soon as someone is ready.</p>
                    <?php endif; ?>
                    <form method="post" class="mt-end"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mt_token); ?>"><input type="hidden" name="id" value="<?php echo (int)$mt_req['id']; ?>">
                        <details><summary><?php echo $mt_req['status'] === 'active' ? 'End this mentoring' : 'Withdraw my request'; ?></summary>
                            <label>A word for the team (optional, only they see it)<textarea name="note" rows="2" maxlength="600" placeholder="Anything we should know, including any concern"></textarea></label>
                            <button class="mt-btn" name="action" value="close">Confirm</button></details>
                    </form>
                </div>
            <?php else: ?>
            <form method="post" class="mt-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mt_token); ?>"><input type="hidden" name="action" value="request">
                <label>What would you like support with?<select name="area" required><option value="">Choose…</option><?php foreach (AW_MENTOR_AREAS as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($mt_form['area'] ?? '') === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select></label>
                <label>What do you hope for?<textarea name="hopes" rows="4" maxlength="1500" required placeholder="A few sentences about where you are and what would help."><?php echo $mt_v('hopes'); ?></textarea></label>
                <label>How often would you like to talk?<select name="frequency"><?php foreach (AW_MENTOR_FREQ as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($mt_form['frequency'] ?? 'fortnight') === $k ? ' selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></label>
                <label>Anything that would help us choose? <small>(optional, for example “a woman mentor”, or a language)</small><input type="text" name="preference" maxlength="200" value="<?php echo $mt_v('preference'); ?>"></label>
                <label class="mt-check"><input type="checkbox" name="adult" value="1" required> <span>I am 18 or older.</span></label>
                <button class="mt-btn mt-btn-primary" type="submit">Ask for a mentor</button>
            </form>
            <?php endif; ?>
        </section>

        <section class="mt-card" aria-labelledby="mtOfferT">
            <h2 id="mtOfferT"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> I would like to mentor</h2>
            <?php if ($mt_me && $mt_me['status'] !== 'declined'): ?>
                <p class="mt-state"><?php echo ['pending' => 'Thank you! The team is reviewing your offer.', 'approved' => 'You are a mentor. The team will ask you when someone could use your help.', 'paused' => 'Your mentoring is paused: nobody new will be proposed to you.'][$mt_me['status']] ?? ''; ?></p>
                <?php if (in_array($mt_me['status'], ['approved', 'paused'], true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mt_token); ?>"><button class="mt-btn" name="action" value="pause"><?php echo $mt_me['status'] === 'paused' ? 'Ready again' : 'Pause for now'; ?></button></form><?php endif; ?>
                <details class="mt-edit"><summary>Change my offer</summary>
            <?php endif; ?>
            <?php $areas = $mt_me ? explode(',', (string)$mt_me['areas']) : (array)($mt_form['areas'] ?? []); ?>
            <form method="post" class="mt-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($mt_token); ?>"><input type="hidden" name="action" value="offer">
                <fieldset class="mt-areas"><legend>Where could you help?</legend><?php foreach (AW_MENTOR_AREAS as $k => $l): ?><label><input type="checkbox" name="areas[]" value="<?php echo $k; ?>"<?php echo in_array($k, $areas, true) ? ' checked' : ''; ?>> <?php echo htmlspecialchars($l); ?></label><?php endforeach; ?></fieldset>
                <label>A few words about you and your walk with God<textarea name="bio" rows="4" maxlength="1200" required><?php echo htmlspecialchars((string)($mt_me['bio'] ?? ($mt_form['bio'] ?? ''))); ?></textarea></label>
                <label>How many people could you walk with at once?<select name="max_mentees"><?php for ($i = 1; $i <= 5; $i++): ?><option<?php echo (int)($mt_me['max_mentees'] ?? 2) === $i ? ' selected' : ''; ?>><?php echo $i; ?></option><?php endfor; ?></select></label>
                <div class="mt-promise"><strong>Mentoring promises</strong><ul><li>I listen more than I speak, and never judge.</li><li>I keep what is shared private, and talk only in AngelWrites messages.</li><li>I do not ask for money, gifts or personal contact details.</li><li>I tell the team at once if someone may be in danger.</li></ul></div>
                <label class="mt-check"><input type="checkbox" name="adult" value="1" required> <span>I am 18 or older.</span></label>
                <label class="mt-check"><input type="checkbox" name="promise" value="1" required> <span>I agree to the mentoring promises.</span></label>
                <button class="mt-btn mt-btn-primary" type="submit"><?php echo $mt_me ? 'Save' : 'Offer to mentor'; ?></button>
            </form>
            <?php if ($mt_me && $mt_me['status'] !== 'declined'): ?></details><?php endif; ?>
        </section>
    </div>
    <p class="mt-safe"><i class="fas fa-shield-heart" aria-hidden="true"></i> Every conversation can be reported, and the team is always there: <a href="<?php echo SITE_URL; ?>/messages.php?view=team">write to the team</a>. If someone is in danger, call your local emergency number or find a helpline at <a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a>.</p>
</main>
<style>
.mt { --mt-rose: #9c5457; --mt-card: var(--card-bg, #fff); --mt-line: rgba(156, 84, 87, .16); --mt-mute: #6d5a5a; max-width: 1100px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .mt { --mt-rose: #f0c4c5; --mt-mute: #cdb8b8; --mt-line: rgba(240, 196, 197, .16); }
.mt-hero { padding: 30px 28px; border-radius: 28px; color: #fff; background: radial-gradient(100% 140% at 100% 0%, rgba(214, 168, 92, .4), transparent 55%), linear-gradient(140deg, #2f4a4a, #5a3d5c 55%, #9c5457); box-shadow: 0 20px 44px rgba(47, 74, 74, .25); }
.mt-kicker { margin: 0; text-transform: uppercase; letter-spacing: .12em; font-size: .78rem; font-weight: 700; opacity: .92; }
.mt-hero h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4.4vw, 2.5rem); color: #fff; } .mt-lead { margin: 0; max-width: 46em; line-height: 1.6; opacity: .95; }
.mt-alert { margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .mt-alert.is-ok { background: #e3efe6; color: #24452f; } .mt-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.mt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-top: 18px; align-items: start; }
.mt-card { background: var(--mt-card); border: 1px solid var(--mt-line); border-radius: 22px; padding: 22px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); margin-top: 18px; }
.mt-grid .mt-card { margin-top: 0; }
.mt-card h2 { margin: 0 0 14px; font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; display: flex; gap: 10px; align-items: center; } .mt-card h2 i { color: var(--mt-rose); }
.mt-form { display: grid; gap: 12px; } .mt-form label { display: grid; gap: 6px; font-weight: 600; font-size: .93rem; } .mt-form small { font-weight: 400; color: var(--mt-mute); }
.mt-form input[type=text], .mt-form textarea, .mt-form select { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.mt-check { display: flex !important; grid-template-columns: none; gap: 10px; align-items: flex-start; font-weight: 400 !important; cursor: pointer; } .mt-check input, .mt-areas input { accent-color: #9c5457; width: 18px; height: 18px; margin-top: 2px; }
.mt-areas { border: 0; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px; } .mt-areas legend { font-weight: 600; font-size: .93rem; margin-bottom: 8px; }
.mt-areas label { display: inline-flex !important; gap: 8px; align-items: center; padding: 8px 12px; border-radius: 999px; border: 1px solid var(--mt-line); font-weight: 500 !important; cursor: pointer; }
.mt-promise { padding: 12px 14px; border-radius: 14px; background: rgba(156, 84, 87, .06); font-size: .9rem; } .mt-promise ul { margin: 6px 0 0; padding-left: 20px; line-height: 1.6; }
.mt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--mt-rose); background: transparent; color: var(--mt-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; justify-self: start; }
.mt-btn:hover { background: var(--mt-rose); color: #fff; } html[data-theme="dark"] .mt-btn:hover { color: #2c1e1e; }
.mt-btn-primary { background: var(--mt-rose); color: #fff; } html[data-theme="dark"] .mt-btn-primary { color: #2c1e1e; }
.mt-pair { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: center; padding: 12px 0; border-top: 1px solid var(--mt-line); } .mt-pair:first-of-type { border-top: 0; }
.mt-pair small { display: block; color: var(--mt-mute); } .mt-hopes { margin: 8px 0 0; font-style: italic; max-width: 46em; line-height: 1.55; }
.mt-acts { display: flex; gap: 8px; flex-wrap: wrap; }
.mt-status p { margin: 0 0 12px; line-height: 1.6; } .mt-end { margin-top: 12px; } .mt-end summary, .mt-edit summary { cursor: pointer; color: var(--mt-rose); font-weight: 600; min-height: 40px; display: inline-flex; align-items: center; }
.mt-end label { display: grid; gap: 6px; margin: 8px 0; font-weight: 600; font-size: .9rem; } .mt-end textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; }
.mt-state { margin: 0 0 10px; line-height: 1.6; } .mt-edit { margin-top: 12px; } .mt-edit[open] summary { margin-bottom: 12px; }
.mt-safe { margin: 18px 0 0; color: var(--mt-mute); font-size: .9rem; line-height: 1.6; } .mt-safe a { color: var(--mt-rose); font-weight: 600; }
</style>
<?php require_once 'includes/footer.php'; ?>
