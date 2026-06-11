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

// ===== HANDLE BROADCAST / SINGLE SEND =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_newsletter'])) {
    $subject = trim($_POST['subject']);
    $content = trim($_POST['content']);
    $send_to = $_POST['send_to'] ?? 'all';

    if (empty($subject) || empty($content)) {
        $error = 'Please fill in both subject and content.';
    } else {
        $sent_count = 0;
        $failed_count = 0;

        // Fetch active subscribers for broadcast
        $stmt = $db->prepare("SELECT id, email, name, unsubscribe_token FROM newsletter WHERE is_active = 1");
        $stmt->execute();
        $active_subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($send_to === 'all') {
            foreach ($active_subscribers as $sub) {
                $unsubscribe_link = SITE_URL . '/unsubscribe.php?token=' . $sub['unsubscribe_token'];
                $full_message = $content . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";

                if (sendEmail($sub['email'], $subject, $full_message, 'angelwrites@zohomail.com', 'AngelWrites Newsletter')) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
                usleep(500000); // 0.5s delay to respect Zoho rate limits
            }
        } else {
            // Send to a single email
            $email = trim($_POST['single_email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Check if email is a subscriber (active or not)
                $stmt = $db->prepare("SELECT id, unsubscribe_token FROM newsletter WHERE email = ?");
                $stmt->execute([$email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $token = $existing['unsubscribe_token'];
                } else {
                    $token = bin2hex(random_bytes(32));
                }
                $unsubscribe_link = SITE_URL . '/unsubscribe.php?token=' . $token;
                $full_message = $content . "\n\n<hr><p style='font-size:0.8rem;'>To unsubscribe, <a href=\"$unsubscribe_link\">click here</a>.</p>";

                if (sendEmail($email, $subject, $full_message, 'angelwrites@zohomail.com', 'AngelWrites Newsletter')) {
                    $sent_count = 1;
                } else {
                    $failed_count = 1;
                }
            } else {
                $error = 'Invalid email address for single send.';
            }
        }

        if ($sent_count > 0) {
            $message = "Newsletter sent successfully! Sent: $sent_count, Failed: $failed_count.";
        } else {
            $error = 'Failed to send any emails. Please check your mail settings.';
        }
    }
}

$pageTitle = 'Manage Newsletter';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>📨 Newsletter Management</h1>
            <div class="admin-actions">
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
                <h2>📤 Send Newsletter (<?php echo $active_count; ?> active subscribers)</h2>
            </div>
            <div class="card-body">
                <form method="POST" class="admin-form">
                    <div class="form-group">
                        <label for="subject">Subject <span class="required">*</span></label>
                        <input type="text" id="subject" name="subject" placeholder="e.g. New Book Release Announcement" required>
                    </div>
                    <div class="form-group">
                        <label for="content">Message Content (HTML allowed) <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="12" placeholder="Write your newsletter content here..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Send to</label>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="send_to" value="all" checked> All Active Subscribers (<?php echo $active_count; ?>)
                            </label>
                            <label style="display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="send_to" value="single"> Single Email
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="singleEmailGroup" style="display: none;">
                        <label for="single_email">Enter Subscriber Email</label>
                        <input type="email" id="single_email" name="single_email" placeholder="subscriber@example.com">
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="send_newsletter" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send Newsletter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subscriber List -->
        <div class="card">
            <div class="card-header">
                <h2>📋 All Subscribers (<?php echo count($subscribers); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($subscribers) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const radioAll = document.querySelector('input[name="send_to"][value="all"]');
    const radioSingle = document.querySelector('input[name="send_to"][value="single"]');
    const singleGroup = document.getElementById('singleEmailGroup');

    radioAll.addEventListener('change', function() {
        singleGroup.style.display = 'none';
    });
    radioSingle.addEventListener('change', function() {
        singleGroup.style.display = 'block';
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
    .no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

    @media (max-width: 768px) {
        .admin-header { flex-direction: column; align-items: flex-start; }
        .admin-actions { width: 100%; }
    }
</style>

<?php require_once '../includes/footer.php'; ?>