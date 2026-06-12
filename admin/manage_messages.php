<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ===== MARK AS READ =====
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $stmt = $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Message marked as read.';
    header('Location: ' . SITE_URL . '/admin/manage_messages.php');
    exit;
}

// ===== MARK AS UNREAD =====
if (isset($_GET['unread'])) {
    $id = (int)$_GET['unread'];
    $stmt = $db->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Message marked as unread.';
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

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM contact_messages WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' messages deleted.';
    } elseif (!empty($ids) && $action === 'mark_read') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE contact_messages SET is_read = 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' messages marked as read.';
    } elseif (!empty($ids) && $action === 'mark_unread') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE contact_messages SET is_read = 0 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' messages marked as unread.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_messages.php');
    exit;
}

// ===== REPLY =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $to = trim($_POST['to_email']);
    $subject = trim($_POST['reply_subject']);
    $reply_message = trim($_POST['reply_message']);

    if (empty($to) || empty($subject) || empty($reply_message)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
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

// ===== FETCH TOTAL MESSAGES =====
$count_sql = "SELECT COUNT(*) FROM contact_messages";
$count_params = [];
if ($search) {
    $count_sql .= " WHERE name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_messages = $stmt->fetchColumn();
$total_pages = ceil($total_messages / $limit);

// ===== FETCH MESSAGES =====
$sql = "SELECT * FROM contact_messages";
$params = [];
if ($search) {
    $sql .= " WHERE name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Contact Messages';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Contact Messages</h1>
            <div class="admin-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
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

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search messages by name, email, subject, or content..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Messages List -->
        <div class="card">
            <div class="card-header">
                <h2>All Messages (<?php echo $total_messages; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                        <option value="mark_read">Mark as Read</option>
                        <option value="mark_unread">Mark as Unread</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($messages) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Sender</th>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Received</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($messages as $msg): ?>
                                        <tr class="<?php echo $msg['is_read'] ? 'read' : 'unread'; ?>">
                                            <td><input type="checkbox" class="row-select" value="<?php echo $msg['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($msg['name']); ?></strong>
                                                <br><small><?php echo htmlspecialchars($msg['email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($msg['subject'] ?? 'No subject'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $msg['is_read'] ? 'read' : 'unread'; ?>">
                                                    <?php echo $msg['is_read'] ? 'Read' : 'Unread'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y, g:i a', strtotime($msg['created_at'])); ?></td>
                                            <td class="actions">
                                                <button class="btn btn-sm btn-primary reply-btn" 
                                                        data-email="<?php echo htmlspecialchars($msg['email']); ?>" 
                                                        data-name="<?php echo htmlspecialchars($msg['name']); ?>">
                                                    <i class="fas fa-reply"></i>
                                                </button>
                                                <?php if (!$msg['is_read']): ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?read=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?unread=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary">
                                                        <i class="fas fa-undo"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php?delete=<?php echo $msg['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this message?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-items">No messages found.</p>
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

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('messagesTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('messagesTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== MODAL =====
    const modal = document.getElementById('replyModal');
    const closeBtn = document.querySelector('.modal-close');
    const toEmail = document.getElementById('to_email');
    const replySubject = document.getElementById('reply_subject');

    document.querySelectorAll('.reply-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            toEmail.value = this.dataset.email;
            replySubject.value = 'Re: Your message to AngelWrites';
            modal.style.display = 'flex';
        });
    });

    closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.style.display = 'none';
    });

    // ===== BULK ACTIONS =====
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn.addEventListener('click', function() {
        const action = bulkActionSelect.value;
        const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || ids.length === 0) {
            alert('Please select an action and at least one message.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} message(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = ids.join(',');
        document.getElementById('bulkForm').submit();
    });
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.admin-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.admin-table th { background: var(--vanilla); padding: 10px 16px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.admin-table tr.read { opacity: 0.7; }
.admin-table tr.unread { font-weight: 600; background: rgba(219,161,162,0.05); }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }

.status-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.status-badge.read { background: #95a5a6; color: white; }
.status-badge.unread { background: var(--rose); color: white; }

.actions { display: flex; gap: 4px; flex-wrap: wrap; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 20px; }

.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

/* ===== MODAL ===== */
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; }
.modal-content { background: var(--card-bg); border-radius: 16px; padding: 32px; width: 90%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text); transition: color 0.2s; }
.modal-close:hover { color: var(--rose); }
.reply-form .form-group { margin-bottom: 16px; }
.reply-form label { display: block; font-weight: 600; margin-bottom: 4px; }
.reply-form input, .reply-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.reply-form input:focus, .reply-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.reply-form textarea { resize: vertical; min-height: 100px; }
.reply-form .btn-block { width: 100%; padding: 12px; font-size: 1rem; }

@media (max-width: 480px) {
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
    .admin-table th, .admin-table td { padding: 8px 10px; font-size: 0.85rem; }
}
</style>

<?php require_once '../includes/footer.php'; ?>