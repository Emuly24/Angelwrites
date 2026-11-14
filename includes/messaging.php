<?php
/**
 * I1: private messages (PROGRAM_PLAN.md Phase 5, wish I).
 *
 * Plain text conversations between members, and between a member and the AngelWrites team.
 *   msg_threads(id, kind 'direct'|'team', created_by, created_at, last_message_at, team_read_at)
 *   msg_participants(thread_id, user_id, status 'active'|'requested'|'declined', last_read_at, joined_at)
 *   messages(id, thread_id, sender_id, body, as_team, status 'sent'|'removed', created_at)
 *   msg_settings(user_id, who 'everyone'|'groups'|'nobody')
 *
 * Rules:
 *  - Nobody can read a conversation they are not in, by any address or number. Team conversations
 *    are also open to staff with the team_inbox capability, who answer as "AngelWrites Team".
 *  - The first message to someone with no conversation yet is a request: they see who it is from and
 *    choose to accept or decline before the message is shown. A declined request stays "waiting" for
 *    the sender (nobody is told they were declined).
 *  - Blocks (Phase 2 table) stop messages both ways. Members choose who may message them.
 *  - Messaging is for adults (decision 5): the 18+ confirmation is the same one Stories & Help uses.
 *  - The email nudge never carries the message: notifications.php sends "You have a new message"
 *    with a link, at most once an hour, and only if the member wants it.
 *  - Unread counts are worked out on page load; nothing polls.
 * The switch: settings.messaging_enabled = '0' hides it. Nothing here throws.
 */
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/community_posts.php'; // the shared 18+ confirmation (community_members)

const AW_MSG_LIMITS = ['body' => 4000, 'per_day' => 80, 'requests_per_day' => 10];
const AW_MSG_WHO = ['everyone' => 'Any member', 'groups' => 'Only people in my reading groups', 'nobody' => 'Nobody (I can still write to the team)'];

function aw_msg_enabled() { return !function_exists('aw_setting') || (string)aw_setting('messaging_enabled', '1') !== '0'; }
function aw_msg_staff() { return function_exists('aw_can') && aw_can('team_inbox'); }

function aw_msg_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS msg_threads (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT 'direct', created_by INTEGER NOT NULL,
                   created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP, team_read_at DATETIME, team_read_id INTEGER NOT NULL DEFAULT 0)");
        $db->exec("CREATE TABLE IF NOT EXISTS msg_participants (thread_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'active',
                   last_read_at DATETIME, last_read_id INTEGER NOT NULL DEFAULT 0, joined_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (thread_id, user_id))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_msgp_user ON msg_participants (user_id, status)");
        $db->exec("CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL, sender_id INTEGER NOT NULL,
                   body TEXT NOT NULL, as_team INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'sent', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_thread ON messages (thread_id, id)");
        $db->exec("CREATE TABLE IF NOT EXISTS msg_settings (user_id INTEGER PRIMARY KEY, who TEXT NOT NULL DEFAULT 'everyone', updated_at DATETIME)");
        return $ok = true;
    } catch (Throwable $e) { error_log('msg tables: ' . $e->getMessage()); return $ok = false; }
}

function aw_msg_name(PDO $db, $uid) { return function_exists('aw_cp_user_name') ? aw_cp_user_name($db, (int)$uid) : 'A member'; }

function aw_msg_who(PDO $db, $uid) {
    try { $st = $db->prepare("SELECT who FROM msg_settings WHERE user_id = ?"); $st->execute([(int)$uid]); $w = (string)$st->fetchColumn(); return isset(AW_MSG_WHO[$w]) ? $w : 'everyone'; }
    catch (Throwable $e) { return 'everyone'; }
}

function aw_msg_set_who(PDO $db, $uid, $who) {
    if (!isset(AW_MSG_WHO[$who]) || !aw_msg_tables($db)) return false;
    try { $db->prepare("INSERT INTO msg_settings (user_id, who, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT(user_id) DO UPDATE SET who = excluded.who, updated_at = excluded.updated_at")->execute([(int)$uid, $who]); return true; }
    catch (Throwable $e) { error_log('msg who: ' . $e->getMessage()); return false; }
}

/** Do two members share a reading group? */
function aw_msg_share_group(PDO $db, $a, $b) {
    try { $st = $db->prepare("SELECT 1 FROM group_members x JOIN group_members y ON y.group_id = x.group_id WHERE x.user_id = ? AND y.user_id = ? LIMIT 1"); $st->execute([(int)$a, (int)$b]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** May $from start or continue a conversation with $to? Returns '' when yes, else a gentle reason. */
function aw_msg_can_write(PDO $db, $from, $to) {
    $generic = 'This member is not accepting messages right now.';
    if (!$to || (int)$from === (int)$to) return 'Please choose someone to write to.';
    try { $st = $db->prepare("SELECT 1 FROM users WHERE id = ?"); $st->execute([(int)$to]); if (!$st->fetchColumn()) return $generic; } catch (Throwable $e) { return $generic; }
    if (aw_is_blocked($db, (int)$to, (int)$from) || aw_is_blocked($db, (int)$from, (int)$to)) return $generic; // the same words either way: a block is never revealed
    if ($r = aw_cp_restriction($db, $from)) return 'Writing is paused on your account at the moment.';
    $existing = aw_msg_direct_thread($db, $from, $to);
    if ($existing && $existing['their_status'] === 'active') return '';
    $who = aw_msg_who($db, $to);
    if ($who === 'nobody') return $generic;
    if ($who === 'groups' && !aw_msg_share_group($db, $from, $to)) return $generic;
    return '';
}

/** The direct conversation between two members, with each side's status, or null. */
function aw_msg_direct_thread(PDO $db, $a, $b) {
    if (!aw_msg_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT t.*, pa.status AS my_status, pb.status AS their_status FROM msg_threads t
                            JOIN msg_participants pa ON pa.thread_id = t.id AND pa.user_id = ?
                            JOIN msg_participants pb ON pb.thread_id = t.id AND pb.user_id = ?
                            WHERE t.kind = 'direct' LIMIT 1");
        $st->execute([(int)$a, (int)$b]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('msg direct: ' . $e->getMessage()); return null; }
}

/** The member's conversation with the team (created on first use when $create). */
function aw_msg_team_thread(PDO $db, $uid, $create = false) {
    if (!aw_msg_tables($db)) return 0;
    try {
        $st = $db->prepare("SELECT t.id FROM msg_threads t JOIN msg_participants p ON p.thread_id = t.id AND p.user_id = ? WHERE t.kind = 'team' LIMIT 1");
        $st->execute([(int)$uid]);
        $id = (int)$st->fetchColumn();
        if ($id || !$create) return $id;
        $db->prepare("INSERT INTO msg_threads (kind, created_by) VALUES ('team', ?)")->execute([(int)$uid]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO msg_participants (thread_id, user_id, status) VALUES (?, ?, 'active')")->execute([$id, (int)$uid]);
        return $id;
    } catch (Throwable $e) { error_log('msg team thread: ' . $e->getMessage()); return 0; }
}

/**
 * Open a conversation for the current member: ['thread', 'me' (participant row or null), 'other' (user id or 0),
 * 'staff' (bool: here as the team)] or null when they may not see it. This is the one gate every page uses.
 */
function aw_msg_open(PDO $db, $thread_id, $uid) {
    if (!$uid || !aw_msg_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT * FROM msg_threads WHERE id = ?");
        $st->execute([(int)$thread_id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $ps = $db->prepare("SELECT * FROM msg_participants WHERE thread_id = ?");
        $ps->execute([(int)$thread_id]);
        $me = null; $other = 0;
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) { if ((int)$p['user_id'] === (int)$uid) $me = $p; else $other = (int)$p['user_id']; }
        if ($t['kind'] === 'team') {
            if ($me) return ['thread' => $t, 'me' => $me, 'other' => 0, 'staff' => false];
            if (aw_msg_staff()) { $st = $db->prepare("SELECT user_id FROM msg_participants WHERE thread_id = ? LIMIT 1"); $st->execute([(int)$thread_id]); return ['thread' => $t, 'me' => null, 'other' => (int)$st->fetchColumn(), 'staff' => true]; }
            return null;
        }
        if (!$me || $me['status'] === 'declined') return null;
        return ['thread' => $t, 'me' => $me, 'other' => $other, 'staff' => false];
    } catch (Throwable $e) { error_log('msg open: ' . $e->getMessage()); return null; }
}

function aw_msg_clean($body) {
    $body = str_replace("\r\n", "\n", (string)$body);
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body); // plain text: nothing removed, escaped when shown
    return trim(preg_replace("/\n{4,}/", "\n\n\n", $body));
}

function aw_msg_count_today(PDO $db, $uid) {
    try { $st = $db->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at >= datetime('now', '-1 day')"); $st->execute([(int)$uid]); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function aw_msg_age_ok(PDO $db, $uid, $tick) {
    if (aw_msg_staff()) return true;
    $m = aw_cp_member($db, $uid);
    if (!empty($m['age_confirmed_at'])) return true;
    if ($tick) { aw_cp_member_save($db, $uid, ['age_confirmed_at' => date('Y-m-d H:i:s')]); return true; }
    return false;
}

/** Start (or continue) a direct conversation. Returns ['ok', 'message', 'thread']. */
function aw_msg_start(PDO $db, $from, $to, $body, $age_tick = false) {
    if (!aw_msg_tables($db)) return ['ok' => false, 'message' => 'Messages are not available right now.'];
    if (!aw_msg_age_ok($db, $from, $age_tick)) return ['ok' => false, 'message' => 'Please confirm you are 18 or older. Messages are for adults.', 'age' => true];
    if ($why = aw_msg_can_write($db, $from, $to)) return ['ok' => false, 'message' => $why];
    $existing = aw_msg_direct_thread($db, $from, $to);
    if ($existing) {
        if ($existing['their_status'] === 'active' && $existing['my_status'] !== 'declined') return aw_msg_send($db, (int)$existing['id'], $from, $body, false, true);
        return ['ok' => false, 'message' => 'Your first message is waiting for them to accept. You can write more once they do.', 'thread' => (int)$existing['id']];
    }
    $body = aw_msg_clean($body);
    if ($body === '') return ['ok' => false, 'message' => 'Please write a message.'];
    if (mb_strlen($body) > AW_MSG_LIMITS['body']) return ['ok' => false, 'message' => 'Please keep a message under ' . number_format(AW_MSG_LIMITS['body']) . ' characters.'];
    try {
        $n = $db->prepare("SELECT COUNT(*) FROM msg_threads WHERE created_by = ? AND kind = 'direct' AND created_at >= datetime('now', '-1 day')");
        $n->execute([(int)$from]);
        if ((int)$n->fetchColumn() >= AW_MSG_LIMITS['requests_per_day']) return ['ok' => false, 'message' => 'You have started ' . AW_MSG_LIMITS['requests_per_day'] . ' new conversations today. Please come back tomorrow.'];
        $db->beginTransaction();
        $db->prepare("INSERT INTO msg_threads (kind, created_by) VALUES ('direct', ?)")->execute([(int)$from]);
        $tid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO msg_participants (thread_id, user_id, status, last_read_at) VALUES (?, ?, 'active', CURRENT_TIMESTAMP)")->execute([$tid, (int)$from]);
        $db->prepare("INSERT INTO msg_participants (thread_id, user_id, status) VALUES (?, ?, 'requested')")->execute([$tid, (int)$to]);
        $db->prepare("INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)")->execute([$tid, (int)$from, $body]);
        $db->prepare("UPDATE msg_participants SET last_read_id = ? WHERE thread_id = ? AND user_id = ?")->execute([(int)$db->lastInsertId(), $tid, (int)$from]);
        $db->commit();
        awNotify($db, (int)$to, 'message_request', 'Someone would like to message you', aw_msg_name($db, $from) . ' sent a message request.', SITE_URL . '/messages.php?view=requests', ['actor' => (int)$from]);
        return ['ok' => true, 'message' => 'Sent. They will see your message once they accept your request.', 'thread' => $tid];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('msg start: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Your message was not sent. Please try again.'];
    }
}

/** Send in a conversation the member can open. $as_team: staff writing in a team conversation. */
function aw_msg_send(PDO $db, $thread_id, $uid, $body, $as_team = false, $checked = false) {
    $o = aw_msg_open($db, $thread_id, $uid);
    if (!$o) return ['ok' => false, 'message' => 'This conversation was not found.'];
    $t = $o['thread'];
    if ($t['kind'] === 'direct') {
        if (!$checked && ($why = aw_msg_can_write($db, $uid, $o['other']))) return ['ok' => false, 'message' => $why];
        if ($o['me']['status'] !== 'active') return ['ok' => false, 'message' => 'Please accept the request first.'];
        try {
            $st = $db->prepare("SELECT status FROM msg_participants WHERE thread_id = ? AND user_id = ?");
            $st->execute([(int)$thread_id, $o['other']]);
            if ((string)$st->fetchColumn() !== 'active') return ['ok' => false, 'message' => 'Your first message is waiting for them to accept. You can write more once they do.'];
        } catch (Throwable $e) { return ['ok' => false, 'message' => 'Your message was not sent.']; }
    } elseif (!$o['staff'] && ($r = aw_cp_restriction($db, $uid))) {
        // writing to the team is always allowed, even while posting elsewhere is paused
    }
    $body = aw_msg_clean($body);
    if ($body === '') return ['ok' => false, 'message' => 'Please write a message.'];
    if (mb_strlen($body) > AW_MSG_LIMITS['body']) return ['ok' => false, 'message' => 'Please keep a message under ' . number_format(AW_MSG_LIMITS['body']) . ' characters.'];
    if (aw_msg_count_today($db, $uid) >= AW_MSG_LIMITS['per_day'] && !$o['staff']) return ['ok' => false, 'message' => 'You have sent ' . AW_MSG_LIMITS['per_day'] . ' messages today. Please come back tomorrow.'];
    $asTeam = $o['staff'] ? 1 : 0;
    try {
        // who should hear about it: only someone with nothing unread here yet (one nudge per burst)
        $notify = [];
        if ($t['kind'] === 'direct') {
            $notify[] = $o['other'];
        } elseif ($asTeam) {
            $notify[] = $o['other'];
        }
        $pq = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE thread_id = ?");
        $pq->execute([(int)$thread_id]);
        $prevId = (int)$pq->fetchColumn();
        $db->prepare("INSERT INTO messages (thread_id, sender_id, body, as_team) VALUES (?, ?, ?, ?)")->execute([(int)$thread_id, (int)$uid, $body, $asTeam]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("UPDATE msg_threads SET last_message_at = CURRENT_TIMESTAMP" . ($asTeam ? ", team_read_at = CURRENT_TIMESTAMP, team_read_id = " . $mid : '') . " WHERE id = ?")->execute([(int)$thread_id]);
        if ($o['me']) $db->prepare("UPDATE msg_participants SET last_read_at = CURRENT_TIMESTAMP, last_read_id = ? WHERE thread_id = ? AND user_id = ?")->execute([$mid, (int)$thread_id, (int)$uid]);
        if ($asTeam) aw_audit('team_message', 'msg_thread', (int)$thread_id, 'message #' . $mid);
        $link = SITE_URL . '/messages.php?t=' . (int)$thread_id;
        foreach ($notify as $rid) {
            $st = $db->prepare("SELECT last_read_id FROM msg_participants WHERE thread_id = ? AND user_id = ?");
            $st->execute([(int)$thread_id, (int)$rid]);
            if ((int)$st->fetchColumn() >= $prevId) { // they had read everything before this: one nudge per burst
                awNotify($db, (int)$rid, 'message', $asTeam ? 'The AngelWrites team sent you a message' : 'You have a new message', 'Open your messages to read it.', $link, ['actor' => (int)$uid]);
            }
        }
        if ($t['kind'] === 'team' && !$asTeam) {
            $unseen = (int)$t['team_read_id'] >= $prevId;
            if ($unseen) foreach ($db->query("SELECT id FROM users WHERE role IN ('admin', 'moderator')")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                awNotify($db, (int)$sid, 'message', 'A member wrote to the team', 'Messages · team conversations', $link . '&team=1', ['actor' => (int)$uid, 'email' => false]);
            }
        }
        return ['ok' => true, 'message' => 'Sent.', 'thread' => (int)$thread_id, 'id' => $mid];
    } catch (Throwable $e) { error_log('msg send: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Your message was not sent. Please try again.']; }
}

/** Accept or decline a request addressed to the member. */
function aw_msg_answer_request(PDO $db, $thread_id, $uid, $accept) {
    if (!aw_msg_tables($db)) return false;
    try {
        $st = $db->prepare("UPDATE msg_participants SET status = ? WHERE thread_id = ? AND user_id = ? AND status = 'requested'");
        $st->execute([$accept ? 'active' : 'declined', (int)$thread_id, (int)$uid]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('msg answer: ' . $e->getMessage()); return false; }
}

function aw_msg_mark_read(PDO $db, array $o, $uid) {
    try {
        $q = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE thread_id = ?");
        $q->execute([(int)$o['thread']['id']]);
        $max = (int)$q->fetchColumn();
        if ($o['me']) $db->prepare("UPDATE msg_participants SET last_read_at = CURRENT_TIMESTAMP, last_read_id = MAX(last_read_id, ?) WHERE thread_id = ? AND user_id = ?")->execute([$max, (int)$o['thread']['id'], (int)$uid]);
        if ($o['staff']) $db->prepare("UPDATE msg_threads SET team_read_at = CURRENT_TIMESTAMP, team_read_id = MAX(team_read_id, ?) WHERE id = ?")->execute([$max, (int)$o['thread']['id']]);
    } catch (Throwable $e) { error_log('msg read: ' . $e->getMessage()); }
}

/** A conversation's messages (removed ones show as a placeholder). */
function aw_msg_messages(PDO $db, $thread_id, $limit = 200) {
    try {
        $st = $db->prepare("SELECT * FROM (SELECT * FROM messages WHERE thread_id = ? ORDER BY id DESC LIMIT ?) ORDER BY id");
        $st->execute([(int)$thread_id, (int)$limit]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('msg list: ' . $e->getMessage()); return []; }
}

function aw_msg_delete_own(PDO $db, $mid, $uid) {
    try { $st = $db->prepare("UPDATE messages SET status = 'removed' WHERE id = ? AND sender_id = ? AND as_team = 0"); $st->execute([(int)$mid, (int)$uid]); return $st->rowCount() > 0; }
    catch (Throwable $e) { error_log('msg delete: ' . $e->getMessage()); return false; }
}

/** The member's conversations: ['active' => [...], 'requests' => [...], 'waiting' => [...]], newest first. */
function aw_msg_inbox(PDO $db, $uid) {
    $out = ['active' => [], 'requests' => []];
    if (!aw_msg_tables($db)) return $out;
    try {
        $st = $db->prepare("SELECT t.*, p.status AS my_status, p.last_read_at,
                                   (SELECT user_id FROM msg_participants o WHERE o.thread_id = t.id AND o.user_id <> p.user_id LIMIT 1) AS other_id,
                                   (SELECT status FROM msg_participants o WHERE o.thread_id = t.id AND o.user_id <> p.user_id LIMIT 1) AS other_status,
                                   (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id AND m.sender_id <> p.user_id AND m.status = 'sent' AND m.id > p.last_read_id) AS unread,
                                   (SELECT body FROM messages m WHERE m.thread_id = t.id AND m.status = 'sent' ORDER BY m.id DESC LIMIT 1) AS last_body,
                                   (SELECT sender_id FROM messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_sender
                            FROM msg_threads t JOIN msg_participants p ON p.thread_id = t.id AND p.user_id = ?
                            WHERE p.status IN ('active', 'requested') ORDER BY t.last_message_at DESC LIMIT 200");
        $st->execute([(int)$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['my_status'] === 'requested') $out['requests'][] = $r; else $out['active'][] = $r;
        }
    } catch (Throwable $e) { error_log('msg inbox: ' . $e->getMessage()); }
    return $out;
}

/** Team conversations for staff, newest first, with whether the team has read the latest. */
function aw_msg_team_inbox(PDO $db) {
    if (!aw_msg_staff() || !aw_msg_tables($db)) return [];
    try {
        return $db->query("SELECT t.*, (SELECT user_id FROM msg_participants p WHERE p.thread_id = t.id LIMIT 1) AS member_id,
                                  (SELECT body FROM messages m WHERE m.thread_id = t.id AND m.status = 'sent' ORDER BY m.id DESC LIMIT 1) AS last_body,
                                  (SELECT as_team FROM messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_as_team,
                                  (SELECT COALESCE(MAX(id), 0) FROM messages m WHERE m.thread_id = t.id) AS last_id
                           FROM msg_threads t WHERE t.kind = 'team' ORDER BY t.last_message_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('msg team inbox: ' . $e->getMessage()); return []; }
}

/** For the header badge: conversations with something unread, plus requests. One query, on page load. */
function aw_msg_unread_count(PDO $db, $uid) {
    if (!$uid) return 0;
    try {
        if (!$db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'msg_participants'")->fetchColumn()) return 0;
        $st = $db->prepare("SELECT COUNT(*) FROM msg_participants p JOIN msg_threads t ON t.id = p.thread_id
                            WHERE p.user_id = ? AND (p.status = 'requested' OR (p.status = 'active' AND EXISTS (SELECT 1 FROM messages m WHERE m.thread_id = p.thread_id AND m.sender_id <> p.user_id AND m.status = 'sent' AND m.id > p.last_read_id)))");
        $st->execute([(int)$uid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** A "Message" link for a member, shown only when the viewer could write to them. */
function aw_msg_button($to, $label = 'Message') {
    $me = (int)($_SESSION['user_id'] ?? 0);
    $db = $GLOBALS['db'] ?? null;
    if (!$me || !$db instanceof PDO || (int)$to === $me || !aw_msg_enabled() || !aw_msg_tables($db)) return '';
    if (aw_msg_can_write($db, $me, (int)$to) !== '') return '';
    return '<a class="aw-msg-btn" href="' . htmlspecialchars(SITE_URL . '/messages.php?to=' . (int)$to) . '"><i class="far fa-envelope" aria-hidden="true"></i> ' . htmlspecialchars($label) . '</a>';
}
