<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ===== HANDLE STATUS UPDATE =====
if (isset($_POST['update_status'])) {
    $session_id = (int)$_POST['session_id'];
    $status = $_POST['status'];
    
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (in_array($status, $valid_statuses)) {
        $stmt = $db->prepare("UPDATE sessions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$status, $session_id]);
        $success = 'Session status updated successfully.';
    } else {
        $error = 'Invalid status.';
    }
}

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Session deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_sessions.php');
    exit;
}

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM sessions WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' sessions deleted.';
    } elseif (!empty($ids) && $action === 'confirm') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE sessions SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' sessions confirmed.';
    } elseif (!empty($ids) && $action === 'cancel') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE sessions SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' sessions cancelled.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_sessions.php');
    exit;
}

// ===== FETCH TOTAL SESSIONS =====
$count_sql = "SELECT COUNT(*) FROM sessions s JOIN users u ON s.user_id = u.id WHERE 1=1";
$count_params = [];
if ($search) {
    $count_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR s.date LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
if ($status_filter) {
    $count_sql .= " AND s.status = ?";
    $count_params[] = $status_filter;
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_sessions = $stmt->fetchColumn();
$total_pages = ceil($total_sessions / $limit);

// ===== FETCH SESSIONS =====
$sql = "
    SELECT s.*, u.name AS user_name, u.email AS user_email 
    FROM sessions s 
    JOIN users u ON s.user_id = u.id 
    WHERE 1=1
";
$params = [];
if ($search) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR s.date LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $sql .= " AND s.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY s.date DESC, s.time DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== STATUS COUNTS =====
$status_counts = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($sessions as $session) {
    if (isset($status_counts[$session['status']])) $status_counts[$session['status']]++;
}
// Total counts across all sessions (not just paginated)
$stmt = $db->query("SELECT status, COUNT(*) as count FROM sessions GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_counts[$row['status']] = $row['count'];
}

$pageTitle = 'Manage Sessions';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Sessions</h1>
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

        <!-- Status Summary Cards -->
        <div class="status-summary">
            <div class="summary-card">
                <div class="summary-count" style="color: #f1c40f;"><?php echo $status_counts['pending']; ?></div>
                <div class="summary-label">Pending</div>
            </div>
            <div class="summary-card">
                <div class="summary-count" style="color: #2ecc71;"><?php echo $status_counts['confirmed']; ?></div>
                <div class="summary-label">Confirmed</div>
            </div>
            <div class="summary-card">
                <div class="summary-count" style="color: #3498db;"><?php echo $status_counts['completed']; ?></div>
                <div class="summary-label">Completed</div>
            </div>
            <div class="summary-card">
                <div class="summary-count" style="color: #e74c3c;"><?php echo $status_counts['cancelled']; ?></div>
                <div class="summary-label">Cancelled</div>
            </div>
        </div>

        <!-- Search & Filter -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search by client name, email, or date..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
        </div>

        <!-- Sessions Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Sessions (<?php echo $total_sessions; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="confirm">Confirm Selected</option>
                        <option value="cancel">Cancel Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($sessions) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Client</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $session): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $session['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($session['user_name']); ?></strong>
                                                <br><small><?php echo htmlspecialchars($session['user_email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($session['date']); ?></td>
                                            <td><?php echo htmlspecialchars($session['time']); ?></td>
                                            <td><?php echo $session['duration'] ?? 60; ?> min</td>
                                            <td>
                                                <form method="POST" class="status-form">
                                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                                    <select name="status" onchange="this.form.submit()" class="status-select <?php echo $session['status']; ?>">
                                                        <option value="pending" <?php echo $session['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="confirmed" <?php echo $session['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                        <option value="completed" <?php echo $session['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="cancelled" <?php echo $session['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                    </select>
                                                    <input type="hidden" name="update_status" value="1">
                                                </form>
                                            </td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php?delete=<?php echo $session['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this session?');">
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
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-items">No sessions found.</p>
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
    const currentTheme = localStorage.getItem('sessionsTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('sessionsTheme', isDark ? 'dark' : 'light');
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
            alert('Please select an action and at least one session.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} session(s)?`)) return;
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

.status-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
.summary-card { background: var(--card-bg); padding: 16px; border-radius: 12px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); }
.summary-count { font-size: 2rem; font-weight: 700; }
.summary-label { font-size: 0.85rem; color: var(--text-light); margin-top: 4px; }

.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.admin-table th { background: var(--vanilla); padding: 10px 16px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }

.status-select { padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); font-size: 0.85rem; cursor: pointer; background: var(--input-bg); color: var(--text); transition: border-color 0.2s; }
.status-select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.status-select.pending { border-left: 4px solid #f1c40f; }
.status-select.confirmed { border-left: 4px solid #2ecc71; }
.status-select.completed { border-left: 4px solid #3498db; }
.status-select.cancelled { border-left: 4px solid #e74c3c; }

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