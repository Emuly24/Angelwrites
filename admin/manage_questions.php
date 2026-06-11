<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Delete answers first (foreign key constraint)
        $stmt = $db->prepare("DELETE FROM answers WHERE question_id = ?");
        $stmt->execute([$id]);
        
        // Delete the question
        $stmt = $db->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->execute([$id]);
        
        $db->commit();
        $success = 'Question deleted successfully.';
        header('Location: ' . SITE_URL . '/admin/manage_questions.php');
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
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

// ===== FETCH QUESTIONS WITH ANSWERS COUNT =====
$sql = "
    SELECT q.*, u.name AS author_name, 
           (SELECT COUNT(*) FROM answers WHERE question_id = q.id) AS answer_count
    FROM questions q
    JOIN users u ON q.user_id = u.id
";
$params = [];

if (!empty($search)) {
    $sql .= " WHERE q.title LIKE ? OR q.body LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter === 'answered') {
    $sql .= (empty($search) ? " WHERE" : " AND") . " q.is_answered = 1";
} elseif ($status_filter === 'unanswered') {
    $sql .= (empty($search) ? " WHERE" : " AND") . " q.is_answered = 0";
}

$sql .= " ORDER BY q.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== SEND EMAIL NOTIFICATION FOR NEW QUESTIONS (Optional) =====

function notifyAdminNewQuestion($question_id, $title, $author_name) {
    $admin_email = 'angelwrites@zohomail.com';
    $subject = '❓ New Community Question: ' . $title;
    $body = "<h2>New Question Posted</h2>";
    $body .= "<p><strong>Title:</strong> " . $title . "</p>";
    $body .= "<p><strong>Author:</strong> " . $author_name . "</p>";
    $body .= "<p><a href='" . SITE_URL . "/community.php?id=" . $question_id . "'>View Question</a></p>";
    return sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
}

$pageTitle = 'Manage Questions';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Community Q&A Management</h1>
            <div class="admin-actions">
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
                <h2>All Questions (<?php echo count($questions); ?>)</h2>
            </div>
            <div class="card-body">
                <!-- Search & Filter -->
                <form method="GET" class="search-form">
                    <input type="text" name="search" placeholder="Search questions by title or body..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="">All</option>
                        <option value="answered" <?php echo $status_filter === 'answered' ? 'selected' : ''; ?>>Answered</option>
                        <option value="unanswered" <?php echo $status_filter === 'unanswered' ? 'selected' : ''; ?>>Unanswered</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if (!empty($search) || !empty($status_filter)): ?>
                        <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-outline btn-sm">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (count($questions) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
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
                                        <td>
                                            <strong><?php echo htmlspecialchars($q['title']); ?></strong>
                                            <br><small><?php echo htmlspecialchars(substr($q['body'], 0, 80)); ?>...</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($q['author_name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $q['answer_count'] > 0 ? 'free' : 'sale'; ?>">
                                                <?php echo $q['answer_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $q['is_answered'] ? 'published' : 'draft'; ?>">
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
                <?php else: ?>
                    <p class="no-items">No questions found. <a href="<?php echo SITE_URL; ?>/community.php">View community</a></p>
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

.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.badge.free { background: #2ecc71; color: white; }
.badge.sale { background: #e67e22; color: white; }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.draft { background: #f1c40f; color: #fff; }
.status-badge.published { background: #27ae60; color: #fff; }

.search-form { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.search-form input[type="text"] { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.search-form select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }
</style>

<?php require_once '../includes/footer.php'; ?>