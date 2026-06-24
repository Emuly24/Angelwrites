<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// ============================================================
// 1. RATE LIMITING (Prevents abuse)
// ============================================================
if (!function_exists('rate_limit')) {
    function rate_limit($key, $limit = 20, $window = 60) {
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

// Apply rate limiting to all actions
rate_limit('user_actions', 20, 60);

// ============================================================
// 2. CHECK LOGIN
// ============================================================
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

header('Content-Type: application/json');

// ============================================================
// 3. ACTION: STATS (User's standard grid stats)
// ============================================================
if ($action === 'stats') {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'currently reading'");
        $stmt->execute([$user_id]); $stats['reading'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'");
        $stmt->execute([$user_id]); $stats['finished'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ?");
        $stmt->execute([$user_id]); $stats['poems'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ?");
        $stmt->execute([$user_id]); $stats['videos'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE user_id = ?");
        $stmt->execute([$user_id]); $stats['questions'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE user_id = ?");
        $stmt->execute([$user_id]); $stats['sessions'] = (int)$stmt->fetchColumn();
        
        // Advanced Monitoring Stats
        $stats['my_total_views'] = 0;
        try {
            $stmt = $db->prepare("SELECT SUM(view_count) FROM poems WHERE id IN (SELECT poem_id FROM poem_reads WHERE user_id = ?)"); 
            $stmt->execute([$user_id]); $poem_views = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("SELECT SUM(view_count) FROM books WHERE id IN (SELECT book_id FROM reading_status WHERE user_id = ?)"); 
            $stmt->execute([$user_id]); $book_views = (int)$stmt->fetchColumn();
            $stats['my_total_views'] = $poem_views + $book_views;
        } catch (Exception $e) { /* Table might not exist */ }

        $stats['my_minutes_today'] = 0; $stats['my_minutes_week'] = 0; $stats['my_minutes_month'] = 0; $stats['my_minutes_year'] = 0; $stats['my_reading_hours'] = 0;
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
        } catch (Exception $e) { /* Reading sessions table might not exist */ }

        echo json_encode($stats);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to fetch stats']);
    }
    exit;
}

// ============================================================
// 4. ACTION: ACTIVE CHART (User's personal reading minutes per day)
// ============================================================
if ($action === 'active_chart') {
    $labels = []; $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('D', strtotime($date));
        try {
            // We measure the seconds read by this user on each specific day
            $stmt = $db->prepare("SELECT SUM(duration_seconds) FROM reading_sessions WHERE user_id = ? AND DATE(start_time) = ?");
            $stmt->execute([$user_id, $date]);
            $seconds = (int)$stmt->fetchColumn();
            $data[] = floor($seconds / 60); // Convert to minutes
        } catch (Exception $e) {
            $data[] = 0;
        }
    }
    echo json_encode(['labels' => $labels, 'data' => $data]);
    exit;
}

// ============================================================
// 5. ACTION: VIEWS CHART (User's content read/watched in 7 days)
// ============================================================
if ($action === 'views_chart') {
    try {
        // Poems read
        $stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ? AND DATE(read_at) >= DATE('now', '-7 days')");
        $stmt->execute([$user_id]); $poems = (int)$stmt->fetchColumn();
        // Books finished/started
        $stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND DATE(updated_at) >= DATE('now', '-7 days')");
        $stmt->execute([$user_id]); $books = (int)$stmt->fetchColumn();
        // Blog posts read
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM blog_reads WHERE user_id = ? AND DATE(read_at) >= DATE('now', '-7 days')");
            $stmt->execute([$user_id]); $blog = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $blog = 0; }
        // Videos watched
        $stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ? AND DATE(watched_at) >= DATE('now', '-7 days')");
        $stmt->execute([$user_id]); $videos = (int)$stmt->fetchColumn();
        echo json_encode(['labels' => ['Poems', 'Books', 'Blog', 'Videos'], 'data' => [$poems, $books, $blog, $videos]]);
    } catch (Exception $e) {
        echo json_encode(['labels' => ['Poems', 'Books', 'Blog', 'Videos'], 'data' => [0,0,0,0]]);
    }
    exit;
}

// ============================================================
// 6. ACTION: GET MY VIEW DETAILS (Deep Dive Modal)
// ============================================================
if ($action === 'get_my_view_details') {
    try {
        // This retrieves logs generated ONLY by this user and their own interaction
        $stmt = $db->prepare("
            SELECT 
                vl.target_type,
                p.title as poem_title,
                b.title as book_title,
                vl.viewed_at
            FROM view_logs vl
            LEFT JOIN poems p ON (vl.target_type = 'poem' AND vl.target_id = p.id)
            LEFT JOIN books b ON (vl.target_type = 'book' AND vl.target_id = b.id)
            WHERE vl.user_id = ?
            ORDER BY vl.viewed_at DESC LIMIT 30
        ");
        $stmt->execute([$user_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'logs' => []]);
    }
    exit;
}

// ============================================================
// 7. ACTION: GET MY READING DETAILS (Deep Dive Modal)
// ============================================================
if ($action === 'get_my_reading_details') {
    try {
        $stmt = $db->prepare("
            SELECT 
                b.title as book_title,
                rs.duration_seconds,
                rs.start_time
            FROM reading_sessions rs
            LEFT JOIN books b ON rs.book_id = b.id
            WHERE rs.user_id = ?
            ORDER BY rs.start_time DESC LIMIT 30
        ");
        $stmt->execute([$user_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'logs' => []]);
    }
    exit;
}

// ============================================================
// 8. ACTION: GET USERS (For Tagging Autocomplete)
// ============================================================
if ($action === 'get_users') {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    try {
        $stmt = $db->prepare("SELECT id, name FROM users WHERE name != '' AND name LIKE ? ORDER BY name LIMIT 15");
        $stmt->execute([$q . '%']);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ============================================================
// 9. CATCH ALL (If invalid action)
// ============================================================
echo json_encode(['error' => 'Invalid action']);