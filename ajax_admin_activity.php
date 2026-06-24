<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

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