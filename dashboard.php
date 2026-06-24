<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

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
// 3. FETCH STATISTICS (All tied to current user)
// ============================================================
// Books reading & finished
try { $stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'currently reading'"); $stmt->execute([$user_id]); $stats['books_reading'] = $stmt->fetchColumn(); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'"); $stmt->execute([$user_id]); $stats['books_finished'] = $stmt->fetchColumn(); } catch (Exception $e) {}
// Poems & Videos
try { $stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ?"); $stmt->execute([$user_id]); $stats['poems_read'] = $stmt->fetchColumn(); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ?"); $stmt->execute([$user_id]); $stats['videos_watched'] = $stmt->fetchColumn(); } catch (Exception $e) {}
// Questions & Sessions
try { $stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE user_id = ?"); $stmt->execute([$user_id]); $stats['questions_asked'] = $stmt->fetchColumn(); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE user_id = ?"); $stmt->execute([$user_id]); $stats['sessions_booked'] = $stmt->fetchColumn(); } catch (Exception $e) {}
// Streak & Rep
try { $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?"); $stmt->execute([$user_id]); $stats['current_streak'] = $stmt->fetchColumn() ?? 0; } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT points, level FROM user_reputations WHERE user_id = ?"); $stmt->execute([$user_id]); $rep = $stmt->fetch(PDO::FETCH_ASSOC); $stats['points'] = $rep['points'] ?? 0; $stats['level'] = $rep['level'] ?? 1; } catch (Exception $e) {}

// My total view counts
$stats['my_views'] = 0;
try { 
    $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE id IN (SELECT poem_id FROM poem_reads WHERE user_id = ?)"); 
    $stmt->execute([$user_id]); $stats['my_poem_views'] = $stmt->fetchColumn() ?? 0;
    $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE id IN (SELECT book_id FROM reading_status WHERE user_id = ?)"); 
    $stmt->execute([$user_id]); $stats['my_book_views'] = $stmt->fetchColumn() ?? 0;
    $stats['my_views'] = $stats['my_poem_views'] + $stats['my_book_views'];
} catch (Exception $e) {}

// My personal reading times
try { 
    $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ?"); 
    $stmt->execute([$user_id]); $stats['my_reading_hours'] = floor(($stmt->fetchColumn() ?? 0) / 3600);
    $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ? AND start_time > date('now', '-1 day')"); 
    $stmt->execute([$user_id]); $stats['my_minutes_today'] = floor(($stmt->fetchColumn() ?? 0) / 60);
    $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ? AND start_time > date('now', '-7 days')"); 
    $stmt->execute([$user_id]); $stats['my_minutes_week'] = floor(($stmt->fetchColumn() ?? 0) / 60);
    $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ? AND start_time > date('now', '-30 days')"); 
    $stmt->execute([$user_id]); $stats['my_minutes_month'] = floor(($stmt->fetchColumn() ?? 0) / 60);
    $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ? AND start_time > date('now', '-365 days')"); 
    $stmt->execute([$user_id]); $stats['my_minutes_year'] = floor(($stmt->fetchColumn() ?? 0) / 60);
} catch (Exception $e) {}

// ============================================================
// 4. FETCH RECENT CONTENT (Books, Poems, Blog, Videos)
// ============================================================
try { $stmt = $db->prepare("SELECT b.*, rs.progress FROM books b JOIN reading_status rs ON b.id = rs.book_id WHERE rs.user_id = ? AND rs.status = 'currently reading' ORDER BY rs.updated_at DESC LIMIT 2"); $stmt->execute([$user_id]); $reading_books = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT p.* FROM poems p JOIN poem_reads pr ON p.id = pr.poem_id WHERE pr.user_id = ? ORDER BY pr.read_at DESC LIMIT 3"); $stmt->execute([$user_id]); $recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT v.* FROM videos v JOIN video_watches vw ON v.id = vw.video_id WHERE vw.user_id = ? ORDER BY vw.watched_at DESC LIMIT 3"); $stmt->execute([$user_id]); $recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT bp.* FROM blog_posts bp JOIN blog_reads br ON bp.id = br.blog_post_id WHERE br.user_id = ? ORDER BY br.read_at DESC LIMIT 3"); $stmt->execute([$user_id]); $recent_blog = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// ============================================================
// 5. FETCH NOTIFICATIONS & UPCOMING SESSIONS
// ============================================================
try { $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"); $stmt->execute([$user_id]); $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"); $stmt->execute([$user_id]); $unread_count = $stmt->fetchColumn(); } catch (Exception $e) {}
try { $stmt = $db->prepare("SELECT * FROM sessions WHERE user_id = ? AND date >= date('now') ORDER BY date ASC, time ASC LIMIT 5"); $stmt->execute([$user_id]); $upcoming_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

// ============================================================
// 6. HANDLE MARK ALL NOTIFICATIONS AS READ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) { die('Invalid CSRF token.'); }
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'My Dashboard';
?>
<?php require_once 'includes/header.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #f0dad9;
    --bg: #F7F3ED; --card-bg: #ffffff; --border: #e5d5d5;
    --shadow: 0 4px 20px rgba(0,0,0,0.04);
}
body { background: var(--bg); font-family: 'Inter', sans-serif; transition: background 0.3s, color 0.3s; color: #333; }
body.dark-mode { --bg: #1a1212; --card-bg: #2c1e1e; --border: #4a3a3a; color: #e0d0d0; }
body.dark-mode .user-module-btn { background: #3a2a2a; color: #e0d0d0; }

/* User Hero */
.user-hero { background: linear-gradient(135deg, #fff, var(--rose-light)); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; border: 1px solid var(--rose-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
.user-hero h1 { font-family: 'Playfair Display', serif; font-size: 2.2rem; margin:0; letter-spacing: -0.5px; }
.user-hero .sub { color: #666; margin-top: 4px; font-size: 0.95rem; }

/* Core Stats */
.user-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 28px; }
.user-stat-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); text-align: center; transition: 0.2s; }
.user-stat-card:hover { transform: translateY(-4px); border-color: var(--rose); }
.user-stat-card .num { font-size: 2.2rem; font-weight: 700; color: var(--rose); display: block; }
.user-stat-card .label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #666; margin-top: 6px; }

/* Alerts Row */
.user-alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.user-alert-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.user-alert-card h4 { font-size: 1rem; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
.user-alert-list { display: flex; flex-direction: column; gap: 10px; }
.user-alert-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.user-alert-item:last-child { border-bottom: none; }

/* Charts */
.chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
.chart-container { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); height: 260px; position: relative; }
@media (max-width: 768px) { .chart-row { grid-template-columns: 1fr; } .user-alert-row { grid-template-columns: 1fr; } }

/* User Monitoring Stats */
.user-monitoring-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 28px; }
.user-monitor-card { background: var(--card-bg); border-radius: 12px; padding: 14px; border: 1px solid var(--border); }
.user-monitor-card .title { font-size: 0.75rem; color: #888; text-transform: uppercase; }
.user-monitor-card .value { font-size: 1.6rem; font-weight: 700; color: var(--rose); margin: 4px 0; }
.user-monitor-card .sub { font-size: 0.75rem; color: #666; }

/* Recent Content */
.user-content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
.user-content-card { background: var(--card-bg); border-radius: 16px; padding: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.user-content-card h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }

/* User Quick Actions */
.user-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px; }
.user-module { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.user-module h3 { font-family: 'Playfair Display', serif; color: var(--rose-dark); font-size: 1.1rem; margin: 0 0 16px 0; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
.user-module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
.user-module-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px 8px; background: var(--bg); border-radius: 12px; border: 1px solid var(--border); text-decoration: none; color: #333; transition: 0.2s; text-align: center; }
.user-module-btn:hover { transform: translateY(-3px); border-color: var(--rose); box-shadow: 0 4px 12px rgba(219,161,162,0.2); }
.user-module-btn i { font-size: 1.4rem; color: var(--rose); margin-bottom: 6px; }
.user-module-btn span { font-size: 0.75rem; font-weight: 500; }

/* Modal */
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
    <!-- User Hero -->
    <div class="user-hero">
        <div>
            <h1>Welcome back, <span style="color:var(--rose);"><?php echo htmlspecialchars($user['name']); ?></span></h1>
            <div class="sub">Your personal dashboard – track your reading journey.</div>
            <div style="margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap;">
                <span style="background: #f1c40f; color: #333; padding: 2px 12px; border-radius: 20px; font-size:0.75rem; font-weight:600;">🔥 <?php echo $stats['current_streak']; ?> Day Streak</span>
                <span style="background: var(--bg); color: #333; padding: 2px 12px; border-radius: 20px; font-size:0.75rem; font-weight:600;">🏆 Level <?php echo $stats['level']; ?> (<?php echo $stats['points']; ?> pts)</span>
            </div>
        </div>
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <a href="notifications.php" class="btn btn-outline btn-sm" style="position:relative; border-radius:30px; text-decoration:none;">
                <i class="fas fa-bell"></i> Alerts
                <?php if ($unread_count > 0): ?>
                    <span style="position:absolute; top:-8px; right:-8px; background:#e74c3c; color:white; border-radius:50%; padding:2px 8px; font-size:0.65rem; font-weight:700;"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
            <button onclick="openQuickModal()" class="btn btn-primary btn-sm"><i class="fas fa-bolt"></i> Quick Actions</button>
        </div>
    </div>

    <!-- Core Stats -->
    <div class="user-stats-grid">
        <div class="user-stat-card"><span class="num"><?php echo $stats['books_reading'] ?? 0; ?></span><span class="label">Currently Reading</span></div>
        <div class="user-stat-card"><span class="num"><?php echo $stats['books_finished'] ?? 0; ?></span><span class="label">Books Finished</span></div>
        <div class="user-stat-card"><span class="num"><?php echo $stats['poems_read'] ?? 0; ?></span><span class="label">Poems Read</span></div>
        <div class="user-stat-card"><span class="num"><?php echo $stats['videos_watched'] ?? 0; ?></span><span class="label">Videos Watched</span></div>
        <div class="user-stat-card"><span class="num"><?php echo $stats['questions_asked'] ?? 0; ?></span><span class="label">Questions Asked</span></div>
        <div class="user-stat-card"><span class="num"><?php echo $stats['sessions_booked'] ?? 0; ?></span><span class="label">Sessions Booked</span></div>
    </div>

    <!-- Alerts Row (Custom for user) -->
    <div class="user-alert-row">
        <div class="user-alert-card"><h4><i class="fas fa-bell" style="color:var(--rose);"></i> Recent Notifications</h4>
            <div class="user-alert-list"><?php if(count($notifications)>0): foreach($notifications as $n): ?>
                <div class="user-alert-item" style="<?php echo $n['is_read'] ? '' : 'background:rgba(219,161,162,0.05); border-left:3px solid var(--rose); padding-left:8px;'; ?>">
                    <span><strong><?php echo htmlspecialchars($n['title']); ?></strong> – <?php echo htmlspecialchars(substr($n['message'],0,30)); ?>...</span>
                    <span style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($n['created_at'])); ?></span>
                </div>
            <?php endforeach; else: ?><div class="user-alert-item" style="color:#999;">All caught up!</div><?php endif; ?></div>
            <div style="display:flex; gap:8px; margin-top:8px;">
                <a href="notifications.php" class="btn btn-sm btn-outline">View All</a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <button type="submit" name="mark_all_read" class="btn btn-sm btn-secondary">Mark All Read</button>
                </form>
            </div>
        </div>
        <div class="user-alert-card"><h4><i class="fas fa-calendar-check" style="color:var(--rose);"></i> Upcoming Sessions</h4>
            <div class="user-alert-list"><?php if(count($upcoming_sessions)>0): foreach($upcoming_sessions as $s): ?>
                <div class="user-alert-item"><span><?php echo date('M j, g:i a', strtotime($s['date'].' '.$s['time'])); ?> – <?php echo htmlspecialchars($s['message'] ?: 'Booked Session'); ?></span><span class="badge badge-info"><?php echo ucfirst($s['status']); ?></span></div>
            <?php endforeach; else: ?><div class="user-alert-item" style="color:#999;">No upcoming sessions.</div><?php endif; ?></div>
            <a href="book_session.php" class="btn btn-sm btn-primary" style="margin-top:8px;">Book a Session</a>
        </div>
    </div>

    <!-- Charts -->
    <div class="chart-row">
        <div class="chart-container"><canvas id="activeChart"></canvas></div>
        <div class="chart-container"><canvas id="viewsChart"></canvas></div>
    </div>

    <!-- Personal Monitoring Stats -->
    <div class="user-monitoring-grid">
        <div class="user-monitor-card" style="cursor: pointer;" onclick="openStatsModal('views')">
            <div class="title">My Total Views</div>
            <div class="value" id="my_total_views"><?php echo number_format($stats['my_views'] ?? 0); ?></div>
            <div class="sub">My Poems + Books <span style="font-size:0.7rem; color:#ccc;">(Click to view)</span></div>
        </div>
        <div class="user-monitor-card"><div class="title">Read Today</div><div class="value" id="my_today"><?php echo $stats['my_minutes_today'] ?? 0; ?>m</div><div class="sub">Last 24 hours</div></div>
        <div class="user-monitor-card"><div class="title">Read This Week</div><div class="value" id="my_week"><?php echo $stats['my_minutes_week'] ?? 0; ?>m</div><div class="sub">Last 7 days</div></div>
        <div class="user-monitor-card"><div class="title">Read This Month</div><div class="value" id="my_month"><?php echo $stats['my_minutes_month'] ?? 0; ?>m</div><div class="sub">Last 30 days</div></div>
        <div class="user-monitor-card"><div class="title">Read This Year</div><div class="value" id="my_year"><?php echo $stats['my_minutes_year'] ?? 0; ?>m</div><div class="sub">Last 365 days</div></div>
        <div class="user-monitor-card" style="cursor: pointer;" onclick="openStatsModal('reading')">
            <div class="title">My Reading Hours</div>
            <div class="value" id="my_hours"><?php echo number_format($stats['my_reading_hours'] ?? 0); ?></div>
            <div class="sub">Lifetime <span style="font-size:0.7rem; color:#ccc;">(Click to view)</span></div>
        </div>
    </div>

    <!-- My Recent Content -->
    <div style="margin-bottom: 20px;">
        <h3 style="font-family:'Playfair Display'; margin-bottom:12px;">📚 My Recent Activity</h3>
        <div class="user-content-grid">
            <div class="user-content-card"><h4>Currently Reading</h4><?php foreach($reading_books as $b): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($b['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo $b['progress'] ?? 0; ?>% complete</div></div></div><?php endforeach; ?></div>
            <div class="user-content-card"><h4>Recent Poems</h4><?php foreach($recent_poems as $p): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($p['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($p['created_at'])); ?></div></div></div><?php endforeach; ?></div>
            <div class="user-content-card"><h4>Recent Videos</h4><?php foreach($recent_videos as $v): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($v['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($v['created_at'])); ?></div></div></div><?php endforeach; ?></div>
            <div class="user-content-card"><h4>Recent Blog</h4><?php foreach($recent_blog as $b): ?><div style="display:flex; align-items:center; gap:8px; width:100%; border-bottom:1px solid var(--border); padding:4px 0;"><div style="flex:1; text-align:left;"><div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($b['title']); ?></div><div style="font-size:0.7rem; color:#999;"><?php echo date('M j', strtotime($b['created_at'])); ?></div></div></div><?php endforeach; ?></div>
        </div>
    </div>

    <!-- Quick Actions Modules (UPDATED TO INCLUDE READER FILES) -->
    <div class="user-actions-grid">
        <div class="user-module"><h3>📖 My Library</h3><div class="user-module-grid">
            <a href="books.php" class="user-module-btn"><i class="fas fa-book"></i><span>Browse Books</span></a>
            <a href="reader/reader.php" class="user-module-btn"><i class="fas fa-book-open"></i><span>Open Reader</span></a>
            <a href="reader/reader_export.php" class="user-module-btn"><i class="fas fa-file-export"></i><span>Export Data</span></a>
        </div></div>
        
        <div class="user-module"><h3>✍️ Poetry & Blogs</h3><div class="user-module-grid">
            <a href="poetry.php" class="user-module-btn"><i class="fas fa-feather-alt"></i><span>Read Poems</span></a>
            <a href="blog.php" class="user-module-btn"><i class="fas fa-blog"></i><span>Read Blog</span></a>
            <a href="reflections.php" class="user-module-btn"><i class="fas fa-pray"></i><span>Reflections</span></a>
        </div></div>
        
        <div class="user-module"><h3>👥 Community</h3><div class="user-module-grid">
            <a href="reader/reader_circles.php" class="user-module-btn"><i class="fas fa-circle"></i><span>Reading Circles</span></a>
            <a href="groups.php" class="user-module-btn"><i class="fas fa-users-cog"></i><span>Reading Groups</span></a>
            <a href="community.php" class="user-module-btn"><i class="fas fa-comments"></i><span>Community Q&A</span></a>
        </div></div>
        
        <div class="user-module"><h3>🎥 Videos</h3><div class="user-module-grid">
            <a href="videos.php" class="user-module-btn"><i class="fas fa-video"></i><span>Watch Videos</span></a>
        </div></div>

        <div class="user-module"><h3>📊 Reader Tools</h3><div class="user-module-grid">
            <a href="reader/reader_analytics.php" class="user-module-btn"><i class="fas fa-chart-line"></i><span>My Analytics</span></a>
            <a href="reader/reader_challenges.php" class="user-module-btn"><i class="fas fa-trophy"></i><span>Challenges</span></a>
            <a href="reader/reader_notes.php" class="user-module-btn"><i class="fas fa-sticky-note"></i><span>My Notes</span></a>
            <a href="reader/reader_tts.php" class="user-module-btn"><i class="fas fa-volume-up"></i><span>Text to Speech</span></a>
            <a href="reader/reader_gamification.php" class="user-module-btn"><i class="fas fa-gamepad"></i><span>Gamification</span></a>
        </div></div>
        
        <div class="user-module"><h3>⚙️ My Account</h3><div class="user-module-grid">
            <a href="profile.php" class="user-module-btn"><i class="fas fa-user"></i><span>My Profile</span></a>
            <a href="achievements.php" class="user-module-btn"><i class="fas fa-trophy"></i><span>Achievements</span></a>
        </div></div>
    </div>

    <!-- Quick Actions Modal -->
    <div class="modal-overlay" id="quickModal">
        <div class="modal-box">
            <h2>Quick Actions</h2>
            <p style="color:#666; margin-bottom: 16px;">Select what you want to do.</p>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="books.php" class="btn btn-primary">📖 Browse Books</a>
                <a href="poetry.php" class="btn btn-primary">✍️ Read a Poem</a>
                <a href="groups.php" class="btn btn-primary">👥 Join a Group</a>
                <a href="profile.php" class="btn btn-primary">⚙️ Update Profile</a>
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
                <p style="color:#999; text-align:center;">Loading your personal data...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Dark mode will be triggered by global header
if (localStorage.getItem('userDarkMode') === '1') { document.body.classList.add('dark-mode'); }
if (localStorage.getItem('adminDarkMode') === '1') { document.body.classList.add('dark-mode'); }

// ===== Quick Modal Functions =====
function openQuickModal() { document.getElementById('quickModal').classList.add('active'); }
function closeQuickModal() { document.getElementById('quickModal').classList.remove('active'); }
document.getElementById('quickModal').addEventListener('click', function(e) { if(e.target===this) closeQuickModal(); });

// ===== Live Personal Stats Polling =====
function refreshStats() {
    fetch('<?php echo SITE_URL; ?>/ajax_user.php?action=stats')
        .then(res => res.json()).then(data => {
            document.getElementById('my_total_views').textContent = data.my_total_views ?? 0;
            document.getElementById('my_today').textContent = (data.my_minutes_today ?? 0) + 'm';
            document.getElementById('my_week').textContent = (data.my_minutes_week ?? 0) + 'm';
            document.getElementById('my_month').textContent = (data.my_minutes_month ?? 0) + 'm';
            document.getElementById('my_year').textContent = (data.my_minutes_year ?? 0) + 'm';
            document.getElementById('my_hours').textContent = data.my_reading_hours ?? 0;
        }).catch(console.error);
}
setInterval(refreshStats, 60000);

// ============================================================
// SMOOTH & SMART CHARTS
// ============================================================
function initCharts() {
    const gradientActive = document.getElementById('activeChart').getContext('2d').createLinearGradient(0,0,0,250);
    const isDark = document.body.classList.contains('dark-mode');
    gradientActive.addColorStop(0, '#DBA1A2'); gradientActive.addColorStop(1, isDark ? '#2c1e1e' : '#f0dad9');

    window.activeChart = new Chart(document.getElementById('activeChart'), {
        type: 'line', 
        data: { labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], datasets: [{ label: 'My Reading Sessions', data: [0,0,0,0,0,0,0], borderColor: '#DBA1A2', backgroundColor: gradientActive, fill: true, tension: 0.4, borderWidth: 3, pointBackgroundColor: '#DBA1A2', pointRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, animation: { duration: 1500, easing: 'easeOutQuart' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' } }, x: { grid: { display: false } } } }
    });
    window.viewsChart = new Chart(document.getElementById('viewsChart'), {
        type: 'bar', 
        data: { labels: ['Poems','Books','Blog','Videos'], datasets: [{ label: 'Content Read (7 days)', data: [0,0,0,0], backgroundColor: ['#DBA1A2','#c08a8b','#e8c0c0','#EFD8D6'], borderRadius: 6, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, animation: { duration: 1500, easing: 'easeOutQuart' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)' } }, x: { grid: { display: false } } } }
    });
    updateCharts();
    setInterval(updateCharts, 60000);
}

function updateCharts() {
    fetch('<?php echo SITE_URL; ?>/ajax_user.php?action=active_chart')
        .then(res => res.json()).then(data => { window.activeChart.data.labels = data.labels; window.activeChart.data.datasets[0].data = data.data; window.activeChart.update(); }).catch(console.error);
    fetch('<?php echo SITE_URL; ?>/ajax_user.php?action=views_chart')
        .then(res => res.json()).then(data => { window.viewsChart.data.datasets[0].data = data.data; window.viewsChart.update(); }).catch(console.error);
}
document.addEventListener('DOMContentLoaded', initCharts);

// ============================================================
// DEEP DIVE MODAL FUNCTIONS (Only displays current user's data)
// ============================================================
function openStatsModal(type) {
    document.getElementById('statsModal').classList.add('active');
    const body = document.getElementById('statsModalBody');
    const title = document.getElementById('statsModalTitle');
    body.innerHTML = '<p style="color:#999; text-align:center;">Loading your history...</p>';

    const url = '<?php echo SITE_URL; ?>/ajax_user.php?action=' + (type === 'views' ? 'get_my_view_details' : 'get_my_reading_details');
    title.textContent = type === 'views' ? '📊 My Viewed Content' : '📖 My Reading Sessions';

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data.success || data.logs.length === 0) {
                body.innerHTML = '<p style="color:#999; text-align:center;">You haven\'t recorded any activity yet. Start reading!</p>';
                return;
            }
            let html = '<table><thead><tr><th>Content</th><th>Date & Time</th></tr></thead><tbody>';
            data.logs.forEach(row => {
                let titleCol = row.target_type === 'poem' ? htmlspecialchars(row.poem_title) : htmlspecialchars(row.book_title);
                let subTitle = `<span style="color:#999;font-size:0.75rem;display:block;">${row.target_type}</span>`;
                if(type === 'views') {
                    html += `<tr><td><strong>${titleCol}</strong> ${subTitle}</td><td style="color:#666;">${new Date(row.viewed_at).toLocaleString()}</td></tr>`;
                } else {
                    let dur = Math.floor(row.duration_seconds / 60);
                    let sec = row.duration_seconds % 60;
                    html += `<tr><td>${htmlspecialchars(row.book_title || 'Unknown Book')}</td><td style="color:#666;">${new Date(row.start_time).toLocaleString()} <br> <span style="font-size:0.75rem; background:#eee; padding:2px 6px; border-radius:10px;">${dur}m ${sec}s</span></td></tr>`;
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
        if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; if (m === '"') return '&quot;'; return m;
    });
}
</script>
<?php require_once 'includes/footer.php'; ?>