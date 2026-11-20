<?php
// ============================================================
//  PROCESS_QUEUE.PHP – send scheduled newsletters (N7)
// ============================================================
// Three ways in, all through the same batch sender (each address once, unsubscribed people skipped,
// attachments included, archived at the end; a per-campaign lock stops double sends):
//  - the web timer: a request with the header  X-Cron-Key: <NEWSLETTER_CRON_KEY from .env>  (or POST key=)
//    (.github/workflows/newsletter-schedule.yml calls it every 10 minutes). Sends for up to ~20 s and
//    answers with a short JSON report. Without the key set in .env this door stays closed.
//  - the command line:  php admin/process_queue.php  sends everything that is due, to the end.
//  - an admin opening it in the browser is taken to the Campaigns tab (no mail is sent by a plain GET).
define('AW_NL_AUTO_OFF', true); // this request is the sender; db.php need not start another
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/newsletter.php';
require_once __DIR__ . '/../includes/newsletter_auto.php';

if (php_sapi_name() !== 'cli') {
    $key = aw_nl_cron_key();
    // the header, or a POST field "key" in case a proxy drops unknown headers
    $sent_key = (string)($_SERVER['HTTP_X_CRON_KEY'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['key'] ?? '') : ''));
    if ($sent_key !== '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if ($key === '' || strlen($key) < 16 || !hash_equals($key, $sent_key)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $key === '' ? 'NEWSLETTER_CRON_KEY is not set in .env' : 'wrong key']);
            exit;
        }
        @set_time_limit(40);
        ignore_user_abort(true);
        $r = aw_nl_auto_run($db, 20, 5);
        // the same timer brings new emails from Zoho into the Team inbox (at most every 2 minutes)
        $zi = null;
        try { require_once __DIR__ . '/../includes/zoho_inbox.php'; $zi = aw_zoho_inbox_sync($db, false); } catch (Throwable $e) { error_log('cron inbox: ' . $e->getMessage()); }
        $next = $db->query("SELECT scheduled_at FROM email_queue WHERE status = 'pending' ORDER BY scheduled_at LIMIT 1")->fetchColumn();
        echo json_encode(['ok' => $r !== false] + ($r ?: []) + ['next_scheduled_utc' => $next ?: null, 'inbox' => $zi ? ($zi['message'] ?: 'checked recently') : 'not checked']);
        exit;
    }
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue');
    exit;
}

$total = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
do {
    $r = aw_nl_auto_run($db, 300, AW_NL_BATCH);
    if ($r === false) { echo "Sending stopped: see the error log.\n"; exit(1); }
    foreach ($total as $k => $v) $total[$k] += $r[$k];
    $progress = $r['sent'] + $r['failed'] + $r['skipped'];
} while ($r['left'] > 0 && $progress > 0);
printf("%d sent, %d failed, %d skipped. %d campaign(s) still sending.\n", $total['sent'], $total['failed'], $total['skipped'], $r['left']);
