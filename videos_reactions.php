<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Return JSON response
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login to react.']);
    exit;
}

$video_id = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
$reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';
$user_id = $_SESSION['user_id'];

if ($video_id <= 0 || !in_array($reaction, ['like', 'love', 'pray'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request parameters.']);
    exit;
}

// Check if video exists
$stmt = $db->prepare("SELECT id FROM videos WHERE id = ?");
$stmt->execute([$video_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Video not found.']);
    exit;
}

// Check for existing reaction
$stmt = $db->prepare("SELECT id, reaction_type FROM video_reactions WHERE video_id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    if ($existing['reaction_type'] === $reaction) {
        // User clicked the same button -> Remove reaction (Toggle off)
        $stmt = $db->prepare("DELETE FROM video_reactions WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        // User clicked a different reaction -> Update to new reaction
        $stmt = $db->prepare("UPDATE video_reactions SET reaction_type = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$reaction, $existing['id']]);
    }
} else {
    // Insert new reaction
    $stmt = $db->prepare("INSERT INTO video_reactions (video_id, user_id, reaction_type) VALUES (?, ?, ?)");
    $stmt->execute([$video_id, $user_id, $reaction]);
}

// Fetch updated counts
$stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN reaction_type = 'like' THEN 1 END) as likes,
        COUNT(CASE WHEN reaction_type = 'love' THEN 1 END) as loves,
        COUNT(CASE WHEN reaction_type = 'pray' THEN 1 END) as prays
    FROM video_reactions WHERE video_id = ?
");
$stmt->execute([$video_id]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true, 
    'likes' => $counts['likes'] ?? 0, 
    'loves' => $counts['loves'] ?? 0, 
    'prays' => $counts['prays'] ?? 0
]);
exit;
?>