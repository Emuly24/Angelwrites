<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Send an email as admin@angelwrites.gt.tc using Gmail SMTP
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message Email body (HTML or plain text)
 * @param string $fromEmail Sender email (default: admin@angelwrites.gt.tc)
 * @param string $fromName Sender name (default: AngelWrites Admin)
 * @param bool $isHTML Whether the message is HTML (default: true)
 * @return bool True on success, false on failure
 */
function sendAdminEmail($to, $subject, $message, $fromEmail = 'admin@angelwrites.gt.tc', $fromName = 'AngelWrites Admin', $isHTML = true) {
    $mail = new PHPMailer(true);
    try {
        // SMTP settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'blessingsemulyn@gmail.com';  
        $mail->Password   = 'yklj fatl czmc txcd';   
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender and recipient
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        // Content
        $mail->Subject = $subject;
        $mail->Body    = $message;
        
        if ($isHTML) {
            $mail->isHTML(true);
        } else {
            $mail->isHTML(false);
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        // For debugging, you can log the error:
        // error_log("Admin mail error: " . $mail->ErrorInfo);
        return false;
    }
}