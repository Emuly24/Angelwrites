<?php
/**
 * Training for the Care Team: read once before a role's pages open (aw_require() sends people here).
 * Safeguarding first, then what the role does and does not include, the rules for everyone, and three
 * short situations to answer. Owners and admins can read any role's training (to know what volunteers
 * are told) but never need to complete it. Completions are in the audit log.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

aw_require('staff_dashboard'); // anyone on the team; checks the CSRF token on every POST

$me = (int)$_SESSION['user_id'];
$pending = aw_training_pending();
$area = (string)($_GET['area'] ?? ($pending[0] ?? ''));
$is_lead = aw_can('manage_members');
if (!isset(AW_POSITIONS[$area])) $area = $pending[0] ?? 'moderator';
$mine = in_array($area, $pending, true) || aw_training_done($me, $area) && ($area === 'moderator' ? aw_my_role() === 'moderator' : in_array($area, aw_user_areas($me), true));
if (!$mine && !$is_lead) { header('Location: ' . SITE_URL . '/admin/team.php'); exit; }
$p = AW_POSITIONS[$area];
$wrong = []; $done_now = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish']) && in_array($area, $pending, true)) {
    foreach (AW_TRAINING_QUIZ as $i => $q) if ((int)($_POST['q' . $i] ?? -1) !== $q[2]) $wrong[] = $i;
    if (empty($_POST['agree'])) $wrong[] = 'agree';
    if (!$wrong && aw_training_complete($db, $me, $area)) {
        $next = array_values(array_diff(aw_training_pending(), [$area]));
        $first = $p['pages'][0][1] ?? 'admin/team.php';
        header('Location: ' . SITE_URL . '/' . ($next ? 'admin/training.php?area=' . rawurlencode($next[0]) : $first));
        exit;
    }
}

$pageTitle = 'Training: ' . $p['title'];
require_once '../includes/header.php';
?>
<main class="tr-page" id="main">
    <p class="tr-kicker"><i class="fas fa-graduation-cap" aria-hidden="true"></i> <?php echo htmlspecialchars(aw_team_name()); ?> training · about 10 minutes</p>
    <h1><?php echo htmlspecialchars($p['title']); ?></h1>
    <p class="tr-lead"><?php echo htmlspecialchars($p['summary']); ?>
        <?php if (in_array($area, $pending, true)): ?>Read this once; when you finish, <?php echo htmlspecialchars(implode(', ', array_column($p['pages'], 0))); ?> will open.<?php elseif ($is_lead && !$mine): ?>You are reading what volunteers in this role are shown.<?php else: ?>You have finished this training. It is here whenever you want to read it again.<?php endif; ?></p>

    <?php if ($wrong): ?><div class="tr-alert" role="alert">Not quite yet. Please look again at the <?php echo in_array('agree', $wrong, true) && count($wrong) === 1 ? 'promise at the end' : 'situations marked below'; ?>.</div><?php endif; ?>

    <section class="tr-card tr-safe">
        <h2><span aria-hidden="true">1</span> If someone may be at risk</h2>
        <ol><?php foreach (AW_SAFEGUARDING as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ol>
    </section>
    <section class="tr-card">
        <h2><span aria-hidden="true">2</span> Your role</h2>
        <div class="tr-cols">
            <div><h3><i class="fas fa-check" aria-hidden="true"></i> You will</h3><ul><?php foreach ($p['do'] as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul></div>
            <div><h3><i class="fas fa-ban" aria-hidden="true"></i> Your role does not include</h3><ul><?php foreach ($p['dont'] as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul></div>
        </div>
        <p class="tr-meta"><strong>Time:</strong> <?php echo htmlspecialchars($p['time']); ?>. <strong>Where:</strong> your Team desk and <?php echo htmlspecialchars(implode(', ', array_column($p['pages'], 0))); ?>.</p>
    </section>
    <section class="tr-card">
        <h2><span aria-hidden="true">3</span> For everyone on the team</h2>
        <ul><?php foreach (AW_STAFF_RULES as $x): ?><li><?php echo htmlspecialchars($x); ?></li><?php endforeach; ?></ul>
    </section>

    <?php if (in_array($area, $pending, true)): ?>
    <form method="POST" class="tr-card">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <h2><span aria-hidden="true">4</span> Three situations</h2>
        <?php foreach (AW_TRAINING_QUIZ as $i => [$q, $opts, $right, $why]): $bad = in_array($i, $wrong, true); ?>
            <fieldset class="tr-q<?php echo $bad ? ' is-wrong' : ''; ?>"<?php echo $bad ? ' aria-describedby="trw' . $i . '"' : ''; ?>>
                <legend><?php echo htmlspecialchars($q); ?></legend>
                <?php foreach ($opts as $k => $o): ?>
                    <label><input type="radio" name="q<?php echo $i; ?>" value="<?php echo $k; ?>" required<?php echo (string)($_POST['q' . $i] ?? '') === (string)$k ? ' checked' : ''; ?>> <?php echo htmlspecialchars($o); ?></label>
                <?php endforeach; ?>
                <?php if ($bad): ?><p class="tr-why" id="trw<?php echo $i; ?>"><?php echo htmlspecialchars($why); ?></p><?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        <label class="tr-agree<?php echo in_array('agree', $wrong, true) ? ' is-wrong' : ''; ?>"><input type="checkbox" name="agree" value="1" required> I have read this, I will follow it, and I will ask the owner when I am unsure.</label>
        <button type="submit" name="finish" value="1" class="tr-btn"><i class="fas fa-check" aria-hidden="true"></i> Finish training</button>
    </form>
    <?php endif; ?>
</main>
<style>
.tr-page{max-width:820px;margin:0 auto;padding:32px 16px 64px;color:var(--text,#3d2e2e)}
.tr-kicker{margin:0;color:#9c5457;font-weight:600;text-transform:uppercase;letter-spacing:.04em;font-size:.85rem}
.tr-page h1{font-family:'Playfair Display',Georgia,serif;margin:4px 0 8px}
.tr-lead{font-size:1.05rem;line-height:1.6}
.tr-card{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:18px;padding:22px 24px;margin:0 0 18px}
.tr-card h2{display:flex;align-items:center;gap:10px;font-size:1.15rem;margin:0 0 10px}
.tr-card h2 span{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#9c5457;color:#fff;font-size:.95rem}
.tr-safe{border-color:#e7b9a3;background:#fff8f3}
.tr-card ol,.tr-card ul{margin:0;padding-left:22px;line-height:1.7}
.tr-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.tr-cols h3{font-size:1rem;margin:0 0 6px}
.tr-meta{color:var(--text-light,#6b5757);margin:12px 0 0}
.tr-q{border:1px solid var(--border,#eadede);border-radius:12px;padding:10px 14px;margin:0 0 12px}
.tr-q legend{font-weight:600;padding:0 6px}
.tr-q label,.tr-agree{display:flex;gap:10px;align-items:flex-start;min-height:36px;padding:4px 0;cursor:pointer}
.tr-q input,.tr-agree input{margin-top:4px;width:18px;height:18px;flex:none}
.tr-q.is-wrong,.tr-agree.is-wrong{border-color:#a8432f;background:#fdf0ee}
.tr-why{margin:6px 0 0;color:#7a2318}
.tr-btn{display:inline-flex;align-items:center;gap:8px;min-height:46px;padding:0 24px;border:0;border-radius:999px;background:#9c5457;color:#fff;font-weight:600;font-size:1rem;cursor:pointer;margin-top:12px}
.tr-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.tr-alert{padding:12px 16px;border-radius:12px;background:#fdecea;border:1px solid #f1aaa0;color:#7a2318;margin:0 0 16px}
[data-theme="dark"] .tr-card{background:#2a2122;border-color:#4a3739}
[data-theme="dark"] .tr-safe{background:#33241c;border-color:#7a4f39}
[data-theme="dark"] .tr-page{color:#f3e6e6}
[data-theme="dark"] .tr-kicker{color:#f0b9ba}
[data-theme="dark"] .tr-q.is-wrong,[data-theme="dark"] .tr-agree.is-wrong{background:#3a1b17}
[data-theme="dark"] .tr-why,[data-theme="dark"] .tr-alert{color:#f6cfc8}
[data-theme="dark"] .tr-alert{background:#3a1b17;border-color:#7a3a30}
</style>
<?php require_once '../includes/footer.php'; ?>
