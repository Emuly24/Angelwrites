<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

// Only admin or CLI can run this
if (php_sapi_name() !== 'cli' && !isAdmin()) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

echo "Processing email queue...\n";

// Fetch pending emails that are due to be sent
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
    // Mark as processing
    $stmt = $db->prepare("UPDATE email_queue SET status = 'processing', attempt_count = attempt_count + 1 WHERE id = ?");
    $stmt->execute([$q['id']]);

    // Fetch attachments
    $stmt = $db->prepare("SELECT file_path FROM email_attachments WHERE email_queue_id = ?");
    $stmt->execute([$q['id']]);
    $attachments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Send to each recipient
    $recipients = explode(',', $q['recipient_emails']);
    $success_count = 0;
    $fail_count = 0;

    foreach ($recipients as $email) {
        $email = trim($email);
        $stmt = $db->prepare("SELECT unsubscribe_token FROM newsletter WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $token = $existing ? $existing['unsubscribe_token'] : bin2hex(random_bytes(32));
        $unsubscribe_link = SITE_URL . '/unsubscribe.php?token=' . $token;
        $full_message = $q['content'] . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";

        if (sendEmail($email, $q['subject'], $full_message, 'angelwrites@zohomail.com', 'AngelWrites Newsletter')) {
            $success_count++;
        } else {
            $fail_count++;
        }
        usleep(500000);
    }

    // Update queue status
    if ($fail_count === 0) {
        $stmt = $db->prepare("UPDATE email_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$q['id']]);
        $sent_count++;
        echo "✅ Sent: {$q['subject']} ($success_count recipients)\n";
    } else {
        $stmt = $db->prepare("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?");
        $stmt->execute(["Failed for $fail_count of " . count($recipients) . " recipients", $q['id']]);
        $failed_count++;
        echo "❌ Failed: {$q['subject']} ($success_count sent, $fail_count failed)\n";
    }
}

echo "\n";
echo "Total sent: $sent_count\n";
echo "Total failed: $failed_count\n";
echo "Queue processing complete.\n";