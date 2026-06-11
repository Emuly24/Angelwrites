<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== MARK AS READ =====
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $stmt = $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Message marked as read.';
    header('Location: ' . SITE_URL . '/admin/manage_messages.php');
    exit;
}

// ===== DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Message deleted.';
    header('Location: ' . SITE_URL . '/admin/manage_messages.php');
    exit;
}

// ===== REPLY (Send email via Zoho SMTP) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $to = trim($_POST['to_email']);
    $subject = trim($_POST['reply_subject']);
    $reply_message = trim($_POST['reply_message']);

    if (empty($to) || empty($subject) || empty($reply_message)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        // Build the reply message (HTML)
        $html_body = "<div style='font-family: Inter, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #333;'>";
        $html_body .= "<h2 style='color: #DBA1A2;'>Reply from AngelWrites</h2>";
        $html_body .= "<p>" . nl2br(htmlspecialchars($reply_message)) . "</p>";
        $html_body .= "<hr style='border: 1px solid #eee;'>";
        $html_body .= "<p style='font-size: 0.9rem; color: #999;'>— Sent via AngelWrites Admin</p>";
        $html_body .= "</div>";

        if (sendEmail($to, $subject, $html_body, 'angelwrites@zohomail.com', 'AngelWrites Admin')) {
            $success = 'Reply sent successfully!';
        } else {
            $error = 'Failed to send reply. Please check your email settings.';
        }
    }
}

// ===== FETCH MESSAGES =====
$stmt = $db->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Contact Messages';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Contact Messages</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>All Messages (<?php echo count($messages); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($messages) > 0): ?>
                    <div class="messages-list">
                        <?php foreach ($messages as $msg): ?>
                            <div class="message-item <?php echo $msg['is_read'] ? 'read' : 'unread'; ?>">
                                <div class="message-header">
                                    <div class="message-sender">
                                        <strong><?php echo htmlspecialchars($msg['name']); ?></strong>
                                        <span><?php echo htmlspecialchars($msg['email']); ?></span>
                                    </div>
                                    <div class="message-meta">
                                        <span class="message-date"><?php echo date('M j, Y g:i a', strtotime($msg['created_at'])); ?></span>
                                        <?php if (!$msg['is_read']): ?>
                                            <span class="badge-unread">Unread</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($msg['subject']): ?>
                                    <div class="message-subject"><strong><?php echo htmlspecialchars($msg['subject']); ?></strong></div>
                                <?php endif; ?>
                                <div class="message-body"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                <div class="message-actions">
                                    <!-- Reply Button -->
                                    <button class="btn btn-sm btn-primary reply-btn" data-email="<?php echo htmlspecialchars($msg['email']); ?>" data-name="<?php echo htmlspecialchars($msg['name']); ?>">
                                        <i class="fas fa-reply"></i> Reply
                                    </button>
                                    <?php if (!$msg['is_read']): ?>
                                        <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?read=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-check"></i> Mark read
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?delete=<?php echo $msg['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this message?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-items">No messages yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== REPLY MODAL ===== -->
<div id="replyModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Reply to Message</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" class="reply-form">
            <div class="form-group">
                <label for="to_email">To</label>
                <input type="email" id="to_email" name="to_email" readonly required>
            </div>
            <div class="form-group">
                <label for="reply_subject">Subject</label>
                <input type="text" id="reply_subject" name="reply_subject" placeholder="Reply subject" required>
            </div>
            <div class="form-group">
                <label for="reply_message">Message</label>
                <textarea id="reply_message" name="reply_message" rows="6" placeholder="Write your reply here..." required></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" name="send_reply" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i> Send Reply
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.messages-list { display: flex; flex-direction: column; gap: 16px; }
.message-item { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.message-item.unread { border-left: 4px solid var(--rose); background: rgba(219, 161, 162, 0.05); }
.message-item.read { opacity: 0.85; }
.message-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
.message-sender { display: flex; flex-direction: column; }
.message-sender strong { font-size: 1.05rem; }
.message-sender span { color: var(--text-light); font-size: 0.9rem; }
.message-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.message-date { color: var(--text-light); font-size: 0.85rem; }
.badge-unread { background: var(--rose); color: white; padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.message-subject { margin: 6px 0 8px; }
.message-body { color: var(--text); line-height: 1.6; margin-bottom: 12px; white-space: pre-wrap; }
.message-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ===== MODAL ===== */
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; }
.modal-content { background: var(--card-bg); border-radius: 16px; padding: 32px; width: 90%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text); transition: color 0.2s; }
.modal-close:hover { color: var(--rose); }
.reply-form .form-group { margin-bottom: 16px; }
.reply-form label { display: block; font-weight: 600; margin-bottom: 4px; }
.reply-form input, .reply-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.reply-form input:focus, .reply-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.reply-form textarea { resize: vertical; min-height: 100px; }
.reply-form .btn-block { width: 100%; padding: 12px; font-size: 1rem; }

.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('replyModal');
    const closeBtn = document.querySelector('.modal-close');
    const toEmail = document.getElementById('to_email');
    const replySubject = document.getElementById('reply_subject');

    // Open modal with data
    document.querySelectorAll('.reply-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const email = this.dataset.email;
            const name = this.dataset.name;
            toEmail.value = email;
            replySubject.value = 'Re: Your message to AngelWrites';
            modal.style.display = 'flex';
        });
    });

    // Close modal
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>