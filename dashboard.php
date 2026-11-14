<?php
/**
 * The member's dashboard: what is waiting for you, where you left off, and what is new.
 * Real things only, read once on the server (no polling). No points, streaks, levels or progress bars.
 * Every query fails soft.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/dash.php';
foreach (['community_posts', 'saves', 'reading_plans', 'messaging', 'bible_versions'] as $aw_inc) if (is_file(__DIR__ . '/includes/' . $aw_inc . '.php')) require_once __DIR__ . '/includes/' . $aw_inc . '.php';

redirectIfNotLoggedIn();
if (isAdmin()) { header('Location: ' . SITE_URL . '/admin/dashboard.php'); exit; }
$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        try { $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) { error_log('dashboard mark read: ' . $e->getMessage()); }
    }
    header('Location: ' . SITE_URL . '/dashboard.php'); exit;
}

$user = aw_dsh_q($db, "SELECT * FROM users WHERE id = ?", [$uid])[0] ?? [];
$first = trim(explode(' ', awDisplayName($user))[0] ?? '');
$img = function ($p) { $p = trim((string)$p); return $p === '' ? '' : (preg_match('#^https?://#i', $p) ? $p : rtrim(SITE_URL, '/') . '/' . ltrim($p, '/')); };
$link = function ($l) { // notification links: only this site
    $l = trim((string)$l);
    if ($l === '') return SITE_URL . '/notifications.php';
    if (strpos($l, SITE_URL . '/') === 0) return $l;
    if ($l[0] === '/' && (strlen($l) < 2 || $l[1] !== '/')) return rtrim(SITE_URL, '/') . $l;
    return SITE_URL . '/notifications.php';
};

// where you left off
$reading = aw_dsh_q($db, "SELECT b.id, b.title, b.cover_path, rp.last_accessed_at, COALESCE(CAST(rp.position_section AS INTEGER), rs.last_read_page) AS page
    FROM books b LEFT JOIN reading_progress rp ON rp.book_id = b.id AND rp.user_id = ? LEFT JOIN reading_status rs ON rs.book_id = b.id AND rs.user_id = ?
    WHERE (rs.status = 'currently reading' OR (rp.id IS NOT NULL AND rp.finished_at IS NULL AND COALESCE(rs.status, '') <> 'finished'))
    ORDER BY COALESCE(rp.last_accessed_at, rs.updated_at) DESC LIMIT 6", [$uid, $uid]);
$plans = function_exists('aw_plans_upcoming') ? aw_plans_upcoming($db, $uid, 4) : [];

// waiting for you
$notes = aw_dsh_q($db, "SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 6", [$uid]);
$unreadNotes = aw_dsh_n($db, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$uid]);
$convos = []; $unreadMsgs = 0;
if (function_exists('aw_msg_inbox') && (!function_exists('aw_msg_enabled') || aw_msg_enabled())) {
    $ib = aw_msg_inbox($db, $uid);
    $convos = array_slice(array_merge($ib['requests'], $ib['active']), 0, 4);
    $unreadMsgs = aw_msg_unread_count($db, $uid);
}

// Stories & Help: your posts and the replies to them (pseudonyms kept)
$myPosts = function_exists('aw_cp_tables') ? aw_dsh_q($db, "SELECT p.*, (SELECT COUNT(*) FROM community_replies r WHERE r.post_id = p.id AND r.status = 'published') AS replies
    FROM community_posts p WHERE p.author_id = ? AND p.status <> 'removed' ORDER BY p.created_at DESC LIMIT 4", [$uid]) : [];
$repliesToMe = function_exists('aw_cp_reply_label') ? aw_dsh_q($db, "SELECT r.*, p.title, p.visibility, p.pseudonym AS post_pseudonym, p.author_id AS post_author, p.id AS pid
    FROM community_replies r JOIN community_posts p ON p.id = r.post_id WHERE p.author_id = ? AND r.author_id <> ? AND r.status = 'published' ORDER BY r.created_at DESC LIMIT 4", [$uid, $uid]) : [];
// Community Q&A: your questions, with the real number of answers
$myQuestions = aw_dsh_q($db, "SELECT q.id, q.title, q.created_at, (SELECT COUNT(*) FROM answers a WHERE a.question_id = q.id) AS answers,
        EXISTS(SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.is_accepted = 1) AS accepted
    FROM questions q WHERE q.user_id = ? ORDER BY q.created_at DESC LIMIT 4", [$uid]);

// kept for you
$saved = function_exists('aw_saves_list') ? array_slice(aw_saves_list($db, $uid), 0, 4) : [];
$savedCount = aw_dsh_n($db, "SELECT COUNT(*) FROM saves WHERE user_id = ?", [$uid]);
$highlights = aw_dsh_q($db, "SELECT h.text, h.book_id, b.title AS book_title FROM highlights h JOIN books b ON b.id = h.book_id WHERE h.user_id = ? ORDER BY h.created_at DESC LIMIT 2", [$uid]);
$groups = aw_dsh_q($db, "SELECT g.id, g.name, (SELECT COUNT(*) FROM group_members m2 WHERE m2.group_id = g.id) AS members FROM reading_groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = ? ORDER BY m.joined_at DESC LIMIT 4", [$uid]);
$sessions = aw_dsh_q($db, "SELECT date, time, status FROM sessions WHERE user_id = ? AND date >= date('now') AND status IN ('pending','confirmed') ORDER BY date, time LIMIT 3", [$uid]);

// new on AngelWrites (the latest of each kind)
$fresh = [];
foreach (aw_dsh_q($db, "SELECT id, title, created_at FROM poems WHERE id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft') ORDER BY created_at DESC LIMIT 2") as $r) $fresh[] = ['Poem', 'fa-feather', $r['title'], SITE_URL . '/poem_view.php?id=' . (int)$r['id'], $r['created_at']];
foreach (aw_dsh_q($db, "SELECT title, slug, COALESCE(published_at, created_at) AS t FROM blog_posts WHERE status = 'published' ORDER BY COALESCE(published_at, created_at) DESC LIMIT 2") as $r) $fresh[] = ['Reflection', 'fa-dove', $r['title'], SITE_URL . '/blog_post.php?slug=' . urlencode((string)$r['slug']), $r['t']];
foreach (aw_dsh_q($db, "SELECT id, title, created_at FROM books ORDER BY created_at DESC LIMIT 1") as $r) $fresh[] = ['Book', 'fa-book', $r['title'], SITE_URL . '/book.php?id=' . (int)$r['id'], $r['created_at']];
foreach (aw_dsh_q($db, "SELECT id, title, created_at FROM videos ORDER BY created_at DESC LIMIT 1") as $r) $fresh[] = ['Video', 'fa-circle-play', $r['title'], SITE_URL . '/video_watch.php?id=' . (int)$r['id'], $r['created_at']];
usort($fresh, function ($a, $b) { return strcmp((string)$b[4], (string)$a[4]); });
$fresh = array_slice($fresh, 0, 5);

// a verse for today (the same for everyone today), from a public-domain translation on the site
$verse = null;
if (function_exists('aw_bible_chapter')) {
    $refs = [['Psalms', 23, 1], ['Isaiah', 41, 10], ['Matthew', 11, 28], ['Philippians', 4, 6], ['Psalms', 34, 18], ['Romans', 8, 28], ['Jeremiah', 29, 11], ['Lamentations', 3, 22],
             ['John', 14, 27], ['Psalms', 46, 1], ['2 Corinthians', 12, 9], ['Isaiah', 40, 31], ['Proverbs', 3, 5], ['Psalms', 147, 3], ['1 Peter', 5, 7], ['Joshua', 1, 9],
             ['Zephaniah', 3, 17], ['Romans', 15, 13], ['Psalms', 121, 2], ['Hebrews', 13, 5], ['Isaiah', 43, 2], ['John', 16, 33], ['Psalms', 91, 1], ['Micah', 6, 8]];
    [$vb, $vc, $vv] = $refs[(int)date('z') % count($refs)];
    foreach (['WEB', 'KJV'] as $ver) {
        $ch = aw_bible_chapter($ver, $vb, $vc);
        if ($ch) foreach ($ch['verses'] as $row) if ((int)$row['v'] === $vv) { $verse = ['text' => trim(strip_tags((string)$row['text'])), 'ref' => $ch['book_name'] . ' ' . $vc . ':' . $vv, 'ver' => $ver, 'b' => $vb, 'c' => $vc, 'v' => $vv]; break 2; }
    }
}

$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
$chips = [
    ['Unread messages', $unreadMsgs, 'fa-envelope', SITE_URL . '/messages.php', '#6d5bb0'],
    ['New for you', $unreadNotes, 'fa-bell', SITE_URL . '/notifications.php', '#9c5457'],
    ['Saved items', $savedCount, 'fa-bookmark', SITE_URL . '/library.php#saved', '#3f8f86'],
    ['Books open', count($reading), 'fa-book-open', SITE_URL . '/library.php#continue', '#b7853a'],
];
$token = generate_csrf_token();
$pageTitle = 'My Dashboard';
require_once 'includes/header.php';
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-dash.css?v=2">
<main class="dsh" id="main">
    <section class="dsh-hero dsh-reveal is-in" aria-labelledby="dshTitle">
        <div class="dsh-hero-top">
            <div>
                <p class="dsh-eyebrow"><?php echo htmlspecialchars(date('l, j F')); ?></p>
                <h1 id="dshTitle"><?php echo $greet . ($first !== '' ? ', ' . htmlspecialchars($first) : ''); ?></h1>
                <p class="dsh-sub"><?php
                    $bits = [];
                    if ($unreadMsgs) $bits[] = $unreadMsgs . ' ' . ($unreadMsgs === 1 ? 'conversation' : 'conversations') . ' waiting';
                    if ($unreadNotes) $bits[] = $unreadNotes . ' new ' . ($unreadNotes === 1 ? 'notification' : 'notifications');
                    if ($plans) $bits[] = 'a planned read ' . strtolower(aw_plan_when($plans[0]['plan_date']));
                    echo htmlspecialchars($bits ? 'You have ' . implode(', ', $bits) . '.' : 'Welcome back. Pick up where you left off, or find something new.');
                ?></p>
            </div>
            <div class="dsh-hero-actions">
                <?php if ($reading): ?><a class="dsh-pill dsh-pill-solid" href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$reading[0]['id']; ?>"><i class="fas fa-book-open" aria-hidden="true"></i> Continue reading</a><?php endif; ?>
                <a class="dsh-pill" href="<?php echo SITE_URL; ?>/messages.php"><i class="fas fa-envelope" aria-hidden="true"></i> Messages<?php if ($unreadMsgs): ?> <span class="dsh-badge"><?php echo (int)$unreadMsgs; ?></span><?php endif; ?></a>
                <a class="dsh-pill" href="<?php echo SITE_URL; ?>/stories.php"><i class="fas fa-seedling" aria-hidden="true"></i> Stories &amp; Help</a>
                <?php if (function_exists('aw_is_staff') && aw_is_staff()): ?><a class="dsh-pill" href="<?php echo SITE_URL; ?>/admin/dashboard.php"><i class="fas fa-shield-heart" aria-hidden="true"></i> Staff</a><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="dsh-kpis" aria-label="At a glance">
        <?php foreach ($chips as $i => [$label, $n, $icon, $href, $c]): ?>
            <a class="dsh-card dsh-kpi dsh-reveal" style="--i:<?php echo $i; ?>;--kc:<?php echo $c; ?>" href="<?php echo htmlspecialchars($href); ?>">
                <span class="dsh-kpi-label"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($label); ?></span>
                <span class="dsh-kpi-num" data-count="<?php echo (int)$n; ?>"><?php echo number_format($n); ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="dsh-row dsh-row-2">
        <section class="dsh-card dsh-reveal" style="--i:1" aria-labelledby="dContinue">
            <h2 id="dContinue"><span class="dsh-ico"><i class="fas fa-book-open" aria-hidden="true"></i></span> Where you left off <a class="dsh-more" href="<?php echo SITE_URL; ?>/library.php">My library</a></h2>
            <?php if ($plans): ?>
                <ul class="dsh-list" style="margin-bottom:12px"><?php foreach ($plans as $pl): ?>
                    <li><a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$pl['book_id']; ?><?php echo $pl['page'] ? '&page=' . (int)$pl['page'] : ''; ?>"><i class="far fa-calendar" aria-hidden="true"></i> <?php echo htmlspecialchars($pl['book_title']); ?><span class="dsh-sub"><?php echo htmlspecialchars(trim(aw_plan_when($pl['plan_date']) . ($pl['label'] ? ' · ' . $pl['label'] : ''))); ?></span></a><span class="dsh-meta">planned</span></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (!$reading): ?>
                <p class="dsh-empty">Open any book and it waits here, at the page where you stopped.</p>
                <div class="dsh-btnrow"><a class="dsh-btn dsh-btn-solid" href="<?php echo SITE_URL; ?>/books.php">Browse books</a></div>
            <?php else: ?>
                <div class="dsh-books">
                <?php foreach ($reading as $b): ?>
                    <a class="dsh-book" href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$b['id']; ?>">
                        <span class="dsh-book-cover"><span><?php echo htmlspecialchars($b['title']); ?></span><?php if ($b['cover_path']): ?><img src="<?php echo htmlspecialchars($img($b['cover_path'])); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                        <strong><?php echo htmlspecialchars($b['title']); ?></strong>
                        <small><?php echo $b['page'] ? 'Page ' . (int)$b['page'] : 'Not started'; ?><?php echo $b['last_accessed_at'] ? ' · ' . htmlspecialchars(aw_dsh_ago($b['last_accessed_at'])) : ''; ?></small>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="dsh-card dsh-reveal" style="--i:2" aria-labelledby="dVerse">
            <h2 id="dVerse"><span class="dsh-ico"><i class="fas fa-bible" aria-hidden="true"></i></span> A verse for today</h2>
            <?php if ($verse): ?>
                <blockquote class="dsh-verse">“<?php echo htmlspecialchars($verse['text']); ?>”<cite><?php echo htmlspecialchars($verse['ref'] . ' (' . $verse['ver'] . ')'); ?></cite></blockquote>
                <div class="dsh-btnrow"><a class="dsh-btn" href="<?php echo SITE_URL; ?>/bible_reader.php?v=<?php echo urlencode($verse['ver']); ?>&amp;b=<?php echo urlencode($verse['b']); ?>&amp;c=<?php echo (int)$verse['c']; ?>&amp;vs=<?php echo (int)$verse['v']; ?>">Read the chapter</a></div>
            <?php else: ?>
                <p class="dsh-empty">Open the Bible reader to read in English, Chichewa or Chiyao.</p>
                <div class="dsh-btnrow"><a class="dsh-btn" href="<?php echo SITE_URL; ?>/bible_reader.php">Open the Bible</a></div>
            <?php endif; ?>
        </section>
    </div>

    <div class="dsh-row dsh-row-3">
        <section class="dsh-card dsh-reveal" style="--i:3" aria-labelledby="dNotes">
            <h2 id="dNotes"><span class="dsh-ico"><i class="fas fa-bell" aria-hidden="true"></i></span> For you <a class="dsh-more" href="<?php echo SITE_URL; ?>/notifications.php">All</a></h2>
            <?php if (!$notes): ?><p class="dsh-empty">Nothing new. Replies, answers, messages and confirmations will show here.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($notes as $n): ?>
                <li><a href="<?php echo htmlspecialchars($link($n['link'] ?? '')); ?>"><?php echo htmlspecialchars($n['title']); ?><span class="dsh-sub"><?php echo htmlspecialchars(aw_dsh_short($n['message'], 70)); ?> · <?php echo htmlspecialchars(aw_dsh_ago($n['created_at'])); ?></span></a><?php if (!(int)$n['is_read']): ?><span class="dsh-dotnew" title="New"><span class="sr-only">New</span></span><?php endif; ?></li>
            <?php endforeach; ?></ul>
            <?php if ($unreadNotes): ?><form method="post" class="dsh-btnrow"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><button class="dsh-btn" name="mark_all_read" value="1">Mark all as read</button></form><?php endif; ?>
            <?php endif; ?>
        </section>
        <?php if (function_exists('aw_msg_inbox')): ?>
        <section class="dsh-card dsh-reveal" style="--i:4" aria-labelledby="dMsgs">
            <h2 id="dMsgs"><span class="dsh-ico"><i class="fas fa-envelope" aria-hidden="true"></i></span> Messages <a class="dsh-more" href="<?php echo SITE_URL; ?>/messages.php">Open</a></h2>
            <?php if (!$convos): ?><p class="dsh-empty">No conversations yet. You can always write to the AngelWrites team.</p>
                <div class="dsh-btnrow"><a class="dsh-btn" href="<?php echo SITE_URL; ?>/messages.php?view=writeteam">Write to the team</a></div>
            <?php else: ?><ul class="dsh-list"><?php foreach ($convos as $c): $nm = $c['kind'] === 'team' ? 'AngelWrites Team' : aw_msg_name($db, (int)$c['other_id']); $req = $c['my_status'] === 'requested'; ?>
                <li><a href="<?php echo SITE_URL; ?>/messages.php?t=<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($nm); ?><span class="dsh-sub"><?php echo $req ? 'Would like to message you' : htmlspecialchars(aw_dsh_short((string)$c['last_body'], 60)); ?></span></a><?php if ($req || (int)$c['unread'] > 0): ?><span class="dsh-dotnew"><span class="sr-only">Unread</span></span><?php else: ?><span class="dsh-meta"><?php echo htmlspecialchars(aw_dsh_ago($c['last_message_at'])); ?></span><?php endif; ?></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php endif; ?>
        <section class="dsh-card dsh-reveal" style="--i:5" aria-labelledby="dStories">
            <h2 id="dStories"><span class="dsh-ico"><i class="fas fa-seedling" aria-hidden="true"></i></span> Stories &amp; Help <a class="dsh-more" href="<?php echo SITE_URL; ?>/my_stories.php">Mine</a></h2>
            <?php if (!$myPosts && !$repliesToMe && !$myQuestions): ?>
                <p class="dsh-empty">Share a story, ask a question or ask for help. You can use your name, a pseudonym, or write only to the team.</p>
                <div class="dsh-btnrow"><a class="dsh-btn dsh-btn-solid" href="<?php echo SITE_URL; ?>/story_write.php">Share something</a><a class="dsh-btn" href="<?php echo SITE_URL; ?>/story_write.php?type=help">Get help</a></div>
            <?php else: ?><ul class="dsh-list">
                <?php foreach ($repliesToMe as $r): $post = ['visibility' => $r['visibility'], 'pseudonym' => $r['post_pseudonym'], 'author_id' => $r['post_author']]; ?>
                    <li><a href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$r['pid']; ?>#reply-<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars(aw_cp_reply_label($db, $r, $post)); ?> replied<span class="dsh-sub">on “<?php echo htmlspecialchars(aw_dsh_short($r['title'], 50)); ?>”</span></a><span class="dsh-meta"><?php echo htmlspecialchars(aw_dsh_ago($r['created_at'])); ?></span></li>
                <?php endforeach; ?>
                <?php foreach ($myPosts as $p): ?>
                    <li><a href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(aw_dsh_short($p['title'], 60)); ?><span class="dsh-sub"><?php echo $p['status'] === 'held' ? 'Waiting for a first read' : ((int)$p['replies'] === 1 ? '1 reply' : (int)$p['replies'] . ' replies'); ?><?php echo $p['visibility'] === 'pseudo' ? ' · as ' . htmlspecialchars($p['pseudonym']) : ''; ?></span></a></li>
                <?php endforeach; ?>
                <?php foreach ($myQuestions as $q): $na = (int)$q['answers']; ?>
                    <li><a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo (int)$q['id']; ?>"><?php echo htmlspecialchars(aw_dsh_short($q['title'], 60)); ?><span class="dsh-sub">Community Q&amp;A · <?php echo (int)$q['accepted'] ? 'Answered' : ($na === 0 ? 'No answers yet' : ($na === 1 ? '1 answer' : $na . ' answers')); ?></span></a></li>
                <?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        <section class="dsh-card dsh-reveal" style="--i:6" aria-labelledby="dSaved">
            <h2 id="dSaved"><span class="dsh-ico"><i class="fas fa-bookmark" aria-hidden="true"></i></span> Kept for you <a class="dsh-more" href="<?php echo SITE_URL; ?>/library.php#saved">All</a></h2>
            <?php if (!$saved && !$highlights): ?><p class="dsh-empty">Use Save on a poem, a reflection, a verse or a book, or highlight a line while reading, and it is kept here.</p>
            <?php else: ?><ul class="dsh-list">
                <?php foreach ($saved as $s): ?><li><a href="<?php echo htmlspecialchars($s['link']); ?>"><?php echo htmlspecialchars(aw_dsh_short($s['title'], 60)); ?><span class="dsh-sub"><?php echo htmlspecialchars($s['label']); ?><?php echo $s['type'] === 'verse' ? ' · “' . htmlspecialchars(aw_dsh_short($s['sub'], 50)) . '”' : ''; ?></span></a></li><?php endforeach; ?>
                <?php foreach ($highlights as $hl): ?><li><a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$hl['book_id']; ?>">“<?php echo htmlspecialchars(aw_dsh_short($hl['text'], 70)); ?>”<span class="dsh-sub">Highlight in <?php echo htmlspecialchars($hl['book_title']); ?></span></a></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        <section class="dsh-card dsh-reveal" style="--i:7" aria-labelledby="dGroups">
            <h2 id="dGroups"><span class="dsh-ico"><i class="fas fa-user-group" aria-hidden="true"></i></span> Your groups &amp; sessions</h2>
            <?php if (!$groups && !$sessions): ?><p class="dsh-empty">Read a book alongside others in a group, or book a private session with Angella.</p>
                <div class="dsh-btnrow"><a class="dsh-btn" href="<?php echo SITE_URL; ?>/groups.php">Find a group</a><a class="dsh-btn" href="<?php echo SITE_URL; ?>/book_session.php">Book a session</a></div>
            <?php else: ?><ul class="dsh-list">
                <?php foreach ($sessions as $s): ?><li><a href="<?php echo SITE_URL; ?>/book_session.php"><i class="far fa-calendar-check" aria-hidden="true"></i> <?php echo htmlspecialchars(date('l j M, g:i a', strtotime($s['date'] . ' ' . $s['time']))); ?><span class="dsh-sub"><?php echo $s['status'] === 'confirmed' ? 'Session confirmed' : 'Session waiting for confirmation'; ?></span></a></li><?php endforeach; ?>
                <?php foreach ($groups as $g): ?><li><a href="<?php echo SITE_URL; ?>/group.php?id=<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></a><span class="dsh-meta"><?php echo (int)$g['members']; ?> members</span></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        <section class="dsh-card dsh-reveal" style="--i:8" aria-labelledby="dNew">
            <h2 id="dNew"><span class="dsh-ico"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i></span> New on AngelWrites</h2>
            <?php if (!$fresh): ?><p class="dsh-empty">New poems, reflections, books and videos will appear here.</p>
            <?php else: ?><ul class="dsh-list"><?php foreach ($fresh as [$kind, $icon, $title, $href, $when]): ?>
                <li><a href="<?php echo htmlspecialchars($href); ?>"><i class="fas <?php echo $icon; ?>" aria-hidden="true" style="color:var(--d-accent);margin-right:6px"></i><?php echo htmlspecialchars(aw_dsh_short($title, 60)); ?><span class="dsh-sub"><?php echo $kind; ?></span></a><span class="dsh-meta"><?php echo htmlspecialchars(aw_dsh_ago($when)); ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
        </section>
    </div>

    <h2 class="dsh-tools-h">Explore</h2>
    <nav class="dsh-explore dsh-reveal" aria-label="Explore AngelWrites">
        <a href="<?php echo SITE_URL; ?>/books.php"><i class="fas fa-book" aria-hidden="true"></i> Books</a>
        <a href="<?php echo SITE_URL; ?>/poetry.php"><i class="fas fa-feather" aria-hidden="true"></i> Poetry</a>
        <a href="<?php echo SITE_URL; ?>/reflections.php"><i class="fas fa-dove" aria-hidden="true"></i> Reflections</a>
        <a href="<?php echo SITE_URL; ?>/videos.php"><i class="fas fa-circle-play" aria-hidden="true"></i> Videos</a>
        <a href="<?php echo SITE_URL; ?>/bible_reader.php"><i class="fas fa-bible" aria-hidden="true"></i> Bible</a>
        <a href="<?php echo SITE_URL; ?>/stories.php"><i class="fas fa-seedling" aria-hidden="true"></i> Stories &amp; Help</a>
        <a href="<?php echo SITE_URL; ?>/community.php"><i class="fas fa-circle-question" aria-hidden="true"></i> Community Q&amp;A</a>
        <a href="<?php echo SITE_URL; ?>/library.php"><i class="fas fa-bookmark" aria-hidden="true"></i> My library</a>
        <a href="<?php echo SITE_URL; ?>/my_purchases.php"><i class="fas fa-receipt" aria-hidden="true"></i> My purchases</a>
        <a href="<?php echo SITE_URL; ?>/profile.php"><i class="fas fa-user" aria-hidden="true"></i> Profile</a>
    </nav>
</main>
<script src="<?php echo SITE_URL; ?>/assets/js/aw-dash.js?v=1" defer></script>
<?php require_once 'includes/footer.php'; ?>
