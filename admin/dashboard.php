<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ============================================================
// 1. HELPER FUNCTIONS (CSRF, WebP, Rate Limit, Image Resize)
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
if (!function_exists('rate_limit')) {
    function rate_limit($key, $limit = 10, $window = 60) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $cache_key = 'rate_limit_' . md5($ip . '_' . $key);
        $file = sys_get_temp_dir() . '/' . $cache_key . '.txt';
        $current = time();
        if (file_exists($file)) {
            $data = file_get_contents($file);
            list($timestamp, $count) = explode('|', $data);
            if ($current - $timestamp < $window) {
                if ($count >= $limit) {
                    http_response_code(429);
                    exit('Rate limit exceeded. Try again later.');
                }
                $count++;
            } else {
                $timestamp = $current;
                $count = 1;
            }
        } else {
            $timestamp = $current;
            $count = 1;
        }
        file_put_contents($file, "$timestamp|$count");
    }
}
if (!function_exists('resize_image')) {
    function resize_image($source_path, $dest_path, $width, $height) {
        $info = getimagesize($source_path);
        list($src_w, $src_h) = $info;
        $type = $info[2];
        $src = null;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($source_path); break;
            case IMAGETYPE_PNG: $src = imagecreatefrompng($source_path); break;
            case IMAGETYPE_GIF: $src = imagecreatefromgif($source_path); break;
            default: return false;
        }
        $dst = imagecreatetruecolor($width, $height);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $src_w, $src_h);
        $ext = pathinfo($dest_path, PATHINFO_EXTENSION);
        if ($ext === 'png') {
            imagepng($dst, $dest_path, 9);
        } else {
            imagejpeg($dst, $dest_path, 85);
        }
        imagedestroy($src);
        imagedestroy($dst);
        return true;
    }
}

// ============================================================
// 2. FETCH FULL STATISTICS
// ============================================================
$stats = [];
$stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['total_users'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['total_books'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['total_poems'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['total_sessions'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['total_posts'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM questions"); $stats['total_questions'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1"); $stats['total_subscribers'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts WHERE category = 'Christian Reflections'"); $stats['total_reflections'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['total_videos'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(*) FROM reading_groups"); $stats['total_groups'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions"); $stats['total_reading_hours'] = floor(($stmt->fetchColumn() ?? 0) / 3600);
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_readers_7days'] = $stmt->fetchColumn();
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_readers_30days'] = $stmt->fetchColumn();

// Book completion rates
$stmt = $db->query("
    SELECT b.title, COUNT(DISTINCT rp.user_id) as readers,
           SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) as completions,
           ROUND(100.0 * SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) / COUNT(DISTINCT rp.user_id), 1) as completion_rate
    FROM reading_progress rp
    JOIN books b ON rp.book_id = b.id
    GROUP BY rp.book_id
    ORDER BY completion_rate DESC LIMIT 5
");
$stats['book_completion_rates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Drop-off points
$stmt = $db->query("
    SELECT rp.position_section as chapter, COUNT(*) as drop_offs
    FROM reading_progress rp
    WHERE rp.progress_percent < 100
    GROUP BY rp.position_section
    ORDER BY drop_offs DESC LIMIT 5
");
$stats['drop_off_points'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Most active readers
$stmt = $db->query("
    SELECT u.name, u.email, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    GROUP BY rs.user_id
    ORDER BY total_time DESC LIMIT 5
");
$stats['most_active_readers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 3. FETCH RECENT ITEMS (All recent content)
// ============================================================
// Keyset pagination for books
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$prev_id = isset($_GET['prev_id']) ? (int)$_GET['prev_id'] : 0;
$limit = 4;
$stmt = $db->prepare("SELECT * FROM books WHERE id > ? ORDER BY id LIMIT ?");
$stmt->execute([$last_id, $limit]);
$recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
$next_last_id = !empty($recent_books) ? end($recent_books)['id'] : 0;
$first_id = !empty($recent_books) ? $recent_books[0]['id'] : 0;

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

// ============================================================
// 4. FETCH LATEST 5 COMMENTS FOR THE DASHBOARD PREVIEW
// ============================================================
$stmt = $db->prepare("
    SELECT r.*, u.name AS author_name, p.title AS poem_title
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN poems p ON r.target_type = 'poem' AND r.target_id = p.id
    WHERE r.deleted_at IS NULL
    ORDER BY r.created_at DESC LIMIT 5
");
$stmt->execute();
$latest_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 5. PAGE OUTPUT
// ============================================================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'stats';
$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<!-- ===== Chart.js CDN ===== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ===== FULL DASHBOARD CSS ===== */
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #fff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --bg: #F7F3ED; --card-bg: #fff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.06);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.10);
    --shadow-lg: 0 16px 48px rgba(44,30,30,0.10);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}
body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; transition: background 0.3s, color 0.3s; }
body.dark-mode { --bg: #1a1212; --card-bg: #2c1e1e; --border: #4a3a3a; --vanilla: #2c1e1e; --fantasy: #2c1e1e; }
.dashboard-page { padding: 32px 0 60px; }

/* --- Buttons --- */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; border-radius: 50px; font-weight: 700; font-size: 0.95rem; border: none; cursor: pointer; text-decoration: none; transition: all var(--transition); box-shadow: 0 2px 8px rgba(44,30,30,0.06); }
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.btn-primary { background: var(--rose); color: var(--white); border: 2px solid var(--rose); }
.btn-primary:hover { background: var(--rose-dark); border-color: var(--rose-dark); }
.btn-secondary { background: var(--vanilla); color: var(--dark); border: 2px solid var(--vanilla); }
.btn-secondary:hover { background: var(--rose-light); border-color: var(--rose-light); }
.btn-outline { background: transparent; color: var(--rose); border: 2px solid var(--rose); }
.btn-outline:hover { background: var(--rose); color: var(--white); }
.btn-sm { padding: 8px 20px; font-size: 0.85rem; }
.btn-danger { background: #dc3545; color: #fff; border: 2px solid #dc3545; }
.btn-danger:hover { background: #c82333; border-color: #c82333; }
.btn-success { background: #28a745; color: #fff; border: 2px solid #28a745; }
.btn-success:hover { background: #218838; border-color: #218838; }

/* --- Hero --- */
.dashboard-hero { background: linear-gradient(135deg, var(--vanilla), var(--fantasy)); border-radius: 20px; padding: 24px 32px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; border: 1px solid var(--rose-light); box-shadow: var(--shadow); }
.hero-content { flex: 1; min-width: 250px; }
.hero-content h1 { font-size: 2.4rem; margin: 0 0 4px 0; color: var(--text); }
.hero-content .hero-sub { color: var(--text-light); font-size: 1.05rem; margin: 0 0 12px 0; }
.hero-stats { display: flex; gap: 12px; flex-wrap: wrap; }
.hero-stat { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-light); background: var(--card-bg); padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); }
.hero-stat i { color: var(--rose); }
.hero-stat strong { color: var(--text); }
.hero-profile { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
.profile-pic-large { width: 80px; height: 80px; border-radius: 50%; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; border: 3px solid var(--rose-light); }
.profile-pic-large i { font-size: 3.5rem; color: var(--rose); }
.profile-details h3 { font-size: 1.2rem; margin: 0; }
.profile-details .user-email { color: var(--text-light); font-size: 0.9rem; margin: 0; }
.badge-container { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
.badge { background: var(--rose); color: white; padding: 0 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }

/* --- Stats Row (Live) --- */
.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px; }
.stat-card { background: var(--card-bg); border-radius: 10px; padding: 10px 12px; display: flex; align-items: center; gap: 8px; border: 1px solid var(--border); box-shadow: var(--shadow); position: relative; overflow: hidden; min-height: 55px; transition: all var(--transition); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 10px 10px 0 0; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.stat-users::before { background: var(--rose); }
.stat-books::before { background: var(--rose-dark); }
.stat-poems::before { background: var(--rose-light); }
.stat-sessions::before { background: var(--vanilla); }
.stat-posts::before { background: var(--rose); }
.stat-questions::before { background: var(--rose-dark); }
.stat-subscribers::before { background: var(--rose-light); }
.stat-reflections::before { background: var(--fantasy); }
.stat-videos::before { background: var(--vanilla); }
.stat-groups::before { background: var(--rose); }
.stat-hours::before { background: var(--rose-dark); }
.stat-active7::before { background: var(--rose-light); }
.stat-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
.stat-number { font-size: 1.1rem; font-weight: 700; color: var(--text); line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-label { font-size: 0.5rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600; }

/* --- Tabs --- */
.tab-nav { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 2px solid var(--border); padding-bottom: 8px; }
.tab-btn { padding: 8px 20px; border-radius: 20px; background: transparent; border: 1px solid var(--border); cursor: pointer; font-weight: 600; color: var(--text-light); transition: all 0.2s; }
.tab-btn.active { background: var(--rose); color: white; border-color: var(--rose); }
.tab-btn:hover:not(.active) { border-color: var(--rose); color: var(--rose); }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* --- Dashboard Grid (for stats tab) --- */
.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }
.main-content { display: flex; flex-direction: column; gap: 32px; }
.alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.recent-content-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.dashboard-section { background: var(--card-bg); border-radius: 16px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all var(--transition); }
.dashboard-section:hover { box-shadow: var(--shadow-hover); }
.section-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.section-header h2 { font-size: 1.2rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.section-header h2 .section-icon { color: var(--rose); }

/* --- Mini Grids --- */
.mini-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
.book-card, .poem-card, .blog-card, .reflection-card, .video-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.book-card:hover, .poem-card:hover, .blog-card:hover, .reflection-card:hover, .video-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.book-cover-wrapper, .poem-thumbnail, .video-thumb { height: 140px; background: var(--vanilla); overflow: hidden; }
.book-cover-wrapper img, .poem-thumbnail img, .video-thumb img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-cover, .poem-thumbnail-placeholder, .video-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); }
.book-info, .poem-body, .blog-content, .reflection-body, .video-info { padding: 10px; }
.book-info h3, .poem-body h3, .blog-content h3, .reflection-body h3, .video-info h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }
.book-author { font-size: 0.75rem; color: var(--text-light); margin: 0; }
.blog-excerpt { font-size: 0.75rem; color: var(--text-light); margin: 0 0 4px; }

/* --- Lists --- */
.session-list, .qa-list { display: flex; flex-direction: column; gap: 8px; }
.session-item, .qa-item { background: var(--bg); padding: 12px; border-radius: 10px; border: 1px solid var(--border); transition: all 0.2s ease; }
.session-item:hover, .qa-item:hover { box-shadow: var(--shadow); border-color: var(--rose-light); }

/* --- Status Badges --- */
.status-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.status-pending { background: #f1c40f; color: #fff; }
.status-unread { background: var(--rose); color: #fff; }
.status-available { background: #2ecc71; color: #fff; }
.status-missing { background: #e74c3c; color: #fff; }

/* --- Sidebar --- */
.dashboard-sidebar { display: flex; flex-direction: column; gap: 32px; }
.sidebar-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s ease; }
.sidebar-card:hover { box-shadow: var(--shadow-hover); }
.sidebar-card .card-header { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.sidebar-card .card-header h4 { font-size: 1rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.sidebar-card .card-header h4 i { color: var(--rose); }
.view-all-link { font-size: 0.8rem; font-weight: 600; color: var(--rose); text-decoration: none; }
.view-all-link:hover { color: var(--rose-dark); text-decoration: underline; }

/* --- Quick Actions --- */
.quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; }
.quick-action-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px 8px; background: var(--bg); border-radius: 10px; border: 1px solid var(--border); text-decoration: none; color: var(--text); transition: all 0.3s; gap: 6px; }
.quick-action-btn:hover { background: var(--vanilla); border-color: var(--rose); transform: translateY(-3px); box-shadow: var(--shadow); }
.quick-action-btn i { font-size: 1.4rem; color: var(--rose); }
.quick-action-btn span { font-size: 0.7rem; text-align: center; line-height: 1.2; font-weight: 500; }

/* --- Achievements --- */
.achievement-list { display: flex; flex-direction: column; gap: 6px; }
.achievement-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border); transition: all 0.2s ease; }
.achievement-item:hover { box-shadow: var(--shadow); }
.achievement-icon { font-size: 1.2rem; }
.achievement-name { font-weight: 500; font-size: 0.85rem; flex: 1; color: var(--text); }
.achievement-date { font-size: 0.7rem; color: var(--text-light); }
.no-items { text-align: center; color: var(--text-light); font-size: 0.9rem; padding: 8px 0; }

/* --- Pagination (Keyset) --- */
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: #fff; border-color: var(--rose); }

/* --- Chart Container --- */
.chart-container { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); margin-bottom: 20px; height: 250px; }

/* --- Modal --- */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 9999; display: none; justify-content: center; align-items: center; }
.modal-overlay.active { display: flex; }
.modal-box { background: var(--card-bg); border-radius: 20px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); border: 1px solid var(--rose-light); }
.modal-box h2 { font-family: 'Playfair Display', serif; margin-top: 0; color: var(--rose-dark); }
.modal-box .btn { width: 100%; justify-content: center; margin-top: 8px; }

/* --- Activity Feed --- */
.activity-feed { max-height: 300px; overflow-y: auto; }
.activity-item { padding: 10px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.activity-item:last-child { border-bottom: none; }
.activity-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--vanilla); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--rose); }
.activity-text { font-size: 0.9rem; }
.activity-time { font-size: 0.7rem; color: #999; margin-left: auto; white-space: nowrap; }

/* --- Responsive --- */
@media (max-width: 1024px) {
    .dashboard-grid { grid-template-columns: 1fr; }
    .recent-content-grid { grid-template-columns: 1fr 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .dashboard-hero { flex-direction: column; text-align: center; }
    .hero-profile { flex-direction: column; text-align: center; }
    .stats-row { grid-template-columns: 1fr 1fr; }
    .alert-row { grid-template-columns: 1fr; }
    .recent-content-grid { grid-template-columns: 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .stat-card { padding: 6px 8px; }
    .stat-number { font-size: 0.9rem; }
}
</style>

<div class="dashboard-page">
    <div class="container">

        <!-- ===== HERO ===== -->
        <div class="dashboard-hero">
            <div class="hero-content">
                <h1>Welcome back, <span class="rose-text"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>!</h1>
                <p class="hero-sub">Your command center – here's what's happening across your site.</p>
                <div class="hero-stats">
                    <div class="hero-stat"><i class="fas fa-users"></i><strong><?php echo $stats['total_users']; ?></strong> Users</div>
                    <div class="hero-stat"><i class="fas fa-book-open"></i><strong><?php echo $stats['total_books']; ?></strong> Books</div>
                    <div class="hero-stat"><i class="fas fa-calendar-check"></i><strong><?php echo $stats['total_sessions']; ?></strong> Sessions</div>
                </div>
            </div>
            <div class="hero-profile">
                <div class="profile-pic-large"><i class="fas fa-user-shield"></i></div>
                <div class="profile-details">
                    <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h3>
                    <p class="user-email"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@angelwrites.com'); ?></p>
                    <div class="badge-container"><span class="badge">Admin</span></div>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <button onclick="toggleDarkMode()" class="btn btn-outline btn-sm"><i class="fas fa-moon"></i> Dark</button>
                    <button onclick="openQuickModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Quick Add</button>
                </div>
            </div>
        </div>

        <!-- ===== TAB NAVIGATION ===== -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $tab === 'stats' ? 'active' : ''; ?>" data-tab="stats">Statistics</button>
            <button class="tab-btn <?php echo $tab === 'comments' ? 'active' : ''; ?>" data-tab="comments">Comments Preview</button>
        </div>

        <!-- ===== TAB 1: STATISTICS ===== -->
        <div id="tab-stats" class="tab-content <?php echo $tab === 'stats' ? 'active' : ''; ?>">
            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-card stat-users"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-number" id="stat_users"><?php echo $stats['total_users']; ?></div><div class="stat-label">Total Users</div></div>
                <div class="stat-card stat-books"><div class="stat-icon"><i class="fas fa-book-open"></i></div><div class="stat-number" id="stat_books"><?php echo $stats['total_books']; ?></div><div class="stat-label">Total Books</div></div>
                <div class="stat-card stat-poems"><div class="stat-icon"><i class="fas fa-pen"></i></div><div class="stat-number" id="stat_poems"><?php echo $stats['total_poems']; ?></div><div class="stat-label">Total Poems</div></div>
                <div class="stat-card stat-sessions"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-number" id="stat_sessions"><?php echo $stats['total_sessions']; ?></div><div class="stat-label">Total Sessions</div></div>
                <div class="stat-card stat-posts"><div class="stat-icon"><i class="fas fa-blog"></i></div><div class="stat-number" id="stat_posts"><?php echo $stats['total_posts']; ?></div><div class="stat-label">Blog Posts</div></div>
                <div class="stat-card stat-questions"><div class="stat-icon"><i class="fas fa-question-circle"></i></div><div class="stat-number" id="stat_questions"><?php echo $stats['total_questions']; ?></div><div class="stat-label">Community Q&A</div></div>
                <div class="stat-card stat-subscribers"><div class="stat-icon"><i class="fas fa-envelope"></i></div><div class="stat-number" id="stat_subscribers"><?php echo $stats['total_subscribers']; ?></div><div class="stat-label">Newsletter Subscribers</div></div>
                <div class="stat-card stat-reflections"><div class="stat-icon"><i class="fas fa-church"></i></div><div class="stat-number" id="stat_reflections"><?php echo $stats['total_reflections']; ?></div><div class="stat-label">Reflections</div></div>
                <div class="stat-card stat-videos"><div class="stat-icon"><i class="fas fa-video"></i></div><div class="stat-number" id="stat_videos"><?php echo $stats['total_videos']; ?></div><div class="stat-label">Videos</div></div>
                <div class="stat-card stat-groups"><div class="stat-icon"><i class="fas fa-users-cog"></i></div><div class="stat-number" id="stat_groups"><?php echo $stats['total_groups']; ?></div><div class="stat-label">Reading Groups</div></div>
                <div class="stat-card stat-hours"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-number" id="stat_hours"><?php echo $stats['total_reading_hours']; ?></div><div class="stat-label">Total Reading Hours</div></div>
                <div class="stat-card stat-active7"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div class="stat-number" id="stat_active7"><?php echo $stats['active_readers_7days']; ?></div><div class="stat-label">Active (7 days)</div></div>
            </div>

            <!-- Chart Container -->
            <div class="chart-container">
                <canvas id="growthChart"></canvas>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <div class="main-content">
                    <!-- Pending Sessions & Unread Messages -->
                    <div class="alert-row">
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-clock section-icon"></i> Pending Sessions</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_sessions) > 0): ?>
                                    <div class="session-list compact-list">
                                        <?php foreach ($recent_sessions as $session): ?>
                                            <div class="session-item">
                                                <div class="session-info">
                                                    <div class="session-date"><?php echo date('M j', strtotime($session['date'])); ?></div>
                                                    <div class="session-time"><?php echo date('g:i a', strtotime($session['time'])); ?></div>
                                                    <span class="status-badge status-pending">Pending</span>
                                                    <small> – <?php echo htmlspecialchars($session['user_name']); ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state compact-empty"><i class="fas fa-check-circle"></i> No pending sessions</div>
                                <?php endif; ?>
                            </div>
                        </section>
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-envelope section-icon"></i> Unread Messages</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_messages) > 0): ?>
                                    <div class="session-list compact-list">
                                        <?php foreach ($recent_messages as $message): ?>
                                            <div class="session-item">
                                                <div class="session-info">
                                                    <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                                                    <small><?php echo htmlspecialchars(substr($message['message'], 0, 35)); ?>...</small>
                                                    <span class="status-badge status-unread">Unread</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state compact-empty"><i class="fas fa-inbox"></i> No unread messages</div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <!-- Recent Books (with Keyset pagination) -->
                    <div class="recent-content-grid">
                        <div class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-book section-icon"></i> Books</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_books) > 0): ?>
                                    <div class="book-grid mini-grid">
                                        <?php foreach ($recent_books as $book): ?>
                                            <div class="book-card">
                                                <div class="book-cover-wrapper" style="height:120px;">
                                                    <?php if ($book['cover_path']): ?><img src="<?php echo get_image_url($book['cover_path']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy"><?php else: ?><div class="placeholder-cover"><i class="fas fa-book"></i></div><?php endif; ?>
                                                </div>
                                                <div class="book-info"><h3><?php echo htmlspecialchars($book['title']); ?></h3><p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="pagination mini-pagination">
                                        <?php if ($prev_id > 0): ?><a href="?last_id=<?php echo $prev_id; ?>&prev_id=<?php echo $first_id; ?>" class="page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                                        <span class="page-link">Page ...</span>
                                        <a href="?last_id=<?php echo $next_last_id; ?>&prev_id=<?php echo $last_id; ?>" class="page-link">Next <i class="fas fa-chevron-right"></i></a>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state compact-empty"><i class="fas fa-book-open"></i> No books yet</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Recent Poems -->
                        <div class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-pen section-icon"></i> Poems</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_poems) > 0): ?>
                                    <div class="horizontal-scroll">
                                        <?php foreach ($recent_poems as $poem): ?>
                                            <div class="scroll-item poem-scroll-item">
                                                <div class="poem-thumbnail" style="height:100px; width:100px;">
                                                    <?php if ($poem['image_path']): ?><img src="<?php echo get_image_url($poem['image_path']); ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" loading="lazy"><?php else: ?><div class="poem-thumbnail-placeholder"><i class="fas fa-feather-alt"></i></div><?php endif; ?>
                                                </div>
                                                <div class="scroll-item-body"><h4><?php echo htmlspecialchars($poem['title']); ?></h4></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state compact-empty"><i class="fas fa-feather-alt"></i> No poems yet</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Recent Questions -->
                        <div class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-question-circle section-icon"></i> Questions</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_questions) > 0): ?>
                                    <div class="horizontal-scroll">
                                        <?php foreach ($recent_questions as $question): ?>
                                            <div class="scroll-item question-scroll-item">
                                                <div class="question-content"><h4><?php echo htmlspecialchars($question['title']); ?></h4><p class="question-excerpt"><?php echo htmlspecialchars(substr($question['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $question['is_answered'] ? 'status-available' : 'status-pending'; ?>"><?php echo $question['is_answered'] ? 'Answered' : 'Pending'; ?></span></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><p>No recent questions.</p><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Row (Messages, Blog, etc.) -->
                    <div class="bottom-row">
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-envelope section-icon"></i> Messages</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_messages) > 0): ?>
                                    <div class="horizontal-scroll">
                                        <?php foreach ($recent_messages as $message): ?>
                                            <div class="scroll-item message-scroll-item">
                                                <div class="message-content"><h4><?php echo htmlspecialchars($message['subject']); ?></h4><p class="message-excerpt"><?php echo htmlspecialchars(substr($message['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $message['is_read'] ? 'status-available' : 'status-pending'; ?>"><?php echo $message['is_read'] ? 'Read' : 'Unread'; ?></span></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><p>No recent messages.</p><?php endif; ?>
                            </div>
                        </section>

                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-blog section-icon"></i> Blog</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_posts) > 0): ?>
                                    <div class="horizontal-scroll">
                                        <?php foreach ($recent_posts as $post): ?>
                                            <div class="scroll-item blog-scroll-item">
                                                <div class="blog-content"><h4><?php echo htmlspecialchars($post['title']); ?></h4><p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'] ?? $post['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $post['status'] === 'published' ? 'status-available' : 'status-pending'; ?>"><?php echo ucfirst($post['status']); ?></span></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-blog"></i> No blog posts yet</div><?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <!-- Newest Users, Reading Groups, Questions, Reflections, Videos -->
                    <div class="bottom-row">
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-users section-icon"></i> Newest Users</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_users) > 0): ?>
                                    <div class="user-table">
                                        <?php foreach ($recent_users as $user): ?>
                                            <div class="user-row">
                                                <div class="user-name"><strong><?php echo htmlspecialchars($user['name']); ?></strong> <small><?php echo htmlspecialchars($user['email']); ?></small></div>
                                                <div class="user-role"><span class="status-badge <?php echo $user['role'] === 'admin' ? 'status-unread' : 'status-available'; ?>"><?php echo ucfirst($user['role'] ?? 'User'); ?></span></div>
                                                <?php if ($user['id'] !== $_SESSION['user_id']): ?><a href="<?php echo SITE_URL; ?>/admin/manage_users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user?');"><i class="fas fa-trash"></i></a><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-users"></i> No users yet</div><?php endif; ?>
                            </div>
                        </section>

                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-users-cog section-icon"></i> Reading Groups</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_groups) > 0): ?>
                                    <div class="session-list compact-list">
                                        <?php foreach ($recent_groups as $group): ?>
                                            <div class="session-item">
                                                <div class="session-info"><strong><?php echo htmlspecialchars($group['name']); ?></strong> <small>Created <?php echo date('M j, Y', strtotime($group['created_at'])); ?></small></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-users-cog"></i> No reading groups yet</div><?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <div class="bottom-row">
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-question-circle section-icon"></i> Community Questions</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_questions) > 0): ?>
                                    <div class="session-list compact-list">
                                        <?php foreach ($recent_questions as $question): ?>
                                            <div class="session-item">
                                                <div class="session-info"><strong><?php echo htmlspecialchars($question['title']); ?></strong> <small><?php echo htmlspecialchars(substr($question['content'], 0, 35)); ?>...</small> <span class="status-badge <?php echo $question['is_answered'] ? 'status-available' : 'status-pending'; ?>"><?php echo $question['is_answered'] ? 'Answered' : 'Pending'; ?></span></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-question-circle"></i> No questions yet</div><?php endif; ?>
                            </div>
                        </section>

                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-church section-icon"></i> Christian Reflections</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?category=Christian+Reflections" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_reflections) > 0): ?>
                                    <div class="session-list compact-list">
                                        <?php foreach ($recent_reflections as $reflection): ?>
                                            <div class="session-item">
                                                <div class="session-info"><strong><?php echo htmlspecialchars($reflection['title']); ?></strong> <small><?php echo htmlspecialchars(substr($reflection['excerpt'] ?? $reflection['content'], 0, 35)); ?>...</small> <span class="status-badge <?php echo $reflection['status'] === 'published' ? 'status-available' : 'status-pending'; ?>"><?php echo ucfirst($reflection['status']); ?></span></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-church"></i> No reflections yet</div><?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <div class="bottom-row">
                        <section class="dashboard-section compact-section">
                            <div class="section-header">
                                <h2><i class="fas fa-video section-icon"></i> Videos</h2>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-sm btn-outline">Manage</a>
                            </div>
                            <div class="dashboard-section-body">
                                <?php if (count($recent_videos) > 0): ?>
                                    <div class="horizontal-scroll">
                                        <?php foreach ($recent_videos as $video): ?>
                                            <div class="scroll-item video-scroll-item">
                                                <div class="video-thumb" style="height:80px; width:120px;">
                                                    <?php if ($video['thumbnail']): ?><img src="<?php echo get_image_url($video['thumbnail']); ?>" alt="<?php echo htmlspecialchars($video['title']); ?>" loading="lazy"><?php else: ?><div class="video-thumb-placeholder"><i class="fas fa-video"></i></div><?php endif; ?>
                                                </div>
                                                <div class="scroll-item-body"><h4><?php echo htmlspecialchars($video['title']); ?></h4></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-video"></i> No videos yet</div><?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>

                <!-- ===== SIDEBAR ===== -->
                <div class="dashboard-sidebar">
                    <!-- Quick Actions -->
                    <div class="sidebar-card quick-actions-card">
                        <div class="card-header"><h4><i class="fas fa-bolt" style="color: var(--rose);"></i> Quick Actions</h4></div>
                        <div class="card-body">
                            <div class="quick-actions-grid">
                                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="quick-action-btn"><i class="fas fa-book"></i><span>Books</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="quick-action-btn"><i class="fas fa-pen"></i><span>Poems</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="quick-action-btn"><i class="fas fa-calendar-check"></i><span>Sessions</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="quick-action-btn"><i class="fas fa-users-cog"></i><span>Users</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="quick-action-btn"><i class="fas fa-edit"></i><span>Blog</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="quick-action-btn"><i class="fas fa-video"></i><span>Videos</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="quick-action-btn"><i class="fas fa-cog"></i><span>Settings</span></a>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="quick-action-btn"><i class="fas fa-users"></i><span>Groups</span></a>
                            </div>
                        </div>
                    </div>

                    <!-- Active Readers -->
                    <div class="sidebar-card">
                        <div class="card-header"><h4><i class="fas fa-fire" style="color: var(--rose);"></i> Active Readers</h4></div>
                        <div class="card-body">
                            <?php if (count($stats['most_active_readers']) > 0): ?>
                                <div class="achievement-list">
                                    <?php foreach ($stats['most_active_readers'] as $reader): ?>
                                        <div class="achievement-item"><span class="achievement-icon">🔥</span><span class="achievement-name"><?php echo htmlspecialchars($reader['name']); ?></span><span class="achievement-date"><?php echo $reader['sessions']; ?> sessions</span></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><p class="no-items">No active readers yet.</p><?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Reading Activity -->
                    <div class="sidebar-card">
                        <div class="card-header"><h4><i class="fas fa-book-reader" style="color: var(--rose);"></i> Recent Reading</h4></div>
                        <div class="card-body">
                            <?php if (count($recent_reading_activity) > 0): ?>
                                <div class="achievement-list">
                                    <?php foreach ($recent_reading_activity as $activity): ?>
                                        <div class="achievement-item"><span class="achievement-icon">📖</span><span class="achievement-name"><?php echo htmlspecialchars($activity['user_name']); ?></span><span class="achievement-date"><?php echo htmlspecialchars($activity['book_title']); ?></span><span class="achievement-date"><?php echo $activity['progress_percent']; ?>%</span></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><p class="no-items">No reading activity yet.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== TAB 2: COMMENTS PREVIEW ===== -->
        <div id="tab-comments" class="tab-content <?php echo $tab === 'comments' ? 'active' : ''; ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <h2 style="margin:0;"><i class="fas fa-comments" style="color: var(--rose);"></i> Latest Comments</h2>
                <a href="<?php echo SITE_URL; ?>/admin/comments.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-arrow-right"></i> Manage All Comments
                </a>
            </div>

            <div class="comment-list">
                <?php if (count($latest_comments) > 0): ?>
                    <?php foreach ($latest_comments as $comment): ?>
                        <div class="comment-item" style="padding:12px 16px;">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['author_name']); ?>
                                </span>
                                <span class="comment-meta"><?php echo date('M j, Y g:i a', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <div class="comment-poem" style="font-size:0.85rem; color:var(--text-light); margin-bottom:4px;">
                                On poem: <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $comment['target_id']; ?>">
                                    <?php echo htmlspecialchars($comment['poem_title'] ?? 'Unknown Poem'); ?>
                                </a>
                            </div>
                            <div class="comment-text" style="font-size:0.95rem;">
                                <?php echo nl2br(htmlspecialchars(substr($comment['comment'], 0, 120))); ?>
                                <?php if (strlen($comment['comment']) > 120): ?>...<?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div style="text-align:center; margin-top:12px;">
                        <a href="<?php echo SITE_URL; ?>/admin/comments.php" class="btn btn-outline btn-sm">View Full Comment Management</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comment-slash"></i>
                        <p>No comments yet.</p>
                    </div>
                <?php endif; ?>
            </div>
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
// 4. GROWTH CHART (Chart.js)
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
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>