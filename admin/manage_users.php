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

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === $_SESSION['user_id']) {
        $error = 'You cannot delete your own account.';
    } else {
        $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'User deleted successfully.';
        
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '👤 User Deleted: ' . $user['name'];
        $body = "<h2>User Account Deleted</h2>";
        $body .= "<p><strong>Name:</strong> " . $user['name'] . "</p>";
        $body .= "<p><strong>Email:</strong> " . $user['email'] . "</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
        
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
        $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_role, $user_id]);
        $success = 'User role updated successfully.';
        
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '🔄 Role Changed: ' . $user['name'];
        $body = "<h2>User Role Updated</h2>";
        $body .= "<p><strong>Name:</strong> " . $user['name'] . "</p>";
        $body .= "<p><strong>Email:</strong> " . $user['email'] . "</p>";
        $body .= "<p><strong>New Role:</strong> " . ucfirst($new_role) . "</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
        
        header('Location: ' . SITE_URL . '/admin/manage_users.php');
        exit;
    }
}

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        // Filter out current admin
        $ids = array_filter($ids, function($id) { return $id != $_SESSION['user_id']; });
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . ' users deleted.';
        } else {
            $error = 'You cannot delete your own account.';
        }
    } elseif (!empty($ids) && $action === 'make_admin') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE users SET role = 'admin', updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' users promoted to admin.';
    } elseif (!empty($ids) && $action === 'make_reader') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE users SET role = 'reader', updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' users demoted to reader.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_users.php');
    exit;
}

// ===== FETCH TOTAL USERS =====
$count_sql = "SELECT COUNT(*) FROM users";
$count_params = [];
if ($search) {
    $count_sql .= " WHERE name LIKE ? OR email LIKE ? OR username LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_users = $stmt->fetchColumn();
$total_pages = ceil($total_users / $limit);

// ===== FETCH USERS =====
$sql = "SELECT * FROM users";
$params = [];
if ($search) {
    $sql .= " WHERE name LIKE ? OR email LIKE ? OR username LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Users';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Users</h1>
            <div class="admin-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
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

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search users by name, email, or username..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Users (<?php echo $total_users; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="make_admin">Promote to Admin</option>
                        <option value="make_reader">Demote to Reader</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($users) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $user['id']; ?>"></td>
                                            <td><?php echo $user['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                    <span class="badge-you">You</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                    <span class="role-badge admin">Admin</span>
                                                <?php else: ?>
                                                    <form method="POST" class="role-form" style="display:inline;">
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
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user?');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
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
                    <p class="no-items">No users found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('usersTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('usersTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

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
            alert('Please select an action and at least one user.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} user(s)?`)) return;
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
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }

.role-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.role-badge.admin { background: var(--rose); color: white; }
.role-badge.reader { background: var(--vanilla); color: var(--text); }

.role-select { padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border); font-size: 0.85rem; cursor: pointer; background: var(--input-bg); color: var(--text); }
.role-select.reader { border-left: 4px solid #3498db; }
.role-select.admin { border-left: 4px solid #e74c3c; }

.badge-you { background: var(--rose); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; margin-left: 6px; }

.actions { display: flex; gap: 4px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 20px; }

.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

@media (max-width: 480px) {
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
    .admin-table th, .admin-table td { padding: 8px 10px; font-size: 0.85rem; }
}
</style>

<?php require_once '../includes/footer.php'; ?>