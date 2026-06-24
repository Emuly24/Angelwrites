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
// 2. CHARTS (active readers and content views)
// ============================================================
if ($action === 'active_chart') {
    $labels = [];
    $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('D', strtotime($date));
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE date(start_time) = ?");
        $stmt->execute([$date]);
        $data[] = (int)$stmt->fetchColumn() ?? 0;
    }
    header('Content-Type: application/json');
    echo json_encode(['labels' => $labels, 'data' => $data]);
    exit;
}

if ($action === 'views_chart') {
    // Poems
    $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $poem_views = (int)$stmt->fetchColumn() ?? 0;
    // Books
    $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $book_views = (int)$stmt->fetchColumn() ?? 0;
    // Blog posts (comment count as proxy)
    $stmt = $db->prepare("SELECT COUNT(*) FROM reviews WHERE target_type='blog' AND created_at >= date('now', '-7 days')");
    $stmt->execute();
    $blog_views = (int)$stmt->fetchColumn() ?? 0;
    // Videos
    $stmt = $db->prepare("SELECT SUM(view_count) FROM videos WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $video_views = (int)$stmt->fetchColumn() ?? 0;
    header('Content-Type: application/json');
    echo json_encode([
        'labels' => ['Poems', 'Books', 'Blog', 'Videos'],
        'data' => [$poem_views, $book_views, $blog_views, $video_views]
    ]);
    exit;
}

// ============================================================
// 3. ACTIVITY FEED (HTML)
// ============================================================
if ($action === 'activity') {
    $stmt = $db->prepare("
        SELECT u.name, u.profile_pic, 'comment' as type, r.comment as text, r.created_at 
        FROM reviews r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.deleted_at IS NULL 
        ORDER BY r.created_at DESC LIMIT 5
    ");
    $stmt->execute();
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($recent) > 0) {
        foreach ($recent as $act) {
            echo '<div class="activity-item">';
            echo '<div class="activity-avatar">' . strtoupper(substr($act['name'], 0, 1)) . '</div>';
            echo '<div class="activity-text"><strong>' . htmlspecialchars($act['name']) . '</strong> ' . htmlspecialchars(substr($act['text'], 0, 60)) . '...</div>';
            echo '<div class="activity-time">' . date('M j, g:i a', strtotime($act['created_at'])) . '</div>';
            echo '</div>';
        }
    } else {
        echo '<div class="activity-item" style="color:#999;">No recent activity.</div>';
    }
    exit;
}

// If no action specified, return error
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid action']);