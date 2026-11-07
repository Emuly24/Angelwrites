<?php
// ============================================================
//  NEWSLETTER_TRACK.PHP – opens and clicks (N1)
// ============================================================
// ?o=<campaign>&e=<email>&s=<sig>          open pixel: records the first open, returns a 1×1 GIF
// ?c=<campaign>&e=<email>&u=<url>&s=<sig>  click: records the link, then redirects to it
// Every link is signed (includes/newsletter.php), so this page only redirects to URLs that were in
// a newsletter Angella sent: it cannot be used as an open redirect, and stats cannot be forged.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/newsletter.php';

$email = strtolower(aw_nl_unb64($_GET['e'] ?? ''));
$sig = (string)($_GET['s'] ?? '');

if (isset($_GET['c'])) {
    $qid = (int)$_GET['c'];
    $url = aw_nl_unb64($_GET['u'] ?? '');
    $valid = preg_match('#^https?://#i', $url) && hash_equals(aw_nl_sign($db, "click|$qid|$email|$url"), $sig);
    if (!$valid) {
        header('Location: ' . SITE_URL . '/');
        exit;
    }
    try {
        $st = $db->prepare("SELECT id, clicked_links FROM newsletter_stats WHERE email_queue_id = ? AND lower(recipient_email) = ? ORDER BY id LIMIT 1");
        $st->execute([$qid, $email]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $links = (array)json_decode((string)$row['clicked_links'], true);
            if (!in_array($url, $links, true) && count($links) < 50) $links[] = $url;
            // A click also proves the email was opened (images may be blocked).
            $db->prepare("UPDATE newsletter_stats SET clicked_at = COALESCE(clicked_at, CURRENT_TIMESTAMP), opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP), clicked_links = ? WHERE id = ?")
               ->execute([json_encode(array_values($links)), $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('newsletter click: ' . $e->getMessage());
    }
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

if (isset($_GET['o'])) {
    $qid = (int)$_GET['o'];
    if (hash_equals(aw_nl_sign($db, "open|$qid|$email"), $sig)) {
        try {
            $db->prepare("UPDATE newsletter_stats SET opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP), user_agent = COALESCE(user_agent, ?) WHERE email_queue_id = ? AND lower(recipient_email) = ?")
               ->execute([mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $qid, $email]);
        } catch (Throwable $e) {
            error_log('newsletter open: ' . $e->getMessage());
        }
    }
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
