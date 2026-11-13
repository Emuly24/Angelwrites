<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/load_env.php';

/**
 * ET1: which SMTP service sends the site's mail.
 *
 * MAIL_PROVIDER=zoho   Zoho SMTP: ZOHO_SMTP_HOST, port 587, STARTTLS, signing in with ZOHO_USERNAME /
 *                      ZOHO_APP_PASSWORD and sending From ZOHO_FROM_ADDRESS / ZOHO_FROM_NAME.
 * MAIL_PROVIDER=gmail  the previous Gmail path (GMAIL_USERNAME / GMAIL_APP_PASSWORD), kept as a fallback
 *                      for a Zoho outage.
 * Not set              gmail, so a missing variable cannot break email that works today.
 *
 * Every value comes from .env; nothing is hard-coded here. Returns
 * ['ok' => true, provider, host, port, username, password, from, from_name] or ['ok' => false, 'error' => why].
 */
function aw_mail_config(): array
{
    $provider = strtolower(trim((string)getenv('MAIL_PROVIDER')));
    if ($provider === '') $provider = 'gmail';

    if ($provider === 'zoho') {
        $need = ['ZOHO_SMTP_HOST', 'ZOHO_USERNAME', 'ZOHO_APP_PASSWORD', 'ZOHO_FROM_ADDRESS', 'ZOHO_FROM_NAME'];
        $missing = [];
        $v = [];
        foreach ($need as $k) {
            $v[$k] = trim((string)getenv($k));
            if ($v[$k] === '') $missing[] = $k;
        }
        if ($missing) {
            return ['ok' => false, 'provider' => 'zoho',
                    'error' => 'Email is set to Zoho (MAIL_PROVIDER=zoho) but ' . implode(', ', $missing) . ' '
                             . (count($missing) === 1 ? 'is' : 'are') . ' missing from the .env file. Nothing was sent.'];
        }
        if (!filter_var($v['ZOHO_FROM_ADDRESS'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'provider' => 'zoho', 'error' => 'ZOHO_FROM_ADDRESS in the .env file is not a valid email address. Nothing was sent.'];
        }
        return ['ok' => true, 'provider' => 'zoho', 'host' => $v['ZOHO_SMTP_HOST'], 'port' => 587,
                'username' => $v['ZOHO_USERNAME'], 'password' => $v['ZOHO_APP_PASSWORD'],
                'from' => $v['ZOHO_FROM_ADDRESS'], 'from_name' => $v['ZOHO_FROM_NAME']];
    }

    if ($provider === 'gmail') {
        $user = trim((string)getenv('GMAIL_USERNAME'));
        $pass = (string)getenv('GMAIL_APP_PASSWORD');
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'provider' => 'gmail',
                    'error' => 'Email is not set up on the server: GMAIL_USERNAME and/or GMAIL_APP_PASSWORD are missing from the .env file'
                             . (trim((string)getenv('MAIL_PROVIDER')) === '' ? ' (MAIL_PROVIDER is not set, so Gmail is used; set MAIL_PROVIDER=zoho to send through Zoho).' : '.')];
        }
        // F217 still applies on this path: through Gmail's SMTP the From must be the Gmail account.
        return ['ok' => true, 'provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587,
                'username' => $user, 'password' => $pass, 'from' => $user, 'from_name' => ''];
    }

    return ['ok' => false, 'provider' => $provider,
            'error' => 'MAIL_PROVIDER in the .env file must be "zoho" or "gmail" (it is "' . $provider . '"). Nothing was sent.'];
}

/** ET1: applies the SMTP settings to a PHPMailer object. Shared by sendEmail() and the newsletter. */
function aw_mail_transport(PHPMailer $mail, array $cfg): void
{
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$cfg['port'];
    $mail->Timeout    = 10; // PHPMailer's default is 300 s, longer than the host allows a request to run
    $mail->CharSet    = 'UTF-8';
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
    $override = trim((string)getenv('MAIL_NOREPLY_ADDRESS'));
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
 *     kind  'transactional' (default): From "<name> (no reply)", automated-message footer
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
    // From is always the mailbox we sign in as (SPF/DKIM align). Transactional mail says so in the name.
    $mail->setFrom($cfg['from'], $kind === 'transactional' ? $fromName . ' (no reply)' : $fromName);
    $mail->Sender = $cfg['from'];
    $mail->addReplyTo(aw_mail_noreply_address($cfg['from']), $kind === 'transactional' ? $fromName . ' (no reply)' : $fromName . ' (replies not monitored)');
    $mail->addAddress((string)$m['to']);
    $mail->Subject = (string)$m['subject'];

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

    [$html, $text] = aw_email_brand((string)$m['subject'], (string)$m['message'], !empty($m['is_html']), $opts);
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
        $mail->send();
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
 *   From      the site's mailbox, shown as "<fromName> (no reply)"
 *   Reply-To  a no-reply address (see aw_mail_noreply_address())
 *   Footer    "This is an automated message from AngelWrites. Please do not reply to this email."
 *
 * Returns true on success and false on failure. It never stops the page: callers have usually
 * already saved the user's action, so a mail failure is logged and the request carries on.
 * The signature is unchanged. $fromEmail is accepted for existing callers but no longer chooses
 * the sender (ET1). An optional 9th argument, ['signature' => false], leaves Angella's signature out.
 * It does not take attachments: use sendEmailWithAttachment() (ET3).
 */
function sendEmail($to, $subject, $message, $fromEmail = 'no-reply@angelwrites.gt.tc', $fromName = 'AngelWrites', $isHTML = true) {
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
