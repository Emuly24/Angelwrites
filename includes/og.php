<?php
/**
 * Link previews (Open Graph). One place that knows a poem's or book's preview image and the link to share.
 *
 * Why previews were plain links (measured on the live site, 14 Nov 2026):
 *  - The free host shows a JavaScript "browser check" page to every visitor it does not recognise.
 *    Facebook's and Google's crawlers are let through; WhatsApp, Telegram, X, LinkedIn, Discord and
 *    Slack are not, so they never see the title or the picture. Code on the site cannot change that;
 *    see tools/share-relay/README.md (a free relay) or move to hosting without the check.
 *  - Even for Facebook the image was a WebP with an address that changed on every visit, and book
 *    pages had no cover in their preview at all.
 * This file gives every poem and book a 1200x630 JPEG (og_image.php) at a stable address that only
 * changes when the cover changes, and a share link that can go through the relay when one is set up.
 */

/** The cover file behind a poem or book: ['path' => absolute file, 'mtime' => int] or null. */
function aw_og_source(PDO $db, $type, $id) {
    try {
        if ($type === 'book') {
            $st = $db->prepare("SELECT cover_path FROM books WHERE id = ?");
        } else {
            $st = $db->prepare("SELECT COALESCE(NULLIF(cover_image, ''), image_path) FROM poems WHERE id = ?");
        }
        $st->execute([(int)$id]);
        $rel = trim((string)$st->fetchColumn());
        if ($rel === '' || preg_match('#^https?://#i', $rel)) return null;
        $root = realpath(dirname(__DIR__) . '/assets');
        $path = realpath(dirname(__DIR__) . '/' . ltrim($rel, '/'));
        if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) return null; // only files inside assets/
        return ['path' => $path, 'mtime' => (int)@filemtime($path)];
    } catch (Throwable $e) {
        error_log('og source: ' . $e->getMessage());
        return null;
    }
}

/** The preview image address: a stable JPEG that changes only when the cover changes. */
function aw_og_image_url(PDO $db, $type, $id) {
    $type = $type === 'book' ? 'book' : 'poem';
    $src = aw_og_source($db, $type, $id);
    if (!$src) return rtrim(SITE_URL, '/') . '/assets/images/og-logo.jpg';
    $v = substr(md5($src['path'] . '|' . $src['mtime']), 0, 10);
    return rtrim(SITE_URL, '/') . '/og_image.php?t=' . $type . '&id=' . (int)$id . '&v=' . $v;
}

/**
 * The link to share. Normally the page itself. When the free preview relay is set up
 * (Admin → Settings → share_base_url, e.g. https://share.example.workers.dev), links go through it so
 * WhatsApp and the others can show the picture; people are sent straight on to the page.
 */
function aw_share_url($type, $id) {
    $base = function_exists('aw_setting') ? rtrim((string)aw_setting('share_base_url', ''), '/') : '';
    if ($base !== '' && preg_match('#^https://[a-z0-9.-]+(/[a-z0-9._/-]*)?$#i', $base)) return $base . ($type === 'book' ? '/b/' : '/p/') . (int)$id;
    return rtrim(SITE_URL, '/') . ($type === 'book' ? '/book.php?id=' : '/poem_view.php?id=') . (int)$id;
}

/** A plain one-line description for previews (no tags, no line breaks), at most $len characters. */
function aw_og_text($html, $len = 180) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string)$html)), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}
