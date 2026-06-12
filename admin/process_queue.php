<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

// Only CLI or admin
if (php_sapi_name() !== 'cli' && !isAdmin()) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

echo "Processing email queue...\n";

$stmt = $db->prepare("
    SELECT * FROM email_queue 
    WHERE status = 'pending' AND scheduled_at <= CURRENT_TIMESTAMP
    ORDER BY scheduled_at ASC
    LIMIT 10
");
$stmt->execute();
$queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent_count = 0;
$failed_count = 0;

foreach ($queue as $q) {
    $stmt = $db->prepare("UPDATE email_queue SET status = 'processing', attempt_count = attempt_count + 1 WHERE id = ?");
    $stmt->execute([$q['id']]);

    $stmt = $db->prepare("SELECT file_path FROM email_attachments WHERE email_queue_id = ?");
    $stmt->execute([$q['id']]);
    $attachments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $recipients = explode(',', $q['recipient_emails']);
    $success = 0;
    $fail = 0;

    foreach ($recipients as $email) {
        $email = trim($email);
        $stmt = $db->prepare("SELECT unsubscribe_token FROM newsletter WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $token = $existing ? $existing['unsubscribe_token'] : bin2hex(random_bytes(32));
        $unsubscribe_link = SITE_URL . '/unsubscribe.php?token=' . $token;
        $full_message = $q['content'] . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";

        if (sendEmail($email, $q['subject'], $full_message, 'angelwrites@zohomail.com', 'AngelWrites Newsletter')) {
            $success++;
            $stmt = $db->prepare("INSERT INTO newsletter_stats (email_queue_id, recipient_email, status) VALUES (?, ?, 'sent')");
            $stmt->execute([$q['id'], $email]);
        } else {
            $fail++;
        }
        usleep(500000);
    }

    if ($fail === 0) {
        $stmt = $db->prepare("UPDATE email_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$q['id']]);
        $stmt = $db->prepare("INSERT INTO newsletter_archive (subject, content, recipient_count, sent_at, email_queue_id) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)");
        $stmt->execute([$q['subject'], $q['content'], count($recipients), $q['id']]);
        $sent_count++;
        echo "✅ Sent: {$q['subject']} ({$success}/" . count($recipients) . ")\n";
    } else {
        $stmt = $db->prepare("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?");
        $stmt->execute(["Failed $fail of " . count($recipients) . " recipients", $q['id']]);
        $failed_count++;
        echo "❌ Failed: {$q['subject']} (success: $success, fail: $fail)\n";
    }
}

echo "\nTotal sent: $sent_count, failed: $failed_count\n";
echo "Queue processing complete.\n";