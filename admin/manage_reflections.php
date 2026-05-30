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
    $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Reflection deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_reflections.php');
    exit;
}

// ===== FETCH ALL REFLECTIONS =====
// Get category ID first
$stmt = $db->prepare("SELECT id FROM blog_categories WHERE name = ?");
$stmt->execute(['Christian Reflections']);
$cat_id = $stmt->fetchColumn();

if (!$cat_id) {
    $reflections = [];
} else {
    $stmt = $db->prepare("
        SELECT p.*, c.name AS category_name
        FROM blog_posts p
        LEFT JOIN blog_categories c ON p.category_id = c.id
        WHERE p.category_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$cat_id]);
    $reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Manage Reflections';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Christian Reflections</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=reflection" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> New Reflection
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
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
                <h2>All Reflections (<?php echo count($reflections); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($reflections) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Views</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reflections as $post): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                            <br><small><?php echo date('M j, Y', strtotime($post['created_at'])); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($post['category_name'] ?? 'Christian Reflections'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $post['status']; ?>">
                                                <?php echo ucfirst($post['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($post['views'] ?? 0); ?></td>
                                        <td class="actions">
                                            <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=reflection&id=<?php echo $post['id']; ?>" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php?delete=<?php echo $post['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this reflection?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No reflections yet. Click "New Reflection" to get started.</p>
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
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }
.actions { display: flex; gap: 6px; }
.status-badge.draft { color: #f39c12; background: rgba(243,156,18,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
.status-badge.published { color: #27ae60; background: rgba(39,174,96,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
</style>

<?php require_once '../includes/footer.php'; ?>