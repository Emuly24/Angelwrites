<?php
/**
 * Team desk: the daily workspace for the people who look after the community (owner, admins and
 * moderators). It is not the admin dashboard (site figures and every tool) and not the member
 * dashboard (your own reading): it answers "what needs a person now, and what should the next person
 * on duty know?"
 *
 *  - Someone may need help now: reports about self-harm or danger, and private requests or stories
 *    that mention crisis topics, always first.
 *  - One queue, oldest first, across every place where members wait for the team, each with how long
 *    it has waited. Each role sees only what it may act on (K1); moderators never see who is behind a
 *    pseudonym, so the queue shows titles and places, never authors.
 *  - Assigned to you: the social platforms you oversee (Admin > Social accounts).
 *  - Handover notes: a shared board for the team (who followed up with whom, what to check tomorrow).
 *  - What you did this week, from the audit log: a plain record, no scores.
 * Everything is read once when the page opens (no polling). Every query fails soft.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/dash.php';
if (is_file(__DIR__ . '/../includes/community_posts.php')) require_once '../includes/community_posts.php';
if (is_file(__DIR__ . '/../includes/social_accounts.php')) require_once '../includes/social_accounts.php';
if (is_file(__DIR__ . '/../includes/moderation.php')) require_once '../includes/moderation.php'; // AW_REPORT_REASONS

aw_require('staff_dashboard'); // owner, admins and moderators; checks the CSRF token on every POST

$td_me = (int)$_SESSION['user_id'];

// ----- handover notes -------------------------------------------------------------------------
function aw_td_notes_table(PDO $db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS team_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            pinned INTEGER NOT NULL DEFAULT 0,
            done_at DATETIME,
            done_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return true;
    } catch (Throwable $e) { error_log('team notes table: ' . $e->getMessage()); return false; }
}
aw_td_notes_table($db);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['note_action'] ?? '');
    $nid = (int)($_POST['note_id'] ?? 0);
    try {
        if ($act === 'add') {
            $body = trim(strip_tags((string)($_POST['body'] ?? '')));
            if ($body === '') $_SESSION['aw_td_flash'] = ['error', 'Write the note first.'];
            elseif (mb_strlen($body) > 1000) $_SESSION['aw_td_flash'] = ['error', 'Notes are kept short: 1,000 characters at most.'];
            else {
                $db->prepare("INSERT INTO team_notes (user_id, body, pinned) VALUES (?, ?, ?)")->execute([$td_me, $body, !empty($_POST['pinned']) ? 1 : 0]);
                $_SESSION['aw_td_flash'] = ['ok', 'Your note is on the board for the team.'];
            }
        } elseif ($act === 'done' && $nid) {
            $db->prepare("UPDATE team_notes SET done_at = CURRENT_TIMESTAMP, done_by = ? WHERE id = ? AND done_at IS NULL")->execute([$td_me, $nid]);
        } elseif ($act === 'reopen' && $nid) {
            $db->prepare("UPDATE team_notes SET done_at = NULL, done_by = NULL WHERE id = ?")->execute([$nid]);
        } elseif ($act === 'pin' && $nid) {
            $db->prepare("UPDATE team_notes SET pinned = 1 - pinned WHERE id = ?")->execute([$nid]);
        } elseif ($act === 'delete' && $nid) {
            // your own notes; admins may tidy any
            $db->prepare("DELETE FROM team_notes WHERE id = ?" . (aw_can('manage_members') ? '' : ' AND user_id = ' . $td_me))->execute([$nid]);
        }
    } catch (Throwable $e) {
        error_log('team notes: ' . $e->getMessage());
        $_SESSION['aw_td_flash'] = ['error', 'That did not save. Please try again.'];
    }
    header('Location: ' . SITE_URL . '/admin/team.php#notes');
    exit;
}
$td_flash = $_SESSION['aw_td_flash'] ?? null;
unset($_SESSION['aw_td_flash']);

// ----- someone may need help now -------------------------------------------------------------
$td_urgent = [];
if (aw_can('moderate')) {
    foreach (aw_dsh_q($db, "SELECT target_type, target_id, MIN(created_at) AS since, COUNT(*) AS n FROM reports WHERE status = 'pending' AND reason = 'harm' GROUP BY target_type, target_id ORDER BY since LIMIT 10") as $r)
        $td_urgent[] = ['what' => 'Reported: self-harm or someone in danger' . ($r['n'] > 1 ? ' (' . (int)$r['n'] . ' reports)' : ''), 'since' => $r['since'], 'link' => 'moderation.php', 'act' => 'Open the report'];
}
if (aw_can('community_team')) { // moderators and the Stories & Help helpers
    $crisis = defined('AW_CP_CRISIS_WARNINGS') ? AW_CP_CRISIS_WARNINGS : ['selfharm', 'suicide', 'abuse', 'violence'];
    $like = implode(' OR ', array_map(function ($w) { return "warnings LIKE '%" . preg_replace('/[^a-z]/', '', $w) . "%'"; }, $crisis));
    $vis = aw_can('see_identities') ? '' : " AND visibility <> 'team'";
    foreach (aw_dsh_q($db, "SELECT id, title, visibility, status, created_at FROM community_posts p WHERE ($like) AND status IN ('held', 'published')$vis
                            AND p.created_at >= datetime('now', '-14 days')
                            AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.as_team = 1) ORDER BY p.created_at LIMIT 10") as $p)
        $td_urgent[] = ['what' => ($p['visibility'] === 'team' ? 'Private request' : ($p['status'] === 'held' ? 'Story waiting for a first read' : 'Story')) . ' about a painful topic, no reply from the team yet: "' . $p['title'] . '"',
                        'since' => $p['created_at'], 'link' => ($p['visibility'] === 'team' ? 'community.php?view=private' : ($p['status'] === 'held' ? 'community.php' : '../story.php?id=' . (int)$p['id'])), 'act' => 'Reach out'];
}

// ----- one queue, oldest first ---------------------------------------------------------------
$td_queue = [];
$td_add = function ($rows, $place, $icon, $link, $title_key = 'title') use (&$td_queue) {
    foreach ($rows as $r) $td_queue[] = ['place' => $place, 'icon' => $icon, 'title' => (string)($r[$title_key] ?? ''), 'since' => (string)$r['since'], 'link' => is_callable($link) ? $link($r) : $link];
};
if (aw_can('moderate')) {
    $td_add(aw_dsh_q($db, "SELECT reason AS title, MIN(created_at) AS since FROM reports WHERE status = 'pending' AND reason <> 'harm' GROUP BY target_type, target_id ORDER BY since LIMIT 15"),
        'Report', 'fa-flag', 'moderation.php');
    foreach ($td_queue as &$q) if ($q['place'] === 'Report' && defined('AW_REPORT_REASONS')) $q['title'] = AW_REPORT_REASONS[$q['title']] ?? $q['title'];
    unset($q);
}
if (aw_can('community_team')) {
    $td_add(aw_dsh_q($db, "SELECT title, created_at AS since FROM community_posts WHERE status = 'held' AND visibility <> 'team' ORDER BY created_at LIMIT 15"), 'Story waiting for a first read', 'fa-seedling', 'community.php');
    $td_add(aw_dsh_q($db, "SELECT id, title, created_at AS since FROM community_posts p WHERE status = 'published' AND visibility <> 'team' AND type IN ('question', 'help')
                           AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.status = 'published') ORDER BY created_at LIMIT 15"),
        'Asked for help, no reply yet', 'fa-hand-holding-heart', function ($r) { return '../story.php?id=' . (int)$r['id']; });
    $td_add(aw_dsh_q($db, "SELECT id, title, created_at AS since FROM questions q WHERE NOT EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id) ORDER BY created_at LIMIT 15"),
        'Question with no answer', 'fa-circle-question', function ($r) { return '../community.php?id=' . (int)$r['id']; });
}
if (aw_can('prayer_team')) {
    $td_add(aw_dsh_q($db, "SELECT COALESCE(NULLIF(request_type, ''), 'Prayer request') AS title, created_at AS since FROM prayer_requests WHERE COALESCE(status, 'pending') NOT IN ('prayed', 'answered', 'removed') ORDER BY created_at LIMIT 15"),
        'Prayer request', 'fa-hands-praying', 'manage_prayer_requests.php');
}
if (aw_can('comments_team')) {
    $td_add(aw_dsh_q($db, "SELECT substr(testimony, 1, 80) AS title, created_at AS since FROM testimonials WHERE approved = 0 AND is_public = 1 ORDER BY created_at LIMIT 15"),
        'Testimonial to review', 'fa-quote-left', 'manage_testimonials.php');
}
if (aw_can('see_identities')) {
    $td_add(aw_dsh_q($db, "SELECT title, created_at AS since FROM community_posts p WHERE visibility = 'team' AND status = 'published'
                           AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.as_team = 1) ORDER BY created_at LIMIT 15"),
        'Private request to the team', 'fa-lock', 'community.php?view=private');
}
if (aw_can('team_inbox')) {
    $td_add(aw_dsh_q($db, "SELECT 'A member wrote to the team' AS title, t.last_message_at AS since FROM msg_threads t WHERE t.kind = 'team'
                           AND COALESCE(t.team_read_id, 0) < (SELECT COALESCE(MAX(id), 0) FROM messages m WHERE m.thread_id = t.id) ORDER BY t.last_message_at LIMIT 15"),
        'Team message', 'fa-comments', '../messages.php?view=team');
    $td_add(aw_dsh_q($db, "SELECT COALESCE(NULLIF(subject, ''), 'Message from the contact form') AS title, created_at AS since FROM contact_messages WHERE is_read = 0 ORDER BY created_at LIMIT 15"),
        'Contact form', 'fa-envelope', 'inbox.php');
}
if (aw_can('manage_content')) {
    $td_add(aw_dsh_q($db, "SELECT title, created_at AS since FROM member_books WHERE status = 'waiting' ORDER BY created_at LIMIT 15"), 'Book shared by a member', 'fa-book', 'member_books.php');
}
if (aw_can('mentoring_team') && !aw_can('manage_members')) { // the mentoring coordinator sees only mentoring
    $td_add(aw_dsh_q($db, "SELECT CASE status WHEN 'proposed' THEN 'Waiting for a mentor to answer' ELSE 'Asked for a mentor' END AS title, created_at AS since FROM mentor_requests WHERE status IN ('waiting', 'proposed') ORDER BY created_at LIMIT 15"), 'Mentoring request', 'fa-hands-holding-circle', 'mentoring.php');
    $td_add(aw_dsh_q($db, "SELECT 'Offered to mentor' AS title, created_at AS since FROM mentors WHERE status = 'pending' ORDER BY created_at LIMIT 15"), 'Mentor offer', 'fa-hand-holding-heart', 'mentoring.php');
}
if (aw_can('manage_members')) {
    $td_add(aw_dsh_q($db, "SELECT 'Session on ' || date || ' at ' || time AS title, created_at AS since FROM sessions WHERE status = 'pending' ORDER BY created_at LIMIT 15"), 'Session request', 'fa-calendar-check', 'manage_sessions.php?status=pending');
    $td_add(aw_dsh_q($db, "SELECT CASE status WHEN 'proposed' THEN 'Waiting for a mentor to answer' ELSE 'Asked for a mentor' END AS title, created_at AS since FROM mentor_requests WHERE status IN ('waiting', 'proposed') ORDER BY created_at LIMIT 15"), 'Mentoring request', 'fa-hands-holding-circle', 'mentoring.php');
    $td_add(aw_dsh_q($db, "SELECT 'Offered to mentor' AS title, created_at AS since FROM mentors WHERE status = 'pending' ORDER BY created_at LIMIT 15"), 'Mentor offer', 'fa-hand-holding-heart', 'mentoring.php');
}
if (aw_can('manage_payments')) {
    $td_add(aw_dsh_q($db, "SELECT 'Order #' || id || ' · ' || COALESCE(payment_method, '') AS title, created_at AS since FROM payments WHERE status = 'pending' ORDER BY created_at LIMIT 15"), 'Order to confirm', 'fa-receipt', 'manage_payments.php');
}
usort($td_queue, function ($a, $b) { return strcmp($a['since'], $b['since']); }); // oldest first: nobody waits longest
$td_total = count($td_queue);
// every kind of request gets seen: the oldest few of each, still in overall waiting order
$td_by_place = []; $td_place_link = []; $td_show = [];
foreach ($td_queue as $q) {
    $td_by_place[$q['place']] = ($td_by_place[$q['place']] ?? 0) + 1;
    $td_place_link[$q['place']] = $td_place_link[$q['place']] ?? $q['link'];
    if ($td_by_place[$q['place']] <= 4) $td_show[] = $q;
}
$td_age = function ($when) {
    $t = strtotime((string)$when);
    if (!$t) return ['', ''];
    $h = (time() - $t) / 3600;
    $label = $h < 1 ? 'just now' : ($h < 24 ? floor($h) . ' h' : floor($h / 24) . ' day' . (floor($h / 24) == 1 ? '' : 's'));
    return [$label, $h >= 24 * 7 ? 'is-late' : ($h >= 48 ? 'is-slow' : '')];
};

// ----- assigned to you ----------------------------------------------------------------------
$td_mine = [];
if (function_exists('aw_social_accounts')) {
    foreach (aw_social_accounts($db) as $a) {
        if (in_array($td_me, (array)($a['overseers'] ?? []), true)) $td_mine[] = $a;
    }
}

// ----- notes and your week --------------------------------------------------------------------
$td_notes = aw_dsh_q($db, "SELECT n.*, u.name, u.first_name, u.last_name, u.username FROM team_notes n LEFT JOIN users u ON u.id = n.user_id
                           WHERE n.done_at IS NULL OR n.done_at >= datetime('now', '-3 days') ORDER BY (n.done_at IS NULL) DESC, n.pinned DESC, n.created_at DESC LIMIT 30");
// owner and admins see what every member of the team did lately (the audit log, newest first)
$td_team_log = aw_can('audit_log') ? aw_dsh_q($db, "SELECT a.action, a.target_type, a.target_id, a.created_at, a.actor_role, a.actor_id, u.name, u.first_name, u.last_name, u.username
    FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE a.actor_id IS NOT NULL AND a.action NOT LIKE 'staff_%' AND a.created_at >= datetime('now', '-7 days')
    ORDER BY a.id DESC LIMIT 14") : [];
$td_week = aw_dsh_q($db, "SELECT action, COUNT(*) AS n FROM audit_log WHERE actor_id = ? AND created_at >= datetime('now', '-7 days')
                          AND action NOT LIKE 'staff_%' AND action <> 'access_denied' GROUP BY action ORDER BY n DESC LIMIT 8", [$td_me]);
$td_week_total = array_sum(array_map(function ($r) { return (int)$r['n']; }, $td_week));
$td_words = function ($action) {
    $map = ['report_' => 'Reports handled', 'cp_' => 'Stories & Help', 'user_delete' => 'Accounts deleted', 'member_book_' => 'Shared books reviewed', 'site_text_save' => 'Page text changed',
            'book_' => 'Books', 'role_changed' => 'Roles changed', 'social_' => 'Social accounts', 'msg_' => 'Messages', 'inbox_' => 'Team inbox'];
    foreach ($map as $k => $v) if (strpos($action, $k) === 0) return $v;
    return ucfirst(str_replace('_', ' ', $action));
};

$td_name = explode(' ', awDisplayName(aw_dsh_q($db, "SELECT name, first_name, last_name, username FROM users WHERE id = ?", [$td_me])[0] ?? []))[0];
$td_role = function_exists('aw_my_staff_label') ? aw_my_staff_label() : aw_role_label(aw_my_role());
$h = (int)date('G');

$pageTitle = 'Team desk';
require_once '../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-dash.css?v=4">
<div class="td">
    <header class="td-hero">
        <div>
            <p class="td-kicker"><i class="fas fa-hands-holding-circle" aria-hidden="true"></i> Team desk · <?php echo htmlspecialchars($td_role); ?></p>
            <h1><?php echo $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening'); ?>, <?php echo htmlspecialchars($td_name); ?></h1>
            <p class="td-lead"><?php if ($td_urgent): ?><strong><?php echo count($td_urgent); ?> <?php echo count($td_urgent) === 1 ? 'person may' : 'people may'; ?> need help now.</strong> <?php endif; ?>
                <?php echo $td_total ? $td_total . ' ' . ($td_total === 1 ? 'thing is' : 'things are') . ' waiting for the team, oldest first.' : 'Nothing is waiting for the team. Thank you for caring for this community.'; ?></p>
        </div>
        <nav class="td-hero-acts" aria-label="Team tools">
            <?php if (aw_can('moderate')): ?><a href="moderation.php"><i class="fas fa-shield-heart" aria-hidden="true"></i> Moderation</a><?php endif; ?>
            <?php if (aw_can('team_inbox')): ?><a href="inbox.php"><i class="fas fa-inbox" aria-hidden="true"></i> Team inbox</a><?php endif; ?>
            <?php if (aw_can('community_team')): ?><a href="community.php"><i class="fas fa-seedling" aria-hidden="true"></i> Stories &amp; Help</a><?php endif; ?>
            <?php // a volunteer's own tools: one button per page their area uses (moderators and admins already have theirs)
            if (!aw_can('manage_members') && function_exists('aw_user_areas')) { $td_seen = ['admin/inbox.php' => aw_can('team_inbox'), 'admin/community.php' => aw_can('community_team')];
                foreach (aw_user_areas($td_me) as $td_a) foreach (AW_POSITIONS[$td_a]['pages'] as [$td_pl, $td_pu]) { if (!empty($td_seen[$td_pu])) continue; $td_seen[$td_pu] = true; ?>
                <a href="<?php echo SITE_URL . '/' . $td_pu; ?>"><i class="fas <?php echo AW_POSITIONS[$td_a]['icon']; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($td_pl); ?></a>
            <?php } } ?>
            <?php if (function_exists('aw_user_areas') && aw_user_areas($td_me)): ?><a href="<?php echo SITE_URL; ?>/staff_invite.php"><i class="fas fa-id-badge" aria-hidden="true"></i> My roles</a><?php endif; ?>
            <?php if (aw_can('invite_staff')): ?><a href="invite_staff.php"><i class="fas fa-user-plus" aria-hidden="true"></i> Invite to the team</a><?php endif; ?>
            <?php // the right dashboard for each person: admins to the admin dashboard, other staff to their own member dashboard ?>
            <?php if (aw_can('manage_members')): ?><a href="dashboard.php"><i class="fas fa-gauge-high" aria-hidden="true"></i> Admin dashboard</a>
            <?php else: ?><a href="<?php echo SITE_URL; ?>/dashboard.php"><i class="fas fa-house-user" aria-hidden="true"></i> My dashboard</a><?php endif; ?>
        </nav>
    </header>

    <?php if ($td_flash): ?><div class="td-alert <?php echo $td_flash[0] === 'ok' ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($td_flash[1]); ?></div><?php endif; ?>

    <?php if ($td_urgent): ?>
    <section class="td-urgent" aria-labelledby="tdUrgent">
        <h2 id="tdUrgent"><i class="fas fa-heart-pulse" aria-hidden="true"></i> Someone may need help now</h2>
        <p class="td-sub">Answer these first. A gentle, personal message helps; if someone is in immediate danger, encourage them to call their local emergency number or a helpline (findahelpline.com).</p>
        <ul>
            <?php foreach ($td_urgent as $u): [$age, $cls] = $td_age($u['since']); ?>
            <li><span><?php echo htmlspecialchars($u['what']); ?> <small>waiting <?php echo htmlspecialchars($age); ?></small></span><a class="td-btn td-btn-urgent" href="<?php echo htmlspecialchars($u['link']); ?>"><?php echo htmlspecialchars($u['act']); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <div class="td-grid">
        <section class="td-card td-queue" aria-labelledby="tdQueue">
            <div class="td-card-head"><h2 id="tdQueue"><i class="fas fa-list-check" aria-hidden="true"></i> Waiting for the team</h2><span class="td-count"><?php echo $td_total; ?></span></div>
            <?php if ($td_by_place): ?>
            <p class="td-places"><?php foreach ($td_by_place as $p => $n): ?><a href="<?php echo htmlspecialchars($td_place_link[$p]); ?>"><?php echo htmlspecialchars($p); ?> <b><?php echo $n; ?></b></a><?php endforeach; ?></p>
            <?php endif; ?>
            <?php if (!$td_queue): ?>
                <div class="td-empty"><i class="fas fa-circle-check" aria-hidden="true"></i><p>All caught up. New requests appear here as soon as members send them.</p></div>
            <?php else: ?>
            <ol class="td-list">
                <?php foreach ($td_show as $i => $q): [$age, $cls] = $td_age($q['since']); ?>
                <li class="<?php echo $cls; ?>" style="--i:<?php echo $i; ?>">
                    <span class="td-ico" aria-hidden="true"><i class="fas <?php echo htmlspecialchars($q['icon']); ?>"></i></span>
                    <span class="td-what"><span class="td-place"><?php echo htmlspecialchars($q['place']); ?></span><span class="td-title"><?php echo htmlspecialchars(mb_strimwidth($q['title'], 0, 110, '…')); ?></span></span>
                    <span class="td-age" title="Waiting since <?php echo htmlspecialchars(date('j M Y, H:i', strtotime($q['since']))); ?>"><?php echo htmlspecialchars($age); ?></span>
                    <a class="td-btn" href="<?php echo htmlspecialchars($q['link']); ?>" aria-label="Open: <?php echo htmlspecialchars($q['place'] . ', ' . $q['title']); ?>">Open</a>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($td_total > count($td_show)): ?><p class="td-sub">Showing the longest-waiting few of each kind (<?php echo count($td_show); ?> of <?php echo $td_total; ?>); the counts above open the full lists.</p><?php endif; ?>
            <p class="td-legend"><span class="is-slow">waiting 2+ days</span><span class="is-late">waiting a week or more</span></p>
            <?php endif; ?>
        </section>

        <div class="td-side">
            <section class="td-card" id="notes" aria-labelledby="tdNotes">
                <div class="td-card-head"><h2 id="tdNotes"><i class="fas fa-note-sticky" aria-hidden="true"></i> Handover notes</h2></div>
                <p class="td-sub">For the next person on duty: who you followed up with, what to check tomorrow. Visible to the whole team; never put a member's private story here.</p>
                <form method="post" class="td-note-form">
                    <input type="hidden" name="note_action" value="add">
                    <label class="sr-only" for="tdNote">New note</label>
                    <textarea id="tdNote" name="body" rows="3" maxlength="1000" required placeholder="e.g. Replied to the private request from Tuesday; please check for an answer on Friday."></textarea>
                    <div class="td-note-row"><label class="td-pin"><input type="checkbox" name="pinned" value="1"> Pin to the top</label><button type="submit" class="td-btn td-btn-primary">Add note</button></div>
                </form>
                <?php if (!$td_notes): ?><p class="td-sub" style="margin-top:12px;">No notes yet.</p><?php else: ?>
                <ul class="td-notes">
                    <?php foreach ($td_notes as $n): $done = !empty($n['done_at']); ?>
                    <li class="<?php echo $done ? 'is-done' : ''; ?><?php echo !empty($n['pinned']) && !$done ? ' is-pinned' : ''; ?>">
                        <p><?php echo nl2br(htmlspecialchars($n['body'])); ?></p>
                        <div class="td-note-meta">
                            <span><?php echo htmlspecialchars(awDisplayName($n)); ?> · <?php echo htmlspecialchars(date('j M, H:i', strtotime($n['created_at']))); ?><?php echo $done ? ' · done' : ''; ?></span>
                            <span class="td-note-acts">
                                <form method="post"><input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>">
                                    <?php if (!$done): ?>
                                    <button name="note_action" value="done" class="td-mini" title="Mark as done" aria-label="Mark as done"><i class="fas fa-check" aria-hidden="true"></i></button>
                                    <button name="note_action" value="pin" class="td-mini" title="<?php echo !empty($n['pinned']) ? 'Unpin' : 'Pin to the top'; ?>" aria-label="<?php echo !empty($n['pinned']) ? 'Unpin' : 'Pin to the top'; ?>"><i class="fas fa-thumbtack" aria-hidden="true"></i></button>
                                    <?php else: ?>
                                    <button name="note_action" value="reopen" class="td-mini" title="Open again" aria-label="Open again"><i class="fas fa-rotate-left" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                    <?php if ((int)$n['user_id'] === $td_me || aw_can('manage_members')): ?><button name="note_action" value="delete" class="td-mini td-mini-del" title="Delete note" aria-label="Delete note" onclick="return confirm('Delete this note?');"><i class="fas fa-trash" aria-hidden="true"></i></button><?php endif; ?>
                                </form>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <?php if ($td_mine): ?>
            <section class="td-card" aria-labelledby="tdMine">
                <div class="td-card-head"><h2 id="tdMine"><i class="fas fa-share-nodes" aria-hidden="true"></i> Platforms you look after</h2></div>
                <ul class="td-mine">
                    <?php foreach ($td_mine as $a): ?>
                    <li><i class="<?php echo htmlspecialchars($a['icon']); ?>" aria-hidden="true"></i> <span><?php echo htmlspecialchars($a['label']); ?> <small><?php echo !empty($a['is_live']) ? 'live' : 'not set up yet'; ?></small></span><a class="td-btn" href="social_accounts.php">Open</a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <section class="td-card" aria-labelledby="tdWeek">
                <div class="td-card-head"><h2 id="tdWeek"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Your week</h2></div>
                <?php if (!$td_week): ?><p class="td-sub">Nothing recorded for you in the last 7 days.</p><?php else: ?>
                <p class="td-sub">What you looked after in the last 7 days (from the team log).</p>
                <ul class="td-week"><?php foreach ($td_week as $w): ?><li><span><?php echo htmlspecialchars($td_words($w['action'])); ?></span><b><?php echo (int)$w['n']; ?></b></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </section>

            <?php if ($td_team_log): ?>
            <section class="td-card" aria-labelledby="tdTeamLog">
                <div class="td-card-head"><h2 id="tdTeamLog"><i class="fas fa-eye" aria-hidden="true"></i> What the team did</h2><a class="td-btn" href="audit.php">Full log</a></div>
                <ul class="td-log">
                    <?php foreach ($td_team_log as $l): ?>
                    <li><b><?php echo htmlspecialchars(awDisplayName($l)); ?></b> <small><?php echo htmlspecialchars(aw_role_label((string)$l['actor_role'])); ?></small><br><span><?php echo htmlspecialchars($td_words($l['action'])); ?><?php echo $l['target_type'] ? ' · ' . htmlspecialchars($l['target_type']) . ($l['target_id'] ? ' #' . (int)$l['target_id'] : '') : ''; ?></span> <small><?php echo htmlspecialchars(date('j M, H:i', strtotime($l['created_at']))); ?></small>
                        <a class="td-log-who" href="audit.php?actor=<?php echo (int)$l['actor_id']; ?>" aria-label="Everything <?php echo htmlspecialchars(awDisplayName($l)); ?> did">all</a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
            <section class="td-card" aria-labelledby="tdGuide">
                <div class="td-card-head"><h2 id="tdGuide"><i class="fas fa-compass" aria-hidden="true"></i> Good to have at hand</h2></div>
                <ul class="td-guide">
                    <li><a href="../community_guidelines.php">Community guidelines</a> <small>what members promised each other</small></li>
                    <li><a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a> <small>free helplines in every country</small></li>
                    <?php if (aw_can('team_inbox')): ?><li><a href="inbox.php">Team inbox and saved replies</a> <small>answer as "AngelWrites Team"</small></li><?php endif; ?>
                    <?php if (aw_can('moderate')): ?><li><a href="community.php?view=settings">Crisis contacts and Stories settings</a></li><?php endif; ?>
                </ul>
                <p class="td-sub" style="margin-top:10px;">When in doubt: listen first, never judge, keep what members share private, and bring anything serious to Angella.</p>
            </section>
        </div>
    </div>
</div>

<style>
.td { --td-rose: #9c5457; --td-ink: var(--text, #3a2629); --td-mute: #6d5a5a; --td-card: var(--card-bg, #fff); --td-line: rgba(156, 84, 87, .16);
      max-width: 1200px; margin: 0 auto; padding: 26px 16px 64px; color: var(--td-ink); }
html[data-theme="dark"] .td { --td-rose: #f0c4c5; --td-mute: #cdb8b8; --td-line: rgba(240, 196, 197, .16); }
.td .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.td-hero { display: flex; flex-wrap: wrap; gap: 18px; justify-content: space-between; align-items: flex-end; padding: 28px; border-radius: 26px; color: #fff;
           background: radial-gradient(110% 130% at 0% 0%, rgba(232, 196, 140, .35), transparent 50%), linear-gradient(135deg, #2f4a4a 0%, #4b3a5c 55%, #7d3f4f 100%); box-shadow: 0 20px 44px rgba(47, 40, 60, .3); }
.td-kicker { margin: 0; font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 700; opacity: .9; }
.td-hero h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.7rem, 4vw, 2.4rem); color: #fff; }
.td-lead { margin: 0; max-width: 46em; line-height: 1.55; opacity: .95; }
.td-hero-acts { display: flex; flex-wrap: wrap; gap: 8px; }
.td-hero-acts a { display: inline-flex; gap: 8px; align-items: center; min-height: 44px; padding: 0 14px; border-radius: 999px; background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .4); color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem; }
.td-hero-acts a:hover { background: #fff; color: #3b2f4a; }
.td-alert { margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .td-alert.is-ok { background: #e3efe6; color: #24452f; } .td-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.td-urgent { margin: 18px 0 0; padding: 18px 20px; border-radius: 20px; background: #fff1ee; border: 1.5px solid #e9a99f; color: #4a1c17; }
html[data-theme="dark"] .td-urgent { background: #3a1f1c; border-color: #8a4a42; color: #ffe1dc; }
.td-urgent h2 { margin: 0 0 4px; font-size: 1.15rem; display: flex; gap: 10px; align-items: center; }
.td-urgent ul { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 8px; }
.td-urgent li { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 14px; background: rgba(255, 255, 255, .7); }
html[data-theme="dark"] .td-urgent li { background: rgba(0, 0, 0, .2); }
.td-urgent small { opacity: .8; margin-left: 6px; }
.td-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 16px; margin-top: 18px; align-items: start; }
.td-side { display: grid; gap: 16px; }
.td-card { background: var(--td-card); border: 1px solid var(--td-line); border-radius: 22px; padding: 18px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); }
.td-card-head { display: flex; align-items: center; gap: 10px; justify-content: space-between; }
.td-card h2 { margin: 0; font-size: 1.08rem; display: flex; gap: 10px; align-items: center; }
.td-card h2 i { color: var(--td-rose); }
.td-count { min-width: 34px; padding: 4px 10px; border-radius: 999px; background: var(--td-rose); color: #fff; font-weight: 700; text-align: center; }
html[data-theme="dark"] .td-count { color: #2c1e1e; }
.td-sub { margin: 6px 0 0; color: var(--td-mute); font-size: .88rem; line-height: 1.5; }
.td-places { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 4px; }
.td-places a { padding: 4px 10px; border-radius: 999px; background: rgba(156, 84, 87, .08); font-size: .8rem; color: inherit; text-decoration: none; min-height: 28px; display: inline-flex; align-items: center; }
.td-places a:hover { background: rgba(156, 84, 87, .18); }
.td-places b { color: var(--td-rose); margin-left: 4px; }
.td-list { list-style: none; margin: 10px 0 0; padding: 0; }
.td-list li { display: grid; grid-template-columns: 38px minmax(0, 1fr) auto auto; gap: 12px; align-items: center; padding: 10px 6px; border-top: 1px solid var(--td-line);
              animation: tdIn .4s ease both; animation-delay: calc(var(--i) * 25ms); }
.td-list li:first-child { border-top: 0; }
@keyframes tdIn { from { opacity: 0; transform: translateY(6px); } }
.td-ico { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; background: rgba(156, 84, 87, .1); color: var(--td-rose); }
.td-what { min-width: 0; display: grid; }
.td-place { font-size: .76rem; font-weight: 700; color: var(--td-mute); text-transform: uppercase; letter-spacing: .04em; }
.td-title { font-weight: 600; overflow-wrap: anywhere; }
.td-age { font-size: .82rem; font-weight: 600; padding: 3px 9px; border-radius: 999px; background: rgba(156, 84, 87, .08); white-space: nowrap; }
.td-list li.is-slow .td-age, .td-legend .is-slow { background: #fbecd4; color: #6b4310; }
.td-list li.is-late .td-age, .td-legend .is-late { background: #f8d7d3; color: #7a1f17; }
html[data-theme="dark"] .td-list li.is-slow .td-age, html[data-theme="dark"] .td-legend .is-slow { background: #4a3a20; color: #ffe2b0; }
html[data-theme="dark"] .td-list li.is-late .td-age, html[data-theme="dark"] .td-legend .is-late { background: #4f2420; color: #ffd0ca; }
.td-legend { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 0; font-size: .78rem; } .td-legend span { padding: 3px 9px; border-radius: 999px; }
.td-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1.5px solid var(--td-rose); color: var(--td-rose); background: transparent; font-weight: 600; font-size: .88rem; text-decoration: none; cursor: pointer; white-space: nowrap; }
.td-btn:hover { background: var(--td-rose); color: #fff; } html[data-theme="dark"] .td-btn:hover { color: #2c1e1e; }
.td-btn-primary { background: var(--td-rose); color: #fff; } html[data-theme="dark"] .td-btn-primary { color: #2c1e1e; }
.td-btn-urgent { border-color: #b3261e; color: #fff; background: #b3261e; } .td-btn-urgent:hover { background: #8c1d17; color: #fff; }
.td-empty { text-align: center; padding: 26px 10px; color: var(--td-mute); } .td-empty i { font-size: 1.8rem; color: #2f6b45; }
.td-note-form textarea { width: 100%; margin-top: 10px; padding: 10px 12px; border-radius: 14px; font: inherit; line-height: 1.5; resize: vertical; }
.td-note-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.td-pin { display: inline-flex; gap: 8px; align-items: center; font-size: .88rem; cursor: pointer; } .td-pin input { accent-color: #9c5457; width: 18px; height: 18px; }
.td-notes { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 8px; }
.td-notes li { padding: 10px 12px; border-radius: 14px; background: #fffaf2; border: 1px solid #f0dfc4; }
html[data-theme="dark"] .td-notes li { background: #2e2620; border-color: #4a3d2e; }
.td-notes li.is-pinned { border-color: #d9a85c; box-shadow: inset 4px 0 0 #d9a85c; }
.td-notes li.is-done { opacity: .65; } .td-notes li.is-done p { text-decoration: line-through; }
.td-notes p { margin: 0 0 6px; line-height: 1.5; overflow-wrap: anywhere; }
.td-note-meta { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: .78rem; color: var(--td-mute); flex-wrap: wrap; }
.td-note-acts form { display: flex; gap: 4px; margin: 0; }
.td-mini { width: 34px; height: 34px; border-radius: 10px; border: 1px solid var(--td-line); background: transparent; color: var(--td-rose); cursor: pointer; }
.td-mini:hover { background: var(--td-rose); color: #fff; } .td-mini-del { color: #b3261e; } .td-mini-del:hover { background: #b3261e; }
.td-mine, .td-week, .td-guide { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 8px; }
.td-mine li { display: flex; gap: 10px; align-items: center; } .td-mine li span { flex: 1; } .td-mine small { color: var(--td-mute); margin-left: 6px; } .td-mine i { color: var(--td-rose); width: 20px; text-align: center; }
.td-week li { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--td-line); } .td-week b { color: var(--td-rose); }
.td-guide a { color: var(--td-rose); font-weight: 600; }
.td-log { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 8px; font-size: .88rem; } .td-log li { padding-bottom: 8px; border-bottom: 1px dashed var(--td-line); } .td-log small { color: var(--td-mute); } .td-log-who { float: right; color: var(--td-rose); font-weight: 600; } .td-guide small { display: block; color: var(--td-mute); }
@media (max-width: 900px) { .td-grid { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .td-list li { grid-template-columns: 34px minmax(0, 1fr) auto; } .td-list .td-btn { grid-column: 2 / -1; justify-self: start; } .td-hero { padding: 22px 18px; } }
@media (prefers-reduced-motion: reduce) { .td-list li { animation: none; } }
</style>
<?php require_once '../includes/footer.php'; ?>
