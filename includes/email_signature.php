<?php
/**
 * Angella's handwritten signature in emails.
 *
 * She signs with a finger or stylus on the pad in Admin > Settings > Branding, or uploads a photo of
 * her signature. The image is cleaned (re-encoded, metadata dropped, a paper background made
 * transparent, cropped to the ink, at most 480 x 160) and kept in data/ (not reachable from the web,
 * not touched by deploys). Emails carry it inside the message (Content-ID "awsign"), like the logo, so
 * nothing is fetched from the site. It appears in the signature block above her typed name.
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_SIGN_MARK = '<!--aw-sign-img-->';

function aw_signature_dir() { return dirname(__DIR__) . '/data/signature'; }

/** The signature image file, or '' when there is none. */
function aw_signature_file() {
    $f = aw_signature_dir() . '/email_signature.png';
    return is_file($f) ? $f : '';
}

/** Clean and save a signature from a data: URI (the pad) or an uploaded file's bytes. Returns '' or an error message. */
function aw_signature_save($bytes) {
    if (!function_exists('imagecreatefromstring')) return 'This server cannot process pictures (GD is missing).';
    if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', (string)$bytes)) $bytes = base64_decode(substr((string)$bytes, strpos((string)$bytes, ',') + 1), true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) return 'Please use a picture smaller than 4 MB.';
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return 'Please use a PNG, JPEG or WebP picture.';
    $src = @imagecreatefromstring($bytes);
    if (!$src) return 'That picture could not be read.';
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 20 || $h < 10 || $w * $h > 25000000) { imagedestroy($src); return 'That picture is too small or too large.'; }
    // a phone photo is large: work on a copy at most 1400 pixels wide (plenty for a 480-pixel signature)
    if ($w > 1400) {
        $nw = 1400; $nh = max(1, (int)round($h * 1400 / $w));
        $small = imagecreatetruecolor($nw, $nh);
        imagealphablending($small, false); imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
        imagecopyresampled($small, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src); $src = $small; $w = $nw; $h = $nh;
    }
    $true = imageistruecolor($src);
    // ink only: transparent where the pixel is transparent or nearly white (paper), and crop to the ink
    $ink = imagecreatetruecolor($w, $h);
    imagesavealpha($ink, true); imagealphablending($ink, false);
    imagefill($ink, 0, 0, imagecolorallocatealpha($ink, 0, 0, 0, 127));
    $minx = $w; $miny = $h; $maxx = -1; $maxy = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            $a = ($c >> 24) & 0x7F; $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            if (!$true) { $rgba = imagecolorsforindex($src, $c); $a = $rgba['alpha']; $r = $rgba['red']; $g = $rgba['green']; $b = $rgba['blue']; }
            $light = ($r + $g + $b) / 3;
            if ($a > 100 || $light > 215) continue; // paper or transparent
            // darker ink stays more opaque; light grey edges fade, so photos of paper look clean
            $alpha = (int)max(0, min(127, $a + ($light > 150 ? ($light - 150) * 1.6 : 0)));
            imagesetpixel($ink, $x, $y, imagecolorallocatealpha($ink, $r, $g, $b, $alpha));
            if ($x < $minx) $minx = $x; if ($x > $maxx) $maxx = $x; if ($y < $miny) $miny = $y; if ($y > $maxy) $maxy = $y;
        }
    }
    imagedestroy($src);
    if ($maxx < 0) { imagedestroy($ink); return 'No signature was found in that picture. Please sign or try a clearer photo.'; }
    $pad = 6;
    $minx = max(0, $minx - $pad); $miny = max(0, $miny - $pad); $maxx = min($w - 1, $maxx + $pad); $maxy = min($h - 1, $maxy + $pad);
    $cw = $maxx - $minx + 1; $ch = $maxy - $miny + 1;
    $scale = min(1, 480 / $cw, 160 / $ch);
    $ow = max(1, (int)round($cw * $scale)); $oh = max(1, (int)round($ch * $scale));
    $out = imagecreatetruecolor($ow, $oh);
    imagesavealpha($out, true); imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $ink, 0, 0, $minx, $miny, $ow, $oh, $cw, $ch);
    imagedestroy($ink);
    $dir = aw_signature_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { imagedestroy($out); return 'The signature could not be saved (folder).'; }
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    $ok = @imagepng($out, $dir . '/email_signature.png', 9);
    imagedestroy($out);
    if (!$ok) return 'The signature could not be saved.';
    if (function_exists('aw_audit')) aw_audit('settings_changed', 'settings', null, 'email signature saved');
    return '';
}

function aw_signature_remove() {
    $f = aw_signature_file();
    if ($f !== '' && !@unlink($f)) return false;
    if (function_exists('aw_audit')) aw_audit('settings_changed', 'settings', null, 'email signature removed');
    return true;
}

/** For a preview on the settings page. */
function aw_signature_data_uri() {
    $f = aw_signature_file();
    return $f !== '' ? 'data:image/png;base64,' . base64_encode((string)@file_get_contents($f)) : '';
}

/**
 * Put the signature into an email: replaces the template's marker with the image and embeds the file.
 * Returns the HTML to send (unchanged when there is no signature or no marker).
 */
function aw_signature_apply($mail, $html) {
    $html = (string)$html;
    $f = aw_signature_file();
    if ($f === '' || strpos($html, AW_SIGN_MARK) === false) return $html;
    try {
        if (!$mail->addEmbeddedImage($f, 'awsign', 'signature.png')) return $html;
    } catch (Throwable $e) { error_log('signature embed: ' . $e->getMessage()); return $html; }
    return str_replace(AW_SIGN_MARK, '<p style="margin:4px 0 0;"><img src="cid:awsign" alt="Angella Bottoman" width="200" style="display:block;width:200px;max-width:70%;height:auto;border:0;"></p>', $html);
}
