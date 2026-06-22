<?php
// generate_og_image.php (Bulletproof Version)
header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000'); // Cache for 30 days

$base_dir = __DIR__;
$relative_path = isset($_GET['src']) ? ltrim($_GET['src'], '/') : '';
$full_file_path = $base_dir . '/' . $relative_path;
$logo_fallback = $base_dir . '/assets/images/angelwrites-logo.png';

$border_thickness = 12;
$border_color = '#DBA1A2'; // Your Rose Brand Color
$target_width = 1200;
$target_height = 630;

// Function to create a fallback branded image
function createFallbackImage($w, $h, $color, $thick) {
    $img = imagecreatetruecolor($w, $h);
    // White background
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $w, $h, $white);
    
    // Brand Border
    list($r, $g, $b) = sscanf($color, "#%02x%02x%02x");
    $border_color_alloc = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, 0, $w, $thick, $border_color_alloc); 
    imagefilledrectangle($img, 0, $h - $thick, $w, $h, $border_color_alloc);
    imagefilledrectangle($img, 0, 0, $thick, $h, $border_color_alloc);
    imagefilledrectangle($img, $w - $thick, 0, $w, $h, $border_color_alloc);
    
    // Draw a central "No image" text
    $text = "Poem Image Missing";
    $font_size = 30;
    $text_color = imagecolorallocate($img, 150, 150, 150);
    $box = imagettfbbox($font_size, 0, __DIR__ . '/assets/fonts/arial.ttf', $text); 
    if (!$box) { // Fallback if no font file
        imagestring($img, 5, ($w/2)-80, ($h/2)-10, $text, $text_color);
    } else {
        $x = ($w - ($box[2] - $box[0])) / 2;
        $y = ($h - ($box[5] - $box[3])) / 2;
        imagettftext($img, $font_size, 0, $x, $y, $text_color, __DIR__ . '/assets/fonts/arial.ttf', $text);
    }
    return $img;
}

// 1. Check if image exists, if not, fallback
if (!file_exists($full_file_path)) {
    $fallbackImg = createFallbackImage($target_width, $target_height, $border_color, $border_thickness);
    imagepng($fallbackImg);
    imagedestroy($fallbackImg);
    exit;
}

// 2. Load Image
$img_info = getimagesize($full_file_path);
if (!$img_info) {
    $fallbackImg = createFallbackImage($target_width, $target_height, $border_color, $border_thickness);
    imagepng($fallbackImg);
    imagedestroy($fallbackImg);
    exit;
}

list($orig_width, $orig_height, $image_type) = $img_info;

// 3. Create Source Resource
switch ($image_type) {
    case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($full_file_path); break;
    case IMAGETYPE_PNG:  $source = @imagecreatefrompng($full_file_path); break;
    case IMAGETYPE_GIF:  $source = @imagecreatefromgif($full_file_path); break;
    default: $source = false; break;
}

if (!$source) {
    $fallbackImg = createFallbackImage($target_width, $target_height, $border_color, $border_thickness);
    imagepng($fallbackImg);
    imagedestroy($fallbackImg);
    exit;
}

// 4. Create Canvas & Resize (Smart Crop)
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

// 5. Draw Brand Border
list($r, $g, $b) = sscanf($border_color, "#%02x%02x%02x");
$border_color_alloc = imagecolorallocate($canvas, $r, $g, $b);
imagefilledrectangle($canvas, 0, 0, $target_width, $border_thickness, $border_color_alloc);
imagefilledrectangle($canvas, 0, $target_height - $border_thickness, $target_width, $target_height, $border_color_alloc);
imagefilledrectangle($canvas, 0, 0, $border_thickness, $target_height, $border_color_alloc);
imagefilledrectangle($canvas, $target_width - $border_thickness, 0, $target_width, $target_height, $border_color_alloc);

// 6. Output
imagepng($canvas);

// Clean up
imagedestroy($source);
imagedestroy($canvas);
exit;
?>