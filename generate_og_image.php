<?php
// generate_og_image.php
// Dynamic script to add the Rose Border and resize for Social Media

// Basic security: only allow access from your own site
if (empty($_GET['src'])) {
    header('HTTP/1.0 400 Bad Request');
    exit('Missing source parameter');
}

// Define paths
$base_dir = __DIR__;
$relative_path = ltrim($_GET['src'], '/');
$full_file_path = $base_dir . '/' . $relative_path;
$logo_fallback = $base_dir . '/assets/images/angelwrites-logo.png';

// Validate file exists
if (!file_exists($full_file_path)) {
    $full_file_path = $logo_fallback;
}

// Set target dimensions (1200x630 is recommended for Facebook/WhatsApp/LinkedIn/Twitter)
$target_width = 1200;
$target_height = 630;
$border_thickness = 12;
$border_color = '#DBA1A2'; // Your Rose Brand Color

// 1. Get original image dimensions and type
$img_info = getimagesize($full_file_path);
if (!$img_info) {
    header('Content-Type: image/png');
    readfile($logo_fallback);
    exit;
}

list($orig_width, $orig_height, $image_type) = $img_info;

// 2. Create canvas
$canvas = imagecreatetruecolor($target_width, $target_height);

// 3. Load source image
switch ($image_type) {
    case IMAGETYPE_JPEG:
        $source = imagecreatefromjpeg($full_file_path);
        break;
    case IMAGETYPE_PNG:
        $source = imagecreatefrompng($full_file_path);
        break;
    case IMAGETYPE_GIF:
        $source = imagecreatefromgif($full_file_path);
        break;
    default:
        header('Content-Type: image/png');
        readfile($logo_fallback);
        exit;
}

// 4. Calculate smart crop/resize to fill 1200x630 without distortion (cover mode)
$ratio_orig = $orig_width / $orig_height;
$ratio_target = $target_width / $target_height;

if ($ratio_orig > $ratio_target) {
    // Original is wider: crop width
    $new_width = $orig_height * $ratio_target;
    $new_height = $orig_height;
    $src_x = ($orig_width - $new_width) / 2;
    $src_y = 0;
} else {
    // Original is taller: crop height
    $new_height = $orig_width / $ratio_target;
    $new_width = $orig_width;
    $src_x = 0;
    $src_y = ($orig_height - $new_height) / 2;
}

// 5. Copy and resize to canvas
imagecopyresampled($canvas, $source, 0, 0, $src_x, $src_y, $target_width, $target_height, $new_width, $new_height);

// 6. Draw the Rose Border
list($r, $g, $b) = sscanf($border_color, "#%02x%02x%02x");
$border_color_alloc = imagecolorallocate($canvas, $r, $g, $b);

// Draw the border using rectangles (top, bottom, left, right)
imagefilledrectangle($canvas, 0, 0, $target_width, $border_thickness, $border_color_alloc); // Top
imagefilledrectangle($canvas, 0, $target_height - $border_thickness, $target_width, $target_height, $border_color_alloc); // Bottom
imagefilledrectangle($canvas, 0, 0, $border_thickness, $target_height, $border_color_alloc); // Left
imagefilledrectangle($canvas, $target_width - $border_thickness, 0, $target_width, $target_height, $border_color_alloc); // Right

// 7. Output the image as PNG (lossless, sharp borders)
header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000'); // Cache for 30 days
imagepng($canvas);

// Clean up
imagedestroy($source);
imagedestroy($canvas);
exit;
?>