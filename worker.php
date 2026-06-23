#!/usr/bin/env php
<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/mail_helper.php';

while (true) {
    $stmt = $db->prepare("SELECT * FROM jobs WHERE status='pending' ORDER BY id LIMIT 1");
    $stmt->execute();
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        sleep(1);
        continue;
    }
    $db->prepare("UPDATE jobs SET status='processing', attempted_at=NOW() WHERE id=?")->execute([$job['id']]);
    try {
        $payload = json_decode($job['payload'], true);
        if ($job['job_type'] === 'process_comment') {
            // Handle private comment email to admin
            if ($payload['is_private']) {
                $stmt = $db->prepare("SELECT email FROM users WHERE role='admin' LIMIT 1");
                $stmt->execute();
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($admin) {
                    sendEmail($admin['email'], 'Private comment on poem: ' . $payload['poem_title'], 
                        "A private comment was posted:\n\n" . $payload['comment_text'], 
                        'angelwrites@zohomail.com', 'AngelWrites');
                }
            }
            // Notify tagged users (extract @username from comment)
            // You can implement by scanning $payload['comment_text'] for @mentions
            // and inserting notifications into the notifications table.
        }
        $db->prepare("UPDATE jobs SET status='done' WHERE id=?")->execute([$job['id']]);
    } catch (Exception $e) {
        $db->prepare("UPDATE jobs SET status='failed' WHERE id=?")->execute([$job['id']]);
        error_log("Job {$job['id']} failed: " . $e->getMessage());
    }
}