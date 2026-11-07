<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();
// S13 (F175): the newsletter_tabs/ partials refuse to render unless included from here.
define('AW_NEWSLETTER_ADMIN', true);

// N2: the send engine (F169's paged sender, rendering, tracking, attachments) lives in
// includes/newsletter.php. Every action below checks the CSRF token, then redirects back (so a
// reload never repeats it and the lists are always current).
require_once __DIR__ . '/../includes/newsletter.php';
require_once __DIR__ . '/../includes/newsletter_optin.php';
require_once __DIR__ . '/../includes/pagination.php';
if (!defined('AW_NEWSLETTER_ADMIN')) define('AW_NEWSLETTER_ADMIN', true);

function aw_nl_flash($type, $msg) { $_SESSION['aw_nl_flash'] = [$type, $msg]; }
function aw_nl_back($tab, array $extra = []) {
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?' . http_build_query(array_merge(['tab' => $tab], $extra)));
    exit;
}
function aw_nl_ids($csv) {
    return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$csv)))));
}

// ====== HANDLE POST ACTIONS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        aw_nl_flash('error', 'The upload was larger than this host accepts. Use smaller attachments (5 MB each, 10 MB in total).');
        aw_nl_back('send');
    }
    aw_csrf_require();
    $action = $_POST['action'] ?? '';
    $is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    // ---- send or schedule a campaign
    if ($action === 'send_newsletter') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $send_to = in_array($_POST['send_to'] ?? '', ['all', 'segment', 'selected', 'single'], true) ? $_POST['send_to'] : 'all';
        $segment_id = (int)($_POST['segment_id'] ?? 0);
        $_SESSION['aw_nl_draft'] = ['subject' => $subject, 'content' => $content];
        if ($subject === '' || trim(strip_tags($content, '<img>')) === '') {
            aw_nl_flash('error', 'Please write a subject and a message.');
            aw_nl_back('send');
        }
        $recipients = aw_nl_resolve($db, $send_to, ['segment_id' => $segment_id, 'ids' => aw_nl_ids($_POST['selected_ids'] ?? ''), 'email' => $_POST['single_email'] ?? '']);
        if (!$recipients) {
            aw_nl_flash('error', $send_to === 'single' ? 'Please enter a valid email address.' : 'Nobody to send to: choose active subscribers, a segment with active members, or an email address.');
            aw_nl_back('send');
        }
        $scheduled_at = null;
        if (($_POST['schedule_type'] ?? 'now') === 'later') {
            $ts = strtotime((string)($_POST['schedule'] ?? '')); // F171: an unparseable date is refused, not stored as 1970
            if ($ts === false) { aw_nl_flash('error', 'Please choose a valid date and time.'); aw_nl_back('send'); }
            $scheduled_at = date('Y-m-d H:i:s', $ts);
        }
        $files = []; $total = 0;
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $k => $name) {
                if ($name === '') continue;
                $r = aw_nl_save_attachment($name, $_FILES['attachments']['tmp_name'][$k], (int)$_FILES['attachments']['size'][$k], $_FILES['attachments']['error'][$k]);
                if (is_string($r)) { aw_nl_flash('error', $r); aw_nl_back('send'); }
                $total += $r['size'];
                $files[] = $r;
            }
            if ($total > AW_NL_MAX_FILES_TOTAL) { aw_nl_flash('error', 'Attachments may be 10 MB in total.'); aw_nl_back('send'); }
        }
        if (!empty($_POST['add_signature'])) $content .= AW_NL_SIG_MARK; // E2
        $qid = aw_nl_queue($db, $subject, $content, $recipients, $send_to, $send_to === 'segment' ? $segment_id : null, $scheduled_at, $files);
        aw_nl_audit($db, $scheduled_at ? 'schedule_newsletter' : 'send_newsletter', "Campaign #$qid \"$subject\" to " . count($recipients) . " ($send_to)");
        unset($_SESSION['aw_nl_draft']);
        if ($scheduled_at) {
            aw_nl_flash('success', 'Scheduled for ' . date('F j, Y, g:i a', strtotime($scheduled_at)) . ' (' . count($recipients) . ' recipients). Press "Send queued now" on the Campaigns tab on or after that time.');
            aw_nl_back('queue');
        }
        aw_nl_back('queue', ['sending' => $qid]);
    }

    // ---- test send: one email, nothing queued or archived
    if ($action === 'test_send') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $to = trim((string)($_POST['test_email'] ?? '')) ?: aw_admin_email();
        $ok = false; $msg = '';
        if ($subject === '' || $content === '') $msg = 'Please write a subject and a message first.';
        elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) $msg = 'Please enter a valid test address.';
        elseif (!aw_nl_rate_ok('test_' . ($_SESSION['user_id'] ?? 0), 10, 600)) $msg = 'Too many test emails; please wait a few minutes.';
        else {
            $sub = aw_nl_subscriber($db, $to) ?: ['email' => $to, 'name' => 'Angella', 'unsubscribe_token' => ''];
            $m = aw_nl_render($db, '[Test] ' . $subject, $content, $sub, ['preview' => true, 'signature' => !empty($_POST['add_signature'])]);
            $ok = aw_nl_mail($to, '[Test] ' . $subject, $m['html'], $m['text']);
            $msg = $ok ? "Test email sent to $to." : "The test email to $to could not be sent. Check the mail settings.";
            aw_nl_audit($db, 'test_send', "To $to: $subject");
        }
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => $ok, 'message' => $msg]); exit; }
        aw_nl_flash($ok ? 'success' : 'error', $msg);
        aw_nl_back('send');
    }

    // ---- paged sending, scheduled sends, retries (F169, F170, F172)
    if ($action === 'send_batch') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        if (!aw_nl_send_batch($db, $qid)) { aw_nl_flash('error', 'Campaign not found.'); aw_nl_back('queue'); }
        aw_nl_back('queue', ['sending' => $qid]);
    }
    if ($action === 'send_due') {
        $st = $db->prepare("SELECT id FROM email_queue WHERE status = 'pending' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT 1");
        $st->execute([date('Y-m-d H:i:s')]);
        $due = (int)$st->fetchColumn();
        if (!$due) { aw_nl_flash('success', 'Nothing is due yet. Scheduled newsletters can be sent on or after their date.'); aw_nl_back('queue'); }
        $db->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ?")->execute([$due]);
        aw_nl_back('queue', ['sending' => $due]);
    }
    if ($action === 'resend_failed') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        $st = $db->prepare("SELECT id FROM email_queue WHERE id = ? AND status IN ('failed', 'sent')");
        $st->execute([$qid]);
        if (!$st->fetchColumn()) { aw_nl_flash('error', 'Campaign not found.'); aw_nl_back('queue'); }
        $db->prepare("DELETE FROM newsletter_stats WHERE email_queue_id = ? AND status = 'failed'")->execute([$qid]);
        $db->prepare("UPDATE email_queue SET status = 'processing', last_error = NULL WHERE id = ?")->execute([$qid]);
        aw_nl_audit($db, 'retry_failed', "Campaign #$qid");
        aw_nl_back('queue', ['sending' => $qid]);
    }
    // These buttons were links to ?cancel_queue= / ?delete_queue= / ?delete_archive= that nothing handled.
    if ($action === 'cancel_queue') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        $db->prepare("UPDATE email_queue SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'processing')")->execute([$qid]);
        aw_nl_audit($db, 'cancel_campaign', "Campaign #$qid");
        aw_nl_flash('success', 'Campaign cancelled. Anyone not yet emailed will not receive it.');
        aw_nl_back('queue');
    }
    if ($action === 'delete_queue') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        $st = $db->prepare("SELECT file_path FROM email_attachments WHERE email_queue_id = ?");
        $st->execute([$qid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
            if (strpos($f, 'data/newsletter_attachments/') === 0) @unlink(dirname(__DIR__) . '/' . $f);
        }
        foreach (['email_attachments', 'newsletter_stats', 'newsletter_archive'] as $t) {
            $db->prepare("DELETE FROM $t WHERE email_queue_id = ?")->execute([$qid]);
        }
        $db->prepare("DELETE FROM email_queue WHERE id = ?")->execute([$qid]);
        aw_nl_audit($db, 'delete_campaign', "Campaign #$qid");
        aw_nl_flash('success', 'Campaign deleted.');
        aw_nl_back('queue');
    }
    if ($action === 'delete_archive') {
        $aid = (int)($_POST['archive_id'] ?? 0);
        $db->prepare("DELETE FROM newsletter_archive WHERE id = ?")->execute([$aid]);
        aw_nl_audit($db, 'delete_archive', "Archive #$aid");
        aw_nl_flash('success', 'Removed from the archive (the campaign and its statistics stay on the Queue tab).');
        aw_nl_back('archive');
    }

    // ---- templates
    if ($action === 'save_template') {
        $name = trim((string)($_POST['template_name'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $_SESSION['aw_nl_draft'] = ['subject' => $subject, 'content' => $content];
        if ($name === '' || $subject === '' || $content === '') { aw_nl_flash('error', 'A template needs a name, a subject and a message.'); aw_nl_back('send'); }
        $st = $db->prepare("SELECT id FROM newsletter_templates WHERE name = ?");
        $st->execute([$name]);
        if ($tid = $st->fetchColumn()) {
            $db->prepare("UPDATE newsletter_templates SET subject = ?, content = ? WHERE id = ?")->execute([$subject, $content, $tid]);
        } else {
            $db->prepare("INSERT INTO newsletter_templates (name, subject, content) VALUES (?, ?, ?)")->execute([$name, $subject, $content]);
        }
        aw_nl_audit($db, 'save_template', $name);
        aw_nl_flash('success', "Template \"$name\" saved.");
        aw_nl_back('send');
    }
    if ($action === 'delete_template') {
        $db->prepare("DELETE FROM newsletter_templates WHERE id = ?")->execute([(int)($_POST['template_id'] ?? 0)]);
        aw_nl_audit($db, 'delete_template', '#' . (int)($_POST['template_id'] ?? 0));
        aw_nl_flash('success', 'Template deleted.');
        aw_nl_back('send');
    }

    // ---- subscribers
    $back_filters = array_intersect_key($_POST, array_flip(['q', 'status', 'segment']));
    if ($action === 'add_subscriber') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $mode = $_POST['mode'] ?? 'confirm';
        $seg = (int)($_POST['segment_id'] ?? 0);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { aw_nl_flash('error', 'Please enter a valid email address.'); aw_nl_back('subscribers'); }
        $existing = aw_nl_subscriber($db, $email);
        if ($mode === 'consented') {
            if (empty($_POST['consent'])) { aw_nl_flash('error', 'Tick the box to confirm this person agreed to receive the newsletter, or send them a confirmation email instead.'); aw_nl_back('subscribers'); }
            if ($existing && aw_nl_status($existing) === 'unsubscribed') { aw_nl_flash('error', "$email unsubscribed earlier. Send them a confirmation email instead, so they choose to come back."); aw_nl_back('subscribers'); }
            if ($existing) {
                $db->prepare("UPDATE newsletter SET is_active = 1, name = COALESCE(NULLIF(?, ''), name) WHERE id = ?")->execute([$name, $existing['id']]);
                $sid = $existing['id'];
            } else {
                $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token, source) VALUES (?, ?, 1, ?, 'admin')")->execute([$email, $name, bin2hex(random_bytes(32))]);
                $sid = (int)$db->lastInsertId();
            }
            $msg = "$email added as an active subscriber.";
        } else {
            $r = newsletter_request_optin($db, $email, $name);
            $sid = ($s = aw_nl_subscriber($db, $email)) ? $s['id'] : 0;
            $msg = "A confirmation email was sent to $email. They become active when they click it.";
        }
        if ($seg && !empty($sid)) $db->prepare("INSERT OR IGNORE INTO subscriber_segment_assignments (subscriber_id, segment_id) VALUES (?, ?)")->execute([$sid, $seg]);
        aw_nl_audit($db, 'add_subscriber', "$email ($mode)");
        aw_nl_flash('success', $msg);
        aw_nl_back('subscribers');
    }
    if ($action === 'edit_subscriber') {
        $sid = (int)($_POST['subscriber_id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $db->prepare("UPDATE newsletter SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$name, $sid]);
        $db->prepare("DELETE FROM subscriber_segment_assignments WHERE subscriber_id = ?")->execute([$sid]);
        foreach ((array)($_POST['segments'] ?? []) as $seg) {
            $db->prepare("INSERT OR IGNORE INTO subscriber_segment_assignments (subscriber_id, segment_id) VALUES (?, ?)")->execute([$sid, (int)$seg]);
        }
        aw_nl_audit($db, 'edit_subscriber', "#$sid");
        aw_nl_flash('success', 'Subscriber updated.');
        aw_nl_back('subscribers', $back_filters);
    }
    if ($action === 'bulk_subscribers') {
        $ids = aw_nl_ids($_POST['selected_ids'] ?? '');
        $bulk = $_POST['bulk_action'] ?? '';
        $seg = (int)($_POST['segment_id'] ?? 0);
        if (!$ids || $bulk === '') { aw_nl_flash('error', 'Select at least one subscriber and an action.'); aw_nl_back('subscribers', $back_filters); }
        $n = 0;
        foreach ($ids as $sid) {
            if ($bulk === 'unsubscribe') {
                $n += $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ? AND is_active = 1")->execute([$sid]) ? 1 : 0;
            } elseif ($bulk === 'delete') {
                foreach (["DELETE FROM subscriber_segment_assignments WHERE subscriber_id = ?", "DELETE FROM newsletter WHERE id = ?"] as $sql) $db->prepare($sql)->execute([$sid]);
                $n++;
            } elseif ($bulk === 'add_segment' && $seg) {
                $db->prepare("INSERT OR IGNORE INTO subscriber_segment_assignments (subscriber_id, segment_id) VALUES (?, ?)")->execute([$sid, $seg]); $n++;
            } elseif ($bulk === 'remove_segment' && $seg) {
                $db->prepare("DELETE FROM subscriber_segment_assignments WHERE subscriber_id = ? AND segment_id = ?")->execute([$sid, $seg]); $n++;
            } elseif ($bulk === 'resend_confirmation') {
                $st = $db->prepare("SELECT * FROM newsletter WHERE id = ?");
                $st->execute([$sid]);
                $sub = $st->fetch(PDO::FETCH_ASSOC);
                if ($sub && aw_nl_status($sub) === 'pending') { newsletter_request_optin($db, $sub['email'], $sub['name'] ?? ''); $n++; }
            }
        }
        if (in_array($bulk, ['add_segment', 'remove_segment'], true) && !$seg) { aw_nl_flash('error', 'Choose a segment.'); aw_nl_back('subscribers', $back_filters); }
        $labels = ['unsubscribe' => 'unsubscribed', 'delete' => 'deleted', 'add_segment' => 'added to the segment', 'remove_segment' => 'removed from the segment', 'resend_confirmation' => 'sent a new confirmation email'];
        aw_nl_audit($db, 'bulk_' . $bulk, count($ids) . ' selected');
        aw_nl_flash('success', count($ids) . ' selected: ' . ($labels[$bulk] ?? $bulk) . ($bulk === 'resend_confirmation' ? " ($n awaiting confirmation)" : '') . '.');
        aw_nl_back('subscribers', $back_filters);
    }

    // ---- import
    if ($action === 'import_csv') {
        if (empty($_POST['consent'])) { aw_nl_flash('error', 'Please confirm that everyone in the file agreed to receive the newsletter.'); aw_nl_back('import'); }
        $f = $_FILES['csv_file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { aw_nl_flash('error', 'Please choose a CSV file.'); aw_nl_back('import'); }
        if ($f['size'] > 2 * 1048576) { aw_nl_flash('error', 'The file is larger than 2 MB. Split it into smaller files.'); aw_nl_back('import'); }
        $h = fopen($f['tmp_name'], 'r');
        $headers = $h ? array_map(function ($x) { return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$x))); }, (array)fgetcsv($h)) : [];
        $ei = array_search('email', $headers, true);
        $ni = array_search('name', $headers, true);
        if ($ei === false) { aw_nl_flash('error', 'The CSV needs an "email" column (and optionally "name").'); aw_nl_back('import'); }
        $seg = (int)($_POST['segment_id'] ?? 0);
        $added = 0; $updated = 0; $invalid = 0; $kept_out = 0; $rows = 0;
        $ins = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token, source) VALUES (?, ?, 1, ?, 'import')");
        $db->beginTransaction();
        while (($row = fgetcsv($h)) !== false && $rows < 20000) {
            $rows++;
            $email = strtolower(trim((string)($row[$ei] ?? '')));
            $name = $ni !== false ? mb_substr(trim((string)($row[$ni] ?? '')), 0, 100) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid++; continue; }
            $ex = aw_nl_subscriber($db, $email);
            if ($ex) {
                if (aw_nl_status($ex) === 'unsubscribed') { $kept_out++; }        // never re-subscribe someone who left
                elseif ($name !== '' && empty($ex['name'])) { $db->prepare("UPDATE newsletter SET name = ? WHERE id = ?")->execute([$name, $ex['id']]); $updated++; }
                $sid = $ex['id'];
            } else {
                $ins->execute([$email, $name, bin2hex(random_bytes(32))]);
                $sid = (int)$db->lastInsertId();
                $added++;
            }
            if ($seg && $sid) $db->prepare("INSERT OR IGNORE INTO subscriber_segment_assignments (subscriber_id, segment_id) VALUES (?, ?)")->execute([$sid, $seg]);
        }
        $db->commit();
        fclose($h);
        $msg = "Import finished: $added added, $updated names filled in, $invalid invalid rows skipped" . ($kept_out ? ", $kept_out who had unsubscribed were left unsubscribed" : '') . '.';
        aw_nl_audit($db, 'import_csv', $msg);
        aw_nl_flash('success', $msg);
        aw_nl_back('subscribers');
    }

    // ---- segments
    if ($action === 'create_segment') {
        $name = mb_substr(trim((string)($_POST['segment_name'] ?? '')), 0, 60);
        if ($name === '') { aw_nl_flash('error', 'A segment needs a name.'); aw_nl_back('segments'); }
        $st = $db->prepare("INSERT OR IGNORE INTO subscriber_segments (name, description) VALUES (?, ?)");
        $st->execute([$name, trim((string)($_POST['segment_description'] ?? ''))]);
        aw_nl_audit($db, 'create_segment', $name);
        aw_nl_flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? "Segment \"$name\" created." : 'A segment with that name already exists.');
        aw_nl_back('segments');
    }
    if ($action === 'delete_segment') {
        $sid = (int)($_POST['segment_id'] ?? 0);
        $db->prepare("DELETE FROM subscriber_segment_assignments WHERE segment_id = ?")->execute([$sid]);
        $db->prepare("DELETE FROM subscriber_segments WHERE id = ?")->execute([$sid]);
        aw_nl_audit($db, 'delete_segment', "#$sid");
        aw_nl_flash('success', 'Segment deleted (its subscribers stay on the list).');
        aw_nl_back('segments');
    }
    aw_nl_back($_GET['tab'] ?? 'send');
}

// ====== DATA FOR THE TABS (read after any change) ======
$active_tab = in_array($_GET['tab'] ?? '', ['send', 'subscribers', 'segments', 'queue', 'archive', 'audit', 'import'], true) ? $_GET['tab'] : 'send';
[$flash_type, $flash_msg] = $_SESSION['aw_nl_flash'] ?? ['', ''];
unset($_SESSION['aw_nl_flash']);
$error = $flash_type === 'error' ? $flash_msg : '';
$success = $flash_type === 'success' ? $flash_msg : '';

$counts = $db->query("SELECT SUM(is_active = 1) a, SUM(is_active = 0 AND unsubscribed_at IS NULL) p, SUM(is_active = 0 AND unsubscribed_at IS NOT NULL) u, COUNT(*) t FROM newsletter")->fetch(PDO::FETCH_ASSOC);
$active_count = (int)$counts['a'];
$segments = $db->query("SELECT s.*, (SELECT COUNT(*) FROM subscriber_segment_assignments a JOIN newsletter n ON n.id = a.subscriber_id WHERE a.segment_id = s.id AND n.is_active = 1) AS active_members, (SELECT COUNT(*) FROM subscriber_segment_assignments a WHERE a.segment_id = s.id) AS members FROM subscriber_segments s ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$templates = $db->query("SELECT id, name, subject, content FROM newsletter_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$queue_count = (int)$db->query("SELECT COUNT(*) FROM email_queue")->fetchColumn();
$last = $db->query("SELECT id FROM email_queue WHERE status = 'sent' ORDER BY sent_at DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
$avg_open = null;
if ($last) { $rates = array_map(function ($id) use ($db) { return aw_nl_report($db, $id)['open_rate']; }, $last); $avg_open = round(array_sum($rates) / count($rates)); }

// Subscribers: search, status and segment filters, paged in SQL (P18).
$f_q = trim((string)($_GET['q'] ?? ''));
$f_status = in_array($_GET['status'] ?? '', ['active', 'pending', 'unsubscribed'], true) ? $_GET['status'] : '';
$f_segment = (int)($_GET['segment'] ?? 0);
$where = '1=1'; $params = [];
if ($f_q !== '') { $where .= " AND (n.email LIKE ? OR n.name LIKE ?)"; $params[] = "%$f_q%"; $params[] = "%$f_q%"; }
if ($f_status === 'active') $where .= " AND n.is_active = 1";
if ($f_status === 'pending') $where .= " AND n.is_active = 0 AND n.unsubscribed_at IS NULL";
if ($f_status === 'unsubscribed') $where .= " AND n.is_active = 0 AND n.unsubscribed_at IS NOT NULL";
if ($f_segment) { $where .= " AND n.id IN (SELECT subscriber_id FROM subscriber_segment_assignments WHERE segment_id = ?)"; $params[] = $f_segment; }
$st = $db->prepare("SELECT COUNT(*) FROM newsletter n WHERE $where");
$st->execute($params);
$sub_total = (int)$st->fetchColumn();
[$sub_page, $sub_per] = aw_page(25, 'spage');
[$sub_page, $sub_off] = aw_clamp($sub_total, $sub_page, $sub_per);
$st = $db->prepare("SELECT n.*, (SELECT MAX(s.opened_at) FROM newsletter_stats s WHERE lower(s.recipient_email) = lower(n.email)) AS last_open, (SELECT COUNT(*) FROM newsletter_stats s WHERE lower(s.recipient_email) = lower(n.email) AND s.status = 'sent') AS received FROM newsletter n WHERE $where ORDER BY n.subscribed_at DESC, n.id DESC LIMIT ? OFFSET ?");
$st->execute(array_merge($params, [$sub_per, $sub_off]));
$subscribers = $st->fetchAll(PDO::FETCH_ASSOC);
$segment_names_by_subscriber = [];
foreach ($db->query("SELECT a.subscriber_id, s.id, s.name FROM subscriber_segment_assignments a JOIN subscriber_segments s ON s.id = a.segment_id ORDER BY s.name") as $row) {
    $segment_names_by_subscriber[$row['subscriber_id']][$row['id']] = $row['name'];
}
$active_list = $db->query("SELECT id, email, name FROM newsletter WHERE is_active = 1 ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

[$q_page, $q_per] = aw_page(25, 'qpage');
[$q_page, $q_off] = aw_clamp($queue_count, $q_page, $q_per);
$st = $db->prepare("SELECT * FROM email_queue ORDER BY COALESCE(sent_at, scheduled_at) DESC, id DESC LIMIT ? OFFSET ?");
$st->execute([$q_per, $q_off]);
$queue = $st->fetchAll(PDO::FETCH_ASSOC);

$archive_total = (int)$db->query("SELECT COUNT(*) FROM newsletter_archive")->fetchColumn();
[$a_page, $a_per] = aw_page(25, 'apage');
[$a_page, $a_off] = aw_clamp($archive_total, $a_page, $a_per);
$st = $db->prepare("SELECT * FROM newsletter_archive ORDER BY sent_at DESC, id DESC LIMIT ? OFFSET ?");
$st->execute([$a_per, $a_off]);
$archive = $st->fetchAll(PDO::FETCH_ASSOC);

$audit_log = $db->query("SELECT l.*, u.name AS user_name FROM newsletter_audit_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$draft = $_SESSION['aw_nl_draft'] ?? ['subject' => '', 'content' => ''];
$subject = $draft['subject'];
$content = $draft['content'];
$prefill_email = filter_var($_GET['to'] ?? '', FILTER_VALIDATE_EMAIL) ? $_GET['to'] : '';

$pageTitle = 'Newsletter Management';
require_once '../includes/header.php';
?>

<div class="admin-newsletter">
    <div class="container">
        <div class="admin-header">
            <h1>📨 Newsletter</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/export_subscribers.php" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export all</a>
                <a href="<?php echo SITE_URL; ?>/admin/export_subscribers.php?status=active" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export active</a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <div class="nl-stats" role="list">
            <div role="listitem"><strong><?php echo number_format($active_count); ?></strong><span>Active subscribers</span></div>
            <div role="listitem"><strong><?php echo number_format((int)$counts['p']); ?></strong><span>Awaiting confirmation</span></div>
            <div role="listitem"><strong><?php echo number_format((int)$counts['u']); ?></strong><span>Unsubscribed</span></div>
            <div role="listitem"><strong><?php echo $avg_open === null ? '–' : $avg_open . '%'; ?></strong><span>Open rate (last 5)</span></div>
        </div>

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <?php
        // F169: progress of a paged send; the form re-posts itself every second until it is done.
        if (isset($_GET['sending'])):
            $progress = aw_nl_progress($db, (int)$_GET['sending']);
            if ($progress):
                $pq = $progress['queue'];
                $attempted = $progress['sent'] + $progress['failed'] + $progress['skipped'];
                $more = !empty($progress['remaining']) && $pq['status'] === 'processing';
                $pct = $progress['total'] ? round($attempted / $progress['total'] * 100) : 100;
        ?>
            <div class="alert <?php echo $more ? 'alert-info' : 'alert-success'; ?>" id="sendProgress" role="status">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <span><?php echo $more ? 'Sending' : 'Finished'; ?> &ldquo;<?php echo htmlspecialchars($pq['subject']); ?>&rdquo;: <?php echo $progress['sent']; ?> sent<?php echo $progress['failed'] ? ', ' . $progress['failed'] . ' failed' : ''; ?><?php echo $progress['skipped'] ? ', ' . $progress['skipped'] . ' skipped (unsubscribed)' : ''; ?> of <?php echo $progress['total']; ?>.</span>
                    <?php if ($more): ?>
                        <form method="POST" id="sendBatchForm" style="display:inline;">
                            <?php echo aw_csrf_field(); ?>
                            <input type="hidden" name="action" value="send_batch">
                            <input type="hidden" name="queue_id" value="<?php echo (int)$pq['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-primary">Continue</button>
                        </form>
                    <?php endif; ?>
                </div>
                <progress value="<?php echo $pct; ?>" max="100" style="width:100%;height:10px;margin-top:8px;accent-color:var(--rose);" aria-label="Sending progress"></progress>
                <?php if ($more): ?><script>setTimeout(function () { document.getElementById('sendBatchForm').submit(); }, 800);</script><?php endif; ?>
            </div>
        <?php endif; endif; ?>

        <nav class="tabs" aria-label="Newsletter sections">
            <?php foreach (['send' => ['fa-paper-plane', 'Compose'], 'subscribers' => ['fa-users', 'Subscribers (' . (int)$counts['t'] . ')'], 'segments' => ['fa-tags', 'Segments'], 'queue' => ['fa-clock', 'Campaigns (' . $queue_count . ')'], 'archive' => ['fa-chart-line', 'Reports'], 'import' => ['fa-upload', 'Import'], 'audit' => ['fa-history', 'Audit log']] as $k => [$icon, $label]): ?>
                <a href="?tab=<?php echo $k; ?>" class="tab <?php echo $active_tab === $k ? 'active' : ''; ?>"<?php echo $active_tab === $k ? ' aria-current="page"' : ''; ?>><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php require __DIR__ . '/newsletter_tabs/' . $active_tab . '.php'; ?>
    </div>
</div>

<style>
.admin-newsletter { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { margin: 0; }
.admin-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.tabs { display: flex; gap: 2px; margin-bottom: 24px; border-bottom: 2px solid var(--border); flex-wrap: wrap; }
.tab { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 0.95rem; color: var(--text-light); border-bottom: 3px solid transparent; transition: all 0.2s; text-decoration: none; }
.tab:hover { color: var(--text); background: var(--vanilla); }
.tab.active { color: var(--rose); border-bottom-color: var(--rose); font-weight: 600; }

.card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.card-body { padding: 20px; }

.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
.admin-form input[type="text"], .admin-form input[type="email"], .admin-form textarea, .admin-form select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
}
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.admin-form textarea { resize: vertical; min-height: 120px; }
.required { color: #dc2626; }

.table-responsive { overflow-x: auto; border-radius: 12px; }
.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th { background: var(--vanilla); padding: 10px 16px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }

.status-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.status-badge.active { background: #27ae60; color: #fff; }
.status-badge.inactive { background: #95a5a6; color: #fff; }
.status-badge.pending { background: #f1c40f; color: #fff; }
.status-badge.processing { background: #3498db; color: #fff; }
.status-badge.sent { background: #2ecc71; color: #fff; }
.status-badge.failed { background: #e74c3c; color: #fff; }
.status-badge.cancelled { background: #95a5a6; color: #fff; }

.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

@media (max-width: 768px) {
    .admin-header { flex-direction: column; align-items: flex-start; }
    .tabs { gap: 4px; }
    .tab { padding: 8px 12px; font-size: 0.85rem; }
}

.nl-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
.nl-stats > div { background:var(--card-bg); border:1px solid var(--border); border-radius:14px; padding:14px 16px; box-shadow:var(--shadow); }
.nl-stats strong { display:block; font-size:1.6rem; color:var(--rose-dark, #c08a8b); font-family:'Playfair Display',Georgia,serif; }
.nl-stats span { font-size:.82rem; color:var(--text-light); }
.nl-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.nl-chip { display:inline-block; padding:2px 10px; border-radius:999px; background:var(--vanilla); font-size:.75rem; margin:1px; }
.status-badge.awaiting { background:#e6a23c; color:#fff; }
.nl-tokens button { font:500 .78rem Inter,sans-serif; border:1px dashed var(--rose); background:var(--fantasy); color:var(--text); border-radius:8px; padding:4px 8px; cursor:pointer; }
.nl-report { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:8px; }
.nl-report div { background:var(--fantasy); border-radius:10px; padding:8px 10px; text-align:center; font-size:.8rem; }
.nl-report b { display:block; font-size:1.15rem; color:var(--dark); }
.btn-danger, .btn-warning { color:#fff; }
details.nl-edit summary { cursor:pointer; color:var(--rose-dark, #c08a8b); }
</style>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
if (document.getElementById('editor') && window.tinymce) {
    tinymce.init({
        selector: '#editor',
        height: 480,
        menubar: false,
        plugins: 'anchor autolink charmap emoticons image link lists media searchreplace table visualblocks wordcount code',
        toolbar: 'undo redo | blocks | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist | link image | table | removeformat code',
        content_style: 'body { font-family: Georgia, serif; font-size: 16px; line-height: 1.7; color: #3d2e2e; }',
        convert_urls: false,
        setup: function (editor) { editor.on('change input', function () { tinymce.triggerSave(); }); }
    });
}
</script>
