<?php
/**
 * "Keep AngelWrites emails out of Junk": while the site sends from a free address, mailboxes often put
 * its emails in Junk or Spam. Members are asked, kindly and once, to move one email to the inbox and
 * add the address to their contacts; after that their mailbox learns.
 *
 *  - email_help.php: the steps for Gmail, Outlook/Hotmail, Yahoo, iCloud/Apple Mail, Zoho and others.
 *  - A banner on the member dashboard until they press "Done" (or "Later": it returns in 14 days).
 *  - Admin > Settings > Email: switch the banner on/off and tell every member at once (a notification).
 *  - A line in every email's footer, and "check Junk" hints where an email is expected.
 * Every function returns a value and logs on failure; nothing throws.
 */

/** The address members should add to their contacts. */
function aw_inbox_address() {
    if (function_exists('aw_mail_config')) { $c = aw_mail_config(); if (!empty($c['from'])) return (string)$c['from']; }
    return 'angelwrites@zohomail.com';
}

function aw_inbox_help_on() { return !function_exists('aw_setting') || (string)aw_setting('inbox_help_on', '1') !== '0'; }

function aw_member_flags_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $db->exec("CREATE TABLE IF NOT EXISTS member_flags (user_id INTEGER NOT NULL, flag TEXT NOT NULL, value TEXT, at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, flag))"); return $ok = true; }
    catch (Throwable $e) { error_log('member_flags: ' . $e->getMessage()); return $ok = false; }
}

/** Should this member see the banner? Not after "Done"; not for 14 days after "Later". */
function aw_inbox_help_due(PDO $db, $uid) {
    if (!aw_inbox_help_on() || !aw_member_flags_table($db)) return false;
    try {
        $st = $db->prepare("SELECT value, at FROM member_flags WHERE user_id = ? AND flag = 'inbox_help'");
        $st->execute([(int)$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return true;
        if ($r['value'] === 'done') return false;
        return strtotime($r['at'] . ' UTC') < time() - 14 * 86400;
    } catch (Throwable $e) { error_log('inbox help due: ' . $e->getMessage()); return false; }
}

function aw_inbox_help_mark(PDO $db, $uid, $value) {
    if (!in_array($value, ['done', 'later'], true) || !aw_member_flags_table($db)) return false;
    try { $db->prepare("INSERT OR REPLACE INTO member_flags (user_id, flag, value, at) VALUES (?, 'inbox_help', ?, CURRENT_TIMESTAMP)")->execute([(int)$uid, $value]); return true; }
    catch (Throwable $e) { error_log('inbox help mark: ' . $e->getMessage()); return false; }
}

/** The steps for each email service: [name, icon, [steps]]. */
function aw_inbox_help_steps() {
    $a = aw_inbox_address();
    return [
        ['Gmail', 'fab fa-google', ['Open Gmail and tap the menu, then Spam.', 'Open the AngelWrites email and tap “Report not spam” (on a computer: “Not spam” at the top).', 'Open the email again, tap the three dots by our name and choose “Add to contacts” (' . $a . ').', 'Optional: if it arrives under Promotions, drag it to Primary and choose “Yes” when Gmail asks.']],
        ['Outlook, Hotmail, Live', 'fab fa-microsoft', ['Open the Junk Email folder.', 'Select the AngelWrites email and choose “Not junk” (or “Move to Inbox”), then “Always trust email from ' . $a . '”.', 'On a computer: Settings, Mail, Junk email, Safe senders, add ' . $a . '.']],
        ['Yahoo Mail', 'fab fa-yahoo', ['Open the Spam folder.', 'Open the AngelWrites email and tap “Not spam”.', 'Tap our name and choose “Add to contacts”.']],
        ['iCloud and Apple Mail (iPhone, iPad, Mac)', 'fab fa-apple', ['Open the Junk mailbox.', 'Open the AngelWrites email and tap the folder icon, then Inbox (on a Mac: “Not Junk”).', 'Tap our name at the top of the email, then “Add to Contacts”.']],
        ['Zoho Mail', 'fas fa-envelope', ['Open the Spam folder.', 'Select the AngelWrites email and choose “Not spam”.', 'Settings, Anti-spam, Allowed senders: add ' . $a . '.']],
        ['Any other email', 'fas fa-inbox', ['Look in the Junk, Spam, Bulk or Promotions folder.', 'Mark the AngelWrites email as “Not spam” or move it to the Inbox.', 'Add ' . $a . ' to your contacts or safe senders.']],
    ];
}

/** Tell every member once, on the site (the bell). Returns how many were told. */
function aw_inbox_help_notify_all(PDO $db) {
    if (!function_exists('awNotify')) return 0;
    $n = 0;
    try {
        foreach ($db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (awNotify($db, (int)$id, 'inbox_help', 'Please check your Junk or Spam folder for our emails',
                'Mailboxes sometimes put AngelWrites emails in Junk. Moving one to your inbox and adding ' . aw_inbox_address() . ' to your contacts makes sure you get replies, reminders and the newsletter.',
                SITE_URL . '/email_help.php', ['email' => false, 'actor' => 0])) $n++;
        }
        if (function_exists('aw_audit')) aw_audit('inbox_help_notified', 'users', null, $n . ' members');
    } catch (Throwable $e) { error_log('inbox help notify: ' . $e->getMessage()); }
    return $n;
}
