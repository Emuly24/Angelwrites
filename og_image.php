<?php
/**
 * og_image.php?t=poem|book&id=N&v=…  — the 1200x630 JPEG used in link previews (includes/og.php).
 * Poems: the cover filling the frame. Books: the cover, whole, on a soft blurred copy of itself.
 * Made once and kept in data/cache/og/ until the cover changes. Never fails: without GD or a cover
 * it sends the original image or the site's preview logo.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/og.php';

$type = ($_GET['t'] ?? '') === 'book' ? 'book' : 'poem';
$id = (int)($_GET['id'] ?? 0);
$logo = __DIR__ . '/assets/images/og-logo.jpg';

function og_send($file, $mime) {
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

$src = $id ? aw_og_source($db, $type, $id) : null;
if (!$src) og_send($logo, 'image/jpeg');

$cacheDir = __DIR__ . '/data/cache/og';
$key = $type . '_' . $id . '_' . substr(md5($src['path'] . '|' . $src['mtime']), 0, 10) . '.jpg';
$cached = $cacheDir . '/' . $key;
if (is_file($cached) && filesize($cached) > 1000) og_send($cached, 'image/jpeg');

$info = @getimagesize($src['path']);
if (!$info || !function_exists('imagecreatetruecolor')) og_send($src['path'], $info['mime'] ?? 'image/jpeg');
$open = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/gif' => 'imagecreatefromgif', 'image/webp' => 'imagecreatefromwebp'][$info['mime']] ?? null;
$img = ($open && function_exists($open)) ? @$open($src['path']) : false;
if (!$img) og_send($src['path'], $info['mime']);

try {
    $W = 1200; $H = 630;
    $sw = imagesx($img); $sh = imagesy($img);
    $out = imagecreatetruecolor($W, $H);
    imagefill($out, 0, 0, imagecolorallocate($out, 239, 216, 214)); // brand vanilla under transparent covers
    // fill the frame (centre crop)
    $fill = function ($dst, $src, $sw, $sh, $W, $H) {
        $scale = max($W / $sw, $H / $sh);
        $cw = (int)round($W / $scale); $ch = (int)round($H / $scale);
        imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $W, $H, $cw, $ch);
    };
    if ($type === 'poem') {
        $fill($out, $img, $sw, $sh, $W, $H);
    } else {
        // blurred background: scale down, blur, scale up (cheap and smooth)
        $small = imagecreatetruecolor(120, 63);
        $fill($small, $img, $sw, $sh, 120, 63);
        for ($i = 0; $i < 6; $i++) imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopyresampled($out, $small, 0, 0, 0, 0, $W, $H, 120, 63);
        imagedestroy($small);
        imagefilter($out, IMG_FILTER_BRIGHTNESS, -25);
        // the whole cover, centred, with a soft shadow
        $ch = 560; $cw = (int)round($sw * $ch / $sh);
        if ($cw > 1000) { $cw = 1000; $ch = (int)round($sh * $cw / $sw); }
        $x = (int)(($W - $cw) / 2); $y = (int)(($H - $ch) / 2);
        $shadow = imagecolorallocatealpha($out, 0, 0, 0, 112); // soft, stacked a few times
        for ($s = 14; $s > 0; $s -= 2) imagefilledrectangle($out, $x - $s + 8, $y - $s + 14, $x + $cw + $s + 8, $y + $ch + $s + 14, $shadow);
        imagecopyresampled($out, $img, $x, $y, 0, 0, $cw, $ch, $sw, $sh);
    }
    imageinterlace($out, true);
    if ((is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) && is_writable($cacheDir)) {
        foreach (glob($cacheDir . '/' . $type . '_' . $id . '_*.jpg') ?: [] as $old) @unlink($old); // the old cover's preview
        if (@imagejpeg($out, $cached, 85)) { imagedestroy($out); imagedestroy($img); og_send($cached, 'image/jpeg'); }
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    imagejpeg($out, null, 85);
    imagedestroy($out); imagedestroy($img);
} catch (Throwable $e) {
    error_log('og_image: ' . $e->getMessage());
    og_send($src['path'], $info['mime']);
}
