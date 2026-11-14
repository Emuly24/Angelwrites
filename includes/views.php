<?php
/**
 * One way to count views (poems, books, reflections/blog posts, videos, questions, stories).
 *
 * Before, each page added 1 to its counter on every load, including refreshes, the team's own visits
 * and link-preview robots (WhatsApp, Facebook, Google fetch a page when a link is shared), books were
 * counted again when the reader opened, and the dashboard's view log was a separate list. So the
 * numbers never matched. Now a view is counted here only when:
 *   - it is a normal page load (GET) by a person, not a robot or link-preview fetcher;
 *   - the viewer is not staff (owner, admins, moderators);
 *   - the same person has not viewed the same item in the last 30 minutes;
 * and then the item's counter and the view log are written together, so they agree from now on.
 * Guests' addresses are stored shortened (the last part removed), enough to tell visits apart.
 * Nothing here throws.
 */

const AW_VIEW_TARGETS = [
    'poem'     => ['poems', 'view_count'],
    'book'     => ['books', 'view_count'],
    'post'     => ['blog_posts', 'views'],
    'video'    => ['videos', 'view_count'],
    'question' => ['questions', 'views'],
    'story'    => [null, null], // logged only
];

function aw_view_is_bot($ua = null) {
    $ua = strtolower((string)($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '') return true;
    return (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|meta-external|whatsapp|telegram|twitter|linkedin|discord|slack|skype|embedly|pinterest|vkshare|w3c_validator|curl|wget|python-requests|httpclient|headless|lighthouse|monitor|uptime/i', $ua);
}

/** A shortened address: 197.211.45.12 -> 197.211.45.0; IPv6 keeps the first 3 groups. */
function aw_view_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.0', $ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
    return '';
}

function aw_view_log_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS view_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
                   user_id INTEGER, ip_address TEXT, viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_view_logs_when ON view_logs (viewed_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_view_logs_target ON view_logs (target_type, target_id, viewed_at)");
        return $ok = true;
    } catch (Throwable $e) { error_log('view_logs table: ' . $e->getMessage()); return $ok = false; }
}

/** Count a view if it is a real one. Returns true when counted. */
function aw_view_record(PDO $db, $type, $id) {
    $id = (int)$id;
    if (!$id || !isset(AW_VIEW_TARGETS[$type])) return false;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || aw_view_is_bot()) return false;
    if (function_exists('aw_is_staff') && aw_is_staff()) return false;
    if (!empty($_GET['preview'])) return false;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $key = $type . ':' . $id;
    $now = time();
    if (isset($_SESSION['aw_viewed'][$key]) && $now - (int)$_SESSION['aw_viewed'][$key] < 1800) return false;
    if (!aw_view_log_table($db)) return false;
    $ip = aw_view_ip();
    try {
        // the same person (or, for guests without a session, the same address) within 30 minutes
        if ($uid) {
            $st = $db->prepare("SELECT 1 FROM view_logs WHERE target_type = ? AND target_id = ? AND user_id = ? AND viewed_at >= datetime('now', '-30 minutes') LIMIT 1");
            $st->execute([$type, $id, $uid]);
        } else {
            $st = $db->prepare("SELECT 1 FROM view_logs WHERE target_type = ? AND target_id = ? AND user_id IS NULL AND ip_address = ? AND viewed_at >= datetime('now', '-30 minutes') LIMIT 1");
            $st->execute([$type, $id, $ip]);
        }
        $_SESSION['aw_viewed'][$key] = $now;
        if (count($_SESSION['aw_viewed']) > 200) $_SESSION['aw_viewed'] = array_slice($_SESSION['aw_viewed'], -100, null, true);
        if ($st->fetchColumn()) return false;
        [$table, $col] = AW_VIEW_TARGETS[$type];
        if ($table) $db->prepare("UPDATE $table SET $col = COALESCE($col, 0) + 1 WHERE id = ?")->execute([$id]);
        $db->prepare("INSERT INTO view_logs (target_type, target_id, user_id, ip_address) VALUES (?, ?, ?, ?)")->execute([$type, $id, $uid ?: null, $ip]);
        return true;
    } catch (Throwable $e) {
        error_log('view record: ' . $e->getMessage());
        return false;
    }
}
