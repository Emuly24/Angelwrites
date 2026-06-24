<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Include the rate_limit helper if it's not in a global file
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

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'You must be logged in to react.']);
    exit;
}

rate_limit('toggle_reaction', 5, 60);

$target_type = $_POST['target_type'] ?? '';
$target_id = (int)$_POST['target_id'] ?? 0;
$reaction = $_POST['reaction'] ?? '';
if (!$target_type || !$target_id || !$reaction) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request parameters.']);
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT id FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?");
$stmt->execute([$target_type, $target_id, $reaction, $user_id]);
if ($stmt->fetchColumn()) {
    $db->prepare("DELETE FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?")
        ->execute([$target_type, $target_id, $reaction, $user_id]);
    $active = false;
} else {
    $db->prepare("INSERT INTO reactions (user_id, target_type, target_id, reaction_type) VALUES (?,?,?,?)")
        ->execute([$user_id, $target_type, $target_id, $reaction]);
    $active = true;
}
$stmt = $db->prepare("SELECT COUNT(*) FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=?");
$stmt->execute([$target_type, $target_id, $reaction]);
$count = $stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode(['active' => $active, 'count' => $count]);