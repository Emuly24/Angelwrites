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

rate_limit('get_reactions', 10, 60);

$target_type = $_GET['target_type'] ?? '';
$target_id = (int)$_GET['target_id'] ?? 0;
if (!$target_type || !$target_id) { exit('[]'); }

$reactions = ['like','love','laugh','wow','sad','angry'];
$result = [];

foreach ($reactions as $r) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=?");
    $stmt->execute([$target_type, $target_id, $r]);
    $count = $stmt->fetchColumn();
    $active = false;
    if (isLoggedIn()) {
        $stmt = $db->prepare("SELECT 1 FROM reactions WHERE target_type=? AND target_id=? AND reaction_type=? AND user_id=?");
        $stmt->execute([$target_type, $target_id, $r, $_SESSION['user_id']]);
        $active = (bool)$stmt->fetchColumn();
    }
    $result[$r] = ['count' => $count, 'active' => $active];
}
header('Content-Type: application/json');
echo json_encode($result);