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
    $base = aw_share_base();
    if ($base !== '') return $base . ($type === 'book' ? '/b/' : '/p/') . (int)$id;
    return rtrim(SITE_URL, '/') . ($type === 'book' ? '/book.php?id=' : '/poem_view.php?id=') . (int)$id;
}

/** The relay address from Settings, or '' when none (or not a plain https address). */
function aw_share_base() {
    $base = function_exists('aw_setting') ? rtrim((string)aw_setting('share_base_url', ''), '/') : '';
    return ($base !== '' && preg_match('#^https://[a-z0-9.-]+(/[a-z0-9._/-]*)?$#i', $base)) ? $base : '';
}

/** Pages the relay will preview (keep in step with PAGES in tools/share-relay/worker.js). */
const AW_SHARE_PAGES = ['index.php', 'about.php', 'poem_view.php', 'poetry.php', 'book.php', 'books.php', 'blog.php', 'blog_post.php',
    'reflections.php', 'video_watch.php', 'videos.php', 'events.php', 'plans.php', 'reading_plan.php', 'prayer.php',
    'prompts.php', 'bible_reader.php', 'contact.php', 'newsletter.php', 'community_guidelines.php'];

/**
 * The link to share for any public page, given its site path ("blog_post.php?slug=x", "" for home).
 * Through the relay when one is set up and the page is on its list; otherwise the page itself.
 */
function aw_share_link($path) {
    $path = ltrim((string)$path, '/');
    $direct = rtrim(SITE_URL, '/') . '/' . $path;
    $base = aw_share_base();
    if ($base === '') return $direct;
    $file = (string)strtok($path, '?');
    $q = (string)parse_url('/' . $path, PHP_URL_QUERY);
    if ($file === '' || $file === 'index.php') return $q === '' ? $base . '/' : $direct;
    if (!in_array($file, AW_SHARE_PAGES, true)) return $direct;
    if ($file === 'poem_view.php' && preg_match('/^id=(\d+)$/', $q, $m)) return $base . '/p/' . $m[1];
    if ($file === 'book.php' && preg_match('/^id=(\d+)$/', $q, $m)) return $base . '/b/' . $m[1];
    if ($q !== '' && !preg_match('/^[A-Za-z0-9=&._%-]{1,200}$/', $q)) return $direct;
    return $base . '/s/' . $file . ($q !== '' ? '?' . $q : '');
}

/** The share link for the page being shown now (no preview flags or tracking). */
function aw_share_link_here() {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $site_path = (string)parse_url(SITE_URL, PHP_URL_PATH);
    $path = (string)parse_url($uri, PHP_URL_PATH);
    if ($site_path !== '' && strpos($path, $site_path) === 0) $path = substr($path, strlen($site_path));
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $qs);
    foreach (['preview', 'utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'aw_t'] as $k) unset($qs[$k]);
    $q = http_build_query($qs);
    return aw_share_link(ltrim($path, '/') . ($q !== '' ? '?' . $q : ''));
}

/** A plain one-line description for previews (no tags, no line breaks), at most $len characters. */
function aw_og_text($html, $len = 180) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string)$html)), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}
