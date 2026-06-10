<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/load_env.php';

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
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->isHTML($isHTML);
        $mail->send();
        return true;
    } catch (Exception $e) {
        // DIE WITH THE REAL ERROR
        die('<div style="background:#fee2e2; color:#991b1b; padding:20px; border-radius:8px; max-width:600px; margin:40px auto;">' .
            '<h3>🔥 SMTP Error</h3>' .
            '<p><strong>Message:</strong> ' . $e->getMessage() . '</p>' .
            '<p><strong>File:</strong> ' . $e->getFile() . '</p>' .
            '<p><strong>Line:</strong> ' . $e->getLine() . '</p>' .
            '</div>');
    }
}