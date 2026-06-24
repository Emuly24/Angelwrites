<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// ============================================================
// 1. RATE LIMITING (optional, but recommended)
// ============================================================
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

// Apply rate limiting: 20 requests per minute per IP
rate_limit('user_stats', 20, 60);

// ============================================================
// 2. CHECK LOGIN
// ============================================================
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

// ============================================================
// 3. FETCH STATS (same as dashboard)
// ============================================================

// Books currently reading
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'currently reading'");
$stmt->execute([$user_id]);
$reading = $stmt->fetchColumn();

// Books finished
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'");
$stmt->execute([$user_id]);
$finished = $stmt->fetchColumn();

// Poems read
$stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ?");
$stmt->execute([$user_id]);
$poems = $stmt->fetchColumn();

// Videos watched
$stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ?");
$stmt->execute([$user_id]);
$videos = $stmt->fetchColumn();

// Questions asked
$stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE user_id = ?");
$stmt->execute([$user_id]);
$questions = $stmt->fetchColumn();

// Sessions booked
$stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE user_id = ?");
$stmt->execute([$user_id]);
$sessions = $stmt->fetchColumn();

// ============================================================
// 4. RETURN JSON
// ============================================================
header('Content-Type: application/json');
echo json_encode([
    'reading' => (int)$reading,
    'finished' => (int)$finished,
    'poems' => (int)$poems,
    'videos' => (int)$videos,
    'questions' => (int)$questions,
    'sessions' => (int)$sessions
]);