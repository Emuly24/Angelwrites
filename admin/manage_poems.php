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
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT image_path, audio_path FROM poems WHERE id = ?");
        $stmt->execute([$id]);
        $poem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($poem) {
            $doc_root = $_SERVER['DOCUMENT_ROOT'];
            if (!empty($poem['image_path']) && file_exists($doc_root . '/' . $poem['image_path'])) {
                @unlink($doc_root . '/' . $poem['image_path']);
            }
            if (!empty($poem['audio_path']) && file_exists($doc_root . '/' . $poem['audio_path'])) {
                @unlink($doc_root . '/' . $poem['audio_path']);
            }
            $stmt = $db->prepare("DELETE FROM poem_status WHERE poem_id = ?");
            $stmt->execute([$id]);
            $stmt = $db->prepare("DELETE FROM reviews WHERE target_type = 'poem' AND target_id = ?");
            $stmt->execute([$id]);
            $stmt = $db->prepare("DELETE FROM poems WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            $success = 'Poem deleted successfully.';
        } else {
            $error = 'Poem not found.';
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
    
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== HANDLE ADD NEW POEM =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_poem'])) {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    
    if (empty($title) || empty($content)) {
        $error = 'Title and content are required.';
    } else {
        $image_path = '';
        $audio_path = '';
        
        // Handle image upload
        if (!empty($_FILES['image']['name'])) {
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $image_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['image']['name']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_filename)) {
                $image_path = 'assets/uploads/poems/' . $image_filename;
            }
        }
        
        // Handle audio upload
        if (!empty($_FILES['audio']['name'])) {
            $upload_dir = '../assets/uploads/audio/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $audio_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['audio']['name']);
            if (move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $audio_filename)) {
                $audio_path = 'assets/uploads/audio/' . $audio_filename;
            }
        }
        
        $stmt = $db->prepare("INSERT INTO poems (title, intro, content, image_path, audio_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $intro, $content, $image_path, $audio_path]);
        $success = 'Poem added successfully!';
        header('Location: ' . SITE_URL . '/admin/manage_poems.php');
        exit;
    }
}

// ===== FETCH ALL POEMS =====
$stmt = $db->query("SELECT * FROM poems ORDER BY created_at DESC");
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Poems';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Poems</h1>
            <div class="admin-actions">
                <button id="showAddModal" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> Add New Poem
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

        <!-- ===== ADD POEM MODAL ===== -->
        <div id="addPoemModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h2>Add New Poem</h2>
                    <button class="modal-close">&times;</button>
                </div>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="add_poem" value="1">
                    <div class="form-group">
                        <label for="modal_title">Title <span class="required">*</span></label>
                        <input type="text" id="modal_title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_intro">Purpose / Introduction</label>
                        <textarea id="modal_intro" name="intro" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="modal_content">Content <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="12"></textarea>
                    </div>
                    
                    <!-- ===== DRAG & DROP IMAGE ZONE ===== -->
                    <div class="form-group">
                        <label>Poem Image (Drag & Drop or Click to Choose)</label>
                        <div id="dropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Drag & drop your image here, or <strong>click to browse</strong></p>
                            <input type="file" id="fileInput" name="image" accept="image/*" style="display: none;">
                            <div id="previewContainer" style="display: none; margin-top: 12px;">
                                <img id="previewImage" style="max-width: 150px; max-height: 150px; border-radius: 8px;">
                            </div>
                        </div>
                    </div>

                    <!-- ===== AUDIO UPLOAD ZONE ===== -->
                    <div class="form-group">
                        <label>Poem Audio (MP3 or WAV) – optional</label>
                        <div id="audioDropZone" style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s;">
                            <i class="fas fa-music" style="font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block;"></i>
                            <p style="margin: 0; color: var(--text-light);">Click to upload an audio file (MP3, WAV)</p>
                            <input type="file" id="audioInput" name="audio" accept="audio/*" style="display: none;">
                            <div id="audioPreviewContainer" style="display: none; margin-top: 12px;">
                                <audio controls id="audioPreview" style="width: 100%;">
                                    <source src="" type="audio/mpeg">
                                </audio>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Poem</button>
                        <button type="button" class="btn btn-outline modal-close">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== POEMS TABLE ===== -->
        <div class="card">
            <div class="card-header">
                <h2>All Poems (<?php echo count($poems); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($poems) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Title</th>
                                    <th>Introduction</th>
                                    <th>Audio</th>
                                    <th>Views</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($poems as $poem): ?>
                                    <tr>
                                        <td>
                                            <?php if ($poem['image_path']): ?>
                                                <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;">
                                            <?php else: ?>
                                                <div style="width: 50px; height: 50px; background: var(--vanilla); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-light);">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                            <br><small><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($poem['intro']): ?>
                                                <span class="intro-preview"><?php echo htmlspecialchars(substr($poem['intro'], 0, 60)); ?>...</span>
                                            <?php else: ?>
                                                <span class="text-muted">No introduction</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($poem['audio_path']): ?>
                                                <i class="fas fa-music" style="color: var(--rose);"></i>
                                                <span class="audio-label">Yes</span>
                                            <?php else: ?>
                                                <span class="text-muted">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($poem['view_count'] ?? 0); ?></td>
                                        <td class="actions">
                                            <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=poem&id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?delete=<?php echo $poem['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this poem?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No poems yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== TINYMCE & MODAL JAVASCRIPT ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let editorInitialized = false;

        // ===== MODAL LOGIC =====
        const showModalBtn = document.getElementById('showAddModal');
        const modal = document.getElementById('addPoemModal');
        const closeButtons = document.querySelectorAll('.modal-close');
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const previewContainer = document.getElementById('previewContainer');
        const previewImage = document.getElementById('previewImage');
        const audioDropZone = document.getElementById('audioDropZone');
        const audioInput = document.getElementById('audioInput');
        const audioPreviewContainer = document.getElementById('audioPreviewContainer');
        const audioPreview = document.getElementById('audioPreview');

        showModalBtn.addEventListener('click', function() {
            modal.style.display = 'flex';
            initTinyMCE();
        });

        closeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        });

        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        // ===== TINYMCE =====
        function initTinyMCE() {
            if (editorInitialized) return;
            tinymce.init({
                selector: '#editor',
                height: 400,
                menubar: true,
                plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
                toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
                content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
                forced_root_block: 'p',
                setup: function(editor) {
                    editor.on('change', function () {
                        tinymce.triggerSave();
                    });
                }
            });
            editorInitialized = true;
        }

        // ===== IMAGE DRAG & DROP =====
        dropZone.addEventListener('click', function() {
            fileInput.click();
        });
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) handleFile(e.target.files[0]);
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
            if (files.length > 0) handleFile(files[0]);
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
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                fileInput.files = dataTransfer.files;
            };
            reader.readAsDataURL(file);
        }

        // ===== AUDIO DRAG & DROP =====
        audioDropZone.addEventListener('click', function() {
            audioInput.click();
        });
        audioInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) handleAudio(e.target.files[0]);
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
            if (files.length > 0) handleAudio(files[0]);
        });
        function handleAudio(file) {
            if (!file.type.startsWith('audio/')) {
                alert('Please drop an audio file.');
                return;
            }
            const url = URL.createObjectURL(file);
            audioPreview.src = url;
            audioPreviewContainer.style.display = 'block';
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            audioInput.files = dataTransfer.files;
        }
    });
</script>

<style>
/* ===== MODAL STYLES ===== */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}
.modal-content {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 32px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.modal-header h2 { margin: 0; }
.modal-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text); transition: color 0.2s; }
.modal-close:hover { color: var(--rose); }

/* ===== ADMIN TABLE ===== */
.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
.admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }
.admin-table tbody tr:last-child td { border-bottom: none; }
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

/* ===== FORM STYLES ===== */
.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
.admin-form input[type="text"], .admin-form textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--input-bg); color: var(--text); }
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.admin-form textarea { resize: vertical; min-height: 60px; }
.required { color: #dc2626; }
.form-actions { display: flex; gap: 12px; margin-top: 16px; }
.form-actions .btn { min-width: 120px; justify-content: center; }
</style>

<?php require_once '../includes/footer.php'; ?>