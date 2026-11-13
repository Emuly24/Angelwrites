<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

aw_require('staff_dashboard'); // K1

// E2 (PROGRAM_PLAN.md Phase 1): the admin dashboard shows what needs doing and what happened
// recently, read once on the server. No charts, no "Live" polling: the page makes no request after
// it loads until the admin acts. Every query fails soft (logged, empty).
function aw_adm_rows(PDO $db, $sql, array $params = []) {
    try { $st = $db->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('admin dashboard: ' . $e->getMessage()); return []; }
}
function aw_adm_count(PDO $db, $sql) {
    try { return (int)$db->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log('admin dashboard: ' . $e->getMessage()); return 0; }
}
function aw_adm_short($text, $len) {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (function_exists('mb_strlen')) return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
    return strlen($t) > $len ? rtrim(substr($t, 0, $len)) . '…' : $t;
}

$week = [
    'New members'    => aw_adm_count($db, "SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 days')"),
    'Readers'        => aw_adm_count($db, "SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time >= datetime('now', '-7 days')"),
    'Comments'       => aw_adm_count($db, "SELECT COUNT(*) FROM reviews WHERE deleted_at IS NULL AND created_at >= datetime('now', '-7 days')"),
    'Questions'      => aw_adm_count($db, "SELECT COUNT(*) FROM questions WHERE created_at >= datetime('now', '-7 days')"),
];
$totals = [
    'Members' => aw_adm_count($db, "SELECT COUNT(*) FROM users"),
    'Books'   => aw_adm_count($db, "SELECT COUNT(*) FROM books"),
    'Poems'   => aw_adm_count($db, "SELECT COUNT(*) FROM poems"),
    'Posts'   => aw_adm_count($db, "SELECT COUNT(*) FROM blog_posts"),
    'Videos'  => aw_adm_count($db, "SELECT COUNT(*) FROM videos"),
];

$recent_sessions = aw_adm_rows($db, "SELECT s.id, s.date, s.time, u.name, u.first_name, u.last_name, u.username FROM sessions s JOIN users u ON s.user_id = u.id WHERE s.status = 'pending' ORDER BY s.date ASC, s.time ASC LIMIT 5");
$recent_messages = aw_adm_rows($db, "SELECT id, name, message, created_at FROM contact_messages WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5");
$aw_me = aw_adm_rows($db, "SELECT name, first_name, last_name, username FROM users WHERE id = ?", [(int)($_SESSION['user_id'] ?? 0)]);
$aw_me_name = $aw_me ? awDisplayName($aw_me[0]) : 'Admin';
$recent_members  = aw_adm_rows($db, "SELECT id, name, first_name, last_name, username, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 5");
$recent_comments = aw_adm_rows($db, "SELECT r.id, r.target_type, r.target_id, r.comment, r.created_at, u.name, u.first_name, u.last_name, u.username
    FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 5");
$open_questions  = aw_adm_rows($db, "SELECT q.id, q.title, q.created_at FROM questions q WHERE q.answers_count = 0 ORDER BY q.created_at DESC LIMIT 5");

// Q42: the real to-do items were buried among totals and zero-filled charts. Count what is waiting
// for the admin; each query is optional (a table may not exist before the DB repair).
$aw_attention = [];
foreach ([
    ['Orders to confirm', "SELECT COUNT(*) FROM payments WHERE status = 'pending'", 'manage_payments.php', 'fa-money-bill-wave', 'manage_payments'],
    ['Session requests', "SELECT COUNT(*) FROM sessions WHERE status = 'pending'", 'manage_sessions.php?status=pending', 'fa-calendar-check', 'manage_members'],
    ['Unread messages', "SELECT COUNT(*) FROM contact_messages WHERE is_read = 0", 'manage_messages.php', 'fa-envelope', 'team_inbox'],
    ['Open prayer requests', "SELECT COUNT(*) FROM prayer_requests WHERE COALESCE(status, 'pending') != 'prayed'", 'manage_prayer_requests.php', 'fa-hands-praying', 'moderate'],
    ['Testimonials to review', "SELECT COUNT(*) FROM testimonials WHERE approved = 0 AND is_public = 1", 'manage_testimonials.php', 'fa-quote-left', 'moderate'],
    ['Community reports', "SELECT COUNT(*) FROM reports WHERE status = 'pending'", 'manage_community.php', 'fa-flag', 'moderate'],
] as [$aw_label, $aw_sql, $aw_link, $aw_icon, $aw_cap]) {
    if (!aw_can($aw_cap)) continue; // K1: each role sees what it can act on
    try {
        $aw_n = (int)$db->query($aw_sql)->fetchColumn();
        if ($aw_n > 0) $aw_attention[] = [$aw_label, $aw_n, $aw_link, $aw_icon];
    } catch (Throwable $e) {
        // table not there yet
    }
}
// P0-1: there are no automatic backups on this host; remind the team when the last manual one is a week old
$aw_last_backup = (string)aw_setting('last_backup_at', '');
$aw_backup_days = $aw_last_backup !== '' ? (int)floor((time() - strtotime($aw_last_backup)) / 86400) : null;
if (aw_can('backup') && ($aw_backup_days === null || $aw_backup_days >= 7)) {
    $aw_attention[] = [$aw_backup_days === null ? 'backup taken yet: download one' : 'days since the last backup', $aw_backup_days === null ? 'No' : $aw_backup_days, 'backup.php', 'fa-database'];
}

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<style>
/* Hero */
.admin-hero { background: linear-gradient(135deg, var(--card-bg), var(--vanilla)); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
.admin-hero h1 { font-family: 'Playfair Display', serif; font-size: 2.2rem; margin:0; letter-spacing: -0.5px; }
.admin-hero .sub { color: var(--text-light); margin-top: 4px; font-size: 0.95rem; }

/* E2 */
.adm-h2 { font-family: 'Playfair Display', serif; font-size: 1.15rem; margin: 0 0 12px; color: var(--text); }
.adm-week { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: var(--shadow); }
.adm-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 0 0 12px; }
.adm-figures div { background: var(--bg); border-radius: 12px; padding: 12px 14px; border: 1px solid var(--border); }
.adm-figures dt { font-size: 0.8rem; color: var(--text-light); }
.adm-figures dd { margin: 2px 0 0; font-size: 1.6rem; font-weight: 700; color: var(--text); }
.adm-totals { margin: 0; font-size: 0.9rem; color: var(--text-light); }
.adm-linkbtn { background: none; border: 0; padding: 0; color: var(--rose-dark, #a8676a); text-decoration: underline; cursor: pointer; font: inherit; }
.adm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 28px; }
.adm-list { list-style: none; margin: 0; padding: 0; }
.adm-list li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.adm-list li:last-child { border-bottom: 0; }
.adm-list li span { color: var(--text-light); text-align: right; min-width: 0; overflow-wrap: anywhere; }
.adm-list a { font-weight: 600; color: var(--text); }
.adm-empty { margin: 0; color: var(--text-light); font-size: 0.9rem; line-height: 1.5; }

/* Alerts */
.alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.alert-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.alert-card h4 { font-size: 1rem; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
.alert-list { display: flex; flex-direction: column; gap: 10px; }
.alert-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.alert-item:last-child { border-bottom: none; }

/* Deep Dive Modal */
#statsModal .modal-box { max-width: 850px; max-height: 90vh; display: flex; flex-direction: column; }
#statsModal table { width:100%; border-collapse:collapse; font-size:0.85rem; }
#statsModal table th { background: var(--bg); padding:10px 12px; text-align:left; border-bottom:2px solid var(--border); position:sticky; top:0; z-index:1; }
#statsModal table td { padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; vertical-align: middle; }
#statsModal table tr:hover { background: var(--rose-light); }
#statsModalBody { max-height: 60vh; overflow-y: auto; }

/* Admin Modules - FIX FOR SCROLLING */
.admin-grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px; }
.admin-module { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.admin-module h3 { font-family: 'Playfair Display', serif; color: var(--rose-dark); font-size: 1.1rem; margin: 0 0 16px 0; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
.admin-module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
.admin-module-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px 8px; background: var(--bg); border-radius: 12px; border: 1px solid var(--border); text-decoration: none; color: var(--text); transition: 0.2s; text-align: center; }
.admin-module-btn:hover { transform: translateY(-3px); border-color: var(--rose); box-shadow: 0 4px 12px rgba(219,161,162,0.2); }
.admin-module-btn i { font-size: 1.4rem; color: var(--rose); margin-bottom: 6px; }
.admin-module-btn span { font-size: 0.75rem; font-weight: 500; }

/* Modal */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 9999; display: none; justify-content: center; align-items: center; }
.modal-overlay.active { display: flex; }
.modal-box { background: var(--card-bg); border-radius: 20px; padding: 32px; max-width: 420px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); border: 1px solid var(--rose-light); }
.modal-box h2 { font-family: 'Playfair Display', serif; margin-top: 0; color: var(--rose-dark); }
.modal-box .btn { width: 100%; justify-content: center; margin-bottom: 10px; }
</style>

<div class="container" style="max-width: 1400px; margin: 0 auto; padding: 20px;">
    <!-- Hero -->
    <div class="admin-hero">
        <div>
            <h1>Welcome back, <span style="color:var(--rose-dark, #a8676a);"><?php echo htmlspecialchars($aw_me_name); ?></span></h1>
            <div class="sub"><?php echo $aw_attention ? 'A few things are waiting for you.' : 'Nothing is waiting for you right now.'; ?></div>
        </div>
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?php if (aw_can('manage_content')): ?><button type="button" onclick="openQuickModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus" aria-hidden="true"></i> Quick add</button><?php endif; ?>
        </div>
    </div>

    <!-- Q42 / E2: needs attention -->
    <section class="admin-module" style="margin-bottom:20px;" aria-labelledby="admAttention">
        <h3 id="admAttention">Needs your attention</h3>
        <?php if (!$aw_attention): ?>
            <p style="margin:0;">All caught up. New orders, session requests, messages and reports will appear here.</p>
        <?php else: ?>
            <div class="admin-module-grid">
                <?php foreach ($aw_attention as [$aw_label, $aw_n, $aw_link, $aw_icon]): ?>
                    <a href="<?php echo htmlspecialchars($aw_link); ?>" class="admin-module-btn"><i class="fas <?php echo $aw_icon; ?>" aria-hidden="true"></i><span><strong><?php echo htmlspecialchars((string)$aw_n); ?></strong> <?php echo htmlspecialchars($aw_label); ?></span></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- This week, at a glance (static numbers, read once) -->
    <section class="adm-week" aria-labelledby="admWeek">
        <h2 id="admWeek" class="adm-h2">This week</h2>
        <dl class="adm-figures">
            <?php foreach ($week as $label => $n): ?>
                <div><dt><?php echo htmlspecialchars($label); ?></dt><dd><?php echo number_format($n); ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="adm-totals"><?php $bits = []; foreach ($totals as $l => $n) { $bits[] = number_format($n) . ' ' . strtolower($l); } echo htmlspecialchars('On the site: ' . implode(' · ', $bits)); ?>.
            <?php if (aw_can('see_identities')): ?>
            <button type="button" class="adm-linkbtn" onclick="openStatsModal('views')">Who viewed what</button> ·
            <button type="button" class="adm-linkbtn" onclick="openStatsModal('reading')">Reading sessions</button>
            <?php endif; ?>
        </p>
    </section>

    <div class="adm-grid">
        <?php if (aw_can('manage_members')): ?>
        <section class="alert-card" aria-labelledby="admSessions">
            <h4 id="admSessions"><i class="fas fa-calendar-check" aria-hidden="true" style="color:var(--rose);"></i> Session requests</h4>
            <?php if ($recent_sessions): ?><ul class="adm-list"><?php foreach ($recent_sessions as $s): ?>
                <li><a href="manage_sessions.php?status=pending"><?php echo htmlspecialchars(awDisplayName($s)); ?></a><span><?php echo htmlspecialchars(date('M j, g:i a', strtotime($s['date'] . ' ' . $s['time']))); ?></span></li>
            <?php endforeach; ?></ul><?php else: ?><p class="adm-empty">No requests waiting. When a member books a session it appears here to confirm.</p><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('team_inbox')): ?>
        <section class="alert-card" aria-labelledby="admMessages">
            <h4 id="admMessages"><i class="fas fa-envelope" aria-hidden="true" style="color:var(--rose);"></i> Unread messages</h4>
            <?php if ($recent_messages): ?><ul class="adm-list"><?php foreach ($recent_messages as $m): ?>
                <li><a href="manage_messages.php"><?php echo htmlspecialchars($m['name']); ?></a><span><?php echo htmlspecialchars(aw_adm_short($m['message'], 40)); ?></span></li>
            <?php endforeach; ?></ul><?php else: ?><p class="adm-empty">No unread messages from the contact form.</p><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('moderate')): ?>
        <section class="alert-card" aria-labelledby="admComments">
            <h4 id="admComments"><i class="fas fa-comment-dots" aria-hidden="true" style="color:var(--rose);"></i> Latest comments</h4>
            <?php if ($recent_comments): ?><ul class="adm-list"><?php foreach ($recent_comments as $c): ?>
                <li><a href="comments.php"><?php echo htmlspecialchars(awDisplayName($c)); ?></a><span><?php echo htmlspecialchars(aw_adm_short($c['comment'], 48)); ?> · <?php echo htmlspecialchars(date('M j', strtotime($c['created_at']))); ?></span></li>
            <?php endforeach; ?></ul><?php else: ?><p class="adm-empty">No comments yet. Comments on poems, books and videos will show here as they arrive.</p><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('moderate')): ?>
        <section class="alert-card" aria-labelledby="admQuestions">
            <h4 id="admQuestions"><i class="fas fa-question-circle" aria-hidden="true" style="color:var(--rose);"></i> Questions with no answer</h4>
            <?php if ($open_questions): ?><ul class="adm-list"><?php foreach ($open_questions as $q): ?>
                <li><a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo (int)$q['id']; ?>"><?php echo htmlspecialchars(aw_adm_short($q['title'], 60)); ?></a><span>asked <?php echo htmlspecialchars(date('M j', strtotime($q['created_at']))); ?></span></li>
            <?php endforeach; ?></ul><?php else: ?><p class="adm-empty">Every question has at least one answer.</p><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if (aw_can('manage_members')): ?>
        <section class="alert-card" aria-labelledby="admMembers">
            <h4 id="admMembers"><i class="fas fa-user-plus" aria-hidden="true" style="color:var(--rose);"></i> Newest members</h4>
            <?php if ($recent_members): ?><ul class="adm-list"><?php foreach ($recent_members as $u): ?>
                <li><a href="manage_users.php"><?php echo htmlspecialchars(awDisplayName($u)); ?></a><span>joined <?php echo htmlspecialchars(date('M j', strtotime($u['created_at']))); ?></span></li>
            <?php endforeach; ?></ul><?php else: ?><p class="adm-empty">No members yet.</p><?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <!-- Deep Dive Stats Modal (opened only when the admin asks) -->
<div id="statsModal" class="modal-overlay" style="z-index: 99999;" role="dialog" aria-modal="true" aria-labelledby="statsModalTitle">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h2 id="statsModalTitle" style="font-family:'Playfair Display'; color: var(--rose-dark); margin:0;">Loading...</h2>
            <button type="button" onclick="closeStatsModal()" aria-label="Close" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
        </div>
        <div id="statsModalBody" style="max-height: 60vh; overflow-y: auto; font-size:0.9rem; padding-bottom:10px;">
            <p style="color:var(--text-light); text-align:center;">Fetching data...</p>
        </div>
    </div>
</div>

        <!-- Admin Modules -->
    <div class="admin-grid-container">
        <?php if (!aw_can('manage_content')): /* K1: a moderator's tools */ ?>
        <div class="admin-module">
            <h3>🛡️ Moderation</h3>
            <div class="admin-module-grid">
                <a href="manage_community.php" class="admin-module-btn"><i class="fas fa-flag"></i><span>Reports</span></a>
                <a href="comments.php" class="admin-module-btn"><i class="fas fa-comments"></i><span>Comments</span></a>
                <a href="manage_questions.php" class="admin-module-btn"><i class="fas fa-question-circle"></i><span>Community Q&amp;A</span></a>
                <a href="manage_testimonials.php" class="admin-module-btn"><i class="fas fa-quote-left"></i><span>Testimonials</span></a>
                <a href="manage_prayer_requests.php" class="admin-module-btn"><i class="fas fa-hands-praying"></i><span>Prayer Requests</span></a>
                <a href="manage_messages.php" class="admin-module-btn"><i class="fas fa-inbox"></i><span>Team Inbox</span></a>
            </div>
        </div>
        <?php else: ?>
        <!-- 1. Books & Poetry -->
        <div class="admin-module">
            <h3>📖 Books & Poetry <a href="manage_poems.php?action=new" style="background:var(--rose);color:#fff;padding:2px 10px;border-radius:12px;text-decoration:none;font-size:0.7rem;font-weight:600;"><i class="fas fa-plus"></i> New Poem</a></h3>
            <div class="admin-module-grid">
                <a href="manage_books.php" class="admin-module-btn"><i class="fas fa-book"></i><span>Manage Books</span></a>
                <a href="process_book.php" class="admin-module-btn"><i class="fas fa-cog"></i><span>Process Book</span></a>
                <a href="manage_poems.php" class="admin-module-btn"><i class="fas fa-feather-alt"></i><span>Manage Poems</span></a>
                <a href="poem_editor.php" class="admin-module-btn"><i class="fas fa-edit"></i><span>Poem Editor</span></a>
            </div>
        </div>

        <!-- 2. Blog & Reflections -->
        <div class="admin-module">
            <h3>✍️ Blog & Reflections <a href="reflection_editor.php" style="background:var(--rose);color:#fff;padding:2px 10px;border-radius:12px;text-decoration:none;font-size:0.7rem;font-weight:600;"><i class="fas fa-plus"></i> New Reflection</a></h3>
            <div class="admin-module-grid">
                <a href="manage_blog.php" class="admin-module-btn"><i class="fas fa-blog"></i><span>Manage Blog</span></a>
                <a href="editor.php" class="admin-module-btn"><i class="fas fa-pen-fancy"></i><span>Blog Editor</span></a>
                <a href="manage_reflections.php" class="admin-module-btn"><i class="fas fa-pray"></i><span>Reflections</span></a>
                <a href="manage_prayer_requests.php" class="admin-module-btn"><i class="fas fa-hands-praying"></i><span>Prayer Requests</span></a><!-- F138 -->
                <a href="manage_payments.php" class="admin-module-btn"><i class="fas fa-money-bill-wave"></i><span>Payments</span></a><!-- F78 -->
                <a href="manage_testimonials.php" class="admin-module-btn"><i class="fas fa-quote-left"></i><span>Testimonials</span></a><!-- F202 -->
            </div>
        </div>

        <!-- 3. Community & Users -->
        <div class="admin-module">
            <h3>👥 Community & Users</h3>
            <div class="admin-module-grid">
                <a href="manage_users.php" class="admin-module-btn"><i class="fas fa-users"></i><span>Manage Users</span></a>
                <a href="manage_questions.php" class="admin-module-btn"><i class="fas fa-question-circle"></i><span>Community Q&amp;A</span></a><!-- Q35: was the reports screen -->
                <a href="manage_community.php" class="admin-module-btn"><i class="fas fa-flag"></i><span>Community Reports</span></a>
                <a href="manage_groups.php" class="admin-module-btn"><i class="fas fa-users-cog"></i><span>Reading Groups</span></a>
                <a href="manage_sessions.php" class="admin-module-btn"><i class="fas fa-calendar-check"></i><span>Booked Sessions</span></a>
            </div>
        </div>

        <!-- 4. Videos & Comments -->
        <div class="admin-module">
            <h3>🎥 Videos & Comments <a href="manage_videos.php?action=new" style="background:var(--rose);color:#fff;padding:2px 10px;border-radius:12px;text-decoration:none;font-size:0.7rem;font-weight:600;"><i class="fas fa-plus"></i> New Video</a></h3>
            <div class="admin-module-grid">
                <a href="manage_videos.php" class="admin-module-btn"><i class="fas fa-video"></i><span>Manage Videos</span></a>
                <a href="comments.php" class="admin-module-btn"><i class="fas fa-comments"></i><span>Manage Comments</span></a>
            </div>
        </div>

        <!-- 5. Newsletter -->
        <div class="admin-module">
            <h3>📧 Newsletter <a href="manage_newsletter.php?tab=send" style="background:var(--rose);color:#fff;padding:2px 10px;border-radius:12px;text-decoration:none;font-size:0.7rem;font-weight:600;"><i class="fas fa-plus"></i> New Campaign</a></h3>
            <div class="admin-module-grid">
                <a href="manage_newsletter.php" class="admin-module-btn"><i class="fas fa-list"></i><span>Subscribers</span></a>
                <a href="manage_newsletter.php?tab=send" class="admin-module-btn"><i class="fas fa-paper-plane"></i><span>Send Campaign</span></a>
                <a href="manage_newsletter.php?tab=queue" class="admin-module-btn"><i class="fas fa-clock"></i><span>Queue</span></a>
                <a href="manage_newsletter.php?tab=archive" class="admin-module-btn"><i class="fas fa-archive"></i><span>Archive</span></a>
            </div>
        </div>

        <!-- 6. System & Analytics -->
        <div class="admin-module">
            <h3>⚙️ System & Analytics</h3>
            <div class="admin-module-grid">
                <a href="settings.php" class="admin-module-btn"><i class="fas fa-sliders-h"></i><span>System Settings</span></a>
                <a href="backup.php" class="admin-module-btn"><i class="fas fa-database"></i><span>Database Backup</span></a><!-- P0-1 -->
                <a href="picks.php" class="admin-module-btn"><i class="fas fa-star"></i><span>Angella's Picks</span></a><!-- C2 -->
                <a href="../reader/admin/reader_analytics.php" class="admin-module-btn"><i class="fas fa-chart-line"></i><span>Reader Analytics</span></a>
                <?php if (aw_can('manage_staff')): ?><a href="staff.php" class="admin-module-btn"><i class="fas fa-user-shield"></i><span>Staff &amp; Roles</span></a><?php endif; ?><!-- K2 -->
                <?php if (aw_can('audit_log')): ?><a href="audit.php" class="admin-module-btn"><i class="fas fa-clipboard-list"></i><span>Audit Log</span></a><?php endif; ?><!-- K1 -->
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Add Modal -->
    <div class="modal-overlay" id="quickModal">
        <div class="modal-box">
                    <h2>Quick Create</h2>
        <p style="color:#6b5a5a; margin-bottom: 16px;">Select what you want to add</p>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <a href="manage_books.php?action=new" class="btn btn-primary">+ New Book</a>
            <a href="manage_poems.php?action=new" class="btn btn-primary">+ New Poem</a>
            <a href="manage_blog.php?action=new" class="btn btn-primary">+ New Blog Post</a>
            <a href="manage_videos.php?action=new" class="btn btn-primary">+ New Video</a>
            <a href="reflection_editor.php" class="btn btn-primary">+ New Reflection</a>
            <a href="manage_newsletter.php?tab=send" class="btn btn-primary">+ New Newsletter</a>
            
            <button onclick="closeQuickModal()" class="btn btn-secondary" style="background: transparent; border: 1px solid #d4c8c8; color:#6b5a5a; margin-top: 8px;">Cancel</button>
        </div>
    </div>
</div>

<script>

// Modal Functions
function openQuickModal() { document.getElementById('quickModal').classList.add('active'); }
function closeQuickModal() { document.getElementById('quickModal').classList.remove('active'); }
document.getElementById('quickModal').addEventListener('click', function(e) { if(e.target===this) closeQuickModal(); });

// ============================================================
// DEEP DIVE MODAL (fetches only when the admin clicks)
// ============================================================
function openStatsModal(type) {
    document.getElementById('statsModal').classList.add('active');
    const body = document.getElementById('statsModalBody');
    const title = document.getElementById('statsModalTitle');
    body.innerHTML = '<p style="color:#a08a8a; text-align:center;">Fetching data...</p>';
    const url = '<?php echo SITE_URL; ?>/ajax_admin.php?action=' + (type === 'views' ? 'get_view_details' : 'get_reading_details');
    title.textContent = type === 'views' ? '📊 Detailed Content Views' : '📖 Detailed Reading Sessions';

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.logs || data.logs.length === 0) {
                body.innerHTML = '<p style="color:#a08a8a; text-align:center;">No data available yet.</p>';
                return;
            }
            let html = '<table><thead><tr><th>Viewer / User</th><th>Content</th><th>Date & Time</th></tr></thead><tbody>';
            data.logs.forEach(row => {
                if(type === 'views') {
                    let titleCol = htmlspecialchars(row.content_title || 'Unknown');
                    let subTitle = `<span style="color:#a08a8a;font-size:0.75rem;display:block;">${htmlspecialchars(row.target_type)}</span>`;
                    let viewer = htmlspecialchars(row.viewer_name);
                    if(viewer === 'Guest') {
                        viewer = `<span style="color:#8a7878;">Guest</span> <span style="font-size:0.75rem; color:#a08a8a;">(IP: ${htmlspecialchars(row.ip_address || 'N/A')})</span>`;
                    } else {
                        viewer = `<strong>${htmlspecialchars(row.viewer_name)}</strong>`;
                    }
                    html += `<tr><td>${viewer}</td><td><strong>${titleCol}</strong> ${subTitle}</td><td style="color:#6b5a5a;">${new Date(row.viewed_at).toLocaleString()}</td></tr>`;
                } else {
                    let dur = Math.floor((row.duration_seconds || 0) / 60);
                    let sec = (row.duration_seconds || 0) % 60;
                    html += `<tr><td><strong>${htmlspecialchars(row.user_name)}</strong> <span style="font-size:0.75rem; color:#a08a8a;">(${htmlspecialchars(row.user_email)})</span></td><td>${htmlspecialchars(row.book_title || 'Unknown Book')}</td><td style="color:#6b5a5a;">${new Date(row.start_time).toLocaleString()} <br> <span style="font-size:0.75rem; background:#f0e8e8; padding:2px 6px; border-radius:10px;">${dur}m ${sec}s</span></td></tr>`;
                }
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<p style="color:red; text-align:center;">Error loading data. Check console.</p>';
            console.error("Deep dive error:", err);
        });
}

function closeStatsModal() {
    document.getElementById('statsModal').classList.remove('active');
}
document.getElementById('statsModal').addEventListener('click', function(e) {
    if (e.target === this) closeStatsModal();
});

function htmlspecialchars(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"]/g, function(m) {
        if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; if (m === '"') return '&quot;'; return m;
    });
}
</script>