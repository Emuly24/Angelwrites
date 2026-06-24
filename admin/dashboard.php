<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ============================================================
// 1. FETCH STATISTICS (all wrapped in try-catch)
// ============================================================
$stats = [];
try { $stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['total_users'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats users: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['total_books'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats books: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['total_poems'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats poems: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['total_sessions'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats sessions: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['total_posts'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats blog_posts: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['total_videos'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats videos: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT SUM(view_count) FROM poems"); $stats['poem_views'] = $stmt->fetchColumn() ?? 0; } catch (Exception $e) { error_log("Stats poem views: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT SUM(view_count) FROM books"); $stats['book_views'] = $stmt->fetchColumn() ?? 0; } catch (Exception $e) { error_log("Stats book views: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions"); $stats['total_reading_hours'] = floor(($stmt->fetchColumn() ?? 0) / 3600); } catch (Exception $e) { error_log("Stats reading hours: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-1 day')"); $stats['active_today'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats active today: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_week'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats active week: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_month'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats active month: " . $e->getMessage()); }
try { $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-365 days')"); $stats['active_year'] = $stmt->fetchColumn(); } catch (Exception $e) { error_log("Stats active year: " . $e->getMessage()); }
$stats['total_views'] = ($stats['poem_views'] ?? 0) + ($stats['book_views'] ?? 0);

// ============================================================
// 2. FETCH RECENT ITEMS
// ============================================================
try { $stmt = $db->prepare("SELECT s.*, u.name AS user_name FROM sessions s JOIN users u ON s.user_id = u.id WHERE s.status = 'pending' ORDER BY s.date ASC LIMIT 5"); $stmt->execute(); $recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT * FROM contact_messages WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5"); $stmt->execute(); $recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// ============================================================
// 3. FETCH TOP CONTENT
// ============================================================
$top_poems = []; $top_books = []; $top_blog = []; $top_reflections = []; $top_videos = [];
try { $stmt = $db->prepare("SELECT id, title, image_path, view_count FROM poems ORDER BY view_count DESC LIMIT 5"); $stmt->execute(); $top_poems = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT id, title, cover_path, view_count FROM books ORDER BY view_count DESC LIMIT 5"); $stmt->execute(); $top_books = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT bp.id, bp.title, bp.featured_image, (SELECT COUNT(*) FROM reviews WHERE target_type='blog' AND target_id=bp.id) as comment_count FROM blog_posts bp ORDER BY comment_count DESC LIMIT 5"); $stmt->execute(); $top_blog = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT bp.id, bp.title, bp.featured_image, (SELECT COUNT(*) FROM reviews WHERE target_type='reflection' AND target_id=bp.id) as comment_count FROM blog_posts bp WHERE category = 'Christian Reflections' ORDER BY comment_count DESC LIMIT 5"); $stmt->execute(); $top_reflections = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT v.id, v.title, v.thumbnail, v.view_count FROM videos v ORDER BY v.view_count DESC LIMIT 5"); $stmt->execute(); $top_videos = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// ============================================================
// 4. FETCH RECENT CONTENT
// ============================================================
$recent_books = []; $recent_poems = []; $recent_posts = []; $recent_videos = [];
try { $stmt = $db->prepare("SELECT * FROM books ORDER BY created_at DESC LIMIT 4"); $stmt->execute(); $recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 6"); $stmt->execute(); $recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT * FROM blog_posts ORDER BY created_at DESC LIMIT 6"); $stmt->execute(); $recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT * FROM videos ORDER BY id DESC LIMIT 6"); $stmt->execute(); $recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// ============================================================
// 5. FETCH RECENT ACTIVITY
// ============================================================
$recent_activity = [];
try { $stmt = $db->prepare("SELECT u.name, u.profile_pic, 'comment' as type, r.comment as text, r.created_at FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 5"); $stmt->execute(); $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #f0dad9;
    --bg: #F7F3ED; --card-bg: #ffffff; --border: #e5d5d5;
    --shadow: 0 4px 20px rgba(0,0,0,0.04);
}
body { background: var(--bg); font-family: 'Inter', sans-serif; transition: background 0.3s, color 0.3s; color: #333; }
body.dark-mode { --bg: #1a1212; --card-bg: #2c1e1e; --border: #4a3a3a; color: #e0d0d0; }
body.dark-mode .admin-module-btn { background: #3a2a2a; color: #e0d0d0; }

/* Hero */
.admin-hero { background: linear-gradient(135deg, #fff, var(--rose-light)); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; border: 1px solid var(--rose-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
.admin-hero h1 { font-family: 'Playfair Display', serif; font-size: 2.2rem; margin:0; letter-spacing: -0.5px; }
.admin-hero .sub { color: #666; margin-top: 4px; font-size: 0.95rem; }

/* Core Stats */
.admin-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 28px; }
.admin-stat-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); text-align: center; transition: 0.2s; }
.admin-stat-card:hover { transform: translateY(-4px); border-color: var(--rose); }
.admin-stat-card .num { font-size: 2.2rem; font-weight: 700; color: var(--rose); display: block; }
.admin-stat-card .label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #666; margin-top: 6px; }

/* Alerts */
.alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.alert-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.alert-card h4 { font-size: 1rem; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
.alert-list { display: flex; flex-direction: column; gap: 10px; }
.alert-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.alert-item:last-child { border-bottom: none; }

/* Charts */
.chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
.chart-container { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); height: 260px; position: relative; }
@media (max-width: 768px) { .chart-row { grid-template-columns: 1fr; } .alert-row { grid-template-columns: 1fr; } }

/* Monitoring Stats */
.monitoring-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 28px; }
.monitor-card { background: var(--card-bg); border-radius: 12px; padding: 14px; border: 1px solid var(--border); }
.monitor-card .title { font-size: 0.75rem; color: #888; text-transform: uppercase; }
.monitor-card .value { font-size: 1.6rem; font-weight: 700; color: var(--rose); margin: 4px 0; }
.monitor-card .sub { font-size: 0.75rem; color: #666; }

/* Top Content */
.top-content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
.top-content-card { background: var(--card-bg); border-radius: 16px; padding: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.top-content-card h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }

/* Recent Grid */
.recent-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.recent-card { background: var(--card-bg); border-radius: 12px; padding: 12px; border: 1px solid var(--border); }
.recent-card h4 { font-size: 0.9rem; margin: 0 0 8px 0; border-bottom: 1px solid var(--border); padding-bottom: 4px; }
.recent-list { display: flex; flex-direction: column; gap: 4px; }
.recent-item { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
.recent-item:last-child { border-bottom: none; }

/* Activity Feed */
.activity-feed { max-height: 300px; overflow-y: auto; }
.activity-item { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.activity-item:last-child { border-bottom: none; }
.activity-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--vanilla); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--rose); }
.activity-text { font-size: 0.9rem; }
.activity-time { font-size: 0.7rem; color: #999; margin-left: auto; white-space: nowrap; }

/* Admin Modules */
.admin-grid-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px; }
.admin-module { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.admin-module h3 { font-family: 'Playfair Display', serif; color: var(--rose-dark); font-size: 1.1rem; margin: 0 0 16px 0; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
.admin-module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
.admin-module-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px 8px; background: var(--bg); border-radius: 12px; border: 1px solid var(--border); text-decoration: none; color: #333; transition: 0.2s; text-align: center; }
.admin-module-btn:hover { transform: translateY(-3px); border-color: var(--rose); box-shadow: 0 4px 12px rgba(219,161,162,0.2); }
.admin-module-btn i { font-size: 1.4rem; color: var(--rose); margin-bottom: 6px; }
.admin-module-btn span { font-size: 0.75rem; font-weight: 500; }

/* Modals */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 9999; display: none; justify-content: center; align-items: center; }
.modal-overlay.active { display: flex; }
.modal-box { background: var(--card-bg); border-radius: 20px; padding: 32px; max-width: 420px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); border: 1px solid var(--rose-light); }
.modal-box h2 { font-family: 'Playfair Display', serif; margin-top: 0; color: var(--rose-dark); }
.modal-box .btn { width: 100%; justify-content: center; margin-bottom: 10px; }

/* Stats Modal Override */
#statsModal .modal-box { max-width: 800px; }
#statsModal table { width:100%; border-collapse:collapse; font-size:0.9rem; }
#statsModal table th { background: var(--vanilla); padding:8px 12px; text-align:left; border-bottom:2px solid var(--border); }
#statsModal table td { padding:8px 12px; border-bottom:1px solid var(--border); text-align:left; }
</style>

<div class="container" style="max-width: 1400px; margin: 0 auto; padding: 20px;">
    <!-- Hero -->
    <div class="admin-hero">
        <div>
            <h1>Welcome back, <span style="color:var(--rose);"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span></h1>
            <div class="sub">Your command center – real-time overview of your site.</div>
        </div>
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <span style="background:#28a745; color:white; padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; animation:pulse 2s infinite;"><i class="fas fa-circle"></i> Live</span>
            <button onclick="openQuickModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Quick Add</button>
        </div>
    </div>

    <!-- Core Stats -->
    <div class="admin-stats-grid">
        <div class="admin-stat-card"><span class="num" id="stat_users"><?php echo $stats['total_users'] ?? 0; ?></span><span class="label">Users</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_books"><?php echo $stats['total_books'] ?? 0; ?></span><span class="label">Books</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_poems"><?php echo $stats['total_poems'] ?? 0; ?></span><span class="label">Poems</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_sessions"><?php echo $stats['total_sessions'] ?? 0; ?></span><span class="label">Sessions</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_posts"><?php echo $stats['total_posts'] ?? 0; ?></span><span class="label">Blog</span></div>
        <div class="admin-stat-card"><span class="num" id="stat_videos"><?php echo $stats['total_videos'] ?? 0; ?></span><span class="label">Videos</span></div>
    </div>

    <!-- Alerts -->
    <div class="alert-row">
        <div class="alert-card"><h4><i class="fas fa-clock" style="color:var(--rose);"></i> Pending Sessions</h4>
            <div class="alert-list"><?php if(count($recent_sessions)>0): foreach($recent_sessions as $s): ?>
                <div class="alert-item"><span><?php echo date('M j, g:i a', strtotime($s['date'].' '.$s['time'])); ?> – <?php echo htmlspecialchars($s['user_name']); ?></span><span class="badge badge-warning">Pending</span></div>
            <?php endforeach; else: ?><div class="alert-item" style="color:#999;">No pending sessions.</div><?php endif; ?></div>
        </div>
        <div class="alert-card"><h4><i class="fas fa-envelope" style="color:var(--rose);"></i> Unread Messages</h4>
            <div class="alert-list"><?php if(count($recent_messages)>0): foreach($recent_messages as $m): ?>
                <div class="alert-item"><span><strong><?php echo htmlspecialchars($m['name']); ?></strong> – <?php echo htmlspecialchars(substr($m['message'],0,30)); ?>...</span><span class="badge badge-danger">Unread</span></div>
            <?php endforeach; else: ?><div class="alert-item" style="color:#999;">No unread messages.</div><?php endif; ?></div>
        </div>
    </div>

    <!-- Charts -->
    <div class="chart-row">
        <div class="chart-container"><canvas id="activeChart"></canvas></div>
        <div class="chart-container"><canvas id="viewsChart"></canvas></div>
    </div>

    <!-- Monitoring Stats -->
    <div class="monitoring-grid">
        <div class="monitor-card" style="cursor: pointer;" onclick="openStatsModal('views')">
            <div class="title">Total Views</div>
            <div class="value" id="total_views"><?php echo number_format($stats['total_views'] ?? 0); ?></div>
            <div class="sub">Poems + Books <span style="font-size:0.7rem; color:#ccc;">(Click to view)</span></div>
        </div>
        <div class="monitor-card"><div class="title">Active Today</div><div class="value" id="active_today"><?php echo $stats['active_today'] ?? 0; ?></div><div class="sub">Logged‑in users</div></div>
        <div class="monitor-card"><div class="title">Active Week</div><div class="value" id="active_week"><?php echo $stats['active_week'] ?? 0; ?></div><div class="sub">Last 7 days</div></div>
        <div class="monitor-card"><div class="title">Active Month</div><div class="value" id="active_month"><?php echo $stats['active_month'] ?? 0; ?></div><div class="sub">Last 30 days</div></div>
        <div class="monitor-card"><div class="title">Active Year</div><div class="value" id="active_year"><?php echo $stats['active_year'] ?? 0; ?></div><div class="sub">Last 365 days</div></div>
        <div class="monitor-card" style="cursor: pointer;" onclick="openStatsModal('reading')">
            <div class="title">Reading Hours</div>
            <div class="value" id="reading_hours"><?php echo number_format($stats['total_reading_hours'] ?? 0); ?></div>
            <div class="sub">All users <span style="font-size:0.7rem; color:#ccc;">(Click to view)</span></div>
        </div>
    </div>

    <!-- Trending Content -->
    <div style="margin-bottom: 20px;">
        <h3 style="font-family:'Playfair Display'; margin-bottom:12px;">🔥 Trending Content</h3>
        <div class="top-content-grid">
            <div class="top-content-card"><h4>Top Poems</h4><?php foreach($top_poems as $p): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><img src="<?php echo get_image_url($p['image_path']); ?>" style="width:40px; height:40px; border-radius:4px; object-fit:cover;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($p['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo number_format($p['view_count']); ?> views</div></div></div><?php endforeach; ?></div>
            <div class="top-content-card"><h4>Top Books</h4><?php foreach($top_books as $b): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><img src="<?php echo get_image_url($b['cover_path']); ?>" style="width:40px; height:40px; border-radius:4px; object-fit:cover;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($b['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo number_format($b['view_count']); ?> views</div></div></div><?php endforeach; ?></div>
            <div class="top-content-card"><h4>Top Blog Posts</h4><?php foreach($top_blog as $p): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><img src="<?php echo get_image_url($p['featured_image']); ?>" style="width:40px; height:40px; border-radius:4px; object-fit:cover;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($p['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo $p['comment_count']; ?> comments</div></div></div><?php endforeach; ?></div>
            <div class="top-content-card"><h4>Top Reflections</h4><?php foreach($top_reflections as $r): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><img src="<?php echo get_image_url($r['featured_image']); ?>" style="width:40px; height:40px; border-radius:4px; object-fit:cover;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($r['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo $r['comment_count']; ?> comments</div></div></div><?php endforeach; ?></div>
            <div class="top-content-card"><h4>Top Videos</h4><?php foreach($top_videos as $v): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><img src="<?php echo get_image_url($v['thumbnail']); ?>" style="width:40px; height:40px; border-radius:4px; object-fit:cover;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($v['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo number_format($v['view_count']); ?> views</div></div></div><?php endforeach; ?></div>
        </div>
    </div>

    <!-- Recent Content Grids -->
    <div style="margin-bottom: 20px;">
        <h3 style="font-family:'Playfair Display'; margin-bottom:12px;">📚 Recent Content</h3>
        <div class="recent-grid">
            <div class="recent-card"><h4>Books</h4><div class="recent-list"><?php foreach($recent_books as $b): ?><div class="recent-item"><span><?php echo htmlspecialchars($b['title']); ?></span><span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($b['created_at'])); ?></span></div><?php endforeach; ?></div></div>
            <div class="recent-card"><h4>Poems</h4><div class="recent-list"><?php foreach($recent_poems as $p): ?><div class="recent-item"><span><?php echo htmlspecialchars($p['title']); ?></span><span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($p['created_at'])); ?></span></div><?php endforeach; ?></div></div>
            <div class="recent-card"><h4>Blog</h4><div class="recent-list"><?php foreach($recent_posts as $p): ?><div class="recent-item"><span><?php echo htmlspecialchars($p['title']); ?></span><span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($p['created_at'])); ?></span></div><?php endforeach; ?></div></div>
            <div class="recent-card"><h4>Videos</h4><div class="recent-list"><?php foreach($recent_videos as $v): ?><div class="recent-item"><span><?php echo htmlspecialchars($v['title']); ?></span><span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($v['created_at'] ?? 'now')); ?></span></div><?php endforeach; ?></div></div>
        </div>
    </div>

    <!-- Admin Modules -->
    <div class="admin-grid-container">
        <div class="admin-module"><h3>📖 Books & Poetry</h3><div class="admin-module-grid">
            <a href="manage_books.php" class="admin-module-btn"><i class="fas fa-book"></i><span>Manage Books</span></a>
            <a href="process_book.php" class="admin-module-btn"><i class="fas fa-cog"></i><span>Process Book</span></a>
            <a href="manage_poems.php" class="admin-module-btn"><i class="fas fa-feather-alt"></i><span>Manage Poems</span></a>
            <a href="poem_editor.php" class="admin-module-btn"><i class="fas fa-edit"></i><span>Poem Editor</span></a>
        </div></div>
        <div class="admin-module"><h3>✍️ Blog & Reflections</h3><div class="admin-module-grid">
            <a href="manage_blog.php" class="admin-module-btn"><i class="fas fa-blog"></i><span>Manage Blog</span></a>
            <a href="editor.php" class="admin-module-btn"><i class="fas fa-pen-fancy"></i><span>Blog Editor</span></a>
            <a href="manage_reflections.php" class="admin-module-btn"><i class="fas fa-pray"></i><span>Reflections</span></a>
        </div></div>
        <div class="admin-module"><h3>👥 Community & Users</h3><div class="admin-module-grid">
            <a href="manage_users.php" class="admin-module-btn"><i class="fas fa-users"></i><span>Manage Users</span></a>
            <a href="manage_community.php" class="admin-module-btn"><i class="fas fa-question-circle"></i><span>Community Q&A</span></a>
            <a href="manage_groups.php" class="admin-module-btn"><i class="fas fa-users-cog"></i><span>Reading Groups</span></a>
            <a href="manage_sessions.php" class="admin-module-btn"><i class="fas fa-calendar-check"></i><span>Booked Sessions</span></a>
        </div></div>
        <div class="admin-module"><h3>🎥 Videos & Comments</h3><div class="admin-module-grid">
            <a href="manage_videos.php" class="admin-module-btn"><i class="fas fa-video"></i><span>Manage Videos</span></a>
            <a href="comments.php" class="admin-module-btn"><i class="fas fa-comments"></i><span>Manage Comments</span></a>
        </div></div>
        <div class="admin-module"><h3>📧 Newsletter</h3><div class="admin-module-grid">
            <a href="manage_newsletter.php" class="admin-module-btn"><i class="fas fa-list"></i><span>Subscribers</span></a>
            <a href="manage_newsletter.php?tab=send" class="admin-module-btn"><i class="fas fa-paper-plane"></i><span>Send Campaign</span></a>
            <a href="manage_newsletter.php?tab=queue" class="admin-module-btn"><i class="fas fa-clock"></i><span>Queue</span></a>
            <a href="manage_newsletter.php?tab=archive" class="admin-module-btn"><i class="fas fa-archive"></i><span>Archive</span></a>
        </div></div>
        <div class="admin-module"><h3>⚙️ System & Analytics</h3><div class="admin-module-grid">
            <a href="settings.php" class="admin-module-btn"><i class="fas fa-sliders-h"></i><span>System Settings</span></a>
            <a href="../reader/admin/reader_analytics.php" class="admin-module-btn"><i class="fas fa-chart-line"></i><span>Reader Analytics</span></a>
        </div></div>
    </div>

    <!-- Activity Feed -->
    <div class="admin-module" style="margin-top:20px;">
        <h3 style="border:none;">🔥 Recent Activity <button class="btn btn-sm btn-outline" onclick="refreshActivity()" style="float:right;"><i class="fas fa-sync-alt"></i></button></h3>
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

    <!-- Quick Add Modal -->
    <div class="modal-overlay" id="quickModal">
        <div class="modal-box">
            <h2>Quick Create</h2>
            <p style="color:#666; margin-bottom: 16px;">Select what you want to add</p>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="manage_books.php?action=new" class="btn btn-primary">+ New Book</a>
                <a href="manage_poems.php?action=new" class="btn btn-primary">+ New Poem</a>
                <a href="manage_blog.php?action=new" class="btn btn-primary">+ New Blog Post</a>
                <a href="manage_videos.php?action=new" class="btn btn-primary">+ New Video</a>
                <a href="reflection_editor.php" class="btn btn-primary">+ New Reflection</a>
                <a href="manage_newsletter.php?tab=send" class="btn btn-primary">+ New Newsletter</a>
                <button onclick="closeQuickModal()" class="btn btn-secondary" style="background: transparent; border: 1px solid #ccc; color:#666; margin-top: 8px;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Deep Dive Stats Modal -->
    <div id="statsModal" class="modal-overlay" style="z-index: 99999;">
        <div class="modal-box">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
                <h2 id="statsModalTitle" style="font-family:'Playfair Display'; color: var(--rose-dark); margin:0;">Loading...</h2>
                <button onclick="closeStatsModal()" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
            </div>
            <div id="statsModalBody" style="max-height: 60vh; overflow-y: auto; font-size:0.9rem;">
                <p style="color:#999; text-align:center;">Loading data...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Dark mode will be triggered by global header
if (localStorage.getItem('adminDarkMode') === '1') { document.body.classList.add('dark-mode'); }

// ===== Quick Modal Functions =====
function openQuickModal() { document.getElementById('quickModal').classList.add('active'); }
function closeQuickModal() { document.getElementById('quickModal').classList.remove('active'); }
document.getElementById('quickModal').addEventListener('click', function(e) { if(e.target===this) closeQuickModal(); });

// ===== Live Stats Polling =====
function refreshStats() {
    fetch('<?php echo SITE_URL; ?>/ajax_admin.php?action=stats')
        .then(res => res.json()).then(data => {
            ['users','books','poems','sessions','posts','videos'].forEach(k => {
                document.getElementById('stat_'+k).textContent = data[k] ?? 0;
            });
            document.getElementById('total_views').textContent = data.total_views ?? 0;
            document.getElementById('active_today').textContent = data.active_today ?? 0;
            document.getElementById('active_week').textContent = data.active_week ?? 0;
            document.getElementById('active_month').textContent = data.active_month ?? 0;
            document.getElementById('active_year').textContent = data.active_year ?? 0;
            document.getElementById('reading_hours').textContent = data.reading_hours ?? 0;
        }).catch(console.error);
}
setInterval(refreshStats, 60000);

// ===== Activity Feed Refresh =====
function refreshActivity() {
    fetch('<?php echo SITE_URL; ?>/ajax_admin.php?action=activity')
        .then(res => res.text())
        .then(html => { document.getElementById('activityFeed').innerHTML = html; })
        .catch(console.error);
}

// ===== SMOOTH & SMART CHARTS =====
function initCharts() {
    const gradientActive = document.getElementById('activeChart').getContext('2d').createLinearGradient(0,0,0,250);
    gradientActive.addColorStop(0, '#DBA1A2'); gradientActive.addColorStop(1, '#e8c0c0');

    window.activeChart = new Chart(document.getElementById('activeChart'), {
        type: 'line',
        data: { labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], datasets: [{ label: 'Active Readers', data: [0,0,0,0,0,0,0], borderColor: '#DBA1A2', backgroundColor: gradientActive, fill: true, tension: 0.4, borderWidth: 3, pointBackgroundColor: '#DBA1A2', pointRadius: 3 }] },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            animation: { duration: 1500, easing: 'easeOutQuart' },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(44,30,30,0.9)', titleColor: '#fff', bodyColor: '#eee', cornerRadius: 8 } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' } }, x: { grid: { display: false } } }
        }
    });

    window.viewsChart = new Chart(document.getElementById('viewsChart'), {
        type: 'bar', 
        data: { labels: ['Poems','Books','Blog','Videos'], datasets: [{ label: 'Views (7 days)', data: [0,0,0,0], backgroundColor: ['#DBA1A2','#c08a8b','#e8c0c0','#EFD8D6'], borderRadius: 6, borderSkipped: false }] },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            animation: { duration: 1500, easing: 'easeOutQuart' },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(44,30,30,0.9)', titleColor: '#fff', bodyColor: '#eee', cornerRadius: 8 } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' } }, x: { grid: { display: false } } }
        }
    });
    updateCharts();
    setInterval(updateCharts, 60000);
}

function updateCharts() {
    fetch('<?php echo SITE_URL; ?>/ajax_admin.php?action=active_chart')
        .then(res => res.json()).then(data => { 
            window.activeChart.data.labels = data.labels; 
            window.activeChart.data.datasets[0].data = data.data; 
            window.activeChart.update(); 
        }).catch(console.error);
    fetch('<?php echo SITE_URL; ?>/ajax_admin.php?action=views_chart')
        .then(res => res.json()).then(data => { 
            window.viewsChart.data.datasets[0].data = data.data; 
            window.viewsChart.update(); 
        }).catch(console.error);
}
document.addEventListener('DOMContentLoaded', initCharts);

// ============================================================
// DEEP DIVE MODAL FUNCTIONS (Click on Views/Reading Cards)
// ============================================================
function openStatsModal(type) {
    document.getElementById('statsModal').classList.add('active');
    const body = document.getElementById('statsModalBody');
    const title = document.getElementById('statsModalTitle');
    body.innerHTML = '<p style="color:#999; text-align:center;">Fetching data...</p>';

    const url = '<?php echo SITE_URL; ?>/ajax_admin.php?action=' + (type === 'views' ? 'get_view_details' : 'get_reading_details');
    title.textContent = type === 'views' ? '📊 Detailed Content Views' : '📖 Detailed Reading Sessions';

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data.success || data.logs.length === 0) {
                body.innerHTML = '<p style="color:#999; text-align:center;">No data available yet.</p>';
                return;
            }

            let html = '<table><thead><tr><th>Viewer / User</th><th>Content</th><th>Date & Time</th></tr></thead><tbody>';
            data.logs.forEach(row => {
                let titleCol = '';
                let subTitle = '';
                if(type === 'views') {
                    titleCol = row.target_type === 'poem' ? htmlspecialchars(row.poem_title) : htmlspecialchars(row.book_title);
                    subTitle = `<span style="color:#999;font-size:0.75rem;display:block;">${row.target_type}</span>`;
                    let viewer = htmlspecialchars(row.viewer_name);
                    if(viewer === 'Guest') {
                        viewer = `<span style="color:#888;">Guest</span> <span style="font-size:0.75rem; color:#999;">(IP: ${htmlspecialchars(row.ip_address)})</span>`;
                    }
                    html += `<tr><td>${viewer}</td><td><strong>${titleCol}</strong> ${subTitle}</td><td style="color:#666;">${new Date(row.viewed_at).toLocaleString()}</td></tr>`;
                } else {
                    let dur = Math.floor(row.duration_seconds / 60);
                    let sec = row.duration_seconds % 60;
                    html += `<tr><td><strong>${htmlspecialchars(row.user_name)}</strong> <span style="font-size:0.75rem; color:#999;">(${htmlspecialchars(row.user_email)})</span></td><td>${htmlspecialchars(row.book_title || 'Unknown Book')}</td><td style="color:#666;">${new Date(row.start_time).toLocaleString()} <br> <span style="font-size:0.75rem; background:#eee; padding:2px 6px; border-radius:10px;">${dur}m ${sec}s</span></td></tr>`;
                }
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<p style="color:red; text-align:center;">Error loading data.</p>';
            console.error(err);
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
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        if (m === '"') return '&quot;';
        return m;
    });
}
</script>
<?php require_once '../includes/footer.php'; ?>