<?php
// serve_og.php - Serves the image via a safe PHP endpoint
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$static_file = 'assets/uploads/poems/og_' . $id . '.png';
$full_path = __DIR__ . '/' . $static_file;

// If the properly generated image exists, serve it
if (file_exists($full_path) && filesize($full_path) > 100) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    readfile($full_path);
    exit;
}

// Fallback: serve the raw poem image
$poem_data = $db->prepare("SELECT image_path FROM poems WHERE id = ?");
$poem_data->execute([$id]);
$poem = $poem_data->fetch(PDO::FETCH_ASSOC);

if ($poem && !empty($poem['image_path'])) {
    $raw_path = __DIR__ . '/' . ltrim($poem['image_path'], '/');
    if (file_exists($raw_path)) {
        header('Content-Type: image/png');
        readfile($raw_path);
        exit;
    }
}

// Ultimate fallback: Logo
header('Content-Type: image/png');
readfile(__DIR__ . '/assets/images/angelwrites-logo.png');
exit;