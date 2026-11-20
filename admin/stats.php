<?php
/**
 * Statistics: how the site is being read, without tracking anyone.
 * Figures come from anonymous daily totals (includes/site_stats.php) and from what the site already
 * records (views per item, new members, reading). Owner and admins. No outside service, no cookies.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/dash.php';
require_once '../includes/site_stats.php';

aw_require('manage_settings'); // owner and admins (checks the CSRF token on POST)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stats_toggle'])) {
    $on = $_POST['stats_toggle'] === '1' ? '1' : '0';
    $db->prepare("INSERT INTO settings (key, value) VALUES ('stats_enabled', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$on]);
    if (function_exists('aw_audit')) aw_audit('stats_' . ($on === '1' ? 'on' : 'off'), 'settings', 0, '');
    header('Location: ' . SITE_URL . '/admin/stats.php');
    exit;
}

$st_days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$st_from = date('Y-m-d', strtotime('-' . ($st_days - 1) . ' days'));
$st_views = aw_stats_daily($db, 'view', $st_days);
$st_visits = aw_stats_daily($db, 'visit', $st_days);
$st_tot_views = array_sum($st_views);
$st_tot_visits = array_sum($st_visits);
// the same length of time just before, to compare
$st_prev = 0;
try {
    $q = $db->prepare("SELECT COALESCE(SUM(n), 0) FROM site_stats WHERE dim = 'visit' AND day >= ? AND day < ?");
    $q->execute([date('Y-m-d', strtotime('-' . (2 * $st_days - 1) . ' days')), $st_from]);
    $st_prev = (int)$q->fetchColumn();
} catch (Throwable $e) { error_log('stats prev: ' . $e->getMessage()); }
$st_pages = aw_stats_top($db, 'page', $st_days, 12);
$st_refs = aw_stats_top($db, 'ref', $st_days, 10);
$st_dev = aw_stats_top($db, 'device', $st_days, 3);
$st_lang = aw_stats_top($db, 'lang', $st_days, 8);
$st_search = aw_stats_top($db, 'search', $st_days, 12);
$st_search0 = aw_stats_top($db, 'search0', $st_days, 12);
$st_members = aw_dsh_q($db, "SELECT COALESCE(NULLIF(TRIM(country), ''), 'Not given') AS c, COUNT(*) AS n FROM users WHERE created_at >= ? GROUP BY c ORDER BY n DESC LIMIT 10", [$st_from]);
$st_new = aw_dsh_n($db, "SELECT COUNT(*) FROM users WHERE created_at >= ?", [$st_from]);
$st_top = aw_dsh_q($db, "SELECT v.target_type, v.target_id, COUNT(*) AS n,
        CASE v.target_type WHEN 'poem' THEN (SELECT title FROM poems WHERE id = v.target_id) WHEN 'book' THEN (SELECT title FROM books WHERE id = v.target_id)
             WHEN 'post' THEN (SELECT title FROM blog_posts WHERE id = v.target_id) WHEN 'video' THEN (SELECT title FROM videos WHERE id = v.target_id)
             WHEN 'question' THEN (SELECT title FROM questions WHERE id = v.target_id) END AS title
    FROM view_logs v WHERE v.viewed_at >= ? AND v.target_type <> 'story' GROUP BY v.target_type, v.target_id ORDER BY n DESC LIMIT 10", [$st_from . ' 00:00:00']);
$st_reading = aw_dsh_n($db, "SELECT COUNT(*) FROM reading_sessions WHERE start_time >= ?", [$st_from . ' 00:00:00']);
$st_on = (string)aw_setting('stats_enabled', '1') !== '0';

$st_page_names = ['/index.php' => 'Home', '/books.php' => 'Books', '/book.php' => 'A book page', '/poetry.php' => 'Poetry', '/poem_view.php' => 'A poem', '/reflections.php' => 'Reflections',
    '/blog.php' => 'Blog', '/blog_post.php' => 'A post or reflection', '/videos.php' => 'Videos', '/video_watch.php' => 'A video', '/bible_reader.php' => 'Bible', '/about.php' => 'About',
    '/contact.php' => 'Contact', '/stories.php' => 'Stories & Help', '/story.php' => 'A story', '/community.php' => 'Community Q&A', '/search_results.php' => 'Search',
    '/login.php' => 'Log in', '/register.php' => 'Join', '/dashboard.php' => 'Member dashboard', '/library.php' => 'My library', '/reader/reader.php' => 'Book reader', '/groups.php' => 'Reading groups'];
$st_lang_names = ['en' => 'English', 'ny' => 'Chichewa', 'fr' => 'French', 'pt' => 'Portuguese', 'sw' => 'Swahili', 'es' => 'Spanish', 'de' => 'German', 'zu' => 'Zulu', 'ar' => 'Arabic',
    'zh' => 'Chinese', 'hi' => 'Hindi', 'yo' => 'Yoruba', 'ig' => 'Igbo', 'ha' => 'Hausa', 'af' => 'Afrikaans', 'nl' => 'Dutch', 'it' => 'Italian', '??' => 'Not given'];
$st_kind = ['poem' => 'Poem', 'book' => 'Book', 'post' => 'Post', 'video' => 'Video', 'question' => 'Question'];

/** A simple bar list. */
function aw_st_bars(array $rows, $label = null) {
    if (!$rows) return '<p class="st-none">Nothing yet for this period.</p>';
    $max = max(1, max($rows));
    $h = '<ul class="st-bars">';
    foreach ($rows as $k => $n) {
        $name = $label ? $label($k) : $k;
        $h .= '<li><span class="st-bar-name">' . htmlspecialchars((string)$name) . '</span><span class="st-bar-n">' . number_format($n) . '</span>'
            . '<span class="st-bar" aria-hidden="true"><span style="width:' . round($n / $max * 100, 1) . '%"></span></span></li>';
    }
    return $h . '</ul>';
}

$pageTitle = 'Statistics';
require_once '../includes/header.php';
$st_max = max(1, max($st_views ?: [0]));
$st_n = count($st_views);
?>
<div class="st">
    <header class="st-head">
        <div>
            <p class="st-kicker"><i class="fas fa-chart-simple" aria-hidden="true"></i> Statistics</p>
            <h1>How AngelWrites is being read</h1>
            <p class="st-lead">Anonymous daily totals only: no names, no addresses, no tracking cookies, no outside services. Robots and the team's own visits are not counted.</p>
        </div>
        <nav class="st-range" aria-label="Period">
            <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => 'A year'] as $d => $l): ?>
            <a href="?days=<?php echo $d; ?>"<?php echo $d === $st_days ? ' class="is-on" aria-current="page"' : ''; ?>><?php echo $l; ?></a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php if (!$st_on): ?><div class="st-alert">Counting is switched off: the figures below stopped when it was turned off.</div><?php endif; ?>

    <section class="st-kpis" aria-label="Totals">
        <div class="st-kpi"><span>Visits</span><b><?php echo number_format($st_tot_visits); ?></b><small><?php
            if ($st_prev > 0) { $ch = round(($st_tot_visits - $st_prev) / $st_prev * 100); echo ($ch >= 0 ? '▲ ' : '▼ ') . abs($ch) . '% on the ' . $st_days . ' days before'; } else echo 'first period counted'; ?></small></div>
        <div class="st-kpi"><span>Pages read</span><b><?php echo number_format($st_tot_views); ?></b><small><?php echo $st_tot_visits ? number_format($st_tot_views / $st_tot_visits, 1) . ' pages per visit' : '&nbsp;'; ?></small></div>
        <div class="st-kpi"><span>New members</span><b><?php echo number_format($st_new); ?></b><small>joined in this period</small></div>
        <div class="st-kpi"><span>Reading sessions</span><b><?php echo number_format($st_reading); ?></b><small>books opened in the reader</small></div>
    </section>

    <section class="st-card" aria-labelledby="stDaily">
        <h2 id="stDaily">Visits and pages, day by day</h2>
        <?php if (!$st_tot_views): ?><p class="st-none">Counting starts with this update; come back in a day or two.</p><?php else: ?>
        <div class="st-chart" role="img" aria-label="<?php echo htmlspecialchars('Pages read per day over ' . $st_days . ' days, from ' . min($st_views) . ' to ' . max($st_views)); ?>">
            <?php $i = 0; foreach ($st_views as $day => $n): $v = $st_visits[$day] ?? 0; ?>
            <span class="st-col" title="<?php echo htmlspecialchars(date('D j M', strtotime($day)) . ': ' . $n . ' pages, ' . $v . ' visits'); ?>">
                <span class="st-col-p" style="height:<?php echo round($n / $st_max * 100, 1); ?>%"></span>
                <span class="st-col-v" style="height:<?php echo round($v / $st_max * 100, 1); ?>%"></span>
            </span>
            <?php $i++; endforeach; ?>
        </div>
        <p class="st-axis"><span><?php echo htmlspecialchars(date('j M', strtotime(array_key_first($st_views)))); ?></span><span class="st-key"><i class="p"></i> pages <i class="v"></i> visits</span><span>today</span></p>
        <?php endif; ?>
    </section>

    <div class="st-grid">
        <section class="st-card"><h2>Most read pages</h2><?php echo aw_st_bars($st_pages, function ($p) use ($st_page_names) { return $st_page_names[$p] ?? $p; }); ?></section>
        <section class="st-card"><h2>Most read writing</h2>
            <?php if (!$st_top): ?><p class="st-none">Nothing yet for this period.</p><?php else: $rows = []; foreach ($st_top as $r) $rows[($st_kind[$r['target_type']] ?? '') . ': ' . ($r['title'] ?: '#' . $r['target_id'])] = (int)$r['n']; echo aw_st_bars($rows); endif; ?></section>
        <section class="st-card"><h2>Where readers come from</h2><?php echo aw_st_bars($st_refs); ?><p class="st-note">The website a visit started from. "Typed or bookmarked" also includes links opened from apps that hide where they came from.</p></section>
        <section class="st-card"><h2>Devices</h2><?php echo aw_st_bars($st_dev); ?>
            <h2 style="margin-top:18px;">Languages</h2><?php echo aw_st_bars($st_lang, function ($c) use ($st_lang_names) { return $st_lang_names[$c] ?? strtoupper($c); }); ?><p class="st-note">The language each reader's browser asks for.</p></section>
        <section class="st-card"><h2>What readers search for</h2><?php echo aw_st_bars($st_search); ?></section>
        <section class="st-card"><h2>Searches that found nothing</h2><?php echo aw_st_bars($st_search0); ?><p class="st-note">Ideas for what to write or add next.</p></section>
        <section class="st-card"><h2>New members by country</h2><?php $rows = []; foreach ($st_members as $r) $rows[$r['c']] = (int)$r['n']; echo aw_st_bars($rows); ?><p class="st-note">From what members chose when joining.</p></section>
        <section class="st-card"><h2>Privacy</h2>
            <p class="st-note" style="margin-top:0;">Only totals per day are kept (for 400 days): no IP address, account, or tracking cookie, and nothing is sent to another company. Searches containing an email address or a long number are never kept.</p>
            <form method="post"><button type="submit" name="stats_toggle" value="<?php echo $st_on ? '0' : '1'; ?>" class="st-btn"><?php echo $st_on ? 'Switch counting off' : 'Switch counting on'; ?></button></form>
        </section>
    </div>
</div>
<style>
.st { --st-rose: #9c5457; --st-gold: #d6a85c; --st-card: var(--card-bg, #fff); --st-line: rgba(156, 84, 87, .15); --st-mute: #6d5a5a;
      max-width: 1180px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .st { --st-rose: #f0c4c5; --st-mute: #cdb8b8; --st-line: rgba(240, 196, 197, .15); }
.st-head { display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; align-items: flex-end; padding: 26px; border-radius: 24px; color: #fff;
           background: radial-gradient(120% 140% at 100% 0%, rgba(214, 168, 92, .45), transparent 55%), linear-gradient(135deg, #3d2a45, #9c5457); box-shadow: 0 18px 40px rgba(61, 42, 69, .25); }
.st-kicker { margin: 0; text-transform: uppercase; letter-spacing: .12em; font-size: .76rem; font-weight: 700; opacity: .9; }
.st-head h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem, 3.6vw, 2.3rem); color: #fff; }
.st-lead { margin: 0; max-width: 44em; opacity: .95; line-height: 1.55; }
.st-range { display: flex; gap: 6px; flex-wrap: wrap; }
.st-range a { min-height: 40px; display: inline-flex; align-items: center; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .45); color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem; }
.st-range a.is-on, .st-range a:hover { background: #fff; color: #3d2a45; }
.st-alert { margin: 14px 0 0; padding: 12px 16px; border-radius: 14px; background: #fbecd4; color: #6b4310; }
.st-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 16px 0; }
.st-kpi { background: var(--st-card); border: 1px solid var(--st-line); border-radius: 18px; padding: 16px; display: grid; gap: 2px; }
.st-kpi span { font-weight: 600; color: var(--st-mute); font-size: .88rem; } .st-kpi b { font-size: 2rem; font-variant-numeric: tabular-nums; } .st-kpi small { color: var(--st-mute); }
.st-card { background: var(--st-card); border: 1px solid var(--st-line); border-radius: 20px; padding: 18px; box-shadow: 0 6px 18px rgba(44, 30, 30, .05); }
.st-card h2 { margin: 0 0 12px; font-size: 1.05rem; }
.st-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-top: 14px; }
.st-chart { display: flex; align-items: flex-end; gap: 2px; height: 200px; padding: 8px 0; border-bottom: 1px solid var(--st-line); }
.st-col { position: relative; flex: 1; height: 100%; display: flex; align-items: flex-end; }
.st-col-p { width: 100%; background: linear-gradient(180deg, var(--st-rose), rgba(156, 84, 87, .45)); border-radius: 4px 4px 0 0; min-height: 1px; animation: stUp .7s cubic-bezier(.2, .8, .2, 1) both; }
.st-col-v { position: absolute; left: 25%; width: 50%; bottom: 0; background: var(--st-gold); border-radius: 3px 3px 0 0; }
.st-col:hover .st-col-p { filter: brightness(1.15); }
@keyframes stUp { from { transform: scaleY(0); transform-origin: bottom; } }
.st-axis { display: flex; justify-content: space-between; color: var(--st-mute); font-size: .8rem; margin: 6px 0 0; }
.st-key i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin: 0 4px 0 10px; vertical-align: -1px; } .st-key .p { background: var(--st-rose); } .st-key .v { background: var(--st-gold); }
.st-bars { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.st-bars li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 10px; }
.st-bar-name { overflow-wrap: anywhere; } .st-bar-n { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--st-rose); }
.st-bar { grid-column: 1 / -1; height: 7px; border-radius: 7px; background: var(--st-line); overflow: hidden; }
.st-bar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--st-rose), var(--st-gold)); }
.st-none, .st-note { color: var(--st-mute); font-size: .88rem; line-height: 1.5; } .st-note { margin: 10px 0 0; }
.st-btn { min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--st-rose); background: transparent; color: var(--st-rose); font-weight: 600; cursor: pointer; margin-top: 10px; }
.st-btn:hover { background: var(--st-rose); color: #fff; }
@media (prefers-reduced-motion: reduce) { .st-col-p { animation: none; } }
</style>
<?php require_once '../includes/footer.php'; ?>
