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
 * Send an email via SMTP.
 *
 * Returns true on success and false on failure. It never stops the page:
 * callers have usually already saved the user's action, so a mail failure
 * is logged and the request carries on.
 */
function sendEmail($to, $subject, $message, $fromEmail = 'no-reply@angelwrites.gt.tc', $fromName = 'AngelWrites', $isHTML = true) {
    $mail = new PHPMailer(true);
    $GLOBALS['aw_mail_last_error'] = '';
    try {
        // ET1: the provider and its settings come from .env; a missing setting is named plainly and
        // nothing is sent (this also covers VF3's "say exactly what is missing").
        $cfg = aw_mail_config();
        if (empty($cfg['ok'])) {
            throw new \RuntimeException($cfg['error']);
        }
        aw_mail_transport($mail, $cfg);
        // ET1: From is always the mailbox we sign in as (ZOHO_FROM_ADDRESS, or the Gmail account on the
        // fallback path), so SPF/DKIM align. The $fromEmail argument is kept for existing callers but no
        // longer chooses the sender. This replaces F217's "From = Gmail, Reply-To = Zoho".
        $mail->setFrom($cfg['from'], ($fromName === 'AngelWrites' && $cfg['from_name'] !== '') ? $cfg['from_name'] : $fromName);
        $mail->Sender = $cfg['from'];
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->isHTML($isHTML);
        // F91: optional attachment as 7th/8th argument (path, file name). admin/process_book.php
        // passed one, but the arguments were ignored, so the file was silently dropped. (Read with
        // func_get_args() so the signature line stays unchanged.)
        $aw_extra = func_get_args();
        if (!empty($aw_extra[6]) && is_file($aw_extra[6])) {
            $mail->addAttachment($aw_extra[6], !empty($aw_extra[7]) ? $aw_extra[7] : basename($aw_extra[6]));
        }
        // E1: every email uses the AngelWrites layout (logo, fonts, colours, Angella's signature) with a
        // plain-text part, in UTF-8 (the default was Latin-1, which garbled ’ é …). A 9th argument
        // ['signature' => false] leaves the signature out; mail to the admin gets a website footer.
        require_once __DIR__ . '/email_template.php';
        [$aw_html, $aw_text] = aw_email_brand($subject, $message, $isHTML, array_merge(['to' => $to], (array)($aw_extra[8] ?? [])));
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Body    = $aw_html;
        $mail->AltBody = $aw_text;
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('sendEmail failed (to: ' . $to . ', subject: ' . $subject . '): ' . $e->getMessage());
        // VF3: keep the reason so the admin can see it (Settings > Email) without server logs
        $why = $e->getMessage();
        if (!empty($mail->ErrorInfo) && strpos($why, $mail->ErrorInfo) === false) $why .= ' — ' . $mail->ErrorInfo;
        $GLOBALS['aw_mail_last_error'] = $why;
        aw_mail_log_failure($to, $subject, $why);
        return false;
    }
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
