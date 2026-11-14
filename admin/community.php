<?php
/**
 * D3: Stories & Help for the team (PROGRAM_PLAN.md Phase 3).
 *   Waiting     posts held for a first read (moderators, admins, owner)
 *   Private     team-only requests (owner and admins only, decision 15)
 *   Paused      posts the team paused
 *   Members     pause or restore a member's posting (by a post, without revealing who they are)
 *   Settings    the switch, first read for every post, crisis contacts (admins), copy the old Q&A (admins)
 * Reports about posts and replies arrive in the one moderation queue (admin/moderation.php).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/community_posts.php';

aw_require('moderate');
aw_cp_tables($db);
aw_cp_purge($db); // retention: removed writing older than 90 days is erased
$full = aw_cp_staff_full();
$view = in_array($_GET['view'] ?? '', ['waiting', 'private', 'paused', 'members', 'settings'], true) ? $_GET['view'] : 'waiting';
if (($view === 'private' || $view === 'settings') && !$full) $view = 'waiting';
$error = ''; $notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['decide'])) {
        $ok = aw_cp_review($db, (int)($_POST['post_id'] ?? 0), (string)$_POST['decide'], (string)($_POST['note'] ?? ''));
        $ok ? $notice = 'Done. The author has been told.' : $error = 'That could not be done.';
    } elseif (isset($_POST['restrict'])) {
        $post = aw_cp_get($db, (int)($_POST['post_id'] ?? 0));
        $days = max(0, min(365, (int)($_POST['days'] ?? 0)));
        if (!$post || ($post['visibility'] === 'team' && !$full)) {
            $error = 'There is no post with that number.';
        } else {
            $until = $days ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
            aw_cp_member_save($db, (int)$post['author_id'], ['restricted_until' => $until, 'restricted_reason' => $days ? trim(strip_tags((string)($_POST['reason'] ?? ''))) : null]);
            aw_audit($days ? 'community_restrict' : 'community_unrestrict', 'community_post', (int)$post['id'], $days ? "$days days" : '');
            if ($days) awNotify($db, (int)$post['author_id'], 'community_review', 'Posting in Stories & Help is paused for ' . $days . ' days', trim((string)($_POST['reason'] ?? '')) ?: 'You can still read. Reply through Contact if you would like to talk about it.', SITE_URL . '/contact.php', ['actor' => 0]);
            $notice = $days ? 'Posting is paused for the author of post #' . (int)$post['id'] . ' for ' . $days . ' days. They have been told.' : 'Posting is open again for the author of post #' . (int)$post['id'] . '.';
        }
    } elseif (isset($_POST['save_settings']) && aw_can('manage_settings')) {
        try {
            $set = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $set->execute(['community_posts_enabled', !empty($_POST['enabled']) ? '1' : '0']);
            $set->execute(['community_premoderate_all', !empty($_POST['premod_all']) ? '1' : '0']);
            $lines = [];
            foreach (preg_split('/\r?\n/', (string)($_POST['crisis'] ?? '')) as $l) { $l = trim(strip_tags($l)); if ($l !== '' && strpos($l, '|') !== false) $lines[] = mb_substr($l, 0, 120); }
            $set->execute(['crisis_contacts', implode("\n", array_slice($lines, 0, 8))]);
            aw_audit('settings_changed', 'settings', null, 'Stories & Help');
            header('Location: ' . SITE_URL . '/admin/community.php?view=settings&saved=1'); exit;
        } catch (Throwable $e) { error_log('cp settings: ' . $e->getMessage()); $error = 'The settings could not be saved.'; }
    } elseif (isset($_POST['migrate']) && aw_can('manage_content')) {
        $r = aw_cp_migrate_questions($db);
        $notice = 'Copied ' . $r['posts'] . ' questions and ' . $r['replies'] . ' answers. Items copied before were left as they are. The old Community pages still work.';
    }
}
if (!empty($_GET['saved'])) $notice = 'Saved.';

$rows = [];
try {
    if ($view === 'waiting') $rows = $db->query("SELECT * FROM community_posts WHERE status = 'held' AND visibility <> 'team' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
    elseif ($view === 'private') $rows = $db->query("SELECT p.*, (SELECT MAX(created_at) FROM community_replies r WHERE r.post_id = p.id) AS last_reply FROM community_posts p WHERE visibility = 'team' AND status <> 'removed' ORDER BY COALESCE((SELECT MAX(created_at) FROM community_replies r WHERE r.post_id = p.id), p.created_at) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    elseif ($view === 'paused') $rows = $db->query("SELECT * FROM community_posts WHERE status IN ('hidden','draft')" . ($full ? '' : " AND visibility <> 'team'") . " ORDER BY updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    elseif ($view === 'members') $rows = $db->query("SELECT m.*, (SELECT MAX(id) FROM community_posts p WHERE p.author_id = m.user_id) AS any_post FROM community_members m WHERE m.restricted_until > CURRENT_TIMESTAMP")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('cp admin list: ' . $e->getMessage()); }
$counts = ['waiting' => 0, 'private' => 0];
try {
    $counts['waiting'] = (int)$db->query("SELECT COUNT(*) FROM community_posts WHERE status = 'held' AND visibility <> 'team'")->fetchColumn();
    if ($full) $counts['private'] = (int)$db->query("SELECT COUNT(*) FROM community_posts p WHERE visibility = 'team' AND status = 'published' AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.as_team = 1)")->fetchColumn();
} catch (Throwable $e) {}
$oldest = $rows && $view === 'waiting' ? $rows[0]['created_at'] : null;
$token = generate_csrf_token();

$pageTitle = 'Stories & Help · Team';
require_once '../includes/header.php';
echo aw_cp_assets();
?>
<main class="cp admin-page" id="main">
<div class="container">
    <div class="cp-head">
        <div><h1>Stories &amp; Help</h1><p>First reads, private requests and settings. Reports about posts and replies are in <a href="<?php echo SITE_URL; ?>/admin/moderation.php">Moderation</a>.</p></div>
        <?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?>
    </div>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

    <nav class="cp-chips" aria-label="Views" style="margin-bottom:18px">
        <a class="cp-chip" href="?view=waiting"<?php echo $view === 'waiting' ? ' aria-current="page"' : ''; ?>>Waiting for a first read<?php echo $counts['waiting'] ? ' (' . $counts['waiting'] . ')' : ''; ?></a>
        <?php if ($full): ?><a class="cp-chip" href="?view=private"<?php echo $view === 'private' ? ' aria-current="page"' : ''; ?>>Private requests<?php echo $counts['private'] ? ' (' . $counts['private'] . ' unanswered)' : ''; ?></a><?php endif; ?>
        <a class="cp-chip" href="?view=paused"<?php echo $view === 'paused' ? ' aria-current="page"' : ''; ?>>Paused and returned</a>
        <a class="cp-chip" href="?view=members"<?php echo $view === 'members' ? ' aria-current="page"' : ''; ?>>Paused posting</a>
        <?php if ($full): ?><a class="cp-chip" href="?view=settings"<?php echo $view === 'settings' ? ' aria-current="page"' : ''; ?>>Settings</a><?php endif; ?>
    </nav>

    <?php if ($view === 'waiting' && $oldest): ?>
        <p class="cp-banner cp-banner-warn">The oldest post has waited since <?php echo htmlspecialchars(date('j M, g:i a', strtotime($oldest))); ?>. Posts about someone at risk should be read within 2 hours in waking hours.</p>
    <?php endif; ?>

    <?php if ($view === 'settings'): $crisis = (string)aw_setting('crisis_contacts', "Angella (AngelWrites) | +265 99 146 9386"); ?>
        <form method="post" class="cp-section cp-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <h2>Settings</h2>
            <label class="cp-check"><input type="checkbox" name="enabled" value="1"<?php echo aw_cp_enabled() ? ' checked' : ''; ?>> Stories &amp; Help is open to members (untick to hide it; the team can still see it)</label>
            <label class="cp-check"><input type="checkbox" name="premod_all" value="1"<?php echo (string)aw_setting('community_premoderate_all', '0') === '1' ? ' checked' : ''; ?>> Read every new post before it is shared (not only the tender areas)</label>
            <div class="cp-field">
                <label for="cpCrisis">Crisis contacts, one per line as “Who | number”</label>
                <span class="cp-hint">Shown on tender areas, on the sharing form and on posts with warnings. Please check each number before saving: a wrong number in a crisis is worse than none.</span>
                <textarea id="cpCrisis" name="crisis" rows="4"><?php echo htmlspecialchars($crisis); ?></textarea>
            </div>
            <p><button class="cp-btn" name="save_settings" value="1">Save settings</button></p>
        </form>
        <?php if (aw_can('manage_content')): ?>
        <form method="post" class="cp-section">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <h2>Copy the old Community questions</h2>
            <p>Copies every question (with the member's name, as before) and its answers into Stories &amp; Help. Safe to run again: nothing is copied twice. The old Community pages keep working until you retire them.</p>
            <button class="cp-btn cp-btn-soft" name="migrate" value="1" onclick="return confirm('Copy the old questions and answers into Stories & Help?');">Copy questions and answers</button>
        </form>
        <?php endif; ?>

    <?php elseif ($view === 'members'): ?>
        <form method="post" class="cp-section cp-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <h2>Pause someone's posting</h2>
            <p>Use the number of one of their posts (in its address, story.php?id=<strong>N</strong>). You do not need to know who they are. Prefer a private, gentle word first.</p>
            <div class="cp-filters" style="margin:0">
                <label class="sr-only" for="rPost">Post number</label><input type="text" id="rPost" name="post_id" inputmode="numeric" placeholder="Post number" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:0 12px;max-width:140px">
                <label class="sr-only" for="rDays">For how long</label>
                <select id="rDays" name="days" style="min-height:44px;border-radius:10px;border:1px solid var(--border);padding:0 10px"><option value="3">3 days</option><option value="7" selected>7 days</option><option value="30">30 days</option><option value="365">A year</option><option value="0">Open again</option></select>
                <label class="sr-only" for="rReason">Note to them</label><input type="text" id="rReason" name="reason" maxlength="300" placeholder="Note to them (they will see it)" style="flex:1;min-height:44px;border-radius:10px;border:1px solid var(--border);padding:0 12px">
                <button class="cp-btn cp-btn-sm" name="restrict" value="1">Save</button>
            </div>
        </form>
        <?php if (!$rows): echo aw_empty('Nobody is paused', 'Members whose posting is paused appear here until the pause ends.', null, 'fa-dove'); else: ?>
            <div class="cp-section"><ul class="cp-mine"><?php foreach ($rows as $m): ?>
                <li><span>Author of post #<?php echo (int)$m['any_post']; ?><?php echo $full ? ' · ' . htmlspecialchars(aw_cp_user_name($db, (int)$m['user_id'])) : ''; ?></span><span class="cp-status cp-status-held">until <?php echo htmlspecialchars(date('j M Y', strtotime($m['restricted_until']))); ?></span></li>
            <?php endforeach; ?></ul></div>
        <?php endif; ?>

    <?php elseif (!$rows): ?>
        <?php echo aw_empty($view === 'waiting' ? 'Nothing is waiting' : ($view === 'private' ? 'No private requests' : 'Nothing is paused'),
            $view === 'waiting' ? 'Posts about tender areas of life wait here until someone on the team reads them.' : ($view === 'private' ? 'Requests members send only to the team appear here.' : 'Posts you pause or send back for a change appear here.'), null, 'fa-seedling'); ?>
    <?php else: ?>
        <div class="cp-list">
        <?php foreach ($rows as $p): $warn = aw_cp_warning_list($p['warnings']); ?>
            <article class="cp-card" style="cursor:default">
                <div class="cp-meta">
                    <span class="cp-type"><?php echo htmlspecialchars(AW_CP_TYPES[$p['type']] ?? 'Post'); ?></span>
                    <span><?php echo htmlspecialchars(aw_cp_category_label($p['category'])); ?></span>
                    <span>#<?php echo (int)$p['id']; ?></span>
                    <span><?php echo htmlspecialchars(date('j M, g:i a', strtotime($p['created_at']))); ?></span>
                    <?php foreach ($warn as $w): ?><span class="cp-tag cp-tag-warn"><?php echo htmlspecialchars(AW_CP_WARNINGS[$w]); ?></span><?php endforeach; ?>
                    <?php if ($view === 'paused'): ?><span class="cp-tag"><?php echo $p['status'] === 'draft' ? 'Change requested' : 'Paused'; ?></span><?php endif; ?>
                </div>
                <h2 style="position:static"><a href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$p['id']; ?>" style="position:static"><?php echo htmlspecialchars($p['title']); ?></a></h2>
                <p class="cp-excerpt"><?php echo htmlspecialchars(aw_cp_excerpt($p['body'], 400)); ?></p>
                <p class="cp-byline"><span><strong><?php echo htmlspecialchars(aw_cp_author_label($db, $p)); ?></strong></span><?php if ($view === 'private'): ?><span><?php echo !empty($p['last_reply']) ? 'last message ' . htmlspecialchars(date('j M', strtotime($p['last_reply']))) : 'no reply yet'; ?></span><?php endif; ?></p>
                <?php if ($view === 'waiting' || $view === 'paused'): ?>
                <form method="post" class="cp-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="sr-only" for="n<?php echo (int)$p['id']; ?>">Note to the author</label>
                    <input type="text" id="n<?php echo (int)$p['id']; ?>" name="note" maxlength="300" placeholder="Note to the author (optional)" style="flex:1 1 220px;min-height:40px;border-radius:10px;border:1px solid var(--border);padding:0 12px;background:var(--card-bg);color:var(--text)">
                    <button class="cp-btn cp-btn-sm" name="decide" value="publish">Share it</button>
                    <?php if ($view === 'waiting'): ?><button class="cp-btn cp-btn-soft cp-btn-sm" name="decide" value="return">Ask for a change</button>
                    <button class="cp-btn cp-btn-quiet cp-btn-sm" name="decide" value="hide">Pause</button><?php endif; ?>
                </form>
                <?php else: ?>
                <p class="cp-actions"><a class="cp-btn cp-btn-sm" href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$p['id']; ?>#reply">Open and reply as the team</a></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</main>
<?php require_once '../includes/footer.php'; ?>
