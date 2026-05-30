<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

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
                                    <?php if (!$msg['is_read']): ?>
                                        <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?read=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-check"></i> Mark read
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?delete=<?php echo $msg['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this message?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="mailto:<?php echo htmlspecialchars($msg['email']); ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-reply"></i> Reply
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
</style>

<?php require_once '../includes/footer.php'; ?>