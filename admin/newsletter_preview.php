<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

aw_require('manage_newsletter'); // K1
// N4: shows exactly what subscribers receive (logo, colours, footer). POST previews the draft being
// written on the Compose tab (with the admin's details for {{name}}); ?id= shows an archived issue.
require_once __DIR__ . '/../includes/newsletter.php';

$sample = ['email' => 'reader@example.com', 'name' => 'Grace Banda', 'unsubscribe_token' => 'preview'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    aw_csrf_require();
    $subject = trim((string)($_POST['subject'] ?? ''));
    $content = (string)($_POST['content'] ?? '');
} elseif (empty($_GET['id'])) {
    // opened directly (no draft, no issue): explain instead of a "not found" page
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Newsletter preview</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 16px;text-align:center;color:#3d2e2e">'
       . '<h1 style="font-family:Georgia,serif;color:#9c5457">Nothing to preview yet</h1>'
       . '<p>Write an issue on the Compose tab and press Preview, or open Preview next to an issue in the Archive.</p>'
       . '<p><a href="' . htmlspecialchars(SITE_URL) . '/admin/manage_newsletter.php" style="color:#9c5457;font-weight:600">Back to the newsletter</a></p></div>';
    exit;
} else {
    $st = $db->prepare("SELECT subject, content FROM newsletter_archive WHERE id = ?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) { http_response_code(404); exit('Archive not found.'); }
    [$subject, $content] = [$item['subject'], $item['content']];
}
$msg = aw_nl_render($db, $subject !== '' ? $subject : 'Your subject appears here', $content, $sample, ['preview' => true, 'signature' => !empty($_POST['add_signature']),
    'preheader' => trim((string)($_POST['preheader'] ?? '')), 'latest' => !empty($_POST['latest']), 'junk_note' => true]);
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
// raw=1: the composer's live preview pane (no banner); otherwise a full page with a note on top
if (empty($_POST['raw'])) echo '<div style="font:13px Arial,sans-serif;background:#2c1e1e;color:#fff;padding:8px 12px;text-align:center;">Preview · personalised for a sample reader “Grace Banda” · links are not tracked</div>';
echo $msg['html'];
