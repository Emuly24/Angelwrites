<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ============================================================
// ADVANCED FETCH: INITIAL STATS (will be updated via AJAX)
// ============================================================
$stats = [];
$stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['total_users'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['total_books'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['total_poems'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['total_sessions'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['total_posts'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['total_videos'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM questions"); $stats['total_questions'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1"); $stats['total_subscribers'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts WHERE category = 'Christian Reflections'"); $stats['total_reflections'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM reading_groups"); $stats['total_groups'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions"); $stats['total_reading_hours'] = floor(($stmt->fetchColumn() ?? 0) / 3600);
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_readers_7days'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_readers_30days'] = $stmt->fetchColumn();

// Most active readers
$stmt = $db->query("
    SELECT u.name, u.email, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    GROUP BY rs.user_id
    ORDER BY total_time DESC LIMIT 5
");
$stats['most_active_readers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reading activity
$stmt = $db->prepare("
    SELECT u.name as user_name, b.title as book_title, rp.progress_percent, rp.last_accessed_at
    FROM reading_progress rp
    JOIN users u ON rp.user_id = u.id
    JOIN books b ON rp.book_id = b.id
    WHERE rp.progress_percent > 0
    ORDER BY rp.last_accessed_at DESC LIMIT 5
");
$stmt->execute();
$recent_reading_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent activity feed (for AJAX refresh)
$stmt = $db->prepare("
    SELECT u.name, u.profile_pic, 'comment' as type, r.comment as text, r.created_at 
    FROM reviews r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.deleted_at IS NULL 
    ORDER BY r.created_at DESC LIMIT 5
");
$stmt->execute();
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FETCH RECENT ITEMS FOR GRIDS
// ============================================================
// Pending sessions
$stmt = $db->prepare("
    SELECT s.*, u.name AS user_name, u.email 
    FROM sessions s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.status = 'pending' 
    ORDER BY s.date ASC, s.time ASC LIMIT 5
");
$stmt->execute();
$recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $db->prepare("SELECT * FROM contact_messages WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent books (limit 4 for mini-grid)
$stmt = $db->prepare("SELECT * FROM books ORDER BY created_at DESC LIMIT 4");
$stmt->execute();
$recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent poems
$stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 6");
$stmt->execute();
$recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent blog posts
$stmt = $db->prepare("SELECT * FROM blog_posts ORDER BY created_at DESC LIMIT 6");
$stmt->execute();
$recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reflections
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE category = 'Christian Reflections' ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent questions
$stmt = $db->prepare("SELECT * FROM questions ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent users
$stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent videos
$stmt = $db->prepare("SELECT * FROM videos ORDER BY created_at DESC LIMIT 6");
$stmt->execute();
$recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reading groups
$stmt = $db->prepare("SELECT name, created_at FROM reading_groups ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<!-- ===== Chart.js CDN ===== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ===== ADVANCED DASHBOARD CSS (with additional components) ===== */
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --bg: #F7F3ED; --card-bg: #fff; --border: #e5d5d5;
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.12);
}
body { background: var(--bg); font-family: 'Inter', sans-serif; transition: background 0.3s, color 0.3s; }
body.dark-mode { --bg: #1a1212; --card-bg: #2c1e1e; --border: #4a3a3a; --vanilla: #2c1e1e; }

.admin-hero { background: linear-gradient(135deg, var(--vanilla), var(--bg)); border-radius: 20px; padding: 24px 32px; margin-bottom: 24px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; border: 1px solid var(--rose-light); }
.admin-hero h1 { font-family: 'Playfair Display', serif; font-size: 2.2rem; margin:0; }
.admin-hero .live-status { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 0.7; } 50% { opacity: 1; } 100% { opacity: 0.7; } }

/* --- STATS CARDS WITH LIVE NUMBERS --- */
.admin-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
.admin-stat-card { background: var(--card-bg); border-radius: 14px; padding: 16px; border: 1px solid var(--border); text-align: center; transition: all 0.2s; }
.admin-stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.admin-stat-card .num { font-size: 2rem; font-weight: 700; color: var(--rose); display: block; font-variant-numeric: tabular-nums; }
.admin-stat-card .label { font-size: 0.7rem; text-transform: uppercase; color: #666; margin-top: 4px; }

/* --- ALERT ROW (Pending sessions & unread messages) --- */
.alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
.alert-card { background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.alert-card h4 { font-size: 1rem; margin: 0 0 8px 0; display: flex; align-items: center; gap: 6px; }
.alert-list { display: flex; flex-direction: column; gap: 6px; }
.alert-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.alert-item:last-child { border-bottom: none; }
.alert-item .badge { font-size: 0.7rem; padding: 2px 10px; border-radius: 12px; }
.badge-pending { background: #f1c40f; color: #fff; }
.badge-unread { background: var(--rose); color: #fff; }

/* --- GRID LAYOUT --- */
.admin-grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.admin-module { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.admin-module h3 { font-family: 'Playfair Display', serif; color: var(--rose-dark); font-size: 1.1rem; margin: 0 0 12px 0; border-bottom: 1px solid var(--border); padding-bottom: 6px; display: flex; justify-content: space-between; }
.admin-module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
.admin-module-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 14px 6px; background: var(--vanilla); border-radius: 12px; border: 1px solid var(--border); text-decoration: none; color: #333; transition: all 0.2s; text-align: center; }
.admin-module-btn:hover { transform: translateY(-3px); border-color: var(--rose); box-shadow: 0 4px 12px rgba(219,161,162,0.2); }
.admin-module-btn i { font-size: 1.4rem; color: var(--rose); margin-bottom: 4px; }
.admin-module-btn span { font-size: 0.75rem; font-weight: 600; }

/* --- RECENT CONTENT GRIDS --- */
.recent-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 20px; }
.recent-card { background: var(--card-bg); border-radius: 12px; padding: 12px; border: 1px solid var(--border); text-align: center; }
.recent-card img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; margin-bottom: 6px; }
.recent-card h4 { font-size: 0.9rem; margin: 0; }

/* --- ACTIVITY FEED --- */
.activity-feed { max-height: 300px; overflow-y: auto; }
.activity-item { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.activity-item:last-child { border-bottom: none; }
.activity-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--vanilla); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--rose); }
.activity-text { font-size: 0.9rem; }
.activity-time { font-size: 0.7rem; color: #999; margin-left: auto; white-space: nowrap; }

/* --- QUICK ACTION MODAL --- */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 9999; display: none; justify-content: center; align-items: center; }
.modal-overlay.active { display: flex; }
.modal-box { background: var(--card-bg); border-radius: 20px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); border: 1px solid var(--rose-light); }
.modal-box h2 { font-family: 'Playfair Display', serif; margin-top: 0; color: var(--rose-dark); }
.modal-box .btn { width: 100%; justify-content: center; margin-top: 8px; }

/* --- CHART CONTAINER --- */
.chart-container { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); margin-bottom: 20px; height: 250px; }

/* --- RESPONSIVE --- */
@media (max-width: 768px) {
    .alert-row { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .admin-hero { flex-direction: column; text-align: center; padding: 16px; }
    .admin-module-grid { grid-template-columns: repeat(2, 1fr); }
    .admin-stat-card .num { font-size: 1.4rem; }
    .recent-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container" style="padding: 20px;">
    
    <!-- Hero with Live Status -->
    <div class="admin-hero">
        <div>
            <h1>Welcome back, <span style="color:var(--rose);"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span></h1>
            <p style="color:#666;">Your command center – here's what's happening across your site.</p>
        </div>
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <span class="live-status"><i class="fas fa-circle"></i> Live</span>
            <button onclick="toggleDarkMode()" class="btn btn-outline btn-sm"><i class="fas fa-moon"></i> Dark</button>
            <button onclick="openQuickModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Quick Add</button>
        </div>
    </div>

    <!-- Stats Row with Live Data IDs -->
    <div class="admin-stats-grid">
        <div class="admin-stat-card"><span class="num" id="stat_users"><?php echo $stats['total_users']; ?></span><span class="label">Users</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_books"><?php echo $stats['total_books']; ?></span><span class="label">Books</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_poems"><?php echo $stats['total_poems']; ?></span><span class="label">Poems</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_sessions"><?php echo $stats['total_sessions']; ?></span><span class="label">Sessions</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_posts"><?php echo $stats['total_posts']; ?></span><span class="label">Blog</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_videos"><?php echo $stats['total_videos']; ?></span><span class="label">Videos</span></div>
    </div>

    <!-- Alert Row (Pending Sessions & Unread Messages) -->
    <div class="alert-row">
        <div class="alert-card">
            <h4><i class="fas fa-clock" style="color:var(--rose);"></i> Pending Sessions</h4>
            <div class="alert-list">
                <?php if (count($recent_sessions) > 0): ?>
                    <?php foreach ($recent_sessions as $session): ?>
                        <div class="alert-item">
                            <span><?php echo date('M j', strtotime($session['date'])); ?> at <?php echo date('g:i a', strtotime($session['time'])); ?> – <?php echo htmlspecialchars($session['user_name']); ?></span>
                            <span class="badge badge-pending">Pending</span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert-item" style="color:#999;">No pending sessions.</div>
                <?php endif; ?>
            </div>
            <a href="manage_sessions.php" class="btn btn-sm btn-outline" style="margin-top:8px;">View All</a>
        </div>
        <div class="alert-card">
            <h4><i class="fas fa-envelope" style="color:var(--rose);"></i> Unread Messages</h4>
            <div class="alert-list">
                <?php if (count($recent_messages) > 0): ?>
                    <?php foreach ($recent_messages as $msg): ?>
                        <div class="alert-item">
                            <span><strong><?php echo htmlspecialchars($msg['name']); ?></strong> – <?php echo htmlspecialchars(substr($msg['message'], 0, 35)); ?>...</span>
                            <span class="badge badge-unread">Unread</span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert-item" style="color:#999;">No unread messages.</div>
                <?php endif; ?>
            </div>
            <a href="manage_messages.php" class="btn btn-sm btn-outline" style="margin-top:8px;">View All</a>
        </div>
    </div>

    <!-- Chart Row (Growth Overview) -->
    <div class="chart-container">
        <canvas id="growthChart"></canvas>
    </div>

    <!-- Full Control Grid -->
    <div class="admin-grid-container">
        <div class="admin-module">
            <h3>📖 Books & Poetry</h3>
            <div class="admin-module-grid">
                <a href="manage_books.php" class="admin-module-btn"><i class="fas fa-book"></i><span>Manage Books</span></a>
                <a href="process_book.php" class="admin-module-btn"><i class="fas fa-cog"></i><span>Process Book</span></a>
                <a href="process_queue.php" class="admin-module-btn"><i class="fas fa-tasks"></i><span>Process Queue</span></a>
                <a href="manage_poems.php" class="admin-module-btn"><i class="fas fa-feather-alt"></i><span>Manage Poems</span></a>
                <a href="poem_editor.php" class="admin-module-btn"><i class="fas fa-edit"></i><span>Poem Editor</span></a>
                <a href="preview_poem.php" class="admin-module-btn"><i class="fas fa-eye"></i><span>Preview Poem</span></a>
            </div>
        </div>

        <div class="admin-module">
            <h3>✍️ Blog & Reflections</h3>
            <div class="admin-module-grid">
                <a href="manage_blog.php" class="admin-module-btn"><i class="fas fa-blog"></i><span>Manage Blog</span></a>
                <a href="editor.php" class="admin-module-btn"><i class="fas fa-pen-fancy"></i><span>Blog Editor</span></a>
                <a href="manage_reflections.php" class="admin-module-btn"><i class="fas fa-pray"></i><span>Reflections</span></a>
                <a href="reflection_editor.php" class="admin-module-btn"><i class="fas fa-edit"></i><span>Reflection Editor</span></a>
            </div>
        </div>

        <div class="admin-module">
            <h3>👥 Community & Users</h3>
            <div class="admin-module-grid">
                <a href="manage_users.php" class="admin-module-btn"><i class="fas fa-users"></i><span>Manage Users</span></a>
                <a href="manage_community.php" class="admin-module-btn"><i class="fas fa-question-circle"></i><span>Community Q&A</span></a>
                <a href="manage_questions.php" class="admin-module-btn"><i class="fas fa-comments"></i><span>Manage Questions</span></a>
                <a href="manage_groups.php" class="admin-module-btn"><i class="fas fa-users-cog"></i><span>Reading Groups</span></a>
                <a href="manage_sessions.php" class="admin-module-btn"><i class="fas fa-calendar-check"></i><span>Booked Sessions</span></a>
                <a href="manage_messages.php" class="admin-module-btn"><i class="fas fa-envelope"></i><span>Contact Messages</span></a>
            </div>
        </div>

        <div class="admin-module">
            <h3>🎥 Videos</h3>
            <div class="admin-module-grid">
                <a href="manage_videos.php" class="admin-module-btn"><i class="fas fa-video"></i><span>Manage Videos</span></a>
            </div>
        </div>

        <div class="admin-module">
            <h3>⚙️ Reader & System</h3>
            <div class="admin-module-grid">
                <a href="../reader/admin/reader_analytics.php" class="admin-module-btn"><i class="fas fa-chart-line"></i><span>Reader Analytics</span></a>
                <a href="settings.php" class="admin-module-btn"><i class="fas fa-sliders-h"></i><span>System Settings</span></a>
                <a href="../generate_og_image.php" class="admin-module-btn"><i class="fas fa-image"></i><span>Generate OG Image</span></a>
                <a href="../worker.php" class="admin-module-btn" target="_blank"><i class="fas fa-cogs"></i><span>Run Worker</span></a>
            </div>
        </div>

        <div class="admin-module">
            <h3>📧 Newsletter <span style="font-size:0.7rem; font-weight:400; color:#999;">(Tabs)</span></h3>
            <div class="admin-module-grid">
                <a href="manage_newsletter.php" class="admin-module-btn"><i class="fas fa-list"></i><span>Manage Subscribers</span></a>
                <a href="export_subscribers.php" class="admin-module-btn"><i class="fas fa-download"></i><span>Export Subscribers</span></a>
                <a href="newsletter_tabs/import.php" class="admin-module-btn"><i class="fas fa-upload"></i><span>Import</span></a>
                <a href="newsletter_tabs/segments.php" class="admin-module-btn"><i class="fas fa-tags"></i><span>Segments</span></a>
                <a href="newsletter_tabs/send.php" class="admin-module-btn"><i class="fas fa-paper-plane"></i><span>Send Campaign</span></a>
                <a href="newsletter_tabs/queue.php" class="admin-module-btn"><i class="fas fa-hourglass-half"></i><span>Queue</span></a>
                <a href="newsletter_tabs/archive.php" class="admin-module-btn"><i class="fas fa-archive"></i><span>Archive</span></a>
                <a href="newsletter_tabs/audit.php" class="admin-module-btn"><i class="fas fa-history"></i><span>Audit Log</span></a>
            </div>
        </div>

        <!-- Add a dedicated Comments module -->
        <div class="admin-module">
            <h3>💬 Comments</h3>
            <div class="admin-module-grid">
                <a href="comments.php" class="admin-module-btn"><i class="fas fa-comments"></i><span>Manage Comments</span></a>
            </div>
        </div>
    </div>

    <!-- Recent Content Grids -->
    <div style="margin-top: 20px;">
        <h3 style="font-family:'Playfair Display'; margin-bottom:12px;">📚 Recent Content</h3>
        <div class="recent-grid">
            <div class="recent-card">
                <h4>Books</h4>
                <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">
                    <?php foreach ($recent_books as $book): ?>
                        <div style="display:flex; gap:8px; align-items:center; border-bottom:1px solid var(--border); padding:4px 0;">
                            <img src="<?php echo get_image_url($book['cover_path']); ?>" style="width:30px; height:30px; border-radius:4px; object-fit:cover;">
                            <span style="font-size:0.85rem;"><?php echo htmlspecialchars($book['title']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="recent-card">
                <h4>Poems</h4>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($recent_poems as $poem): ?>
                        <span style="background:var(--vanilla); padding:4px 10px; border-radius:20px; font-size:0.75rem;"><?php echo htmlspecialchars($poem['title']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="recent-card">
                <h4>Blog</h4>
                <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">
                    <?php foreach ($recent_posts as $post): ?>
                        <div style="border-bottom:1px solid var(--border); padding:4px 0;">
                            <span style="font-size:0.85rem;"><?php echo htmlspecialchars($post['title']); ?></span>
                            <span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($post['created_at'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="recent-card">
                <h4>Videos</h4>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($recent_videos as $video): ?>
                        <span style="background:var(--vanilla); padding:4px 10px; border-radius:20px; font-size:0.75rem;"><?php echo htmlspecialchars($video['title']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Feed (AJAX Update) -->
    <div class="admin-module" style="margin-top:20px; max-width: 100%;">
        <h3 style="border:none;">🔥 Recent Activity <button class="btn btn-sm btn-outline" onclick="refreshActivity()" style="float:right; padding:2px 12px;"><i class="fas fa-sync-alt"></i></button></h3>
        <div class="activity-feed" id="activityFeed">
            <?php if (count($recent_activity) > 0): ?>
                <?php foreach ($recent_activity as $act): ?>
                    <div class="activity-item">
                        <div class="activity-avatar"><?php echo strtoupper(substr($act['name'], 0, 1)); ?></div>
                        <div class="activity-text"><strong><?php echo htmlspecialchars($act['name']); ?></strong> <?php echo htmlspecialchars(substr($act['text'], 0, 60)); ?>...</div>
                        <div class="activity-time"><?php echo date('M j, g:i a', strtotime($act['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="activity-item" style="color:#999;">No recent activity.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Quick Action Modal -->
<div class="modal-overlay" id="quickModal">
    <div class="modal-box">
        <h2>Quick Create</h2>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <a href="manage_books.php?action=new" class="btn btn-primary">+ New Book</a>
            <a href="manage_poems.php?action=new" class="btn btn-primary">+ New Poem</a>
            <a href="manage_blog.php?action=new" class="btn btn-primary">+ New Blog Post</a>
            <a href="manage_users.php?action=new" class="btn btn-primary">+ New User</a>
            <button onclick="closeQuickModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// 1. DARK MODE PERSISTENCE
// ============================================================
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('adminDarkMode', document.body.classList.contains('dark-mode') ? '1' : '0');
}
if (localStorage.getItem('adminDarkMode') === '1') {
    document.body.classList.add('dark-mode');
}

// ============================================================
// 2. QUICK MODAL
// ============================================================
function openQuickModal() { document.getElementById('quickModal').classList.add('active'); }
function closeQuickModal() { document.getElementById('quickModal').classList.remove('active'); }
document.getElementById('quickModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickModal();
});

// ============================================================
// 3. AJAX LIVE STATS REFRESH (Every 60 seconds)
// ============================================================
function refreshStats() {
    fetch('ajax_admin_stats.php')
        .then(res => res.json())
        .then(data => {
            document.getElementById('stat_users').textContent = data.users;
            document.getElementById('stat_books').textContent = data.books;
            document.getElementById('stat_poems').textContent = data.poems;
            document.getElementById('stat_sessions').textContent = data.sessions;
            document.getElementById('stat_posts').textContent = data.posts;
            document.getElementById('stat_videos').textContent = data.videos;
        })
        .catch(err => console.error('Stats refresh failed:', err));
}
setInterval(refreshStats, 60000);

// ============================================================
// 4. AJAX ACTIVITY FEED REFRESH
// ============================================================
function refreshActivity() {
    fetch('ajax_admin_activity.php')
        .then(res => res.text())
        .then(html => {
            document.getElementById('activityFeed').innerHTML = html;
        })
        .catch(err => console.error('Activity refresh failed:', err));
}

// ============================================================
// 5. GROWTH CHART (Chart.js)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('growthChart').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 250);
    gradient.addColorStop(0, '#DBA1A2');
    gradient.addColorStop(1, '#e8c0c0');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'New Users',
                data: [12, 19, 3, 5, 2, 3, 8],
                borderColor: '#DBA1A2',
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                borderWidth: 2,
                pointBackgroundColor: '#DBA1A2'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>