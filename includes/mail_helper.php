<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/load_env.php';

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
        // VF3: say exactly what is missing instead of a vague SMTP authentication error
        if (!getenv('GMAIL_USERNAME') || !getenv('GMAIL_APP_PASSWORD')) {
            throw new \RuntimeException('Email is not set up on the server: GMAIL_USERNAME and/or GMAIL_APP_PASSWORD are missing from the .env file.');
        }
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('GMAIL_USERNAME');
        $mail->Password   = getenv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 10; // PHPMailer's default is 300 s, longer than the host allows a request to run
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        // F217: mail goes through Gmail's SMTP, so From must be the Gmail account itself
        // (SPF/DKIM/DMARC then align with gmail.com). The caller's address (normally the
        // Zoho mailbox) becomes Reply-To, so replies still arrive there.
        $smtpUser = getenv('GMAIL_USERNAME');
        $mail->setFrom($smtpUser ?: $fromEmail, $fromName);
        $mail->Sender = $smtpUser ?: $fromEmail; // envelope sender too (the setFrom above already set it)
        $replyTo = (stripos($fromEmail, 'no-reply@') === 0) ? 'angelwrites@zohomail.com' : $fromEmail;
        if (strcasecmp($replyTo, (string)$smtpUser) !== 0) {
            $mail->addReplyTo($replyTo, $fromName);
        }
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
