<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ============================================================
// 1. STATS (for cards)
// ============================================================
if ($action === 'stats') {
    $stats = [];
    $stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['users'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['books'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['poems'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['sessions'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['posts'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['videos'] = $stmt->fetchColumn();
    
    // Monitoring stats
    $stmt = $db->query("SELECT SUM(view_count) FROM poems"); $stats['poem_views'] = $stmt->fetchColumn() ?? 0;
    $stmt = $db->query("SELECT SUM(view_count) FROM books"); $stats['book_views'] = $stmt->fetchColumn() ?? 0;
    $stats['total_views'] = number_format($stats['poem_views'] + $stats['book_views']);
    
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-1 day')"); $stats['active_today'] = $stmt->fetchColumn() ?? 0;
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_week'] = $stmt->fetchColumn() ?? 0;
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_month'] = $stmt->fetchColumn() ?? 0;
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-365 days')"); $stats['active_year'] = $stmt->fetchColumn() ?? 0;
    
    $stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions"); $stats['reading_hours'] = number_format(floor(($stmt->fetchColumn() ?? 0) / 3600));
    
    header('Content-Type: application/json');
    echo json_encode($stats);
    exit;
}

// ============================================================
// 2. CHARTS (Fixed SQLite Date Syntax)
// ============================================================
if ($action === 'active_chart') {
    $labels = []; $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('D', strtotime($date));
        // Changed `date(start_time)` to `DATE(start_time)` for SQLite compatibility
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE DATE(start_time) = ?");
        $stmt->execute([$date]);
        $data[] = (int)$stmt->fetchColumn() ?? 0;
    }
    header('Content-Type: application/json');
    echo json_encode(['labels' => $labels, 'data' => $data]);
    exit;
}

if ($action === 'views_chart') {
    // Fixed `date(created_at)` to `DATE(created_at)` for SQLite compatibility
    $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $poem_views = (int)$stmt->fetchColumn() ?? 0;
    
    $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $book_views = (int)$stmt->fetchColumn() ?? 0;
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM reviews WHERE target_type='blog' AND DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $blog_views = (int)$stmt->fetchColumn() ?? 0;
    
    $stmt = $db->prepare("SELECT SUM(view_count) FROM videos WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $video_views = (int)$stmt->fetchColumn() ?? 0;
    
    header('Content-Type: application/json');
    echo json_encode(['labels' => ['Poems', 'Books', 'Blog', 'Videos'], 'data' => [$poem_views, $book_views, $blog_views, $video_views]]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid action']);