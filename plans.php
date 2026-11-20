<?php
/**
 * Devotionals (includes/devotionals.php): choose a plan, read a day, think about the question,
 * pray, and mark the day as read. Progress is private; there are no streaks.
 *   plans.php                 the plans
 *   plans.php?plan=3&day=2    one day (members; guests see day 1 of a plan as a taste)
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/devotionals.php';
require_once 'includes/bible_versions.php';

$dv_uid = (int)($_SESSION['user_id'] ?? 0);
$dv_self = SITE_URL . '/plans.php';
aw_devo_tables($db);
$dv_team = function_exists('aw_can') && aw_can('manage_content'); // the team may preview drafts

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dv_uid && validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $pid = (int)($_POST['plan'] ?? 0); $day = (int)($_POST['day'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $plan = aw_devo_plan($db, $pid);
    if ($plan && ($plan['status'] === 'published' || $dv_team)) {
        if ($act === 'start') { aw_devo_start($db, $dv_uid, $pid); header('Location: ' . $dv_self . '?plan=' . $pid . '&day=1'); exit; }
        if ($act === 'leave') { aw_devo_leave($db, $dv_uid, $pid); header('Location: ' . $dv_self); exit; }
        if ($act === 'done' || $act === 'undo') {
            aw_devo_mark($db, $dv_uid, $pid, $day, $act === 'done');
            $next = $act === 'done' ? aw_devo_next_day($plan, aw_devo_done($db, $dv_uid, $pid)) : $day;
            $all = count(aw_devo_done($db, $dv_uid, $pid)) >= count($plan['days']);
            header('Location: ' . $dv_self . '?plan=' . $pid . '&day=' . ($all && $act === 'done' ? $day . '&finished=1' : $next) . '&v=' . urlencode((string)($_POST['v'] ?? '')));
            exit;
        }
    }
    header('Location: ' . $dv_self); exit;
}

$dv_v = (string)($_GET['v'] ?? ($_COOKIE['aw_bible_v'] ?? 'KJV'));
if (!isset(aw_bible_versions()[$dv_v])) $dv_v = (string)(array_key_first(aw_bible_versions()) ?? 'KJV');
$dv_plan = isset($_GET['plan']) ? aw_devo_plan($db, (int)$_GET['plan']) : null;
if ($dv_plan && $dv_plan['status'] !== 'published' && !$dv_team) $dv_plan = null;

$pageTitle = $dv_plan ? $dv_plan['title'] : 'Devotionals';
require_once 'includes/header.php';
?>
<main class="dv">
<?php if (!$dv_plan): $plans = aw_devo_plans($db, $dv_uid, $dv_team); ?>
    <header class="dv-hero">
        <p class="dv-kicker"><i class="fas fa-sun" aria-hidden="true"></i> Devotionals</p>
        <h1>A few minutes with God each day</h1>
        <p class="dv-lead">Choose a plan. Each day brings a short Bible passage, a few thoughts, a question to sit with, and a prayer. Go at your own pace: there are no streaks to keep, and a missed day simply waits for you.</p>
    </header>
    <?php if (!$plans): ?>
        <div class="dv-empty"><i class="fas fa-seedling" aria-hidden="true"></i><p>New devotionals are being prepared. Please come back soon.</p></div>
    <?php else: ?>
    <div class="dv-plans">
        <?php foreach ($plans as $p): $pal = AW_DEVO_PALETTES[(int)$p['palette'] % count(AW_DEVO_PALETTES)]; $started = !empty($p['started_at']); $pct = $p['days'] ? round($p['done'] / $p['days'] * 100) : 0; ?>
        <article class="dv-plan" style="--a:<?php echo $pal[0]; ?>;--b:<?php echo $pal[1]; ?>">
            <div class="dv-plan-top"><span><?php echo (int)$p['days']; ?> days</span><?php if ($p['status'] !== 'published'): ?><span class="dv-draft">Draft · only the team sees it</span><?php endif; ?></div>
            <h2><?php echo htmlspecialchars($p['title']); ?></h2>
            <p><?php echo htmlspecialchars((string)$p['summary']); ?></p>
            <?php if ($started): ?>
                <div class="dv-prog" role="progressbar" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Read"><span style="width:<?php echo $pct; ?>%"></span></div>
                <p class="dv-prog-l"><?php echo !empty($p['finished_at']) ? 'Finished · well done' : (int)$p['done'] . ' of ' . (int)$p['days'] . ' days read'; ?></p>
            <?php endif; ?>
            <a class="dv-btn dv-btn-light" href="<?php echo $dv_self; ?>?plan=<?php echo (int)$p['id']; ?>"><?php echo $started ? (!empty($p['finished_at']) ? 'Read again' : 'Continue') : 'Begin'; ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($dv_team): ?><p class="dv-team"><a href="<?php echo SITE_URL; ?>/admin/devotionals.php"><i class="fas fa-pen" aria-hidden="true"></i> Write or edit devotionals</a></p><?php endif; ?>

<?php else:
    $done = $dv_uid ? aw_devo_done($db, $dv_uid, (int)$dv_plan['id']) : [];
    $started = $dv_uid && (bool)$db->query("SELECT 1 FROM devo_progress WHERE user_id = " . $dv_uid . " AND plan_id = " . (int)$dv_plan['id'])->fetchColumn();
    $dayNo = isset($_GET['day']) ? (int)$_GET['day'] : ($started ? aw_devo_next_day($dv_plan, $done) : 1);
    if (!$dv_uid) $dayNo = 1; // guests: a taste of the first day
    $day = null; foreach ($dv_plan['days'] as $d) if ((int)$d['day_no'] === $dayNo) $day = $d;
    if (!$day && $dv_plan['days']) { $day = $dv_plan['days'][0]; $dayNo = (int)$day['day_no']; }
    $pal = AW_DEVO_PALETTES[(int)$dv_plan['palette'] % count(AW_DEVO_PALETTES)];
    $verses = [];
    if ($day && $day['book'] && $day['chapter']) {
        $ch = aw_bible_chapter($dv_v, $day['book'], (int)$day['chapter']);
        if ($ch) foreach ($ch['verses'] as $vv) if ((!$day['verse_from'] || $vv['v'] >= (int)$day['verse_from']) && (!$day['verse_to'] || $vv['v'] <= (int)$day['verse_to'])) $verses[] = $vv;
    }
    $isDone = !empty($done[$dayNo]);
    $token = $dv_uid ? generate_csrf_token() : '';
    $vinfo = aw_bible_versions()[$dv_v] ?? ['short' => $dv_v, 'lang' => 'en'];
?>
    <header class="dv-dayhead" style="--a:<?php echo $pal[0]; ?>;--b:<?php echo $pal[1]; ?>">
        <a class="dv-back" href="<?php echo $dv_self; ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> All devotionals</a>
        <p class="dv-kicker"><?php echo htmlspecialchars($dv_plan['title']); ?> · Day <?php echo $dayNo; ?> of <?php echo count($dv_plan['days']); ?></p>
        <h1><?php echo htmlspecialchars((string)($day['title'] ?? '')); ?></h1>
        <?php if ($dv_uid): ?>
        <nav class="dv-days" aria-label="Days">
            <?php foreach ($dv_plan['days'] as $d): $n = (int)$d['day_no']; ?>
            <a href="<?php echo $dv_self; ?>?plan=<?php echo (int)$dv_plan['id']; ?>&amp;day=<?php echo $n; ?>&amp;v=<?php echo urlencode($dv_v); ?>" class="<?php echo $n === $dayNo ? 'is-on' : ''; ?><?php echo !empty($done[$n]) ? ' is-done' : ''; ?>" aria-label="Day <?php echo $n; ?><?php echo !empty($done[$n]) ? ', read' : ''; ?>"<?php echo $n === $dayNo ? ' aria-current="page"' : ''; ?>><?php echo !empty($done[$n]) ? '<i class="fas fa-check" aria-hidden="true"></i>' : $n; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </header>

    <?php if (isset($_GET['finished'])): ?><div class="dv-alert" role="status"><i class="fas fa-champagne-glasses" aria-hidden="true"></i> You have read every day of <strong><?php echo htmlspecialchars($dv_plan['title']); ?></strong>. May what you read stay with you.</div><?php endif; ?>

    <div class="dv-day">
        <?php if ($day && aw_devo_ref($day) !== ''): ?>
        <section class="dv-card dv-scripture" aria-labelledby="dvRef">
            <div class="dv-ref-row">
                <h2 id="dvRef"><i class="fas fa-book-bible" aria-hidden="true"></i> <?php echo htmlspecialchars(aw_devo_ref($day)); ?></h2>
                <?php if (count(aw_bible_versions()) > 1): ?>
                <form method="get" class="dv-ver"><input type="hidden" name="plan" value="<?php echo (int)$dv_plan['id']; ?>"><input type="hidden" name="day" value="<?php echo $dayNo; ?>">
                    <label class="sr-only" for="dvV">Bible version</label>
                    <select id="dvV" name="v" onchange="document.cookie='aw_bible_v='+this.value+';path=/;max-age=31536000;samesite=lax';this.form.submit()"><?php foreach (aw_bible_versions() as $vc => $vv): ?><option value="<?php echo htmlspecialchars($vc); ?>"<?php echo $vc === $dv_v ? ' selected' : ''; ?>><?php echo htmlspecialchars($vv['short']); ?></option><?php endforeach; ?></select></form>
                <?php endif; ?>
            </div>
            <div class="dv-verses" lang="<?php echo htmlspecialchars((string)($vinfo['lang'] ?? 'en')); ?>">
                <?php if (!$verses): ?><p class="dv-mute">This passage is not in the <?php echo htmlspecialchars($vinfo['short'] ?? $dv_v); ?> version. Try another version above.</p><?php endif; ?>
                <?php foreach ($verses as $vv): ?><span class="dv-v"><sup aria-hidden="true"><?php echo (int)$vv['v']; ?></sup><?php echo nl2br(htmlspecialchars($vv['text']), false); ?></span> <?php endforeach; ?>
            </div>
            <a class="dv-link" href="<?php echo SITE_URL; ?>/bible_reader.php?v=<?php echo urlencode($dv_v); ?>&amp;b=<?php echo urlencode((string)$day['book']); ?>&amp;c=<?php echo (int)$day['chapter']; ?><?php echo $day['verse_from'] ? '&amp;vs=' . (int)$day['verse_from'] : ''; ?>">Read the whole chapter <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </section>
        <?php endif; ?>

        <?php if ($day && trim((string)$day['reflection']) !== ''): ?>
        <section class="dv-card dv-reflect"><h2>Reflect</h2><div class="dv-text"><?php echo nl2br(htmlspecialchars((string)$day['reflection'])); ?></div></section>
        <?php endif; ?>

        <?php if ($day && trim((string)$day['question']) !== ''): ?>
        <section class="dv-card dv-question"><h2>Sit with this</h2><p class="dv-q"><?php echo htmlspecialchars((string)$day['question']); ?></p>
            <?php if ($dv_uid): ?><a class="dv-btn" href="<?php echo SITE_URL; ?>/journal.php?new=1&amp;prompt=<?php echo urlencode((string)$day['question']); ?>"><i class="fas fa-book-open" aria-hidden="true"></i> Write about it in my journal</a><?php endif; ?></section>
        <?php endif; ?>

        <?php if ($day && trim((string)$day['prayer']) !== ''): ?>
        <section class="dv-card dv-prayer"><h2>Pray</h2><p><?php echo nl2br(htmlspecialchars((string)$day['prayer'])); ?></p></section>
        <?php endif; ?>

        <div class="dv-foot">
        <?php if (!$dv_uid): ?>
            <p>This is the first day of <strong><?php echo htmlspecialchars($dv_plan['title']); ?></strong>. <a href="<?php echo SITE_URL; ?>/register.php">Join free</a> or <a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/plans.php?plan=' . (int)$dv_plan['id']); ?>">log in</a> to read every day and keep your place.</p>
        <?php else: ?>
            <form method="post" class="dv-done">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="plan" value="<?php echo (int)$dv_plan['id']; ?>"><input type="hidden" name="day" value="<?php echo $dayNo; ?>"><input type="hidden" name="v" value="<?php echo htmlspecialchars($dv_v); ?>">
                <?php if ($isDone): ?>
                    <span class="dv-read"><i class="fas fa-circle-check" aria-hidden="true"></i> You read this day</span>
                    <button class="dv-btn dv-btn-quiet" name="action" value="undo">Mark as not read</button>
                <?php else: ?>
                    <button class="dv-btn dv-btn-primary" name="action" value="done"><i class="fas fa-check" aria-hidden="true"></i> I have read today's devotional</button>
                <?php endif; ?>
            </form>
            <p class="dv-mute dv-private"><i class="fas fa-lock" aria-hidden="true"></i> Only you see what you have read. <?php if ($started): ?><button form="dvLeave" class="dv-link-btn">Leave this plan</button><?php endif; ?></p>
            <form method="post" id="dvLeave" onsubmit="return confirm('Leave this plan? Your progress in it will be forgotten.');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="plan" value="<?php echo (int)$dv_plan['id']; ?>"><input type="hidden" name="action" value="leave"></form>
        <?php endif; ?>
        </div>
    </div>
    <script src="<?php echo SITE_URL; ?>/assets/js/aw-listen.js?v=1" defer></script>
    <script>document.addEventListener('DOMContentLoaded', function () { if (window.AWListen && AWListen.supported) AWListen.attach({ label: 'Listen to today', lang: <?php echo json_encode(substr((string)($vinfo['lang'] ?? 'en'), 0, 3)); ?>,
        blocks: function () { return [].slice.call(document.querySelectorAll('.dv-dayhead h1, .dv-scripture h2, .dv-v, .dv-reflect h2, .dv-text, .dv-question h2, .dv-q, .dv-prayer h2, .dv-prayer p')); } }); });</script>
<?php endif; ?>
</main>
<style>
.dv { --dv-rose: #9c5457; --dv-card: var(--card-bg, #fff); --dv-line: rgba(156, 84, 87, .16); --dv-mute: #6d5a5a; max-width: 1000px; margin: 0 auto; padding: 26px 16px 70px; color: var(--text, #3a2629); }
html[data-theme="dark"] .dv { --dv-rose: #f0c4c5; --dv-mute: #cdb8b8; --dv-line: rgba(240, 196, 197, .16); }
.dv .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.dv-hero, .dv-dayhead { padding: 30px 28px; border-radius: 28px; color: #fff; box-shadow: 0 20px 44px rgba(44, 30, 30, .25);
    background: radial-gradient(100% 140% at 100% 0%, rgba(255, 220, 160, .4), transparent 55%), linear-gradient(140deg, var(--a, #3b2b4f), var(--b, #c08a73)); }
.dv-hero { --a: #4a2f45; --b: #c08a73; }
.dv-kicker { margin: 0; text-transform: uppercase; letter-spacing: .12em; font-size: .78rem; font-weight: 700; opacity: .92; }
.dv-hero h1, .dv-dayhead h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4.4vw, 2.6rem); color: #fff; }
.dv-lead { margin: 0; max-width: 44em; line-height: 1.6; opacity: .95; }
.dv-back { color: #fff; opacity: .9; text-decoration: none; font-weight: 600; display: inline-flex; gap: 8px; align-items: center; min-height: 40px; }
.dv-plans { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 18px; }
.dv-plan { display: flex; flex-direction: column; gap: 8px; padding: 22px; border-radius: 24px; color: #fff; min-height: 260px; position: relative; overflow: hidden;
    background: radial-gradient(120% 120% at 100% 0%, rgba(255, 255, 255, .22), transparent 50%), linear-gradient(150deg, var(--a), var(--b)); box-shadow: 0 14px 30px rgba(44, 30, 30, .2); transition: transform .25s; }
.dv-plan:hover { transform: translateY(-3px); }
.dv-plan-top { display: flex; gap: 8px; flex-wrap: wrap; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; opacity: .9; }
.dv-draft { background: rgba(255, 255, 255, .22); padding: 2px 8px; border-radius: 999px; text-transform: none; letter-spacing: 0; }
.dv-plan h2 { margin: 4px 0 0; font-family: 'Playfair Display', Georgia, serif; font-size: 1.45rem; color: #fff; }
.dv-plan p { margin: 0; line-height: 1.55; opacity: .95; flex: 1; }
.dv-prog { height: 6px; border-radius: 6px; background: rgba(255, 255, 255, .25); overflow: hidden; } .dv-prog span { display: block; height: 100%; background: #fff; border-radius: inherit; }
.dv-prog-l { flex: 0 !important; font-size: .86rem; }
.dv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--dv-rose); background: transparent; color: var(--dv-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; align-self: flex-start; }
.dv-btn:hover { background: var(--dv-rose); color: #fff; } html[data-theme="dark"] .dv-btn:hover { color: #2c1e1e; }
.dv-btn-light { background: #fff; border-color: #fff; color: #3b2b3f; } .dv-btn-light:hover { background: rgba(255, 255, 255, .85); color: #3b2b3f; }
.dv-btn-primary { background: var(--dv-rose); color: #fff; } html[data-theme="dark"] .dv-btn-primary { color: #2c1e1e; }
.dv-btn-quiet { border-color: var(--dv-line); color: var(--dv-mute); }
.dv-empty { text-align: center; padding: 40px; margin-top: 18px; border-radius: 22px; border: 1px dashed var(--dv-line); color: var(--dv-mute); } .dv-empty i { font-size: 2rem; color: var(--dv-rose); }
.dv-team { margin-top: 16px; } .dv-team a { color: var(--dv-rose); font-weight: 600; }
.dv-days { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
.dv-days a { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; background: rgba(255, 255, 255, .15); color: #fff; text-decoration: none; font-weight: 700; border: 1.5px solid rgba(255, 255, 255, .35); }
.dv-days a.is-done { background: rgba(255, 255, 255, .3); } .dv-days a.is-on { background: #fff; color: #3b2b3f; border-color: #fff; }
.dv-alert { margin: 16px 0 0; padding: 14px 18px; border-radius: 16px; background: #e3efe6; color: #24452f; }
.dv-day { display: grid; gap: 14px; margin-top: 18px; }
.dv-card { background: var(--dv-card); border: 1px solid var(--dv-line); border-radius: 22px; padding: 22px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); }
.dv-card h2 { margin: 0 0 10px; font-size: .82rem; text-transform: uppercase; letter-spacing: .12em; color: var(--dv-mute); font-family: Inter, sans-serif; }
.dv-ref-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.dv-scripture h2 { margin: 0; font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; text-transform: none; letter-spacing: 0; color: var(--text); } .dv-scripture h2 i { color: var(--dv-rose); }
.dv-ver select { min-height: 40px; border-radius: 999px; padding: 0 30px 0 12px; }
.dv-verses { font-family: Georgia, 'Times New Roman', serif; font-size: 1.15rem; line-height: 1.85; }
.dv-v sup { color: var(--dv-rose); font-family: Inter, sans-serif; font-size: .62em; font-weight: 700; margin-right: 3px; }
.dv-link { display: inline-flex; gap: 6px; align-items: center; margin-top: 12px; color: var(--dv-rose); font-weight: 600; text-decoration: none; min-height: 40px; }
.dv-text { font-size: 1.05rem; line-height: 1.8; }
.dv-question { background: linear-gradient(160deg, #fff7ec, var(--dv-card)); } html[data-theme="dark"] .dv-question { background: linear-gradient(160deg, #2e2620, var(--dv-card)); }
.dv-q { font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; line-height: 1.5; margin: 0 0 14px; }
.dv-prayer p { margin: 0; font-style: italic; font-size: 1.08rem; line-height: 1.75; }
.dv-foot { display: grid; gap: 8px; justify-items: center; text-align: center; padding: 10px 0; }
.dv-done { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: center; } .dv-read { color: #2f6b45; font-weight: 700; display: inline-flex; gap: 8px; align-items: center; }
html[data-theme="dark"] .dv-read { color: #8fd1a5; }
.dv-mute { color: var(--dv-mute); } .dv-private { font-size: .88rem; margin: 0; }
.dv-link-btn { background: none; border: 0; color: var(--dv-rose); font-weight: 600; text-decoration: underline; cursor: pointer; padding: 6px; }
.dv-foot a { color: var(--dv-rose); font-weight: 600; }
</style>
<?php require_once 'includes/footer.php'; ?>
