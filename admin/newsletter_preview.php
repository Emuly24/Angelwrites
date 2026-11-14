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
} else {
    $st = $db->prepare("SELECT subject, content FROM newsletter_archive WHERE id = ?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) { http_response_code(404); exit('Archive not found.'); }
    [$subject, $content] = [$item['subject'], $item['content']];
}
$msg = aw_nl_render($db, $subject, $content, $sample, ['preview' => true, 'signature' => !empty($_POST['add_signature'])]);
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo '<div style="font:13px Arial,sans-serif;background:#2c1e1e;color:#fff;padding:8px 12px;text-align:center;">Preview · personalised for a sample reader “Grace Banda” · links are not tracked</div>';
echo $msg['html'];
