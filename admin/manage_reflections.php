<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// RF2: every change is a CSRF-checked POST (delete was a GET link), the bulk actions work (the form
// posted to nothing), and a published reflection can be sent to subscribers from here.
require_once __DIR__ . '/../includes/newsletter.php';
$status_f = in_array($_GET['status'] ?? '', ['published', 'draft'], true) ? $_GET['status'] : '';
if (isset($_SESSION['aw_refl_flash'])) { [$t, $m] = $_SESSION['aw_refl_flash']; unset($_SESSION['aw_refl_flash']); if ($t === 'error') $error = $m; else $success = $m; }
function aw_refl_back($type, $msg) {
    $_SESSION['aw_refl_flash'] = [$type, $msg];
    $q = array_intersect_key($_POST, array_flip(['search', 'status', 'page']));
    header('Location: ' . SITE_URL . '/admin/manage_reflections.php' . ($q ? '?' . http_build_query(array_filter($q)) : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    aw_csrf_require();
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['selected_ids'] ?? $_POST['row_id'] ?? '')))));
    $act = $_POST['bulk_action'] ?? $_POST['row_action'] ?? '';
    if (!$ids) aw_refl_back('error', 'Select at least one reflection.');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $scope = " AND category = 'Christian Reflections'";
    switch ($act) {
        case 'delete':
            $db->prepare("DELETE FROM reflection_comments WHERE reflection_id IN ($in)")->execute($ids);
            $db->prepare("DELETE FROM reactions WHERE target_type = 'reflection' AND target_id IN ($in)")->execute($ids);
            $db->prepare("DELETE FROM blog_posts WHERE id IN ($in)$scope")->execute($ids);
            aw_refl_back('success', count($ids) . ' reflection(s) deleted.');
        case 'publish':
            $db->prepare("UPDATE blog_posts SET status = 'published', published_at = COALESCE(published_at, CURRENT_TIMESTAMP) WHERE id IN ($in)$scope")->execute($ids);
            aw_refl_back('success', count($ids) . ' reflection(s) published.');
        case 'draft':
            $db->prepare("UPDATE blog_posts SET status = 'draft' WHERE id IN ($in)$scope")->execute($ids);
            aw_refl_back('success', count($ids) . ' reflection(s) moved to drafts (hidden from readers).');
        case 'feature':
        case 'unfeature':
            $db->prepare("UPDATE blog_posts SET featured = ? WHERE id IN ($in)$scope")->execute(array_merge([$act === 'feature' ? 1 : 0], $ids));
            aw_refl_back('success', count($ids) . ' reflection(s) ' . ($act === 'feature' ? 'featured at the top of the Reflections page.' : 'no longer featured.'));
        case 'newsletter':
            $st = $db->prepare("SELECT * FROM blog_posts WHERE id = ?$scope");
            $st->execute([$ids[0]]);
            $post = $st->fetch(PDO::FETCH_ASSOC);
            if (!$post || $post['status'] !== 'published') aw_refl_back('error', 'Only a published reflection can be sent to subscribers.');
            $qid = aw_nl_announce_post($db, $post, 'Read the reflection');
            if (!$qid) aw_refl_back('error', 'There are no active subscribers yet.');
            header('Location: ' . SITE_URL . '/admin/manage_newsletter.php?tab=queue&sending=' . $qid);
            exit;
    }
    aw_refl_back('error', 'Choose an action.');
}

// ===== FETCH REFLECTIONS =====
$sql = "SELECT b.*, (SELECT COUNT(*) FROM reflection_comments c WHERE c.reflection_id = b.id) AS comments FROM blog_posts b WHERE category = 'Christian Reflections'";
$params = [];
if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR content LIKE ? OR tags LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_f !== '') { $sql .= " AND status = ?"; $params[] = $status_f; }
$counts = $db->query("SELECT SUM(status = 'published') p, SUM(status != 'published') d, SUM(featured = 1 AND status = 'published') f FROM blog_posts WHERE category = 'Christian Reflections'")->fetch(PDO::FETCH_ASSOC);
// P10: all reflections were listed on one page; 20 per page now.
require_once __DIR__ . '/../includes/pagination.php';
[$page, $per_page] = aw_page(20);
$cst = $db->prepare("SELECT COUNT(*) FROM (" . $sql . ")");
$cst->execute($params);
$reflections_total = (int)$cst->fetchColumn();
[$page, $offset] = aw_clamp($reflections_total, $page, $per_page);
$sql .= " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Reflections';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Christian Reflections</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/reflection_editor.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Reflection
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

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search by title, content or tag..." value="<?php echo htmlspecialchars($search); ?>" aria-label="Search reflections">
                <?php if ($status_f !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>"><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
            <nav class="status-tabs" aria-label="Filter by status">
                <a href="?<?php echo htmlspecialchars(http_build_query(array_filter(['search' => $search]))); ?>" class="<?php echo $status_f === '' ? 'on' : ''; ?>">All (<?php echo (int)$counts['p'] + (int)$counts['d']; ?>)</a>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_filter(['search' => $search, 'status' => 'published']))); ?>" class="<?php echo $status_f === 'published' ? 'on' : ''; ?>">Published (<?php echo (int)$counts['p']; ?>)</a>
                <a href="?<?php echo htmlspecialchars(http_build_query(array_filter(['search' => $search, 'status' => 'draft']))); ?>" class="<?php echo $status_f === 'draft' ? 'on' : ''; ?>">Drafts (<?php echo (int)$counts['d']; ?>)</a>
                <span class="field-hint">★ <?php echo (int)$counts['f']; ?> featured</span>
            </nav>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Reflections (<?php echo $reflections_total; ?>)</h2>
                <div class="card-header-actions">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">With selected…</option>
                        <option value="publish">Publish</option>
                        <option value="draft">Move to drafts</option>
                        <option value="feature">Feature on the Reflections page</option>
                        <option value="unfeature">Stop featuring</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if ($reflections_total > 0): ?>
                    <form method="POST" id="bulkForm">
                        <?php echo aw_csrf_field(); ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Engagement</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reflections as $post): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $post['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo !empty($post['featured']) ? '<span title="Featured" aria-label="Featured" style="color:var(--rose-dark,#c08a8b);">★</span> ' : ''; ?><?php echo htmlspecialchars($post['title']); ?></strong>
                                                <?php if (!empty($post['tags'])): ?><br><small class="field-hint"><?php echo htmlspecialchars($post['tags']); ?></small><?php endif; ?>
                                                <br><small><?php echo date('M j, Y', strtotime($post['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $post['status']; ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td style="white-space:nowrap;font-size:.85rem;">👁 <?php echo number_format((int)($post['views'] ?? 0)); ?> · 💬 <?php echo (int)$post['comments']; ?> · ❤️ <?php echo (int)($post['likes'] ?? 0) + (int)($post['loves'] ?? 0) + (int)($post['prays'] ?? 0); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($post['created_at'])); ?></td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/admin/reflection_editor.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-danger" aria-label="Delete" onclick="rowAction(<?php echo (int)$post['id']; ?>, 'delete', 'Delete this reflection and its comments?')"><i class="fas fa-trash"></i></button>
                                                <?php if ($post['status'] === 'published'): ?>
                                                    <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo urlencode((string)$post['slug']); ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener" aria-label="View"><i class="fas fa-eye"></i></a>
                                                    <button type="button" class="btn btn-sm btn-outline" title="Send to newsletter subscribers" aria-label="Send to subscribers" onclick="rowAction(<?php echo (int)$post['id']; ?>, 'newsletter', 'Send an announcement of this reflection to all active subscribers?')"><i class="fas fa-paper-plane"></i></button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-success" title="Publish" aria-label="Publish" onclick="rowAction(<?php echo (int)$post['id']; ?>, 'publish', '')"><i class="fas fa-check"></i></button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                <?php echo aw_pager($reflections_total, $page, $per_page); // P10 ?>
                    </form>
                <?php else: ?>
                    <p class="no-items">No reflections yet. Click "New Reflection" to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="rowForm" hidden><?php echo aw_csrf_field(); ?><input type="hidden" name="row_id"><input type="hidden" name="row_action"><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>"></form>
<!-- Bulk actions JavaScript (same as in manage_poems.php) -->
<script>
function rowAction(id, action, question) {
    if (question && !confirm(question)) return;
    const f = document.getElementById('rowForm'); f.row_id.value = id; f.row_action.value = action; f.submit();
}
document.addEventListener('DOMContentLoaded', function() {
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
            alert('Please select an action and at least one reflection.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} reflection(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = ids.join(',');
        document.getElementById('bulkForm').submit();
    });
});
</script>

<style>
/* Same styles as manage_poems.php – adapt as needed */
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
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.draft { background: #c9965a; color: #fff; }
.status-badge.published { background: #4f8a63; color: #fff; }
.status-badge.archived { background: #b4505a; color: #fff; }
.status-tabs { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:10px; }
.status-tabs a { padding:6px 14px; border-radius:999px; border:1px solid var(--border); text-decoration:none; color:var(--text); font-size:.85rem; }
.status-tabs a.on { background:var(--rose); border-color:var(--rose); color:#fff; }
.btn-success { background:#4f8a63; color:#fff; }

.actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }
</style>

<?php require_once '../includes/footer.php'; ?>