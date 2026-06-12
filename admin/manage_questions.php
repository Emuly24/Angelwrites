<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("DELETE FROM answers WHERE question_id = ?");
        $stmt->execute([$id]);
        $stmt = $db->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->execute([$id]);
        $db->commit();
        $success = 'Question deleted successfully.';
    } catch (PDOException $e) {
        $db->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
    header('Location: ' . SITE_URL . '/admin/manage_questions.php');
    exit;
}

// ===== HANDLE MARK AS ANSWERED =====
if (isset($_GET['answer'])) {
    $id = (int)$_GET['answer'];
    $stmt = $db->prepare("UPDATE questions SET is_answered = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Question marked as answered.';
    header('Location: ' . SITE_URL . '/admin/manage_questions.php');
    exit;
}

// ===== HANDLE MARK AS UNANSWERED =====
if (isset($_GET['unanswer'])) {
    $id = (int)$_GET['unanswer'];
    $stmt = $db->prepare("UPDATE questions SET is_answered = 0 WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Question marked as unanswered.';
    header('Location: ' . SITE_URL . '/admin/manage_questions.php');
    exit;
}

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids)) {
        if ($action === 'delete') {
            try {
                $db->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM answers WHERE question_id IN ($placeholders)");
                $stmt->execute($ids);
                $stmt = $db->prepare("DELETE FROM questions WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $db->commit();
                $success = count($ids) . ' questions deleted.';
            } catch (PDOException $e) {
                $db->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        } elseif ($action === 'answer') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE questions SET is_answered = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . ' questions marked as answered.';
        } elseif ($action === 'unanswer') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE questions SET is_answered = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $success = count($ids) . ' questions marked as unanswered.';
        }
    }
    header('Location: ' . SITE_URL . '/admin/manage_questions.php');
    exit;
}

// ===== FETCH TOTAL QUESTIONS =====
$count_sql = "
    SELECT COUNT(*) 
    FROM questions q
    JOIN users u ON q.user_id = u.id
    WHERE 1=1
";
$count_params = [];
if ($search) {
    $count_sql .= " AND (q.title LIKE ? OR q.body LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
if ($status_filter === 'answered') {
    $count_sql .= " AND q.is_answered = 1";
} elseif ($status_filter === 'unanswered') {
    $count_sql .= " AND q.is_answered = 0";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_questions = $stmt->fetchColumn();
$total_pages = ceil($total_questions / $limit);

// ===== FETCH QUESTIONS =====
$sql = "
    SELECT q.*, u.name AS author_name, 
           (SELECT COUNT(*) FROM answers WHERE question_id = q.id) AS answer_count
    FROM questions q
    JOIN users u ON q.user_id = u.id
    WHERE 1=1
";
$params = [];
if ($search) {
    $sql .= " AND (q.title LIKE ? OR q.body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter === 'answered') {
    $sql .= " AND q.is_answered = 1";
} elseif ($status_filter === 'unanswered') {
    $sql .= " AND q.is_answered = 0";
}
$sql .= " ORDER BY q.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Questions';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Community Q&A Management</h1>
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

        <!-- Search & Filter -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search questions by title or body..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status">
                    <option value="">All</option>
                    <option value="answered" <?php echo $status_filter === 'answered' ? 'selected' : ''; ?>>Answered</option>
                    <option value="unanswered" <?php echo $status_filter === 'unanswered' ? 'selected' : ''; ?>>Unanswered</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
        </div>

        <!-- Questions Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Questions (<?php echo $total_questions; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="answer">Mark as Answered</option>
                        <option value="unanswer">Mark as Unanswered</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($questions) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Question</th>
                                        <th>Author</th>
                                        <th>Answers</th>
                                        <th>Status</th>
                                        <th>Views</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($questions as $q): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $q['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($q['title']); ?></strong>
                                                <br><small><?php echo htmlspecialchars(substr($q['body'], 0, 80)); ?>...</small>
                                            </td>
                                            <td><?php echo htmlspecialchars($q['author_name']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $q['answer_count'] > 0 ? 'badge-answered' : 'badge-unanswered'; ?>">
                                                    <?php echo $q['answer_count']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $q['is_answered'] ? 'answered' : 'unanswered'; ?>">
                                                    <?php echo $q['is_answered'] ? 'Answered' : 'Unanswered'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($q['views'] ?? 0); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($q['created_at'])); ?></td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (!$q['is_answered']): ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php?answer=<?php echo $q['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark this question as answered?');">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php?unanswer=<?php echo $q['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Mark this question as unanswered?');">
                                                        <i class="fas fa-undo"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php?delete=<?php echo $q['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this question and all its answers?');">
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
                    <p class="no-items">No questions found.</p>
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
    const currentTheme = localStorage.getItem('questionsTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('questionsTheme', isDark ? 'dark' : 'light');
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
            alert('Please select an action and at least one question.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} question(s)?`)) return;
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
.search-form select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.admin-table th { background: var(--vanilla); padding: 10px 16px; text-align: left; font-weight: 600; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }

.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.badge-answered { background: #2ecc71; color: white; }
.badge-unanswered { background: #e67e22; color: white; }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.answered { background: #2ecc71; color: white; }
.status-badge.unanswered { background: #e74c3c; color: white; }

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