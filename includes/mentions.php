<?php
/**
 * N2: @mentions as one shared function (PROGRAM_PLAN.md Phase 2).
 *
 * Typed as "@Display Name" (or "@username"). At save time each mention is resolved on the server to a
 * user id and stored (mentions table), so a later rename does not break it, and each mentioned
 * person is notified exactly once per piece of writing. On display, stored mentions are highlighted.
 *
 * Pseudonym-safe: the notification names the writer with $actor_label, which the caller sets to the
 * pseudonym for a pseudonymous post. A real name behind a pseudonym is never used here.
 */
require_once __DIR__ . '/notifications.php';

function aw_mentions_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS mentions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type TEXT NOT NULL, source_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source_type, source_id, user_id))");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('mentions table: ' . $e->getMessage());
        return $ok = false;
    }
}

/** The members a piece of text mentions: [user_id => display name]. Longest names are matched first. */
function aw_mentions_find(PDO $db, $text) {
    $text = (string)$text;
    if (strpos($text, '@') === false) return [];
    $found = [];
    try {
        $users = $db->query("SELECT id, name, first_name, last_name, username FROM users")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('mentions find: ' . $e->getMessage());
        return [];
    }
    $cands = [];
    foreach ($users as $u) {
        $dn = awDisplayName($u);
        if ($dn !== 'Friend') $cands[] = [mb_strlen($dn), $dn, (int)$u['id']];
        if (!empty($u['username']) && $u['username'] !== $dn) $cands[] = [mb_strlen($u['username']), (string)$u['username'], (int)$u['id']];
    }
    usort($cands, function ($a, $b) { return $b[0] <=> $a[0]; });
    $work = $text;
    foreach ($cands as [, $name, $id]) {
        $re = '/(^|[^\p{L}\p{N}_])@' . preg_quote($name, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($re, $work)) {
            if (!isset($found[$id])) $found[$id] = $name;
            $work = preg_replace($re, '$1', $work); // so "@Ann" is not matched again inside "@Ann Lee"
        }
    }
    return $found;
}

/**
 * Store and notify. $source_type/$source_id identify the writing (e.g. 'review', 42); $title is what
 * it is about ("the poem “Grace”"); $link opens it; $actor_label names the writer as shown on the page.
 * Returns the ids newly notified.
 */
function aw_mentions_save(PDO $db, $text, $source_type, $source_id, $title, $link, $actor_label) {
    if (!aw_mentions_table($db)) return [];
    $me = (int)($_SESSION['user_id'] ?? 0);
    $done = [];
    foreach (aw_mentions_find($db, $text) as $uid => $name) {
        if ($uid === $me) continue;
        if (function_exists('aw_is_blocked') && aw_is_blocked($db, $uid, $me)) continue; // N4: they blocked the writer
        try {
            $st = $db->prepare("INSERT OR IGNORE INTO mentions (source_type, source_id, user_id) VALUES (?, ?, ?)");
            $st->execute([(string)$source_type, (int)$source_id, $uid]);
            if ($st->rowCount() === 0) continue; // already notified for this writing
            awNotify($db, $uid, 'mention', $actor_label . ' mentioned you', 'On ' . $title . '.', $link);
            $done[] = $uid;
        } catch (Throwable $e) {
            error_log('mentions save: ' . $e->getMessage());
        }
    }
    return $done;
}

/**
 * Highlight stored mentions in text that is ALREADY escaped for HTML. Only names actually stored for
 * this writing are highlighted, so typing "@" before any word changes nothing.
 */
function aw_mentions_render(PDO $db, $escaped, $source_type, $source_id) {
    $escaped = (string)$escaped;
    if (strpos($escaped, '@') === false || !aw_mentions_table($db)) return $escaped;
    try {
        $st = $db->prepare("SELECT u.id, u.name, u.first_name, u.last_name, u.username FROM mentions m JOIN users u ON u.id = m.user_id WHERE m.source_type = ? AND m.source_id = ?");
        $st->execute([(string)$source_type, (int)$source_id]);
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) { $names[] = awDisplayName($u); if (!empty($u['username'])) $names[] = (string)$u['username']; }
        usort($names, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        foreach (array_unique($names) as $n) {
            $e = htmlspecialchars($n, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/(^|[^\p{L}\p{N}_>])@' . preg_quote($e, '/') . '(?![\p{L}\p{N}_])/iu', '$1<span class="aw-mention">@' . $e . '</span>', $escaped);
        }
    } catch (Throwable $e) {
        error_log('mentions render: ' . $e->getMessage());
    }
    return $escaped;
}
