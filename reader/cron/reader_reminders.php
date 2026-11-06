<?php
// ============================================================
//  READER_REMINDERS.PHP – Daily cron script for email reminders
//  Sends reminders to users who haven't read in 3+ days.
// ============================================================

// F13: '../includes/' resolved against reader/cron/ (or the CLI's working directory), so the
// script fatalled however it was started. Paths are now relative to this file.
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail_helper.php';

// S13 (F212): only run via CLI, or over HTTP with the key from the READER_CRON_KEY env
// var sent in an X-Cron-Key header (never in the URL, where it lands in logs and history).
// With READER_CRON_KEY unset the HTTP route is closed.
$cron_key = (string)getenv('READER_CRON_KEY');
$sent_key = (string)($_SERVER['HTTP_X_CRON_KEY'] ?? '');
if (php_sapi_name() !== 'cli' && ($cron_key === '' || !hash_equals($cron_key, $sent_key))) {
    http_response_code(403);
    die('Access denied.');
}

echo "Reading reminder cron started...\n";

// Find users with reading progress but no activity in 3+ days
$stmt = $db->prepare("
    SELECT DISTINCT rp.user_id, rp.book_id, rp.progress_percent, 
           u.email, u.name, b.title as book_title,
           rp.last_accessed_at,
           (SELECT COUNT(*) FROM reading_sessions WHERE user_id = rp.user_id AND book_id = rp.book_id AND end_time IS NOT NULL) as session_count
    FROM reading_progress rp
    JOIN users u ON rp.user_id = u.id
    JOIN books b ON rp.book_id = b.id
    WHERE rp.progress_percent < 100
    AND rp.last_accessed_at < date('now', '-3 days')
    AND NOT EXISTS (
        SELECT 1 FROM reader_admin_notifications
        WHERE user_id = rp.user_id
        AND book_id = rp.book_id
        AND event_type = 'reminder_sent'
        AND created_at > date('now', '-7 days')
    )
    ORDER BY rp.last_accessed_at ASC
");

$stmt->execute();
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent_count = 0;

foreach ($reminders as $reminder) {
    $user_id = $reminder['user_id'];
    $book_id = $reminder['book_id'];
    $email = $reminder['email'];
    $name = $reminder['name'] ?? 'Reader';
    $book_title = $reminder['book_title'];
    $progress = $reminder['progress_percent'];
    $last_accessed = date('F j, Y', strtotime($reminder['last_accessed_at']));
    $remaining = 100 - $progress;

    // Build email
    $subject = "📚 You left off in '$book_title'";
    $body = "<h2>You left off in '$book_title'</h2>";
    $body .= "<p>Hello $name,</p>";
    $body .= "<p>You last read on <strong>$last_accessed</strong>.</p>";
    $body .= "<p>You were <strong>$progress%</strong> through the book — only <strong>$remaining%</strong> left to finish!</p>";
    $body .= "<p>Don't let the story wait. <a href='" . SITE_URL . "/reader/reader.php?id=$book_id'>Continue reading</a>.</p>";
    $body .= "<hr>";
    $body .= "<p style='font-size:0.8rem; color:#999;'>— AngelWrites</p>";

    if (sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites')) {
        // Log that we sent a reminder
        $stmt = $db->prepare("INSERT INTO reader_admin_notifications (user_id, book_id, event_type, event_data) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $book_id, 'reminder_sent', json_encode(['email' => $email, 'progress' => $progress])]);
        $sent_count++;
        echo "Reminder sent to $email for $book_title\n";
    } else {
        echo "Failed to send reminder to $email\n";
    }

    // Rate limit
    usleep(500000);
}

echo "\nReminders sent: $sent_count\n";
echo "Cron completed.\n";