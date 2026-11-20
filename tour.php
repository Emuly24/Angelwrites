<?php
/**
 * tour.php: turn guided tours on and off (includes/tours.php).
 *   GET              the "Guided tours" page: every tour this person may take, with Start, Resume,
 *                    Start again and Turn off.
 *   POST (form)      start | resume | restart | off  (tour=…, optional step=…), then go to that step.
 *   POST (from the card, JSON)  step | off | done
 * Every POST needs this session's CSRF token.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/tours.php';

redirectIfNotLoggedIn();
$me = (int)$_SESSION['user_id'];
$mine = aw_tours_for_me();
$json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $key = (string)($_POST['tour'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $ok = validate_csrf_token($tok) && in_array($key, $mine, true);
    $steps = $ok ? aw_tour_steps($key) : [];
    $url = '';
    if ($ok) {
        $n = count($steps);
        $step = max(0, min($n - 1, (int)($_POST['step'] ?? 0)));
        if ($action === 'start' || $action === 'restart') { aw_tour_set($db, $me, $key, 'on', $action === 'restart' ? 0 : $step); $url = aw_tour_url($steps[$action === 'restart' ? 0 : $step]); }
        elseif ($action === 'resume') { $cur = aw_tour_get($db, $me, $key); $s = min($n - 1, (int)($cur['step'] ?? 0)); aw_tour_set($db, $me, $key, 'on', $s); $url = aw_tour_url($steps[$s]); }
        elseif ($action === 'step') { aw_tour_set($db, $me, $key, 'on', $step); $url = aw_tour_url($steps[$step]); }
        elseif ($action === 'off') aw_tour_set($db, $me, $key, 'off');
        elseif ($action === 'done') aw_tour_set($db, $me, $key, 'done', 0);
        else $ok = false;
    }
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok, 'url' => $url]); exit; }
    if (!$ok) $_SESSION['aw_tour_flash'] = 'That did not work. Please try again.';
    header('Location: ' . ($url !== '' ? $url : SITE_URL . '/tour.php'));
    exit;
}

$flash = (string)($_SESSION['aw_tour_flash'] ?? ''); unset($_SESSION['aw_tour_flash']);
$pageTitle = 'Guided tours';
require_once 'includes/header.php';
?>
<main class="gt-page" id="main">
    <h1>Guided tours</h1>
    <p class="gt-lead">A tour moves from page to page and points at each part, with a short explanation. Start, pause or turn one off whenever you like; it remembers where you were.</p>
    <?php if ($flash): ?><p class="gt-flash" role="alert"><?php echo htmlspecialchars($flash); ?></p><?php endif; ?>
    <?php foreach ($mine as $key): $t = AW_TOURS[$key]; $s = aw_tour_get($db, $me, $key); $n = count(aw_tour_steps($key)); $status = $s['status'] ?? ''; $at = min($n, (int)($s['step'] ?? 0) + 1); ?>
        <section class="gt-card" aria-labelledby="gt_<?php echo $key; ?>">
            <h2 id="gt_<?php echo $key; ?>"><?php echo htmlspecialchars($t['title']); ?></h2>
            <p><?php echo htmlspecialchars($t['about']); ?> <span class="gt-meta"><?php echo $n; ?> steps · for <?php echo htmlspecialchars($t['who']); ?></span></p>
            <p class="gt-state"><?php echo $status === 'on' ? 'On, at step ' . $at . ' of ' . $n . '.' : ($status === 'done' ? 'Finished.' : ($status === 'off' ? 'Turned off at step ' . $at . '.' : 'Not started.')); ?></p>
            <form method="POST" class="gt-acts">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="hidden" name="tour" value="<?php echo $key; ?>">
                <?php if ($status === 'on' || $status === 'off'): ?>
                    <button type="submit" name="action" value="resume" class="gt-btn gt-primary"><?php echo $status === 'on' ? 'Continue' : 'Turn on again'; ?></button>
                    <button type="submit" name="action" value="restart" class="gt-btn">Start from the beginning</button>
                <?php else: ?>
                    <button type="submit" name="action" value="restart" class="gt-btn gt-primary"><?php echo $status === 'done' ? 'Take it again' : 'Start'; ?></button>
                <?php endif; ?>
                <?php if ($status === 'on'): ?><button type="submit" name="action" value="off" class="gt-btn">Turn off</button><?php endif; ?>
            </form>
            <?php if ($key === 'admin'): ?><p class="gt-meta"><a href="<?php echo SITE_URL; ?>/admin/handbook.php">Read it as the Founder's handbook</a> (every page explained, searchable and printable).</p><?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
<style>
.gt-page{max-width:760px;margin:0 auto;padding:36px 16px 64px;color:var(--text,#3d2e2e)}
.gt-page h1{font-family:'Playfair Display',Georgia,serif;margin:0 0 8px}.gt-lead{font-size:1.05rem;line-height:1.6}
.gt-card{background:var(--card-bg,#fff);border:1px solid var(--border,#eadede);border-radius:18px;padding:20px 22px;margin:0 0 16px}
.gt-card h2{margin:0 0 6px;font-size:1.2rem}.gt-meta{color:var(--text-light,#6b5757);font-size:.9rem}.gt-state{font-weight:600}
.gt-acts{display:flex;gap:10px;flex-wrap:wrap}.gt-btn{min-height:44px;padding:0 18px;border-radius:999px;border:1px solid #9c5457;background:transparent;color:#9c5457;font:inherit;font-weight:600;cursor:pointer}
.gt-primary{background:#9c5457;color:#fff}.gt-btn:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}
.gt-flash{padding:10px 14px;border-radius:12px;background:#fdecea;color:#7a2318}
[data-theme="dark"] .gt-page{color:#f3e6e6}[data-theme="dark"] .gt-card{background:#2a2122;border-color:#4a3739}[data-theme="dark"] .gt-btn{border-color:#f0b9ba;color:#f0b9ba}[data-theme="dark"] .gt-primary{background:#c97c7f;color:#1f1516}
</style>
<?php require_once 'includes/footer.php'; ?>
