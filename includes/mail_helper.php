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
    try {
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
        return false;
    }
}
