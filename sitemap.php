<?php
/**
 * sitemap.xml (served from here by .htaccess): every public page search engines should know about,
 * so Google, Bing (and through Bing: DuckDuckGo, Yahoo, Ecosia) can find each poem, book, post,
 * reflection, video and event. Built from the database on each request; nothing private is listed
 * (no member pages, no admin, no Stories & Help). Fails soft: a missing table just leaves its part out.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex'); // the sitemap itself is not a page
$base = rtrim(SITE_URL, '/');
$urls = [];
$add = function ($path, $mod = null, $freq = 'weekly', $prio = '0.6') use (&$urls, $base) {
    $urls[] = ['loc' => $base . '/' . ltrim($path, '/'), 'mod' => $mod ? date('Y-m-d', strtotime((string)$mod)) : null, 'freq' => $freq, 'prio' => $prio];
};
$rows = function ($sql) use ($db) { try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };

// the main pages
$add('', null, 'daily', '1.0');
foreach (['books.php' => '0.9', 'poetry.php' => '0.9', 'reflections.php' => '0.8', 'blog.php' => '0.8', 'videos.php' => '0.7', 'bible_reader.php' => '0.7',
          'plans.php' => '0.7', 'prayer.php' => '0.6', 'events.php' => '0.6', 'prompts.php' => '0.6', 'community.php' => '0.6', 'about.php' => '0.7',
          'contact.php' => '0.5', 'newsletter.php' => '0.4', 'privacy.php' => '0.2', 'terms.php' => '0.2', 'community_guidelines.php' => '0.3', 'accessibility.php' => '0.2'] as $p => $pr) {
    if (is_file(__DIR__ . '/' . $p)) $add($p, null, 'weekly', $pr);
}
// each item
foreach ($rows("SELECT id, COALESCE(updated_at, created_at) AS m FROM poems ORDER BY id DESC LIMIT 5000") as $r) $add('poem_view.php?id=' . (int)$r['id'], $r['m'], 'monthly', '0.8');
foreach ($rows("SELECT id, COALESCE(updated_at, created_at) AS m FROM books ORDER BY id DESC LIMIT 5000") as $r) $add('book.php?id=' . (int)$r['id'], $r['m'], 'monthly', '0.8');
foreach ($rows("SELECT slug, COALESCE(updated_at, published_at, created_at) AS m FROM blog_posts WHERE status = 'published' AND slug <> '' ORDER BY id DESC LIMIT 5000") as $r) $add('blog_post.php?slug=' . rawurlencode($r['slug']), $r['m'], 'monthly', '0.7');
foreach ($rows("SELECT id, COALESCE(updated_at, created_at) AS m FROM videos ORDER BY id DESC LIMIT 2000") as $r) $add('video_watch.php?id=' . (int)$r['id'], $r['m'], 'monthly', '0.6');
foreach ($rows("SELECT id, COALESCE(updated_at, created_at) AS m FROM events WHERE status = 'published' ORDER BY id DESC LIMIT 500") as $r) $add('events.php?id=' . (int)$r['id'], $r['m'], 'weekly', '0.5');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$seen = [];
foreach ($urls as $u) {
    if (isset($seen[$u['loc']])) continue;
    $seen[$u['loc']] = true;
    echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>' . ($u['mod'] ? '<lastmod>' . $u['mod'] . '</lastmod>' : '')
       . '<changefreq>' . $u['freq'] . '</changefreq><priority>' . $u['prio'] . "</priority></url>\n";
}
echo "</urlset>\n";
