<?php
// ============================================================
//  PROCESS_QUEUE.PHP – send scheduled newsletters (N7)
// ============================================================
// From the command line (a cron job, if the host ever offers one):  php admin/process_queue.php
//   sends every scheduled campaign whose time has come, through the same batch sender as the admin
//   page (each address once, unsubscribed people skipped, attachments included, archived at the end).
// In the browser it was an admin GET that sent mail (so a link on another site could trigger it) and
// a second sender that re-mailed everyone after a partial failure. It now only accepts the CSRF-protected
// "Send queued now" button of the admin page, which sends in pages there.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/newsletter.php';

if (php_sapi_name() !== 'cli') {
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue');
    exit;
}

$due = $db->prepare("SELECT id, subject FROM email_queue WHERE (status = 'pending' AND scheduled_at <= ?) OR status = 'processing' ORDER BY scheduled_at ASC");
$due->execute([date('Y-m-d H:i:s')]);
$rows = $due->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "Nothing is due.\n";
    exit(0);
}
foreach ($rows as $row) {
    $db->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ? AND status = 'pending'")->execute([$row['id']]);
    do {
        $p = aw_nl_send_batch($db, $row['id']);
    } while ($p && !empty($p['remaining']) && $p['queue']['status'] === 'processing');
    printf("Campaign #%d \"%s\": %d sent, %d failed, %d skipped of %d.\n", $row['id'], $row['subject'], $p['sent'] ?? 0, $p['failed'] ?? 0, $p['skipped'] ?? 0, $p['total'] ?? 0);
    aw_nl_audit($db, 'cron_send', "Campaign #{$row['id']}");
}
