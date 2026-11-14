<?php
/**
 * Notifications (F114, completed in N1 — PROGRAM_PLAN.md Phase 2).
 *
 * awNotify($db, $user_id, $type, $title, $message, $link, $opts) adds the in-site notification (the bell)
 * and, when the member wants it, a short email nudge:
 *   - preferences per kind, set by the member (notification_prefs; default below);
 *   - at most one email per kind per hour per member (notification_mail_log);
 *   - the email says what happened and links to it; it never carries the comment or message text.
 * Account events (sessions, payments) keep their own emails and are not emailed again here.
 * Nothing here ever breaks the page that calls it.
 */

/** Which preference a notification type belongs to, and whether it can be emailed. */
function aw_notify_kind($type) {
    $type = (string)$type;
    if ($type === 'mention') return 'mentions';
    if (in_array($type, ['answer', 'reply', 'admin_reply', 'comment_reply', 'answer_accepted', 'thanks'], true)) return 'replies';
    if ($type === 'team_reply') return 'team';
    if (in_array($type, ['report_outcome', 'approved', 'testimonial_approved', 'question_approved', 'post_approved', 'post_held', 'community_review'], true)) return 'moderation';
    if ($type === 'message' || $type === 'message_request') return 'messages';
    return 'account'; // sessions, payments and anything else: bell only from here
}

const AW_NOTIFY_KINDS = [
    'replies'    => ['Replies to you', 'When someone answers your question or replies to your comment.', 1],
    'mentions'   => ['Mentions', 'When someone mentions you with @ and your name.', 1],
    'team'       => ['Messages from the AngelWrites team', 'When the team answers something you sent.', 1],
    'moderation' => ['Decisions about your posts and reports', 'When something you posted is approved, or a report you made is closed.', 1],
    'messages'   => ['Private messages', 'When someone sends you a message (a short nudge only, never the message).', 1],
];

function aw_notify_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_prefs (user_id INTEGER NOT NULL, kind TEXT NOT NULL, email INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (user_id, kind))");
        $db->exec("CREATE TABLE IF NOT EXISTS notification_mail_log (user_id INTEGER NOT NULL, kind TEXT NOT NULL, sent_at DATETIME NOT NULL, PRIMARY KEY (user_id, kind))");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('notify tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Does this member want an email for this kind? Default: yes, as listed in AW_NOTIFY_KINDS. */
function aw_notify_wants_email(PDO $db, $user_id, $kind) {
    if (!isset(AW_NOTIFY_KINDS[$kind]) || !aw_notify_tables($db)) return false;
    try {
        $st = $db->prepare("SELECT email FROM notification_prefs WHERE user_id = ? AND kind = ?");
        $st->execute([(int)$user_id, $kind]);
        $v = $st->fetchColumn();
        return $v === false ? (bool)AW_NOTIFY_KINDS[$kind][2] : (int)$v === 1;
    } catch (Throwable $e) {
        error_log('notify prefs: ' . $e->getMessage());
        return false;
    }
}

/** All of a member's preferences: [kind => bool]. */
function aw_notify_prefs(PDO $db, $user_id) {
    $out = [];
    foreach (array_keys(AW_NOTIFY_KINDS) as $k) $out[$k] = aw_notify_wants_email($db, $user_id, $k);
    return $out;
}

function aw_notify_save_prefs(PDO $db, $user_id, array $on) {
    if (!aw_notify_tables($db)) return false;
    try {
        $st = $db->prepare("INSERT INTO notification_prefs (user_id, kind, email) VALUES (?, ?, ?) ON CONFLICT(user_id, kind) DO UPDATE SET email = excluded.email");
        foreach (array_keys(AW_NOTIFY_KINDS) as $k) $st->execute([(int)$user_id, $k, !empty($on[$k]) ? 1 : 0]);
        return true;
    } catch (Throwable $e) {
        error_log('notify save prefs: ' . $e->getMessage());
        return false;
    }
}

/** Send the email nudge if wanted and not sent for this kind in the last hour. */
function aw_notify_email(PDO $db, $user_id, $kind, $title, $link) {
    if (!aw_notify_wants_email($db, $user_id, $kind)) return false;
    try {
        $st = $db->prepare("SELECT sent_at FROM notification_mail_log WHERE user_id = ? AND kind = ?");
        $st->execute([(int)$user_id, $kind]);
        $last = $st->fetchColumn();
        if ($last && strtotime($last) > time() - 3600) return false; // one an hour per kind
        $u = $db->prepare("SELECT email, name, first_name, last_name, username, is_verified FROM users WHERE id = ?");
        $u->execute([(int)$user_id]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if (!$row || trim((string)$row['email']) === '') return false;
        if (!function_exists('sendEmail')) require_once __DIR__ . '/mail_helper.php';
        $first = preg_split('/\s+/', awDisplayName($row))[0] ?? 'friend';
        $settings = SITE_URL . '/notifications.php#email-settings';
        $body = "Hello $first,\n\n" . $title . ".\n\nSee it here: " . ($link ?: SITE_URL . '/notifications.php') . "\n\n"
              . "You receive this because email for \"" . AW_NOTIFY_KINDS[$kind][0] . "\" is on. You can turn it off here: $settings\n\n"
              . "With love,\nThe AngelWrites team";
        $sent = sendEmail($row['email'], $title . ' · AngelWrites', $body);
        $db->prepare("INSERT INTO notification_mail_log (user_id, kind, sent_at) VALUES (?, ?, ?) ON CONFLICT(user_id, kind) DO UPDATE SET sent_at = excluded.sent_at")
           ->execute([(int)$user_id, $kind, date('Y-m-d H:i:s')]);
        return (bool)$sent;
    } catch (Throwable $e) {
        error_log('notify email: ' . $e->getMessage());
        return false;
    }
}

if (!function_exists('awNotify')) {
    /**
     * Add a notification. $opts: 'email' => false to never email (default: by preference),
     * 'actor' => user id of whoever caused it (nobody is notified about their own action).
     * Returns true when the notification was stored.
     */
    function awNotify($db, $user_id, $type, $title, $message, $link = null, array $opts = []) {
        if (!$user_id || !$db instanceof PDO) return false;
        $actor = (int)($opts['actor'] ?? ($_SESSION['user_id'] ?? 0));
        if ($actor && $actor === (int)$user_id && empty($opts['self_ok'])) return false;
        if ($actor) { // N4: nothing reaches a member from someone they blocked
            try { $b = $db->prepare("SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?"); $b->execute([(int)$user_id, $actor]); if ($b->fetchColumn()) return false; }
            catch (Throwable $e) { /* no blocks table yet */ }
        }
        try {
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)")
               ->execute([(int)$user_id, $type, $title, $message, $link]);
        } catch (Throwable $e) {
            error_log('awNotify: ' . $e->getMessage());
            return false;
        }
        $kind = aw_notify_kind($type);
        if (($opts['email'] ?? true) && $kind !== 'account') aw_notify_email($db, $user_id, $kind, $title, $link);
        return true;
    }
}
