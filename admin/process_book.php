<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch book
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header('Location: ' . SITE_URL . '/admin/manage_books.php');
    exit;
}

// Check if book already has processed content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$existing_content = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_processed'])) {
    $content_html = trim($_POST['content_html']);
    $toc_json = trim($_POST['toc_json']);
    $is_angella_book = isset($_POST['is_angella_book']) ? 1 : 0;

    if ($existing_content) {
        $stmt = $db->prepare("
            UPDATE book_content SET 
                content_html = ?, toc_json = ?, is_angella_book = ?, is_processed = 1, updated_at = CURRENT_TIMESTAMP
            WHERE book_id = ?
        ");
        $stmt->execute([$content_html, $toc_json, $is_angella_book, $book_id]);
        $success = 'Book content updated and published.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO book_content (book_id, title, content_html, toc_json, is_angella_book, is_processed)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$book_id, $book['title'], $content_html, $toc_json, $is_angella_book]);
        $success = 'Book processed and published.';
    }
}

// Handle fallback DOCX upload (manual processing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_docx'])) {
    if (!empty($_FILES['docx_file']['name'])) {
        $upload_dir = '../assets/uploads/temp/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $docx_filename = 'book_' . $book_id . '.docx';
        $target = $upload_dir . $docx_filename;
        if (move_uploaded_file($_FILES['docx_file']['tmp_name'], $target)) {
            // Here you would call a function to parse DOCX and generate HTML
            // For now, just save it and let the admin edit manually
            $success = 'DOCX uploaded. You can now edit the content in the editor.';
        } else {
            $error = 'Failed to upload DOCX.';
        }
    }
}

$pageTitle = 'Process Book: ' . htmlspecialchars($book['title']);
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-process-book">
    <div class="container">
        <div class="admin-header">
            <h1>Process Book: <?php echo htmlspecialchars($book['title']); ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Books
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
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="save_processed" value="1">
                    <div class="form-group">
                        <label>Content (HTML)</label>
                        <textarea name="content_html" rows="15" class="editor-textarea"><?php echo htmlspecialchars($existing_content['content_html'] ?? ''); ?></textarea>
                        <small>Use the editor below for advanced editing.</small>
                    </div>
                    <div class="form-group">
                        <label>Table of Contents (JSON)</label>
                        <textarea name="toc_json" rows="5"><?php echo htmlspecialchars($existing_content['toc_json'] ?? '[]'); ?></textarea>
                        <small>JSON array: [{"id":"ch1","title":"Chapter 1","level":1}]</small>
                    </div>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_angella_book" <?php echo ($existing_content['is_angella_book'] ?? 1) ? 'checked' : ''; ?>>
                            <span>This is Angella's original work (public)</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">Save & Publish</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Fallback: Upload DOCX for Manual Processing</h2>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="upload_docx" value="1">
                    <div class="form-group">
                        <label>Upload Word Document (.docx)</label>
                        <input type="file" name="docx_file" accept=".docx">
                        <small>Upload a DOCX file to manually build the structure using the editor.</small>
                    </div>
                    <button type="submit" class="btn btn-secondary">Upload DOCX</button>
                </form>
            </div>
        </div>

        <!-- Advanced Editor -->
        <div class="card">
            <div class="card-header">
                <h2>Advanced Editor</h2>
            </div>
            <div class="card-body">
                <p>Use the editor below to manually edit the book content and structure.</p>
                <textarea id="advancedEditor" rows="20"><?php echo htmlspecialchars($existing_content['content_html'] ?? ''); ?></textarea>
                <button onclick="updateEditorContent()" class="btn btn-primary mt-2">Update Content from Editor</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#advancedEditor',
        height: 500,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
        content_style: 'body { font-family: Inter, sans-serif; }',
        init_instance_callback: function(editor) {
            // Load existing content
            const existingContent = <?php echo json_encode($existing_content['content_html'] ?? ''); ?>;
            if (existingContent) {
                editor.setContent(existingContent);
            }
        }
    });

    function updateEditorContent() {
        const content = tinymce.get('advancedEditor').getContent();
        document.querySelector('textarea[name="content_html"]').value = content;
        alert('Content updated. Click "Save & Publish" to save the changes.');
    }
</script>

<style>
    .admin-process-book { padding: 32px 0 60px; }
    .editor-textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: monospace; resize: vertical; }
    .checkbox-group { margin: 16px 0; }
    .mt-2 { margin-top: 12px; }
</style>

<?php require_once '../includes/footer.php'; ?>