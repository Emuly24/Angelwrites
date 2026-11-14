<?php
/**
 * DB: Database setup from the browser (owner and admins).
 *
 * The site creates most of its tables the first time a feature is used. This page does it all at
 * once, so a new feature works from its first visit, and shows what is there and what is missing.
 *  - "Check" only reads (sqlite_master); nothing changes.
 *  - "Create what is missing" runs CREATE TABLE IF NOT EXISTS for the full base schema and every
 *    feature's own setup. Existing tables and data are never changed or deleted. Retired tables
 *    (points, streaks, achievements, challenges; removed in Phase 1) are never brought back.
 * POST + CSRF, logged in the audit log. Take a backup first (a link is on the page).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';

aw_require('manage_settings');

// C1: retired in Phase 1 (admin/backup.php, AW_RETIRED_TABLES); never recreated here
const AW_SETUP_RETIRED = ['achievements', 'user_reputations', 'reading_streaks', 'reading_challenges', 'reading_goals', 'bible_monthly_challenges'];

/** Each feature: label, the file that knows its tables, the function that creates them, and the tables it needs. */
function aw_setup_features() {
    return [
        ['Site settings', null, function (PDO $db) { $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)"); }, ['settings']],
        ['Staff roles and the audit log', 'roles.php', 'aw_audit_table', ['audit_log']],
        ['Notifications and email choices', 'notifications.php', 'aw_notify_tables', ['notifications', 'notification_prefs', 'notification_mail_log']],
        ['Reports, moderation and blocks', 'moderation.php', 'aw_moderation_tables', ['reports', 'blocks', 'moderation_archive']],
        ['@mentions', 'mentions.php', 'aw_mentions_table', ['mentions']],
        ['Team inbox', 'team_inbox.php', 'aw_inbox_tables', ['team_replies', 'team_inbox_state', 'canned_responses']],
        ['Two-step sign-in for staff', 'twofactor.php', 'aw_2fa_table', ['staff_2fa']],
        ['Upload limits', 'upload_quota.php', 'aw_upload_usage_table', ['member_upload_usage']],
        ['Stories & Help', 'community_posts.php', 'aw_cp_tables', ['community_posts', 'community_replies', 'community_thanks', 'community_members']],
        ['Saves and collections', 'saves.php', 'aw_save_tables', ['saves', 'collections']],
        ['Planned reads', 'reading_plans.php', 'aw_plans_table', ['reading_plans']],
        ['Messages', 'messaging.php', 'aw_msg_tables', ['msg_threads', 'msg_participants', 'messages', 'msg_settings']],
    ];
}

function aw_setup_tables(PDO $db) {
    $out = [];
    try { foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $r) $out[$r['name']] = true; }
    catch (Throwable $e) { error_log('db setup list: ' . $e->getMessage()); }
    return $out;
}

/** The base schema's CREATE TABLE statements as [table => sql], without retired tables; and its indexes. */
function aw_setup_base() {
    $tables = []; $indexes = [];
    $file = dirname(__DIR__) . '/includes/schema.php';
    if (!is_file($file)) return [$tables, $indexes];
    foreach ((array)(require $file) as $sql) {
        if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+"?(\w+)"?/i', $sql, $m)) {
            if (!in_array($m[1], AW_SETUP_RETIRED, true)) $tables[$m[1]] = $sql;
        } elseif (preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+"?\w+"?\s+ON\s+"?(\w+)"?/i', $sql, $m)) {
            if (!in_array($m[2], AW_SETUP_RETIRED, true)) $indexes[] = [$m[2], $sql];
        }
    }
    return [$tables, $indexes];
}

$report = null; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $before = aw_setup_tables($db);
        $report = ['created' => [], 'failed' => [], 'indexes' => 0];
        // 1. the base schema (only tables that are missing)
        [$baseTables, $baseIndexes] = aw_setup_base();
        foreach ($baseTables as $t => $sql) {
            if (isset($before[$t])) continue;
            try { $db->exec($sql); $report['created'][] = $t; }
            catch (Throwable $e) { $report['failed'][] = $t . ': ' . $e->getMessage(); error_log('db setup ' . $t . ': ' . $e->getMessage()); }
        }
        $now = aw_setup_tables($db);
        foreach ($baseIndexes as [$t, $sql]) {
            if (!isset($now[$t])) continue;
            try { $db->exec($sql); $report['indexes']++; } catch (Throwable $e) { error_log('db setup index: ' . $e->getMessage()); }
        }
        // 2. every feature's own setup (also adds columns a feature needs, e.g. the report decisions)
        foreach (aw_setup_features() as [$label, $file, $fn, $need]) {
            try {
                if ($file) require_once dirname(__DIR__) . '/includes/' . $file;
                if (is_callable($fn)) $fn($db);
            } catch (Throwable $e) { $report['failed'][] = $label . ': ' . $e->getMessage(); error_log('db setup ' . $label . ': ' . $e->getMessage()); }
        }
        $after = aw_setup_tables($db);
        foreach (array_keys($after) as $t) if (!isset($before[$t]) && !in_array($t, $report['created'], true)) $report['created'][] = $t;
        sort($report['created']);
        aw_audit('database_setup', 'database', null, count($report['created']) . ' tables created' . ($report['failed'] ? ', ' . count($report['failed']) . ' failed' : ''));
    }
}

$have = aw_setup_tables($db);
[$baseTables] = aw_setup_base();
$missingBase = array_values(array_filter(array_keys($baseTables), function ($t) use ($have) { return !isset($have[$t]); }));
$features = [];
$missingCount = count($missingBase);
foreach (aw_setup_features() as [$label, , , $need]) {
    $miss = array_values(array_filter($need, function ($t) use ($have) { return !isset($have[$t]); }));
    $missingCount += count($miss);
    $features[] = [$label, $need, $miss];
}
$integrity = '';
try { $integrity = (string)$db->query('PRAGMA quick_check')->fetchColumn(); } catch (Throwable $e) { $integrity = 'could not be checked'; }
$size = 0;
try { $size = (int)$db->query('PRAGMA page_count')->fetchColumn() * (int)$db->query('PRAGMA page_size')->fetchColumn(); } catch (Throwable $e) {}

$pageTitle = 'Database setup';
require_once '../includes/header.php';
?>
<style>
.dbs { padding: 32px 0 64px; } .dbs .container { max-width: 900px; }
.dbs h1 { margin: 0 0 6px; } .dbs-lead { color: var(--text-light); margin: 0 0 18px; }
.dbs-sum { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 20px; }
.dbs-sum span { padding: 10px 16px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border); font-size: .92rem; }
.dbs-sum strong { display: block; font-size: 1.3rem; }
.dbs-card { padding: 20px 22px; border-radius: 18px; background: var(--card-bg); border: 1px solid var(--border); margin: 0 0 16px; }
.dbs-card h2 { font-size: 1.15rem; margin: 0 0 10px; }
.dbs-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
.dbs-table th, .dbs-table td { text-align: left; padding: 9px 6px; border-top: 1px solid var(--border); vertical-align: top; }
.dbs-ok { color: #2f6b45; font-weight: 700; } .dbs-miss { color: #a3262e; font-weight: 700; }
html[data-theme="dark"] .dbs-ok { color: #8fd1a5; } html[data-theme="dark"] .dbs-miss { color: #f2b8b5; }
.dbs code { font-size: .84rem; background: rgba(219,161,162,.15); padding: 1px 6px; border-radius: 6px; }
.dbs-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.dbs-btn { min-height: 46px; padding: 0 22px; border: 0; border-radius: 999px; background: #9c5457; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.dbs-check { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; background: rgba(219,161,162,.12); margin: 0 0 12px; }
.dbs-check input { width: 20px; height: 20px; margin-top: 2px; accent-color: #9c5457; }
.dbs-list { margin: 6px 0 0 1.2em; }
</style>
<main class="dbs" id="main">
<div class="container">
    <h1>Database setup</h1>
    <p class="dbs-lead">Creates any tables the site needs that are not there yet. Nothing that exists is changed or deleted, and the retired points, streaks and achievements tables are never brought back.</p>
    <?php echo aw_alert('error', $error); ?>

    <?php if ($report !== null): ?>
        <div class="dbs-card" role="status">
            <h2><?php echo $report['failed'] ? 'Finished, with problems' : 'Done'; ?></h2>
            <p><?php echo $report['created'] ? 'Created ' . count($report['created']) . ' ' . (count($report['created']) === 1 ? 'table' : 'tables') . ':' : 'Nothing was missing. Every table was already there.'; ?></p>
            <?php if ($report['created']): ?><p><?php echo implode(' ', array_map(function ($t) { return '<code>' . htmlspecialchars($t) . '</code>'; }, $report['created'])); ?></p><?php endif; ?>
            <?php if ($report['failed']): ?><p class="dbs-miss">These could not be created (details are in the server's error log):</p><ul class="dbs-list"><?php foreach ($report['failed'] as $f): ?><li><?php echo htmlspecialchars($f); ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="dbs-sum">
        <span><strong><?php echo count($have); ?></strong>tables now</span>
        <span><strong class="<?php echo $missingCount ? 'dbs-miss' : 'dbs-ok'; ?>"><?php echo $missingCount; ?></strong>missing</span>
        <span><strong><?php echo $size ? number_format($size / 1048576, 1) . ' MB' : '—'; ?></strong>database size</span>
        <span><strong class="<?php echo $integrity === 'ok' ? 'dbs-ok' : 'dbs-miss'; ?>"><?php echo $integrity === 'ok' ? 'OK' : 'Check'; ?></strong>integrity<?php echo $integrity !== 'ok' && $integrity !== '' ? ': ' . htmlspecialchars(mb_strimwidth($integrity, 0, 80, '…')) : ''; ?></span>
    </div>

    <form method="post" class="dbs-card">
        <h2>Create what is missing</h2>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <label class="dbs-check"><input type="checkbox" required> <span>I have a backup from today. <a href="<?php echo SITE_URL; ?>/admin/backup.php">Download one now</a> if not.</span></label>
        <div class="dbs-actions">
            <button type="submit" name="create" value="1" class="dbs-btn"><?php echo $missingCount ? 'Create ' . $missingCount . ' missing ' . ($missingCount === 1 ? 'table' : 'tables') : 'Run the setup again (safe)'; ?></button>
            <span class="dbs-lead" style="margin:0">Safe to run more than once.</span>
        </div>
    </form>

    <div class="dbs-card">
        <h2>Features</h2>
        <table class="dbs-table">
            <thead><tr><th scope="col">Feature</th><th scope="col">Tables</th><th scope="col">State</th></tr></thead>
            <tbody>
            <?php foreach ($features as [$label, $need, $miss]): ?>
                <tr><th scope="row"><?php echo htmlspecialchars($label); ?></th>
                    <td><?php echo implode(' ', array_map(function ($t) use ($miss) { return '<code' . (in_array($t, $miss, true) ? ' class="dbs-miss"' : '') . '>' . htmlspecialchars($t) . '</code>'; }, $need)); ?></td>
                    <td><?php echo $miss ? '<span class="dbs-miss">' . count($miss) . ' missing</span>' : '<span class="dbs-ok">Ready</span>'; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="dbs-card">
        <h2>Base tables</h2>
        <?php if (!$missingBase): ?>
            <p class="dbs-ok">All <?php echo count($baseTables); ?> base tables are there.</p>
        <?php else: ?>
            <p><span class="dbs-miss"><?php echo count($missingBase); ?> missing</span> of <?php echo count($baseTables); ?>:</p>
            <p><?php echo implode(' ', array_map(function ($t) { return '<code>' . htmlspecialchars($t) . '</code>'; }, $missingBase)); ?></p>
        <?php endif; ?>
    </div>
    <p><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?></p>
</div>
</main>
<?php require_once '../includes/footer.php'; ?>
