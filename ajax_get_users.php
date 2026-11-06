<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Optional: Rate limiting (reuse the same function as in poem_view)
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

// Apply rate limiting (10 requests per minute)
rate_limit('get_users', 10, 60);

// Optional: Only allow logged‑in users to fetch user list
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// Get search query (if any)
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Prepare and execute query with safe LIKE pattern
// F126: self-registered members have a NULL name, so "name != '' AND name LIKE ?" skipped all of
// them. Match name, first/last name or username, and return the display name used everywhere
// else (awDisplayName, F100). The page filters this list as the member types.
$like = $q . '%';
$stmt = $db->prepare("SELECT id, name, first_name, last_name, username FROM users
                      WHERE name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR username LIKE ?
                      ORDER BY id LIMIT 50");
$stmt->execute([$like, $like, $like, $like]);
$users = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $users[] = ['id' => (int)$u['id'], 'name' => awDisplayName($u)];
}
usort($users, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Send JSON response
header('Content-Type: application/json');
echo json_encode($users);