<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/email_signature.php';

/**
 * ET1: the site's mail goes through Zoho Mail, and only Zoho (the old Gmail route is removed).
 *
 * The one value that must be in the .env file on the server is the Zoho app password:
 *     ZOHO_APP_PASSWORD=xxxxxxxxxxxx
 * Everything else has a working default and only needs a line in .env to change it:
 *     ZOHO_USERNAME      angelwrites@zohomail.com   (the Zoho mailbox that signs in and sends)
 *     ZOHO_FROM_ADDRESS  the same as ZOHO_USERNAME (Zoho only sends From the signed-in mailbox)
 *     ZOHO_FROM_NAME     AngelWrites
 *     ZOHO_SMTP_HOST     smtp.zoho.com              (smtp.zoho.eu / smtp.zoho.in for those regions)
 *     ZOHO_SMTP_PORT     587 (STARTTLS); if the host cannot reach it, 465 (SSL) is tried once
 * In Zoho Mail: Settings > Mail Accounts > IMAP/POP/SMTP access must be on, and with two-step sign-in
 * the password is an app-specific password (Security > App Passwords), not the account password.
 *
 * Returns ['ok' => true, provider, host, port, username, password, from, from_name] or ['ok' => false, 'error' => why].
 */
/** The names each mail setting may have in .env: the documented one first, then common other spellings. */
const AW_MAIL_KEY_NAMES = [
    'password'  => ['ZOHO_APP_PASSWORD', 'ZOHO_PASSWORD', 'ZOHO_MAIL_PASSWORD', 'ZOHO_SMTP_PASSWORD', 'ZOHO_APP_PASS', 'ZOHO_PASS', 'SMTP_PASSWORD', 'SMTP_PASS', 'MAIL_PASSWORD', 'EMAIL_PASSWORD', 'MAIL_PASS'],
    'username'  => ['ZOHO_USERNAME', 'ZOHO_USER', 'ZOHO_EMAIL', 'ZOHO_MAIL', 'ZOHO_SMTP_USER', 'ZOHO_SMTP_USERNAME', 'SMTP_USERNAME', 'SMTP_USER', 'MAIL_USERNAME', 'MAIL_USER', 'EMAIL_USERNAME', 'EMAIL_USER'],
    'from'      => ['ZOHO_FROM_ADDRESS', 'ZOHO_FROM', 'MAIL_FROM', 'MAIL_FROM_ADDRESS', 'EMAIL_FROM'],
    'from_name' => ['ZOHO_FROM_NAME', 'MAIL_FROM_NAME', 'EMAIL_FROM_NAME'],
    'host'      => ['ZOHO_SMTP_HOST', 'ZOHO_HOST', 'SMTP_HOST', 'MAIL_HOST'],
    'port'      => ['ZOHO_SMTP_PORT', 'ZOHO_PORT', 'SMTP_PORT', 'MAIL_PORT'],
];
/** Zoho's mail servers by data centre; an account only signs in on its own one. */
const AW_ZOHO_HOSTS = ['smtp.zoho.com', 'smtp.zoho.eu', 'smtp.zoho.in', 'smtp.zoho.com.au', 'smtp.zoho.jp', 'smtp.zohocloud.ca', 'smtp.zoho.sa', 'smtp.zoho.com.cn'];

/** The first of a setting's names that has a value: [name, value], or ['', '']. */
function aw_mail_key(string $kind): array
{
    foreach (AW_MAIL_KEY_NAMES[$kind] ?? [] as $n) { $v = trim((string)aw_env($n)); if ($v !== '') return [$n, $v]; }
    return ['', ''];
}

function aw_mail_config(): array
{
    $user = aw_mail_key('username')[1] ?: 'angelwrites@zohomail.com';
    $pass = aw_mail_key('password')[1];
    // app passwords are often copied with spaces between groups ("abcd efgh ijkl"): Zoho wants them without
    if (preg_match('/^[A-Za-z0-9]{4}(\s+[A-Za-z0-9]{4}){2,}$/', $pass)) $pass = preg_replace('/\s+/', '', $pass);
    $from = aw_mail_key('from')[1] ?: $user;
    if ($pass === '') {
        return ['ok' => false, 'provider' => 'zoho',
                'error' => 'Email is not switched on yet: the site found no Zoho password in the .env file (it looks for ZOHO_APP_PASSWORD, or names like ZOHO_PASSWORD or SMTP_PASSWORD). Nothing was sent.'];
    }
    if (!filter_var($user, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'provider' => 'zoho', 'error' => 'The Zoho username (or From address) in the .env file is not a full email address, like angelwrites@zohomail.com. Nothing was sent.'];
    }
    $port = (int)(aw_mail_key('port')[1] ?: '587');
    if (!in_array($port, [587, 465], true)) $port = 587;
    // the host: from .env if given; else the Zoho data centre this site last signed in to; else zoho.com
    [$hostName, $host] = aw_mail_key('host');
    $learned = function_exists('aw_setting') ? (string)aw_setting('mail_smtp_host_detected', '') : '';
    return ['ok' => true, 'provider' => 'zoho', 'host' => $host !== '' ? $host : ($learned !== '' ? $learned : 'smtp.zoho.com'), 'host_fixed' => $host !== '', 'port' => $port,
            'username' => $user, 'password' => $pass, 'from' => $from, 'from_name' => aw_mail_key('from_name')[1] ?: 'AngelWrites'];
}

/** ET1: applies the SMTP settings to a PHPMailer object. Shared by sendEmail() and the newsletter. */
function aw_mail_transport(PHPMailer $mail, array $cfg): void
{
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = (int)$cfg['port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$cfg['port'];
    $mail->Timeout    = 12; // PHPMailer's default is 300 s, longer than the host allows a request to run
    $mail->CharSet    = 'UTF-8';
    // inbox, not junk: no "X-Mailer: PHPMailer" header (spam filters score it), and a Message-ID from the
    // sending mailbox's own domain rather than the hosting server's name
    $mail->XMailer    = ' ';
    $dom = strtolower((string)substr((string)strrchr((string)($cfg['from'] ?? $cfg['username']), '@'), 1));
    if ($dom !== '') $mail->Hostname = $dom;
    $mail->Encoding   = PHPMailer::ENCODING_QUOTED_PRINTABLE;
}

/**
 * Send, and if the server could not even be reached on the configured port, try Zoho's other
 * port once (587 STARTTLS <-> 465 SSL): some hosts block one of them. A wrong password or a
 * refused address is not retried. Throws like PHPMailer::send() when both fail.
 */
function aw_mail_send(PHPMailer $mail, array $cfg): bool
{
    try {
        return $mail->send();
    } catch (\Throwable $e) {
        $msg = $e->getMessage() . ' ' . $mail->ErrorInfo;
        if (aw_mail_is_auth_error($msg) && empty($cfg['host_fixed'])) {
            // the password may be right but for another Zoho data centre (zoho.eu, zoho.in…): try those once
            foreach (AW_ZOHO_HOSTS as $h) {
                if ($h === $cfg['host']) continue;
                $alt = $cfg; $alt['host'] = $h;
                $mail->smtpClose();
                aw_mail_transport($mail, $alt);
                try {
                    if ($mail->send()) { aw_mail_remember_host($h); error_log('mail: signed in on ' . $h . ', remembered for next time'); return true; }
                } catch (\Throwable $e2) {
                    if (!aw_mail_is_auth_error($e2->getMessage() . ' ' . $mail->ErrorInfo)) break;
                }
            }
            $mail->smtpClose();
            aw_mail_transport($mail, $cfg);
            throw $e;
        }
        if (!preg_match('/could not connect|connect\(\) failed|connection (refused|timed out)|timed out|network is unreachable|failed to connect/i', $msg)) throw $e;
        $alt = $cfg;
        $alt['port'] = (int)$cfg['port'] === 465 ? 587 : 465;
        aw_mail_transport($mail, $alt);
        $mail->smtpClose();
        error_log('mail: port ' . (int)$cfg['port'] . ' unreachable, trying ' . $alt['port']);
        return $mail->send();
    }
}

function aw_mail_is_auth_error(string $msg): bool
{
    return (bool)preg_match('/could not authenticate|authentication (failed|unsuccessful)|\b535\b|invalid (login|credentials)|username and password not accepted/i', $msg);
}

/** Remember the Zoho data centre that accepted the sign-in, so later emails go there first. */
function aw_mail_remember_host(string $host): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO) return;
    try { $db->prepare("INSERT INTO settings (key, value) VALUES ('mail_smtp_host_detected', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$host]); }
    catch (\Throwable $e) { error_log('mail host: ' . $e->getMessage()); }
}

/**
 * ET2: the address replies are pointed at, so an accidental "Reply" never reaches a monitored inbox.
 *
 * Normally noreply@<the domain of the From address>. One exception: when From is a mailbox at a
 * shared provider (zohomail.com, gmail.com, ...), "noreply@" at that domain is not ours and could
 * belong to a stranger, who would then receive members' replies. In that case the site's own host
 * name is used instead (noreply@angelwrites.gt.tc), which has no mailbox, so the reply bounces.
 * MAIL_NOREPLY_ADDRESS in .env overrides both.
 */
function aw_mail_noreply_address(string $from): string
{
    $override = trim((string)aw_env('MAIL_NOREPLY_ADDRESS'));
    if ($override !== '' && filter_var($override, FILTER_VALIDATE_EMAIL)) return $override;
    $domain = strtolower((string)substr((string)strrchr($from, '@'), 1));
    $shared = ['zohomail.com', 'zohomail.eu', 'zohomail.in', 'zoho.com', 'zoho.eu', 'zoho.in', 'gmail.com', 'googlemail.com',
               'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com'];
    if ($domain === '' || in_array($domain, $shared, true)) {
        $host = strtolower((string)parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST));
        if ($host !== '') $domain = preg_replace('/^www\./', '', $host);
    }
    return 'noreply@' . ($domain !== '' ? $domain : 'invalid.invalid');
}

/** ET2: the sentence that ends every automated (transactional) email. */
function aw_mail_automated_note(): string
{
    return 'This is an automated message from ' . (function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites') . '. Please do not reply to this email.';
}

/**
 * ET2: builds a ready-to-send PHPMailer object. One place for sender, Reply-To, layout and footer,
 * shared by sendEmail(), sendConversationalEmail() and sendEmailWithAttachment().
 *
 * $m: to, subject, message, is_html, from_name,
 *     kind  'transactional' (default): automated-message footer
 *           'conversational': From "<name>", a button to $m['reply_url'] and a "not monitored" note
 *     reply_url, reply_label, attachment ['path' =>, 'name' =>], opts (passed to the template)
 * Throws on a configuration problem; callers catch.
 */
function aw_mail_prepare(array $m): PHPMailer
{
    require_once __DIR__ . '/email_template.php';
    $cfg = aw_mail_config();
    if (empty($cfg['ok'])) {
        throw new \RuntimeException($cfg['error']);
    }
    $kind = ($m['kind'] ?? 'transactional') === 'conversational' ? 'conversational' : 'transactional';
    $fromName = trim((string)($m['from_name'] ?? '')) ?: 'AngelWrites';
    if ($fromName === 'AngelWrites' && $cfg['from_name'] !== '') $fromName = $cfg['from_name'];
    $fromName = trim(str_replace(["\r", "\n"], ' ', $fromName));

    $mail = new PHPMailer(true);
    aw_mail_transport($mail, $cfg);
    // From is always the mailbox we sign in as (SPF/DKIM align). Replies go back to that same mailbox:
    // a Reply-To on another domain without a mailbox, or "(no reply)" in the name, sends mail to junk.
    $mail->setFrom($cfg['from'], $fromName);
    $mail->Sender = $cfg['from'];
    $mail->addReplyTo($cfg['from'], $fromName);
    $mail->addAddress((string)$m['to']);
    $mail->Subject = (string)$m['subject'];
    // a reply to an email someone sent us: keep it in their conversation (Team inbox, includes/zoho_inbox.php)
    $irt = trim((string)($m['opts']['in_reply_to'] ?? ''));
    if ($irt !== '' && preg_match('/^<[^<>\s]+>$/', $irt)) { $mail->addCustomHeader('In-Reply-To', $irt); $mail->addCustomHeader('References', $irt); }

    $opts = array_merge(['to' => (string)$m['to']], (array)($m['opts'] ?? []));
    if ($kind === 'conversational') {
        $url = (string)($m['reply_url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) $url = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/'; // never a broken or script link
        $label = trim((string)($m['reply_label'] ?? '')) ?: 'Reply in the app';
        $note = 'Replies to this email are not monitored. Use the button above to reach us.';
        // Inline styles only: email clients strip modern CSS. Deep rose from the design tokens (--aw-rose-deep).
        $opts['append_html'] =
              '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:28px auto 12px;"><tr>'
            . '<td align="center" bgcolor="#a8676a" style="background:#a8676a;border-radius:30px;">'
            . '<a href="' . aw_email_e($url) . '" style="display:inline-block;padding:14px 36px;font:600 16px \'Inter\',Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none;border-radius:30px;">'
            . aw_email_e($label) . '</a></td></tr></table>'
            . '<p style="margin:0 0 6px;font:13px/1.6 \'Inter\',Arial,Helvetica,sans-serif;color:#6b5a5a;text-align:center;">' . aw_email_e($note) . '</p>';
        $opts['append_text'] = $label . ': ' . $url . "\n\n" . str_replace('button above', 'link above', $note);
        $opts['footer_note'] = $note;
    } else {
        $opts['footer_note'] = aw_mail_automated_note();
    }

    $GLOBALS['AW_EMAIL_INLINE_LOGO'] = aw_mail_embed_logo($mail);
    [$html, $text] = aw_email_brand((string)$m['subject'], (string)$m['message'], !empty($m['is_html']), $opts);
    $GLOBALS['AW_EMAIL_INLINE_LOGO'] = false;
    $html = aw_signature_apply($mail, $html); // her handwritten signature, when this email is signed
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $text;

    if (!empty($m['attachment'])) {
        $path = (string)($m['attachment']['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('The file to attach could not be read (' . basename($path) . '). Nothing was sent.');
        }
        $mail->addAttachment($path, (string)($m['attachment']['name'] ?? '') ?: basename($path));
    }
    return $mail;
}

/**
 * Put the logo inside the email (Content-ID "awlogo") instead of linking to it on the site, so the
 * email has no outside images. Returns true when embedded; the layout then uses cid:awlogo.
 */
function aw_mail_embed_logo(PHPMailer $mail): bool
{
    $rel = function_exists('aw_logo_path') ? aw_logo_path() : 'assets/images/logo.png';
    $file = dirname(__DIR__) . '/' . ltrim($rel, '/');
    if (!is_file($file) || filesize($file) > 200000) $file = dirname(__DIR__) . '/assets/images/logo.png';
    if (!is_file($file)) return false;
    try { return $mail->addEmbeddedImage($file, 'awlogo', 'angelwrites-logo.' . strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: 'png')); }
    catch (\Throwable $e) { error_log('mail logo: ' . $e->getMessage()); return false; }
}

/** ET2: prepare, send, and on any failure log it and return false. Nothing escapes; nothing dies. */
function aw_mail_deliver(array $m, string $fn): bool
{
    $GLOBALS['aw_mail_last_error'] = '';
    $mail = null;
    try {
        if (function_exists('aw_mail_test_sink')) { // test harness only (never defined on the site)
            $mail = aw_mail_prepare($m);
            $mail->preSend();
            return (bool)aw_mail_test_sink(['fn' => $fn, 'to' => $m['to'], 'subject' => $m['subject'], 'mime' => $mail->getSentMIMEMessage(), 'host' => $mail->Host, 'port' => $mail->Port, 'user' => $mail->Username]);
        }
        $mail = aw_mail_prepare($m);
        aw_mail_send($mail, aw_mail_config());
        return true;
    } catch (\Throwable $e) {
        error_log($fn . ' failed (to: ' . ($m['to'] ?? '') . ', subject: ' . ($m['subject'] ?? '') . '): ' . $e->getMessage());
        // VF3: keep the reason so the admin can see it (Settings > Email) without server logs
        $why = $e->getMessage();
        if ($mail && !empty($mail->ErrorInfo) && strpos($why, $mail->ErrorInfo) === false) $why .= ' — ' . $mail->ErrorInfo;
        $GLOBALS['aw_mail_last_error'] = $why;
        aw_mail_log_failure((string)($m['to'] ?? ''), (string)($m['subject'] ?? ''), $why);
        return false;
    }
}

/**
 * Send a TRANSACTIONAL email (the default): codes, receipts, alerts, confirmations. Nobody is
 * expected to answer it.
 *
 *   From      the site's mailbox, shown as "<fromName>"
 *   Reply-To  the same mailbox (a no-reply address on another domain made mail land in junk)
 *   Footer    "This is an automated message from AngelWrites. Please do not reply to this email."
 *
 * Returns true on success and false on failure. It never stops the page: callers have usually
 * already saved the user's action, so a mail failure is logged and the request carries on.
 * The signature is unchanged. $fromEmail is accepted for existing callers but no longer chooses
 * the sender (ET1). An optional 9th argument, ['signature' => false], leaves Angella's signature out.
 * It does not take attachments: use sendEmailWithAttachment() (ET3).
 */
function sendEmail($to, $subject, $message, $fromEmail = 'angelwrites@zohomail.com', $fromName = 'AngelWrites', $isHTML = true) {
    $aw_extra = func_get_args();
    return aw_mail_deliver([
        'kind' => 'transactional', 'to' => $to, 'subject' => $subject, 'message' => $message,
        'is_html' => $isHTML, 'from_name' => $fromName,
        'opts' => (array)($aw_extra[8] ?? []),
    ], 'sendEmail');
}

/**
 * ET2: send a CONVERSATIONAL email: the person is expected to answer, and the answer belongs on
 * the site, not in an inbox.
 *
 *   From      the site's mailbox, shown as "<fromName>"
 *   Reply-To  the same no-reply address, so a reply by email fails cleanly instead of sitting unread
 *   Body      $body, then a prominent button to $replyUrl labelled $replyLabel, then
 *             "Replies to this email are not monitored. Use the button above to reach us."
 *
 * $replyUrl must be a full http(s) URL (build it from SITE_URL). Returns false on failure, logs,
 * never dies. An optional 7th argument is passed to the template, e.g. ['signature' => false].
 */
function sendConversationalEmail($to, $subject, $body, $replyUrl, $replyLabel = 'Reply in the app', $fromName = 'AngelWrites') {
    $aw_extra = func_get_args();
    return aw_mail_deliver([
        'kind' => 'conversational', 'to' => $to, 'subject' => $subject, 'message' => $body, 'is_html' => true,
        'from_name' => $fromName, 'reply_url' => $replyUrl, 'reply_label' => $replyLabel,
        'opts' => (array)($aw_extra[6] ?? []),
    ], 'sendConversationalEmail');
}

/**
 * ET3 (F91): send a transactional email with one file attached.
 *
 * Same SMTP settings, From and Reply-To as sendEmail(). If the file cannot be read, or the send
 * fails, NOTHING is sent and false is returned (and logged). It never falls back to sending the
 * message without the file: a missing attachment that looks like a success is what F91 was.
 */
function sendEmailWithAttachment($to, $subject, $body, $filePath, $fileName, $fromName = 'AngelWrites') {
    return aw_mail_deliver([
        'kind' => 'transactional', 'to' => $to, 'subject' => $subject, 'message' => $body, 'is_html' => true,
        'from_name' => $fromName,
        'attachment' => ['path' => (string)$filePath, 'name' => (string)$fileName],
    ], 'sendEmailWithAttachment');
}

/**
 * VF3: remember the last 50 failed emails in data/mail_errors.log (data/ is not reachable from the web),
 * so Admin > Settings > Email can show why verification codes are not arriving.
 */
function aw_mail_log_failure($to, $subject, $why) {
    $file = __DIR__ . '/../data/mail_errors.log';
    $line = json_encode(['at' => date('Y-m-d H:i:s'), 'to' => (string)$to, 'subject' => (string)$subject,
                         'error' => mb_substr((string)$why, 0, 400)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines = is_file($file) ? (array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $lines[] = $line;
    @file_put_contents($file, implode("\n", array_slice($lines, -50)) . "\n", LOCK_EX);
}

/** VF3: the failures recorded above, newest first. */
function aw_mail_recent_failures($limit = 10) {
    $file = __DIR__ . '/../data/mail_errors.log';
    if (!is_file($file)) return [];
    $out = [];
    foreach (array_reverse((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $l) {
        $r = json_decode($l, true);
        if (is_array($r)) $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Admin > Settings > Email > "Check email": each step of sending, in plain words, so the reason
 * email fails is visible without server logs. Never shows a password, only which name it was found
 * under and its length. Returns [[status ok|bad|warn, title, detail], ...].
 */
function aw_mail_diagnose(): array
{
    $out = [];
    $rep = function_exists('aw_env_report') ? aw_env_report() : ['file' => '', 'count' => 0];
    [$pn, $pv] = aw_mail_key('password');
    $out[] = $rep['file'] !== '' ? ['ok', 'Settings file', 'Found: ' . $rep['file'] . ' (' . (int)$rep['count'] . ' settings).']
                                 : ($pn !== '' ? ['warn', 'Settings file', 'No .env file was found, but the server itself provides the mail settings.']
                                               : ['bad', 'Settings file', 'No .env file could be read, so there is no Zoho password.']);
    [$un, $uv] = aw_mail_key('username');
    $out[] = $pn !== '' ? ['ok', 'Zoho password', 'Found as ' . $pn . ' (' . strlen(preg_replace('/\s+/', '', $pv)) . ' characters' . (preg_match('/\s/', $pv) ? ', spaces removed' : '') . ').']
                        : ['bad', 'Zoho password', 'Not found. Add a line ZOHO_APP_PASSWORD=your-app-password to the .env file.'];
    $out[] = $un !== '' ? ['ok', 'Zoho mailbox', 'Found as ' . $un . ': ' . $uv . '.'] : ['warn', 'Zoho mailbox', 'Not set, so angelwrites@zohomail.com is used. If your mailbox is different, add ZOHO_USERNAME=your@address.'];
    $cfg = aw_mail_config();
    if (empty($cfg['ok'])) { $out[] = ['bad', 'Ready to send', (string)$cfg['error']]; return $out; }

    // can the hosting server reach Zoho at all?
    $host = $cfg['host'];
    $ip = gethostbyname($host);
    if ($ip === $host) { $out[] = ['bad', 'Finding Zoho', 'The server could not look up ' . $host . '. Check ZOHO_SMTP_HOST, or try again later.']; return $out; }
    $reach = [];
    foreach ([587 => 'tcp://', 465 => 'ssl://'] as $port => $scheme) {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 6);
        if ($fp) { fclose($fp); $reach[$port] = true; } else { $reach[$port] = $errstr !== '' ? $errstr : 'no answer'; }
    }
    $open = array_keys(array_filter($reach, function ($r) { return $r === true; }));
    if (!$open) {
        $out[] = ['bad', 'Reaching Zoho', 'The hosting server cannot connect to ' . $host . ' on port 587 (' . $reach[587] . ') or 465 (' . $reach[465] . '). The host is blocking outgoing email connections; no password can fix this. Ask the host, or move to hosting that allows SMTP.'];
        return $out;
    }
    $out[] = ['ok', 'Reaching Zoho', $host . ' answers on port ' . implode(' and ', $open) . '.'];

    // sign in, as sending does; on a refused password try Zoho's other data centres
    $try = function (string $h, int $port) use ($cfg) {
        $m = new PHPMailer(true);
        $c = $cfg; $c['host'] = $h; $c['port'] = $port;
        aw_mail_transport($m, $c);
        try { $ok = $m->smtpConnect(); $m->smtpClose(); return $ok ? true : ($m->ErrorInfo ?: 'refused'); }
        catch (\Throwable $e) { return trim($e->getMessage() . ' ' . $m->ErrorInfo); }
    };
    $port = in_array((int)$cfg['port'], $open, true) ? (int)$cfg['port'] : $open[0];
    $r = $try($host, $port);
    if ($r === true) { $out[] = ['ok', 'Signing in', 'Zoho accepted the mailbox and password on ' . $host . '. Email should work: use “Send test email” to be sure.']; return $out; }
    if (aw_mail_is_auth_error($r) && empty($cfg['host_fixed'])) {
        foreach (AW_ZOHO_HOSTS as $h) {
            if ($h === $host || gethostbyname($h) === $h) continue;
            if ($try($h, $port) === true) {
                aw_mail_remember_host($h);
                $out[] = ['ok', 'Signing in', 'Your Zoho account is on ' . $h . ' (not ' . $host . '). The site will use it from now on. Email should work now.'];
                return $out;
            }
        }
    }
    if (aw_mail_is_auth_error($r)) {
        $out[] = ['bad', 'Signing in', 'Zoho refused the sign-in (' . mb_substr($r, 0, 160) . '). In Zoho Mail check: (1) Settings > Mail Accounts > IMAP/POP/SMTP access is ON for ' . $cfg['username'] . '; (2) if your Zoho account has two-step sign-in, ZOHO_APP_PASSWORD must be an app password made in Zoho > My Account > Security > App Passwords, not your normal password; (3) the mailbox in ZOHO_USERNAME is the one the password belongs to. Some free Zoho plans do not allow SMTP at all; then Zoho refuses every password.'];
    } else {
        $out[] = ['bad', 'Signing in', 'Zoho did not let the site sign in: ' . mb_substr($r, 0, 200)];
    }
    return $out;
}

/**
 * A plain test email: text only, no logo, no links, no layout. Sent next to the normal test email it
 * shows where junk-marking comes from: if this one reaches the inbox and the normal one does not, the
 * content or the links (the free gt.tc address) are the cause; if both go to junk, it is the sender's
 * reputation. Returns true when sent; the reason is in $GLOBALS['aw_mail_last_error'] otherwise.
 */
function aw_mail_send_plain(string $to, string $subject, string $text): bool
{
    $GLOBALS['aw_mail_last_error'] = '';
    try {
        $cfg = aw_mail_config();
        if (empty($cfg['ok'])) throw new \RuntimeException($cfg['error']);
        $mail = new PHPMailer(true);
        aw_mail_transport($mail, $cfg);
        $mail->setFrom($cfg['from'], $cfg['from_name']);
        $mail->Sender = $cfg['from'];
        $mail->addReplyTo($cfg['from'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body = $text;
        if (function_exists('aw_mail_test_sink')) return (bool)aw_mail_test_sink(['to' => $to, 'subject' => $subject, 'mime' => ($mail->preSend() ? $mail->getSentMIMEMessage() : '')]);
        return aw_mail_send($mail, $cfg);
    } catch (\Throwable $e) {
        $GLOBALS['aw_mail_last_error'] = $e->getMessage();
        aw_mail_log_failure($to, $subject, $e->getMessage());
        return false;
    }
}
