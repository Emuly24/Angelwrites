<?php
/**
 * Looking after the Care Team (no polling; all of it runs from the daily housekeeping or a page):
 *
 *  - Monthly check-in: every 30 days each moderator and volunteer gets a gentle question on the site,
 *    "How is your role going?": happy to continue, I need some help, I would like a break, or I would
 *    like to step down (with an optional note). The owner is told each answer; stepping down ends a
 *    volunteer area at once.
 *  - Weekly summary: once a week the owner is emailed what the team did, what is waiting, new
 *    members, check-in answers and the coming meetings. Switched in Team & roles.
 *  - Team meetings (training and catch-ups): the owner or an admin schedules a meeting for the whole
 *    Care Team or one role, with a place or online link and an agenda. Invitees are told on the site
 *    and by email with a calendar file, answer Yes/Maybe/No on admin/meetings.php, and are reminded
 *    the day before.
 * Times are entered in the site's time zone and stored in UTC (includes/events.php helpers).
 * Every function returns a value and logs on failure; nothing throws.
 */
require_once __DIR__ . '/events.php';            // aw_event_tz(), aw_event_to_utc(), aw_event_local(), aw_event_tz_label()
require_once __DIR__ . '/staff_positions.php';

const AW_CHECKIN_ANSWERS = [
    'continue' => 'I am happy to continue',
    'help'     => 'I need some help or support',
    'break'    => 'I would like a break for a while',
    'stop'     => 'I would like to step down',
];

function aw_care_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS staff_checkins (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, answered_at DATETIME, answer TEXT, note TEXT)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_checkins_user ON staff_checkins(user_id, sent_at)");
        $db->exec("CREATE TABLE IF NOT EXISTS team_meetings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'training',
            starts_at DATETIME NOT NULL, minutes INTEGER NOT NULL DEFAULT 60, place TEXT, link TEXT, agenda TEXT, audience TEXT NOT NULL DEFAULT 'all',
            created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, cancelled_at DATETIME, reminded_at DATETIME)");
        $db->exec("CREATE TABLE IF NOT EXISTS team_meeting_rsvps (meeting_id INTEGER NOT NULL, user_id INTEGER NOT NULL, answer TEXT NOT NULL, at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (meeting_id, user_id))");
        return $ok = true;
    } catch (Throwable $e) { error_log('care tables: ' . $e->getMessage()); return $ok = false; }
}

/** Everyone on the Care Team (moderators and volunteers): [id => user row + 'areas' => [...]]. */
function aw_care_team(PDO $db) {
    $team = [];
    try {
        foreach ($db->query("SELECT id, username, name, first_name, last_name, email, role FROM users WHERE role = 'moderator'")->fetchAll(PDO::FETCH_ASSOC) as $u) { $u['areas'] = ['moderator']; $team[(int)$u['id']] = $u; }
        if (aw_staff_tables($db)) foreach ($db->query("SELECT a.area, u.id, u.username, u.name, u.first_name, u.last_name, u.email, u.role FROM staff_areas a JOIN users u ON u.id = a.user_id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $id = (int)$u['id'];
            if (!isset($team[$id])) { $team[$id] = $u; $team[$id]['areas'] = []; }
            $team[$id]['areas'][] = $u['area'];
        }
    } catch (Throwable $e) { error_log('aw_care_team: ' . $e->getMessage()); }
    return $team;
}

// ===== check-ins =========================================================================

/** Send check-ins to anyone on the team not asked in the last 30 days (and on the team for 7+ days). */
function aw_checkins_send(PDO $db) {
    if (!aw_care_tables($db)) return 0;
    $n = 0;
    foreach (aw_care_team($db) as $id => $u) {
        try {
            $st = $db->prepare("SELECT MAX(sent_at) FROM staff_checkins WHERE user_id = ?"); $st->execute([$id]); $last = (string)$st->fetchColumn();
            if ($last !== '' && strtotime($last . ' UTC') > time() - 30 * 86400) continue;
            if ($last === '') { // first one a week after they joined
                $g = $db->prepare("SELECT MIN(granted_at) FROM staff_areas WHERE user_id = ?"); $g->execute([$id]); $since = (string)$g->fetchColumn();
                if ($since !== '' && strtotime($since . ' UTC') > time() - 7 * 86400) continue;
            }
            $db->prepare("INSERT INTO staff_checkins (user_id) VALUES (?)")->execute([$id]);
            $cid = (int)$db->lastInsertId();
            if (function_exists('awNotify')) awNotify($db, $id, 'team_checkin', 'How is your role going?', 'A one-minute check-in from the ' . (function_exists('aw_team_name') ? aw_team_name() : 'team') . '. Thank you for all you do.', SITE_URL . '/admin/checkin.php?id=' . $cid, ['email' => false, 'actor' => 0]);
            $n++;
        } catch (Throwable $e) { error_log('aw_checkins_send: ' . $e->getMessage()); }
    }
    return $n;
}

/** The member answers a check-in. Returns ['ok', 'msg']. */
function aw_checkin_answer(PDO $db, $cid, $uid, $answer, $note) {
    if (!aw_care_tables($db) || !isset(AW_CHECKIN_ANSWERS[$answer])) return ['ok' => false, 'msg' => 'Please choose an answer.'];
    try {
        $st = $db->prepare("SELECT * FROM staff_checkins WHERE id = ? AND user_id = ?"); $st->execute([(int)$cid, (int)$uid]); $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'msg' => 'This check-in was not found.'];
        $note = trim(mb_substr((string)$note, 0, 1000));
        $db->prepare("UPDATE staff_checkins SET answer = ?, note = ?, answered_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$answer, $note, (int)$cid]);
        $msg = 'Thank you. The owner has your answer.';
        if ($answer === 'stop') {
            foreach (aw_user_areas((int)$uid) as $a) aw_area_revoke($db, (int)$uid, $a);
            $msg = 'Thank you for everything you did. Your volunteer roles have ended' . (aw_role_of((int)$uid) === 'moderator' ? ', and the owner will change your moderator role.' : '.');
        }
        $who = function_exists('awDisplayName') ? awDisplayName(aw_staff_user_row($db, $uid)) : 'Someone';
        $owner = aw_owner_id();
        if ($owner && function_exists('awNotify')) awNotify($db, $owner, 'team_checkin_answer', $who . ': ' . AW_CHECKIN_ANSWERS[$answer], $note !== '' ? $note : 'From the monthly check-in.', SITE_URL . '/admin/meetings.php#checkins', ['email' => false, 'actor' => (int)$uid]);
        if ($owner && in_array($answer, ['help', 'stop', 'break'], true) && function_exists('sendEmail')) {
            $o = $db->prepare("SELECT email FROM users WHERE id = ?"); $o->execute([$owner]); $oe = (string)$o->fetchColumn();
            if ($oe !== '') sendEmail($oe, 'Check-in: ' . $who . ' · ' . AW_CHECKIN_ANSWERS[$answer], htmlspecialchars($who) . ' answered the monthly check-in: <strong>' . htmlspecialchars(AW_CHECKIN_ANSWERS[$answer]) . '</strong>.' . ($note !== '' ? '<br><br>“' . nl2br(htmlspecialchars($note)) . '”' : '') . '<br><br><a href="' . SITE_URL . '/admin/meetings.php#checkins">See check-ins</a>');
        }
        if (function_exists('aw_audit')) aw_audit('checkin_answered', 'user', (int)$uid, $answer);
        return ['ok' => true, 'msg' => $msg];
    } catch (Throwable $e) { error_log('aw_checkin_answer: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'Your answer could not be saved. Please try again.']; }
}

/** Recent check-ins for the owner: [row + name]. */
function aw_checkins_recent(PDO $db, $days = 45) {
    if (!aw_care_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT c.*, u.username, u.name, u.first_name, u.last_name FROM staff_checkins c JOIN users u ON u.id = c.user_id WHERE c.sent_at >= datetime('now', ?) ORDER BY c.answered_at IS NULL, c.answered_at DESC, c.sent_at DESC");
        $st->execute(['-' . (int)$days . ' days']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('aw_checkins_recent: ' . $e->getMessage()); return []; }
}

// ===== meetings ==========================================================================

/** Who a meeting is for: 'all' (the whole Care Team) or a position key. */
function aw_meeting_invitees(PDO $db, $audience) {
    $out = [];
    foreach (aw_care_team($db) as $id => $u) if ($audience === 'all' || in_array($audience, $u['areas'], true)) $out[$id] = $u;
    return $out;
}

function aw_meeting_ics(array $m) {
    $s = new DateTime((string)$m['starts_at'], new DateTimeZone('UTC'));
    $e = (clone $s)->modify('+' . (int)$m['minutes'] . ' minutes');
    $esc = function ($t) { return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string)$t); };
    $loc = trim((string)$m['link']) !== '' ? (string)$m['link'] : (string)$m['place'];
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AngelWrites//Team//EN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n"
         . 'UID:team-meeting-' . (int)$m['id'] . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'angelwrites') . "\r\n"
         . 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\nDTSTART:" . $s->format('Ymd\THis\Z') . "\r\nDTEND:" . $e->format('Ymd\THis\Z') . "\r\n"
         . 'SUMMARY:' . $esc('AngelWrites: ' . $m['title']) . "\r\n" . ($loc !== '' ? 'LOCATION:' . $esc($loc) . "\r\n" : '')
         . 'DESCRIPTION:' . $esc(trim((string)$m['agenda']) . "\n\n" . SITE_URL . '/admin/meetings.php') . "\r\n"
         . (!empty($m['cancelled_at']) ? "STATUS:CANCELLED\r\n" : '') . "END:VEVENT\r\nEND:VCALENDAR\r\n";
}

/** Tell invitees about a meeting (new, or cancelled). Returns how many were told. */
function aw_meeting_tell(PDO $db, array $m, $what = 'new') {
    $people = aw_meeting_invitees($db, (string)$m['audience']);
    $when = aw_event_local($m['starts_at']);
    $whenTxt = $when->format('l j F Y, H:i') . ' (' . aw_event_tz_label() . ')';
    $title = $what === 'cancelled' ? 'Cancelled: ' . $m['title'] : ($what === 'reminder' ? 'Tomorrow: ' . $m['title'] : 'Team meeting: ' . $m['title']);
    $ics = '';
    if ($what !== 'reminder') {
        $dir = dirname(__DIR__) . '/data/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ics = $dir . '/meeting_' . (int)$m['id'] . '_' . bin2hex(random_bytes(4)) . '.ics';
        if (@file_put_contents($ics, aw_meeting_ics($m)) === false) $ics = '';
    }
    $n = 0;
    foreach ($people as $id => $u) {
        if (function_exists('awNotify')) awNotify($db, $id, 'team_meeting', $title, $whenTxt, SITE_URL . '/admin/meetings.php#m' . (int)$m['id'], ['email' => false, 'actor' => 0]);
        if (!empty($u['email'])) {
            $body = '<p>Hello ' . htmlspecialchars((string)($u['first_name'] ?: $u['username'])) . ',</p>'
                  . ($what === 'cancelled' ? '<p>This team meeting has been <strong>cancelled</strong>:</p>' : ($what === 'reminder' ? '<p>A reminder that this team meeting is tomorrow:</p>' : '<p>You are invited to a team meeting:</p>'))
                  . '<p><strong>' . htmlspecialchars((string)$m['title']) . '</strong><br>' . htmlspecialchars($whenTxt) . ' · ' . (int)$m['minutes'] . ' minutes'
                  . (trim((string)$m['place']) !== '' ? '<br>Where: ' . htmlspecialchars((string)$m['place']) : '') . (trim((string)$m['link']) !== '' && $what !== 'cancelled' ? '<br>Join: <a href="' . htmlspecialchars((string)$m['link']) . '">' . htmlspecialchars((string)$m['link']) . '</a>' : '') . '</p>'
                  . (trim((string)$m['agenda']) !== '' && $what !== 'cancelled' ? '<p><strong>Agenda</strong><br>' . nl2br(htmlspecialchars((string)$m['agenda'])) . '</p>' : '')
                  . ($what === 'new' ? '<p>Please say whether you can come: <a href="' . SITE_URL . '/admin/meetings.php#m' . (int)$m['id'] . '">answer on AngelWrites</a>. The attached file adds it to your calendar.</p>' : '');
            $ok = $ics !== '' && function_exists('sendEmailWithAttachment') ? sendEmailWithAttachment($u['email'], $title, $body, $ics, 'angelwrites-meeting.ics')
                                                                              : (function_exists('sendEmail') ? sendEmail($u['email'], $title, $body) : false);
            if (!$ok) error_log('meeting email failed for user ' . $id);
        }
        $n++;
    }
    if ($ics !== '') @unlink($ics);
    return $n;
}

/** Create a meeting from a form. Returns ['ok', 'msg', 'id']. */
function aw_meeting_create(PDO $db, array $post, $by) {
    if (!aw_care_tables($db)) return ['ok' => false, 'msg' => 'The meeting could not be saved.'];
    $title = trim(mb_substr(strip_tags((string)($post['title'] ?? '')), 0, 120));
    $kind = in_array($post['kind'] ?? '', ['training', 'catchup', 'prayer'], true) ? $post['kind'] : 'training';
    $date = (string)($post['date'] ?? ''); $time = (string)($post['time'] ?? '');
    $minutes = max(15, min(480, (int)($post['minutes'] ?? 60)));
    $place = trim(mb_substr(strip_tags((string)($post['place'] ?? '')), 0, 200));
    $link = trim((string)($post['link'] ?? ''));
    $agenda = trim(mb_substr(strip_tags((string)($post['agenda'] ?? '')), 0, 3000));
    $aud = (string)($post['audience'] ?? 'all');
    if ($aud !== 'all' && !isset(AW_POSITIONS[$aud])) $aud = 'all';
    if ($title === '') return ['ok' => false, 'msg' => 'Please give the meeting a title.'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return ['ok' => false, 'msg' => 'Please choose a date and a time.'];
    $utc = aw_event_to_utc($date . ' ' . $time);
    if ($utc === '' || strtotime($utc . ' UTC') < time()) return ['ok' => false, 'msg' => 'Please choose a time in the future.'];
    if ($link !== '' && !preg_match('#^https://[^\s<>"]+$#i', $link)) return ['ok' => false, 'msg' => 'The online link must start with https://'];
    if ($place === '' && $link === '') return ['ok' => false, 'msg' => 'Please give a place or an online link (Google Meet, Zoom, WhatsApp call…).'];
    if (!aw_meeting_invitees($db, $aud)) return ['ok' => false, 'msg' => 'Nobody holds that role yet, so there is nobody to invite.'];
    try {
        $db->prepare("INSERT INTO team_meetings (title, kind, starts_at, minutes, place, link, agenda, audience, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$title, $kind, $utc, $minutes, $place, $link, $agenda, $aud, (int)$by]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) { error_log('aw_meeting_create: ' . $e->getMessage()); return ['ok' => false, 'msg' => 'The meeting could not be saved.']; }
    $m = aw_meeting_get($db, $id);
    $n = $m ? aw_meeting_tell($db, $m, 'new') : 0;
    if (function_exists('aw_audit')) aw_audit('team_meeting_created', 'meeting', $id, $aud);
    return ['ok' => true, 'msg' => 'Meeting scheduled. ' . $n . ' ' . ($n === 1 ? 'person was' : 'people were') . ' invited on the site and by email.', 'id' => $id];
}

function aw_meeting_get(PDO $db, $id) {
    if (!aw_care_tables($db)) return null;
    try { $st = $db->prepare("SELECT * FROM team_meetings WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('aw_meeting_get: ' . $e->getMessage()); return null; }
}

function aw_meeting_cancel(PDO $db, $id) {
    $m = aw_meeting_get($db, $id);
    if (!$m || !empty($m['cancelled_at'])) return false;
    try { $db->prepare("UPDATE team_meetings SET cancelled_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$id]); }
    catch (Throwable $e) { error_log('aw_meeting_cancel: ' . $e->getMessage()); return false; }
    $m['cancelled_at'] = gmdate('Y-m-d H:i:s');
    aw_meeting_tell($db, $m, 'cancelled');
    if (function_exists('aw_audit')) aw_audit('team_meeting_cancelled', 'meeting', (int)$id, '');
    return true;
}

/** Meetings this person is invited to (or all, for owners and admins), upcoming first. */
function aw_meetings_for(PDO $db, $uid, $all = false, $past = false) {
    if (!aw_care_tables($db)) return [];
    try {
        $rows = $db->query("SELECT * FROM team_meetings WHERE starts_at " . ($past ? "<" : ">=") . " datetime('now', '-2 hours') ORDER BY starts_at " . ($past ? 'DESC LIMIT 20' : 'ASC'))->fetchAll(PDO::FETCH_ASSOC);
        $mine = aw_care_team($db)[(int)$uid]['areas'] ?? [];
        $out = [];
        foreach ($rows as $m) {
            if (!$all && $m['audience'] !== 'all' && !in_array($m['audience'], $mine, true)) continue;
            $r = $db->prepare("SELECT r.user_id, r.answer, u.username, u.name, u.first_name, u.last_name FROM team_meeting_rsvps r JOIN users u ON u.id = r.user_id WHERE r.meeting_id = ?");
            $r->execute([(int)$m['id']]);
            $m['rsvps'] = $r->fetchAll(PDO::FETCH_ASSOC);
            $m['my'] = '';
            foreach ($m['rsvps'] as $x) if ((int)$x['user_id'] === (int)$uid) $m['my'] = $x['answer'];
            $out[] = $m;
        }
        return $out;
    } catch (Throwable $e) { error_log('aw_meetings_for: ' . $e->getMessage()); return []; }
}

function aw_meeting_rsvp(PDO $db, $mid, $uid, $answer) {
    if (!in_array($answer, ['yes', 'maybe', 'no'], true) || !aw_care_tables($db)) return false;
    try { $db->prepare("INSERT OR REPLACE INTO team_meeting_rsvps (meeting_id, user_id, answer, at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)")->execute([(int)$mid, (int)$uid, $answer]); return true; }
    catch (Throwable $e) { error_log('aw_meeting_rsvp: ' . $e->getMessage()); return false; }
}

/** The day before: one reminder per meeting. */
function aw_meeting_reminders(PDO $db) {
    if (!aw_care_tables($db)) return 0;
    $n = 0;
    try {
        foreach ($db->query("SELECT * FROM team_meetings WHERE cancelled_at IS NULL AND reminded_at IS NULL AND starts_at BETWEEN datetime('now') AND datetime('now', '+30 hours')")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $db->prepare("UPDATE team_meetings SET reminded_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$m['id']]);
            aw_meeting_tell($db, $m, 'reminder');
            $n++;
        }
    } catch (Throwable $e) { error_log('aw_meeting_reminders: ' . $e->getMessage()); }
    return $n;
}

// ===== weekly summary ========================================================================

/** The summary's HTML for the past 7 days. */
function aw_weekly_summary_html(PDO $db) {
    $q = function ($sql, $args = []) use ($db) { try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    $one = function ($sql) use ($db) { try { return (int)$db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $new = $one("SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 days')");
    $waiting = [
        'Reports to review' => $one("SELECT COUNT(*) FROM reports WHERE status = 'pending'"),
        'Prayer requests waiting' => $one("SELECT COUNT(*) FROM prayer_requests WHERE COALESCE(status, 'pending') NOT IN ('prayed', 'answered', 'removed')"),
        'Unread contact messages' => $one("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0"),
        'Stories waiting for a first read' => $one("SELECT COUNT(*) FROM community_posts WHERE status = 'held'"),
        'Books shared by members, waiting' => $one("SELECT COUNT(*) FROM member_books WHERE status = 'waiting'"),
        'Orders to confirm' => $one("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
    ];
    $team = $q("SELECT a.actor_id, COUNT(*) AS n, u.username, u.name, u.first_name, u.last_name FROM audit_log a JOIN users u ON u.id = a.actor_id
                WHERE a.created_at >= datetime('now', '-7 days') AND a.actor_role NOT IN ('owner', 'guest') GROUP BY a.actor_id ORDER BY n DESC LIMIT 15");
    $checkins = aw_checkins_recent($db, 7);
    $quiet = $q("SELECT u.username, u.name, u.first_name, u.last_name FROM staff_checkins c JOIN users u ON u.id = c.user_id WHERE c.answered_at IS NULL AND c.sent_at < datetime('now', '-14 days') AND c.sent_at >= datetime('now', '-60 days')");
    $meet = $q("SELECT * FROM team_meetings WHERE cancelled_at IS NULL AND starts_at BETWEEN datetime('now') AND datetime('now', '+14 days') ORDER BY starts_at");
    $name = function ($r) { return function_exists('awDisplayName') ? awDisplayName($r) : (string)$r['username']; };
    $h = '<p>Here is the week on AngelWrites.</p>';
    $h .= '<h3>New members</h3><p>' . $new . ' joined in the last 7 days. <a href="' . SITE_URL . '/admin/manage_users.php?filter=new">See them</a></p>';
    $h .= '<h3>Waiting for a person</h3><ul>';
    foreach ($waiting as $l => $n) $h .= '<li>' . $e($l) . ': <strong>' . (int)$n . '</strong></li>';
    $h .= '</ul><p><a href="' . SITE_URL . '/admin/team.php">Open the Team desk</a></p>';
    $h .= '<h3>What the team did</h3>' . ($team ? '<ul>' . implode('', array_map(function ($r) use ($e, $name) { return '<li>' . $e($name($r)) . ': ' . (int)$r['n'] . ' actions · <a href="' . SITE_URL . '/admin/audit.php?actor=' . (int)$r['actor_id'] . '">details</a></li>'; }, $team)) . '</ul>' : '<p>No team activity recorded this week.</p>');
    if ($checkins || $quiet) {
        $h .= '<h3>Check-ins</h3><ul>';
        foreach ($checkins as $c) if ($c['answer']) $h .= '<li>' . $e($name($c)) . ': ' . $e(AW_CHECKIN_ANSWERS[$c['answer']] ?? $c['answer']) . ($c['note'] ? ' (“' . $e(mb_substr($c['note'], 0, 160)) . '”)' : '') . '</li>';
        foreach ($quiet as $c) $h .= '<li>' . $e($name($c)) . ' has not answered their check-in for two weeks. A friendly word may help.</li>';
        $h .= '</ul>';
    }
    if ($meet) {
        $h .= '<h3>Coming meetings</h3><ul>';
        foreach ($meet as $m) $h .= '<li>' . $e(aw_event_local($m['starts_at'])->format('D j M, H:i')) . ' · ' . $e($m['title']) . '</li>';
        $h .= '</ul>';
    }
    return $h;
}

/** Send the weekly summary to the owner. $force: send now, whatever the date. */
function aw_weekly_summary_send(PDO $db, $force = false) {
    if (!$force && (string)aw_setting('weekly_summary_enabled', '1') === '0') return false;
    $last = (string)aw_setting('weekly_summary_last', '');
    if (!$force && $last !== '' && strtotime($last) > time() - 7 * 86400 + 3600) return false;
    $owner = aw_owner_id();
    try { $st = $db->prepare("SELECT email FROM users WHERE id = ?"); $st->execute([$owner]); $to = (string)$st->fetchColumn(); } catch (Throwable $e) { $to = ''; }
    if ($to === '') $to = function_exists('aw_admin_email') ? aw_admin_email() : '';
    if ($to === '' || !function_exists('sendEmail')) return false;
    $ok = sendEmail($to, 'Your week on AngelWrites', aw_weekly_summary_html($db));
    if ($ok || !$force) {
        try { $db->prepare("INSERT INTO settings (key, value) VALUES ('weekly_summary_last', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) { error_log('weekly summary: ' . $e->getMessage()); }
    }
    return (bool)$ok;
}

/** Called once a day by the housekeeping. */
function aw_team_care_daily(PDO $db) {
    try {
        if (!function_exists('awNotify') && is_file(__DIR__ . '/notifications.php')) require_once __DIR__ . '/notifications.php';
        if (!function_exists('sendEmail') && is_file(__DIR__ . '/mail_helper.php')) require_once __DIR__ . '/mail_helper.php';
        aw_checkins_send($db);
        aw_meeting_reminders($db);
        aw_weekly_summary_send($db);
    } catch (Throwable $e) { error_log('aw_team_care_daily: ' . $e->getMessage()); }
}
