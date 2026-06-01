<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM newsletter WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Subscriber removed.';
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
    exit;
}

// ===== HANDLE UNSUBSCRIBE =====
if (isset($_GET['unsubscribe'])) {
    $id = (int)$_GET['unsubscribe'];
    $stmt = $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Subscriber unsubscribed.';
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
    exit;
}

// ===== HANDLE BROADCAST EMAIL =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['broadcast'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    if (empty($subject) || empty($message)) {
        $error = 'Subject and message are required.';
    } else {
        // Fetch all active subscribers
        $stmt = $db->prepare("SELECT email FROM newsletter WHERE is_active = 1");
        $stmt->execute();
        $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $count = 0;
        foreach ($subscribers as $email) {
            $headers = "From: " . SITE_NAME . " <admin@angelawrites.com>\r\n";
            $headers .= "Reply-To: admin@angelawrites.com\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            if (mail($email, $subject, nl2br($message), $headers)) {
                $count++;
            }
        }
        $success = "Broadcast sent to $count active subscribers.";
    }
}

// ===== FETCH SUBSCRIBERS =====
$stmt = $db->query("SELECT * FROM newsletter ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active subscriber count
$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1");
$active_count = $stmt->fetchColumn();

$pageTitle = 'Newsletter';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Newsletter Subscribers</h1>
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

        <!-- Broadcast Email Form -->
        <div class="card">
            <div class="card-header">
                <h2>📨 Broadcast Email to Subscribers (<?php echo $active_count; ?> active)</h2>
            </div>
            <div class="card-body">
                <form method="POST" class="admin-form">
                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <input type="text" id="subject" name="subject" placeholder="Announcement title..." required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" rows="6" placeholder="Write your message here..." required></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="broadcast" class="btn btn-primary">Send to All Subscribers</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subscriber List -->
        <div class="card">
            <div class="card-header">
                <h2>All Subscribers (<?php echo count($subscribers); ?>)</h2>
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
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?delete=<?php echo $sub['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this subscriber?');">
                                                <i class="fas fa-trash"></i>
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

<style>
    .admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
    .admin-table thead { background: var(--vanilla); }
    .admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
    .admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }
    .admin-table tbody tr:last-child td { border-bottom: none; }
    .table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
    .status-badge.active { background: #27ae60; color: #fff; }
    .status-badge.inactive { background: #95a5a6; color: #fff; }
    .no-items { text-align: center; padding: 40px 0; color: var(--text-light); }
</style>

<?php require_once '../includes/footer.php'; ?>