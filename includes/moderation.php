<?php
/**
 * N4: one way to report anything, one moderation queue, and blocks (PROGRAM_PLAN.md Phase 2).
 *
 * Reports use the existing reports table for every content type in AW_REPORT_TYPES. A moderator
 * either keeps the content (the report is closed) or removes it: the row is copied to
 * moderation_archive first (so an admin can restore it) and then removed, or, for poem/book
 * comments, soft-deleted with deleted_at as before. Reporters hear the outcome; an author hears
 * when their writing is removed. Every decision is written to the audit log.
 *
 * Blocks (blocks table): a member who blocks another gets no notifications or mentions from them,
 * and that member's comments are folded away for them. Messaging (Phase 5) honours the same table.
 *
 * Nothing here throws; every function returns a value and logs failures.
 */
require_once __DIR__ . '/notifications.php';

const AW_REPORT_REASONS = [
    'hurtful'    => 'Unkind or hurtful',
    'harassment' => 'Harassment or bullying',
    'harm'       => 'Talks about self-harm or someone in danger',
    'private'    => 'Shares someone\'s private information',
    'spam'       => 'Spam or advertising',
    'other'      => 'Something else',
];

/** What can be reported: table, author column, text column, and how to link to it. */
function aw_report_types() {
    return [
        'question'           => ['questions', 'user_id', "title || ' — ' || body", "'community.php?id=' || id", 'archive'],
        'answer'             => ['answers', 'user_id', 'body', "'community.php?id=' || question_id", 'archive'],
        'review'             => ['reviews', 'user_id', 'comment', "CASE target_type WHEN 'poem' THEN 'poem_view.php?id=' || target_id WHEN 'book' THEN 'book.php?id=' || target_id ELSE 'index.php' END || '#comments'", 'soft'],
        'reflection_comment' => ['reflection_comments', 'user_id', 'comment', "'blog_post.php?slug=' || (SELECT slug FROM blog_posts b WHERE b.id = reflection_id) || '#comments'", 'archive'],
        'video_comment'      => ['video_comments', 'user_id', 'content', "'video_watch.php?id=' || video_id", 'archive'],
        // D1: Stories & Help. 'status' mode: removal sets status = 'removed' (kept 90 days, then purged)
        'community_post'     => ['community_posts', 'author_id', "title || ' — ' || body", "'story.php?id=' || id", 'status'],
        'community_reply'    => ['community_replies', 'author_id', 'body', "'story.php?id=' || post_id || '#reply-' || id", 'status'],
    ];
}

function aw_moderation_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS blocks (blocker_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (blocker_id, blocked_id))");
        $db->exec("CREATE TABLE IF NOT EXISTS moderation_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
                   row_json TEXT NOT NULL, removed_by INTEGER, reason TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, restored_at DATETIME)");
        $cols = array_column($db->query("PRAGMA table_info(reports)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($cols && !in_array('resolved_at', $cols, true)) {
            // reports gains its decision; existing rows are untouched (NULL)
            $db->exec("ALTER TABLE reports ADD COLUMN resolved_at DATETIME");
            $db->exec("ALTER TABLE reports ADD COLUMN resolved_by INTEGER");
            $db->exec("ALTER TABLE reports ADD COLUMN outcome_note TEXT");
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('moderation tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** The reported item: ['author_id', 'text', 'link'] or null when it no longer exists. */
function aw_report_item(PDO $db, $type, $id) {
    $t = aw_report_types()[$type] ?? null;
    if (!$t) return null;
    [$table, $authorCol, $textExpr, $linkExpr, $mode] = $t;
    try {
        $extra = $mode === 'soft' ? ' AND deleted_at IS NULL' : ($mode === 'status' ? " AND status <> 'removed'" : '');
        $st = $db->prepare("SELECT $authorCol AS author_id, $textExpr AS text, $linkExpr AS link FROM $table WHERE id = ?$extra");
        $st->execute([(int)$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['link'] = SITE_URL . '/' . ltrim((string)$r['link'], '/');
        // a team-only post (or a reply on one) is shown only to the owner and admins (decision 15)
        if (($type === 'community_post' || $type === 'community_reply') && !(function_exists('aw_can') && aw_can('see_identities'))) {
            $pid = $type === 'community_post' ? (int)$id : (int)$db->query("SELECT post_id FROM community_replies WHERE id = " . (int)$id)->fetchColumn();
            if ((string)$db->query("SELECT visibility FROM community_posts WHERE id = " . $pid)->fetchColumn() === 'team') {
                $r['text'] = 'A private post for the team. An admin will review this report.';
                $r['link'] = SITE_URL . '/admin/moderation.php';
            }
        }
        return $r;
    } catch (Throwable $e) {
        error_log('report item: ' . $e->getMessage());
        return null;
    }
}

/** File a report. Returns ['ok' => bool, 'message' => string]. */
function aw_report_create(PDO $db, $reporter_id, $type, $id, $reason, $details = '') {
    if (!aw_moderation_tables($db)) return ['ok' => false, 'message' => 'Reporting is not available right now. Please try again later.'];
    if (!isset(aw_report_types()[$type])) return ['ok' => false, 'message' => 'This cannot be reported.'];
    if (!isset(AW_REPORT_REASONS[$reason])) return ['ok' => false, 'message' => 'Please choose a reason.'];
    $item = aw_report_item($db, $type, $id);
    if (!$item) return ['ok' => false, 'message' => 'This has already been removed.'];
    if ((int)$item['author_id'] === (int)$reporter_id) return ['ok' => false, 'message' => 'You cannot report your own writing. You can edit or delete it instead.'];
    $details = trim(strip_tags((string)$details));
    $details = function_exists('mb_substr') ? mb_substr($details, 0, 1000) : substr($details, 0, 1000);
    try {
        $dup = $db->prepare("SELECT 1 FROM reports WHERE reporter_user_id = ? AND target_type = ? AND target_id = ? AND status = 'pending'");
        $dup->execute([(int)$reporter_id, $type, (int)$id]);
        if ($dup->fetchColumn()) return ['ok' => true, 'message' => 'You have already reported this. The team will look at it.'];
        $db->prepare("INSERT INTO reports (reporter_user_id, target_type, target_id, reason, details) VALUES (?, ?, ?, ?, ?)")
           ->execute([(int)$reporter_id, $type, (int)$id, $reason, $details]);
        // tell the staff who moderate (bell only; the admin email below keeps working as before)
        foreach ($db->query("SELECT id, role FROM users WHERE role IN ('admin', 'moderator')")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            awNotify($db, (int)$s['id'], 'report_new', 'New report to review', AW_REPORT_REASONS[$reason], SITE_URL . '/admin/moderation.php', ['email' => false, 'actor' => (int)$reporter_id]);
        }
        if (function_exists('sendEmail') && function_exists('aw_admin_email')) {
            sendEmail(aw_admin_email(), 'New report to review', "A member reported something (" . AW_REPORT_REASONS[$reason] . ").\n\nReview it here: " . SITE_URL . "/admin/moderation.php");
        }
        return ['ok' => true, 'message' => 'Thank you. The team will look at it. You will hear what was decided.'];
    } catch (Throwable $e) {
        error_log('report create: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The report could not be sent. Please try again.'];
    }
}

/**
 * Decide a report: 'keep' closes every pending report on that item; 'remove' takes the item down
 * (archived first) and closes them as action taken. $note is shown to the reporters.
 */
function aw_report_decide(PDO $db, $report_id, $decision, $note = '') {
    if (!aw_moderation_tables($db)) return false;
    $st = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $st->execute([(int)$report_id]);
    $rep = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rep) return false;
    $type = (string)$rep['target_type']; $tid = (int)$rep['target_id'];
    $note = trim(strip_tags((string)$note));
    $me = (int)($_SESSION['user_id'] ?? 0);
    try {
        $item = aw_report_item($db, $type, $tid);
        $db->beginTransaction();
        if ($decision === 'remove' && $item) {
            [$table, , , , $mode] = aw_report_types()[$type];
            $row = $db->prepare("SELECT * FROM $table WHERE id = ?");
            $row->execute([$tid]);
            $db->prepare("INSERT INTO moderation_archive (target_type, target_id, row_json, removed_by, reason) VALUES (?, ?, ?, ?, ?)")
               ->execute([$type, $tid, json_encode($row->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE), $me, (string)$rep['reason']]);
            if ($mode === 'soft') $db->prepare("UPDATE $table SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$tid]);
            elseif ($mode === 'status') $db->prepare("UPDATE $table SET status = 'removed', updated_at = CURRENT_TIMESTAMP" . ($table === 'community_posts' ? ', removed_at = CURRENT_TIMESTAMP' : '') . " WHERE id = ?")->execute([$tid]);
            else $db->prepare("DELETE FROM $table WHERE id = ?")->execute([$tid]);
            if ($type === 'answer') { try { $db->exec("UPDATE questions SET answers_count = (SELECT COUNT(*) FROM answers a WHERE a.question_id = questions.id)"); } catch (Throwable $e) {} }
        }
        $status = $decision === 'remove' ? 'action_taken' : 'dismissed';
        $reporters = $db->prepare("SELECT DISTINCT reporter_user_id FROM reports WHERE target_type = ? AND target_id = ? AND status = 'pending'");
        $reporters->execute([$type, $tid]);
        $who = $reporters->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare("UPDATE reports SET status = ?, resolved_at = CURRENT_TIMESTAMP, resolved_by = ?, outcome_note = ? WHERE target_type = ? AND target_id = ? AND status = 'pending'")
           ->execute([$status, $me, $note, $type, $tid]);
        $db->commit();
        $msg = $decision === 'remove' ? 'We removed what you reported. Thank you for looking out for others.' : 'We looked at what you reported and decided to leave it up.';
        if ($note !== '') $msg .= ' Note from the team: ' . $note;
        foreach ($who as $rid) awNotify($db, (int)$rid, 'report_outcome', 'Your report was reviewed', $msg, null);
        if ($decision === 'remove' && $item && (int)$item['author_id']) {
            awNotify($db, (int)$item['author_id'], 'post_removed', 'Something you wrote was removed',
                'It did not fit the community guidelines' . ($note !== '' ? ': ' . $note : '.') . ' If you think this was a mistake, reply through Contact.', SITE_URL . '/contact.php');
        }
        aw_audit($decision === 'remove' ? 'content_removed' : 'report_dismissed', $type, $tid, 'report #' . (int)$report_id . ($note !== '' ? ' — ' . $note : ''));
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('report decide: ' . $e->getMessage());
        return false;
    }
}

/** Put removed content back (admins). */
function aw_moderation_restore(PDO $db, $archive_id) {
    if (!aw_moderation_tables($db)) return false;
    try {
        $st = $db->prepare("SELECT * FROM moderation_archive WHERE id = ? AND restored_at IS NULL");
        $st->execute([(int)$archive_id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return false;
        $t = aw_report_types()[$a['target_type']] ?? null;
        if (!$t) return false;
        [$table, , , , $mode] = $t;
        $row = json_decode((string)$a['row_json'], true);
        $db->beginTransaction();
        if ($mode === 'soft') {
            $db->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ?")->execute([(int)$a['target_id']]);
        } elseif ($mode === 'status') {
            $db->prepare("UPDATE $table SET status = 'published', updated_at = CURRENT_TIMESTAMP" . ($table === 'community_posts' ? ', removed_at = NULL' : '') . " WHERE id = ?")->execute([(int)$a['target_id']]);
        } elseif (is_array($row) && $row) {
            $cols = array_keys($row);
            $db->prepare("INSERT OR IGNORE INTO $table (" . implode(',', array_map(function ($c) { return '"' . str_replace('"', '', $c) . '"'; }, $cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
               ->execute(array_values($row));
        }
        $db->prepare("UPDATE moderation_archive SET restored_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$archive_id]);
        $db->commit();
        aw_audit('content_restored', $a['target_type'], (int)$a['target_id'], 'archive #' . (int)$archive_id);
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('moderation restore: ' . $e->getMessage());
        return false;
    }
}

// ===== BLOCKS =======================================================================

/** Has $blocker blocked $blocked? */
function aw_is_blocked(PDO $db, $blocker, $blocked) {
    if (!$blocker || !$blocked || !aw_moderation_tables($db)) return false;
    try {
        $st = $db->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
        $st->execute([(int)$blocker, (int)$blocked]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { error_log('is blocked: ' . $e->getMessage()); return false; }
}

/** The ids this member has blocked: [id => true]. */
function aw_blocked_ids(PDO $db, $user_id) {
    static $cache = [];
    $user_id = (int)$user_id;
    if (!$user_id) return [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    if (!aw_moderation_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT blocked_id FROM blocks WHERE blocker_id = ?");
        $st->execute([$user_id]);
        return $cache[$user_id] = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) { error_log('blocked ids: ' . $e->getMessage()); return []; }
}

function aw_block(PDO $db, $blocker, $blocked, $on = true) {
    $blocker = (int)$blocker; $blocked = (int)$blocked;
    if (!$blocker || !$blocked || $blocker === $blocked || !aw_moderation_tables($db)) return false;
    try {
        if ($on) {
            $r = $db->prepare("SELECT role FROM users WHERE id = ?"); $r->execute([$blocked]);
            if (in_array((string)$r->fetchColumn(), ['admin', 'moderator'], true)) return false; // the team cannot be blocked
            $db->prepare("INSERT OR IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)")->execute([$blocker, $blocked]);
        } else {
            $db->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?")->execute([$blocker, $blocked]);
        }
        return true;
    } catch (Throwable $e) { error_log('block: ' . $e->getMessage()); return false; }
}

/** The report/block controls under a comment (shown to signed-in members other than the author). */
function aw_report_controls($type, $id, $author_id, $author_label) {
    $me = (int)($_SESSION['user_id'] ?? 0);
    if (!$me || (int)$author_id === $me) return '';
    return '<span class="aw-rb" data-type="' . htmlspecialchars($type) . '" data-id="' . (int)$id . '" data-user="' . (int)$author_id . '" data-name="' . htmlspecialchars($author_label) . '">'
         . '<button type="button" class="aw-rb-btn" data-act="report">Report</button>'
         . '<button type="button" class="aw-rb-btn" data-act="block">Block</button></span>';
}

/** The dialog and script behind the controls; printed once. */
function aw_report_assets() {
    static $done = false;
    if ($done || empty($_SESSION['user_id'])) return '';
    $done = true;
    $t = htmlspecialchars(function_exists('generate_csrf_token') ? generate_csrf_token() : (string)($_SESSION['csrf_token'] ?? ''));
    $reasons = '';
    foreach (AW_REPORT_REASONS as $k => $l) $reasons .= '<label class="aw-rb-reason"><input type="radio" name="reason" value="' . $k . '" required> <span>' . htmlspecialchars($l) . '</span></label>';
    $url = htmlspecialchars(SITE_URL);
    return <<<HTML
<div class="aw-rb-dialog" id="awReportDialog" role="dialog" aria-modal="true" aria-labelledby="awRbTitle" hidden>
  <form class="aw-rb-box" id="awReportForm">
    <h2 id="awRbTitle">Report this</h2>
    <p class="aw-rb-sub">The team reads every report. The person will not know who reported them.</p>
    <fieldset><legend class="sr-only">Reason</legend>{$reasons}</fieldset>
    <label for="awRbDetails" class="aw-rb-label">Anything the team should know? <span>(optional)</span></label>
    <textarea id="awRbDetails" name="details" rows="3" maxlength="1000"></textarea>
    <p class="aw-rb-msg" role="status" aria-live="polite"></p>
    <div class="aw-rb-actions"><button type="button" class="btn btn-outline btn-sm" data-close>Cancel</button><button type="submit" class="btn btn-primary btn-sm">Send report</button></div>
  </form>
</div>
<style>
.aw-rb { display:inline-flex; gap:4px; margin-left:8px; }
.aw-rb-btn { background:none; border:0; padding:4px 6px; min-height:32px; font:inherit; font-size:.8rem; color:var(--text-light); cursor:pointer; text-decoration:underline; }
.aw-rb-btn:hover, .aw-rb-btn:focus-visible { color:var(--rose-dark, #a8676a); }
.aw-rb-dialog { position:fixed; inset:0; z-index:3000; background:rgba(30,20,20,.55); display:grid; place-items:center; padding:16px; }
.aw-rb-dialog[hidden] { display:none; }
.aw-rb-box { width:min(460px,100%); max-height:90vh; overflow:auto; background:var(--card-bg); color:var(--text); border-radius:18px; padding:22px; }
.aw-rb-box h2 { font-family:'Playfair Display',Georgia,serif; margin:0 0 4px; font-size:1.3rem; }
.aw-rb-sub { color:var(--text-light); margin:0 0 12px; font-size:.92rem; }
.aw-rb-box fieldset { border:0; padding:0; margin:0 0 12px; display:grid; gap:6px; }
.aw-rb-reason { display:flex; gap:10px; align-items:center; padding:8px 10px; border:1px solid var(--border); border-radius:10px; cursor:pointer; min-height:44px; }
.aw-rb-reason input { width:20px; height:20px; accent-color:var(--rose-dark, #a8676a); }
.aw-rb-label { display:block; font-weight:600; margin-bottom:6px; } .aw-rb-label span { font-weight:400; color:var(--text-light); }
.aw-rb-box textarea { width:100%; border:1px solid var(--border); border-radius:10px; padding:10px; background:var(--card-bg); color:var(--text); font:inherit; }
.aw-rb-msg { min-height:1.2em; margin:8px 0; font-weight:600; }
.aw-rb-actions { display:flex; justify-content:flex-end; gap:8px; } .aw-rb-actions .btn { min-height:44px; }
.aw-blocked-fold { color:var(--text-light); font-size:.9rem; margin:6px 0; } .aw-blocked-fold summary { cursor:pointer; }
</style>
<script>
(function () {
  var dlg = document.getElementById('awReportDialog'), form = document.getElementById('awReportForm'), msg = form.querySelector('.aw-rb-msg'), cur = null, opener = null, token = '{$t}';
  function post(url, data) { data.append('csrf_token', token); return fetch(url, { method: 'POST', body: data, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function close() { dlg.hidden = true; if (opener) opener.focus(); }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.aw-rb-btn'); if (!b) return;
    var box = b.closest('.aw-rb'); opener = b;
    if (b.dataset.act === 'report') { cur = box; form.reset(); msg.textContent = ''; dlg.hidden = false; form.querySelector('input[name=reason]').focus(); return; }
    if (!confirm('Block ' + box.dataset.name + '? You will not get notifications or mentions from them, and their comments will be folded away for you. You can undo this in your notifications settings.')) return;
    var d = new FormData(); d.append('user_id', box.dataset.user); d.append('block', '1');
    post('{$url}/block.php', d).then(function (r) { alert(r.message || 'Done.'); if (r.ok) location.reload(); }).catch(function () { alert('That did not work. Please try again.'); });
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.hasAttribute('data-close')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !dlg.hidden) close(); });
  form.addEventListener('submit', function (e) {
    e.preventDefault(); if (!cur) return;
    var d = new FormData(form); d.append('target_type', cur.dataset.type); d.append('target_id', cur.dataset.id);
    msg.textContent = 'Sending…';
    post('{$url}/report.php', d).then(function (r) { msg.textContent = r.message || ''; if (r.ok) setTimeout(close, 1600); }).catch(function () { msg.textContent = 'That did not work. Please try again.'; });
  });
})();
</script>
HTML;
}
