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
        <!-- ===== HERO / HEADER ===== -->
        <div class="admin-hero">
            <div class="admin-hero-content">
                <h1>👥 Manage Users</h1>
                <p class="admin-hero-sub">View, edit, and manage all user accounts on AngelWrites.</p>
            </div>
            <div class="admin-hero-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ===== SEARCH BAR ===== -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search users by name, email, or username..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== USERS TABLE ===== -->
        <div class="card">
            <div class="card-header">
                <h2>All Users <span class="count-badge"><?php echo $total_users; ?></span></h2>
                <div class="card-header-actions">
                    <select id="bulkActionSelect" class="bulk-select">
                        <option value="">Bulk Actions</option>
                        <option value="make_admin">👑 Promote to Admin</option>
                        <option value="make_reader">📖 Demote to Reader</option>
                        <option value="delete">🗑️ Delete Selected</option>
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
                                        <th class="check-col"><input type="checkbox" id="selectAllRows" class="styled-checkbox"></th>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Joined</th>
                                        <th class="actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select styled-checkbox" value="<?php echo $user['id']; ?>"></td>
                                            <td><span class="user-id"><?php echo $user['id']; ?></span></td>
                                            <td>
                                                <div class="user-name-cell">
                                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                    <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                        <span class="badge-you">You</span>
                                                    <?php endif; ?>
                                                </div>
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
                                            <td class="actions-cell">
                                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger action-btn" onclick="return confirm('Delete this user?');" title="Delete">
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

                    <!-- ===== PAGINATION ===== -->
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
                    <div class="empty-state">
                        <i class="fas fa-users empty-icon"></i>
                        <h3>No users found</h3>
                        <p>Try adjusting your search.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== BULK ACTIONS =====
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows?.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn?.addEventListener('click', function() {
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

<!-- ===== STYLES ===== -->
<style>
/* ===== BRAND VARIABLES ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background 0.3s, color 0.3s; }

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS ===== */
.btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
    border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
    cursor:pointer; text-decoration:none; transition:all var(--transition);
    box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px;
}
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-danger { background:#dc3545; color:white; border:2px solid #dc3545; }
.btn-danger:hover { background:#c82333; border-color:#c82333; }

/* ===== PAGE ===== */
.admin-page { padding:32px 0 60px; }

/* ===== HERO ===== */
.admin-hero {
    background:linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius:20px; padding:24px 32px; margin-bottom:24px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;
    border:1px solid var(--rose-light); box-shadow:var(--shadow);
}
.admin-hero-content h1 { font-size:2rem; margin:0 0 4px 0; color:var(--dark); }
.admin-hero-sub { color:var(--text-light); font-size:1.05rem; margin:0; }
.admin-hero-actions { display:flex; gap:12px; flex-wrap:wrap; }

/* ===== ALERTS ===== */
.alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; font-weight:500; }
.alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }

/* ===== SEARCH BAR ===== */
.search-bar { margin-bottom:24px; }
.search-form { display:flex; gap:8px; flex-wrap:wrap; }
.search-form input { flex:1; min-width:200px; padding:12px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.95rem; background:var(--card-bg); color:var(--text); transition:border-color 0.2s; }
.search-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding:8px 20px; font-size:0.85rem; border-radius:50px; }

/* ===== CARD ===== */
.card { background:var(--card-bg); border-radius:20px; border:1px solid var(--border); box-shadow:var(--shadow); overflow:hidden; margin-bottom:24px; transition:all var(--transition); }
.card:hover { box-shadow:var(--shadow-hover); }
.card-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:var(--vanilla); }
.card-header h2 { font-size:1.3rem; margin:0; font-family:'Playfair Display',Georgia,serif; color:var(--dark); display:flex; align-items:center; gap:8px; }
.count-badge { background:var(--rose); color:white; padding:2px 12px; border-radius:20px; font-size:0.8rem; font-weight:600; }
.card-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
.card-body { padding:24px; }

/* ===== TABLE ===== */
.table-responsive { overflow-x:auto; border-radius:12px; border:1px solid var(--border); }
.admin-table { width:100%; border-collapse:separate; border-spacing:0; }
.admin-table thead { background:var(--vanilla); }
.admin-table th { text-align:left; padding:14px 20px; font-weight:600; color:var(--text); border-bottom:2px solid var(--border); font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px; }
.admin-table td { padding:14px 20px; border-bottom:1px solid var(--border); vertical-align:middle; color:var(--text); font-size:0.9rem; }
.admin-table tbody tr:hover { background:rgba(219,161,162,0.08); }
.admin-table .check-col { width:40px; }
.admin-table .actions-col { width:80px; }

.styled-checkbox { width:18px; height:18px; accent-color:var(--rose); cursor:pointer; }
.user-id { font-weight:500; color:var(--text-light); }
.user-name-cell { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.badge-you { background:var(--rose); color:white; padding:2px 10px; border-radius:20px; font-size:0.7rem; font-weight:600; }

/* ===== ROLE ===== */
.role-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:600; }
.role-badge.admin { background:var(--rose); color:white; }
.role-badge.reader { background:var(--vanilla); color:var(--text); }
.role-select { padding:6px 10px; border-radius:8px; border:1px solid var(--border); font-size:0.85rem; cursor:pointer; background:var(--input-bg); color:var(--text); transition:border-color 0.2s; }
.role-select:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.role-select.reader { border-left:4px solid #3498db; }
.role-select.admin { border-left:4px solid #e74c3c; }

/* ===== BULK ===== */
.bulk-select { padding:6px 12px; border-radius:8px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); font-size:0.85rem; }

/* ===== ACTIONS ===== */
.actions-cell { display:flex; gap:4px; }
.action-btn { padding:6px 10px; font-size:0.8rem; border-radius:8px; min-width:32px; justify-content:center; }

/* ===== PAGINATION ===== */
.pagination { display:flex; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap; }
.page-link { display:inline-flex; align-items:center; justify-content:center; padding:6px 14px; border-radius:8px; background:var(--card-bg); border:1px solid var(--border); color:var(--text); font-size:0.9rem; transition:all 0.2s; min-width:36px; text-decoration:none; }
.page-link:hover { border-color:var(--rose); }
.page-link.active { background:var(--rose); color:white; border-color:var(--rose); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align:center; padding:40px 20px; color:var(--text-light); }
.empty-icon { font-size:3rem; color:var(--rose); margin-bottom:16px; opacity:0.6; }
.empty-state h3 { font-size:1.3rem; margin-bottom:4px; color:var(--text); }
.empty-state p { margin:0; font-size:0.95rem; }

/* ===== RESPONSIVE ===== */
@media (max-width:992px) {
    .admin-hero { flex-direction:column; text-align:center; align-items:center; }
    .admin-hero-actions { justify-content:center; }
    .admin-hero-content h1 { font-size:1.6rem; }
}
@media (max-width:768px) {
    .admin-table th, .admin-table td { padding:10px 12px; font-size:0.8rem; }
    .actions-cell { flex-wrap:wrap; }
}
</style>

<?php require_once '../includes/footer.php'; ?>