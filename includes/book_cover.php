<?php
/**
 * Automatic book covers (nobody adds covers by hand any more).
 *
 *   1. An EPUB that carries its own cover image: that image is used (resized, saved as JPEG).
 *   2. Otherwise a cover is designed here: a deep brand gradient with soft light, a thin gold frame,
 *      the title in Playfair Display and the author below. Each book gets one of several palettes,
 *      chosen from its title, so the shelves are varied but always on brand.
 * Books that already have a cover keep it. Covers are saved in assets/uploads/books.
 * The font (assets/fonts/PlayfairDisplay.ttf) is under the SIL Open Font License (file next to it).
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

require_once __DIR__ . '/book_extract.php'; // aw_book_file_abs(), aw_book_type()

const AW_COVER_W = 900;
const AW_COVER_H = 1350;
const AW_COVER_PALETTES = [
    // top colour, bottom colour, glow, accent (frame and ornament)
    [[92, 38, 50], [156, 84, 87], [219, 161, 162], [232, 196, 140]],   // rose
    [[48, 34, 72], [109, 69, 120], [190, 150, 210], [236, 205, 150]],  // plum
    [[22, 58, 64], [44, 110, 108], [140, 205, 190], [236, 210, 150]],  // dusk teal
    [[70, 44, 26], [140, 96, 52], [230, 190, 130], [246, 222, 170]],   // amber
    [[26, 36, 66], [68, 76, 128], [170, 180, 230], [232, 200, 160]],   // night blue
    [[60, 28, 40], [120, 52, 72], [230, 150, 170], [240, 206, 160]],   // wine
];

function aw_cover_font() {
    $f = realpath(dirname(__DIR__) . '/assets/fonts/PlayfairDisplay.ttf');
    return ($f && is_file($f)) ? $f : '';
}

/** The cover image inside an EPUB (bytes), or ''. */
function aw_cover_from_epub($abs) {
    if (!class_exists('ZipArchive')) return '';
    $z = new ZipArchive();
    if ($z->open($abs) !== true) return '';
    $out = '';
    try {
        $container = (string)$z->getFromName('META-INF/container.xml');
        $opf_path = preg_match('/full-path="([^"]+)"/', $container, $m) ? $m[1] : '';
        $opf = $opf_path !== '' ? (string)$z->getFromName($opf_path) : '';
        $base = ($opf_path !== '' && dirname($opf_path) !== '.') ? dirname($opf_path) . '/' : '';
        $href = '';
        if ($opf !== '') {
            // EPUB 3: properties="cover-image"; EPUB 2: <meta name="cover" content="id">
            if (preg_match('/<item\b[^>]*properties="[^"]*cover-image[^"]*"[^>]*>/i', $opf, $it) && preg_match('/href="([^"]+)"/i', $it[0], $h)) $href = $h[1];
            elseif (preg_match('/<meta\b[^>]*name="cover"[^>]*content="([^"]+)"/i', $opf, $mc) || preg_match('/<meta\b[^>]*content="([^"]+)"[^>]*name="cover"/i', $opf, $mc)) {
                $id = preg_quote($mc[1], '/');
                if (preg_match('/<item\b[^>]*id="' . $id . '"[^>]*>/i', $opf, $it) && preg_match('/href="([^"]+)"/i', $it[0], $h)) $href = $h[1];
            }
        }
        if ($href !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $href)) $out = (string)$z->getFromName($base . rawurldecode($href));
    } catch (Throwable $e) {
        error_log('cover from epub: ' . $e->getMessage());
    }
    $z->close();
    return $out;
}

/** Fit text into lines no wider than $max at $size; returns [lines, size]. */
function aw_cover_wrap($font, $text, $size, $max, $maxLines) {
    $words = preg_split('/\s+/u', trim($text));
    for ($s = $size; $s >= 26; $s -= 4) {
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $box = imagettfbbox($s, 0, $font, $try);
            if ($box && ($box[2] - $box[0]) > $max && $cur !== '') { $lines[] = $cur; $cur = $w; } else $cur = $try;
        }
        if ($cur !== '') $lines[] = $cur;
        $widest = 0;
        foreach ($lines as $l) { $b = imagettfbbox($s, 0, $font, $l); $widest = max($widest, $b[2] - $b[0]); }
        if (count($lines) <= $maxLines && $widest <= $max) return [$lines, $s];
    }
    return [array_slice($lines, 0, $maxLines), 26];
}

/** Design a cover; returns a GD image or null. */
function aw_cover_design($title, $author) {
    if (!function_exists('imagecreatetruecolor')) return null;
    $font = aw_cover_font();
    $W = AW_COVER_W; $H = AW_COVER_H;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    $pal = AW_COVER_PALETTES[abs(crc32(mb_strtolower(trim((string)$title)))) % count(AW_COVER_PALETTES)];
    [$top, $bot, $glow, $acc] = $pal;
    for ($y = 0; $y < $H; $y++) { // vertical gradient
        $t = $y / ($H - 1);
        $c = imagecolorallocate($im, (int)($top[0] + ($bot[0] - $top[0]) * $t), (int)($top[1] + ($bot[1] - $top[1]) * $t), (int)($top[2] + ($bot[2] - $top[2]) * $t));
        imageline($im, 0, $y, $W, $y, $c);
    }
    // soft light: layered translucent circles
    foreach ([[0.82, 0.12, 0.62], [0.1, 0.88, 0.5]] as $g) {
        for ($r = (int)($W * $g[2]); $r > 10; $r -= 14) {
            $a = (int)max(118, 127 - (int)(9 * (1 - $r / ($W * $g[2]))));
            imagefilledellipse($im, (int)($W * $g[0]), (int)($H * $g[1]), $r * 2, $r * 2, imagecolorallocatealpha($im, $glow[0], $glow[1], $glow[2], $a));
        }
    }
    $gold = imagecolorallocate($im, $acc[0], $acc[1], $acc[2]);
    $goldSoft = imagecolorallocatealpha($im, $acc[0], $acc[1], $acc[2], 70);
    imagesetthickness($im, 3);
    imagerectangle($im, 42, 42, $W - 43, $H - 43, $goldSoft);
    imagesetthickness($im, 1);
    imagerectangle($im, 56, 56, $W - 57, $H - 57, $goldSoft);
    $white = imagecolorallocate($im, 255, 255, 255);
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 30);
    if ($font === '') { imagestring($im, 5, 80, (int)($H / 2), mb_substr((string)$title, 0, 60), $white); return $im; }
    // title
    [$lines, $size] = aw_cover_wrap($font, (string)$title, 92, $W - 240, 5);
    $lh = (int)($size * 1.5);
    $blockH = $lh * count($lines);
    $y = (int)($H * 0.40 - $blockH / 2) + $size;
    foreach ($lines as $l) {
        $b = imagettfbbox($size, 0, $font, $l);
        $x = (int)(($W - ($b[2] - $b[0])) / 2);
        imagettftext($im, $size, 0, $x + 2, $y + 3, imagecolorallocatealpha($im, 0, 0, 0, 90), $font, $l); // gentle shadow
        imagettftext($im, $size, 0, $x, $y, $white, $font, $l);
        $y += $lh;
    }
    // ornament: a short gold line with a diamond
    $oy = $y + 18;
    imagesetthickness($im, 2);
    imageline($im, (int)($W / 2 - 110), $oy, (int)($W / 2 - 18), $oy, $gold);
    imageline($im, (int)($W / 2 + 18), $oy, (int)($W / 2 + 110), $oy, $gold);
    imagefilledpolygon($im, [(int)($W / 2), $oy - 9, (int)($W / 2 + 9), $oy, (int)($W / 2), $oy + 9, (int)($W / 2 - 9), $oy], $gold);
    imagesetthickness($im, 1);
    // author
    $author = trim((string)$author);
    if ($author !== '') {
        [$al, $as] = aw_cover_wrap($font, mb_strtoupper($author), 34, $W - 220, 2);
        $ay = $oy + 80;
        foreach ($al as $l) {
            $spaced = implode(' ', preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY)); // letter-spaced capitals
            $b = imagettfbbox($as * 0.8, 0, $font, $spaced);
            if (($b[2] - $b[0]) > $W - 200) { $spaced = $l; $b = imagettfbbox($as * 0.8, 0, $font, $spaced); }
            imagettftext($im, $as * 0.8, 0, (int)(($W - ($b[2] - $b[0])) / 2), $ay, $soft, $font, $spaced);
            $ay += (int)($as * 1.4);
        }
    }
    // the small AngelWrites mark at the foot
    $mark = 'AngelWrites';
    $b = imagettfbbox(24, 0, $font, $mark);
    imagettftext($im, 24, 0, (int)(($W - ($b[2] - $b[0])) / 2), $H - 96, $goldSoft, $font, $mark);
    return $im;
}

/** Save a GD image as a cover JPEG; returns the relative path or ''. */
function aw_cover_save($im, $book_id) {
    if (!$im) return '';
    $rel = 'assets/uploads/books/cover_auto_' . (int)$book_id . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    $abs = dirname(__DIR__) . '/' . $rel;
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
    imageinterlace($im, true);
    $ok = imagejpeg($im, $abs, 86);
    imagedestroy($im);
    return $ok ? $rel : '';
}

/** An image from bytes, resized to the cover size (keeping its shape), or null. */
function aw_cover_from_bytes($bytes) {
    if ($bytes === '' || !function_exists('imagecreatefromstring')) return null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 200 || $h < 200) { imagedestroy($src); return null; } // too small to look good
    $scale = min(1, 1350 / $h);
    $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    return $dst;
}

/**
 * Give a book its automatic cover when it has none (or always, with $force).
 * Returns the cover's relative path ('' when none could be made).
 */
function aw_book_auto_cover(PDO $db, $book_id, $force = false) {
    try {
        $st = $db->prepare("SELECT id, title, author, cover_path, file_path FROM books WHERE id = ?");
        $st->execute([(int)$book_id]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) return '';
        $cur = trim((string)$b['cover_path']);
        if (!$force && $cur !== '' && (preg_match('#^https?://#', $cur) || is_file(dirname(__DIR__) . '/' . ltrim($cur, '/')))) return $cur;
        $im = null;
        $abs = aw_book_file_abs($b['file_path'] ?? '');
        if ($abs !== '' && aw_book_type($abs) === 'epub') $im = aw_cover_from_bytes(aw_cover_from_epub($abs));
        if (!$im) $im = aw_cover_design($b['title'], $b['author'] ?? '');
        $rel = aw_cover_save($im, (int)$b['id']);
        if ($rel === '') return $cur;
        $db->prepare("UPDATE books SET cover_path = ? WHERE id = ?")->execute([$rel, (int)$b['id']]);
        // an earlier automatic cover is removed (never one that was uploaded)
        if ($cur !== '' && strpos(basename($cur), 'cover_auto_') === 0) @unlink(dirname(__DIR__) . '/' . ltrim($cur, '/'));
        return $rel;
    } catch (Throwable $e) {
        error_log('auto cover ' . $book_id . ': ' . $e->getMessage());
        return '';
    }
}

/** Covers for every book that has none; returns how many were made. */
function aw_book_auto_cover_missing(PDO $db, $limit = 40) {
    $n = 0;
    try {
        $ids = $db->query("SELECT id, cover_path FROM books ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ids as $r) {
            $c = trim((string)$r['cover_path']);
            if ($c !== '' && (preg_match('#^https?://#', $c) || is_file(dirname(__DIR__) . '/' . ltrim($c, '/')))) continue;
            if (aw_book_auto_cover($db, (int)$r['id']) !== '') $n++;
            if ($n >= $limit) break;
        }
    } catch (Throwable $e) {
        error_log('auto covers: ' . $e->getMessage());
    }
    return $n;
}
