<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

// ===== HANDLE FLASH MESSAGES =====
$flash_message = '';
$flash_type = '';

if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    $flash_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ===== HANDLE PUBLISH / UNPUBLISH =====
if (isset($_GET['publish']) && is_numeric($_GET['publish'])) {
    $id = (int)$_GET['publish'];
    $stmt = $db->prepare("UPDATE poem_status SET status = 'published' WHERE poem_id = ?");
    $stmt->execute([$id]);
    $_SESSION['flash_message'] = '✅ Poem published and is now live for all users!';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}
if (isset($_GET['unpublish']) && is_numeric($_GET['unpublish'])) {
    $id = (int)$_GET['unpublish'];
    $stmt = $db->prepare("UPDATE poem_status SET status = 'draft' WHERE poem_id = ?");
    $stmt->execute([$id]);
    $_SESSION['flash_message'] = '🔒 Poem unpublished and reverted to draft.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== HANDLE BULK DELETE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    $ids = array_filter(explode(',', $_POST['selected_ids'] ?? ''));
    if (!empty($ids)) {
        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM poem_status WHERE poem_id IN ($placeholders)");
            $stmt->execute($ids);
            $stmt = $db->prepare("DELETE FROM reviews WHERE target_type = 'poem' AND target_id IN ($placeholders)");
            $stmt->execute($ids);
            $stmt = $db->prepare("DELETE FROM poems WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $db->commit();
            $_SESSION['flash_message'] = count($ids) . ' poem(s) deleted successfully.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['flash_message'] = 'Error deleting poems: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: ' . SITE_URL . '/admin/manage_poems.php');
        exit;
    }
}

// ===== HANDLE SINGLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT image_path, audio_path FROM poems WHERE id = ?");
        $stmt->execute([$id]);
        $poem = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($poem) {
            $doc_root = $_SERVER['DOCUMENT_ROOT'];
            if (!empty($poem['image_path']) && file_exists($doc_root . '/' . $poem['image_path'])) @unlink($doc_root . '/' . $poem['image_path']);
            if (!empty($poem['audio_path']) && file_exists($doc_root . '/' . $poem['audio_path'])) @unlink($doc_root . '/' . $poem['audio_path']);
            
            $stmt = $db->prepare("DELETE FROM poem_status WHERE poem_id = ?"); $stmt->execute([$id]);
            $stmt = $db->prepare("DELETE FROM reviews WHERE target_type = 'poem' AND target_id = ?"); $stmt->execute([$id]);
            $stmt = $db->prepare("DELETE FROM poems WHERE id = ?"); $stmt->execute([$id]);
            $db->commit();
            $_SESSION['flash_message'] = 'Poem deleted successfully.';
            $_SESSION['flash_type'] = 'success';
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $_SESSION['flash_message'] = 'Database error: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== HANDLE SAVE (ADD / EDIT) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_poem'])) {
    $id = isset($_POST['poem_id']) ? (int)$_POST['poem_id'] : 0;
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);

    $error = null;
    if (empty($title)) $error = 'Please enter a title.';
    elseif (empty($content)) $error = 'Please enter the poem content.';

    // Handle Image Upload
    $image_path = null;
    if (is_null($error)) {
        if (!empty($_FILES['image']['name'])) {
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = 'poem_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                $image_path = 'assets/uploads/poems/' . $filename;
            } else $error = 'Failed to upload image.';
        } elseif (!empty($_FILES['image_fallback']['name'])) { // Fallback drag & drop
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['image_fallback']['name'], PATHINFO_EXTENSION);
            $filename = 'poem_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image_fallback']['tmp_name'], $upload_dir . $filename)) {
                $image_path = 'assets/uploads/poems/' . $filename;
            } else $error = 'Failed to upload image.';
        }
    }

    // Handle Audio Upload
    $audio_path = null;
    if (is_null($error)) {
        if (!empty($_FILES['audio']['name'])) {
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
            $filename = 'poem_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $filename)) {
                $audio_path = 'assets/uploads/poems/' . $filename;
            } else $error = 'Failed to upload audio.';
        } elseif (!empty($_FILES['audio_recording']['name'])) { // Recording fallback
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['audio_recording']['name'], PATHINFO_EXTENSION);
            $filename = 'poem_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['audio_recording']['tmp_name'], $upload_dir . $filename)) {
                $audio_path = 'assets/uploads/poems/' . $filename;
            } else $error = 'Failed to upload recording.';
        }
    }

    if (is_null($error)) {
        try {
            if ($id > 0) {
                // Update existing poem (DO NOT change status)
                $stmt = $db->prepare("
                    UPDATE poems SET 
                        title = ?, intro = ?, content = ?, 
                        image_path = COALESCE(?, image_path), 
                        audio_path = COALESCE(?, audio_path),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$title, $intro, $content, $image_path, $audio_path, $id]);
                $_SESSION['flash_message'] = '✅ Poem updated successfully. Status remains unchanged.';
                $_SESSION['flash_type'] = 'success';
            } else {
                // Insert new poem (Default to DRAFT)
                $stmt = $db->prepare("
                    INSERT INTO poems (title, intro, content, image_path, audio_path, view_count, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$title, $intro, $content, $image_path, $audio_path]);
                $new_id = $db->lastInsertId();
                
                // Insert default status as DRAFT
                $stmt = $db->prepare("INSERT INTO poem_status (poem_id, status) VALUES (?, 'draft')");
                $stmt->execute([$new_id]);
                
                $_SESSION['flash_message'] = '✅ Poem added successfully as a Draft. Click the "Publish" button in the table to make it live!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = '❌ Database error: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_message'] = '❌ ' . $error;
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== FETCH POEMS WITH SEARCH (JOIN status) =====
$sql = "SELECT p.*, COALESCE(s.status, 'draft') as status 
        FROM poems p 
        LEFT JOIN poem_status s ON p.id = s.poem_id";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE p.title LIKE ? OR p.intro LIKE ? OR p.content LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY p.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
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
                    <i class="fas fa-plus"></i> Add New Poem
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Toast Notification Container -->
        <div id="toastContainer"></div>

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search poems by title, introduction, or content..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Poems Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Poems (<?php echo count($poems); ?>)</h2>
                <div class="card-header-actions">
                    <select id="bulkActionSelect" style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.85rem;background:var(--card-bg);color:var(--text);">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($poems) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows" class="styled-checkbox"></th>
                                        <th>Image</th>
                                        <th>Title</th>
                                        <th>Introduction</th>
                                        <th>Audio</th>
                                        <th>Status</th>
                                        <th>Views</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($poems as $poem): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select styled-checkbox" value="<?php echo $poem['id']; ?>"></td>
                                            <td>
                                                <?php if ($poem['image_path']): ?>
                                                    <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                                                <?php else: ?>
                                                    <div style="width: 50px; height: 50px; background: var(--vanilla); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-light);">
                                                        <i class="fas fa-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                                <br><small style="color:var(--text-light);font-size:0.8rem;"><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($poem['intro']): ?>
                                                    <span class="intro-preview" style="color:var(--text-light);font-size:0.9rem;"><?php echo htmlspecialchars(substr($poem['intro'], 0, 60)); ?>...</span>
                                                <?php else: ?>
                                                    <span class="text-muted" style="color:#999;font-size:0.9rem;">No introduction</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($poem['audio_path']): ?>
                                                    <span style="display:flex;align-items:center;gap:6px;color:var(--rose);"><i class="fas fa-music"></i> Yes</span>
                                                <?php else: ?>
                                                    <span style="color:#999;font-size:0.9rem;">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $poem['status']; ?>">
                                                    <?php echo ucfirst($poem['status']); ?>
                                                </span>
                                            </td>
                                            <td style="font-weight:600;"><?php echo number_format($poem['view_count'] ?? 0); ?></td>
                                            <td class="actions">
                                                <!-- MANUAL PUBLISH / UNPUBLISH BUTTON -->
                                                <?php if ($poem['status'] === 'published'): ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?unpublish=<?php echo $poem['id']; ?>" class="btn btn-sm btn-warning" title="Unpublish (Revert to Draft)">
                                                        <i class="fas fa-eye-slash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?publish=<?php echo $poem['id']; ?>" class="btn btn-sm btn-success" title="Publish (Make Live)">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <button class="btn btn-sm btn-secondary edit-btn" 
                                                        data-id="<?php echo $poem['id']; ?>" 
                                                        data-title="<?php echo htmlspecialchars($poem['title']); ?>" 
                                                        data-intro="<?php echo htmlspecialchars($poem['intro'] ?? ''); ?>" 
                                                        data-content="<?php echo htmlspecialchars($poem['content'] ?? ''); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?delete=<?php echo $poem['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this poem permanently?');">
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
                    </form>
                <?php else: ?>
                    <p class="no-items" style="text-align:center;padding:40px;color:var(--text-light);">No poems yet. Click <strong>"Add New Poem"</strong> to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== ADD/EDIT POEM MODAL ===== -->
<div id="poemModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 850px;">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Poem</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="poem-form" id="poemForm">
            <input type="hidden" name="poem_id" id="poem_id" value="0">
            <input type="hidden" name="save_poem" value="1">
            
            <div class="form-group">
                <label for="title">Title <span class="required">*</span></label>
                <input type="text" id="title" name="title" required placeholder="Enter poem title...">
            </div>
            
            <div class="form-group">
                <label for="intro">Introduction / Purpose</label>
                <textarea id="intro" name="intro" rows="3" placeholder="Write a short introduction explaining the purpose or inspiration behind this poem..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="content">Content <span class="required">*</span></label>
                <textarea id="editor" name="content" rows="12"></textarea>
            </div>

            <!-- ===== CAMERA & IMAGE ===== -->
            <div class="form-group">
                <label>Image (Live Photo or Upload)</label>
                <div class="camera-trigger-group">
                    <button type="button" id="openCameraBtn" class="btn btn-secondary btn-sm">
                        <i class="fas fa-camera"></i> Open Camera
                    </button>
                    <span id="photoStatus" class="status-indicator">No photo captured</span>
                    <input type="file" id="livePhotoInput" name="image" accept="image/*" style="display:none;">
                </div>
            </div>

            <!-- ===== DRAG & DROP IMAGE (FALLBACK) ===== -->
            <div class="form-group">
                <label>Or Upload Image (Drag & Drop)</label>
                <div id="dropZone" class="upload-zone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag & drop your image here, or <strong>click to browse</strong></p>
                    <input type="file" id="fileInput" name="image_fallback" accept="image/*" style="display:none;">
                    <div id="previewContainer" style="display:none; margin-top:12px;">
                        <img id="previewImage" style="max-width:150px; max-height:150px; border-radius:8px;">
                    </div>
                </div>
            </div>

            <!-- ===== AUDIO UPLOAD ===== -->
            <div class="form-group">
                <label>Poem Audio (MP3 or WAV) – optional</label>
                <div id="audioDropZone" class="upload-zone">
                    <i class="fas fa-music"></i>
                    <p>Drag & drop an audio file (MP3, WAV), or <strong>click to browse</strong></p>
                    <input type="file" id="audioInput" name="audio" accept="audio/*" style="display:none;">
                    <div id="audioPreviewContainer" style="display:none; margin-top:12px;">
                        <audio controls id="audioPreview" style="width:100%;"><source src="" type="audio/mpeg"></audio>
                    </div>
                </div>
            </div>

            <!-- ===== AUDIO RECORDER ===== -->
            <div class="recorder-section">
                <h4>🎙️ Or Record Audio Directly</h4>
                <div class="recorder-controls">
                    <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                    <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                    <form id="recordingForm" style="display:none;">
                        <input type="file" name="audio_recording" id="recordingInput" accept="audio/webm">
                    </form>
                    <div id="recordingPreviewContainer" style="display:none; margin-top:10px;">
                        <audio controls id="recordingPreview" style="width:100%;"><source src="" type="audio/webm"></audio>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" id="previewPoemBtn" class="btn btn-outline">
                    <i class="fas fa-eye"></i> Preview
                </button>
                <button type="submit" id="savePoemBtn" name="save_poem" class="btn btn-primary">
                    <i class="fas fa-save"></i> <span id="saveBtnText">Save Poem</span>
                </button>
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== LIVE PREVIEW MODAL ===== -->
<div id="previewModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 800px; background: var(--fantasy); padding:0; overflow:hidden;">
        <div class="preview-header" style="padding:16px 24px; background: var(--vanilla); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-family:'Playfair Display',Georgia,serif;">📖 Live Preview</h3>
            <button class="preview-close" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light);">&times;</button>
        </div>
        <div class="preview-body" style="padding:40px 30px; background:#fff; max-height:70vh; overflow-y:auto; display:flex; flex-direction:column; align-items:center;">
            <div class="preview-book" style="max-width:600px; width:100%;">
                <h1 id="prevTitle" style="font-family:'Playfair Display',Georgia,serif; font-size:2rem; color:var(--dark); margin-bottom:8px;">Title</h1>
                <p id="prevIntro" style="font-style:italic; color:var(--text-light); margin-bottom:24px; border-left:4px solid var(--rose); padding-left:16px;">Introduction</p>
                <div id="prevContent" style="font-family:'Georgia',serif; line-height:2; font-size:1.1rem; color:var(--text);"></div>
            </div>
        </div>
    </div>
</div>

<!-- ===== FULL-SCREEN CAMERA MODAL ===== -->
<div id="cameraModal" class="camera-modal" style="display:none;">
    <div class="camera-modal-inner">
        <div class="camera-preview-wrapper">
            <video id="cameraPreview" autoplay muted playsinline></video>
        </div>
        
        <div class="camera-top-bar">
            <button type="button" class="camera-close-btn" id="cameraCloseBtn"><i class="fas fa-times"></i></button>
            <div class="camera-mode-switch">
                <button type="button" class="mode-btn active" data-mode="photo">📷 Photo</button>
                <button type="button" class="mode-btn" data-mode="video">🎥 Video</button>
            </div>
        </div>

        <div class="camera-bottom-controls">
            <div class="camera-controls-left">
                <button type="button" id="retakeMediaBtn" class="camera-btn" disabled><i class="fas fa-redo"></i> Retake</button>
            </div>
            <div class="camera-controls-center">
                <button type="button" id="captureBtn" class="camera-shutter-btn">
                    <span class="shutter-ring"></span><span class="shutter-inner"></span>
                </button>
            </div>
            <div class="camera-controls-right">
                <button type="button" id="confirmMediaBtn" class="camera-btn" disabled><i class="fas fa-check"></i> Confirm</button>
            </div>
        </div>
        <div id="cameraStatus" class="camera-status">Ready</div>
    </div>
</div>

<!-- ===== TINYMCE & JAVASCRIPT ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let editorInitialized = false;

    // ===== TOAST NOTIFICATIONS =====
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${type === 'success' ? '✅' : '❌'}</span>
            <span class="toast-message">${message}</span>
            <span class="toast-close">&times;</span>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            setTimeout(() => toast.remove(), 500);
        }, 5000);

        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            setTimeout(() => toast.remove(), 500);
        });
    }

    <?php if (!empty($flash_message)): ?>
        showToast("<?php echo addslashes($flash_message); ?>", "<?php echo addslashes($flash_type); ?>");
    <?php endif; ?>

    // ===== MODAL LOGIC =====
    const modal = document.getElementById('poemModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeButtons = document.querySelectorAll('.modal-close');
    const addBtn = document.getElementById('showAddModal');
    const editBtns = document.querySelectorAll('.edit-btn');
    const saveBtn = document.getElementById('savePoemBtn');
    const saveBtnText = document.getElementById('saveBtnText');

   function openModal(title, data) {
    modalTitle.textContent = title;
    modal.style.display = 'flex';
    initTinyMCE();
    if (data) {
        document.getElementById('poem_id').value = data.id;
        document.getElementById('title').value = data.title;
        document.getElementById('intro').value = data.intro;
        tinymce.get('editor').setContent(data.content);
        saveBtnText.textContent = 'Update Poem';
    } else {
        document.getElementById('poem_id').value = 0;
        document.getElementById('title').value = '';
        document.getElementById('intro').value = '';
        tinymce.get('editor').setContent('');
        saveBtnText.textContent = 'Save Poem (Draft)';
    }

    try { resetCamera(); } catch(e) { console.log('Camera reset skipped'); }
    try { resetAudioRecorder(); } catch(e) { console.log('Audio recorder reset skipped'); }
}

    addBtn.addEventListener('click', function() { openModal('Add New Poem', null); });
    editBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            openModal('Edit Poem', {
                id: this.dataset.id,
                title: this.dataset.title,
                intro: this.dataset.intro,
                content: this.dataset.content
            });
        });
    });

    document.getElementById('poemForm').addEventListener('submit', function() {
        saveBtn.disabled = true;
        saveBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Saving...`;
    });

    closeButtons.forEach(btn => btn.addEventListener('click', function() { modal.style.display = 'none'; }));
    window.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });

    // ===== TINYMCE =====
    function initTinyMCE() {
        if (editorInitialized) return;
        tinymce.init({
            selector: '#editor',
            height: 450,
            menubar: true,
            plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
            toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
            content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
            forced_root_block: 'p',
            setup: function(editor) { editor.on('change', function() { tinymce.triggerSave(); }); }
        });
        editorInitialized = true;
    }

    // ===== LIVE PREVIEW BUTTON =====
    const previewBtn = document.getElementById('previewPoemBtn');
    const previewModal = document.getElementById('previewModal');
    const prevTitle = document.getElementById('prevTitle');
    const prevIntro = document.getElementById('prevIntro');
    const prevContent = document.getElementById('prevContent');

    previewBtn.addEventListener('click', function() {
        const title = document.getElementById('title').value.trim() || 'Untitled Poem';
        const intro = document.getElementById('intro').value.trim() || 'No introduction provided.';
        let content = tinymce.get('editor').getContent();
        if(content.trim() === '') content = '<p style="color:#999;font-style:italic;">No content entered yet.</p>';

        prevTitle.textContent = title;
        prevIntro.textContent = intro;
        prevContent.innerHTML = content;
        previewModal.style.display = 'flex';
    });

    document.querySelector('.preview-close').addEventListener('click', function() { previewModal.style.display = 'none'; });
    window.addEventListener('click', function(e) { if (e.target === previewModal) previewModal.style.display = 'none'; });

    // ============================================================
    // CAMERA / RECORDER / DRAG & DROP LOGIC
    // ============================================================
    const cameraModal = document.getElementById('cameraModal');
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraCloseBtn = document.getElementById('cameraCloseBtn');
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeMediaBtn');
    const confirmBtn = document.getElementById('confirmMediaBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const modeBtns = document.querySelectorAll('.mode-btn');
    const openCameraBtn = document.getElementById('openCameraBtn');
    const livePhotoInput = document.getElementById('livePhotoInput');
    const photoStatus = document.getElementById('photoStatus');
    const audioInput = document.getElementById('audioInput');

    let cameraStream = null, mediaRecorder = null, recordedChunks = [], capturedBlob = null, recordedBlob = null, currentMode = 'photo';

    function openCamera(mode) {
        currentMode = mode;
        cameraModal.style.display = 'flex';
        cameraStatus.textContent = 'Starting camera...';
        modeBtns.forEach(btn => btn.classList.toggle('active', btn.dataset.mode === mode));
        retakeBtn.disabled = true;
        confirmBtn.disabled = true;
        capturedBlob = null; recordedBlob = null; recordedChunks = [];
        startCameraStream();
    }

    function closeCamera() {
        if (cameraStream) { cameraStream.getTracks().forEach(track => track.stop()); cameraStream = null; }
        if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
        cameraPreview.srcObject = null; cameraPreview.src = '';
        cameraModal.style.display = 'none';
    }

    async function startCameraStream() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return cameraStatus.textContent = '❌ Camera not supported';
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: currentMode === 'video' });
            cameraPreview.srcObject = cameraStream;
            cameraStatus.textContent = currentMode === 'photo' ? 'Ready' : 'Ready to record';
        } catch (error) { cameraStatus.textContent = '❌ Camera access denied: ' + error.message; }
    }

    function capturePhoto() {
        if (!cameraStream) return;
        const canvas = document.createElement('canvas');
        canvas.width = cameraPreview.videoWidth || 1280; canvas.height = cameraPreview.videoHeight || 720;
        const ctx = canvas.getContext('2d'); ctx.drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            capturedBlob = blob; retakeBtn.disabled = false; confirmBtn.disabled = false;
            cameraStatus.textContent = '✅ Photo captured';
            cameraStream.getTracks().forEach(track => track.stop()); cameraPreview.srcObject = null;
        }, 'image/jpeg');
    }

    function startRecording() {
        if (!cameraStream) return;
        recordedChunks = []; mediaRecorder = new MediaRecorder(cameraStream);
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };
        mediaRecorder.onstop = function() {
            const blob = new Blob(recordedChunks, { type: 'video/webm' });
            if (blob.size > 0) {
                recordedBlob = blob; retakeBtn.disabled = false; confirmBtn.disabled = false;
                cameraStatus.textContent = '✅ Recording complete';
                cameraStream.getTracks().forEach(track => track.stop()); cameraPreview.srcObject = null;
            }
        };
        mediaRecorder.start();
        captureBtn.classList.add('recording'); cameraStatus.textContent = '🔴 Recording...';
    }

    function stopRecording() { if (mediaRecorder && mediaRecorder.state !== 'inactive') { mediaRecorder.stop(); captureBtn.classList.remove('recording'); } }

    function retakeMedia() {
        capturedBlob = null; recordedBlob = null; recordedChunks = []; retakeBtn.disabled = true; confirmBtn.disabled = true;
        cameraStatus.textContent = 'Retaking...';
        if (cameraStream) { cameraStream.getTracks().forEach(track => track.stop()); cameraStream = null; }
        startCameraStream();
    }

    function confirmMedia() {
        if (currentMode === 'photo' && capturedBlob) {
            const file = new File([capturedBlob], 'live_photo.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer(); dt.items.add(file); livePhotoInput.files = dt.files;
            photoStatus.textContent = '✅ Photo confirmed!'; photoStatus.style.color = '#2ecc71'; closeCamera();
        } else if (currentMode === 'video' && recordedBlob) {
            const file = new File([recordedBlob], 'live_video.webm', { type: 'video/webm' });
            const dt = new DataTransfer(); dt.items.add(file); audioInput.files = dt.files;
            document.getElementById('audioPreviewContainer').style.display = 'block';
            const url = URL.createObjectURL(file); document.getElementById('audioPreview').src = url; closeCamera();
            showToast('✅ Video captured and saved as audio!', 'success');
        }
    }

    openCameraBtn.addEventListener('click', function() { openCamera('photo'); });
    cameraCloseBtn.addEventListener('click', closeCamera);
    captureBtn.addEventListener('click', function() { currentMode === 'photo' ? capturePhoto() : (mediaRecorder && mediaRecorder.state !== 'inactive' ? stopRecording() : startRecording()); });
    retakeBtn.addEventListener('click', retakeMedia);
    confirmBtn.addEventListener('click', confirmMedia);
    modeBtns.forEach(btn => { btn.addEventListener('click', function() { if (this.dataset.mode === currentMode) return; if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop(); closeCamera(); setTimeout(() => openCamera(this.dataset.mode), 200); }); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && cameraModal.style.display !== 'none') closeCamera(); });

    function resetCamera() {
        if (cameraStream) { cameraStream.getTracks().forEach(track => track.stop()); cameraStream = null; }
        cameraPreview.srcObject = null; cameraPreview.src = ''; cameraModal.style.display = 'none'; livePhotoInput.value = '';
        photoStatus.textContent = 'No photo captured'; photoStatus.style.color = 'var(--text-light)'; audioInput.value = '';
    }

    // ===== DRAG & DROP IMAGE =====
    const dropZone = document.getElementById('dropZone'); const fileInput = document.getElementById('fileInput');
    const previewContainer = document.getElementById('previewContainer'); const previewImage = document.getElementById('previewImage');
    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', function(e) { if (e.target.files.length > 0) handleDragDrop(e.target.files[0]); });
    dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.style.borderColor = 'var(--rose)'; dropZone.style.background = 'rgba(219,161,162,0.1)'; });
    dropZone.addEventListener('dragleave', function(e) { e.preventDefault(); dropZone.style.borderColor = 'var(--border)'; dropZone.style.background = 'transparent'; });
    dropZone.addEventListener('drop', function(e) { e.preventDefault(); dropZone.style.borderColor = 'var(--border)'; dropZone.style.background = 'transparent'; if (e.dataTransfer.files.length > 0) handleDragDrop(e.dataTransfer.files[0]); });

    function handleDragDrop(file) {
        if (!file.type.startsWith('image/')) return alert('Please drop an image file.');
        const reader = new FileReader();
        reader.onload = function(e) { previewImage.src = e.target.result; previewContainer.style.display = 'block'; const dt = new DataTransfer(); dt.items.add(file); fileInput.files = dt.files; };
        reader.readAsDataURL(file);
    }

    // ===== AUDIO DRAG & DROP =====
    const audioDropZone = document.getElementById('audioDropZone'); const audioInputFallback = document.getElementById('audioInput'); const audioPreviewContainer = document.getElementById('audioPreviewContainer'); const audioPreview = document.getElementById('audioPreview');
    audioDropZone.addEventListener('click', () => audioInputFallback.click());
    audioInputFallback.addEventListener('change', function(e) { if (e.target.files.length > 0) handleAudio(e.target.files[0]); });
    audioDropZone.addEventListener('dragover', function(e) { e.preventDefault(); audioDropZone.style.borderColor = 'var(--rose)'; audioDropZone.style.background = 'rgba(219,161,162,0.1)'; });
    audioDropZone.addEventListener('dragleave', function(e) { e.preventDefault(); audioDropZone.style.borderColor = 'var(--border)'; audioDropZone.style.background = 'transparent'; });
    audioDropZone.addEventListener('drop', function(e) { e.preventDefault(); audioDropZone.style.borderColor = 'var(--border)'; audioDropZone.style.background = 'transparent'; if (e.dataTransfer.files.length > 0) handleAudio(e.dataTransfer.files[0]); });

    function handleAudio(file) {
        if (!file.type.startsWith('audio/')) return alert('Please drop an audio file.');
        const url = URL.createObjectURL(file); audioPreview.src = url; audioPreviewContainer.style.display = 'block';
        const dt = new DataTransfer(); dt.items.add(file); audioInputFallback.files = dt.files;
    }

    // ===== AUDIO RECORDER =====
    const recordBtn = document.getElementById('recordBtn'); const recordingStatus = document.getElementById('recordingStatus'); const recordingInput = document.getElementById('recordingInput'); const recordingPreviewContainer = document.getElementById('recordingPreviewContainer'); const recordingPreview = document.getElementById('recordingPreview');
    let audioRecorder = { mediaRecorder: null, chunks: [], stream: null, blob: null };

    recordBtn.addEventListener('click', async function() {
        if (audioRecorder.mediaRecorder && audioRecorder.mediaRecorder.state === 'recording') {
            audioRecorder.mediaRecorder.stop(); recordingStatus.style.display = 'none';
            recordBtn.textContent = '🎙️ Start Recording'; recordBtn.classList.remove('btn-danger'); recordBtn.classList.add('btn-secondary'); return;
        }
        try {
            audioRecorder.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioRecorder.mediaRecorder = new MediaRecorder(audioRecorder.stream); audioRecorder.chunks = [];
            audioRecorder.mediaRecorder.ondataavailable = event => { audioRecorder.chunks.push(event.data); };
            audioRecorder.mediaRecorder.onstop = () => {
                audioRecorder.blob = new Blob(audioRecorder.chunks, { type: 'audio/webm' });
                const file = new File([audioRecorder.blob], 'poem_recording.webm', { type: 'audio/webm' });
                const dt = new DataTransfer(); dt.items.add(file); recordingInput.files = dt.files;
                const url = URL.createObjectURL(file); recordingPreview.src = url; recordingPreview.load(); recordingPreviewContainer.style.display = 'block'; document.getElementById('recordingForm').style.display = 'block';
                recordBtn.textContent = '🎙️ Record Again'; recordBtn.classList.remove('btn-danger'); recordBtn.classList.add('btn-secondary');
            };
            audioRecorder.mediaRecorder.start(); recordingStatus.style.display = 'inline'; recordBtn.textContent = '⏹️ Stop Recording'; recordBtn.classList.remove('btn-secondary'); recordBtn.classList.add('btn-danger');
        } catch (error) { alert('Microphone access denied.'); console.error('Recording error:', error); }
    });

    function resetAudioRecorder() {
        if (audioRecorder.stream) { audioRecorder.stream.getTracks().forEach(track => track.stop()); audioRecorder.stream = null; }
        audioRecorder.mediaRecorder = null; audioRecorder.chunks = []; audioRecorder.blob = null;
        recordingPreviewContainer.style.display = 'none'; recordingPreview.src = ''; document.getElementById('recordingForm').style.display = 'none'; recordingStatus.style.display = 'none';
        recordBtn.textContent = '🎙️ Start Recording'; recordBtn.classList.remove('btn-danger'); recordBtn.classList.add('btn-secondary');
    }

    // ===== BULK ACTIONS =====
    const selectAllRows = document.getElementById('selectAllRows'); const rowCheckboxes = document.querySelectorAll('.row-select'); const executeBulkBtn = document.getElementById('executeBulkAction'); const bulkActionSelect = document.getElementById('bulkActionSelect');
    selectAllRows?.addEventListener('change', function() { rowCheckboxes.forEach(cb => cb.checked = this.checked); updateBulkButton(); });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() { const checked = document.querySelectorAll('.row-select:checked').length; executeBulkBtn.disabled = (checked === 0); }
    
    executeBulkBtn?.addEventListener('click', function() {
        const action = bulkActionSelect.value; const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || ids.length === 0) return alert('Please select an action and at least one poem.');
        if (!confirm(`Apply "${action}" to ${ids.length} poem(s)? This cannot be undone.`)) return;
        document.getElementById('bulkActionInput').value = action; document.getElementById('selectedIdsInput').value = ids.join(','); document.getElementById('bulkForm').submit();
    });
});
</script>

<style>
/* ===== BRAND VARIABLES (AngelWrites) ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); }

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS ===== */
.btn { display:inline-flex; align-items:center; gap:8px; padding:12px 28px; border-radius:50px; font-weight:700; font-size:0.95rem; border:none; cursor:pointer; text-decoration:none; transition:all var(--transition); box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px; }
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-block { width:100%; justify-content:center; }
.btn-danger { background:#dc3545; color:white; border:2px solid #dc3545; }
.btn-danger:hover { background:#c82333; border-color:#c82333; }
.btn-success { background:#28a745; color:white; border:2px solid #28a745; }
.btn-success:hover { background:#218838; border-color:#218838; }
.btn-info { background:#17a2b8; color:white; border:2px solid #17a2b8; }
.btn-info:hover { background:#138496; border-color:#138496; }
.btn-warning { background:#ffc107; color:#212529; border:2px solid #ffc107; }
.btn-warning:hover { background:#e0a800; border-color:#e0a800; }

/* ===== ADMIN PAGE ===== */
.admin-page { padding:32px 0 60px; }
.admin-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px; }
.admin-header h1 { margin:0; font-size:2rem; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.admin-actions { display:flex; gap:12px; flex-wrap:wrap; }

/* ===== SEARCH BAR ===== */
.search-bar { margin-bottom:24px; }
.search-form { display:flex; gap:8px; flex-wrap:wrap; }
.search-form input { flex:1; min-width:200px; padding:12px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.95rem; background:var(--card-bg); color:var(--text); transition:border-color 0.2s; }
.search-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding:8px 20px; font-size:0.85rem; border-radius:50px; }

/* ===== TOAST NOTIFICATIONS ===== */
#toastContainer { position: fixed; top: 30px; right: 30px; z-index: 9999999; display: flex; flex-direction: column; gap: 12px; align-items: flex-end; pointer-events: none; }
.toast-notification { background: #fff; padding: 16px 20px; border-radius: 12px; box-shadow: 0 10px 40px rgba(44,30,30,0.15); display: flex; align-items: center; gap: 12px; border-left: 6px solid #28a745; font-size: 0.95rem; animation: slideInRight 0.4s ease forwards; pointer-events: auto; min-width: 280px; max-width: 450px; border: 1px solid rgba(0,0,0,0.05); color: var(--text); }
.toast-notification.toast-error { border-left-color: #dc3545; }
.toast-notification .toast-icon { font-size: 1.2rem; }
.toast-notification .toast-message { flex: 1; font-weight: 500; }
.toast-notification .toast-close { cursor: pointer; color: var(--text-light); font-size: 1.2rem; line-height: 1; transition: color 0.2s; }
.toast-notification .toast-close:hover { color: var(--dark); }
@keyframes slideInRight { from { opacity: 0; transform: translateX(40px); } to { opacity: 1; transform: translateX(0); } }

/* ===== STATUS BADGE ===== */
.status-badge { padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; }
.status-published { background: #d4edda; color: #155724; }
.status-draft { background: #fff3cd; color: #856404; }

/* ===== CARD ===== */
.card { background:var(--card-bg); border-radius:20px; border:1px solid var(--border); box-shadow:var(--shadow); overflow:hidden; margin-bottom:24px; transition:all var(--transition); }
.card:hover { box-shadow:var(--shadow-hover); }
.card-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:var(--vanilla); }
.card-header h2 { font-size:1.3rem; margin:0; font-family:'Playfair Display',Georgia,serif; color:var(--dark); display:flex; align-items:center; gap:8px; }
.card-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
.card-body { padding:24px; }

/* ===== TABLE ===== */
.table-responsive { overflow-x:auto; border-radius:12px; border:1px solid var(--border); }
.admin-table { width:100%; border-collapse:separate; border-spacing:0; }
.admin-table thead { background:var(--vanilla); }
.admin-table th { text-align:left; padding:14px 20px; font-weight:600; color:var(--text); border-bottom:2px solid var(--border); font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px; }
.admin-table td { padding:14px 20px; border-bottom:1px solid var(--border); vertical-align:middle; color:var(--text); font-size:0.9rem; }
.admin-table tbody tr:hover { background:rgba(219,161,162,0.06); transition:background 0.2s; }
.styled-checkbox { width:18px; height:18px; accent-color:var(--rose); cursor:pointer; }
.actions { display:flex; gap:4px; flex-wrap:wrap; }

/* ===== EMPTY STATE ===== */
.no-items { text-align:center; padding:40px 20px; color:var(--text-light); font-size:0.95rem; }

/* ===== MODAL ===== */
.modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(30,20,20,0.6); backdrop-filter:blur(6px); display:none; align-items:center; justify-content:center; z-index:999999; }
.modal-content { background:var(--card-bg); border-radius:24px; padding:32px; width:90%; max-width:800px; max-height:90vh; overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,0.35); border:1px solid var(--rose-light); }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
.modal-header h2 { margin:0; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.modal-close { background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-light); transition:color 0.2s; }
.modal-close:hover { color:var(--rose); }

/* ===== PREVIEW MODAL ENHANCEMENTS ===== */
.preview-book h1 { margin-top: 0; }
.preview-close:hover { color: var(--rose); transform: rotate(90deg); transition: transform 0.3s; }
.preview-body::-webkit-scrollbar { width: 8px; }
.preview-body::-webkit-scrollbar-track { background: var(--fantasy); }
.preview-body::-webkit-scrollbar-thumb { background: var(--rose); border-radius: 10px; }

/* ===== FORM & UPLOADS ===== */
.poem-form .form-group { margin-bottom:16px; }
.poem-form label { display:block; font-weight:600; margin-bottom:6px; font-size:0.9rem; color:var(--text); }
.poem-form .required { color:#dc3545; }
.poem-form input, .poem-form textarea { width:100%; padding:12px 16px; border:1px solid var(--border); border-radius:12px; font-size:0.95rem; background:var(--input-bg); color:var(--text); transition:border-color 0.2s; }
.poem-form input:focus, .poem-form textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.poem-form textarea { resize:vertical; min-height:60px; font-family:'Inter',sans-serif; }
.poem-form .form-actions { display:flex; gap:12px; margin-top:16px; }
.poem-form .form-actions .btn { min-width:120px; justify-content:center; }
.camera-trigger-group { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.status-indicator { font-size:0.9rem; color:var(--text-light); font-weight:500; }
.upload-zone { border:2px dashed var(--border); border-radius:16px; padding:30px; text-align:center; cursor:pointer; transition:all 0.3s; background:var(--fantasy); }
.upload-zone i { font-size:2.5rem; color:var(--rose); margin-bottom:8px; display:block; }
.upload-zone p { margin:0; color:var(--text-light); }
.upload-zone:hover { border-color:var(--rose); background:rgba(219,161,162,0.05); }
.recorder-section { background:var(--fantasy); border-radius:16px; padding:16px; margin-top:12px; border:1px solid var(--border); }
.recorder-section h4 { margin-bottom:8px; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.recorder-controls { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
.recorder-controls .btn { padding:8px 16px; border-radius:50px; }
#recordingStatus { font-weight:600; color:#e74c3c; }

/* ===== FULL-SCREEN CAMERA MODAL ===== */
.camera-modal { position:fixed; top:0; left:0; width:100%; height:100%; background:#000; z-index:999999; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.camera-modal-inner { width:100%; height:100%; position:relative; display:flex; flex-direction:column; background:#000; }
.camera-preview-wrapper { flex:1; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:#000; overflow:hidden; }
.camera-preview-wrapper video { width:100%; height:100%; object-fit:cover; }
.camera-top-bar { position:absolute; top:0; left:0; right:0; padding:20px; background:linear-gradient(to bottom,rgba(0,0,0,0.6) 0%,transparent 100%); display:flex; justify-content:space-between; align-items:center; z-index:2; }
.camera-close-btn { background:rgba(255,255,255,0.2); border:none; color:#fff; font-size:1.5rem; width:44px; height:44px; border-radius:50%; cursor:pointer; transition:background 0.2s; display:flex; align-items:center; justify-content:center; }
.camera-close-btn:hover { background:rgba(255,255,255,0.3); }
.camera-mode-switch { display:flex; gap:4px; background:rgba(255,255,255,0.2); border-radius:30px; padding:4px; }
.camera-mode-switch .mode-btn { background:transparent; border:none; color:rgba(255,255,255,0.6); padding:8px 20px; border-radius:26px; font-size:0.9rem; font-weight:600; cursor:pointer; transition:all 0.2s; }
.camera-mode-switch .mode-btn.active { background:rgba(255,255,255,0.9); color:#000; }
.camera-bottom-controls { position:absolute; bottom:30px; left:0; right:0; padding:0 20px; display:flex; justify-content:space-between; align-items:center; z-index:2; background:linear-gradient(to top,rgba(0,0,0,0.6) 0%,transparent 100%); padding-top:30px; padding-bottom:30px; }
.camera-controls-left, .camera-controls-right { flex:0 0 80px; display:flex; justify-content:center; }
.camera-controls-center { flex:1; display:flex; justify-content:center; }
.camera-shutter-btn { width:72px; height:72px; border-radius:50%; border:4px solid rgba(255,255,255,0.8); background:transparent; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; transition:all 0.2s; }
.camera-shutter-btn .shutter-ring { position:absolute; width:100%; height:100%; border-radius:50%; border:4px solid rgba(255,255,255,0.3); top:-4px; left:-4px; pointer-events:none; }
.camera-shutter-btn .shutter-inner { width:56px; height:56px; border-radius:50%; background:#fff; transition:all 0.2s; }
.camera-shutter-btn:hover .shutter-inner { opacity:0.8; }
.camera-shutter-btn.recording .shutter-inner { background:#ff3b30; width:24px; height:24px; border-radius:6px; }
.camera-btn { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:8px 16px; border-radius:30px; font-size:0.85rem; cursor:pointer; transition:all 0.2s; display:flex; align-items:center; gap:6px; }
.camera-btn:disabled { opacity:0.4; cursor:not-allowed; }
.camera-btn:hover:not(:disabled) { background:rgba(255,255,255,0.3); }
.camera-status { position:absolute; bottom:110px; left:50%; transform:translateX(-50%); color:#fff; font-size:1rem; font-weight:500; text-shadow:0 0 20px rgba(0,0,0,0.5); z-index:2; background:rgba(0,0,0,0.5); padding:6px 16px; border-radius:20px; backdrop-filter:blur(4px); }

/* ===== BUTTON LOADING STATE ===== */
.btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) { .admin-header { flex-direction:column; align-items:stretch; text-align:center; } .admin-actions { justify-content:center; } .modal-content { padding:24px; } }
@media (max-width:480px) { .search-form { flex-direction:column; } .search-form input { width:100%; } .camera-bottom-controls { bottom:16px; padding:0 12px; padding-bottom:16px; } .camera-shutter-btn { width:64px; height:64px; } .camera-shutter-btn .shutter-inner { width:48px; height:48px; } .camera-btn { font-size:0.75rem; padding:6px 12px; } }
/* ===== TOAST NOTIFICATIONS ===== */
#toastContainer {
    position: fixed; top: 30px; right: 30px; z-index: 9999999;
    display: flex; flex-direction: column; gap: 12px; align-items: flex-end; pointer-events: none;
}
.toast-notification {
    background: #fff; padding: 16px 20px; border-radius: 12px; box-shadow: 0 10px 40px rgba(44,30,30,0.15);
    display: flex; align-items: center; gap: 12px; border-left: 6px solid #28a745; font-size: 0.95rem;
    animation: slideInRight 0.4s ease forwards; pointer-events: auto; min-width: 280px; max-width: 450px;
    border: 1px solid rgba(0,0,0,0.05); color: var(--text);
}
.toast-notification.toast-error { border-left-color: #dc3545; }
.toast-notification .toast-icon { font-size: 1.2rem; }
.toast-notification .toast-message { flex: 1; font-weight: 500; }
.toast-notification .toast-close { cursor: pointer; color: var(--text-light); font-size: 1.2rem; line-height: 1; transition: color 0.2s; }
.toast-notification .toast-close:hover { color: var(--dark); }
@keyframes slideInRight { from { opacity: 0; transform: translateX(40px); } to { opacity: 1; transform: translateX(0); } }

/* ===== STATUS BADGE ===== */
.status-badge { padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; }
.status-published { background: #d4edda; color: #155724; }
.status-draft { background: #fff3cd; color: #856404; }

/* ===== PREVIEW MODAL ENHANCEMENTS ===== */
.preview-book h1 { margin-top: 0; }
.preview-close:hover { color: var(--rose); transform: rotate(90deg); transition: transform 0.3s; }
.preview-body::-webkit-scrollbar { width: 8px; }
.preview-body::-webkit-scrollbar-track { background: var(--fantasy); }
.preview-body::-webkit-scrollbar-thumb { background: var(--rose); border-radius: 10px; }

/* ===== BUTTON LOADING STATE ===== */
.btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }

/* ===== CHECKBOX & BULK STYLES ===== */
.styled-checkbox { width: 18px; height: 18px; accent-color: var(--rose); cursor: pointer; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.06); transition: background 0.2s; }
</style>

<?php require_once '../includes/footer.php'; ?>