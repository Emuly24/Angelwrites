<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ============================================================
// 1. CSRF HELPER (if not already defined)
// ============================================================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ============================================================
// 2. HANDLE POST ACTIONS (Admin reply)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_reply'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $parent_id = (int)$_POST['parent_id'];
    $reply = trim($_POST['reply']);
    $target_type = $_POST['target_type']; // 'poem' or 'book_comment'
    $target_id = (int)$_POST['target_id'];
    $is_admin_reply = 1;

    if (!empty($reply) && $parent_id && $target_type && $target_id) {
        if ($target_type === 'poem') {
            // Insert into reviews as an admin reply
            $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, comment, parent_id, is_admin_reply, rating) VALUES ('poem', ?, ?, ?, ?, 1, 0)");
            $stmt->execute([$target_id, $_SESSION['user_id'], $reply, $parent_id]);
        } elseif ($target_type === 'book_comment') {
            // Insert into book_comments as a reply (if we have a reply column)
            // First, check if book_comments has a parent_id and is_admin_reply columns
            // We'll add them via ALTER TABLE if missing.
            $stmt = $db->prepare("INSERT INTO book_comments (book_id, user_id, paragraph_index, comment, parent_id, is_admin_reply) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$target_id, $_SESSION['user_id'], 0, $reply, $parent_id, 1]);
        }
        header('Location: ' . SITE_URL . '/admin/comments.php');
        exit;
    }
}

// ============================================================
// 3. HANDLE GET ACTIONS (Toggle private, delete)
// ============================================================
if (isset($_GET['toggle_private']) && is_numeric($_GET['toggle_private'])) {
    $id = (int)$_GET['toggle_private'];
    $stmt = $db->prepare("UPDATE reviews SET is_private = NOT is_private WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/comments.php');
    exit;
}
if (isset($_GET['delete_comment'])) {
    $id = (int)$_GET['delete_comment'];
    $type = isset($_GET['type']) ? $_GET['type'] : 'poem';
    if ($type === 'poem') {
        $stmt = $db->prepare("UPDATE reviews SET deleted_at = NOW() WHERE id = ?");
    } else {
        // For book comments, we just delete permanently (or soft delete if you have a deleted_at column)
        // We'll just delete permanently for simplicity.
        $stmt = $db->prepare("DELETE FROM book_comments WHERE id = ?");
    }
    $stmt->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/comments.php');
    exit;
}

// ============================================================
// 4. DETERMINE ACTIVE TAB
// ============================================================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'poems';

// ============================================================
// 5. FETCH POEM REVIEWS (if tab = poems)
// ============================================================
$poem_comments = [];
$poem_total = 0;
$poem_page = isset($_GET['poem_page']) ? (int)$_GET['poem_page'] : 1;
$poem_limit = 20;
$poem_offset = ($poem_page - 1) * $poem_limit;

if ($tab === 'poems') {
    $filter_poem = isset($_GET['filter_poem']) ? (int)$_GET['filter_poem'] : 0;
    $filter_private = isset($_GET['filter_private']) ? (int)$_GET['filter_private'] : -1; // -1 = all

    $sql = "
        SELECT r.*, u.name AS author_name, u.email AS author_email,
               p.title AS poem_title, p.view_count AS poem_views,
               (SELECT COUNT(*) FROM reactions WHERE target_type='comment' AND target_id=r.id) AS total_reactions
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN poems p ON r.target_type = 'poem' AND r.target_id = p.id
        WHERE r.deleted_at IS NULL AND r.target_type = 'poem'
    ";
    $params = [];
    if ($filter_poem > 0) {
        $sql .= " AND r.target_id = ?";
        $params[] = $filter_poem;
    }
    if ($filter_private >= 0) {
        $sql .= " AND r.is_private = ?";
        $params[] = $filter_private;
    }
    $sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $poem_limit;
    $params[] = $poem_offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $poem_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total for pagination
    $count_sql = str_replace("ORDER BY r.created_at DESC LIMIT ? OFFSET ?", "", $sql);
    $count_sql = preg_replace('/^SELECT r\..*?FROM/', 'SELECT COUNT(*) FROM', $count_sql);
    $stmt = $db->prepare($count_sql);
    $stmt->execute(array_slice($params, 0, -2));
    $poem_total = $stmt->fetchColumn();

    // Fetch poem list for dropdown
    $stmt = $db->query("SELECT id, title FROM poems ORDER BY title");
    $poems_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 6. FETCH BOOK COMMENTS (if tab = books)
// ============================================================
$book_comments = [];
$book_total = 0;
$book_page = isset($_GET['book_page']) ? (int)$_GET['book_page'] : 1;
$book_limit = 20;
$book_offset = ($book_page - 1) * $book_limit;

if ($tab === 'books') {
    $filter_book = isset($_GET['filter_book']) ? (int)$_GET['filter_book'] : 0;
    $filter_resolved = isset($_GET['filter_resolved']) ? (int)$_GET['filter_resolved'] : -1; // -1 = all

    $sql = "
        SELECT bc.*, u.name AS author_name, u.email AS author_email,
               b.title AS book_title, b.view_count AS book_views
        FROM book_comments bc
        JOIN users u ON bc.user_id = u.id
        LEFT JOIN books b ON bc.book_id = b.id
        WHERE 1=1
    ";
    $params = [];
    if ($filter_book > 0) {
        $sql .= " AND bc.book_id = ?";
        $params[] = $filter_book;
    }
    if ($filter_resolved >= 0) {
        $sql .= " AND bc.resolved = ?";
        $params[] = $filter_resolved;
    }
    $sql .= " ORDER BY bc.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $book_limit;
    $params[] = $book_offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $book_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total for pagination
    $count_sql = str_replace("ORDER BY bc.created_at DESC LIMIT ? OFFSET ?", "", $sql);
    $count_sql = preg_replace('/^SELECT bc\..*?FROM/', 'SELECT COUNT(*) FROM', $count_sql);
    $stmt = $db->prepare($count_sql);
    $stmt->execute(array_slice($params, 0, -2));
    $book_total = $stmt->fetchColumn();

    // Fetch books list for dropdown
    $stmt = $db->query("SELECT id, title FROM books ORDER BY title");
    $books_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 7. FETCH REPLIES FOR POEM COMMENTS
// ============================================================
foreach ($poem_comments as &$comment) {
    $stmt = $db->prepare("
        SELECT r.*, u.name AS author_name
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.parent_id = ? AND r.deleted_at IS NULL
        ORDER BY r.created_at
    ");
    $stmt->execute([$comment['id']]);
    $comment['replies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 8. FETCH REPLIES FOR BOOK COMMENTS (if we added parent_id)
// ============================================================
foreach ($book_comments as &$comment) {
    // Assuming we added parent_id and is_admin_reply to book_comments
    $stmt = $db->prepare("
        SELECT bc.*, u.name AS author_name
        FROM book_comments bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.parent_id = ? 
        ORDER BY bc.created_at
    ");
    $stmt->execute([$comment['id']]);
    $comment['replies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Comment Management';
?>
<?php require_once '../includes/header.php'; ?>

<style>
/* ===== ADMIN COMMENT MANAGEMENT CSS ===== */
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #fff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --bg: #F7F3ED; --card-bg: #fff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.06);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.10);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}
body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; }
.dashboard-page { padding: 32px 0 60px; }

/* Tabs */
.tab-nav { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 2px solid var(--border); padding-bottom: 8px; }
.tab-btn { padding: 8px 20px; border-radius: 20px; background: transparent; border: 1px solid var(--border); cursor: pointer; font-weight: 600; color: var(--text-light); transition: all 0.2s; }
.tab-btn.active { background: var(--rose); color: white; border-color: var(--rose); }
.tab-btn:hover:not(.active) { border-color: var(--rose); color: var(--rose); }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* Filters */
.comment-filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
.comment-filters select, .comment-filters input { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); color: var(--text); }
.comment-filters button { padding: 8px 16px; }

/* Comment List */
.comment-list { display: flex; flex-direction: column; gap: 12px; }
.comment-item { background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.comment-item:hover { box-shadow: var(--shadow-hover); }
.comment-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.comment-author { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.comment-author i { color: var(--rose); }
.comment-meta { font-size: 0.85rem; color: var(--text-light); }
.comment-poem { font-size: 0.9rem; font-weight: 500; }
.comment-poem a { color: var(--rose); text-decoration: none; }
.comment-poem a:hover { text-decoration: underline; }
.comment-views { font-size: 0.8rem; color: var(--text-light); margin-left: 8px; }
.comment-rating .filled { color: #f1c40f; }
.comment-rating .empty { color: #ddd; }
.comment-text { margin: 8px 0; line-height: 1.6; }
.comment-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.comment-actions .btn { padding: 4px 12px; font-size: 0.8rem; }
.reply-form-container { margin-top: 8px; padding: 12px; background: var(--vanilla); border-radius: 8px; display: none; }
.reply-form-container textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; resize: vertical; min-height: 60px; background: var(--card-bg); color: var(--text); }
.reply-form-container .btn { margin-top: 6px; }
.private-badge { background: #ffd700; color: #333; font-size: 0.6rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.admin-badge { background: var(--rose); color: white; font-size: 0.7rem; padding: 2px 10px; border-radius: 12px; font-weight: 600; }
.reaction-count { font-size: 0.8rem; color: var(--text-light); }
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: #fff; border-color: var(--rose); }
.empty-state { text-align: center; padding: 24px; color: var(--text-light); }
.empty-state i { display: block; font-size: 2.5rem; color: var(--rose); margin-bottom: 12px; opacity: 0.6; }
@media (max-width: 768px) {
    .comment-filters { flex-direction: column; align-items: stretch; }
}
</style>

<div class="dashboard-page">
    <div class="container">
        <h2><i class="fas fa-comments" style="color: var(--rose);"></i> Comment Management</h2>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $tab === 'poems' ? 'active' : ''; ?>" data-tab="poems">Poem Reviews</button>
            <button class="tab-btn <?php echo $tab === 'books' ? 'active' : ''; ?>" data-tab="books">Book Comments</button>
        </div>

        <!-- Poem Reviews Tab -->
        <div id="tab-poems" class="tab-content <?php echo $tab === 'poems' ? 'active' : ''; ?>">
            <div class="comment-filters">
                <form method="GET" action="">
                    <input type="hidden" name="tab" value="poems">
                    <select name="filter_poem">
                        <option value="0">All Poems</option>
                        <?php foreach ($poems_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($filter_poem ?? 0) == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_private">
                        <option value="-1">All Visibility</option>
                        <option value="0" <?php echo ($filter_private ?? -1) === 0 ? 'selected' : ''; ?>>Public</option>
                        <option value="1" <?php echo ($filter_private ?? -1) === 1 ? 'selected' : ''; ?>>Private</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="?tab=poems" class="btn btn-sm btn-secondary">Reset</a>
                </form>
            </div>

            <div class="comment-list">
                <?php if (count($poem_comments) > 0): ?>
                    <?php foreach ($poem_comments as $comment): ?>
                        <div class="comment-item" id="comment-<?php echo $comment['id']; ?>">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['author_name']); ?>
                                    <span class="comment-meta">(<?php echo htmlspecialchars($comment['author_email']); ?>)</span>
                                    <?php if ($comment['is_private']): ?>
                                        <span class="private-badge">🔒 Private</span>
                                    <?php endif; ?>
                                    <?php if ($comment['is_admin_reply']): ?>
                                        <span class="admin-badge">🛡️ Admin Reply</span>
                                    <?php endif; ?>
                                </span>
                                <span class="comment-meta"><?php echo date('M j, Y g:i a', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <div class="comment-poem">
                                Poem: <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $comment['target_id']; ?>">
                                    <?php echo htmlspecialchars($comment['poem_title']); ?>
                                </a>
                                <span class="comment-views">(<?php echo number_format($comment['poem_views'] ?? 0); ?> views)</span>
                            </div>
                            <?php if ($comment['rating'] > 0): ?>
                                <div class="comment-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= $comment['rating'] ? 'filled' : 'empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                            <div class="comment-text">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                            <div class="comment-actions">
                                <span class="reaction-count">Reactions: <?php echo $comment['total_reactions']; ?></span>
                                <button class="btn btn-sm btn-outline reply-toggle" data-comment-id="<?php echo $comment['id']; ?>" data-target-type="poem" data-target-id="<?php echo $comment['target_id']; ?>">Reply</button>
                                <?php if ($comment['is_private']): ?>
                                    <a href="?toggle_private=<?php echo $comment['id']; ?>" class="btn btn-sm btn-secondary">Make Public</a>
                                <?php else: ?>
                                    <a href="?toggle_private=<?php echo $comment['id']; ?>" class="btn btn-sm btn-secondary">Make Private</a>
                                <?php endif; ?>
                                <a href="?delete_comment=<?php echo $comment['id']; ?>&type=poem" class="btn btn-sm btn-danger" onclick="return confirm('Delete this comment?');">Delete</a>
                            </div>
                            <!-- Reply form (hidden) -->
                            <div class="reply-form-container" id="reply-form-<?php echo $comment['id']; ?>">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="parent_id" value="<?php echo $comment['id']; ?>">
                                    <input type="hidden" name="target_type" value="poem">
                                    <input type="hidden" name="target_id" value="<?php echo $comment['target_id']; ?>">
                                    <textarea name="reply" rows="2" placeholder="Write your reply..." required></textarea>
                                    <button type="submit" name="admin_reply" class="btn btn-sm btn-primary">Post Reply</button>
                                    <button type="button" class="btn btn-sm btn-secondary cancel-reply">Cancel</button>
                                </form>
                            </div>
                            <!-- Show existing replies -->
                            <?php if (!empty($comment['replies'])): ?>
                                <div style="margin-left:20px; margin-top:8px; border-left:2px solid var(--rose); padding-left:12px;">
                                    <?php foreach ($comment['replies'] as $reply): ?>
                                        <div class="comment-item" style="padding:8px; margin-bottom:4px;">
                                            <span class="comment-author">
                                                <i class="fas fa-user-circle"></i>
                                                <?php echo htmlspecialchars($reply['author_name']); ?>
                                                <?php if ($reply['is_admin_reply']): ?><span class="admin-badge">🛡️ Admin</span><?php endif; ?>
                                            </span>
                                            <div class="comment-text"><?php echo nl2br(htmlspecialchars($reply['comment'])); ?></div>
                                            <span class="comment-meta"><?php echo date('M j, Y g:i a', strtotime($reply['created_at'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comment-slash"></i>
                        <p>No poem reviews found matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination for poems -->
            <?php if ($poem_total > $poem_limit): ?>
                <div class="pagination">
                    <?php if ($poem_page > 1): ?>
                        <a href="?tab=poems&poem_page=<?php echo $poem_page - 1; ?>&filter_poem=<?php echo $filter_poem ?? 0; ?>&filter_private=<?php echo $filter_private ?? -1; ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= ceil($poem_total / $poem_limit); $i++): ?>
                        <a href="?tab=poems&poem_page=<?php echo $i; ?>&filter_poem=<?php echo $filter_poem ?? 0; ?>&filter_private=<?php echo $filter_private ?? -1; ?>" class="page-link <?php echo $i === $poem_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($poem_page < ceil($poem_total / $poem_limit)): ?>
                        <a href="?tab=poems&poem_page=<?php echo $poem_page + 1; ?>&filter_poem=<?php echo $filter_poem ?? 0; ?>&filter_private=<?php echo $filter_private ?? -1; ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Book Comments Tab -->
        <div id="tab-books" class="tab-content <?php echo $tab === 'books' ? 'active' : ''; ?>">
            <div class="comment-filters">
                <form method="GET" action="">
                    <input type="hidden" name="tab" value="books">
                    <select name="filter_book">
                        <option value="0">All Books</option>
                        <?php foreach ($books_list as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($filter_book ?? 0) == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_resolved">
                        <option value="-1">All Status</option>
                        <option value="0" <?php echo ($filter_resolved ?? -1) === 0 ? 'selected' : ''; ?>>Unresolved</option>
                        <option value="1" <?php echo ($filter_resolved ?? -1) === 1 ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="?tab=books" class="btn btn-sm btn-secondary">Reset</a>
                </form>
            </div>

            <div class="comment-list">
                <?php if (count($book_comments) > 0): ?>
                    <?php foreach ($book_comments as $comment): ?>
                        <div class="comment-item" id="bcomment-<?php echo $comment['id']; ?>">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['author_name']); ?>
                                    <span class="comment-meta">(<?php echo htmlspecialchars($comment['author_email']); ?>)</span>
                                    <?php if ($comment['is_admin_reply'] ?? false): ?>
                                        <span class="admin-badge">🛡️ Admin Reply</span>
                                    <?php endif; ?>
                                    <?php if ($comment['resolved']): ?>
                                        <span class="private-badge">✅ Resolved</span>
                                    <?php endif; ?>
                                </span>
                                <span class="comment-meta"><?php echo date('M j, Y g:i a', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <div class="comment-poem">
                                Book: <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo $comment['book_id']; ?>">
                                    <?php echo htmlspecialchars($comment['book_title']); ?>
                                </a>
                                <span class="comment-views">(<?php echo number_format($comment['book_views'] ?? 0); ?> views)</span>
                                <span style="font-size:0.8rem; color:var(--text-light); margin-left:8px;">Paragraph <?php echo $comment['paragraph_index']; ?></span>
                            </div>
                            <div class="comment-text">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                            <div class="comment-actions">
                                <button class="btn btn-sm btn-outline reply-toggle" data-comment-id="<?php echo $comment['id']; ?>" data-target-type="book_comment" data-target-id="<?php echo $comment['book_id']; ?>">Reply</button>
                                <?php if (!$comment['resolved']): ?>
                                    <a href="?resolve_comment=<?php echo $comment['id']; ?>&type=book" class="btn btn-sm btn-success" onclick="return confirm('Mark as resolved?');">✓ Resolve</a>
                                <?php endif; ?>
                                <a href="?delete_comment=<?php echo $comment['id']; ?>&type=book" class="btn btn-sm btn-danger" onclick="return confirm('Delete this comment?');">Delete</a>
                            </div>
                            <!-- Reply form (hidden) -->
                            <div class="reply-form-container" id="reply-form-<?php echo $comment['id']; ?>">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="parent_id" value="<?php echo $comment['id']; ?>">
                                    <input type="hidden" name="target_type" value="book_comment">
                                    <input type="hidden" name="target_id" value="<?php echo $comment['book_id']; ?>">
                                    <textarea name="reply" rows="2" placeholder="Write your reply..." required></textarea>
                                    <button type="submit" name="admin_reply" class="btn btn-sm btn-primary">Post Reply</button>
                                    <button type="button" class="btn btn-sm btn-secondary cancel-reply">Cancel</button>
                                </form>
                            </div>
                            <!-- Show existing replies (if we have them) -->
                            <?php if (!empty($comment['replies'])): ?>
                                <div style="margin-left:20px; margin-top:8px; border-left:2px solid var(--rose); padding-left:12px;">
                                    <?php foreach ($comment['replies'] as $reply): ?>
                                        <div class="comment-item" style="padding:8px; margin-bottom:4px;">
                                            <span class="comment-author">
                                                <i class="fas fa-user-circle"></i>
                                                <?php echo htmlspecialchars($reply['author_name']); ?>
                                                <?php if ($reply['is_admin_reply'] ?? false): ?><span class="admin-badge">🛡️ Admin</span><?php endif; ?>
                                            </span>
                                            <div class="comment-text"><?php echo nl2br(htmlspecialchars($reply['comment'])); ?></div>
                                            <span class="comment-meta"><?php echo date('M j, Y g:i a', strtotime($reply['created_at'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comment-slash"></i>
                        <p>No book comments found matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination for books -->
            <?php if ($book_total > $book_limit): ?>
                <div class="pagination">
                    <?php if ($book_page > 1): ?>
                        <a href="?tab=books&book_page=<?php echo $book_page - 1; ?>&filter_book=<?php echo $filter_book ?? 0; ?>&filter_resolved=<?php echo $filter_resolved ?? -1; ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= ceil($book_total / $book_limit); $i++): ?>
                        <a href="?tab=books&book_page=<?php echo $i; ?>&filter_book=<?php echo $filter_book ?? 0; ?>&filter_resolved=<?php echo $filter_resolved ?? -1; ?>" class="page-link <?php echo $i === $book_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($book_page < ceil($book_total / $book_limit)): ?>
                        <a href="?tab=books&book_page=<?php echo $book_page + 1; ?>&filter_book=<?php echo $filter_book ?? 0; ?>&filter_resolved=<?php echo $filter_resolved ?? -1; ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tab = this.dataset.tab;
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('tab-' + tab).classList.add('active');
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            url.searchParams.delete('poem_page');
            url.searchParams.delete('book_page');
            window.history.pushState({}, '', url);
            location.reload(); // reload to apply filters
        });
    });

    // Reply toggle
    document.querySelectorAll('.reply-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.commentId;
            const form = document.getElementById('reply-form-' + id);
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                form.querySelector('textarea').focus();
            } else {
                form.style.display = 'none';
            }
        });
    });
    document.querySelectorAll('.cancel-reply').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.reply-form-container').style.display = 'none';
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>