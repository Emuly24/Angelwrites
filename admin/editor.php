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
$image_path = '';
$audio_path = '';
$category = '';
$tags = '';

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
    
    // Handle image upload
    if (!empty($_FILES['image']['name'])) {
        $upload_dir = '../assets/uploads/poems/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
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
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $audio_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['audio']['name']);
        if (move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $audio_filename)) {
            $uploaded_audio_path = 'assets/uploads/audio/' . $audio_filename;
        } else {
            $error = 'Failed to upload audio.';
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
                $stmt = $db->prepare("UPDATE blog_posts SET title = ?, content = ?, excerpt = ?, category = ?, tags = ? WHERE id = ?");
                $stmt->execute([$title, $content, $intro, $category, $tags, $id]);
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
                $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, category, tags, status) VALUES (?, ?, ?, ?, ?, ?, 'published')");
                $stmt->execute([$title, $slug, $content, $intro, $category, $tags]);
                $id = $db->lastInsertId();
                $success = 'Blog post created successfully!';
            }
        }
        
        if ($action === 'save_and_continue') {
            header('Location: ' . SITE_URL . '/admin/editor.php?type=' . $type . '&id=' . $id);
            exit;
        } else {
            if ($type === 'poem') {
                header('Location: ' . SITE_URL . '/admin/manage_poems.php');
            } else {
                header('Location: ' . SITE_URL . '/admin/manage_blog.php');
            }
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

                    <?php if ($type === 'poem'): ?>
                    <!-- ===== POEM COVER IMAGE (DRAG & DROP) ===== -->
                    <div class="form-group">
                        <label>Poem Cover Image (Drag & Drop or Click to Choose)</label>
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

                    <!-- ===== POEM AUDIO (DRAG & DROP) ===== -->
                    <div class="form-group">
                        <label>Poem Audio (MP3 or WAV) – optional</label>
                        <div id="audioDropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-music" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Drag & drop an audio file (MP3, WAV) or click to browse</p>
                            <input type="file" id="audioInput" name="audio" accept="audio/*" style="display: none;">
                            <div id="audioPreviewContainer" style="display: none; margin-top: 12px;">
                                <audio controls id="audioPreview" style="width: 100%;">
                                    <source src="" type="audio/mpeg">
                                </audio>
                            </div>
                            <?php if (!empty($audio_path)): ?>
                                <div id="currentAudioContainer" style="margin-top: 12px;">
                                    <p><strong>Current Audio:</strong></p>
                                    <audio controls style="width: 100%;">
                                        <source src="<?php echo SITE_URL . '/' . $audio_path; ?>" type="audio/mpeg">
                                    </audio>
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

<!-- ===== TINYMCE EDITOR ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    // ===== TINYMCE INIT =====
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

    // ===== DRAG & DROP IMAGE =====
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const previewContainer = document.getElementById('previewContainer');
        const previewImage = document.getElementById('previewImage');

        if (!dropZone) return;

        dropZone.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleFile(e.target.files[0]);
            }
        });

        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.style.borderColor = 'var(--rose)';
            dropZone.style.background = 'rgba(219, 161, 162, 0.1)';
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dropZone.style.borderColor = 'var(--border)';
            dropZone.style.background = 'transparent';
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.style.borderColor = 'var(--border)';
            dropZone.style.background = 'transparent';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });

        function handleFile(file) {
            if (!file.type.startsWith('image/')) {
                alert('Please drop an image file.');
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewContainer.style.display = 'block';
                // Hide current image preview if exists
                const currentImg = document.getElementById('currentImageContainer');
                if (currentImg) {
                    currentImg.style.display = 'none';
                }
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;
            };
            reader.readAsDataURL(file);
        }
    });

    // ===== DRAG & DROP AUDIO =====
    document.addEventListener('DOMContentLoaded', function() {
        const audioDropZone = document.getElementById('audioDropZone');
        const audioInput = document.getElementById('audioInput');
        const audioPreviewContainer = document.getElementById('audioPreviewContainer');
        const audioPreview = document.getElementById('audioPreview');

        if (!audioDropZone) return;

        audioDropZone.addEventListener('click', function() {
            audioInput.click();
        });

        audioInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleAudio(e.target.files[0]);
            }
        });

        audioDropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            audioDropZone.style.borderColor = 'var(--rose)';
            audioDropZone.style.background = 'rgba(219, 161, 162, 0.1)';
        });

        audioDropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            audioDropZone.style.borderColor = 'var(--border)';
            audioDropZone.style.background = 'transparent';
        });

        audioDropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            audioDropZone.style.borderColor = 'var(--border)';
            audioDropZone.style.background = 'transparent';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleAudio(files[0]);
            }
        });

        function handleAudio(file) {
            if (!file.type.startsWith('audio/')) {
                alert('Please drop an audio file.');
                return;
            }
            const url = URL.createObjectURL(file);
            audioPreview.src = url;
            audioPreviewContainer.style.display = 'block';
            // Hide current audio preview if exists
            const currentAudio = document.getElementById('currentAudioContainer');
            if (currentAudio) {
                currentAudio.style.display = 'none';
            }
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            audioInput.files = dataTransfer.files;
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