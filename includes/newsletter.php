<?php
// ============================================================
//  NEWSLETTER.PHP – the newsletter engine (N1)
// ============================================================
// Used by admin/manage_newsletter.php, admin/process_queue.php, the blog and reflection editors,
// newsletter.php, unsubscribe.php, newsletter_track.php and newsletter_view.php.
//
//  aw_nl_render()      branded HTML + plain-text email (brand colours, preheader, "view in browser",
//                      unsubscribe footer), with {{name}}, {{first_name}}, {{email}} personalisation
//  aw_nl_mail()        sends one message: HTML with a plain-text part, List-Unsubscribe (RFC 2369)
//                      and one-click unsubscribe (RFC 8058) headers, optional attachments
//  aw_nl_queue()       creates a campaign (email_queue row) for a list of recipients
//  aw_nl_send_batch()  sends the next batch of a campaign; every attempt is logged in
//                      newsletter_stats, so a reload never sends twice; people who unsubscribed
//                      after the campaign was queued are skipped
//  aw_nl_*_url()       signed links: unsubscribe, web version, open pixel, click tracking (a click
//                      link only redirects to the URL it was signed for, so it is not an open redirect)
//  aw_nl_save_attachment()  checks type and size and stores the file outside the web root (data/)
//  aw_nl_rate_ok(), aw_nl_audit(), CSRF helpers (same session key as includes/csrf.php)

require_once __DIR__ . '/mail_helper.php'; // loads PHPMailer and the SMTP settings (.env)

use PHPMailer\PHPMailer\PHPMailer;

if (!defined('AW_NL_BATCH')) define('AW_NL_BATCH', 20);           // 20 × the 10 s SMTP timeout stays under the host limit
if (!defined('AW_NL_MAX_FILE')) define('AW_NL_MAX_FILE', 5 * 1048576);
if (!defined('AW_NL_MAX_FILES_TOTAL')) define('AW_NL_MAX_FILES_TOTAL', 10 * 1048576);

// ---------------------------------------------------------------- CSRF (same key as includes/csrf.php)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
function aw_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}
function aw_csrf_require() {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------- signing
function aw_nl_secret(PDO $db) {
    static $secret = null;
    if ($secret !== null) return $secret;
    try {
        $s = $db->query("SELECT value FROM settings WHERE key = 'newsletter_secret'")->fetchColumn();
        if (!$s || strlen($s) < 32) {
            $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('newsletter_secret', ?)")->execute([bin2hex(random_bytes(32))]);
            $s = $db->query("SELECT value FROM settings WHERE key = 'newsletter_secret'")->fetchColumn();
        }
        $secret = (string)$s;
    } catch (Throwable $e) {
        $secret = hash('sha256', __FILE__ . (getenv('GMAIL_APP_PASSWORD') ?: 'aw')); // settings table missing: still unguessable per install
    }
    return $secret;
}
function aw_nl_sign(PDO $db, $data) {
    return substr(hash_hmac('sha256', (string)$data, aw_nl_secret($db)), 0, 32);
}
function aw_nl_b64($s) { return rtrim(strtr(base64_encode((string)$s), '+/', '-_'), '='); }
function aw_nl_unb64($s) { return (string)base64_decode(strtr((string)$s, '-_', '+/')); }

function aw_nl_unsubscribe_url($token) {
    return rtrim(SITE_URL, '/') . '/unsubscribe.php?token=' . urlencode((string)$token);
}
function aw_nl_web_url(PDO $db, $queue_id) {
    $q = (int)$queue_id;
    return rtrim(SITE_URL, '/') . '/newsletter_view.php?id=' . $q . '&s=' . aw_nl_sign($db, 'web|' . $q);
}
function aw_nl_open_url(PDO $db, $queue_id, $email) {
    $q = (int)$queue_id; $e = strtolower(trim($email));
    return rtrim(SITE_URL, '/') . '/newsletter_track.php?o=' . $q . '&e=' . aw_nl_b64($e) . '&s=' . aw_nl_sign($db, "open|$q|$e");
}
function aw_nl_click_url(PDO $db, $queue_id, $email, $url) {
    $q = (int)$queue_id; $e = strtolower(trim($email));
    return rtrim(SITE_URL, '/') . '/newsletter_track.php?c=' . $q . '&e=' . aw_nl_b64($e) . '&u=' . aw_nl_b64($url) . '&s=' . aw_nl_sign($db, "click|$q|$e|$url");
}

// ---------------------------------------------------------------- rendering
function aw_nl_first_name($name) {
    $name = trim((string)$name);
    if ($name === '') return 'friend';
    $parts = preg_split('/\s+/u', $name);
    return $parts[0];
}
function aw_nl_personalize($html, array $sub) {
    $name = trim((string)($sub['name'] ?? ''));
    return strtr((string)$html, [
        '{{name}}' => htmlspecialchars($name !== '' ? $name : 'friend'),
        '{{first_name}}' => htmlspecialchars(aw_nl_first_name($name)),
        '{{email}}' => htmlspecialchars((string)($sub['email'] ?? '')),
    ]);
}
function aw_nl_track_links($html, PDO $db, $queue_id, $email) {
    return preg_replace_callback('#\bhref\s*=\s*(["\'])(https?://[^"\']+)\1#i', function ($m) use ($db, $queue_id, $email) {
        $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (stripos($url, '/unsubscribe.php') !== false || stripos($url, '/newsletter_view.php') !== false) return $m[0];
        return 'href=' . $m[1] . htmlspecialchars(aw_nl_click_url($db, $queue_id, $email, $url)) . $m[1];
    }, (string)$html);
}
function aw_nl_html_to_text($html) {
    $t = preg_replace('#<a\b[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)</a>#is', '$3 ($2)', (string)$html);
    $t = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $t);
    $t = preg_replace('#<li\b[^>]*>#i', "• ", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t));
}

/**
 * Builds the email a subscriber receives.
 * $sub: ['email', 'name', 'unsubscribe_token'] (token may be empty for a one-off address)
 * $opts: queue_id (adds web version, open pixel, click tracking), track (default true), preview (no tracking)
 * Returns ['html' => ..., 'text' => ...].
 */
function aw_nl_render(PDO $db, $subject, $content, array $sub, array $opts = []) {
    $site = function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites';
    $base = rtrim(SITE_URL, '/');
    $logo = $base . '/' . ltrim(function_exists('aw_logo_path') ? aw_logo_path() : 'assets/images/logo.png', '/');
    $qid = $opts['queue_id'] ?? null;
    $track = $qid && ($opts['track'] ?? true) && empty($opts['preview']);
    $body = aw_nl_personalize($content, $sub);
    if ($track) $body = aw_nl_track_links($body, $db, $qid, $sub['email'] ?? '');
    $text_body = aw_nl_html_to_text($body);
    $pre = mb_substr(preg_replace('/\s+/u', ' ', aw_nl_html_to_text(aw_nl_personalize($content, $sub))), 0, 140);
    $unsub = !empty($sub['unsubscribe_token']) ? aw_nl_unsubscribe_url($sub['unsubscribe_token']) : '';
    $web = $qid ? aw_nl_web_url($db, $qid) : '';
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#F7F3ED;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F7F3ED;">' . $e($pre) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F3ED;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #EFD8D6;">'
        . ($web ? '<tr><td style="padding:10px 24px;background:#F7F3ED;font:12px Arial,sans-serif;color:#6b5a5a;text-align:right;"><a href="' . $e($web) . '" style="color:#c08a8b;">View in your browser</a></td></tr>' : '')
        . '<tr><td style="padding:26px 28px 18px;text-align:center;background:#EFD8D6;border-bottom:4px solid #DBA1A2;">'
        . '<img src="' . $e($logo) . '" alt="' . $e($site) . '" width="150" style="max-width:150px;height:auto;border:0;display:inline-block;">'
        . '<div style="font:600 11px Arial,sans-serif;letter-spacing:3px;text-transform:uppercase;color:#c08a8b;margin-top:10px;">' . $e($site) . ' Newsletter</div></td></tr>'
        . '<tr><td style="padding:30px 32px 10px;font:16px/1.7 Georgia,\'Times New Roman\',serif;color:#3d2e2e;">'
        . '<h1 style="font:700 24px/1.3 Georgia,serif;color:#2c1e1e;margin:0 0 18px;">' . $e($subject) . '</h1>'
        . $body
        . '</td></tr>'
        . '<tr><td style="padding:10px 32px 28px;text-align:center;font:22px Georgia,serif;color:#DBA1A2;">&#9829;</td></tr>'
        . '<tr><td style="padding:20px 28px;background:#F7F3ED;border-top:1px solid #EFD8D6;font:12px/1.6 Arial,sans-serif;color:#6b5a5a;text-align:center;">'
        . 'You are receiving this because you subscribed to the ' . $e($site) . ' newsletter at <a href="' . $e($base) . '" style="color:#c08a8b;">' . $e(preg_replace('#^https?://#', '', $base)) . '</a>.<br>'
        . ($unsub ? '<a href="' . $e($unsub) . '" style="color:#c08a8b;">Unsubscribe</a> · ' : '')
        . '<a href="mailto:angelwrites@zohomail.com" style="color:#c08a8b;">Reply to Angella</a><br>' . $e($site) . ', Malawi'
        . '</td></tr></table></td></tr></table>'
        . ($track ? '<img src="' . $e(aw_nl_open_url($db, $qid, $sub['email'] ?? '')) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '')
        . '</body></html>';

    $text = $subject . "\n" . str_repeat('=', min(60, mb_strlen($subject))) . "\n\n" . $text_body . "\n\n"
        . ($web ? "Read it in your browser: $web\n" : '')
        . ($unsub ? "Unsubscribe: $unsub\n" : '')
        . "$site, Malawi";
    return ['html' => $html, 'text' => $text];
}

// ---------------------------------------------------------------- sending
/**
 * Sends one message. $attachments: [['path' => ..., 'name' => ...], ...]. Returns true/false.
 */
function aw_nl_mail($to, $subject, $html, $text, $unsubscribe_url = '', array $attachments = []) {
    if (function_exists('aw_mail_test_sink')) { // test harness only (never defined on the site)
        return aw_mail_test_sink(compact('to', 'subject', 'html', 'text', 'unsubscribe_url', 'attachments'));
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('GMAIL_USERNAME');
        $mail->Password = getenv('GMAIL_APP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        // As F217: From is the Gmail account (SPF/DKIM align), replies go to the AngelWrites mailbox.
        $from = getenv('GMAIL_USERNAME') ?: 'angelwrites@zohomail.com';
        $mail->setFrom($from, (function_exists('aw_site_name') ? aw_site_name() : 'AngelWrites') . ' Newsletter');
        if (strcasecmp($from, 'angelwrites@zohomail.com') !== 0) $mail->addReplyTo('angelwrites@zohomail.com', 'Angella Bottoman');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
        if ($unsubscribe_url) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>, <mailto:angelwrites@zohomail.com?subject=unsubscribe>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $mail->addCustomHeader('Precedence', 'bulk');
        foreach ($attachments as $a) {
            if (!empty($a['path']) && is_file($a['path'])) $mail->addAttachment($a['path'], $a['name'] ?? basename($a['path']));
        }
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('aw_nl_mail failed (to: ' . $to . '): ' . $e->getMessage());
        return false;
    }
}

function aw_nl_subscriber(PDO $db, $email) {
    $st = $db->prepare("SELECT id, email, name, is_active, unsubscribe_token, unsubscribed_at FROM newsletter WHERE email = ? COLLATE NOCASE");
    $st->execute([trim((string)$email)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function aw_nl_attachments(PDO $db, $queue_id) {
    $st = $db->prepare("SELECT file_path, file_name FROM email_attachments WHERE email_queue_id = ?");
    $st->execute([(int)$queue_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $p = $a['file_path'];
        $abs = $p[0] === '/' ? $p : dirname(__DIR__) . '/' . ltrim($p, '/');
        if (is_file($abs)) $out[] = ['path' => $abs, 'name' => $a['file_name']];
    }
    return $out;
}

/** Creates a campaign. $status: 'processing' (send now) or 'pending' (scheduled). Returns the id. */
function aw_nl_queue(PDO $db, $subject, $content, array $recipients, $send_to, $segment_id = null, $scheduled_at = null, array $attachments = []) {
    $clean = [];
    foreach ($recipients as $r) {
        $r = strtolower(trim((string)$r));
        if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) $clean[$r] = true;
    }
    $status = $scheduled_at ? 'pending' : 'processing';
    $st = $db->prepare("INSERT INTO email_queue (subject, content, recipient_emails, send_to_type, segment_id, scheduled_at, status, attempt_count) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
    $st->execute([$subject, $content, implode(',', array_keys($clean)), $send_to, $segment_id ?: null, $scheduled_at ?: date('Y-m-d H:i:s'), $status]);
    $id = (int)$db->lastInsertId();
    $ins = $db->prepare("INSERT INTO email_attachments (email_queue_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)");
    foreach ($attachments as $a) $ins->execute([$id, $a['path'], $a['name'], (int)$a['size']]);
    return $id;
}

function aw_nl_progress(PDO $db, $queue_id) {
    $st = $db->prepare("SELECT * FROM email_queue WHERE id = ?");
    $st->execute([(int)$queue_id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) return null;
    $recipients = array_values(array_unique(array_filter(array_map(function ($e) { return strtolower(trim($e)); }, explode(',', (string)$q['recipient_emails'])))));
    $st = $db->prepare("SELECT lower(recipient_email), status FROM newsletter_stats WHERE email_queue_id = ?");
    $st->execute([(int)$queue_id]);
    $done = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $sent = 0; $failed = 0; $skipped = 0; $remaining = [];
    foreach ($recipients as $e) {
        if (!isset($done[$e])) $remaining[] = $e;
        elseif ($done[$e] === 'sent') $sent++;
        elseif ($done[$e] === 'skipped') $skipped++;
        else $failed++;
    }
    return ['queue' => $q, 'total' => count($recipients), 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'remaining' => $remaining];
}

/** Sends the next batch of a 'processing' campaign and finishes it when nobody is left. */
function aw_nl_send_batch(PDO $db, $queue_id, $batch = AW_NL_BATCH) {
    $p = aw_nl_progress($db, $queue_id);
    if (!$p || $p['queue']['status'] !== 'processing') return $p;
    $q = $p['queue'];
    $files = aw_nl_attachments($db, $queue_id);
    $log = $db->prepare("INSERT INTO newsletter_stats (email_queue_id, recipient_email, status) VALUES (?, ?, ?)");
    foreach (array_slice($p['remaining'], 0, $batch) as $email) {
        $sub = aw_nl_subscriber($db, $email);
        // Someone who unsubscribed after the campaign was queued is not mailed. One-off sends to an
        // address that is not on the list (send_to 'single') still go out.
        if ($sub && (int)$sub['is_active'] !== 1 && $q['send_to_type'] !== 'single') {
            $log->execute([$queue_id, $email, 'skipped']);
            continue;
        }
        $sub = $sub ?: ['email' => $email, 'name' => '', 'unsubscribe_token' => ''];
        $msg = aw_nl_render($db, $q['subject'], $q['content'], $sub, ['queue_id' => $queue_id]);
        $ok = aw_nl_mail($email, $q['subject'], $msg['html'], $msg['text'], !empty($sub['unsubscribe_token']) ? aw_nl_unsubscribe_url($sub['unsubscribe_token']) : '', $files);
        $log->execute([$queue_id, $email, $ok ? 'sent' : 'failed']);
    }
    $p = aw_nl_progress($db, $queue_id);
    if (empty($p['remaining'])) {
        $db->prepare("UPDATE email_queue SET status = ?, sent_at = CURRENT_TIMESTAMP, attempt_count = attempt_count + 1, last_error = ? WHERE id = ?")
           ->execute([$p['sent'] > 0 ? 'sent' : 'failed', $p['failed'] > 0 ? "Failed: {$p['failed']}, Sent: {$p['sent']}" : null, $queue_id]);
        $db->prepare("DELETE FROM newsletter_archive WHERE email_queue_id = ?")->execute([$queue_id]);
        $db->prepare("INSERT INTO newsletter_archive (subject, content, recipient_count, sent_at, email_queue_id) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)")
           ->execute([$q['subject'], $q['content'], $p['sent'], $queue_id]);
    } else {
        $db->prepare("UPDATE email_queue SET attempt_count = attempt_count + 1 WHERE id = ?")->execute([$queue_id]);
    }
    return aw_nl_progress($db, $queue_id);
}

/** Campaign report: sent, failed, skipped, unique opens and clicks, top links. */
function aw_nl_report(PDO $db, $queue_id) {
    $st = $db->prepare("SELECT status, COUNT(*) n, SUM(opened_at IS NOT NULL) o, SUM(clicked_at IS NOT NULL) c FROM newsletter_stats WHERE email_queue_id = ? GROUP BY status");
    $st->execute([(int)$queue_id]);
    $r = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0, 'links' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($r[$row['status']])) $r[$row['status']] = (int)$row['n'];
        $r['opened'] += (int)$row['o']; $r['clicked'] += (int)$row['c'];
    }
    $st = $db->prepare("SELECT clicked_links FROM newsletter_stats WHERE email_queue_id = ? AND clicked_links IS NOT NULL");
    $st->execute([(int)$queue_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach ((array)json_decode((string)$json, true) as $u) $r['links'][$u] = ($r['links'][$u] ?? 0) + 1;
    }
    arsort($r['links']);
    $r['open_rate'] = $r['sent'] ? round($r['opened'] / $r['sent'] * 100) : 0;
    $r['click_rate'] = $r['sent'] ? round($r['clicked'] / $r['sent'] * 100) : 0;
    return $r;
}

// ---------------------------------------------------------------- recipients
/** Active subscribers for 'all', a segment, or a list of subscriber ids. */
function aw_nl_resolve(PDO $db, $send_to, array $opts = []) {
    if ($send_to === 'all') {
        return $db->query("SELECT email FROM newsletter WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($send_to === 'segment' && !empty($opts['segment_id'])) {
        $st = $db->prepare("SELECT n.email FROM newsletter n JOIN subscriber_segment_assignments a ON a.subscriber_id = n.id WHERE a.segment_id = ? AND n.is_active = 1 ORDER BY n.id");
        $st->execute([(int)$opts['segment_id']]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($send_to === 'selected' && !empty($opts['ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array)$opts['ids'])));
        if (!$ids) return [];
        $st = $db->prepare("SELECT email FROM newsletter WHERE is_active = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($send_to === 'single' && !empty($opts['email']) && filter_var(trim($opts['email']), FILTER_VALIDATE_EMAIL)) {
        return [trim($opts['email'])];
    }
    return [];
}

// ---------------------------------------------------------------- attachments
/**
 * Validates one uploaded file and stores it outside the web root (data/newsletter_attachments/),
 * under a random name. Returns ['path','name','size'] or an error string.
 */
function aw_nl_save_attachment($name, $tmp, $size, $error) {
    $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'mp3' => 'audio/mpeg', 'txt' => 'text/plain'];
    if ($error !== UPLOAD_ERR_OK) return 'The file "' . $name . '" did not upload completely.';
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) return '"' . $name . '" is not an allowed file type (PDF, Word, images, MP3 or text).';
    if ($size > AW_NL_MAX_FILE) return '"' . $name . '" is larger than 5 MB.';
    if (function_exists('finfo_open')) {
        $mime = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp);
        $okMime = $mime === $allowed[$ext] || ($ext === 'docx' && $mime === 'application/zip') || ($ext === 'doc' && $mime === 'application/octet-stream') || ($ext === 'txt' && strpos($mime, 'text/') === 0);
        if (!$okMime) return '"' . $name . '" does not look like a real .' . $ext . ' file.';
    }
    $dir = dirname(__DIR__) . '/data/newsletter_attachments';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $target = $dir . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@move_uploaded_file($tmp, $target)) return 'Could not store "' . $name . '".';
    $clean = preg_replace('/[^\w .()-]+/u', '_', basename((string)$name));
    return ['path' => 'data/newsletter_attachments/' . basename($target), 'name' => $clean, 'size' => (int)$size];
}

// ---------------------------------------------------------------- misc
function aw_nl_rate_ok($key, $limit, $window) {
    $file = function_exists('aw_ratelimit_file') ? aw_ratelimit_file('nl_' . $key) : sys_get_temp_dir() . '/nl_' . md5($key);
    $now = time();
    $hits = [];
    if (is_file($file)) $hits = array_filter(array_map('intval', explode(',', (string)@file_get_contents($file))), function ($t) use ($now, $window) { return $t > $now - $window; });
    if (count($hits) >= $limit) return false;
    $hits[] = $now;
    @file_put_contents($file, implode(',', $hits), LOCK_EX);
    return true;
}

function aw_nl_audit(PDO $db, $action, $details = '') {
    try {
        $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)")
           ->execute([$_SESSION['user_id'] ?? null, $action, mb_substr((string)$details, 0, 500), $_SERVER['REMOTE_ADDR'] ?? 'cli']);
    } catch (Throwable $e) {
        error_log('newsletter audit: ' . $e->getMessage());
    }
}

/** Subscriber status from the stored columns: active, pending (awaiting confirmation) or unsubscribed. */
function aw_nl_status(array $sub) {
    if ((int)$sub['is_active'] === 1) return 'active';
    return empty($sub['unsubscribed_at']) ? 'pending' : 'unsubscribed';
}
