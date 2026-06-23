<?php
// serve_og.php - The Proxy that bypasses InfinityFree's static-file bot block
header('Cache-Control: no-cache, must-revalidate');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$static_og_file = 'assets/uploads/poems/og_' . $id . '.png';
$static_og_full_path = __DIR__ . '/' . $static_og_file;

// If the static file exists and is valid, serve it directly
if (file_exists($static_og_full_path) && filesize($static_og_full_path) > 100) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    readfile($static_og_full_path);
    exit;
}

// If the static file is missing/corrupt, fallback to the raw original image
$raw_image = 'assets/uploads/poems/poem_1782047795_6a37e433f3f42.png'; // Replace with dynamic fetch if needed, but readfile works locally
// For safety, let's just show the logo fallback
header('Content-Type: image/png');
readfile(__DIR__ . '/assets/images/angelwrites-logo.png');
exit;
?>