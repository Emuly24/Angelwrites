<?php
// Emails to the Zoho mailbox reach the Team inbox (and the staff's notification bell) without anyone
// opening the inbox page: at most every 5 minutes, after a page has been delivered, the site reads new
// mail (includes/zoho_inbox.php). Reading Zoho can take several seconds, so it only runs where the
// server can hand the page over first, or on a staff member's own page view; the 10-minute timer
// (admin/process_queue.php) also runs it. Nothing here can break the page.
if (!defined('AW_INBOX_BG_EVERY')) define('AW_INBOX_BG_EVERY', 300);

function aw_inbox_bg_maybe(PDO $db) {
    if (PHP_SAPI === 'cli' || defined('AW_NL_AUTO_OFF')) return;
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'inbox.php') return; // that page reads mail itself
    // decided at the end of the request, when the visitor's session (and role) is known
    register_shutdown_function(function () use ($db) {
        try {
            $detachable = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
            $staff = in_array((string)($_SESSION['role'] ?? ''), ['admin', 'moderator'], true);
            if (!$detachable && !$staff) return;
            $now = time();
            $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('zoho_inbox_bg', '0')");
            $claim = $db->prepare("UPDATE settings SET value = ? WHERE key = 'zoho_inbox_bg' AND CAST(value AS INTEGER) <= ?");
            $claim->execute([(string)$now, $now - AW_INBOX_BG_EVERY]);
            if ($claim->rowCount() !== 1) return;
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @set_time_limit(40);
            require_once __DIR__ . '/zoho_inbox.php';
            aw_zoho_inbox_sync($db, false);
        } catch (Throwable $e) { error_log('inbox bg: ' . $e->getMessage()); }
    });
}
