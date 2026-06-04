<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

echo "Fixing encoding in book_content...<br>";

$stmt = $db->query("SELECT id, content_html FROM book_content");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $id = $row['id'];
    $content = $row['content_html'];
    
    // Convert from ISO-8859-1 (the broken format) back to UTF-8
    $fixed = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    
    // Also try a fallback conversion for extra safety
    $fixed = utf8_encode($content);
    
    $update = $db->prepare("UPDATE book_content SET content_html = ? WHERE id = ?");
    $update->execute([$fixed, $id]);
    
    echo "Fixed ID: $id<br>";
}

echo "✅ Done!";