<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ===== 1. STATS =====
if ($action === 'stats') {
    $stats = [];
    $stmt = $db->query("SELECT COUNT(*) FROM users"); $stats['users'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM books"); $stats['books'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM poems"); $stats['poems'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM sessions"); $stats['sessions'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM blog_posts"); $stats['posts'] = $stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(*) FROM videos"); $stats['videos'] = $stmt->fetchColumn();

    $stmt = $db->query("SELECT SUM(view_count) FROM poems"); $stats['poem_views'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT SUM(view_count) FROM books"); $stats['book_views'] = (int)$stmt->fetchColumn();
    $stats['total_views'] = number_format($stats['poem_views'] + $stats['book_views']);

    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-1 day')"); $stats['active_today'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')"); $stats['active_week'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')"); $stats['active_month'] = (int)$stmt->fetchColumn();
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-365 days')"); $stats['active_year'] = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT SUM(duration_seconds) FROM reading_sessions"); 
    $stats['reading_hours'] = number_format(floor(($stmt->fetchColumn() ?? 0) / 3600));

    echo json_encode($stats, JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===== 2. CHARTS =====
if ($action === 'active_chart') {
    $labels = []; $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('D', strtotime($date));
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE DATE(start_time) = ?");
        $stmt->execute([$date]);
        $data[] = (int)$stmt->fetchColumn();
    }
    echo json_encode(['labels' => $labels, 'data' => $data]);
    exit;
}

if ($action === 'views_chart') {
    $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $poem_views = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $book_views = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM reviews WHERE target_type='blog' AND DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $blog_views = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT SUM(view_count) FROM videos WHERE DATE(created_at) >= DATE('now', '-7 days')");
    $stmt->execute(); $video_views = (int)$stmt->fetchColumn();
    echo json_encode(['labels' => ['Poems', 'Books', 'Blog', 'Videos'], 'data' => [$poem_views, $book_views, $blog_views, $video_views]]);
    exit;
}

// ===== 3. DEEP DIVE: View Details =====
if ($action === 'get_view_details') {
    try {
        $stmt = $db->prepare("
            SELECT 
                COALESCE(u.name, 'Guest') as viewer_name,
                vl.target_type,
                COALESCE(p.title, b.title, 'Unknown') as content_title,
                vl.ip_address,
                vl.viewed_at
            FROM view_logs vl
            LEFT JOIN users u ON vl.user_id = u.id
            LEFT JOIN poems p ON (vl.target_type = 'poem' AND vl.target_id = p.id)
            LEFT JOIN books b ON (vl.target_type = 'book' AND vl.target_id = b.id)
            ORDER BY vl.viewed_at DESC LIMIT 50
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'View logs not found.']);
    }
    exit;
}

// ===== 4. DEEP DIVE: Reading Details =====
if ($action === 'get_reading_details') {
    try {
        $stmt = $db->prepare("
            SELECT 
                u.name as user_name,
                u.email as user_email,
                COALESCE(b.title, 'Unknown Book') as book_title,
                rs.duration_seconds,
                rs.start_time,
                rs.end_time
            FROM reading_sessions rs
            JOIN users u ON rs.user_id = u.id
            LEFT JOIN books b ON rs.book_id = b.id
            ORDER BY rs.start_time DESC LIMIT 50
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Session logs failed.']);
    }
    exit;
}

echo json_encode(['error' => 'Invalid action']);