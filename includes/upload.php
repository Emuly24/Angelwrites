<?php
/**
 * R: the one shared upload helper. Every upload on the site goes through aw_upload_store()
 * (or aw_upload_store_data() for an image sent as a data: URI). Limits live in upload_limits.php.
 *
 * What it guarantees for each new upload:
 *   - size re-checked on the server (against the limit and the host's own PHP limits);
 *   - type decided by the file's content (finfo), never by the browser's header;
 *   - the file name's extension must be one the type allows; the stored name is generated here;
 *   - images are resized, re-encoded and stripped of EXIF when GD is available; when it is not,
 *     images over the limit are refused (no fake compression);
 *   - audio/video length checked when the file states it; video files must be MP4 (H.264);
 *   - per-member daily and total quotas;
 *   - a refusal never leaves a partial file behind, always returns a clear message, and is logged.
 *
 * Files already on disk are never touched.
 */

require_once __DIR__ . '/upload_limits.php';
require_once __DIR__ . '/upload_quota.php';

/* ------------------------------------------------------------------ host facts */

function aw_upload_ini_bytes($v): int
{
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (float)$v;
    $u = strtolower(substr($v, -1));
    return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
}

/** Largest single file the host lets PHP receive. */
function aw_upload_host_max(): int
{
    $a = aw_upload_ini_bytes(ini_get('upload_max_filesize'));
    $b = aw_upload_ini_bytes(ini_get('post_max_size'));
    return max(1, min($a, $b));
}

/** Which image tools this server has. Shown to admins in Settings; never assumed. */
function aw_upload_image_tools(): array
{
    $gd = extension_loaded('gd') && function_exists('imagecreatetruecolor');
    $info = $gd && function_exists('gd_info') ? gd_info() : [];
    return [
        'gd'      => $gd,
        'jpeg'    => $gd && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
        'png'     => $gd && function_exists('imagecreatefrompng') && function_exists('imagepng'),
        'webp'    => $gd && function_exists('imagecreatefromwebp') && function_exists('imagewebp') && !empty($info['WebP Support']),
        'gif'     => $gd && function_exists('imagecreatefromgif'),
        'imagick' => extension_loaded('imagick') && class_exists('Imagick'),
        'exif'    => function_exists('exif_read_data'),
        'finfo'   => function_exists('finfo_open'),
        'zip'     => class_exists('ZipArchive'),
    ];
}

/** True when the server can resize and re-encode images itself. */
function aw_upload_can_process_images(): bool
{
    $t = aw_upload_image_tools();
    return $t['gd'] && $t['jpeg'];
}

function aw_upload_mb(int $bytes): string
{
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    $mb = $bytes / 1048576;
    return (abs($mb - round($mb)) < 0.05 ? (string)round($mb) : number_format($mb, 1)) . ' MB';
}

/**
 * The limits that actually apply to a kind on this server:
 *   accept_bytes  largest file the form may send (never more than the host allows)
 *   stored_bytes  largest file that may be kept
 */
function aw_upload_effective(string $kind): array
{
    $all = aw_upload_limits();
    $c = $all[$kind] ?? null;
    if (!$c) return [];
    $host = aw_upload_host_max();
    $isImage = isset($c['max_edge']) || isset($c['crop']);
    $accept = ($isImage && aw_upload_can_process_images()) ? (int)$c['input_max_bytes'] : (int)$c['max_bytes'];
    $c['is_image'] = $isImage;
    $c['accept_bytes'] = min($accept, $host);
    $c['stored_bytes'] = min((int)$c['max_bytes'], $host);
    $c['host_bytes'] = $host;
    return $c;
}

/* ------------------------------------------------------------------ form helpers */

/** Text for a field's label, e.g. "max 2 MB, JPG, PNG or WebP". */
function aw_upload_hint(string $kind): string
{
    $c = aw_upload_effective($kind);
    if (!$c) return '';
    if ($c['is_image'] && aw_upload_can_process_images()) {
        $s = $c['hint_types'] . '. Photos up to ' . aw_upload_mb($c['accept_bytes']) . ' are resized for you';
    } else {
        $s = 'max ' . aw_upload_mb($c['stored_bytes']) . ', ' . $c['hint_types'];
    }
    if (!empty($c['max_seconds'])) {
        $m = $c['max_seconds'] / 60;
        $s .= ', up to ' . (floor($m) == $m ? (int)$m : round($m, 1)) . ' minute' . ($m == 1 ? '' : 's');
    }
    return $s;
}

/** Attributes for <input type="file">: accept list plus the numbers the browser check reads. */
function aw_upload_attrs(string $kind): string
{
    $c = aw_upload_effective($kind);
    if (!$c) return '';
    $exts = array_values(array_unique(array_merge(array_values($c['mimes']), $c['extra_exts'] ?? [])));
    if (in_array('jpg', $exts, true)) $exts[] = 'jpeg';
    if ($kind === 'book') $exts = ['pdf', 'epub', 'docx'];
    if (($kind === 'video') || ($kind === 'video_recording')) $exts = array_merge($exts, ['m4v', 'mov']);
    $accept = $c['is_image'] ? 'image/jpeg,image/png,image/webp,image/gif'
            : implode(',', array_map(function ($e) { return '.' . $e; }, array_unique($exts)));
    $out = ' accept="' . htmlspecialchars($accept) . '"'
         . ' data-aw-upload="' . htmlspecialchars($kind) . '"'
         . ' data-aw-max="' . (int)$c['accept_bytes'] . '"'
         . ' data-aw-exts="' . htmlspecialchars(implode(',', array_unique($exts))) . '"'
         . ' data-aw-label="' . htmlspecialchars($c['label']) . '"';
    if (!empty($c['max_seconds'])) $out .= ' data-aw-seconds="' . (int)$c['max_seconds'] . '"';
    if (!empty($c['too_big'])) $out .= ' data-aw-toobig="' . htmlspecialchars($c['too_big']) . '"';
    return $out;
}

/** JSON of the effective limits for page scripts that build uploads themselves (recorders, cameras). */
function aw_upload_js_limits(array $kinds): string
{
    $out = [];
    foreach ($kinds as $k) {
        $c = aw_upload_effective($k);
        if (!$c) continue;
        $exts = array_values(array_unique(array_merge(array_values($c['mimes']), $c['extra_exts'] ?? [])));
        $out[$k] = ['max' => (int)$c['accept_bytes'], 'seconds' => (int)($c['max_seconds'] ?? 0),
                    'label' => $c['label'], 'exts' => $exts, 'tooBig' => $c['too_big'] ?? ''];
    }
    return json_encode($out);
}

/** Prints the browser-side checker once per page. Call it on pages that have an upload field. */
function aw_upload_script(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $v = @filemtime(__DIR__ . '/../assets/js/aw-upload.js') ?: 1;
    echo '<script src="' . SITE_URL . '/assets/js/aw-upload.js?v=' . $v . '" defer></script>' . "\n";
}

/**
 * When a request is larger than post_max_size PHP throws the whole form away ($_POST and $_FILES
 * arrive empty). Returns a message for that case, or '' when the request is fine.
 */
function aw_upload_post_overflow(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($_POST) || !empty($_FILES)) return '';
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) return '';
    return 'That upload was larger than this server accepts (' . aw_upload_mb(aw_upload_ini_bytes(ini_get('post_max_size')))
         . ' in total), so nothing was saved. Please choose smaller files.';
}

/* ------------------------------------------------------------------ logging */

/** Every refusal: who, what kind, how big, why. Kept in data/upload_rejections.log (last 300) and the PHP log. */
function aw_upload_log_reject($userId, string $kind, int $size, string $reason, string $name = ''): void
{
    $row = ['at' => date('Y-m-d H:i:s'), 'member' => $userId === null ? null : (int)$userId, 'type' => $kind,
            'size' => $size, 'reason' => $reason, 'name' => substr($name, 0, 80)];
    error_log('upload refused: ' . json_encode($row));
    $file = __DIR__ . '/../data/upload_rejections.log';
    $lines = is_file($file) ? (array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, implode("\n", array_slice($lines, -300)) . "\n", LOCK_EX);
}

/* ------------------------------------------------------------------ type detection */

function aw_upload_detect_mime(string $path): string
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f) { $mime = (string)@finfo_file($f, $path); @finfo_close($f); }
    }
    $mime = strtolower(trim($mime));
    $head = (string)@file_get_contents($path, false, null, 0, 64);
    // finfo is vague or wrong for a few formats the site uses; settle them from the first bytes
    if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'text/plain') {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) $mime = 'image/jpeg';
        elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) $mime = 'image/png';
        elseif (strncmp($head, 'GIF8', 4) === 0) $mime = 'image/gif';
        elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') $mime = 'image/webp';
        elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WAVE') $mime = 'audio/wav';
        elseif (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) $mime = 'video/webm';
        elseif (substr($head, 4, 4) === 'ftyp') $mime = 'video/mp4';
        elseif (strncmp($head, 'OggS', 4) === 0) $mime = 'audio/ogg';
        elseif (strncmp($head, 'ID3', 3) === 0 || (strlen($head) > 1 && $head[0] === "\xFF" && (ord($head[1]) & 0xE0) === 0xE0)) $mime = 'audio/mpeg';
        elseif (strncmp($head, '%PDF-', 5) === 0) $mime = 'application/pdf';
        elseif (strncmp($head, "PK\x03\x04", 4) === 0) $mime = 'application/zip';
    }
    if ($mime === 'video/x-matroska') $mime = 'video/webm';
    if ($mime === 'video/x-m4v') $mime = 'video/mp4';
    return $mime;
}

/** EPUB and DOCX are zip files: look inside to tell which (or neither). */
function aw_upload_zip_kind(string $path): string
{
    $head = (string)@file_get_contents($path, false, null, 0, 80);
    if (strpos($head, 'mimetypeapplication/epub+zip') !== false) return 'epub';
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) === true) {
            $kind = '';
            if ($z->locateName('META-INF/container.xml') !== false) $kind = 'epub';
            elseif ($z->locateName('word/document.xml') !== false) $kind = 'docx';
            $z->close();
            return $kind;
        }
    }
    return '';
}

/* ------------------------------------------------------------------ media facts (no ffmpeg on this host) */

/** Returns the bytes of a top-level MP4 box (e.g. "moov"), wherever it sits in the file. */
function aw_upload_mp4_box(string $path, string $want, int $cap = 6291456): string
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $size = (int)filesize($path);
    $pos = 0; $out = '';
    for ($i = 0; $i < 200 && $pos + 8 <= $size; $i++) {
        fseek($fh, $pos);
        $h = fread($fh, 16);
        if (strlen($h) < 8) break;
        $len = unpack('N', substr($h, 0, 4))[1];
        $type = substr($h, 4, 4);
        $hdr = 8;
        if ($len === 1 && strlen($h) >= 16) { $p = unpack('N2', substr($h, 8, 8)); $len = $p[1] * 4294967296 + $p[2]; $hdr = 16; }
        elseif ($len === 0) { $len = $size - $pos; }
        if ($len < $hdr) break;
        if ($type === $want) {
            fseek($fh, $pos + $hdr);
            $out = (string)fread($fh, (int)min($len - $hdr, $cap));
            break;
        }
        $pos += $len;
    }
    fclose($fh);
    return $out;
}

/** Length in seconds when the file states it; null when it cannot be read. */
function aw_upload_duration(string $path, string $mime): ?float
{
    $size = (int)@filesize($path);
    if ($size <= 0) return null;

    if (strpos($mime, 'mp4') !== false || strpos($mime, 'm4a') !== false || $mime === 'video/quicktime') {
        $moov = aw_upload_mp4_box($path, 'moov');
        $p = strpos($moov, 'mvhd');
        if ($p === false) return null;
        $b = substr($moov, $p + 4, 32);
        if (strlen($b) < 20) return null;
        if (ord($b[0]) === 1) {
            if (strlen($b) < 32) return null;
            $ts = unpack('N', substr($b, 20, 4))[1];
            $d = unpack('N2', substr($b, 24, 8)); $dur = $d[1] * 4294967296 + $d[2];
        } else {
            $ts = unpack('N', substr($b, 12, 4))[1];
            $dur = unpack('N', substr($b, 16, 4))[1];
        }
        return $ts > 0 ? $dur / $ts : null;
    }

    if (strpos($mime, 'wav') !== false) {
        $h = (string)@file_get_contents($path, false, null, 0, 4096);
        $f = strpos($h, 'fmt ');
        if ($f === false || strlen($h) < $f + 24) return null;
        $byteRate = unpack('V', substr($h, $f + 16, 4))[1];
        return $byteRate > 0 ? max(0, $size - 44) / $byteRate : null;
    }

    if (strpos($mime, 'ogg') !== false || $mime === 'audio/opus') {
        $head = (string)@file_get_contents($path, false, null, 0, 8192);
        $rate = 0;
        if (strpos($head, 'OpusHead') !== false) $rate = 48000;
        elseif (($v = strpos($head, "\x01vorbis")) !== false && strlen($head) >= $v + 16) $rate = unpack('V', substr($head, $v + 12, 4))[1];
        if ($rate <= 0) return null;
        $tailLen = min($size, 65536);
        $tail = (string)@file_get_contents($path, false, null, $size - $tailLen, $tailLen);
        $p = strrpos($tail, 'OggS');
        if ($p === false || strlen($tail) < $p + 14) return null;
        $g = unpack('V2', substr($tail, $p + 6, 8));
        $granule = $g[2] * 4294967296 + $g[1];
        return $granule > 0 ? $granule / $rate : null;
    }

    if ($mime === 'audio/mpeg' || $mime === 'audio/mp3') {
        $h = (string)@file_get_contents($path, false, null, 0, 16);
        $skip = 0;
        if (strncmp($h, 'ID3', 3) === 0 && strlen($h) >= 10) {
            $skip = 10 + ((ord($h[6]) & 0x7F) << 21 | (ord($h[7]) & 0x7F) << 14 | (ord($h[8]) & 0x7F) << 7 | (ord($h[9]) & 0x7F));
        }
        $buf = (string)@file_get_contents($path, false, null, $skip, 4096);
        for ($i = 0; $i + 4 <= strlen($buf); $i++) {
            if ($buf[$i] !== "\xFF" || (ord($buf[$i + 1]) & 0xE0) !== 0xE0) continue;
            $b1 = ord($buf[$i + 1]); $b2 = ord($buf[$i + 2]);
            $ver = ($b1 >> 3) & 3; $layer = ($b1 >> 1) & 3; $bi = ($b2 >> 4) & 15; $si = ($b2 >> 2) & 3;
            if ($ver === 1 || $layer !== 1 || $bi === 0 || $bi === 15 || $si === 3) continue;   // Layer III only
            $v1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
            $v2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
            $kbps = $ver === 3 ? $v1[$bi] : $v2[$bi];
            $rates = $ver === 3 ? [44100, 48000, 32000] : ($ver === 2 ? [22050, 24000, 16000] : [11025, 12000, 8000]);
            $x = strpos($buf, 'Xing', $i); if ($x === false) $x = strpos($buf, 'Info', $i);
            if ($x !== false && $x < $i + 200 && strlen($buf) >= $x + 12 && (ord($buf[$x + 7]) & 1)) {
                $frames = unpack('N', substr($buf, $x + 8, 4))[1];
                return $frames * ($ver === 3 ? 1152 : 576) / $rates[$si];
            }
            return $kbps > 0 ? ($size - $skip) * 8 / ($kbps * 1000) : null;
        }
        return null;
    }

    if (strpos($mime, 'webm') !== false) {
        // Recordings made in the browser usually carry no length at all; files from an encoder do.
        $h = (string)@file_get_contents($path, false, null, 0, 65536);
        $scale = 1000000;
        if (($s = strpos($h, "\x2A\xD7\xB1")) !== false && strlen($h) > $s + 4) {
            $n = ord($h[$s + 3]) & 0x0F; $val = 0;
            for ($i = 0; $i < $n && $i < 8; $i++) $val = $val * 256 + ord($h[$s + 4 + $i]);
            if ($val > 0) $scale = $val;
        }
        if (($d = strpos($h, "\x44\x89")) !== false && strlen($h) > $d + 11) {
            $n = ord($h[$d + 2]);
            if ($n === 0x84) { $v = unpack('G', substr($h, $d + 3, 4))[1]; }
            elseif ($n === 0x88) { $v = unpack('E', substr($h, $d + 3, 8))[1]; }
            else return null;
            return ($v > 0 && is_finite($v)) ? $v * $scale / 1e9 : null;
        }
        return null;
    }
    return null;
}

/** 'h264', 'other' (a different codec was found) or '' (could not tell). */
function aw_upload_mp4_video_codec(string $path): string
{
    $moov = aw_upload_mp4_box($path, 'moov');
    if ($moov === '') return '';
    if (strpos($moov, 'avc1') !== false || strpos($moov, 'avc3') !== false) return 'h264';
    foreach (['hvc1', 'hev1', 'vp09', 'av01', 'mp4v', 'dvh1'] as $c) {
        if (strpos($moov, $c) !== false) return 'other';
    }
    return '';
}

/* ------------------------------------------------------------------ images */

function aw_upload_jpeg_orientation(string $path): int
{
    if (function_exists('exif_read_data')) {
        $e = @exif_read_data($path);
        if (is_array($e) && !empty($e['Orientation'])) return (int)$e['Orientation'];
        if (is_array($e)) return 1;
    }
    // no exif extension: read the Orientation tag straight from the APP1 segment
    $d = (string)@file_get_contents($path, false, null, 0, 131072);
    $p = strpos($d, "Exif\0\0");
    if ($p === false) return 1;
    $t = $p + 6;
    $le = substr($d, $t, 2) === 'II';
    $u16 = function ($o) use ($d, $le) { $s = substr($d, $o, 2); return strlen($s) < 2 ? 0 : unpack($le ? 'v' : 'n', $s)[1]; };
    $u32 = function ($o) use ($d, $le) { $s = substr($d, $o, 4); return strlen($s) < 4 ? 0 : unpack($le ? 'V' : 'N', $s)[1]; };
    $ifd = $t + $u32($t + 4);
    $n = $u16($ifd);
    for ($i = 0; $i < $n && $i < 64; $i++) {
        $e = $ifd + 2 + $i * 12;
        if ($u16($e) === 0x0112) return max(1, min(8, $u16($e + 8)));
    }
    return 1;
}

/** Rough check that decoding w x h pixels will fit in PHP's memory. */
function aw_upload_image_fits_memory(int $w, int $h): bool
{
    $limit = aw_upload_ini_bytes(ini_get('memory_limit'));
    if ($limit === PHP_INT_MAX) return $w * $h <= 60000000;
    $need = (int)($w * $h * 5.2) + 16 * 1048576;   // decoded source + working copy + headroom
    return memory_get_usage(true) + $need < $limit;
}

/**
 * Resize, re-encode and strip EXIF. Returns [true, temp path, extension, mime] or [false, message].
 * Never writes outside the temp path it creates, and removes it on failure.
 */
function aw_upload_process_image(string $src, string $mime, array $c, string $tmpDir): array
{
    $tools = aw_upload_image_tools();
    $info = @getimagesize($src);
    if (!$info || empty($info[0]) || empty($info[1])) return [false, 'That file is not an image we can read.'];
    [$w, $h] = $info;
    if ($w > 12000 || $h > 12000 || !aw_upload_image_fits_memory($w, $h)) {
        return [false, 'That photo is too large for the server to resize (' . $w . ' × ' . $h . ' pixels). Please choose a smaller one or take the photo at a lower resolution.'];
    }

    // An animated GIF would lose its animation if redrawn: keep it as it is when it is small enough.
    if ($mime === 'image/gif') {
        $raw = (string)@file_get_contents($src);
        if (substr_count($raw, "\x00\x21\xF9\x04") > 1) {
            if (strlen($raw) > $c['stored_bytes']) return [false, 'Animated GIFs must be ' . aw_upload_mb($c['stored_bytes']) . ' or smaller.'];
            $out = tempnam($tmpDir, 'awu');
            file_put_contents($out, $raw);
            return [true, $out, 'gif', 'image/gif'];
        }
        unset($raw);
    }

    switch ($mime) {
        case 'image/jpeg': $im = $tools['jpeg'] ? @imagecreatefromjpeg($src) : false; break;
        case 'image/png':  $im = $tools['png'] ? @imagecreatefrompng($src) : false; break;
        case 'image/webp': $im = $tools['webp'] ? @imagecreatefromwebp($src) : false; break;
        case 'image/gif':  $im = $tools['gif'] ? @imagecreatefromgif($src) : false; break;
        default: $im = false;
    }
    if (!$im) return [false, 'That image could not be read. Please save it as a JPG or PNG and try again.'];
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);

    // Photos from phones are stored sideways with a "rotate me" note in EXIF. EXIF is removed, so turn the pixels.
    if ($mime === 'image/jpeg') {
        $o = aw_upload_jpeg_orientation($src);
        $deg = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($im, $deg, 0); if ($r) { imagedestroy($im); $im = $r; } }
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        $w = imagesx($im); $h = imagesy($im);
    }

    // Target size
    if (!empty($c['crop'])) {
        $side = min($w, $h);
        $sx = (int)(($w - $side) / 2); $sy = (int)(($h - $side) / 2);
        $tw = $th = (int)$c['crop']; $sw = $sh = $side;
    } else {
        $edge = (int)($c['max_edge'] ?? 1600);
        $scale = min(1, $edge / max($w, $h));
        $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
        $sx = $sy = 0; $sw = $w; $sh = $h;
    }

    $hasAlpha = false;
    if ($mime !== 'image/jpeg') {
        // look for real transparency on a tiny copy (cheap), so logos and stickers keep it
        $probe = imagecreatetruecolor(48, 48);
        imagealphablending($probe, false); imagesavealpha($probe, true);
        imagecopyresampled($probe, $im, 0, 0, 0, 0, 48, 48, $w, $h);
        for ($y = 0; $y < 48 && !$hasAlpha; $y++) for ($x = 0; $x < 48; $x++) {
            if (((imagecolorat($probe, $x, $y) >> 24) & 0x7F) > 8) { $hasAlpha = true; break; }
        }
        imagedestroy($probe);
    }

    $out = tempnam($tmpDir, 'awu');
    $q = (int)($c['jpeg_quality'] ?? 85);
    $useWebp = $tools['webp'] && (!empty($c['prefer_webp']) || false);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $dst = imagecreatetruecolor($tw, $th);
        if ($hasAlpha) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
        else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); }
        imagecopyresampled($dst, $im, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);

        if ($hasAlpha && $tools['webp'] && ($useWebp || $attempt > 0)) { $ok = imagewebp($dst, $out, $q); $ext = 'webp'; $omime = 'image/webp'; }
        elseif ($hasAlpha) { $ok = imagepng($dst, $out, 9); $ext = 'png'; $omime = 'image/png'; }
        elseif ($useWebp) { $ok = imagewebp($dst, $out, $q); $ext = 'webp'; $omime = 'image/webp'; }
        else { imageinterlace($dst, true); $ok = imagejpeg($dst, $out, $q); $ext = 'jpg'; $omime = 'image/jpeg'; }
        imagedestroy($dst);
        if (!$ok) { imagedestroy($im); @unlink($out); return [false, 'The image could not be saved. Please try again.']; }
        clearstatcache(true, $out);
        if (filesize($out) <= $c['stored_bytes']) { imagedestroy($im); return [true, $out, $ext, $omime]; }
        // still too heavy: lower the quality first, then the dimensions
        if ($q > 65) { $q -= 10; }
        else { $tw = max(1, (int)($tw * 0.8)); $th = max(1, (int)($th * 0.8)); }
    }
    imagedestroy($im);
    @unlink($out);
    return [false, 'That image is still larger than ' . aw_upload_mb($c['stored_bytes']) . ' after resizing. Please choose a simpler or smaller image.'];
}

/* ------------------------------------------------------------------ the one entry point */

/**
 * Validate and store one upload.
 *
 * @param array|null $file  one entry of $_FILES (name, tmp_name, size, error)
 * @param string     $kind  a key of aw_upload_limits(): image, avatar, audio, video, video_recording, book
 * @param string     $dirRel folder under the site root, e.g. 'assets/uploads/comments'
 * @param array      $opts  prefix (file-name prefix), user_id, role, db, count_quota (default true)
 * @return array ['ok'=>true,'path'=>relative path,'bytes'=>int,'mime'=>string,'ext'=>string,'processed'=>bool]
 *            or ['ok'=>false,'error'=>message,'empty'=>true when no file was sent]
 */
function aw_upload_store(?array $file, string $kind, string $dirRel, array $opts = []): array
{
    if (!$file || !isset($file['error']) || is_array($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
        return ['ok' => false, 'empty' => true, 'error' => ''];
    }
    return aw_upload_ingest((string)$file['tmp_name'], (string)$file['name'], (int)($file['size'] ?? 0), (int)$file['error'], true, $kind, $dirRel, $opts);
}

/** The same checks for an image that arrives as a data: URI (the avatar cropper sends one). */
function aw_upload_store_data(string $dataUri, string $kind, string $dirRel, array $opts = []): array
{
    if ($dataUri === '') return ['ok' => false, 'empty' => true, 'error' => ''];
    $c = aw_upload_effective($kind);
    $uid = $opts['user_id'] ?? ($_SESSION['user_id'] ?? null);
    if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $dataUri, $m)) {
        aw_upload_log_reject($uid, $kind, strlen($dataUri), 'not an image data URI');
        return ['ok' => false, 'error' => 'That picture could not be read. Please choose it again.'];
    }
    // refuse before decoding if it could not possibly fit (base64 is 4/3 the size)
    if ($c && strlen($dataUri) > $c['accept_bytes'] * 1.37 + 100) {
        aw_upload_log_reject($uid, $kind, (int)(strlen($dataUri) * 0.75), 'too large');
        return ['ok' => false, 'error' => 'That ' . $c['label'] . ' is too large (max ' . aw_upload_mb($c['accept_bytes']) . ').'];
    }
    $bin = base64_decode(substr($dataUri, strlen($m[0])), true);
    if ($bin === false || $bin === '') {
        aw_upload_log_reject($uid, $kind, 0, 'data URI did not decode');
        return ['ok' => false, 'error' => 'That picture could not be read. Please choose it again.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'awd');
    file_put_contents($tmp, $bin);
    $r = aw_upload_ingest($tmp, 'picture', strlen($bin), UPLOAD_ERR_OK, false, $kind, $dirRel, $opts);
    @unlink($tmp);
    return $r;
}

function aw_upload_ingest(string $tmp, string $name, int $size, int $err, bool $isUpload, string $kind, string $dirRel, array $opts): array
{
    $c = aw_upload_effective($kind);
    $uid = $opts['user_id'] ?? ($_SESSION['user_id'] ?? null);
    $role = (string)($opts['role'] ?? ($_SESSION['role'] ?? ''));
    $db = $opts['db'] ?? ($GLOBALS['db'] ?? null);
    $fail = function (string $msg, string $reason) use ($uid, $kind, &$size, $name) {
        aw_upload_log_reject($uid, $kind, $size, $reason, $name);
        return ['ok' => false, 'error' => $msg];
    };

    if (!$c) return $fail('This kind of file cannot be uploaded here.', 'unknown kind ' . $kind);
    $label = $c['label'];

    // 1. PHP's own verdict
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return $fail('That ' . $label . ' is larger than this server accepts (' . aw_upload_mb($c['host_bytes']) . '). Nothing was saved.', 'over host limit');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $fail('The ' . $label . ' did not finish uploading. Please try again.', 'php upload error ' . $err);
    }
    if ($isUpload && !is_uploaded_file($tmp)) return $fail('The ' . $label . ' could not be read. Please try again.', 'not an uploaded file');
    if (!is_file($tmp)) return $fail('The ' . $label . ' could not be read. Please try again.', 'temp file missing');
    clearstatcache(true, $tmp);
    $size = (int)filesize($tmp);
    if ($size <= 0) return $fail('That file is empty.', 'empty file');

    // 2. size
    if ($size > $c['accept_bytes']) {
        $lead = !empty($c['too_big']) ? $c['too_big'] . ' (max ' . aw_upload_mb($c['accept_bytes']) . ').'
                                      : 'That ' . $label . ' is too large (' . aw_upload_mb($size) . '). The limit is ' . aw_upload_mb($c['accept_bytes']) . '.';
        return $fail($lead, 'too large');
    }

    // 3. type: by content, then the name's extension must be one this kind allows
    $mime = aw_upload_detect_mime($tmp);
    if ($kind === 'book' && ($mime === 'application/zip' || $mime === 'application/octet-stream' || $mime === 'application/epub+zip'
            || strpos($mime, 'officedocument') !== false)) {
        $zk = aw_upload_zip_kind($tmp);
        $mime = $zk === 'epub' ? 'application/epub+zip'
              : ($zk === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/x-unknown-zip');
    }
    if (!isset($c['mimes'][$mime]) || $c['mimes'][$mime] === 'zip') {
        return $fail('That file cannot be used here. Allowed: ' . $c['hint_types'] . '.', 'type refused: ' . ($mime ?: 'unknown'));
    }
    $ext = $c['mimes'][$mime];
    $allowedExts = array_unique(array_merge(array_values($c['mimes']), $c['extra_exts'] ?? [], ['jpeg'], in_array('mp4', $c['mimes'], true) ? ['m4v', 'mov'] : []));
    $nameExt = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if ($nameExt !== '' && $nameExt !== 'blob' && !in_array($nameExt, $allowedExts, true)) {
        return $fail('Files ending in ".' . htmlspecialchars($nameExt) . '" cannot be uploaded here. Allowed: ' . $c['hint_types'] . '.', 'extension refused: ' . $nameExt);
    }
    if ($kind === 'video_recording' && $ext === 'webm' && empty($c['allow_webm'])) {
        return $fail('This browser records a video format that cannot be saved here. Please use Safari or Chrome, or upload an MP4.', 'webm recording refused');
    }
    if ($kind === 'audio' && $nameExt === 'mp4') $ext = 'm4a';

    // 4. video must be H.264; length when the file states it
    if (!empty($c['require_h264']) && $ext === 'mp4') {
        $codec = aw_upload_mp4_video_codec($tmp);
        if ($codec === 'other') {
            return $fail('This video uses a format that does not play everywhere. Please export it as MP4 (H.264) and try again.', 'not h264');
        }
    }
    if (!empty($c['max_seconds'])) {
        $secs = aw_upload_duration($tmp, $mime);
        if ($secs !== null && $secs > $c['max_seconds'] + 2) {
            $mins = $c['max_seconds'] / 60;
            return $fail('That ' . $label . ' is ' . (int)floor($secs / 60) . ':' . str_pad((string)((int)round($secs) % 60), 2, '0', STR_PAD_LEFT) . ' long. The limit is '
                        . (floor($mins) == $mins ? (int)$mins : round($mins, 1)) . ' minute' . ($mins == 1 ? '' : 's') . '.', 'too long: ' . round($secs) . 's');
        }
    }

    // 5. destination
    $dirRel = trim(str_replace('\\', '/', $dirRel), '/');
    if ($dirRel === '' || strpos($dirRel, '..') !== false) return $fail('The ' . $label . ' could not be saved.', 'bad destination');
    $dirAbs = dirname(__DIR__) . '/' . $dirRel;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
        return $fail('The ' . $label . ' could not be saved. Please try again.', 'cannot create folder');
    }

    // 6. images: resize / re-encode / strip EXIF (GD), or refuse what is over the limit (no GD)
    $work = $tmp; $processed = false; $made = null;
    if ($c['is_image']) {
        if (aw_upload_can_process_images()) {
            $r = aw_upload_process_image($tmp, $mime, $c, $dirAbs);
            if (!$r[0]) return $fail($r[1], 'image processing: ' . $r[1]);
            [, $made, $ext, $mime] = $r;
            $work = $made; $processed = true;
        } else {
            if (@getimagesize($tmp) === false) return $fail('That file is not an image we can read.', 'not an image');
            if ($size > $c['stored_bytes']) {
                return $fail('That ' . $label . ' is ' . aw_upload_mb($size) . '. This server cannot resize images, so the limit is '
                            . aw_upload_mb($c['stored_bytes']) . '. Please make it smaller first.', 'too large, no image tools');
            }
        }
    }
    clearstatcache(true, $work);
    $finalBytes = (int)filesize($work);
    if ($finalBytes > $c['stored_bytes']) {
        if ($made) @unlink($made);
        return $fail('That ' . $label . ' is too large. The limit is ' . aw_upload_mb($c['stored_bytes']) . '.', 'too large after processing');
    }

    // 7. quota
    $countQuota = function_exists('aw_upload_quota_check') && ($opts['count_quota'] ?? true) && $uid !== null && $db instanceof PDO;
    if ($countQuota) {
        try {
            $q = aw_upload_quota_check($db, (int)$uid, $role, $size, $finalBytes);
        } catch (Throwable $e) { error_log('upload quota check: ' . $e->getMessage()); $q = ''; }
        if ($q !== '') { if ($made) @unlink($made); return $fail($q, 'quota'); }
    }

    // 8. store under a name made here
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', (string)($opts['prefix'] ?? ''));
    $fname = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dirAbs . '/' . $fname;
    if ($made) { $okMove = @rename($made, $dest); if (!$okMove) { $okMove = @copy($made, $dest); @unlink($made); } }
    elseif ($isUpload) { $okMove = @move_uploaded_file($tmp, $dest); }
    else { $okMove = @copy($tmp, $dest); }
    if (!$okMove) { @unlink($dest); return $fail('The ' . $label . ' could not be saved. Please try again.', 'move failed'); }
    @chmod($dest, 0644);

    if ($countQuota) {
        try { aw_upload_usage_add($db, (int)$uid, $size, $finalBytes); } catch (Throwable $e) { error_log('upload usage: ' . $e->getMessage()); }
    }
    return ['ok' => true, 'path' => $dirRel . '/' . $fname, 'bytes' => $finalBytes, 'mime' => $mime, 'ext' => $ext, 'processed' => $processed, 'original_bytes' => $size];
}
