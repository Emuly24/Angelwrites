<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Only logged-in users can mark notifications as read
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($notification_id > 0) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
}

// Redirect back to the dashboard
header('Location: ' . SITE_URL . '/dashboard.php');
exit;