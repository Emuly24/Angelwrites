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

// ===== FETCH SUBSCRIBERS =====
$stmt = $db->query("SELECT * FROM newsletter ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        <div class="card">
            <div class="card-header">
                <h2>Subscribers (<?php echo count($subscribers); ?>)</h2>
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
.admin-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.admin-table th { background: var(--vanilla); text-align: left; padding: 12px 16px; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.admin-table tr:hover { background: rgba(219, 161, 162, 0.05); }
.status-badge.active { color: #27ae60; background: rgba(39,174,96,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
.status-badge.inactive { color: #7f8c8d; background: rgba(127,140,141,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
.table-responsive { overflow-x: auto; }
</style>

<?php require_once '../includes/footer.php'; ?>