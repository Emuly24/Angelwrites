<?php
/**
 * Events: live readings, prayer meetings, workshops, book club meetings.
 *
 * Times are entered in the site's time zone (settings.site_timezone, default Africa/Blantyre) and
 * stored in UTC; pages show the site time and, with a little script, the reader's own local time.
 * Members sign up; when an event is full they join the waitlist and move up automatically when a
 * place frees. An online link is shown only to people who are going. A calendar file (.ics) with a
 * reminder can be added to any phone. About 24 hours before, everyone going gets a reminder
 * notification and email (checked at most once an hour, after a page has been sent).
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_EVENT_KINDS = ['reading' => ['Live reading', 'fa-book-open-reader'], 'prayer' => ['Prayer meeting', 'fa-hands-praying'], 'workshop' => ['Workshop', 'fa-pen-nib'],
    'club' => ['Book club meeting', 'fa-people-group'], 'worship' => ['Worship and fellowship', 'fa-music'], 'other' => ['Gathering', 'fa-calendar-day']];

function aw_event_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'other', description TEXT,
                   starts_at TEXT NOT NULL, duration_min INTEGER NOT NULL DEFAULT 60, place TEXT, online_link TEXT, capacity INTEGER NOT NULL DEFAULT 0,
                   status TEXT NOT NULL DEFAULT 'draft', reminded_at DATETIME, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS event_signups (event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                   status TEXT NOT NULL DEFAULT 'going', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (event_id, user_id))");
        return $ok = true;
    } catch (Throwable $e) { error_log('event tables: ' . $e->getMessage()); return $ok = false; }
}

function aw_event_tz() {
    $tz = function_exists('aw_setting') ? (string)aw_setting('site_timezone', 'Africa/Blantyre') : 'Africa/Blantyre';
    try { return new DateTimeZone($tz); } catch (Throwable $e) { return new DateTimeZone('Africa/Blantyre'); }
}
/** Site-time "Y-m-d H:i" to stored UTC "Y-m-d H:i:s". */
function aw_event_to_utc($local) {
    try { $d = new DateTime((string)$local, aw_event_tz()); $d->setTimezone(new DateTimeZone('UTC')); return $d->format('Y-m-d H:i:s'); } catch (Throwable $e) { return ''; }
}
/** Stored UTC to a DateTime in site time. */
function aw_event_local($utc) {
    try { $d = new DateTime((string)$utc, new DateTimeZone('UTC')); $d->setTimezone(aw_event_tz()); return $d; } catch (Throwable $e) { return new DateTime(); }
}
function aw_event_tz_label() {
    $n = aw_event_tz()->getName();
    return ['Africa/Blantyre' => 'Malawi time', 'Africa/Harare' => 'Central Africa time', 'Africa/Johannesburg' => 'South Africa time', 'Europe/London' => 'UK time'][$n] ?? str_replace('_', ' ', $n) . ' time';
}

/** Events with counts and the viewer's status. $when: 'upcoming' | 'past' | 'all' (team). */
function aw_event_list(PDO $db, $uid = 0, $when = 'upcoming', $team = false) {
    if (!aw_event_tables($db)) return [];
    $w = $team ? '1=1' : "e.status IN ('published', 'cancelled')";
    if ($when === 'upcoming') $w .= " AND datetime(e.starts_at, '+' || e.duration_min || ' minutes') >= datetime('now')";
    elseif ($when === 'past') $w .= " AND datetime(e.starts_at, '+' || e.duration_min || ' minutes') < datetime('now')";
    try {
        $st = $db->prepare("SELECT e.*, (SELECT COUNT(*) FROM event_signups s WHERE s.event_id = e.id AND s.status = 'going') AS going,
                                   (SELECT COUNT(*) FROM event_signups s WHERE s.event_id = e.id AND s.status = 'waitlist') AS waiting,
                                   (SELECT status FROM event_signups s WHERE s.event_id = e.id AND s.user_id = ?) AS mine
                            FROM events e WHERE $w ORDER BY e.starts_at " . ($when === 'past' ? 'DESC LIMIT 20' : 'ASC'));
        $st->execute([(int)$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('event list: ' . $e->getMessage()); return []; }
}

function aw_event_get(PDO $db, $id, $uid = 0) {
    if (!aw_event_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT e.*, (SELECT COUNT(*) FROM event_signups s WHERE s.event_id = e.id AND s.status = 'going') AS going,
                                   (SELECT COUNT(*) FROM event_signups s WHERE s.event_id = e.id AND s.status = 'waitlist') AS waiting,
                                   (SELECT status FROM event_signups s WHERE s.event_id = e.id AND s.user_id = ?) AS mine FROM events e WHERE e.id = ?");
        $st->execute([(int)$uid, (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('event get: ' . $e->getMessage()); return null; }
}

/** Sign up (or join the waitlist when full). Returns ['ok', 'status', 'message']. */
function aw_event_join(PDO $db, $uid, $id) {
    $e = aw_event_get($db, $id, $uid);
    if (!$e || $e['status'] !== 'published') return ['ok' => false, 'message' => 'This event is not open for sign-up.'];
    if (strtotime($e['starts_at'] . ' UTC') < time()) return ['ok' => false, 'message' => 'This event has already started.'];
    if ($e['mine'] === 'going' || $e['mine'] === 'waitlist') return ['ok' => true, 'status' => $e['mine'], 'message' => 'You are already signed up.'];
    $full = (int)$e['capacity'] > 0 && (int)$e['going'] >= (int)$e['capacity'];
    $status = $full ? 'waitlist' : 'going';
    try {
        $db->prepare("INSERT INTO event_signups (event_id, user_id, status) VALUES (?, ?, ?) ON CONFLICT(event_id, user_id) DO UPDATE SET status = excluded.status, created_at = CURRENT_TIMESTAMP")->execute([(int)$id, (int)$uid, $status]);
        return ['ok' => true, 'status' => $status, 'message' => $full ? 'The event is full, so you are on the waitlist. We will tell you as soon as a place frees up.' : 'You are going! Add it to your calendar so you do not miss it.'];
    } catch (Throwable $e2) { error_log('event join: ' . $e2->getMessage()); return ['ok' => false, 'message' => 'That did not work. Please try again.']; }
}

/** Leave; the first person on the waitlist moves up and is told. */
function aw_event_leave(PDO $db, $uid, $id) {
    try {
        $e = aw_event_get($db, $id, $uid);
        if (!$e) return false;
        $db->prepare("DELETE FROM event_signups WHERE event_id = ? AND user_id = ?")->execute([(int)$id, (int)$uid]);
        if ($e['mine'] === 'going') aw_event_promote($db, $e);
        return true;
    } catch (Throwable $e2) { error_log('event leave: ' . $e2->getMessage()); return false; }
}

function aw_event_promote(PDO $db, array $e) {
    try {
        $going = (int)$db->query("SELECT COUNT(*) FROM event_signups WHERE event_id = " . (int)$e['id'] . " AND status = 'going'")->fetchColumn();
        while ((int)$e['capacity'] === 0 || $going < (int)$e['capacity']) {
            $next = $db->query("SELECT user_id FROM event_signups WHERE event_id = " . (int)$e['id'] . " AND status = 'waitlist' ORDER BY created_at LIMIT 1")->fetchColumn();
            if (!$next) break;
            $db->prepare("UPDATE event_signups SET status = 'going' WHERE event_id = ? AND user_id = ?")->execute([(int)$e['id'], (int)$next]);
            if (function_exists('awNotify')) awNotify($db, (int)$next, 'event_place', 'A place opened up for you', 'You are now going to "' . $e['title'] . '".', SITE_URL . '/events.php?id=' . (int)$e['id'], ['email' => true, 'actor' => 0]);
            $going++;
        }
    } catch (Throwable $e2) { error_log('event promote: ' . $e2->getMessage()); }
}

/** A calendar file for one event, with a reminder an hour before. */
function aw_event_ics(array $e) {
    $esc = function ($s) { return addcslashes(str_replace(["\r\n", "\n"], '\\n', (string)$s), ',;\\'); };
    $start = gmdate('Ymd\THis\Z', strtotime($e['starts_at'] . ' UTC'));
    $end = gmdate('Ymd\THis\Z', strtotime($e['starts_at'] . ' UTC') + 60 * (int)$e['duration_min']);
    $url = SITE_URL . '/events.php?id=' . (int)$e['id'];
    $where = trim((string)$e['place']) !== '' ? $e['place'] : 'Online (link on the event page)';
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AngelWrites//Events//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n"
         . "UID:event-" . (int)$e['id'] . "@" . parse_url(SITE_URL, PHP_URL_HOST) . "\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:$start\r\nDTEND:$end\r\n"
         . "SUMMARY:" . $esc($e['title']) . "\r\nDESCRIPTION:" . $esc(mb_substr((string)$e['description'], 0, 800) . "\n\n" . $url) . "\r\nLOCATION:" . $esc($where) . "\r\nURL:$url\r\n"
         . "BEGIN:VALARM\r\nTRIGGER:-PT1H\r\nACTION:DISPLAY\r\nDESCRIPTION:" . $esc($e['title']) . "\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}

/** Reminders about 24 hours before (called from the footer; checks at most once an hour). */
function aw_event_reminders_maybe() {
    static $queued = false;
    if ($queued || !function_exists('aw_setting')) return;
    $last = strtotime((string)aw_setting('events_checked_at', '')) ?: 0;
    if (time() - $last < 3600) return;
    $queued = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        $db = $GLOBALS['db'] ?? null;
        if (!$db instanceof PDO || !aw_event_tables($db)) return;
        try {
            require_once __DIR__ . '/notifications.php';
            require_once __DIR__ . '/mail_helper.php';
            $db->prepare("INSERT INTO settings (key, value) VALUES ('events_checked_at', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);
            $due = $db->query("SELECT * FROM events WHERE status = 'published' AND reminded_at IS NULL AND starts_at > datetime('now') AND starts_at <= datetime('now', '+26 hours')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($due as $e) {
                $db->prepare("UPDATE events SET reminded_at = CURRENT_TIMESTAMP WHERE id = ? AND reminded_at IS NULL")->execute([(int)$e['id']]);
                $when = aw_event_local($e['starts_at'])->format('l j F, H:i') . ' ' . aw_event_tz_label();
                $st = $db->prepare("SELECT u.id, u.email, u.name, u.first_name, u.last_name, u.username FROM event_signups s JOIN users u ON u.id = s.user_id WHERE s.event_id = ? AND s.status = 'going'");
                $st->execute([(int)$e['id']]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    if (function_exists('awNotify')) awNotify($db, (int)$u['id'], 'event_reminder', 'Tomorrow: ' . $e['title'], $when, SITE_URL . '/events.php?id=' . (int)$e['id'], ['email' => false, 'actor' => 0]);
                    if (function_exists('sendConversationalEmail') && !empty($u['email'])) {
                        sendConversationalEmail($u['email'], 'Reminder: ' . $e['title'], 'Hello ' . (function_exists('awDisplayName') ? awDisplayName($u) : 'friend') . ",\n\nA gentle reminder that \"" . $e['title'] . "\" is on " . $when . ".\n\nThe details" . (trim((string)$e['online_link']) !== '' ? ', including how to join,' : '') . " are on the event page.", SITE_URL . '/events.php?id=' . (int)$e['id'], 'Open the event', 'AngelWrites');
                    }
                }
            }
        } catch (Throwable $e) { error_log('event reminders: ' . $e->getMessage()); }
    });
}
