<?php
// F234: the live search box on reflections.php fetched this file, but it did not exist, so the
// search box never showed results. Returns up to 8 published Christian Reflections whose title,
// excerpt or tags match the query, as [{title, slug}]. The page inserts the title as HTML, so it is
// escaped here.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo '[]';
    exit;
}
$q = mb_substr($q, 0, 100);
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$stmt = $db->prepare("
    SELECT title, slug FROM blog_posts
    WHERE status = 'published' AND category = 'Christian Reflections'
      AND (title LIKE :q ESCAPE '\\' OR excerpt LIKE :q ESCAPE '\\' OR tags LIKE :q ESCAPE '\\')
    ORDER BY created_at DESC
    LIMIT 8
");
$stmt->execute([':q' => $like]);

$out = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $out[] = [
        'title' => htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8'),
        'slug'  => (string)$row['slug'],
    ];
}
echo json_encode($out);
