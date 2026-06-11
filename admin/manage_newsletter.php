<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

// Only admin can access
if (!isAdmin()) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$error = '';
$success = '';
$message = '';

// ===== HANDLE UNSUBSCRIBE (via admin action) =====
if (isset($_GET['unsubscribe'])) {
    $id = (int)$_GET['unsubscribe'];
    $stmt = $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = 'Subscriber has been unsubscribed.';
    } else {
        $error = 'Failed to unsubscribe.';
    }
}

// ===== HANDLE DELETE (remove completely) =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM newsletter WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = 'Subscriber has been removed completely.';
    } else {
        $error = 'Failed to remove subscriber.';
    }
}

// ===== FETCH SUBSCRIBERS =====
$stmt = $db->query("SELECT * FROM newsletter ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active subscriber count
$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1");
$active_count = $stmt->fetchColumn();
$active_subscribers = array_filter($subscribers, function($sub) { return $sub['is_active'] == 1; });

// ===== HANDLE QUEUE CANCEL =====
if (isset($_GET['cancel_queue'])) {
    $id = (int)$_GET['cancel_queue'];
    $stmt = $db->prepare("UPDATE email_queue SET status = 'cancelled' WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = 'Scheduled email has been cancelled.';
    } else {
        $error = 'Failed to cancel scheduled email.';
    }
}

// ===== HANDLE QUEUE DELETE =====
if (isset($_GET['delete_queue'])) {
    $id = (int)$_GET['delete_queue'];
    $stmt = $db->prepare("DELETE FROM email_queue WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = 'Scheduled email has been deleted.';
    } else {
        $error = 'Failed to delete scheduled email.';
    }
}

// ===== FETCH QUEUE =====
$stmt = $db->query("SELECT * FROM email_queue ORDER BY scheduled_at ASC");
$queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== HANDLE TEMPLATE SAVE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $template_name = trim($_POST['template_name']);
    $subject = trim($_POST['subject']);
    $content = trim($_POST['content']);
    if (!empty($template_name) && !empty($subject) && !empty($content)) {
        $stmt = $db->prepare("INSERT INTO newsletter_templates (name, subject, content) VALUES (?, ?, ?)");
        $stmt->execute([$template_name, $subject, $content]);
        $success = 'Template saved successfully.';
    } else {
        $error = 'Template name, subject, and content are required.';
    }
}

// ===== HANDLE TEMPLATE LOAD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_template'])) {
    $template_id = (int)$_POST['template_id'];
    $stmt = $db->prepare("SELECT * FROM newsletter_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($template) {
        $_SESSION['newsletter_template'] = $template;
        header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
        exit;
    } else {
        $error = 'Template not found.';
    }
}

// ===== HANDLE TEMPLATE DELETE =====
if (isset($_GET['delete_template'])) {
    $id = (int)$_GET['delete_template'];
    $stmt = $db->prepare("DELETE FROM newsletter_templates WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success = 'Template deleted.';
    } else {
        $error = 'Failed to delete template.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
    exit;
}

// ===== FETCH TEMPLATES =====
$templates = $db->query("SELECT * FROM newsletter_templates ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// ===== HANDLE NEWSLETTER SEND OR SCHEDULE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_newsletter'])) {
    $subject = trim($_POST['subject']);
    $content = trim($_POST['content']);
    $send_to = $_POST['send_to'] ?? 'all';
    $action_type = $_POST['action_type'] ?? 'send_now'; // send_now or schedule
    $scheduled_at = null;

    if ($action_type === 'schedule') {
        $scheduled_datetime = trim($_POST['scheduled_datetime'] ?? '');
        if (!empty($scheduled_datetime)) {
            $scheduled_at = date('Y-m-d H:i:s', strtotime($scheduled_datetime));
            if ($scheduled_at === false) {
                $error = 'Invalid schedule date/time.';
            }
        } else {
            $error = 'Please select a schedule date and time.';
        }
    }

    if (empty($subject) || empty($content)) {
        $error = 'Please fill in both subject and content.';
    } else {
        $target_emails = [];

        if ($send_to === 'all') {
            foreach ($active_subscribers as $sub) {
                $target_emails[] = $sub['email'];
            }
        } elseif ($send_to === 'selected' && !empty($_POST['selected_emails'])) {
            $emails_string = trim($_POST['selected_emails']);
            $target_emails = array_map('trim', explode(',', $emails_string));
            $target_emails = array_filter($target_emails);
        } elseif ($send_to === 'single') {
            $email = trim($_POST['single_email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $target_emails[] = $email;
            } else {
                $error = 'Invalid email address for single send.';
            }
        } else {
            $error = 'No valid recipients selected.';
        }

        // Attachments handling
        $attachment_paths = [];
        if (empty($error) && !empty($_FILES['attachments']['name'][0])) {
            $upload_dir = '../assets/uploads/attachments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $filename = 'attachment_' . time() . '_' . $key . '.' . $ext;
                    $target = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $target)) {
                        $attachment_paths[] = 'assets/uploads/attachments/' . $filename;
                    }
                }
            }
        }

        if (empty($error) && count($target_emails) > 0) {
            $recipient_emails = implode(',', $target_emails);

            // Insert into email queue
            $stmt = $db->prepare("
                INSERT INTO email_queue (subject, content, recipient_emails, send_to_type, scheduled_at, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $queue_status = ($action_type === 'schedule') ? 'pending' : 'processing';
            $stmt->execute([$subject, $content, $recipient_emails, $send_to, $scheduled_at ?? date('Y-m-d H:i:s'), $queue_status]);
            $queue_id = $db->lastInsertId();

            // Attach files to this queue entry
            foreach ($attachment_paths as $path) {
                $stmt = $db->prepare("INSERT INTO email_attachments (email_queue_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)");
                $stmt->execute([$queue_id, $path, basename($path), filesize('../' . $path)]);
            }

            if ($action_type === 'schedule') {
                $message = "Newsletter scheduled successfully for " . date('F j, Y, g:i a', strtotime($scheduled_at));
            } else {
                // Immediately send
                $sent_count = 0;
                $failed_count = 0;
                foreach ($target_emails as $email) {
                    $stmt = $db->prepare("SELECT unsubscribe_token FROM newsletter WHERE email = ?");
                    $stmt->execute([$email]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    $token = $existing ? $existing['unsubscribe_token'] : bin2hex(random_bytes(32));
                    $unsubscribe_link = SITE_URL . '/unsubscribe.php?token=' . $token;
                    $full_message = $content . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";

                    if (sendMarkdownEmail($email, $subject, $markdown_content, 'angelwrites@zohomail.com', 'AngelWrites Newsletter');) {
                        $sent_count++;
                    } else {
                        $failed_count++;
                    }
                    usleep(500000);
                }
                $message = "Newsletter sent successfully! Sent: $sent_count, Failed: $failed_count.";
                // Mark queue as sent
                $stmt = $db->prepare("UPDATE email_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$queue_id]);
            }
        } elseif (empty($error) && count($target_emails) === 0) {
            $error = 'No valid recipients selected.';
        }
    }
}

// ===== LOAD TEMPLATE FROM SESSION (if set) =====
$template_name = '';
$subject = '';
$content = '';
if (isset($_SESSION['newsletter_template'])) {
    $template = $_SESSION['newsletter_template'];
    $template_name = $template['name'];
    $subject = $template['subject'];
    $content = $template['content'];
}

$pageTitle = 'Manage Newsletter';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>📨 Newsletter Management</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/export_subscribers.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Send Newsletter Form -->
        <div class="card">
            <div class="card-header">
                <h2>📤 Send or Schedule Newsletter</h2>
                <div class="card-header-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" id="previewBtn" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> Preview</button>
                    <button type="button" id="testSendBtn" class="btn btn-sm btn-secondary"><i class="fas fa-vial"></i> Send Test</button>
                    <button type="button" id="saveTemplateBtn" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save Template</button>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" class="admin-form" id="newsletterForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="subject">Subject <span class="required">*</span></label>
                        <input type="text" id="subject" name="subject" placeholder="e.g. New Book Release Announcement" value="<?php echo htmlspecialchars($subject); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="content">Message Content (HTML allowed) <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="10" placeholder="Write your newsletter content here..."><?php echo htmlspecialchars($content); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Send to</label>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="send_to" value="all" checked> All Active Subscribers (<?php echo $active_count; ?>)
                            </label>
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="send_to" value="selected"> Select Subscribers
                            </label>
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="send_to" value="single"> Single Email
                            </label>
                        </div>
                    </div>

                    <!-- Single Email Input -->
                    <div class="form-group" id="singleEmailGroup" style="display: none;">
                        <label for="single_email">Enter Subscriber Email</label>
                        <input type="email" id="single_email" name="single_email" placeholder="subscriber@example.com">
                    </div>

                    <!-- Select Subscribers List -->
                    <div id="selectedSubscriberGroup" style="display: none; margin-top: 16px;">
                        <div class="form-group">
                            <label>Select Subscribers</label>
                            <div class="subscriber-list-wrapper" style="border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--fantasy);">
                                <div class="select-all-wrapper" style="margin-bottom: 8px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                                        <input type="checkbox" id="selectAllSubscribers"> Select All / Deselect All
                                    </label>
                                </div>
                                <div class="subscriber-checkboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 6px; max-height: 250px; overflow-y: auto; padding-right: 4px;">
                                    <?php foreach ($active_subscribers as $sub): ?>
                                        <div class="checkbox-item" style="display: flex; align-items: center; gap: 6px; padding: 4px 6px; background: var(--card-bg); border-radius: 4px; border: 1px solid var(--border);">
                                            <input type="checkbox" class="subscriber-checkbox" value="<?php echo htmlspecialchars($sub['email']); ?>" id="sub_<?php echo $sub['id']; ?>" style="margin: 0;">
                                            <label for="sub_<?php echo $sub['id']; ?>" style="font-size: 0.85rem; cursor: pointer; margin: 0; flex: 1;">
                                                <?php echo htmlspecialchars($sub['email']); ?>
                                                <?php if (!empty($sub['name'])): ?>
                                                    <span style="color: var(--text-light); font-weight: 300;">(<?php echo htmlspecialchars($sub['name']); ?>)</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="selected_emails" id="selectedEmailsInput" value="">
                            </div>
                            <div id="selectedEmailsDisplay" style="margin-top: 8px; border: 1px dashed var(--border); padding: 8px; min-height: 40px; border-radius: 8px; background: var(--card-bg); display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                                <span style="color: var(--text-light); font-size: 0.85rem;">No subscribers selected.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Options -->
                    <div class="form-group" style="margin-top: 16px; border-top: 1px solid var(--border); padding-top: 16px;">
                        <label>Send Options</label>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="action_type" value="send_now" checked> Send Now
                            </label>
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="action_type" value="schedule"> Schedule for Later
                            </label>
                        </div>
                        <div id="scheduleDateTimeGroup" style="display: none; margin-top: 8px;">
                            <label for="scheduled_datetime">Schedule Date & Time</label>
                            <input type="datetime-local" id="scheduled_datetime" name="scheduled_datetime">
                        </div>
                    </div>

                    <!-- Attachments -->
                    <div class="form-group" style="border-top: 1px solid var(--border); padding-top: 16px;">
                        <label for="attachments">Attachments</label>
                        <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.zip">
                        <small class="field-hint">Max file size: 10MB per file. Supported: PDF, DOC, DOCX, JPG, PNG, GIF, ZIP.</small>
                        <div id="attachmentPreview" style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    </div>

                    <!-- Template Load & Save -->
                    <div class="form-row" style="display: flex; gap: 12px; flex-wrap: wrap; margin: 16px 0; align-items: flex-end;">
                        <div class="form-group" style="flex: 1; min-width: 150px;">
                            <label for="templateSelect">Load Template</label>
                            <select id="templateSelect" name="template_id">
                                <option value="">— Select a template —</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo $tpl['id']; ?>" <?php echo ($template_id ?? 0) == $tpl['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="loadTemplateBtn" class="btn btn-sm btn-outline" style="margin-top: 4px;">Load</button>
                            <?php if (count($templates) > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?delete_template=<?php echo $templates[0]['id']; ?>" class="btn btn-sm btn-danger" style="margin-top: 4px;" onclick="return confirm('Delete this template?');">Delete</a>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="template_name">Save as Template</label>
                            <input type="text" id="template_name" name="template_name" placeholder="Template name...">
                            <button type="button" id="saveTemplateBtnAction" class="btn btn-sm btn-primary" style="margin-top: 4px;">Save</button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="send_newsletter" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send / Schedule Newsletter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Queue Management -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Email Queue (<?php echo count($queue); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($queue) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Recipients</th>
                                    <th>Scheduled</th>
                                    <th>Status</th>
                                    <th>Attempts</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue as $q): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($q['subject']); ?></td>
                                        <td><?php echo substr($q['recipient_emails'], 0, 30); ?>...</td>
                                        <td><?php echo date('M j, Y, g:i a', strtotime($q['scheduled_at'])); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $q['status']; ?>">
                                                <?php echo ucfirst($q['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $q['attempt_count'] ?? 0; ?></td>
                                        <td class="actions">
                                            <?php if ($q['status'] === 'pending' || $q['status'] === 'processing'): ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?cancel_queue=<?php echo $q['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Cancel this scheduled email?');">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?delete_queue=<?php echo $q['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this queue entry?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No emails in the queue.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Subscriber List -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Subscribers (<?php echo count($subscribers); ?>)</h2>
                <div class="card-header-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <select id="bulkActionSelect" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border); font-size: 0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="unsubscribe">Unsubscribe Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($subscribers) > 0): ?>
                    <div class="table-responsive">
                        <form method="POST" id="bulkForm">
                            <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                            <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Email</th>
                                        <th>Name</th>
                                        <th>Status</th>
                                        <th>Subscribed</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subscribers as $sub): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $sub['id']; ?>"></td>
                                            <td><?php echo htmlspecialchars($sub['email']); ?></td>
                                            <td><?php echo htmlspecialchars($sub['name'] ?? '—'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $sub['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $sub['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($sub['subscribed_at'])); ?></td>
                                            <td class="actions">
                                                <?php if ($sub['is_active']): ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?unsubscribe=<?php echo $sub['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Unsubscribe this user?');">
                                                        <i class="fas fa-times"></i> Unsubscribe
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?delete=<?php echo $sub['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this subscriber permanently?');">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="no-items">No subscribers yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== TINYMCE EDITOR ===== -->
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
            editor.on('change', function () {
                tinymce.triggerSave();
            });
        }
    });
</script>

<!-- ===== MAIN JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== SCHEDULE TOGGLE =====
    const sendNowRadio = document.querySelector('input[name="action_type"][value="send_now"]');
    const scheduleRadio = document.querySelector('input[name="action_type"][value="schedule"]');
    const scheduleGroup = document.getElementById('scheduleDateTimeGroup');

    sendNowRadio.addEventListener('change', function() {
        scheduleGroup.style.display = 'none';
    });
    scheduleRadio.addEventListener('change', function() {
        scheduleGroup.style.display = 'block';
    });

    // ===== RADIO TOGGLES =====
    const radioAll = document.querySelector('input[name="send_to"][value="all"]');
    const radioSelected = document.querySelector('input[name="send_to"][value="selected"]');
    const radioSingle = document.querySelector('input[name="send_to"][value="single"]');
    const singleGroup = document.getElementById('singleEmailGroup');
    const selectedGroup = document.getElementById('selectedSubscriberGroup');
    const selectAllCheckbox = document.getElementById('selectAllSubscribers');
    const subscriberCheckboxes = document.querySelectorAll('.subscriber-checkbox');
    const selectedEmailsInput = document.getElementById('selectedEmailsInput');
    const selectedEmailsDisplay = document.getElementById('selectedEmailsDisplay');

    function updateSelectedEmails() {
        let selected = [];
        let htmlContent = '';
        subscriberCheckboxes.forEach(cb => {
            if (cb.checked) {
                selected.push(cb.value);
            }
        });
        selectedEmailsInput.value = selected.join(',');

        if (selected.length === 0) {
            htmlContent = '<span style="color: var(--text-light); font-size: 0.85rem;">No subscribers selected.</span>';
        } else {
            selected.forEach(email => {
                htmlContent += `<span style="background: var(--rose-light); color: var(--dark); padding: 4px 10px; border-radius: 16px; font-size: 0.8rem; display: inline-block; border: 1px solid var(--rose);">${email}</span>`;
            });
        }
        selectedEmailsDisplay.innerHTML = htmlContent;

        const total = subscriberCheckboxes.length;
        const checked = document.querySelectorAll('.subscriber-checkbox:checked').length;
        selectAllCheckbox.checked = (total > 0 && checked === total);
        selectAllCheckbox.indeterminate = (checked > 0 && checked < total);
    }

    function updateVisibility() {
        if (radioAll.checked) {
            singleGroup.style.display = 'none';
            selectedGroup.style.display = 'none';
        } else if (radioSelected.checked) {
            singleGroup.style.display = 'none';
            selectedGroup.style.display = 'block';
        } else if (radioSingle.checked) {
            singleGroup.style.display = 'block';
            selectedGroup.style.display = 'none';
        }
    }

    radioAll.addEventListener('change', updateVisibility);
    radioSelected.addEventListener('change', updateVisibility);
    radioSingle.addEventListener('change', updateVisibility);

    selectAllCheckbox.addEventListener('change', function() {
        subscriberCheckboxes.forEach(cb => {
            cb.checked = this.checked;
        });
        updateSelectedEmails();
    });

    subscriberCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectedEmails);
    });

    updateVisibility();
    updateSelectedEmails();

    // ===== VALIDATION FOR EMPTY SELECTION =====
    document.getElementById('newsletterForm').addEventListener('submit', function(e) {
        const sendTo = document.querySelector('input[name="send_to"]:checked').value;
        if (sendTo === 'selected') {
            const selected = document.querySelectorAll('.subscriber-checkbox:checked');
            if (selected.length === 0) {
                e.preventDefault();
                alert('Please select at least one subscriber.');
            }
        }
        const actionType = document.querySelector('input[name="action_type"]:checked').value;
        if (actionType === 'schedule') {
            const datetime = document.getElementById('scheduled_datetime').value;
            if (!datetime) {
                e.preventDefault();
                alert('Please select a schedule date and time.');
            }
        }
    });

    // ===== PREVIEW =====
    document.getElementById('previewBtn').addEventListener('click', function() {
        const content = tinymce.get('editor').getContent();
        const subject = document.getElementById('subject').value;
        const previewWindow = window.open('', 'Preview', 'width=600,height=800');
        previewWindow.document.write(`
            <html><head><title>Newsletter Preview</title></head>
            <body style="font-family: Inter, sans-serif; padding:20px; max-width:600px; margin:0 auto;">
                <h2 style="color: var(--rose);">${subject}</h2>
                <hr>
                ${content}
            </body></html>
        `);
    });

    // ===== SEND TEST =====
    document.getElementById('testSendBtn').addEventListener('click', function() {
        const content = tinymce.get('editor').getContent();
        const subject = document.getElementById('subject').value;
        if (!subject || !content) {
            alert('Please fill in both subject and content before sending a test.');
            return;
        }
        const formData = new FormData();
        formData.append('subject', subject);
        formData.append('content', content);
        formData.append('send_to', 'single');
        formData.append('single_email', 'angelwrites@zohomail.com');
        formData.append('send_newsletter', '1');

        fetch('<?php echo SITE_URL; ?>/admin/manage_newsletter.php', {
            method: 'POST',
            body: formData
        }).then(response => response.text()).then(() => {
            alert('Test email sent to angelwrites@zohomail.com.');
        }).catch(() => {
            alert('Failed to send test email.');
        });
    });

    // ===== SAVE TEMPLATE =====
    document.getElementById('saveTemplateBtnAction').addEventListener('click', function() {
        const name = document.getElementById('template_name').value;
        const subject = document.getElementById('subject').value;
        const content = tinymce.get('editor').getContent();
        if (!name || !subject || !content) {
            alert('Please fill in template name, subject, and content.');
            return;
        }
        const formData = new FormData();
        formData.append('template_name', name);
        formData.append('subject', subject);
        formData.append('content', content);
        formData.append('save_template', '1');

        fetch('<?php echo SITE_URL; ?>/admin/manage_newsletter.php', {
            method: 'POST',
            body: formData
        }).then(() => {
            location.reload();
        });
    });

    // ===== LOAD TEMPLATE =====
    document.getElementById('loadTemplateBtn').addEventListener('click', function() {
        const templateId = document.getElementById('templateSelect').value;
        if (!templateId) {
            alert('Please select a template.');
            return;
        }
        const formData = new FormData();
        formData.append('load_template', '1');
        formData.append('template_id', templateId);
        fetch('<?php echo SITE_URL; ?>/admin/manage_newsletter.php', {
            method: 'POST',
            body: formData
        }).then(() => {
            location.reload();
        });
    });

    // ===== ATTACHMENT PREVIEW =====
    const fileInput = document.getElementById('attachments');
    const attachmentPreview = document.getElementById('attachmentPreview');

    fileInput.addEventListener('change', function() {
        attachmentPreview.innerHTML = '';
        if (this.files) {
            Array.from(this.files).forEach(file => {
                const span = document.createElement('span');
                span.style.cssText = 'background: var(--vanilla); padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; display: inline-block; border: 1px solid var(--border);';
                span.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                attachmentPreview.appendChild(span);
            });
        }
    });

    // ===== BULK ACTIONS (SUBSCRIBER TABLE) =====
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkButton);
    });

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn.addEventListener('click', function() {
        const action = bulkActionSelect.value;
        const selectedIds = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || selectedIds.length === 0) {
            alert('Please select an action and at least one subscriber.');
            return;
        }
        if (!confirm(`Are you sure you want to ${action} ${selectedIds.length} subscriber(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = selectedIds.join(',');
        document.getElementById('bulkForm').submit();
    });
});
</script>

<style>
    .admin-page { padding: 32px 0 60px; }
    .admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
    .admin-header h1 { font-size: 2rem; margin: 0; }
    .admin-actions { display: flex; gap: 12px; }
    .card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
    .card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
    .card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
    .card-body { padding: 20px; }

    .admin-form .form-group { margin-bottom: 16px; }
    .admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
    .admin-form input[type="text"], .admin-form input[type="email"], .admin-form textarea, .admin-form select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 1rem;
        background: var(--input-bg);
        color: var(--text);
        transition: border-color 0.3s, box-shadow 0.3s;
    }
    .admin-form input:focus, .admin-form textarea:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .admin-form textarea { resize: vertical; min-height: 120px; }
    .required { color: #dc2626; }
    .admin-form .btn-block { width: 100%; justify-content: center; padding: 14px; font-size: 1.05rem; border-radius: 30px; }
    .admin-form .form-actions { margin-top: 16px; }

    .table-responsive { overflow-x: auto; border-radius: 12px; }
    .admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
    .admin-table thead { background: var(--vanilla); }
    .admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
    .admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }
    .admin-table tbody tr:last-child td { border-bottom: none; }
    .admin-table td.actions { display: flex; gap: 4px; flex-wrap: wrap; }
    .admin-table td.actions .btn { padding: 4px 12px; font-size: 0.75rem; border-radius: 20px; }

    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
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
        .admin-actions { width: 100%; }
    }
</style>

<?php require_once '../includes/footer.php'; ?>