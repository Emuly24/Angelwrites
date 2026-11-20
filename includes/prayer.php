<?php
/**
 * The prayer wall: members ask for prayer, others pray and say so with one tap, and the person is
 * told, gently. Built on the existing prayer_requests table (requests sent from posts and the reader
 * stay team-only, as before).
 *
 *  visibility  'public' (on the wall) or 'team' (only the AngelWrites team)
 *  anonymous   1 = shown as "A member" (the owner and admins can still see who; moderators cannot)
 *  status      'pending' (open), 'prayed' (the team marked it), 'answered', 'removed' (moderation)
 *  renewed_at  a public request rests after 30 days unless its author says "Still praying"
 *
 * Notifications: the author hears when the first person prays and at 5, 10, 25, 50, 100, and when
 * someone leaves a short encouragement; never more than that.
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_PRAYER_CATEGORIES = ['health' => 'Health and healing', 'family' => 'Family and relationships', 'work' => 'Work, school and money',
    'faith' => 'Faith and guidance', 'grief' => 'Grief and comfort', 'peace' => 'Peace of mind', 'thanks' => 'Thanksgiving', 'other' => 'Something else'];
const AW_PRAYER_DAYS = 30;          // a public request stays on the wall this long unless renewed
const AW_PRAYER_LIMIT_DAY = 3;      // new requests per member per day
const AW_PRAYER_MILESTONES = [1, 5, 10, 25, 50, 100, 250];

function aw_prayer_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $cols = [];
        foreach ($db->query("PRAGMA table_info(prayer_requests)") as $c) $cols[$c['name']] = true;
        if (!$cols) {
            $db->exec("CREATE TABLE IF NOT EXISTS prayer_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                       reflection_id INTEGER, request_type TEXT NOT NULL DEFAULT 'prayer', message TEXT, status TEXT DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                       book_id INTEGER, request_text TEXT)");
            $cols = ['id' => true];
        }
        foreach (['title' => 'TEXT', 'category' => 'TEXT', 'visibility' => "TEXT NOT NULL DEFAULT 'team'", 'anonymous' => 'INTEGER NOT NULL DEFAULT 0',
                  'prayer_count' => 'INTEGER NOT NULL DEFAULT 0', 'answered_at' => 'DATETIME', 'praise_note' => 'TEXT', 'renewed_at' => 'DATETIME', 'updated_at' => 'DATETIME'] as $c => $def) {
            if (!isset($cols[$c])) $db->exec("ALTER TABLE prayer_requests ADD COLUMN $c $def");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS prayer_prayed (request_id INTEGER NOT NULL REFERENCES prayer_requests(id) ON DELETE CASCADE,
                   user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (request_id, user_id))");
        $db->exec("CREATE TABLE IF NOT EXISTS prayer_updates (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INTEGER NOT NULL REFERENCES prayer_requests(id) ON DELETE CASCADE,
                   user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, kind TEXT NOT NULL DEFAULT 'update', body TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_prayer_wall ON prayer_requests(visibility, status, created_at)");
        return $ok = true;
    } catch (Throwable $e) { error_log('prayer tables: ' . $e->getMessage()); return $ok = false; }
}

/** The name shown for a request: the member's name, or "A member" when they asked to stay anonymous. */
function aw_prayer_name(array $r, $viewer_can_see = false) {
    if (!empty($r['anonymous']) && !$viewer_can_see) return 'A member';
    $n = function_exists('awDisplayName') ? awDisplayName($r) : (string)($r['username'] ?? 'A member');
    return (!empty($r['anonymous']) ? $n . ' (anonymous to others)' : $n);
}

/** Public requests on the wall. $filter: '' all open, 'answered', 'mine' (needs $uid), or a category key. */
function aw_prayer_wall(PDO $db, $uid, $filter = '', $limit = 40, $offset = 0) {
    if (!aw_prayer_tables($db)) return [];
    $where = "p.visibility = 'public' AND p.status <> 'removed'";
    $args = [];
    if ($filter === 'answered') $where .= " AND p.status = 'answered'";
    elseif ($filter === 'mine') { $where = "p.user_id = ? AND p.status <> 'removed' AND (p.visibility = 'public' OR p.title IS NOT NULL)"; $args[] = (int)$uid; }
    else {
        $where .= " AND p.status <> 'answered' AND COALESCE(p.renewed_at, p.created_at) >= datetime('now', '-" . AW_PRAYER_DAYS . " days')";
        if (isset(AW_PRAYER_CATEGORIES[$filter])) { $where .= " AND p.category = ?"; $args[] = $filter; }
    }
    try {
        $st = $db->prepare("SELECT p.*, u.name, u.first_name, u.last_name, u.username,
                                   (SELECT 1 FROM prayer_prayed x WHERE x.request_id = p.id AND x.user_id = ?) AS i_prayed
                            FROM prayer_requests p LEFT JOIN users u ON u.id = p.user_id
                            WHERE $where ORDER BY " . ($filter === 'answered' ? 'p.answered_at' : 'COALESCE(p.renewed_at, p.created_at)') . " DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $st->execute(array_merge([(int)$uid], $args));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('prayer wall: ' . $e->getMessage()); return []; }
}

function aw_prayer_get(PDO $db, $id) {
    if (!aw_prayer_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT p.*, u.name, u.first_name, u.last_name, u.username FROM prayer_requests p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('prayer get: ' . $e->getMessage()); return null; }
}

function aw_prayer_updates(PDO $db, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    try {
        $st = $db->query("SELECT x.*, u.name, u.first_name, u.last_name, u.username FROM prayer_updates x LEFT JOIN users u ON u.id = x.user_id WHERE x.request_id IN (" . implode(',', $ids) . ") ORDER BY x.created_at");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $out[(int)$u['request_id']][] = $u;
        return $out;
    } catch (Throwable $e) { error_log('prayer updates: ' . $e->getMessage()); return []; }
}

/** A new request. Returns ['ok' => bool, 'id' => n, 'error' => ...]. */
function aw_prayer_create(PDO $db, $uid, array $in) {
    if (!aw_prayer_tables($db)) return ['ok' => false, 'error' => 'Prayer requests are not available just now.'];
    $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 120));
    $text = trim(str_replace("\r\n", "\n", strip_tags((string)($in['message'] ?? ''))));
    $cat = isset(AW_PRAYER_CATEGORIES[$in['category'] ?? '']) ? (string)$in['category'] : 'other';
    $vis = ($in['visibility'] ?? '') === 'team' ? 'team' : 'public';
    $anon = !empty($in['anonymous']) ? 1 : 0;
    if ($title === '') return ['ok' => false, 'error' => 'Give your request a short title, for example "Healing for my mother".'];
    if (mb_strlen($text) < 5) return ['ok' => false, 'error' => 'Tell us a little about what you would like prayer for.'];
    if (mb_strlen($text) > 2000) return ['ok' => false, 'error' => 'Please keep it under 2,000 characters.'];
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM prayer_requests WHERE user_id = ? AND title IS NOT NULL AND created_at >= datetime('now', '-1 day')");
        $st->execute([(int)$uid]);
        if ((int)$st->fetchColumn() >= AW_PRAYER_LIMIT_DAY) return ['ok' => false, 'error' => 'You have shared ' . AW_PRAYER_LIMIT_DAY . ' requests today. You can add an update to one of them instead.'];
        $db->prepare("INSERT INTO prayer_requests (user_id, request_type, message, request_text, status, title, category, visibility, anonymous, created_at, updated_at)
                      VALUES (?, 'wall', ?, ?, 'pending', ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
           ->execute([(int)$uid, $text, $text, $title, $cat, $vis, $anon]);
        $id = (int)$db->lastInsertId();
        // the team hears about every request (a private one is only for them)
        if (function_exists('awNotify')) {
            foreach ($db->query("SELECT id FROM users WHERE role IN ('admin', 'moderator')")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                awNotify($db, (int)$sid, 'prayer_new', $vis === 'team' ? 'A private prayer request' : 'A new prayer request', $title, SITE_URL . ($vis === 'team' ? '/admin/manage_prayer_requests.php' : '/prayer.php#p' . $id), ['email' => false]);
            }
        }
        if (function_exists('aw_audit')) aw_audit('prayer_request', 'prayer', $id, $vis);
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        error_log('prayer create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Your request could not be saved just now. Please try again.'];
    }
}

/** "I prayed" (again to take it back). Returns ['ok' => bool, 'prayed' => bool, 'count' => n]. */
function aw_prayer_toggle(PDO $db, $uid, $id) {
    $r = aw_prayer_get($db, $id);
    if (!$r || $r['visibility'] !== 'public' || $r['status'] === 'removed') return ['ok' => false];
    try {
        $st = $db->prepare("SELECT 1 FROM prayer_prayed WHERE request_id = ? AND user_id = ?");
        $st->execute([(int)$id, (int)$uid]);
        if ($st->fetchColumn()) {
            $db->prepare("DELETE FROM prayer_prayed WHERE request_id = ? AND user_id = ?")->execute([(int)$id, (int)$uid]);
            $prayed = false;
        } else {
            $db->prepare("INSERT OR IGNORE INTO prayer_prayed (request_id, user_id) VALUES (?, ?)")->execute([(int)$id, (int)$uid]);
            $prayed = true;
        }
        $n = (int)$db->query("SELECT COUNT(*) FROM prayer_prayed WHERE request_id = " . (int)$id)->fetchColumn();
        $db->prepare("UPDATE prayer_requests SET prayer_count = ? WHERE id = ?")->execute([$n, (int)$id]);
        if ($prayed && in_array($n, AW_PRAYER_MILESTONES, true) && (int)$r['user_id'] !== (int)$uid && function_exists('awNotify')) {
            awNotify($db, (int)$r['user_id'], 'prayer_prayed', $n === 1 ? 'Someone prayed for you' : $n . ' people have prayed for you',
                '"' . $r['title'] . '": ' . ($n === 1 ? 'a member of the AngelWrites family prayed for your request.' : 'you are being carried in prayer.'), SITE_URL . '/prayer.php?show=mine#p' . (int)$id, ['email' => false, 'actor' => 0]);
        }
        return ['ok' => true, 'prayed' => $prayed, 'count' => $n];
    } catch (Throwable $e) { error_log('prayer toggle: ' . $e->getMessage()); return ['ok' => false]; }
}

/**
 * The author adds an update, says it was answered, renews it, or removes it; anyone else may leave a
 * short encouragement. Returns ['ok' => bool, 'message' => ...].
 */
function aw_prayer_act(PDO $db, $uid, $id, $act, $text = '') {
    $r = aw_prayer_get($db, $id);
    if (!$r || $r['status'] === 'removed') return ['ok' => false, 'message' => 'That request is no longer there.'];
    $mine = (int)$r['user_id'] === (int)$uid;
    $text = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)$text)), 0, 800));
    try {
        switch ($act) {
            case 'update':
            case 'answered':
                if (!$mine) return ['ok' => false, 'message' => 'Only the person who asked can do that.'];
                if ($act === 'update' && $text === '') return ['ok' => false, 'message' => 'Write your update first.'];
                if ($text !== '') $db->prepare("INSERT INTO prayer_updates (request_id, user_id, kind, body) VALUES (?, ?, ?, ?)")->execute([(int)$id, (int)$uid, $act === 'answered' ? 'praise' : 'update', $text]);
                if ($act === 'answered') $db->prepare("UPDATE prayer_requests SET status = 'answered', answered_at = CURRENT_TIMESTAMP, praise_note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$text, (int)$id]);
                else $db->prepare("UPDATE prayer_requests SET updated_at = CURRENT_TIMESTAMP, renewed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$id]);
                // everyone who prayed hears the news
                if (function_exists('awNotify')) {
                    $st = $db->prepare("SELECT user_id FROM prayer_prayed WHERE request_id = ? AND user_id <> ?");
                    $st->execute([(int)$id, (int)$uid]);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) awNotify($db, (int)$pid, 'prayer_news', $act === 'answered' ? 'A prayer was answered 🎉' : 'News on a request you prayed for', '"' . $r['title'] . '"', SITE_URL . '/prayer.php#p' . (int)$id, ['email' => false]);
                }
                return ['ok' => true, 'message' => $act === 'answered' ? 'Praise God! Everyone who prayed will hear the good news.' : 'Your update was added; those who prayed will see it.'];
            case 'renew':
                if (!$mine) return ['ok' => false, 'message' => 'Only the person who asked can do that.'];
                $db->prepare("UPDATE prayer_requests SET renewed_at = CURRENT_TIMESTAMP, status = CASE WHEN status = 'answered' THEN 'pending' ELSE status END WHERE id = ?")->execute([(int)$id]);
                return ['ok' => true, 'message' => 'Your request is back at the top of the wall for another ' . AW_PRAYER_DAYS . ' days.'];
            case 'delete':
                if (!$mine) return ['ok' => false, 'message' => 'Only the person who asked can do that.'];
                $db->prepare("DELETE FROM prayer_requests WHERE id = ?")->execute([(int)$id]);
                return ['ok' => true, 'message' => 'Your request was removed.'];
            case 'encourage':
                if ($mine || $r['visibility'] !== 'public') return ['ok' => false, 'message' => 'That is not possible here.'];
                if (mb_strlen($text) < 2) return ['ok' => false, 'message' => 'Write a few words first.'];
                $st = $db->prepare("SELECT COUNT(*) FROM prayer_updates WHERE request_id = ? AND user_id = ? AND kind = 'encourage'");
                $st->execute([(int)$id, (int)$uid]);
                if ((int)$st->fetchColumn() >= 2) return ['ok' => false, 'message' => 'You have already left words of encouragement here. Thank you!'];
                $db->prepare("INSERT INTO prayer_updates (request_id, user_id, kind, body) VALUES (?, ?, 'encourage', ?)")->execute([(int)$id, (int)$uid, mb_substr($text, 0, 300)]);
                if (function_exists('awNotify')) awNotify($db, (int)$r['user_id'], 'prayer_encourage', 'Words of encouragement for you', '"' . $r['title'] . '"', SITE_URL . '/prayer.php?show=mine#p' . (int)$id, ['email' => false]);
                return ['ok' => true, 'message' => 'Thank you. Your words were added.'];
        }
    } catch (Throwable $e) { error_log('prayer act: ' . $e->getMessage()); }
    return ['ok' => false, 'message' => 'That did not work. Please try again.'];
}
