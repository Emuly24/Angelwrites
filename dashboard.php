<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';
require_once 'includes/components.php'; // A1

redirectIfNotLoggedIn();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// ============================================================
// 1. HELPER FUNCTIONS (CSRF, WebP, Rate Limit)
// ============================================================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
if (!function_exists('get_image_url')) {
    function get_image_url($path) {
        if (empty($path)) return '';
        $base = rtrim(SITE_URL, '/');
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $webp_support = strpos($accept, 'image/webp') !== false;
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($webp_support && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/', '.webp', $path);
            $full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $webp_path;
            if (file_exists($full_path)) {
                return $base . '/' . $webp_path;
            }
        }
        return $base . '/' . ltrim($path, '/');
    }
}

// ============================================================
// 2. FETCH USER PROFILE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 3. WHAT IS WAITING FOR THIS MEMBER (E1)
// ============================================================
// E1 (PROGRAM_PLAN.md Phase 1): the dashboard shows real things the member can act on, read
// once on the server. There are no charts, counters or timers, and the page makes no request
// after it loads until the member does something. Each query fails soft (logged, empty list).
function aw_dash_rows(PDO $db, $sql, array $params) {
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('dashboard: ' . $e->getMessage());
        return [];
    }
}
function aw_dash_link($link) {
    // only same-site links from notifications are followed
    $link = trim((string)$link);
    if ($link === '') return SITE_URL . '/notifications.php';
    if (strpos($link, SITE_URL . '/') === 0) return $link;
    if ($link[0] === '/' && (strlen($link) < 2 || $link[1] !== '/')) return rtrim(SITE_URL, '/') . $link;
    return SITE_URL . '/notifications.php';
}
function aw_dash_short($text, $len) {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (function_exists('mb_strlen')) return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
    return strlen($t) > $len ? rtrim(substr($t, 0, $len)) . '…' : $t;
}

$reading_books = aw_dash_rows($db, "SELECT b.id, b.title, b.cover_path, COALESCE(rp.progress_percent, rs.progress, 0) AS progress, COALESCE(rp.last_accessed_at, rs.updated_at) AS last_read
    FROM books b JOIN reading_status rs ON b.id = rs.book_id
    LEFT JOIN reading_progress rp ON rp.book_id = b.id AND rp.user_id = rs.user_id
    WHERE rs.user_id = ? AND rs.status = 'currently reading'
    ORDER BY COALESCE(rp.last_accessed_at, rs.updated_at) DESC LIMIT 3", [$user_id]);
$notifications = aw_dash_rows($db, "SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 5", [$user_id]);
$unread_count = (int)(aw_dash_rows($db, "SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0", [$user_id])[0]['n'] ?? 0);
$my_questions = aw_dash_rows($db, "SELECT q.id, q.title, q.answers_count, q.created_at,
        EXISTS(SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.is_accepted = 1) AS has_accepted
    FROM questions q WHERE q.user_id = ? ORDER BY q.updated_at DESC, q.created_at DESC LIMIT 4", [$user_id]);
$highlights = aw_dash_rows($db, "SELECT h.text, h.book_id, h.created_at, b.title AS book_title FROM highlights h JOIN books b ON b.id = h.book_id WHERE h.user_id = ? ORDER BY h.created_at DESC LIMIT 3", [$user_id]);
$bible_marks = aw_dash_rows($db, "SELECT book, chapter, verse FROM bible_bookmarks WHERE user_id = ? ORDER BY created_at DESC LIMIT 3", [$user_id]);
$my_groups = aw_dash_rows($db, "SELECT g.id, g.name FROM reading_groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = ? ORDER BY m.joined_at DESC LIMIT 4", [$user_id]);
$upcoming_sessions = aw_dash_rows($db, "SELECT id, date, time, status, message FROM sessions WHERE user_id = ? AND date >= date('now') AND status IN ('pending','confirmed') ORDER BY date ASC, time ASC LIMIT 3", [$user_id]);
$recent_poems = aw_dash_rows($db, "SELECT p.id, p.title, MAX(pr.read_at) AS read_at FROM poems p JOIN poem_reads pr ON p.id = pr.poem_id WHERE pr.user_id = ? GROUP BY p.id ORDER BY read_at DESC LIMIT 3", [$user_id]);

// ============================================================
// 4. HANDLE MARK ALL NOTIFICATIONS AS READ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        try {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
        } catch (Throwable $e) {
            error_log('dashboard: mark all read: ' . $e->getMessage());
        }
    }
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'My Dashboard';
$aw_first = trim(explode(' ', awDisplayName($user))[0] ?? '');
?>
<?php require_once 'includes/header.php'; ?>

<style>
.dash { max-width: 1200px; margin: 0 auto; padding: 24px 20px 48px; }
.dash-hero { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px; padding:28px 32px; border-radius:20px; background:linear-gradient(135deg, var(--card-bg), var(--vanilla)); border:1px solid var(--border); }
.dash-hero h1 { font-family:'Playfair Display', Georgia, serif; font-size:clamp(1.6rem, 4vw, 2.2rem); margin:0; color:var(--text); }
.dash-hero p { margin:6px 0 0; color:var(--text-light); }
.dash-hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
.dash-badge { display:inline-block; min-width:1.5em; padding:0 6px; margin-left:4px; border-radius:999px; background:var(--rose-dark, #a8676a); color:#fff; font-size:.75rem; font-weight:700; line-height:1.5; text-align:center; }
.dash-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:28px; }
.dash-card { background:var(--card-bg); border:1px solid var(--border); border-radius:18px; padding:20px 22px; box-shadow:var(--shadow); display:flex; flex-direction:column; }
.dash-card h2 { font-family:'Playfair Display', Georgia, serif; font-size:1.15rem; margin:0 0 14px; color:var(--text); display:flex; align-items:center; gap:10px; }
.dash-card h2 i { color:var(--rose); }
.dash-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; }
.dash-list li { padding:10px 0; border-bottom:1px solid var(--border); display:flex; gap:12px; align-items:center; justify-content:space-between; }
.dash-list li:last-child { border-bottom:0; }
.dash-list a { color:var(--text); font-weight:600; text-decoration:none; }
.dash-list a:hover, .dash-list a:focus-visible { color:var(--rose-dark, #a8676a); text-decoration:underline; }
.dash-meta { display:block; font-size:.82rem; color:var(--text-light); font-weight:400; margin-top:2px; }
.dash-unread { width:8px; height:8px; border-radius:50%; background:var(--rose-dark, #a8676a); flex:none; }
.dash-progress { height:6px; border-radius:6px; background:var(--border); overflow:hidden; margin-top:6px; }
.dash-progress span { display:block; height:100%; background:var(--rose); }
.dash-book { display:flex; gap:12px; align-items:center; flex:1; min-width:0; }
.dash-book img { width:40px; height:56px; object-fit:cover; border-radius:6px; flex:none; background:var(--vanilla); }
.dash-book > div { flex:1; min-width:0; }
.dash-quote { font-style:italic; color:var(--text); display:block; }
.dash-empty { color:var(--text-light); margin:0 0 14px; line-height:1.55; }
.dash-foot { margin-top:auto; padding-top:14px; display:flex; gap:10px; flex-wrap:wrap; }
.dash-explore { display:flex; flex-wrap:wrap; gap:10px; }
.dash-explore a { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:999px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); text-decoration:none; font-weight:500; }
.dash-explore a:hover, .dash-explore a:focus-visible { border-color:var(--rose); color:var(--rose-dark, #a8676a); }
.dash-explore i { color:var(--rose); }
.dash h2.dash-section { font-family:'Playfair Display', Georgia, serif; font-size:1.2rem; margin:0 0 12px; color:var(--text); }
@media (max-width: 420px) { .dash-hero { padding:22px 20px; } .dash-grid { grid-template-columns:1fr; } }
</style>

<div class="dash">
    <div class="dash-hero">
        <div>
            <h1>Welcome back<?php echo $aw_first !== '' ? ', <span style="color:var(--rose-dark, #a8676a);">' . htmlspecialchars($aw_first) . '</span>' : ''; ?></h1>
            <p><?php echo $unread_count > 0 ? 'You have ' . $unread_count . ' new notification' . ($unread_count === 1 ? '' : 's') . '.' : 'Here is what is waiting for you.'; ?></p>
        </div>
        <div class="dash-hero-actions">
            <a href="<?php echo SITE_URL; ?>/notifications.php" class="btn btn-outline btn-sm"><i class="fas fa-bell" aria-hidden="true"></i> Notifications<?php if ($unread_count > 0): ?><span class="dash-badge"><?php echo $unread_count; ?><span class="sr-only"> unread</span></span><?php endif; ?></a>
            <a href="<?php echo SITE_URL; ?>/profile.php" class="btn btn-outline btn-sm"><i class="fas fa-user" aria-hidden="true"></i> My profile</a>
        </div>
    </div>

    <div class="dash-grid">
        <!-- Continue reading -->
        <section class="dash-card" aria-labelledby="dContinue">
            <h2 id="dContinue"><i class="fas fa-book-open" aria-hidden="true"></i> Continue reading</h2>
            <?php if ($reading_books): ?>
                <ul class="dash-list">
                <?php foreach ($reading_books as $b): $pc = max(0, min(100, (int)$b['progress'])); ?>
                    <li>
                        <div class="dash-book">
                            <?php if (!empty($b['cover_path'])): ?><img src="<?php echo htmlspecialchars(get_image_url($b['cover_path'])); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                            <div>
                                <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></a>
                                <span class="dash-meta"><?php echo $pc; ?>% read</span>
                                <div class="dash-progress" role="progressbar" aria-label="<?php echo htmlspecialchars($b['title']); ?> progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $pc; ?>"><span style="width:<?php echo $pc; ?>%"></span></div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/library.php', 'My library', 'outline', ['small' => true]); ?></div>
            <?php else: ?>
                <p class="dash-empty">You are not reading a book right now. Open any book and it will wait for you here, with your place saved.</p>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/books.php', 'Browse books', 'primary', ['small' => true]); ?></div>
            <?php endif; ?>
        </section>

        <!-- Notifications -->
        <section class="dash-card" aria-labelledby="dNotes">
            <h2 id="dNotes"><i class="fas fa-bell" aria-hidden="true"></i> For you</h2>
            <?php if ($notifications): ?>
                <ul class="dash-list">
                <?php foreach ($notifications as $n): ?>
                    <li>
                        <div style="min-width:0;">
                            <a href="<?php echo htmlspecialchars(aw_dash_link($n['link'] ?? '')); ?>"><?php echo htmlspecialchars($n['title']); ?></a>
                            <span class="dash-meta"><?php echo htmlspecialchars(aw_dash_short($n['message'], 70)); ?> · <?php echo date('M j', strtotime($n['created_at'])); ?></span>
                        </div>
                        <?php if (!(int)$n['is_read']): ?><span class="dash-unread" title="New"><span class="sr-only">New</span></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div class="dash-foot">
                    <?php echo aw_link_btn(SITE_URL . '/notifications.php', 'See all', 'outline', ['small' => true]); ?>
                    <?php if ($unread_count > 0): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <button type="submit" name="mark_all_read" value="1" class="btn btn-sm btn-outline">Mark all as read</button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="dash-empty">Nothing new. When someone answers your question, replies in your group or your session is confirmed, you will see it here.</p>
            <?php endif; ?>
        </section>

        <!-- Questions -->
        <section class="dash-card" aria-labelledby="dQuestions">
            <h2 id="dQuestions"><i class="fas fa-comments" aria-hidden="true"></i> Your questions</h2>
            <?php if ($my_questions): ?>
                <ul class="dash-list">
                <?php foreach ($my_questions as $q): $na = (int)$q['answers_count']; ?>
                    <li><div style="min-width:0;">
                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo (int)$q['id']; ?>"><?php echo htmlspecialchars($q['title']); ?></a>
                        <span class="dash-meta"><?php echo (int)$q['has_accepted'] ? 'Answered' : ($na === 0 ? 'No answers yet' : ($na === 1 ? '1 answer' : $na . ' answers')); ?> · asked <?php echo date('M j', strtotime($q['created_at'])); ?></span>
                    </div></li>
                <?php endforeach; ?>
                </ul>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/community.php', 'Community Q&A', 'outline', ['small' => true]); ?></div>
            <?php else: ?>
                <p class="dash-empty">Carrying a question about faith, healing or a passage you read? Ask it in the community; others who have walked the same road can answer.</p>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/community.php', 'Ask a question', 'primary', ['small' => true]); ?></div>
            <?php endif; ?>
        </section>

        <!-- Saved -->
        <section class="dash-card" aria-labelledby="dSaved">
            <h2 id="dSaved"><i class="fas fa-bookmark" aria-hidden="true"></i> Saved for later</h2>
            <?php if ($highlights || $bible_marks): ?>
                <ul class="dash-list">
                <?php foreach ($highlights as $h): ?>
                    <li><div style="min-width:0;">
                        <span class="dash-quote">“<?php echo htmlspecialchars(aw_dash_short($h['text'], 90)); ?>”</span>
                        <span class="dash-meta">from <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$h['book_id']; ?>"><?php echo htmlspecialchars($h['book_title']); ?></a></span>
                    </div></li>
                <?php endforeach; ?>
                <?php foreach ($bible_marks as $m): $ref = $m['book'] . ' ' . (int)$m['chapter'] . ((int)$m['verse'] > 0 ? ':' . (int)$m['verse'] : ''); ?>
                    <li><div>
                        <a href="<?php echo SITE_URL; ?>/bible_reader.php?book=<?php echo urlencode($m['book']); ?>&amp;chapter=<?php echo (int)$m['chapter']; ?><?php echo (int)$m['verse'] > 0 ? '&amp;verse=' . (int)$m['verse'] : ''; ?>"><?php echo htmlspecialchars($ref); ?></a>
                        <span class="dash-meta">Bible bookmark</span>
                    </div></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="dash-empty">Highlight a line in any book, or bookmark a verse in the Bible reader, and it will be kept here for you.</p>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/bible_reader.php', 'Open the Bible', 'outline', ['small' => true]); ?></div>
            <?php endif; ?>
        </section>

        <!-- Groups -->
        <section class="dash-card" aria-labelledby="dGroups">
            <h2 id="dGroups"><i class="fas fa-users" aria-hidden="true"></i> Your groups</h2>
            <?php if ($my_groups): ?>
                <ul class="dash-list">
                <?php foreach ($my_groups as $g): ?>
                    <li><a href="<?php echo SITE_URL; ?>/group.php?id=<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></a></li>
                <?php endforeach; ?>
                </ul>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/groups.php', 'All groups', 'outline', ['small' => true]); ?></div>
            <?php else: ?>
                <p class="dash-empty">Reading together helps. Join a group to read a book alongside others and share what stays with you.</p>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/groups.php', 'Find a group', 'primary', ['small' => true]); ?></div>
            <?php endif; ?>
        </section>

        <!-- Sessions -->
        <section class="dash-card" aria-labelledby="dSessions">
            <h2 id="dSessions"><i class="fas fa-calendar-check" aria-hidden="true"></i> Your sessions</h2>
            <?php if ($upcoming_sessions): ?>
                <ul class="dash-list">
                <?php foreach ($upcoming_sessions as $s): ?>
                    <li><div>
                        <a href="<?php echo SITE_URL; ?>/book_session.php"><?php echo htmlspecialchars(date('l, M j \a\t g:i a', strtotime($s['date'] . ' ' . $s['time']))); ?></a>
                        <span class="dash-meta"><?php echo $s['status'] === 'confirmed' ? 'Confirmed' : 'Waiting for confirmation'; ?></span>
                    </div></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="dash-empty">Would it help to talk with someone? You can book a private session with Angella.</p>
                <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/book_session.php', 'Book a session', 'outline', ['small' => true]); ?></div>
            <?php endif; ?>
        </section>

        <?php if ($recent_poems): ?>
        <!-- Recently read poems -->
        <section class="dash-card" aria-labelledby="dPoems">
            <h2 id="dPoems"><i class="fas fa-feather-alt" aria-hidden="true"></i> Poems you read recently</h2>
            <ul class="dash-list">
            <?php foreach ($recent_poems as $p): ?>
                <li><a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a></li>
            <?php endforeach; ?>
            </ul>
            <div class="dash-foot"><?php echo aw_link_btn(SITE_URL . '/poetry.php', 'More poems', 'outline', ['small' => true]); ?></div>
        </section>
        <?php endif; ?>
    </div>

    <h2 class="dash-section">Explore</h2>
    <nav class="dash-explore" aria-label="Explore AngelWrites">
        <a href="<?php echo SITE_URL; ?>/books.php"><i class="fas fa-book" aria-hidden="true"></i> Books</a>
        <a href="<?php echo SITE_URL; ?>/poetry.php"><i class="fas fa-feather-alt" aria-hidden="true"></i> Poetry</a>
        <a href="<?php echo SITE_URL; ?>/reflections.php"><i class="fas fa-dove" aria-hidden="true"></i> Reflections</a>
        <a href="<?php echo SITE_URL; ?>/blog.php"><i class="fas fa-pen-nib" aria-hidden="true"></i> Blog</a>
        <a href="<?php echo SITE_URL; ?>/videos.php"><i class="fas fa-video" aria-hidden="true"></i> Videos</a>
        <a href="<?php echo SITE_URL; ?>/bible_reader.php"><i class="fas fa-bible" aria-hidden="true"></i> Bible</a>
        <a href="<?php echo SITE_URL; ?>/my_purchases.php"><i class="fas fa-receipt" aria-hidden="true"></i> My purchases</a>
    </nav>
</div>
<?php require_once 'includes/footer.php'; ?>
