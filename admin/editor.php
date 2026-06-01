<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$type = isset($_GET['type']) ? $_GET['type'] : 'poem';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';
$existing_content = null;
$title = '';
$intro = '';
$content = '';
$image_path = '';
$audio_path = '';
$category = '';
$tags = '';
$status = 'draft';
$featured_image = '';

// Fetch existing content
if ($id > 0) {
    if ($type === 'poem') {
        $stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
        $stmt->execute([$id]);
        $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing_content) {
            $title = $existing_content['title'];
            $intro = $existing_content['intro'];
            $content = $existing_content['content'];
            $image_path = $existing_content['image_path'] ?? '';
            $audio_path = $existing_content['audio_path'] ?? '';
        }
    } elseif ($type === 'blog') {
        $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
        $stmt->execute([$id]);
        $existing_content = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing_content) {
            $title = $existing_content['title'];
            $intro = $existing_content['excerpt'] ?? '';
            $content = $existing_content['content'];
            $category = $existing_content['category'] ?? 'Christian Reflections';
            $tags = $existing_content['tags'] ?? '';
            $status = $existing_content['status'] ?? 'draft';
            $featured_image = $existing_content['featured_image'] ?? '';
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $action = $_POST['action'] ?? 'save';
    
    $uploaded_image_path = $image_path;
    $uploaded_audio_path = $audio_path;
    $uploaded_featured_image = $featured_image;

    // Handle image upload (for poem cover or blog body)
    if (!empty($_FILES['image']['name'])) {
        $upload_dir = '../assets/uploads/poems/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $image_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['image']['name']);
        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_filename)) {
            $uploaded_image_path = 'assets/uploads/poems/' . $image_filename;
        } else {
            $error = 'Failed to upload image.';
        }
    }
    
    // Handle audio upload
    if (!empty($_FILES['audio']['name'])) {
        $upload_dir = '../assets/uploads/audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $audio_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['audio']['name']);
        if (move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $audio_filename)) {
            $uploaded_audio_path = 'assets/uploads/audio/' . $audio_filename;
        } else {
            $error = 'Failed to upload audio.';
        }
    }

    // Handle featured image upload (for blog posts)
    if (!empty($_FILES['featured_image']['name']) && $type === 'blog') {
        $upload_dir = '../assets/uploads/blog/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $feat_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['featured_image']['name']);
        if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . $feat_filename)) {
            $uploaded_featured_image = 'assets/uploads/blog/' . $feat_filename;
        } else {
            $error = 'Failed to upload featured image.';
        }
    }

    if (empty($title) || empty($content)) {
        $error = 'Title and content are required.';
    } else {
        if ($id > 0) {
            // Update existing
            if ($type === 'poem') {
                $stmt = $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ?, image_path = ?, audio_path = ? WHERE id = ?");
                $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path, $id]);
                $success = 'Poem updated successfully!';
            } elseif ($type === 'blog') {
                $category = trim($_POST['category'] ?? 'Christian Reflections');
                $tags = trim($_POST['tags'] ?? '');
                $status = trim($_POST['status'] ?? 'draft');
                $stmt = $db->prepare("UPDATE blog_posts SET title = ?, content = ?, excerpt = ?, category = ?, tags = ?, status = ?, featured_image = ? WHERE id = ?");
                $stmt->execute([$title, $content, $intro, $category, $tags, $status, $uploaded_featured_image, $id]);
                $success = 'Blog post updated successfully!';
            }
        } else {
            // Insert new
            if ($type === 'poem') {
                $stmt = $db->prepare("INSERT INTO poems (title, intro, content, image_path, audio_path) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path]);
                $id = $db->lastInsertId();
                $success = 'Poem created successfully!';
            } elseif ($type === 'blog') {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
                $category = trim($_POST['category'] ?? 'Christian Reflections');
                $tags = trim($_POST['tags'] ?? '');
                $status = trim($_POST['status'] ?? 'draft');
                $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, category, tags, status, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $content, $intro, $category, $tags, $status, $uploaded_featured_image]);
                $id = $db->lastInsertId();
                $success = 'Blog post created successfully!';
            }
        }
        
        if ($action === 'save_and_continue') {
            header('Location: ' . SITE_URL . '/admin/editor.php?type=' . $type . '&id=' . $id);
            exit;
        } else {
            header('Location: ' . SITE_URL . '/admin/manage_' . $type . 's.php');
            exit;
        }
    }
}

$pageTitle = ucfirst($type) . ' Editor';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-editor">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo $id > 0 ? 'Edit ' . ucfirst($type) : 'Add New ' . ucfirst($type); ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_<?php echo $type; ?>s.php" class="btn btn-outline">
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

        <form method="POST" id="editorForm" class="admin-form" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="save">

            <div class="card">
                <div class="card-body">
                    <div class="form-group">
                        <label for="title">Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="intro">Introduction / Purpose</label>
                        <textarea id="intro" name="intro" rows="3"><?php echo htmlspecialchars($intro); ?></textarea>
                    </div>

                    <?php if ($type === 'blog'): ?>
                    <!-- ===== BLOG FIELDS ===== -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($category ?? 'Christian Reflections'); ?>" placeholder="e.g. Christian Reflections">
                        </div>
                        <div class="form-group">
                            <label for="tags">Tags (comma separated)</label>
                            <input type="text" id="tags" name="tags" value="<?php echo htmlspecialchars($tags ?? ''); ?>" placeholder="e.g. faith, hope, healing">
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="draft" <?php echo ($status ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo ($status ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="archived" <?php echo ($status ?? '') === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                    </div>

                    <!-- Featured Image -->
                    <div class="form-group">
                        <label>Featured Image (for blog listing)</label>
                        <div id="featDropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-image" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Click to upload a featured image</p>
                            <input type="file" id="featFileInput" name="featured_image" accept="image/*" style="display: none;">
                            <div id="featPreviewContainer" style="display: none; margin-top: 12px;">
                                <img id="featPreviewImage" style="max-width: 150px; max-height: 150px; border-radius: 8px;">
                            </div>
                            <?php if (!empty($featured_image)): ?>
                                <div id="currentFeatContainer" style="margin-top: 12px;">
                                    <p><strong>Current Featured Image:</strong></p>
                                    <img src="<?php echo SITE_URL . '/' . $featured_image; ?>" style="max-width: 150px; max-height: 150px; border-radius: 8px;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($type === 'poem'): ?>
                    <!-- ===== POEM COVER IMAGE ===== -->
                    <div class="form-group">
                        <label>Poem Cover Image</label>
                        <div id="dropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Drag & drop your image here, or <strong>click to browse</strong></p>
                            <input type="file" id="fileInput" name="image" accept="image/*" style="display: none;">
                            <div id="previewContainer" style="display: none; margin-top: 12px;">
                                <img id="previewImage" style="max-width: 150px; max-height: 150px; border-radius: 8px;">
                            </div>
                            <?php if (!empty($image_path)): ?>
                                <div id="currentImageContainer" style="margin-top: 12px;">
                                    <p><strong>Current Image:</strong></p>
                                    <img src="<?php echo SITE_URL . '/' . $image_path; ?>" style="max-width: 150px; max-height: 150px; border-radius: 8px;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== POEM AUDIO ===== -->
                    <div class="form-group">
                        <label>Poem Audio (MP3 or WAV) – optional</label>
                        <div id="audioDropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-music" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Click to upload an audio file</p>
                            <input type="file" id="audioInput" name="audio" accept="audio/*" style="display: none;">
                            <div id="audioPreviewContainer" style="display: none; margin-top: 12px;">
                                <audio controls id="audioPreview" style="width: 100%;"><source src="" type="audio/mpeg"></audio>
                            </div>
                            <?php if (!empty($audio_path)): ?>
                                <div id="currentAudioContainer" style="margin-top: 12px;">
                                    <p><strong>Current Audio:</strong></p>
                                    <audio controls style="width: 100%;"><source src="<?php echo SITE_URL . '/' . $audio_path; ?>" type="audio/mpeg"></audio>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="content">Content <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="20"><?php echo htmlspecialchars($content); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save</button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save_and_continue'; document.getElementById('editorForm').submit();">
                            Save & Continue
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ===== TINYMCE EDITOR WITH BIBLE BUTTON ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#editor',
        height: 600,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code | bible',
        content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
        forced_root_block: 'p',
        init_instance_callback: function(editor) {
            const existingContent = <?php echo json_encode($content); ?>;
            if (existingContent) {
                editor.setContent(existingContent);
            }
        },
        setup: function(editor) {
            // ===== BIBLE BUTTON =====
            editor.ui.registry.addButton('bible', {
                text: '📖 Bible',
                tooltip: 'Open Bible Reader to extract verses',
                onAction: function() {
                    // Open bible_reader.php in a new tab for verse extraction
                    const bibleWindow = window.open('/bible_reader.php', 'BibleReader', 'width=1000,height=800,scrollbars=yes');
                    if (!bibleWindow) {
                        alert('Please allow popups to use the Bible feature.');
                    }
                }
            });

            editor.addShortcut('Ctrl+S', 'Save', function() {
                document.querySelector('form').submit();
            });
        }
    });

    // ===== DRAG & DROP FOR FEATURED IMAGE =====
    document.addEventListener('DOMContentLoaded', function() {
        const featDropZone = document.getElementById('featDropZone');
        const featFileInput = document.getElementById('featFileInput');
        const featPreviewContainer = document.getElementById('featPreviewContainer');
        const featPreviewImage = document.getElementById('featPreviewImage');

        if (featDropZone) {
            featDropZone.addEventListener('click', function() { featFileInput.click(); });
            featFileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) handleFeatFile(e.target.files[0]);
            });
            featDropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                featDropZone.style.borderColor = 'var(--rose)';
                featDropZone.style.background = 'rgba(219, 161, 162, 0.1)';
            });
            featDropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                featDropZone.style.borderColor = 'var(--border)';
                featDropZone.style.background = 'transparent';
            });
            featDropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                featDropZone.style.borderColor = 'var(--border)';
                featDropZone.style.background = 'transparent';
                const files = e.dataTransfer.files;
                if (files.length > 0) handleFeatFile(files[0]);
            });
        }

        function handleFeatFile(file) {
            if (!file.type.startsWith('image/')) {
                alert('Please select an image file.');
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                featPreviewImage.src = e.target.result;
                featPreviewContainer.style.display = 'block';
                const currentFeat = document.getElementById('currentFeatContainer');
                if (currentFeat) currentFeat.style.display = 'none';
                const dt = new DataTransfer();
                dt.items.add(file);
                featFileInput.files = dt.files;
            };
            reader.readAsDataURL(file);
        }
    });

    // ===== EXISTING DRAG & DROP FOR POEMS =====
    // (Keep the existing dropZone logic from your original file)
    // ...
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
    .form-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
    .form-row .form-group { flex: 1; min-width: 150px; }
    .required { color: #dc2626; }
    .form-actions { display: flex; gap: 12px; margin-top: 16px; }
    .form-actions .btn { min-width: 120px; justify-content: center; }
    .card { margin-bottom: 24px; }
    .card-body { padding: 20px; }
</style>

<?php require_once '../includes/footer.php'; ?>