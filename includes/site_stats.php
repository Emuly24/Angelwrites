<?php
/**
 * Simple, privacy-friendly statistics.
 *
 * Only daily totals are kept: how many pages were read, how many visits started, which pages, where
 * readers came from (the other website's name only), what kind of device (phone, tablet, computer),
 * the browser's language, and what people searched for. Nothing is stored about the person: no IP
 * address, no account, no tracking cookie, no outside service. Robots, link previews and the team's
 * own visits are not counted. Totals older than 400 days are removed.
 *
 *   site_stats(day, dim, val, n)   e.g. ('2026-11-20', 'page', '/poetry.php', 14)
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_STATS_DIMS = ['view', 'visit', 'page', 'ref', 'device', 'lang', 'search', 'search0'];

function aw_stats_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS site_stats (day TEXT NOT NULL, dim TEXT NOT NULL, val TEXT NOT NULL DEFAULT '', n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, dim, val))");
        return $ok = true;
    } catch (Throwable $e) { error_log('site_stats table: ' . $e->getMessage()); return $ok = false; }
}

/** Add to today's totals: [[dim, val], ...]. */
function aw_stats_add(PDO $db, array $pairs) {
    if (!$pairs || !aw_stats_table($db)) return false;
    $day = date('Y-m-d');
    try {
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        $st = $db->prepare("INSERT INTO site_stats (day, dim, val, n) VALUES (?, ?, ?, 1) ON CONFLICT(day, dim, val) DO UPDATE SET n = n + 1");
        foreach ($pairs as [$dim, $val]) {
            if (!in_array($dim, AW_STATS_DIMS, true)) continue;
            $st->execute([$day, $dim, mb_substr((string)$val, 0, 120)]);
        }
        if ($own) $db->commit();
        // tidy once in a while (about one page in 500)
        if (mt_rand(1, 500) === 1) $db->prepare("DELETE FROM site_stats WHERE day < ?")->execute([date('Y-m-d', strtotime('-400 days'))]);
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $e2) {} }
        error_log('site_stats add: ' . $e->getMessage());
        return false;
    }
}

/** Should this request be counted? A person (not a robot), a normal page load, not the team. */
function aw_stats_countable() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    if (function_exists('aw_view_is_bot') ? aw_view_is_bot() : preg_match('/bot|crawl|spider|preview/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) return false;
    if (function_exists('aw_is_staff') && aw_is_staff()) return false;
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('#^/(admin|api|reader/reader_|ajax|_h)/#', $path) || preg_match('#ajax|_api\.php$#', $path)) return false;
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_SERVER['HTTP_SEC_PURPOSE']) || (($_SERVER['HTTP_PURPOSE'] ?? '') === 'prefetch')) return false;
    return true;
}

/** Called once at the end of a public page (footer). */
function aw_stats_record() {
    static $done = false;
    if ($done) return false;
    $done = true;
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO || !aw_stats_countable()) return false;
    if (function_exists('aw_setting') && (string)aw_setting('stats_enabled', '1') === '0') return false;

    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = $path === '/' ? '/index.php' : $path;
    $pairs = [['view', ''], ['page', $path]];

    // a visit = the first page of a browser session (the session already exists for the site; nothing extra is stored)
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['aw_stats_seen'])) {
        $_SESSION['aw_stats_seen'] = 1;
        $pairs[] = ['visit', ''];
        // where they came from: only the other website's name
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
        $own = strtolower((string)parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);
        if ($host === '' || $host === $own) $pairs[] = ['ref', '(typed or bookmarked)'];
        else $pairs[] = ['ref', aw_stats_ref_name($host)];
        $pairs[] = ['device', aw_stats_device((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))];
        $lang = strtolower(substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 2));
        $pairs[] = ['lang', preg_match('/^[a-z]{2}$/', $lang) ? $lang : '??'];
    }
    return aw_stats_add($db, $pairs);
}

/** Group the big sites under one name (facebook.com, l.facebook.com, fb.me -> Facebook). */
function aw_stats_ref_name($host) {
    // matched on whole domain names (so ft.com is not "t.co")
    $map = ['Email' => 'mail\.google\.com|outlook\.[a-z.]+|mail\.yahoo\.com|mail\.zoho\.[a-z]+|zohomail\.[a-z]+', 'Facebook' => 'facebook\.com|fb\.com|fb\.me|messenger\.com', 'Instagram' => 'instagram\.com', 'WhatsApp' => 'whatsapp\.com|whatsapp\.net|wa\.me',
            'X (Twitter)' => 't\.co|twitter\.com|x\.com', 'TikTok' => 'tiktok\.com', 'YouTube' => 'youtube\.com|youtu\.be', 'Google' => 'google\.[a-z.]+|googleusercontent\.com',
            'Bing' => 'bing\.com', 'DuckDuckGo' => 'duckduckgo\.com', 'Yahoo' => 'yahoo\.[a-z.]+', 'LinkedIn' => 'linkedin\.com|lnkd\.in', 'Telegram' => 't\.me|telegram\.org|telegram\.me',
            'Pinterest' => 'pinterest\.[a-z.]+', 'Reddit' => 'reddit\.com'];
    foreach ($map as $name => $re) if (preg_match('/(^|\.)(' . $re . ')$/', $host)) return $name;
    return $host;
}

function aw_stats_device($ua) {
    $ua = strtolower($ua);
    if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/', $ua)) return 'Tablet';
    if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile|kaios/', $ua)) return 'Phone';
    return 'Computer';
}

/** What someone searched for (search_results.php), and whether anything was found. */
function aw_stats_search($q, $found) {
    $db = $GLOBALS['db'] ?? null;
    $q = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$q)));
    if (!$db instanceof PDO || $q === '' || mb_strlen($q) > 80 || !aw_stats_countable()) return false;
    if (function_exists('aw_setting') && (string)aw_setting('stats_enabled', '1') === '0') return false;
    // an email address or a long number is personal: never kept
    if (preg_match('/@|\d{6,}/', $q)) return false;
    return aw_stats_add($db, [[$found ? 'search' : 'search0', $q]]);
}

/** Totals for a dimension over the last $days: [val => n], largest first. */
function aw_stats_top(PDO $db, $dim, $days, $limit = 10) {
    if (!aw_stats_table($db)) return [];
    try {
        $st = $db->prepare("SELECT val, SUM(n) AS n FROM site_stats WHERE dim = ? AND day >= ? GROUP BY val ORDER BY n DESC LIMIT " . (int)$limit);
        $st->execute([$dim, date('Y-m-d', strtotime('-' . ((int)$days - 1) . ' days'))]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['val']] = (int)$r['n'];
        return $out;
    } catch (Throwable $e) { error_log('site_stats top: ' . $e->getMessage()); return []; }
}

/** Daily totals for a dimension (all values): [Y-m-d => n] for each of the last $days days. */
function aw_stats_daily(PDO $db, $dim, $days) {
    $out = [];
    for ($i = (int)$days - 1; $i >= 0; $i--) $out[date('Y-m-d', strtotime("-$i days"))] = 0;
    if (!aw_stats_table($db)) return $out;
    try {
        $st = $db->prepare("SELECT day, SUM(n) AS n FROM site_stats WHERE dim = ? AND day >= ? GROUP BY day");
        $st->execute([$dim, array_key_first($out)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($out[$r['day']])) $out[$r['day']] = (int)$r['n'];
    } catch (Throwable $e) { error_log('site_stats daily: ' . $e->getMessage()); }
    return $out;
}
