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
    $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Blog post deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_blog.php');
    exit;
}

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM blog_posts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts deleted.';
    } elseif (!empty($ids) && $action === 'publish') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE blog_posts SET status = 'published' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts published.';
    } elseif (!empty($ids) && $action === 'draft') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE blog_posts SET status = 'draft' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts moved to draft.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_blog.php');
    exit;
}

// ===== FETCH TOTAL POSTS =====
$count_sql = "SELECT COUNT(*) FROM blog_posts";
$count_params = [];
if ($search) {
    $count_sql .= " WHERE title LIKE ? OR content LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_posts = $stmt->fetchColumn();
$total_pages = ceil($total_posts / $limit);

// ===== FETCH POSTS =====
$sql = "SELECT * FROM blog_posts";
$params = [];
if ($search) {
    $sql .= " WHERE title LIKE ? OR content LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Blog';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Blog</h1>
            <div class="admin-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Post
                </a>
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
                <input type="text" name="search" placeholder="Search posts by title or content..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Posts (<?php echo $total_posts; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="publish">Publish Selected</option>
                        <option value="draft">Move to Draft</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($posts) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Views</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posts as $post): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $post['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                                <br><small><?php echo date('M j, Y', strtotime($post['created_at'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($post['category'] ?? 'General'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $post['status']; ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($post['views'] ?? 0); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($post['created_at'])); ?></td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog&id=<?php echo $post['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?delete=<?php echo $post['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this post?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                                <?php if ($post['status'] === 'published'): ?>
                                                    <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                        <i class="fas fa-eye"></i>
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
                    <p class="no-items">No blog posts yet. <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog">Create the first post</a>.</p>
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
    const currentTheme = localStorage.getItem('blogManagerTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('blogManagerTheme', isDark ? 'dark' : 'light');
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
            alert('Please select an action and at least one post.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} post(s)?`)) return;
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

.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }
.admin-table tbody tr:last-child td { border-bottom: none; }
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.draft { background: #f1c40f; color: #fff; }
.status-badge.published { background: #27ae60; color: #fff; }
.status-badge.archived { background: #e74c3c; color: #fff; }

.actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }

.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

@media (max-width: 480px) {
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
    .admin-table th, .admin-table td { padding: 8px 10px; font-size: 0.85rem; }
}
</style>

<?php require_once '../includes/footer.php'; ?>