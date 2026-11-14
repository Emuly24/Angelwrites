<?php
/**
 * The team's dashboard: what needs doing, what is happening, and every tool, in one calm, lively page.
 * All figures come from the site's own records and are read once on the server (no polling):
 *  - views come from the one view log (includes/views.php), so they match the counters;
 *  - community counts include both the old Community Q&A and Stories & Help;
 *  - "unanswered" means no answer exists (not a stored counter that can drift).
 * Each role sees what it can act on (K1). Every query fails soft.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/dash.php';
if (is_file(__DIR__ . '/../includes/community_posts.php')) require_once '../includes/community_posts.php';

aw_require('staff_dashboard'); // K1

$D = 14;
// ----- daily series (last 14 days) -----
$sViews   = aw_dsh_daily($db, [['view_logs', 'viewed_at']], $D);
$sMembers = aw_dsh_daily($db, [['users', 'created_at']], $D);
$sComments = aw_dsh_daily($db, [['reviews', 'created_at', 'deleted_at IS NULL'], ['reflection_comments', 'created_at'], ['video_comments', 'created_at']], $D);
$sCommunity = aw_dsh_daily($db, [['questions', 'created_at'], ['answers', 'created_at'], ['community_posts', 'created_at', "status <> 'removed'"], ['community_replies', 'created_at', "status = 'published'"]], $D);
$sReading = aw_dsh_daily($db, [['reading_sessions', 'start_time']], $D);

$kpis = [];
foreach ([
    ['Views', 'fa-eye', $sViews, '#9c5457', null],
    ['New members', 'fa-user-plus', $sMembers, '#6d5bb0', aw_can('manage_members') ? 'manage_users.php' : null],
    ['Community posts & replies', 'fa-seedling', $sCommunity, '#c25f6b', aw_can('moderate') ? 'community.php' : null],
    ['Comments', 'fa-comment-dots', $sComments, '#3f8f86', aw_can('moderate') ? 'comments.php' : null],
    ['Reading sessions', 'fa-book-open-reader', $sReading, '#b7853a', null],
] as [$label, $icon, $series, $colour, $link]) {
    [$now, $prev] = aw_dsh_weeks($series);
    $kpis[] = compact('label', 'icon', 'series', 'colour', 'link', 'now', 'prev');
}

// ----- needs attention (only what this role can act on) -----
$attention = [];
foreach ([
    ['Orders to confirm', "SELECT COUNT(*) FROM payments WHERE status = 'pending'", 'manage_payments.php', 'manage_payments'],
    ['Session requests', "SELECT COUNT(*) FROM sessions WHERE status = 'pending'", 'manage_sessions.php?status=pending', 'manage_members'],
    ['Unread messages', "SELECT COUNT(*) FROM contact_messages WHERE is_read = 0", 'inbox.php', 'team_inbox'],
    ['Team conversations waiting', "SELECT COUNT(*) FROM msg_threads t WHERE t.kind = 'team' AND COALESCE(t.team_read_id, 0) < (SELECT COALESCE(MAX(id), 0) FROM messages m WHERE m.thread_id = t.id)", '../messages.php?view=team', 'team_inbox'],
    ['Open prayer requests', "SELECT COUNT(*) FROM prayer_requests WHERE COALESCE(status, 'pending') != 'prayed'", 'manage_prayer_requests.php', 'moderate'],
    ['Testimonials to review', "SELECT COUNT(*) FROM testimonials WHERE approved = 0 AND is_public = 1", 'manage_testimonials.php', 'moderate'],
    ['Reports to review', "SELECT COUNT(DISTINCT target_type || ':' || target_id) FROM reports WHERE status = 'pending'", 'moderation.php', 'moderate'],
    ['Stories waiting for a first read', "SELECT COUNT(*) FROM community_posts WHERE status = 'held' AND visibility <> 'team'", 'community.php', 'moderate'],
    ['Private requests not yet answered', "SELECT COUNT(*) FROM community_posts p WHERE visibility = 'team' AND status = 'published' AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.as_team = 1)", 'community.php?view=private', 'see_identities'],
    ['Questions with no answer', "SELECT COUNT(*) FROM questions q WHERE NOT EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id)", 'manage_questions.php', 'moderate'],
] as [$label, $sql, $link, $cap]) {
    if (!aw_can($cap)) continue;
    $n = aw_dsh_n($db, $sql);
    if ($n > 0) $attention[] = [$label, $n, $link];
}
$lastBackup = (string)aw_setting('last_backup_at', '');
$backupDays = $lastBackup !== '' ? (int)floor((time() - strtotime($lastBackup)) / 86400) : null;
if (aw_can('backup') && ($backupDays === null || $backupDays >= 7)) $attention[] = [$backupDays === null ? 'No backup yet: download one' : 'days since the last backup', $backupDays === null ? '!' : $backupDays, 'backup.php'];

// ----- most viewed this week (from the view log) -----
$top = aw_dsh_q($db, "SELECT v.target_type, v.target_id, COUNT(*) AS n,
        CASE v.target_type WHEN 'poem' THEN (SELECT title FROM poems WHERE id = v.target_id) WHEN 'book' THEN (SELECT title FROM books WHERE id = v.target_id)
             WHEN 'post' THEN (SELECT title FROM blog_posts WHERE id = v.target_id) WHEN 'video' THEN (SELECT title FROM videos WHERE id = v.target_id)
             WHEN 'question' THEN (SELECT title FROM questions WHERE id = v.target_id) END AS title,
        (SELECT slug FROM blog_posts WHERE id = v.target_id AND v.target_type = 'post') AS slug
    FROM view_logs v WHERE v.viewed_at >= datetime('now', '-7 days') AND v.target_type <> 'story' GROUP BY v.target_type, v.target_id ORDER BY n DESC LIMIT 6");
$topMax = $top ? max(1, (int)$top[0]['n']) : 1;
$kindLabel = ['poem' => 'Poem', 'book' => 'Book', 'post' => 'Reflection or blog post', 'video' => 'Video', 'question' => 'Community question'];
$kindLink = function ($r) {
    $u = rtrim(SITE_URL, '/');
    switch ($r['target_type']) {
        case 'poem': return $u . '/poem_view.php?id=' . (int)$r['target_id'];
        case 'book': return $u . '/book.php?id=' . (int)$r['target_id'];
        case 'post': return $u . '/blog_post.php?slug=' . urlencode((string)$r['slug']);
        case 'video': return $u . '/video_watch.php?id=' . (int)$r['target_id'];
        default: return $u . '/community.php?id=' . (int)$r['target_id'];
    }
};

// ----- community: questions still waiting, from both places -----
$waiting = [];
if (aw_can('moderate')) {
    foreach (aw_dsh_q($db, "SELECT q.id, q.title, q.created_at FROM questions q WHERE NOT EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id) ORDER BY q.created_at DESC LIMIT 6") as $q)
        $waiting[] = ['title' => $q['title'], 'when' => $q['created_at'], 'where' => 'Community Q&A', 'link' => SITE_URL . '/community.php?id=' . (int)$q['id']];
    foreach (aw_dsh_q($db, "SELECT p.id, p.title, p.type, p.created_at FROM community_posts p WHERE p.status = 'published' AND p.visibility <> 'team' AND p.type IN ('question', 'help')
                             AND NOT EXISTS (SELECT 1 FROM community_replies r WHERE r.post_id = p.id AND r.status = 'published') ORDER BY p.created_at DESC LIMIT 6") as $q)
        $waiting[] = ['title' => $q['title'], 'when' => $q['created_at'], 'where' => $q['type'] === 'help' ? 'Request for help' : 'Stories & Help question', 'link' => SITE_URL . '/story.php?id=' . (int)$q['id']];
    usort($waiting, function ($a, $b) { return strcmp($b['when'], $a['when']); });
    $waiting = array_slice($waiting, 0, 6);
}

$sessions = aw_can('manage_members') ? aw_dsh_q($db, "SELECT s.date, s.time, u.name, u.first_name, u.last_name, u.username FROM sessions s JOIN users u ON s.user_id = u.id WHERE s.status = 'pending' ORDER BY s.date, s.time LIMIT 5") : [];
$messages = aw_can('team_inbox') ? aw_dsh_q($db, "SELECT name, message, created_at FROM contact_messages WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5") : [];
$newMembers = aw_can('manage_members') ? aw_dsh_q($db, "SELECT name, first_name, last_name, username, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 5") : [];
$feed = aw_dsh_feed($db, 16);

$totals = [
    'members' => aw_dsh_n($db, "SELECT COUNT(*) FROM users"),
    'books' => aw_dsh_n($db, "SELECT COUNT(*) FROM books"),
    'poems' => aw_dsh_n($db, "SELECT COUNT(*) FROM poems"),
    'reflections & posts' => aw_dsh_n($db, "SELECT COUNT(*) FROM blog_posts"),
    'videos' => aw_dsh_n($db, "SELECT COUNT(*) FROM videos"),
    'community posts' => aw_dsh_n($db, "SELECT COUNT(*) FROM questions") + aw_dsh_n($db, "SELECT COUNT(*) FROM community_posts WHERE status <> 'removed'"),
];
$me = aw_dsh_q($db, "SELECT name, first_name, last_name, username FROM users WHERE id = ?", [(int)($_SESSION['user_id'] ?? 0)]);
$meName = $me ? explode(' ', awDisplayName($me[0]))[0] : 'there';
$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Admin Dashboard';
require_once '../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-dash.css?v=2">
<main class="dsh" id="main">
    <section class="dsh-hero dsh-reveal is-in" aria-labelledby="dshTitle">
        <div class="dsh-hero-top">
            <div>
                <p class="dsh-eyebrow"><?php echo htmlspecialchars(date('l, j F Y')); ?></p>
                <h1 id="dshTitle"><?php echo $greet; ?>, <?php echo htmlspecialchars($meName); ?></h1>
                <p class="dsh-sub"><?php echo $attention ? count($attention) . ' ' . (count($attention) === 1 ? 'thing is' : 'things are') . ' waiting for you below.' : 'Nothing is waiting for you. Here is what is happening on AngelWrites.'; ?></p>
            </div>
            <div class="dsh-hero-actions">
                <?php if (aw_can('manage_content')): ?><button type="button" class="dsh-pill dsh-pill-solid" onclick="openQuickModal()"><i class="fas fa-plus" aria-hidden="true"></i> Create</button><?php endif; ?>
                <?php if (aw_can('moderate')): ?><a class="dsh-pill" href="moderation.php"><i class="fas fa-shield-heart" aria-hidden="true"></i> Moderation</a><?php endif; ?>
                <a class="dsh-pill" href="<?php echo SITE_URL; ?>/index.php"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> View site</a>
            </div>
        </div>
        <p class="dsh-hero-strip"><?php foreach ($totals as $l => $n): ?><span><strong><?php echo number_format($n); ?></strong> <?php echo htmlspecialchars($l); ?></span><?php endforeach; ?></p>
    </section>

    <section class="dsh-kpis" aria-label="The last 7 days">
        <?php foreach ($kpis as $i => $k): [$dTxt, $dCls] = aw_dsh_delta($k['now'], $k['prev']); $tag = $k['link'] ? 'a' : 'div'; ?>
            <<?php echo $tag; ?> class="dsh-card dsh-kpi dsh-reveal" style="--i:<?php echo $i; ?>;--kc:<?php echo $k['colour']; ?>"<?php echo $k['link'] ? ' href="' . htmlspecialchars($k['link']) . '"' : ''; ?>>
                <span class="dsh-kpi-label"><i class="fas <?php echo $k['icon']; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($k['label']); ?></span>
                <span class="dsh-kpi-num" data-count="<?php echo (int)$k['now']; ?>"><?php echo number_format($k['now']); ?></span>
                <?php echo aw_dsh_spark($k['series']); ?>
                <span class="dsh-kpi-delta <?php echo $dCls; ?>"><?php echo htmlspecialchars($dTxt); ?><span class="sr-only"> (last 7 days: <?php echo (int)$k['now']; ?>, the 7 before: <?php echo (int)$k['prev']; ?>)</span></span>
            </<?php echo $tag; ?>>
        <?php endforeach; ?>
    </section>

    <section class="dsh-card dsh-reveal" style="--i:1;margin-bottom:18px" aria-labelledby="dshAttn">
        <h2 id="dshAttn"><span class="dsh-ico"><i class="fas fa-bell" aria-hidden="true"></i></span> Needs your attention</h2>
        <?php if (!$attention): ?>
            <p class="dsh-calm"><i class="fas fa-circle-check" aria-hidden="true"></i> All caught up. Orders, session requests, messages, reports and waiting stories will appear here.</p>
        <?php else: ?>
            <div class="dsh-attn">
                <?php foreach ($attention as [$label, $n, $link]): ?>
                    <a href="<?php echo htmlspecialchars($link); ?>"><span class="dsh-attn-n"><?php echo htmlspecialchars((string)$n); ?></span><span class="dsh-attn-t"><?php echo htmlspecialchars($label); ?></span></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dsh-row dsh-row-2">
        <section class="dsh-card dsh-reveal" style="--i:2" aria-labelledby="dshChart">
            <h2 id="dshChart"><span class="dsh-ico"><i class="fas fa-chart-line" aria-hidden="true"></i></span> The last 14 days
                <?php if (aw_can('see_identities')): ?><button type="button" class="dsh-more" style="background:none;border:0;cursor:pointer;font:inherit" onclick="openStatsModal('views')">Who viewed what</button><?php endif; ?></h2>
            <?php echo aw_dsh_chart([
                ['label' => 'Views', 'class' => 's1', 'data' => $sViews],
                ['label' => 'Community', 'class' => 's2', 'data' => $sCommunity],
                ['label' => 'Comments', 'class' => 's3', 'data' => $sComments],
                ['label' => 'New members', 'class' => 's4', 'data' => $sMembers],
            ], 'Views, community posts and replies, comments and new members each day for the last 14 days'); ?>
        </section>
        <section class="dsh-card dsh-reveal" style="--i:3" aria-labelledby="dshFeed">
            <h2 id="dshFeed"><span class="dsh-ico"><i class="fas fa-bolt" aria-hidden="true"></i></span> Happening now</h2>
            <?php if (!$feed): ?>
                <p class="dsh-empty">When members join, comment, ask, share or write to the team, it appears here.</p>
            <?php else: ?>
                <ol class="dsh-feed">
                <?php foreach ($feed as $i => [$when, $icon, $tone, $who, $what, $title, $link]): ?>
                    <li style="--i:<?php echo $i; ?>"><span class="dsh-fico <?php echo $tone; ?>" aria-hidden="true"><i class="fas <?php echo $icon; ?>"></i></span>
                        <span class="dsh-ftext"><a href="<?php echo htmlspecialchars($link); ?>"><strong><?php echo htmlspecialchars($who); ?></strong> <?php echo htmlspecialchars($what); ?><?php if ($title !== ''): ?> <strong class="dsh-ftitle">“<?php echo htmlspecialchars(aw_dsh_short($title, 60)); ?>”</strong><?php endif; ?></a>
                        <time datetime="<?php echo htmlspecialchars(str_replace(' ', 'T', (string)$when)); ?>Z"><?php echo htmlspecialchars(aw_dsh_ago($when)); ?></time></span></li>
                <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <div class="dsh-row dsh-row-3">
        <section class="dsh-card dsh-reveal" style="--i:4" aria-labelledby="dshTop">
            <h2 id="dshTop"><span class="dsh-ico"><i class="fas fa-fire" aria-hidden="true"></i></span> Most viewed this week</h2>
            <?php if (!$top): ?><p class="dsh-empty">Views are counted once per person every 30 minutes (robots and the team are left out). The most read pieces of the week will rise here.</p>
            <?php else: ?><ol class="dsh-rank"><?php foreach ($top as $i => $r): ?>
                <li style="--i:<?php echo $i; ?>"><a href="<?php echo htmlspecialchars($kindLink($r)); ?>"><?php echo htmlspecialchars($r['title'] ?: 'Removed item'); ?></a><span class="dsh-rn"><?php echo number_format((int)$r['n']); ?></span>
                    <span class="dsh-kind"><?php echo htmlspecialchars($kindLabel[$r['target_type']] ?? ''); ?></span>
                    <span class="dsh-bar" aria-hidden="true"><span style="--w:<?php echo round((int)$r['n'] * 100 / $topMax); ?>%"></span></span></li>
            <?php endforeach; ?></ol><?php endif; ?>
        </section>
        <?php if (aw_can('moderate')): ?>
        <section class="dsh-card dsh-reveal" style="--i:5" aria-labelledby="dshWait">
            <h2 id="dshWait"><span class="dsh-ico"><i class="fas fa-hands-holding-circle" aria-hidden="true"></i></span> Waiting for an answer <a class="dsh-more" href="manage_questions.php">All</a></h2>
            <?php if (!$waiting): ?><p class="dsh-empty">Every question and request for help has at least one answer.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($waiting as $w): ?>
                <li><a href="<?php echo htmlspecialchars($w['link']); ?>"><?php echo htmlspecialchars(aw_dsh_short($w['title'], 60)); ?><span class="dsh-sub"><?php echo htmlspecialchars($w['where']); ?></span></a><span class="dsh-meta"><?php echo htmlspecialchars(aw_dsh_ago($w['when'])); ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('manage_members')): ?>
        <section class="dsh-card dsh-reveal" style="--i:6" aria-labelledby="dshSess">
            <h2 id="dshSess"><span class="dsh-ico"><i class="fas fa-calendar-check" aria-hidden="true"></i></span> Session requests <a class="dsh-more" href="manage_sessions.php">All</a></h2>
            <?php if (!$sessions): ?><p class="dsh-empty">No requests waiting. When a member books a session it appears here to confirm.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($sessions as $s): ?>
                <li><a href="manage_sessions.php?status=pending"><?php echo htmlspecialchars(awDisplayName($s)); ?></a><span class="dsh-meta"><?php echo htmlspecialchars(date('D j M, g:i a', strtotime($s['date'] . ' ' . $s['time']))); ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('team_inbox')): ?>
        <section class="dsh-card dsh-reveal" style="--i:7" aria-labelledby="dshMsg">
            <h2 id="dshMsg"><span class="dsh-ico"><i class="fas fa-envelope-open-text" aria-hidden="true"></i></span> Unread messages <a class="dsh-more" href="inbox.php">Inbox</a></h2>
            <?php if (!$messages): ?><p class="dsh-empty">No unread messages from the contact form.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($messages as $m): ?>
                <li><a href="inbox.php"><?php echo htmlspecialchars($m['name'] ?: 'Someone'); ?><span class="dsh-sub"><?php echo htmlspecialchars(aw_dsh_short($m['message'], 70)); ?></span></a><span class="dsh-meta"><?php echo htmlspecialchars(aw_dsh_ago($m['created_at'])); ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('manage_members')): ?>
        <section class="dsh-card dsh-reveal" style="--i:8" aria-labelledby="dshNew">
            <h2 id="dshNew"><span class="dsh-ico"><i class="fas fa-user-plus" aria-hidden="true"></i></span> Newest members <a class="dsh-more" href="manage_users.php">All</a></h2>
            <?php if (!$newMembers): ?><p class="dsh-empty">No members yet.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($newMembers as $u): ?>
                <li><a href="manage_users.php"><?php echo htmlspecialchars(awDisplayName($u)); ?></a><span class="dsh-meta">joined <?php echo htmlspecialchars(aw_dsh_ago($u['created_at'])); ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <h2 class="dsh-tools-h">Your tools</h2>
    <div class="dsh-tools">
        <?php if (!aw_can('manage_content')): /* K1: a moderator's tools */ ?>
        <section class="dsh-card dsh-reveal" style="--i:1"><h3>Moderation</h3><div class="dsh-tiles">
            <a class="dsh-tile" href="moderation.php"><i class="fas fa-flag" aria-hidden="true"></i>Moderation</a>
            <a class="dsh-tile" href="community.php"><i class="fas fa-seedling" aria-hidden="true"></i>Stories &amp; Help</a>
            <a class="dsh-tile" href="comments.php"><i class="fas fa-comments" aria-hidden="true"></i>Comments</a>
            <a class="dsh-tile" href="manage_questions.php"><i class="fas fa-circle-question" aria-hidden="true"></i>Community Q&amp;A</a>
            <a class="dsh-tile" href="manage_testimonials.php"><i class="fas fa-quote-left" aria-hidden="true"></i>Testimonials</a>
            <a class="dsh-tile" href="manage_prayer_requests.php"><i class="fas fa-hands-praying" aria-hidden="true"></i>Prayer requests</a>
            <a class="dsh-tile" href="inbox.php"><i class="fas fa-inbox" aria-hidden="true"></i>Team inbox</a>
            <a class="dsh-tile" href="../messages.php?view=team"><i class="fas fa-heart" aria-hidden="true"></i>Team conversations</a>
            <a class="dsh-tile" href="../account_security.php"><i class="fas fa-lock" aria-hidden="true"></i>Account security</a>
        </div></section>
        <?php else: ?>
        <section class="dsh-card dsh-reveal" style="--i:1"><h3>Books &amp; poetry <a href="manage_poems.php?new=1">+ New poem</a></h3><div class="dsh-tiles">
            <a class="dsh-tile" href="manage_books.php"><i class="fas fa-book" aria-hidden="true"></i>Books</a>
            <a class="dsh-tile" href="process_book.php"><i class="fas fa-gear" aria-hidden="true"></i>Process a book</a>
            <a class="dsh-tile" href="manage_poems.php"><i class="fas fa-feather" aria-hidden="true"></i>Poems</a>
            <a class="dsh-tile" href="picks.php"><i class="fas fa-star" aria-hidden="true"></i>Angella's picks</a>
        </div></section>
        <section class="dsh-card dsh-reveal" style="--i:2"><h3>Reflections &amp; blog <a href="reflection_editor.php">+ New reflection</a></h3><div class="dsh-tiles">
            <a class="dsh-tile" href="manage_reflections.php"><i class="fas fa-dove" aria-hidden="true"></i>Reflections</a>
            <a class="dsh-tile" href="manage_blog.php"><i class="fas fa-blog" aria-hidden="true"></i>Blog</a>
            <a class="dsh-tile" href="editor.php"><i class="fas fa-pen-fancy" aria-hidden="true"></i>Blog editor</a>
            <a class="dsh-tile" href="manage_videos.php"><i class="fas fa-video" aria-hidden="true"></i>Videos</a>
        </div></section>
        <section class="dsh-card dsh-reveal" style="--i:3"><h3>Community &amp; care</h3><div class="dsh-tiles">
            <a class="dsh-tile" href="community.php"><i class="fas fa-seedling" aria-hidden="true"></i>Stories &amp; Help</a>
            <a class="dsh-tile" href="manage_questions.php"><i class="fas fa-circle-question" aria-hidden="true"></i>Community Q&amp;A</a>
            <a class="dsh-tile" href="moderation.php"><i class="fas fa-flag" aria-hidden="true"></i>Moderation</a>
            <a class="dsh-tile" href="comments.php"><i class="fas fa-comments" aria-hidden="true"></i>Comments</a>
            <a class="dsh-tile" href="inbox.php"><i class="fas fa-inbox" aria-hidden="true"></i>Team inbox</a>
            <a class="dsh-tile" href="../messages.php?view=team"><i class="fas fa-heart" aria-hidden="true"></i>Team conversations</a>
            <a class="dsh-tile" href="manage_prayer_requests.php"><i class="fas fa-hands-praying" aria-hidden="true"></i>Prayer requests</a>
            <a class="dsh-tile" href="manage_testimonials.php"><i class="fas fa-quote-left" aria-hidden="true"></i>Testimonials</a>
            <a class="dsh-tile" href="manage_groups.php"><i class="fas fa-users-gear" aria-hidden="true"></i>Reading groups</a>
            <a class="dsh-tile" href="manage_sessions.php"><i class="fas fa-calendar-check" aria-hidden="true"></i>Sessions</a>
        </div></section>
        <section class="dsh-card dsh-reveal" style="--i:4"><h3>Members &amp; money</h3><div class="dsh-tiles">
            <a class="dsh-tile" href="manage_users.php"><i class="fas fa-users" aria-hidden="true"></i>Members</a>
            <a class="dsh-tile" href="manage_payments.php"><i class="fas fa-money-bill-wave" aria-hidden="true"></i>Payments</a>
            <a class="dsh-tile" href="manage_newsletter.php"><i class="fas fa-envelope" aria-hidden="true"></i>Newsletter</a>
            <a class="dsh-tile" href="manage_newsletter.php?tab=send"><i class="fas fa-paper-plane" aria-hidden="true"></i>Send a campaign</a>
        </div></section>
        <section class="dsh-card dsh-reveal" style="--i:5"><h3>System</h3><div class="dsh-tiles">
            <a class="dsh-tile" href="settings.php"><i class="fas fa-sliders" aria-hidden="true"></i>Settings</a>
            <a class="dsh-tile" href="backup.php"><i class="fas fa-database" aria-hidden="true"></i>Backup</a>
            <?php if (aw_can('manage_settings')): ?><a class="dsh-tile" href="db_setup.php"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>Database setup</a><?php endif; ?>
            <a class="dsh-tile" href="../reader/admin/reader_analytics.php"><i class="fas fa-chart-line" aria-hidden="true"></i>Reader analytics</a>
            <?php if (aw_can('see_identities')): ?><button type="button" class="dsh-tile" style="font:inherit;font-size:.78rem;font-weight:600;cursor:pointer" onclick="openStatsModal('reading')"><i class="fas fa-book-open-reader" aria-hidden="true"></i>Reading sessions</button><?php endif; ?>
            <a class="dsh-tile" href="../account_security.php"><i class="fas fa-lock" aria-hidden="true"></i>Account security</a>
            <?php if (aw_can('manage_staff')): ?><a class="dsh-tile" href="staff.php"><i class="fas fa-user-shield" aria-hidden="true"></i>Staff &amp; roles</a><?php endif; ?>
            <?php if (aw_can('audit_log')): ?><a class="dsh-tile" href="audit.php"><i class="fas fa-clipboard-list" aria-hidden="true"></i>Audit log</a><?php endif; ?>
        </div></section>
        <?php endif; ?>
    </div>

    <div id="statsModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="statsModalTitle">
        <div class="modal-box">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:16px">
                <h2 id="statsModalTitle" style="margin:0">Loading…</h2>
                <button type="button" onclick="closeStatsModal()" aria-label="Close" style="background:transparent;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-light)">&times;</button>
            </div>
            <div id="statsModalBody" style="max-height:60vh;overflow-y:auto;font-size:.9rem"><p>Fetching…</p></div>
        </div>
    </div>
    <div class="modal-overlay" id="quickModal" role="dialog" aria-modal="true" aria-labelledby="quickTitle">
        <div class="modal-box">
            <h2 id="quickTitle">Create something</h2>
            <div style="display:flex;flex-direction:column;gap:6px">
                <a href="manage_books.php?action=new" class="btn btn-primary">+ New book</a>
                <a href="manage_poems.php?new=1" class="btn btn-primary">+ New poem</a>
                <a href="reflection_editor.php" class="btn btn-primary">+ New reflection</a>
                <a href="manage_blog.php?action=new" class="btn btn-primary">+ New blog post</a>
                <a href="manage_videos.php?action=new" class="btn btn-primary">+ New video</a>
                <a href="manage_newsletter.php?tab=send" class="btn btn-primary">+ New newsletter</a>
                <button type="button" onclick="closeQuickModal()" class="btn btn-outline" style="margin-top:6px">Cancel</button>
            </div>
        </div>
    </div>
</main>
<script src="<?php echo SITE_URL; ?>/assets/js/aw-dash.js?v=1" defer></script>
<script>
function openQuickModal() { document.getElementById('quickModal').classList.add('active'); }
function closeQuickModal() { document.getElementById('quickModal').classList.remove('active'); }
document.getElementById('quickModal').addEventListener('click', function (e) { if (e.target === this) closeQuickModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeQuickModal(); closeStatsModal(); } });
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function openStatsModal(type) { // fetched only when the admin asks
    var m = document.getElementById('statsModal'), body = document.getElementById('statsModalBody'), title = document.getElementById('statsModalTitle');
    m.classList.add('active'); body.innerHTML = '<p>Fetching…</p>';
    title.textContent = type === 'views' ? 'Who viewed what (latest 50)' : 'Reading sessions (latest)';
    fetch('<?php echo SITE_URL; ?>/ajax_admin.php?action=' + (type === 'views' ? 'get_view_details' : 'get_reading_details'), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success || !d.logs || !d.logs.length) { body.innerHTML = '<p>Nothing yet.</p>'; return; }
            var h = '<table><thead><tr><th>Who</th><th>What</th><th>When</th></tr></thead><tbody>';
            d.logs.forEach(function (r) {
                if (type === 'views') h += '<tr><td>' + (r.viewer_name === 'Guest' ? 'Guest <small>(' + esc(r.ip_address || '') + ')</small>' : '<strong>' + esc(r.viewer_name) + '</strong>') + '</td><td><strong>' + esc(r.content_title) + '</strong><br><small>' + esc(r.target_type) + '</small></td><td>' + esc(new Date(String(r.viewed_at).replace(' ', 'T') + 'Z').toLocaleString()) + '</td></tr>';
                else { var mm = Math.floor((r.duration_seconds || 0) / 60); h += '<tr><td><strong>' + esc(r.user_name) + '</strong></td><td>' + esc(r.book_title || 'A book') + '</td><td>' + esc(new Date(String(r.start_time).replace(' ', 'T') + 'Z').toLocaleString()) + '<br><small>' + mm + ' min</small></td></tr>'; }
            });
            body.innerHTML = h + '</tbody></table>';
        })
        .catch(function () { body.innerHTML = '<p>That could not be loaded. Please try again.</p>'; });
}
function closeStatsModal() { document.getElementById('statsModal').classList.remove('active'); }
document.getElementById('statsModal').addEventListener('click', function (e) { if (e.target === this) closeStatsModal(); });
</script>
<?php require_once '../includes/footer.php'; ?>
