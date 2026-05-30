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
    $success = 'Blog post deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_blog.php');
    exit;
}

// ===== HANDLE EDIT FETCH =====
$edit_post = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $edit_post = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ===== HANDLE FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
    $title = trim($_POST['title']);
    $slug = trim($_POST['slug']) ?: strtolower(str_replace(' ', '-', $title));
    $content = trim($_POST['content']);
    $excerpt = trim($_POST['excerpt']);
    $category = trim($_POST['category']) ?: 'Christian Reflections';
    $status = $_POST['status'] ?? 'draft';

    if (empty($title)) {
        $error = 'Title is required.';
    } elseif (empty($content)) {
        $error = 'Content is required.';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE blog_posts SET title = ?, slug = ?, content = ?, excerpt = ?, category = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$title, $slug, $content, $excerpt, $category, $status, $id]);
            $success = 'Blog post updated successfully.';
        } else {
            $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, category, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $slug, $content, $excerpt, $category, $status]);
            $success = 'Blog post added successfully.';
        }
        header('Location: ' . SITE_URL . '/admin/manage_blog.php');
        exit;
    }
}

// ===== FETCH ALL POSTS =====
$stmt = $db->query("SELECT * FROM blog_posts ORDER BY created_at DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Blog';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Blog</h1>
            <div class="admin-actions">
                <button id="showAddForm" class="btn btn-primary"><i class="fa-pen-fancy"></i> New Post</button>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="post-form-container" id="postFormContainer" style="display: <?php echo ($edit_post || isset($_GET['edit'])) ? 'block' : 'none'; ?>;">
            <div class="card">
                <div class="card-header">
                    <h2><?php echo $edit_post ? 'Edit Post' : 'Add New Post'; ?></h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="post-form">
                        <input type="hidden" name="post_id" value="<?php echo $edit_post['id'] ?? 0; ?>">
                        <div class="form-group">
                            <label for="title">Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($edit_post['title'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="slug">Slug (URL-friendly)</label>
                            <input type="text" id="slug" name="slug" value="<?php echo htmlspecialchars($edit_post['slug'] ?? ''); ?>" placeholder="leave-empty-to-auto-generate">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($edit_post['category'] ?? 'Christian Reflections'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="excerpt">Excerpt (summary)</label>
                            <textarea id="excerpt" name="excerpt" rows="2"><?php echo htmlspecialchars($edit_post['excerpt'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="content">Content <span class="required">*</span></label>
                            <textarea id="content" name="content" rows="8" required><?php echo htmlspecialchars($edit_post['content'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="draft" <?php echo ($edit_post['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo ($edit_post['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                            <button type="button" class="btn btn-outline" id="cancelForm">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Posts (<?php echo count($posts); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($posts) > 0): ?>
                    <div class="posts-table-wrapper">
                        <table class="posts-table">
                            <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Views</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($posts as $post): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($post['title']); ?></strong><br><small><?php echo date('M j, Y', strtotime($post['created_at'])); ?></small></td>
                                        <td><?php echo htmlspecialchars($post['category']); ?></td>
                                        <td><span class="status-badge <?php echo $post['status']; ?>"><?php echo $post['status']; ?></span></td>
                                        <td><?php echo number_format($post['views'] ?? 0); ?></td>
                                        <td class="actions">
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?edit=<?php echo $post['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?delete=<?php echo $post['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this post?');"><i class="fas fa-trash"></i></a>
                                            <?php if ($post['status'] === 'published'): ?>
                                                <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-sm btn-primary" target="_blank"><i class="fas fa-eye"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No blog posts yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const showBtn = document.getElementById('showAddForm');
    const container = document.getElementById('postFormContainer');
    const cancelBtn = document.getElementById('cancelForm');

    function toggleForm(show) {
        container.style.display = show ? 'block' : 'none';
        if (show && !window.location.search.includes('edit')) {
            container.scrollIntoView({ behavior: 'smooth' });
        }
    }

    if (showBtn) showBtn.addEventListener('click', function() {
        if (window.location.search.includes('edit')) window.location.href = '<?php echo SITE_URL; ?>/admin/manage_blog.php';
        else toggleForm(true);
    });
    if (cancelBtn) cancelBtn.addEventListener('click', function() { toggleForm(false); });
    if (window.location.search.includes('edit')) toggleForm(true);
});
</script>

<style>
.posts-table-wrapper { overflow-x: auto; }
.posts-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
.posts-table th { background: var(--vanilla); text-align: left; padding: 12px 16px; font-weight: 600; border-bottom: 2px solid var(--border); }
.posts-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.status-badge.draft { color: #f39c12; background: rgba(243,156,18,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
.status-badge.published { color: #27ae60; background: rgba(39,174,96,0.1); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; }
</style>

<?php require_once '../includes/footer.php'; ?>