<?php
/**
 * Emails to angelwrites@zohomail.com in the Team inbox, so the team can read and answer them on the
 * site without opening Zoho.
 *
 * How: when the Team inbox opens (at most every 2 minutes, or on "Check for new emails"), the site
 * signs in to the Zoho mailbox over IMAP (the same mailbox and password as sending), reads new mail in
 * the INBOX and adds each email to contact_messages (source 'email'). Replies from the Team inbox go
 * back by email from the same address, as a reply in the same conversation (In-Reply-To).
 *
 *  - Reading only: messages are fetched with BODY.PEEK, so nothing is marked read, moved or deleted in
 *    Zoho. Zoho keeps every email as before.
 *  - Small and bounded: at most 25 emails and 20 seconds per check, the first 400 KB of each email.
 *  - Text only: the readable text of each email is stored (HTML is turned into text); attachments are
 *    listed by name, to be opened in Zoho. Nothing from an email is ever shown as HTML.
 *  - Skipped: our own messages, automatic replies and delivery failures (counted, not stored).
 *  - First check: the last 14 days. After that, only new emails (by IMAP UID).
 * No library: a minimal IMAP client over stream_socket_client (PHP's imap extension is not on most
 * hosts). Nothing throws; failures are recorded for the inbox page (setting zoho_inbox_error).
 *
 * In Zoho: Settings > Mail Accounts > IMAP Access must be on (the same place as SMTP access).
 * .env (all optional): ZOHO_IMAP_HOST (default: the SMTP host with smtp. replaced by imap.),
 * ZOHO_IMAP_PORT (993), ZOHO_IMAP_SSL (1; 0 only for a test server).
 */
require_once __DIR__ . '/mail_helper.php';
if (!function_exists('aw_inbox_address')) {
    /** The site's mailbox address (also defined in includes/inbox_help.php, loaded by mail_helper.php when present). */
    function aw_inbox_address() { $c = aw_mail_config(); return !empty($c['from']) ? (string)$c['from'] : 'angelwrites@zohomail.com'; }
}

const AW_ZI_MAX_PER_SYNC = 25;
const AW_ZI_MAX_BYTES = 400000;
const AW_ZI_FIRST_DAYS = 14;

// ===== a minimal IMAP client ==================================================================

final class AwImap
{
    private $s = null;
    private $n = 0;
    private $deadline;
    public $error = '';

    public function __construct(int $seconds = 20) { $this->deadline = microtime(true) + $seconds; }

    public function connect(string $host, int $port, bool $ssl): bool
    {
        $errno = 0; $errstr = '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) { $this->error = 'Could not reach ' . $host . ':' . $port . ' (' . ($errstr ?: 'no answer') . ')'; return false; }
        stream_set_timeout($this->s, 15);
        $greet = $this->line();
        if ($greet === null || stripos($greet, '* OK') !== 0) { $this->error = 'The mail server did not greet us: ' . trim((string)$greet); return false; }
        return true;
    }

    private function line(): ?string
    {
        if (!$this->s || microtime(true) > $this->deadline) { $this->error = $this->error ?: 'Took too long.'; return null; }
        $l = fgets($this->s, 65536);
        if ($l === false) { $this->error = $this->error ?: 'The connection closed.'; return null; }
        return $l;
    }

    private function bytes(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) > $this->deadline) { $this->error = 'Took too long.'; return null; }
            $chunk = fread($this->s, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') { if (feof($this->s)) { $this->error = 'The connection closed.'; return null; } continue; }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Send a command; returns ['ok' => bool, 'status' => text, 'lines' => untagged responses (literals joined in)]. */
    public function cmd(string $command): array
    {
        $tag = 'A' . (++$this->n);
        if (!$this->s || @fwrite($this->s, $tag . ' ' . $command . "\r\n") === false) return ['ok' => false, 'status' => 'not connected', 'lines' => []];
        $lines = []; $cur = '';
        while (true) {
            $l = $this->line();
            if ($l === null) return ['ok' => false, 'status' => $this->error, 'lines' => $lines];
            if (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) {
                $lit = $this->bytes((int)$m[1]);
                if ($lit === null) return ['ok' => false, 'status' => $this->error, 'lines' => $lines];
                $cur .= $l . $lit;
                continue;
            }
            $cur .= $l;
            if (strpos($cur, $tag . ' ') === 0) {
                $st = trim(substr($cur, strlen($tag) + 1));
                return ['ok' => stripos($st, 'OK') === 0, 'status' => $st, 'lines' => $lines];
            }
            $lines[] = $cur; $cur = '';
        }
    }

    public static function q(string $s): string { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; }

    public function close(): void { if ($this->s) { @fwrite($this->s, 'Z LOGOUT' . "\r\n"); @fclose($this->s); } $this->s = null; }
}

// ===== reading an email ==========================================================================

function aw_mime_header_decode(string $v): string
{
    $v = trim(preg_replace('/\r?\n[ \t]+/', ' ', $v));
    if (strpos($v, '=?') !== false && function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) $v = $d;
    }
    return aw_mime_utf8($v, '');
}

function aw_mime_utf8(string $s, string $charset): string
{
    $charset = strtolower(trim($charset, " \"'"));
    if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii' && function_exists('mb_convert_encoding')) {
        $c = @mb_convert_encoding($s, 'UTF-8', $charset === 'iso-8859-1' ? 'ISO-8859-1' : $charset);
        if (is_string($c)) $s = $c;
    }
    return function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : $s;
}

/** Split raw headers into [lowercase name => value] (first one wins, except Received is ignored). */
function aw_mime_headers(string $raw): array
{
    $h = [];
    foreach (preg_split('/\r?\n(?![ \t])/', $raw) as $line) {
        $p = strpos($line, ':');
        if ($p === false) continue;
        $k = strtolower(trim(substr($line, 0, $p)));
        if (!isset($h[$k])) $h[$k] = trim(preg_replace('/\r?\n[ \t]+/', ' ', substr($line, $p + 1)));
    }
    return $h;
}

function aw_mime_param(string $value, string $name): string
{
    if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\*?=\s*(?:"([^"]*)"|([^;\s]*))/i', $value, $m)) {
        $v = $m[1] !== '' ? $m[1] : $m[2];
        if (preg_match("/^([a-z0-9-]+)''(.*)$/i", $v, $x)) $v = aw_mime_utf8(rawurldecode($x[2]), $x[1]); // RFC 2231
        return $v;
    }
    return '';
}

/** Walk the MIME tree: ['text' => plain, 'html' => html, 'files' => [names]]. Depth and parts are limited. */
function aw_mime_walk(string $raw, int $depth = 0, ?array &$acc = null): array
{
    if ($acc === null) $acc = ['text' => '', 'html' => '', 'files' => [], 'parts' => 0];
    if ($depth > 6 || ++$acc['parts'] > 60) return $acc;
    $split = preg_split('/\r?\n\r?\n/', $raw, 2);
    $h = aw_mime_headers($split[0] ?? '');
    $body = $split[1] ?? '';
    $ct = strtolower($h['content-type'] ?? 'text/plain');
    $type = trim(explode(';', $ct)[0]);
    $disp = strtolower($h['content-disposition'] ?? '');
    $fname = aw_mime_param($h['content-disposition'] ?? '', 'filename') ?: aw_mime_param($h['content-type'] ?? '', 'name');
    if (strpos($type, 'multipart/') === 0) {
        $b = aw_mime_param($h['content-type'] ?? '', 'boundary');
        if ($b === '') return $acc;
        $chunks = explode('--' . $b, $body);
        array_shift($chunks); // the preamble
        foreach ($chunks as $c) {
            if (strpos($c, '--') === 0) break; // the closing boundary
            aw_mime_walk(ltrim($c, "\r\n"), $depth + 1, $acc);
        }
        return $acc;
    }
    if ($type === 'message/rfc822') return $acc; // a forwarded email: listed by Zoho, not unpacked here
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($enc === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
    elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
    $isFile = strpos($disp, 'attachment') === 0 || ($fname !== '' && strpos($type, 'text/') !== 0);
    if ($isFile) { $acc['files'][] = aw_mime_header_decode($fname !== '' ? $fname : $type); return $acc; }
    $charset = aw_mime_param($h['content-type'] ?? '', 'charset');
    if ($type === 'text/plain' && $acc['text'] === '') $acc['text'] = aw_mime_utf8($body, $charset);
    elseif ($type === 'text/html' && $acc['html'] === '') $acc['html'] = aw_mime_utf8($body, $charset);
    elseif (strpos($type, 'image/') === 0) $acc['files'][] = $fname !== '' ? aw_mime_header_decode($fname) : 'picture';
    return $acc;
}

function aw_mime_html_to_text(string $html): string
{
    $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
    $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html);
    $html = preg_replace('#<li\b[^>]*>#i', '• ', $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
}

/** The new part of a reply: everything before "On … wrote:" or a quoted block of earlier messages. */
function aw_mime_strip_quoted(string $t): string
{
    $t = str_replace("\r", '', $t);
    $cut = preg_split('/\n(?:On .{5,200}wrote:|-{2,} ?Original Message ?-{2,}|From: .+\nSent: |_{10,}\n)/i', $t, 2);
    $t = $cut[0];
    $lines = explode("\n", $t);
    while ($lines && (trim(end($lines)) === '' || strpos(ltrim(end($lines)), '>') === 0)) array_pop($lines);
    $t = trim(implode("\n", $lines));
    return $t;
}

/** Parse one raw email into what the inbox stores. */
function aw_mail_parse(string $raw): array
{
    $split = preg_split('/\r?\n\r?\n/', $raw, 2);
    $h = aw_mime_headers($split[0] ?? '');
    $acc = aw_mime_walk($raw);
    $text = $acc['text'] !== '' ? $acc['text'] : aw_mime_html_to_text($acc['html']);
    $from = aw_mime_header_decode($h['from'] ?? '');
    $email = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : (preg_match('/[^\s<>"]+@[^\s<>"]+/', $from, $m2) ? $m2[0] : '');
    $name = trim(preg_replace('/\s*<[^>]*>\s*/', '', $from), " \"'");
    $reply = trim(aw_mime_strip_quoted($text));
    return [
        'from_email' => strtolower(trim($email)), 'from_name' => $name !== '' && $name !== $email ? $name : strtok($email, '@'),
        'subject' => aw_mime_header_decode($h['subject'] ?? ''), 'message_id' => trim($h['message-id'] ?? ''),
        'date' => isset($h['date']) && strtotime($h['date']) ? gmdate('Y-m-d H:i:s', strtotime($h['date'])) : gmdate('Y-m-d H:i:s'),
        'text' => mb_substr($reply !== '' ? $reply : trim($text), 0, 20000), 'files' => array_slice(array_unique($acc['files']), 0, 20),
        'auto' => (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no') || preg_match('/^(mailer-daemon|postmaster)@/i', $email)
                  || preg_match('/(bulk|list|junk|auto_reply)/i', $h['precedence'] ?? '') || isset($h['x-autoreply']) || isset($h['x-autorespond']),
    ];
}

// ===== bringing new emails into the Team inbox ====================================================

function aw_zoho_inbox_columns(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $have = [];
        foreach ($db->query("PRAGMA table_info(contact_messages)")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['name']] = true;
        foreach (['source' => 'TEXT', 'ext_id' => 'TEXT', 'attachments' => 'TEXT'] as $col => $def) if (!isset($have[$col])) $db->exec("ALTER TABLE contact_messages ADD COLUMN $col $def");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_messages_ext ON contact_messages(ext_id)");
        return $ok = true;
    } catch (Throwable $e) { error_log('zoho inbox columns: ' . $e->getMessage()); return $ok = false; }
}

function aw_zoho_imap_settings(): array
{
    if (!function_exists('aw_mail_config')) return ['ok' => false, 'error' => 'Email is not set up on this server.'];
    $cfg = aw_mail_config();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => (string)$cfg['error']];
    $host = trim((string)aw_env('ZOHO_IMAP_HOST')) ?: trim((string)aw_env('IMAP_HOST'));
    if ($host === '') $host = preg_replace('/^smtp\./', 'imap.', (string)$cfg['host']);
    if ($host === '' || $host === $cfg['host']) $host = 'imap.zoho.com';
    $port = (int)(aw_env('ZOHO_IMAP_PORT') ?: (aw_env('IMAP_PORT') ?: 993));
    $ssl = (string)(aw_env('ZOHO_IMAP_SSL') !== '' ? aw_env('ZOHO_IMAP_SSL') : '1') !== '0';
    return ['ok' => true, 'host' => $host, 'port' => $port, 'ssl' => $ssl, 'user' => $cfg['username'], 'pass' => $cfg['password'], 'self' => strtolower($cfg['from'])];
}

function aw_zi_set(PDO $db, string $k, string $v): void
{
    try { $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]); }
    catch (Throwable $e) { error_log('zoho inbox setting: ' . $e->getMessage()); }
}

/** Plain-words advice for an IMAP failure. */
function aw_zoho_inbox_advice(string $why): string
{
    if (preg_match('/AUTHENTICATIONFAILED|invalid credentials|LOGIN failed|authentication failed|NO \[AUTH/i', $why))
        return 'Zoho refused the sign-in for reading mail. In Zoho Mail, open Settings > Mail Accounts > IMAP Access and switch it on (sending already works, so the password is right).';
    if (preg_match('/Could not reach|no answer|timed out|connection/i', $why))
        return 'The server could not connect to Zoho to read mail. If this continues, the host may block it (port 993).';
    return 'Reading the Zoho mailbox did not work: ' . mb_substr($why, 0, 200);
}

/**
 * Bring new emails into the Team inbox. $force: check now even if checked under 2 minutes ago.
 * Returns ['ok' => bool, 'added' => n, 'skipped' => n, 'message' => text].
 */
function aw_zoho_inbox_sync(PDO $db, bool $force = false): array
{
    if (!aw_zoho_inbox_columns($db)) return ['ok' => false, 'added' => 0, 'skipped' => 0, 'message' => 'The inbox table could not be prepared.'];
    $last = function_exists('aw_setting') ? (string)aw_setting('zoho_inbox_last', '') : '';
    if (!$force && $last !== '' && strtotime($last) > time() - 120) return ['ok' => true, 'added' => 0, 'skipped' => 0, 'message' => '', 'cached' => true];
    aw_zi_set($db, 'zoho_inbox_last', date('Y-m-d H:i:s'));
    $cfg = aw_zoho_imap_settings();
    if (empty($cfg['ok'])) return ['ok' => false, 'added' => 0, 'skipped' => 0, 'message' => (string)$cfg['error']];

    $fail = function (string $why) use ($db) { aw_zi_set($db, 'zoho_inbox_error', $why); error_log('zoho inbox: ' . $why); return ['ok' => false, 'added' => 0, 'skipped' => 0, 'message' => aw_zoho_inbox_advice($why)]; };
    $im = new AwImap(20);
    if (!$im->connect($cfg['host'], $cfg['port'], $cfg['ssl'])) return $fail($im->error);
    $r = $im->cmd('LOGIN ' . AwImap::q($cfg['user']) . ' ' . AwImap::q($cfg['pass']));
    if (!$r['ok']) { $im->close(); return $fail($r['status']); }
    $r = $im->cmd('SELECT INBOX');
    if (!$r['ok']) { $im->close(); return $fail($r['status']); }
    $validity = '';
    foreach ($r['lines'] as $l) if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) $validity = $m[1];
    $known = (string)aw_setting('zoho_inbox_uidvalidity', '');
    $lastUid = $known === $validity ? (int)aw_setting('zoho_inbox_uid', 0) : 0;

    $search = $lastUid > 0 ? 'UID SEARCH UID ' . ($lastUid + 1) . ':*' : 'UID SEARCH SINCE ' . date('j-M-Y', time() - AW_ZI_FIRST_DAYS * 86400);
    $r = $im->cmd($search);
    if (!$r['ok']) { $im->close(); return $fail($r['status']); }
    $uids = [];
    foreach ($r['lines'] as $l) if (stripos($l, '* SEARCH') === 0) foreach (preg_split('/\s+/', trim(substr($l, 8))) as $u) if (ctype_digit($u) && (int)$u > $lastUid) $uids[] = (int)$u;
    sort($uids);
    $uids = array_slice($uids, 0, AW_ZI_MAX_PER_SYNC);

    $added = 0; $skipped = 0; $maxUid = $lastUid;
    $ins = $db->prepare("INSERT OR IGNORE INTO contact_messages (name, email, subject, message, is_read, created_at, user_id, source, ext_id, attachments) VALUES (?, ?, ?, ?, 0, ?, ?, 'email', ?, ?)");
    $who = $db->prepare("SELECT id FROM users WHERE email = ? COLLATE NOCASE LIMIT 1");
    foreach ($uids as $uid) {
        $r = $im->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . AW_ZI_MAX_BYTES . '>)');
        if (!$r['ok']) break; // time or connection: the rest waits for the next check
        $raw = '';
        foreach ($r['lines'] as $l) if (preg_match('/\{(\d+)\}\r?\n/', $l, $m, PREG_OFFSET_CAPTURE)) { $raw = substr($l, $m[0][1] + strlen($m[0][0]), (int)$m[1][0]); break; }
        $maxUid = max($maxUid, $uid);
        if ($raw === '') { $skipped++; continue; }
        try {
            $p = aw_mail_parse($raw);
            if ($p['auto'] || $p['from_email'] === '' || $p['from_email'] === $cfg['self']) { $skipped++; continue; }
            $who->execute([$p['from_email']]);
            $uidMember = (int)$who->fetchColumn() ?: null;
            $ext = $p['message_id'] !== '' ? $p['message_id'] : 'imap:' . $validity . ':' . $uid;
            $ins->execute([mb_substr($p['from_name'], 0, 120), $p['from_email'], mb_substr($p['subject'] !== '' ? $p['subject'] : '(no subject)', 0, 250),
                           $p['text'] !== '' ? $p['text'] : '(This email has no text. Open it in Zoho to see it.)', $p['date'], $uidMember, $ext,
                           $p['files'] ? implode("\n", $p['files']) : null]);
            if ($ins->rowCount()) $added++; else $skipped++;
        } catch (Throwable $e) { $skipped++; error_log('zoho inbox message ' . $uid . ': ' . $e->getMessage()); }
    }
    $im->close();
    aw_zi_set($db, 'zoho_inbox_uidvalidity', $validity);
    aw_zi_set($db, 'zoho_inbox_uid', (string)$maxUid);
    aw_zi_set($db, 'zoho_inbox_error', '');
    return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'message' => $added ? $added . ' new ' . ($added === 1 ? 'email' : 'emails') . ' from Zoho.' : 'No new emails in Zoho.'];
}
