<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$id = (int)$_GET['id'];
$stmt = $db->prepare("SELECT * FROM newsletter_archive WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    die('Archive not found.');
}

echo '<html><head><meta charset="UTF-8"><title>' . htmlspecialchars($item['subject']) . '</title></head>
<body style="font-family:Inter,sans-serif;padding:20px;max-width:600px;margin:0 auto;">';
echo '<h2>' . htmlspecialchars($item['subject']) . '</h2>';
echo '<p style="color:#999;font-size:0.85rem;">Sent: ' . date('F j, Y, g:i a', strtotime($item['sent_at'])) . '</p>';
echo '<hr>';
echo $item['content'];
echo '</body></html>';
exit;