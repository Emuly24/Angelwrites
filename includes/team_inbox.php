<?php
/**
 * K4: the team inbox (PROGRAM_PLAN.md Phase 2, wish K).
 *
 * Contact messages become conversations the team answers as "AngelWrites Team":
 *   - the person sees "AngelWrites Team" (email, and their messages page when signed in);
 *   - the owner and admins see which staff member wrote each reply; moderators see "Team";
 *   - a conversation can be assigned to one staff member and marked open, answered or closed;
 *   - saved replies ("canned responses") can be inserted and edited before sending.
 * New tables only: team_replies, team_inbox_state, canned_responses. Nothing throws.
 */
require_once __DIR__ . '/notifications.php';

function aw_inbox_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS team_replies (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER NOT NULL,
                   author_id INTEGER, body TEXT NOT NULL, emailed INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_team_replies_msg ON team_replies(message_id)");
        $db->exec("CREATE TABLE IF NOT EXISTS team_inbox_state (message_id INTEGER PRIMARY KEY, assigned_to INTEGER,
                   status TEXT DEFAULT 'open', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS canned_responses (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL,
                   created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $cols = array_column($db->query("PRAGMA table_info(contact_messages)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($cols && !in_array('user_id', $cols, true)) $db->exec("ALTER TABLE contact_messages ADD COLUMN user_id INTEGER");
        if (!(int)$db->query("SELECT COUNT(*) FROM canned_responses")->fetchColumn()) {
            $ins = $db->prepare("INSERT INTO canned_responses (title, body) VALUES (?, ?)");
            $ins->execute(['Thank you, we read it', "Dear {first_name},\n\nThank you for writing to us. We have read your message and we are holding you in prayer.\n\n"]);
            $ins->execute(['Book a session', "Dear {first_name},\n\nThank you for reaching out. If you would like to talk with Angella, you can book a private session here: " . SITE_URL . "/book_session.php\n\n"]);
            $ins->execute(['In crisis', "Dear {first_name},\n\nThank you for trusting us with this. If you are in danger or thinking of harming yourself, please call your local emergency number now, or reach Angella on +265 99 146 9386.\n\nYou are not alone.\n\n"]);
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('inbox tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Status and assignment of a conversation. */
function aw_inbox_state(PDO $db, $message_id) {
    try {
        $st = $db->prepare("SELECT * FROM team_inbox_state WHERE message_id = ?");
        $st->execute([(int)$message_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['message_id' => (int)$message_id, 'assigned_to' => null, 'status' => 'open'];
    } catch (Throwable $e) { return ['message_id' => (int)$message_id, 'assigned_to' => null, 'status' => 'open']; }
}

function aw_inbox_set_state(PDO $db, $message_id, array $set) {
    $cur = aw_inbox_state($db, $message_id);
    $status = in_array($set['status'] ?? $cur['status'], ['open', 'answered', 'closed'], true) ? ($set['status'] ?? $cur['status']) : 'open';
    $assigned = array_key_exists('assigned_to', $set) ? ($set['assigned_to'] ? (int)$set['assigned_to'] : null) : $cur['assigned_to'];
    try {
        $db->prepare("INSERT INTO team_inbox_state (message_id, assigned_to, status, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                      ON CONFLICT(message_id) DO UPDATE SET assigned_to = excluded.assigned_to, status = excluded.status, updated_at = excluded.updated_at")
           ->execute([(int)$message_id, $assigned, $status]);
        return true;
    } catch (Throwable $e) { error_log('inbox state: ' . $e->getMessage()); return false; }
}

/**
 * Reply as the team. Emails the person, notifies their account if they have one, marks the
 * conversation answered. Returns ['ok' => bool, 'message' => string].
 */
function aw_inbox_reply(PDO $db, $message_id, $body) {
    $body = trim(str_replace("\r", '', strip_tags((string)$body)));
    if ($body === '') return ['ok' => false, 'message' => 'Please write a reply.'];
    if (mb_strlen($body) > 10000) return ['ok' => false, 'message' => 'The reply is too long.'];
    try {
        $st = $db->prepare("SELECT * FROM contact_messages WHERE id = ?");
        $st->execute([(int)$message_id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return ['ok' => false, 'message' => 'That message no longer exists.'];
        $first = preg_split('/\s+/', trim((string)$m['name']))[0] ?? '';
        $body = str_replace('{first_name}', $first !== '' ? $first : 'friend', $body);
        if (!function_exists('sendEmail')) require_once __DIR__ . '/mail_helper.php';
        $subject = 'Re: ' . (trim((string)$m['subject']) !== '' ? $m['subject'] : 'your message to AngelWrites');
        $text = $body . "\n\nWith love,\nAngelWrites Team\n\n—\nYou wrote on " . date('F j, Y', strtotime($m['created_at'])) . ":\n> "
              . str_replace("\n", "\n> ", mb_substr((string)$m['message'], 0, 2000));
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $sent = (bool)sendEmail($m['email'], $subject, $html, 'no-reply@angelwrites.gt.tc', 'AngelWrites Team', true);
        $db->prepare("INSERT INTO team_replies (message_id, author_id, body, emailed) VALUES (?, ?, ?, ?)")
           ->execute([(int)$message_id, (int)($_SESSION['user_id'] ?? 0) ?: null, $body, $sent ? 1 : 0]);
        $rid = (int)$db->lastInsertId();
        $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?")->execute([(int)$message_id]);
        aw_inbox_set_state($db, $message_id, ['status' => 'answered']);
        if (!empty($m['user_id'])) {
            awNotify($db, (int)$m['user_id'], 'team_reply', 'The AngelWrites team replied', 'About “' . mb_substr((string)($m['subject'] ?: 'your message'), 0, 80) . '”.',
                SITE_URL . '/my_messages.php#m' . (int)$message_id, ['email' => false]); // the reply itself was emailed
        }
        if (function_exists('aw_audit')) aw_audit('team_reply', 'contact_message', (int)$message_id, 'reply #' . $rid . ($sent ? '' : ' (email not sent)'));
        return ['ok' => true, 'message' => $sent ? 'Sent as AngelWrites Team.' : 'Saved, but the email could not be sent. The person will see it on their messages page if they have an account.'];
    } catch (Throwable $e) {
        error_log('inbox reply: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The reply could not be saved. Please try again.'];
    }
}
