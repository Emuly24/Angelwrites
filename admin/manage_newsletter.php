<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();
// S13 (F175): the newsletter_tabs/ partials refuse to render unless included from here.
define('AW_NEWSLETTER_ADMIN', true);

// ====== F169: SEND A QUEUED NEWSLETTER IN PAGES ======
// One request sends at most NEWSLETTER_BATCH_SIZE recipients (20 × the 10 s SMTP timeout
// stays under the host's execution limit). Every attempt is logged in newsletter_stats,
// so a repeated or reloaded request never sends twice to the same address. The page then
// posts itself again (see the progress box below) until every recipient has been tried.
define('NEWSLETTER_BATCH_SIZE', 20);

function newsletter_progress($db, $queue_id) {
    $stmt = $db->prepare("SELECT * FROM email_queue WHERE id = ?");
    $stmt->execute([$queue_id]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) return null;
    $recipients = array_values(array_unique(array_filter(array_map('trim', explode(',', $q['recipient_emails'])))));
    $stmt = $db->prepare("SELECT recipient_email, status FROM newsletter_stats WHERE email_queue_id = ?");
    $stmt->execute([$queue_id]);
    $done = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $sent = 0; $failed = 0; $remaining = [];
    foreach ($recipients as $e) {
        if (!isset($done[$e])) { $remaining[] = $e; }
        elseif ($done[$e] === 'sent') { $sent++; }
        else { $failed++; }
    }
    return ['queue' => $q, 'total' => count($recipients), 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
}

function newsletter_send_batch($db, $queue_id) {
    $p = newsletter_progress($db, $queue_id);
    if (!$p || $p['queue']['status'] !== 'processing') return $p;
    $q = $p['queue'];
    $tok = $db->prepare("SELECT unsubscribe_token FROM newsletter WHERE email = ?");
    $log = $db->prepare("INSERT INTO newsletter_stats (email_queue_id, recipient_email, status) VALUES (?, ?, ?)");
    foreach (array_slice($p['remaining'], 0, NEWSLETTER_BATCH_SIZE) as $email) {
        $tok->execute([$email]);
        $token = $tok->fetchColumn();
        $unsubscribe_link = $token
            ? SITE_URL . '/unsubscribe.php?token=' . urlencode($token)
            : SITE_URL . '/unsubscribe.php?email=' . urlencode($email);
        $full_message = $q['content'] . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";
        $ok = sendEmail($email, $q['subject'], $full_message, 'angelwrites@zohomail.com', 'AngelWrites Newsletter');
        $log->execute([$queue_id, $email, $ok ? 'sent' : 'failed']);
    }
    $p = newsletter_progress($db, $queue_id);
    if (empty($p['remaining'])) {
        $stmt = $db->prepare("UPDATE email_queue SET status = ?, sent_at = CURRENT_TIMESTAMP, attempt_count = attempt_count + 1, last_error = ? WHERE id = ?");
        $stmt->execute([$p['sent'] > 0 ? 'sent' : 'failed', $p['failed'] > 0 ? "Failed: {$p['failed']}, Sent: {$p['sent']}" : null, $queue_id]);
        $stmt = $db->prepare("INSERT INTO newsletter_archive (subject, content, recipient_count, sent_at, email_queue_id) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)");
        $stmt->execute([$q['subject'], $q['content'], $p['sent'], $queue_id]);
    } else {
        $stmt = $db->prepare("UPDATE email_queue SET attempt_count = attempt_count + 1 WHERE id = ?");
        $stmt->execute([$queue_id]);
    }
    return newsletter_progress($db, $queue_id);
}

$error = '';
$success = '';

// ====== TAB NAVIGATION ======
$active_tab = $_GET['tab'] ?? 'send';

// ====== FETCH ALL DATA ======
$stmt = $db->query("SELECT * FROM newsletter ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1");
$active_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT * FROM email_queue ORDER BY scheduled_at DESC");
$queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT * FROM subscriber_segments ORDER BY name ASC");
$segments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT * FROM newsletter_archive ORDER BY sent_at DESC");
$archive = $stmt->fetchAll(PDO::FETCH_ASSOC);

// P18: the Subscribers, Queue and Archive tabs showed every row at once. Each table is paged
// (25 per page, own parameter); the full lists are kept for counts and the Send tab's picker.
require_once __DIR__ . '/../includes/pagination.php';
$aw_nl = [];
foreach (['subscribers' => 'spage', 'queue' => 'qpage', 'archive' => 'apage'] as $aw_list => $aw_param) {
    [$aw_p, $aw_per] = aw_page(25, $aw_param);
    $aw_t = count($$aw_list);
    [$aw_p, $aw_o] = aw_clamp($aw_t, $aw_p, $aw_per);
    $aw_nl[$aw_list] = ['rows' => array_slice($$aw_list, $aw_o, $aw_per), 'total' => $aw_t, 'page' => $aw_p, 'per' => $aw_per, 'param' => $aw_param];
}

$stmt = $db->query("SELECT * FROM newsletter_audit_log ORDER BY created_at DESC LIMIT 50");
$audit_log = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====== HANDLE POST ACTIONS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ====== SEND NEWSLETTER ======
    if ($action === 'send_newsletter') {
        $subject = trim($_POST['subject']);
        $content = trim($_POST['content']);
        $send_to = $_POST['send_to'] ?? 'all';
        $segment_id = isset($_POST['segment_id']) ? (int)$_POST['segment_id'] : null;
        $schedule = isset($_POST['schedule']) ? trim($_POST['schedule']) : '';
        $attachments = [];

        // Validate
        if (empty($subject) || empty($content)) {
            $error = 'Subject and content are required.';
        } else {
            // Handle attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../assets/uploads/attachments/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                foreach ($_FILES['attachments']['name'] as $key => $name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $filename = 'newsletter_' . time() . '_' . $key . '.' . $ext;
                        $target = $upload_dir . $filename;
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $target)) {
                            $attachments[] = 'assets/uploads/attachments/' . $filename;
                        }
                    }
                }
            }

            // Determine recipients
            $recipients = [];
            if ($send_to === 'all') {
                $stmt = $db->prepare("SELECT email FROM newsletter WHERE is_active = 1");
                $stmt->execute();
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($send_to === 'segment' && $segment_id) {
                $stmt = $db->prepare("
                    SELECT n.email FROM newsletter n
                    JOIN subscriber_segment_assignments a ON n.id = a.subscriber_id
                    WHERE a.segment_id = ? AND n.is_active = 1
                ");
                $stmt->execute([$segment_id]);
                $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($send_to === 'single') {
                $email = trim($_POST['single_email']);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                } else {
                    $error = 'Invalid email address.';
                }
            } elseif ($send_to === 'selected' && !empty($_POST['selected_emails'])) {
                $emails = explode(',', trim($_POST['selected_emails']));
                foreach ($emails as $e) {
                    if (filter_var(trim($e), FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = trim($e);
                    }
                }
            }

            if (empty($recipients)) {
                $error = 'No valid recipients selected.';
            }

            if (empty($error)) {
                $recipient_emails = implode(',', $recipients);
                $scheduled_at = null;
                $status = 'pending';

                // F171: date() never returns false, so an unparseable value was stored as 1970.
                // Check strtotime() itself, and only use the field when "Schedule" is chosen
                // (a value left in the hidden field no longer overrides "Send Now").
                $wants_schedule = ($_POST['schedule_type'] ?? (empty($schedule) ? 'now' : 'later')) === 'later';
                if ($wants_schedule) {
                    $ts = $schedule !== '' ? strtotime($schedule) : false;
                    if ($ts === false) {
                        $error = 'Invalid schedule date/time.';
                    } else {
                        $scheduled_at = date('Y-m-d H:i:s', $ts);
                    }
                } else {
                    $status = 'processing'; // send immediately
                }

                if (empty($error)) {
                    // Insert into queue
                    $stmt = $db->prepare("
                        INSERT INTO email_queue (subject, content, recipient_emails, send_to_type, segment_id, scheduled_at, status, attempt_count)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([$subject, $content, $recipient_emails, $send_to, $segment_id, $scheduled_at ?? date('Y-m-d H:i:s'), $status]);
                    $queue_id = $db->lastInsertId();

                    // Attachments
                    foreach ($attachments as $path) {
                        $stmt = $db->prepare("INSERT INTO email_attachments (email_queue_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$queue_id, $path, basename($path), filesize('../' . $path)]);
                    }

                    // F169: sending now goes through the paged sender (see newsletter_send_batch)
                    if ($status === 'processing') {
                        $send_redirect_id = $queue_id;
                    } else {
                        $success = "Newsletter scheduled for " . date('F j, Y, g:i a', strtotime($scheduled_at));
                    }

                    // Audit log
                    $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], 'send_newsletter', "Subject: $subject, Recipients: " . count($recipients)]);
                    if (!empty($send_redirect_id)) {
                        header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue&sending=' . (int)$send_redirect_id);
                        exit;
                    }
                }
            }
        }
    }

    // ====== F169: SEND THE NEXT BATCH OF A QUEUED NEWSLETTER ======
    if ($action === 'send_batch') {
        $queue_id = (int)($_POST['queue_id'] ?? 0);
        $progress = newsletter_send_batch($db, $queue_id);
        if (!$progress) {
            $error = 'Queue entry not found.';
        } else {
            header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue&sending=' . $queue_id);
            exit;
        }
    }

    // ====== SAVE TEMPLATE ======
    if ($action === 'save_template') {
        $name = trim($_POST['template_name']);
        $subject = trim($_POST['subject']);
        $content = trim($_POST['content']);
        if (empty($name) || empty($subject) || empty($content)) {
            $error = 'Name, subject, and content are required.';
        } else {
            $stmt = $db->prepare("INSERT INTO newsletter_templates (name, subject, content) VALUES (?, ?, ?)");
            $stmt->execute([$name, $subject, $content]);
            $success = 'Template saved.';
            $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], 'save_template', "Name: $name"]);
        }
    }

    // ====== DELETE TEMPLATE ======
    if ($action === 'delete_template') {
        $template_id = (int)$_POST['template_id'];
        $stmt = $db->prepare("DELETE FROM newsletter_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $success = 'Template deleted.';
        $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], 'delete_template', "ID: $template_id"]);
    }

    // ====== LOAD TEMPLATE ======
    if ($action === 'load_template') {
        $template_id = (int)$_POST['template_id'];
        $stmt = $db->prepare("SELECT * FROM newsletter_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template) {
            $_SESSION['newsletter_template'] = $template;
            $success = 'Template loaded.';
        } else {
            $error = 'Template not found.';
        }
    }

    // ====== BULK ACTIONS ======
    if ($action === 'bulk_subscribers') {
        $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
        $bulk_action = $_POST['bulk_action'] ?? '';
        if (empty($ids) || empty($bulk_action)) {
            $error = 'Please select at least one subscriber and an action.';
        } else {
            if ($bulk_action === 'unsubscribe') {
                $stmt = $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?");
                foreach ($ids as $id) {
                    $stmt->execute([$id]);
                }
                $success = count($ids) . ' subscribers unsubscribed.';
            } elseif ($bulk_action === 'delete') {
                $stmt = $db->prepare("DELETE FROM newsletter WHERE id = ?");
                foreach ($ids as $id) {
                    $stmt->execute([$id]);
                }
                $success = count($ids) . ' subscribers deleted.';
            } elseif ($bulk_action === 'add_segment') {
                $segment_id = (int)$_POST['segment_id'];
                if (!$segment_id) {
                    $error = 'Please select a segment.';
                } else {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO subscriber_segment_assignments (subscriber_id, segment_id) VALUES (?, ?)");
                    foreach ($ids as $id) {
                        $stmt->execute([$id, $segment_id]);
                    }
                    $success = count($ids) . ' subscribers added to segment.';
                }
            } elseif ($bulk_action === 'remove_segment') {
                $segment_id = (int)$_POST['segment_id'];
                if (!$segment_id) {
                    $error = 'Please select a segment.';
                } else {
                    $stmt = $db->prepare("DELETE FROM subscriber_segment_assignments WHERE subscriber_id = ? AND segment_id = ?");
                    foreach ($ids as $id) {
                        $stmt->execute([$id, $segment_id]);
                    }
                    $success = count($ids) . ' subscribers removed from segment.';
                }
            }
            if ($success) {
                $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], 'bulk_' . $bulk_action, "Count: " . count($ids)]);
            }
        }
    }

    // ====== IMPORT CSV ======
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid CSV file.';
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle === false) {
                $error = 'Failed to read CSV file.';
            } else {
                $headers = fgetcsv($handle);
                $email_index = array_search('email', array_map('strtolower', $headers));
                $name_index = array_search('name', array_map('strtolower', $headers));
                if ($email_index === false) {
                    $error = 'CSV must contain an "email" column.';
                } else {
                    $imported = 0;
                    $skipped = 0;
                    while (($row = fgetcsv($handle)) !== false) {
                        if (!isset($row[$email_index])) continue;
                        $email = trim($row[$email_index]);
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                        $name = ($name_index !== false && isset($row[$name_index])) ? trim($row[$name_index]) : '';
                        $token = bin2hex(random_bytes(32));
                        $stmt = $db->prepare("INSERT OR IGNORE INTO newsletter (email, name, unsubscribe_token, source) VALUES (?, ?, ?, 'import')");
                        $stmt->execute([$email, $name, $token]);
                        if ($stmt->rowCount() > 0) $imported++;
                        else $skipped++;
                    }
                    fclose($handle);
                    $success = "Import complete: $imported added, $skipped skipped.";
                    $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], 'import_csv', "Imported: $imported, Skipped: $skipped"]);
                }
            }
        }
    }

    // ====== CREATE SEGMENT ======
    if ($action === 'create_segment') {
        $name = trim($_POST['segment_name']);
        $description = trim($_POST['segment_description']);
        if (empty($name)) {
            $error = 'Segment name is required.';
        } else {
            $stmt = $db->prepare("INSERT OR IGNORE INTO subscriber_segments (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            if ($stmt->rowCount() > 0) {
                $success = "Segment '$name' created.";
            } else {
                $error = 'Segment already exists.';
            }
        }
    }

    // ====== DELETE SEGMENT ======
    if ($action === 'delete_segment') {
        $segment_id = (int)$_POST['segment_id'];
        $stmt = $db->prepare("DELETE FROM subscriber_segments WHERE id = ?");
        $stmt->execute([$segment_id]);
        $stmt = $db->prepare("DELETE FROM subscriber_segment_assignments WHERE segment_id = ?");
        $stmt->execute([$segment_id]);
        $success = 'Segment deleted.';
    }

    // ====== F172: SEND QUEUED NOW (this host has no cron) ======
    // Scheduled newsletters wait in the queue until the admin presses "Send queued now" on or
    // after their date. The oldest due row is moved to 'processing' and sent through the paged
    // sender; when it finishes, the button sends the next due row.
    if ($action === 'send_due') {
        $stmt = $db->prepare("SELECT id FROM email_queue WHERE status = 'pending' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT 1");
        $stmt->execute([date('Y-m-d H:i:s')]);
        $due_id = $stmt->fetchColumn();
        if (!$due_id) {
            $success = 'Nothing is due yet. Scheduled newsletters can be sent on or after their date.';
        } else {
            $db->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ?")->execute([$due_id]);
            header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue&sending=' . (int)$due_id);
            exit;
        }
    }

    // ====== F170: RESUME A STUCK SEND / RETRY FAILED RECIPIENTS ======
    // A send interrupted mid-way (closed tab, timeout) stays 'processing'; "Resume" continues it
    // through the paged sender. "Retry failed" forgets only the failed attempts, sets the row
    // back to 'processing' and re-sends to those recipients; addresses already sent are kept.
    if ($action === 'resend_failed') {
        $queue_id = (int)$_POST['queue_id'];
        $stmt = $db->prepare("SELECT id FROM email_queue WHERE id = ? AND status IN ('failed', 'sent')");
        $stmt->execute([$queue_id]);
        if (!$stmt->fetchColumn()) {
            $error = 'Failed email not found.';
        } else {
            $db->prepare("DELETE FROM newsletter_stats WHERE email_queue_id = ? AND status = 'failed'")->execute([$queue_id]);
            $db->prepare("DELETE FROM newsletter_archive WHERE email_queue_id = ?")->execute([$queue_id]);
            $db->prepare("UPDATE email_queue SET status = 'processing', last_error = NULL WHERE id = ?")->execute([$queue_id]);
            header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue&sending=' . $queue_id);
            exit;
        }
    }
}

// ====== LOAD TEMPLATE FROM SESSION ======
$template_name = '';
$subject = '';
$content = '';
if (isset($_SESSION['newsletter_template'])) {
    $template = $_SESSION['newsletter_template'];
    $template_name = $template['name'];
    $subject = $template['subject'];
    $content = $template['content'];
}

$pageTitle = 'Newsletter Management';
require_once '../includes/header.php';
?>

<div class="admin-newsletter">
    <div class="container">
        <div class="admin-header">
            <h1>📨 Newsletter Management</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/export_subscribers.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php
        // F169: progress of a paged send. While recipients remain and the row is still
        // 'processing', the form below re-posts itself after one second; the button is the
        // fallback if scripts or refresh are blocked.
        if (isset($_GET['sending'])):
            $progress = newsletter_progress($db, (int)$_GET['sending']);
            if ($progress):
                $pq = $progress['queue'];
                $attempted = $progress['sent'] + $progress['failed'];
                $more = !empty($progress['remaining']) && $pq['status'] === 'processing';
        ?>
            <div class="alert <?php echo $more ? 'alert-info' : 'alert-success'; ?>" id="sendProgress">
                <?php if ($more): ?>
                    Sent <?php echo $attempted; ?> of <?php echo $progress['total']; ?> to &ldquo;<?php echo htmlspecialchars($pq['subject']); ?>&rdquo;. Sending the next batch&hellip;
                    <form method="POST" id="sendBatchForm" style="display:inline;margin-left:8px;">
                        <input type="hidden" name="action" value="send_batch">
                        <input type="hidden" name="queue_id" value="<?php echo (int)$pq['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Continue</button>
                    </form>
                    <script>setTimeout(function () { document.getElementById('sendBatchForm').submit(); }, 1000);</script>
                <?php else: ?>
                    Finished &ldquo;<?php echo htmlspecialchars($pq['subject']); ?>&rdquo;: sent <?php echo $progress['sent']; ?> of <?php echo $progress['total']; ?><?php echo $progress['failed'] ? ', ' . $progress['failed'] . ' failed' : ''; ?>.
                <?php endif; ?>
            </div>
        <?php endif; endif; ?>

        <div class="tabs">
            <a href="?tab=send" class="tab <?php echo $active_tab === 'send' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i> Send
            </a>
            <a href="?tab=subscribers" class="tab <?php echo $active_tab === 'subscribers' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Subscribers (<?php echo count($subscribers); ?>)
            </a>
            <a href="?tab=segments" class="tab <?php echo $active_tab === 'segments' ? 'active' : ''; ?>">
                <i class="fas fa-tags"></i> Segments
            </a>
            <a href="?tab=queue" class="tab <?php echo $active_tab === 'queue' ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i> Queue (<?php echo count($queue); ?>)
            </a>
            <a href="?tab=archive" class="tab <?php echo $active_tab === 'archive' ? 'active' : ''; ?>">
                <i class="fas fa-archive"></i> Archive
            </a>
            <a href="?tab=audit" class="tab <?php echo $active_tab === 'audit' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Audit Log
            </a>
            <a href="?tab=import" class="tab <?php echo $active_tab === 'import' ? 'active' : ''; ?>">
                <i class="fas fa-upload"></i> Import
            </a>
        </div>

        <?php
        // Include tab content based on active tab
        switch ($active_tab) {
            case 'send':
                require_once 'newsletter_tabs/send.php';
                break;
            case 'subscribers':
                require_once 'newsletter_tabs/subscribers.php';
                break;
            case 'segments':
                require_once 'newsletter_tabs/segments.php';
                break;
            case 'queue':
                require_once 'newsletter_tabs/queue.php';
                break;
            case 'archive':
                require_once 'newsletter_tabs/archive.php';
                break;
            case 'audit':
                require_once 'newsletter_tabs/audit.php';
                break;
            case 'import':
                require_once 'newsletter_tabs/import.php';
                break;
            default:
                require_once 'newsletter_tabs/send.php';
        }
        ?>
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
</style>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#editor',
    height: 500,
    menubar: true,
    plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
    toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
    forced_root_block: 'p',
    setup: function(editor) {
        editor.on('change', function () { tinymce.triggerSave(); });
    }
});

// Preview
document.getElementById('previewBtn')?.addEventListener('click', function() {
    const content = tinymce.get('editor').getContent();
    const subject = document.getElementById('subject').value;
    const w = window.open('', 'Preview', 'width=600,height=800');
    w.document.write(`
        <html><head><title>Newsletter Preview</title></head>
        <body style="font-family: Inter, sans-serif; padding:20px; max-width:600px; margin:0 auto;">
            <h2 style="color: var(--rose);">${subject}</h2>
            <hr>
            ${content}
        </body></html>
    `);
});
</script>

<?php require_once '../includes/footer.php'; ?>