<?php
// generate_og_image.php (BOT-PROOF & ERROR-PROOF)
ob_start(); // Start output buffering to catch stray PHP errors
error_reporting(0); // Turn off PHP warnings from leaking

// Custom error handler to ensure we always output an image
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Silence the error and return, since we will output a fallback image anyway
    return true;
}
set_error_handler("customErrorHandler");

// SECURITY: Only allow PNG/JPEG/GIF extensions
$src = isset($_GET['src']) ? ltrim($_GET['src'], '/') : '';
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions)) {
    // If someone tries to load a bad file, just show a fallback.
    header_remove();
    header('Content-Type: image/png');
    readfile(__DIR__ . '/assets/images/angelwrites-logo.png');
    exit;
}

$base_dir = __DIR__;
$full_file_path = $base_dir . '/' . $src;
$logo_fallback = $base_dir . '/assets/images/angelwrites-logo.png';
$border_color = '#DBA1A2';
$target_width = 1200;
$target_height = 630;
$border_thickness = 12;

// Helper: generate a clean fallback image with the border and a message
function generateFallbackImage($w, $h, $border_color, $thick, $message) {
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $w, $h, $white);
    
    list($r, $g, $b) = sscanf($border_color, "#%02x%02x%02x");
    $border_color_alloc = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, 0, $w, $thick, $border_color_alloc); 
    imagefilledrectangle($img, 0, $h - $thick, $w, $h, $border_color_alloc);
    imagefilledrectangle($img, 0, 0, $thick, $h, $border_color_alloc);
    imagefilledrectangle($img, $w - $thick, 0, $w, $h, $border_color_alloc);
    
    $text_color = imagecolorallocate($img, 150, 150, 150);
    $text = $message;
    // Fallback to simple text if GD font loading fails
    imagestring($img, 5, ($w/2)-80, ($h/2)-10, $text, $text_color);
    return $img;
}

// If image doesn't exist, output a beautiful fallback instead of dying
if (!file_exists($full_file_path)) {
    header_remove();
    header('Content-Type: image/png');
    $fallback = generateFallbackImage($target_width, $target_height, $border_color, $border_thickness, "Poem Image");
    imagepng($fallback);
    imagedestroy($fallback);
    exit;
}

// Load image & handle unknown types
$img_info = getimagesize($full_file_path);
if (!$img_info) {
    header_remove();
    header('Content-Type: image/png');
    $fallback = generateFallbackImage($target_width, $target_height, $border_color, $border_thickness, "Image Format Invalid");
    imagepng($fallback);
    imagedestroy($fallback);
    exit;
}

list($orig_width, $orig_height, $image_type) = $img_info;
$source = false;
switch ($image_type) {
    case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($full_file_path); break;
    case IMAGETYPE_PNG:  $source = @imagecreatefrompng($full_file_path); break;
    case IMAGETYPE_GIF:  $source = @imagecreatefromgif($full_file_path); break;
    case IMAGETYPE_WEBP: $source = @imagecreatefromwebp($full_file_path); break;
}
if (!$source) {
    header_remove();
    header('Content-Type: image/png');
    $fallback = generateFallbackImage($target_width, $target_height, $border_color, $border_thickness, "Image Reading Error");
    imagepng($fallback);
    imagedestroy($fallback);
    exit;
}

// Create Canvas
$canvas = imagecreatetruecolor($target_width, $target_height);
$ratio_orig = $orig_width / $orig_height;
$ratio_target = $target_width / $target_height;
if ($ratio_orig > $ratio_target) {
    $new_width = $orig_height * $ratio_target;
    $new_height = $orig_height;
    $src_x = ($orig_width - $new_width) / 2;
    $src_y = 0;
} else {
    $new_height = $orig_width / $ratio_target;
    $new_width = $orig_width;
    $src_x = 0;
    $src_y = ($orig_height - $new_height) / 2;
}
imagecopyresampled($canvas, $source, 0, 0, $src_x, $src_y, $target_width, $target_height, $new_width, $new_height);

// Draw Brand Border
list($r, $g, $b) = sscanf($border_color, "#%02x%02x%02x");
$border_color_alloc = imagecolorallocate($canvas, $r, $g, $b);
imagefilledrectangle($canvas, 0, 0, $target_width, $border_thickness, $border_color_alloc);
imagefilledrectangle($canvas, 0, $target_height - $border_thickness, $target_width, $target_height, $border_color_alloc);
imagefilledrectangle($canvas, 0, 0, $border_thickness, $target_height, $border_color_alloc);
imagefilledrectangle($canvas, $target_width - $border_thickness, 0, $target_width, $target_height, $border_color_alloc);

// Clean output buffer and send the PNG
ob_clean();
header_remove();
header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000');
imagepng($canvas);
imagedestroy($source);
imagedestroy($canvas);
exit;
?>