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
// F200: $db was used without loading the connection, so every fallback fatalled.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
$poem_data = $db->prepare("SELECT image_path FROM poems WHERE id = ?");
$poem_data->execute([$id]);
$poem = $poem_data->fetch(PDO::FETCH_ASSOC);

if ($poem && !empty($poem['image_path'])) {
    $raw_path = __DIR__ . '/' . ltrim($poem['image_path'], '/');
    $raw_info = file_exists($raw_path) ? @getimagesize($raw_path) : false;
    if ($raw_info) {
        header('Content-Type: ' . $raw_info['mime']); // F200: poem images are often JPEG
        readfile($raw_path);
        exit;
    }
}

// Ultimate fallback: Logo
// Q43: logo.png is now a small header-sized image; share previews use a 1200x800 JPEG of the logo.
header('Content-Type: image/jpeg');
readfile(__DIR__ . '/assets/images/og-logo.jpg');
exit;