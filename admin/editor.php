<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$type = isset($_GET['type']) ? $_GET['type'] : 'poem';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';
$existing_content = null;
$title = '';
$intro = '';
$content = '';
$category = '';
$tags = '';

// Fetch existing content
if ($id > 0 && $type === 'poem') {
    $stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing_content) {
        $title = $existing_content['title'];
        $intro = $existing_content['intro'];
        $content = $existing_content['content'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $action = $_POST['action'] ?? 'save';

    if (empty($title) || empty($content)) {
        $error = 'Title and content are required.';
    } else {
        if ($id > 0) {
            // Update existing
            $stmt = $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ? WHERE id = ?");
            $stmt->execute([$title, $intro, $content, $id]);
            $success = 'Poem updated successfully!';
        } else {
            // Insert new
            $stmt = $db->prepare("INSERT INTO poems (title, intro, content) VALUES (?, ?, ?)");
            $stmt->execute([$title, $intro, $content]);
            $id = $db->lastInsertId();
            $success = 'Poem created successfully!';
        }
        if ($action === 'save_and_continue') {
            header('Location: ' . SITE_URL . '/admin/editor.php?type=' . $type . '&id=' . $id);
            exit;
        } else {
            header('Location: ' . SITE_URL . '/admin/manage_poems.php');
            exit;
        }
    }
}

$pageTitle = 'Poem Editor';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-editor">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo $id > 0 ? 'Edit Poem' : 'Add New Poem'; ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-outline">
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

        <form method="POST" id="editorForm" class="admin-form">
            <input type="hidden" name="action" id="formAction" value="save">

            <div class="card">
                <div class="card-body">
                    <div class="form-group">
                        <label for="title">Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="intro">Purpose / Introduction</label>
                        <textarea id="intro" name="intro" rows="3"><?php echo htmlspecialchars($intro); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="content">Content <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="20"><?php echo htmlspecialchars($content); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Poem</button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save_and_continue'; document.getElementById('editorForm').submit();">
                            Save & Continue
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TinyMCE Editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#editor',
        height: 600,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
        content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
        forced_root_block: 'p',
        init_instance_callback: function(editor) {
            const existingContent = <?php echo json_encode($content); ?>;
            if (existingContent) {
                editor.setContent(existingContent);
            }
        },
        setup: function(editor) {
            editor.addShortcut('Ctrl+S', 'Save', function() {
                document.querySelector('form').submit();
            });
        }
    });
</script>

<style>
    .admin-editor { padding: 32px 0 60px; }
    .admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
    .admin-header h1 { font-size: 2rem; margin: 0; }
    .admin-actions { display: flex; gap: 12px; }
    .admin-form .form-group { margin-bottom: 16px; }
    .admin-form label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
    .admin-form input[type="text"], .admin-form textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); resize: vertical; }
    .admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
    .admin-form textarea { min-height: 60px; }
    .required { color: #dc2626; }
    .form-actions { display: flex; gap: 12px; margin-top: 16px; }
    .form-actions .btn { min-width: 120px; justify-content: center; }
    .card { margin-bottom: 24px; }
    .card-body { padding: 20px; }
</style>

<?php require_once '../includes/footer.php'; ?>