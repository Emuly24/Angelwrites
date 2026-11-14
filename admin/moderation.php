<?php
/**
 * N4: the one moderation queue. Every report, whatever was reported, in one list.
 * Moderators and admins. Keep (close the report) or remove (archived, restorable by admins).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/components.php';
require_once '../includes/mail_helper.php';
require_once '../includes/moderation.php';

aw_require('moderate');
aw_moderation_tables($db);

$error = ''; $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['decide'])) {
        $decision = $_POST['decide'] === 'remove' ? 'remove' : 'keep';
        if (aw_report_decide($db, (int)($_POST['report_id'] ?? 0), $decision, (string)($_POST['note'] ?? ''))) {
            $notice = $decision === 'remove' ? 'Removed. The reporter and the author have been told.' : 'Kept. The reporter has been told.';
        } else {
            $error = 'That could not be saved. It may already have been decided.';
        }
    } elseif (isset($_POST['restore']) && aw_can('manage_content')) {
        $notice = aw_moderation_restore($db, (int)$_POST['restore']) ? 'Restored.' : '';
        if ($notice === '') $error = 'That could not be restored.';
    }
}

$view = ($_GET['view'] ?? 'open') === 'closed' ? 'closed' : (($_GET['view'] ?? '') === 'removed' ? 'removed' : 'open');
$rows = [];
try {
    if ($view === 'removed') {
        $rows = $db->query("SELECT a.*, u.name, u.first_name, u.last_name, u.username FROM moderation_archive a LEFT JOIN users u ON u.id = a.removed_by ORDER BY a.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $where = $view === 'open' ? "r.status = 'pending'" : "r.status <> 'pending'";
        // one row per reported item, newest first, with how many people reported it
        $rows = $db->query("SELECT r.target_type, r.target_id, MIN(r.id) AS id, COUNT(*) AS n, MAX(r.created_at) AS last_at,
                                   GROUP_CONCAT(DISTINCT r.reason) AS reasons, GROUP_CONCAT(r.details, ' | ') AS details,
                                   MAX(r.status) AS status, MAX(r.outcome_note) AS outcome_note, MAX(r.resolved_at) AS resolved_at
                            FROM reports r WHERE $where GROUP BY r.target_type, r.target_id ORDER BY last_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('moderation list: ' . $e->getMessage());
}
$labels = ['question' => 'Question', 'answer' => 'Answer', 'review' => 'Comment', 'reflection_comment' => 'Reflection comment', 'video_comment' => 'Video comment'];
$open_count = 0;
try { $open_count = (int)$db->query("SELECT COUNT(DISTINCT target_type || ':' || target_id) FROM reports WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}

$pageTitle = 'Moderation';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:980px;">
        <div class="admin-header">
            <div><h1>Moderation</h1><p>Everything members have reported, in one place.</p></div>
            <div class="admin-actions"><?php echo aw_link_btn(SITE_URL . '/admin/dashboard.php', 'Back to Dashboard', 'outline', ['icon' => 'fa-arrow-left']); ?></div>
        </div>
        <?php echo aw_alert('error', $error); echo aw_alert('success', $notice); ?>

        <nav class="mod-tabs" aria-label="Moderation views">
            <a href="?view=open"<?php echo $view === 'open' ? ' aria-current="page"' : ''; ?>>To review<?php echo $open_count ? ' (' . $open_count . ')' : ''; ?></a>
            <a href="?view=closed"<?php echo $view === 'closed' ? ' aria-current="page"' : ''; ?>>Decided</a>
            <a href="?view=removed"<?php echo $view === 'removed' ? ' aria-current="page"' : ''; ?>>Removed</a>
        </nav>

        <?php if (!$rows): ?>
            <?php echo aw_empty($view === 'open' ? 'Nothing to review' : 'Nothing here yet',
                $view === 'open' ? 'When a member reports a comment, answer or question, it appears here with what they said.' : 'Decisions are kept here for reference.', null, 'fa-shield-heart'); ?>
        <?php elseif ($view === 'removed'): ?>
            <?php foreach ($rows as $a): $row = json_decode((string)$a['row_json'], true) ?: []; ?>
                <article class="mod-card">
                    <p class="mod-kind"><?php echo htmlspecialchars($labels[$a['target_type']] ?? $a['target_type']); ?> · removed <?php echo htmlspecialchars(date('M j, Y', strtotime($a['created_at']))); ?> by <?php echo htmlspecialchars($a['removed_by'] ? awDisplayName($a) : 'staff'); ?><?php echo $a['restored_at'] ? ' · restored ' . htmlspecialchars(date('M j', strtotime($a['restored_at']))) : ''; ?></p>
                    <blockquote class="mod-text"><?php echo nl2br(htmlspecialchars(mb_substr((string)($row['comment'] ?? $row['body'] ?? $row['content'] ?? $row['title'] ?? ''), 0, 600))); ?></blockquote>
                    <?php if (!$a['restored_at'] && aw_can('manage_content')): ?>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>"><button type="submit" name="restore" value="<?php echo (int)$a['id']; ?>" class="btn btn-outline btn-sm">Put it back</button></form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($rows as $r): $item = aw_report_item($db, $r['target_type'], (int)$r['target_id']); ?>
                <article class="mod-card">
                    <p class="mod-kind"><?php echo htmlspecialchars($labels[$r['target_type']] ?? $r['target_type']); ?> · reported by <?php echo (int)$r['n']; ?> <?php echo (int)$r['n'] === 1 ? 'person' : 'people'; ?> · <?php echo htmlspecialchars(date('M j, g:i a', strtotime($r['last_at']))); ?></p>
                    <p class="mod-reasons"><?php foreach (array_unique(explode(',', (string)$r['reasons'])) as $rs): ?><span><?php echo htmlspecialchars(AW_REPORT_REASONS[$rs] ?? $rs); ?></span><?php endforeach; ?></p>
                    <?php if ($item): ?>
                        <blockquote class="mod-text"><?php echo nl2br(htmlspecialchars(mb_substr(strip_tags((string)$item['text']), 0, 800))); ?></blockquote>
                        <p><a href="<?php echo htmlspecialchars($item['link']); ?>" target="_blank" rel="noopener">See it in place<span class="sr-only"> (opens in a new tab)</span></a></p>
                    <?php else: ?>
                        <p class="mod-gone">This has already been removed.</p>
                    <?php endif; ?>
                    <?php if (trim((string)$r['details'], ' |') !== ''): ?><p class="mod-details"><strong>What reporters said:</strong> <?php echo htmlspecialchars(trim((string)$r['details'], ' |')); ?></p><?php endif; ?>
                    <?php if ($view === 'open'): ?>
                    <form method="POST" class="mod-decide">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="report_id" value="<?php echo (int)$r['id']; ?>">
                        <label for="note<?php echo (int)$r['id']; ?>">Note to the reporter <span>(optional, they will see it)</span></label>
                        <input type="text" id="note<?php echo (int)$r['id']; ?>" name="note" maxlength="300">
                        <div class="mod-buttons">
                            <button type="submit" name="decide" value="keep" class="btn btn-outline btn-sm">Keep it</button>
                            <?php if ($item): ?><button type="submit" name="decide" value="remove" class="btn btn-sm mod-remove" onclick="return confirm('Remove this? The author will be told.');">Remove it</button><?php endif; ?>
                        </div>
                    </form>
                    <?php else: ?>
                        <p class="mod-outcome"><?php echo $r['status'] === 'action_taken' ? 'Removed' : 'Kept'; ?><?php echo $r['resolved_at'] ? ' on ' . htmlspecialchars(date('M j, Y', strtotime($r['resolved_at']))) : ''; ?><?php echo trim((string)$r['outcome_note']) !== '' ? ' — “' . htmlspecialchars($r['outcome_note']) . '”' : ''; ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<style>
.mod-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
.mod-tabs a { padding:10px 16px; min-height:44px; border-radius:999px; border:1px solid var(--border); text-decoration:none; color:var(--text); background:var(--card-bg); }
.mod-tabs a[aria-current] { background:var(--rose-dark, #a8676a); color:#fff; border-color:transparent; }
.mod-card { background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:18px 20px; margin-bottom:14px; }
.mod-kind { margin:0 0 6px; color:var(--text-light); font-size:.88rem; }
.mod-reasons { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 10px; }
.mod-reasons span { background:#f7ecdc; color:#6f4e22; border-radius:999px; padding:3px 10px; font-size:.8rem; font-weight:600; }
.mod-text { margin:0 0 8px; padding:10px 14px; border-left:3px solid var(--rose); background:var(--fantasy); border-radius:0 10px 10px 0; color:var(--text); overflow-wrap:anywhere; }
.mod-gone, .mod-outcome { color:var(--text-light); }
.mod-decide label { display:block; font-weight:600; margin:10px 0 4px; } .mod-decide label span { font-weight:400; color:var(--text-light); }
.mod-decide input[type=text] { width:100%; min-height:44px; border:1px solid var(--border); border-radius:10px; padding:8px 12px; background:var(--card-bg); color:var(--text); }
.mod-buttons { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; } .mod-buttons .btn { min-height:44px; }
.mod-remove { background:#b4505a; color:#fff; border:0; }
</style>
<?php require_once '../includes/footer.php'; ?>
