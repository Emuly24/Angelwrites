<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Prevent admin from deleting themselves
    if ($id === $_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'User deleted successfully.';
        header('Location: ' . SITE_URL . '/admin/manage_users.php');
        exit;
    }
}

// ===== HANDLE ROLE CHANGE =====
if (isset($_POST['change_role'])) {
    $user_id = (int)$_POST['user_id'];
    $new_role = $_POST['role'];
    
    if ($user_id === $_SESSION['user_id']) {
        $error = 'You cannot change your own role.';
    } elseif (!in_array($new_role, ['reader', 'admin'])) {
        $error = 'Invalid role.';
    } else {
        $stmt = $db->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_role, $user_id]);
        $success = 'User role updated successfully.';
        header('Location: ' . SITE_URL . '/admin/manage_users.php');
        exit;
    }
}

// ===== FETCH ALL USERS =====
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Users';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Users</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>All Users (<?php echo count($users); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($users) > 0): ?>
                    <div class="users-table-wrapper">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                            <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                <span class="badge you">You</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                <span class="role-badge admin">Admin</span>
                                            <?php else: ?>
                                                <form method="POST" class="role-form">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <select name="role" onchange="this.form.submit()" class="role-select <?php echo $user['role']; ?>">
                                                        <option value="reader" <?php echo $user['role'] === 'reader' ? 'selected' : ''; ?>>Reader</option>
                                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    </select>
                                                    <input type="hidden" name="change_role" value="1">
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td class="actions">
                                            <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No users found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .users-table-wrapper { overflow-x: auto; }
    .users-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .users-table th { background: var(--vanilla); text-align: left; padding: 12px 16px; font-weight: 600; border-bottom: 2px solid var(--border); }
    .users-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .users-table tr:hover { background: rgba(219, 161, 162, 0.05); }
    
    .role-badge { padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
    .role-badge.admin { background: var(--rose); color: white; }
    .role-badge.reader { background: var(--vanilla); color: var(--text); }
    
    .role-select { padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border); font-size: 0.85rem; cursor: pointer; background: var(--input-bg); color: var(--text); }
    .role-select.reader { border-left: 4px solid #3498db; }
    .role-select.admin { border-left: 4px solid #e74c3c; }
    
    .badge.you { background: var(--rose); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; margin-left: 6px; }
    .role-form { margin: 0; }
</style>

<?php require_once '../includes/footer.php'; ?>