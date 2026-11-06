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
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->isHTML($isHTML);
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('sendEmail failed (to: ' . $to . ', subject: ' . $subject . '): ' . $e->getMessage());
        return false;
    }
}
