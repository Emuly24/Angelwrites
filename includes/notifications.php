<?php
// F114: the notifications table, the header bell and notifications.php existed, but nothing ever
// created a notification. awNotify() adds one; it never breaks the page that calls it.
if (!function_exists('awNotify')) {
    function awNotify($db, $user_id, $type, $title, $message, $link = null) {
        if (!$user_id) return;
        try {
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)")
               ->execute([(int)$user_id, $type, $title, $message, $link]);
        } catch (Throwable $e) {
            error_log('awNotify: ' . $e->getMessage());
        }
    }
}
