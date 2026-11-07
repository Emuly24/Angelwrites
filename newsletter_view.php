<?php
// ============================================================
//  NEWSLETTER_VIEW.PHP – "View in your browser" (N1)
// ============================================================
// Shows a sent newsletter as a web page, from the signed link in its header. Personal details are
// not shown ({{name}} reads "friend") and nothing is tracked.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/newsletter.php';

$qid = (int)($_GET['id'] ?? 0);
$st = $db->prepare("SELECT subject, content FROM email_queue WHERE id = ?");
$st->execute([$qid]);
$q = $st->fetch(PDO::FETCH_ASSOC);
if (!$q || !hash_equals(aw_nl_sign($db, 'web|' . $qid), (string)($_GET['s'] ?? ''))) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Newsletter not found</title><p style="font-family:Georgia,serif;text-align:center;margin:60px 16px;">This newsletter link is not valid. <a href="' . htmlspecialchars(SITE_URL) . '">Visit AngelWrites</a></p>';
    exit;
}
$msg = aw_nl_render($db, $q['subject'], $q['content'], ['email' => '', 'name' => '', 'unsubscribe_token' => ''], ['preview' => true]);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo $msg['html'];
