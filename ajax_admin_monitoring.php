<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$type = isset($_GET['type']) ? $_GET['type'] : 'active';

if ($type === 'active') {
    // Active readers for the last 7 days
    $labels = [];
    $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('D', strtotime($date));
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE date(start_time) = ?");
        $stmt->execute([$date]);
        $data[] = (int)$stmt->fetchColumn() ?? 0;
    }
    echo json_encode(['labels' => $labels, 'data' => $data]);
    exit;
}

if ($type === 'views') {
    // Total views for each content type in the last 7 days
    // Poems
    $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $poem_views = (int)$stmt->fetchColumn() ?? 0;

    // Books
    $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $book_views = (int)$stmt->fetchColumn() ?? 0;

    // Blog posts (we need to add view_count to blog_posts if not present; if not, fallback to comment count)
    // We'll use comment count as a proxy if view_count missing.
    $stmt = $db->prepare("SELECT COUNT(*) FROM reviews WHERE target_type='blog' AND created_at >= date('now', '-7 days')");
    $stmt->execute();
    $blog_views = (int)$stmt->fetchColumn() ?? 0;

    // Videos
    $stmt = $db->prepare("SELECT SUM(view_count) FROM videos WHERE date(created_at) >= date('now', '-7 days')");
    $stmt->execute();
    $video_views = (int)$stmt->fetchColumn() ?? 0;

    echo json_encode([
        'labels' => ['Poems', 'Books', 'Blog', 'Videos'],
        'data' => [$poem_views, $book_views, $blog_views, $video_views]
    ]);
    exit;
}