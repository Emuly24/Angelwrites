<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

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

// ===== FETCH POEMS WITH SEARCH =====
$sql = "SELECT * FROM poems";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE title LIKE ? OR intro LIKE ? OR content LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC";
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

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

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
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
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
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Image</th>
                                        <th>Title</th>
                                        <th>Introduction</th>
                                        <th>Audio</th>
                                        <th>Views</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($poems as $poem): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $poem['id']; ?>"></td>
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
                                            <td><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></td>
                                            <td class="actions">
                                                <button class="btn btn-sm btn-secondary edit-btn" 
                                                        data-id="<?php echo $poem['id']; ?>" 
                                                        data-title="<?php echo htmlspecialchars($poem['title']); ?>" 
                                                        data-intro="<?php echo htmlspecialchars($poem['intro'] ?? ''); ?>" 
                                                        data-content="<?php echo htmlspecialchars($poem['content'] ?? ''); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
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
                    </form>
                <?php else: ?>
                    <p class="no-items">No poems yet. Click "Add New Poem" to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== ADD/EDIT POEM MODAL ===== -->
<div id="poemModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Poem</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="poem-form" id="poemForm">
            <input type="hidden" name="poem_id" id="poem_id" value="0">
            <input type="hidden" name="save_poem" value="1">
            
            <div class="form-group">
                <label for="title">Title <span class="required">*</span></label>
                <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-group">
                <label for="intro">Introduction / Purpose</label>
                <textarea id="intro" name="intro" rows="3" placeholder="Write a short introduction explaining the purpose or inspiration behind this poem..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="content">Content <span class="required">*</span></label>
                <textarea id="editor" name="content" rows="10"></textarea>
            </div>

            <!-- ===== LIVE PHOTO CAPTURE ===== -->
            <div class="form-group">
                <label>Live Photo (capture with camera)</label>
                <div class="camera-section">
                    <div class="camera-preview-container">
                        <video id="cameraPreview" autoplay muted playsinline></video>
                        <div class="camera-placeholder" id="cameraPlaceholder">
                            <i class="fas fa-camera"></i>
                            <p>Camera preview will appear here.</p>
                        </div>
                    </div>
                    <div class="camera-controls">
                        <button type="button" id="startCameraBtn" class="btn btn-secondary btn-sm">
                            <i class="fas fa-camera"></i> Start Camera
                        </button>
                        <button type="button" id="capturePhotoBtn" class="btn btn-primary btn-sm" disabled>
                            <i class="fas fa-camera-retro"></i> Capture
                        </button>
                        <button type="button" id="retakePhotoBtn" class="btn btn-warning btn-sm" disabled>
                            <i class="fas fa-redo"></i> Retake
                        </button>
                        <button type="button" id="confirmPhotoBtn" class="btn btn-success btn-sm" disabled>
                            <i class="fas fa-check"></i> Use This Photo
                        </button>
                        <span id="cameraStatus" class="status-indicator">Camera ready</span>
                    </div>
                    <div class="captured-photo-container" id="capturedPhotoContainer" style="display:none;">
                        <img id="capturedPhotoPreview" style="max-width:200px; max-height:200px; border-radius:8px;">
                    </div>
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
                <p class="field-hint">Record your poem directly in the browser. The recording will be saved when you submit the form.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Poem</button>
                <button type="button" class="btn btn-outline modal-close">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== TINYMCE & JAVASCRIPT ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let editorInitialized = false;
    let editingId = 0;

    // ===== MODAL LOGIC =====
    const modal = document.getElementById('poemModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeButtons = document.querySelectorAll('.modal-close');
    const addBtn = document.getElementById('showAddModal');
    const editBtns = document.querySelectorAll('.edit-btn');
    const cancelBtn = document.querySelector('.modal-close');

    function openModal(title, data) {
        modalTitle.textContent = title;
        modal.style.display = 'flex';
        initTinyMCE();
        if (data) {
            document.getElementById('poem_id').value = data.id;
            document.getElementById('title').value = data.title;
            document.getElementById('intro').value = data.intro;
            tinymce.get('editor').setContent(data.content);
        } else {
            document.getElementById('poem_id').value = 0;
            document.getElementById('title').value = '';
            document.getElementById('intro').value = '';
            tinymce.get('editor').setContent('');
        }
        resetCamera();
        resetAudioRecorder();
    }

    addBtn.addEventListener('click', function() { openModal('Add New Poem', null); });
    editBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const data = {
                id: this.dataset.id,
                title: this.dataset.title,
                intro: this.dataset.intro,
                content: this.dataset.content
            };
            openModal('Edit Poem', data);
        });
    });
    closeButtons.forEach(btn => btn.addEventListener('click', function() { modal.style.display = 'none'; }));
    window.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });

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
                editor.on('change', function() { tinymce.triggerSave(); });
            }
        });
        editorInitialized = true;
    }

    // ===== CAMERA (LIVE PHOTO) =====
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraPlaceholder = document.getElementById('cameraPlaceholder');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const capturePhotoBtn = document.getElementById('capturePhotoBtn');
    const retakePhotoBtn = document.getElementById('retakePhotoBtn');
    const confirmPhotoBtn = document.getElementById('confirmPhotoBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const capturedPhotoContainer = document.getElementById('capturedPhotoContainer');
    const capturedPhotoPreview = document.getElementById('capturedPhotoPreview');
    const livePhotoInput = document.getElementById('livePhotoInput');

    let cameraStream = null;
    let capturedBlob = null;

    async function startCamera() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Your browser does not support camera access.');
                return;
            }
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            cameraPreview.srcObject = cameraStream;
            cameraPreview.style.display = 'block';
            cameraPlaceholder.style.display = 'none';
            startCameraBtn.disabled = true;
            capturePhotoBtn.disabled = false;
            cameraStatus.textContent = 'Camera active';
            cameraStatus.style.color = '#27ae60';
        } catch (error) {
            alert('Camera access denied: ' + error.message);
        }
    }

    function capturePhoto() {
        if (!cameraStream) return;
        const canvas = document.createElement('canvas');
        canvas.width = cameraPreview.videoWidth;
        canvas.height = cameraPreview.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            capturedBlob = blob;
            const url = URL.createObjectURL(blob);
            capturedPhotoPreview.src = url;
            capturedPhotoContainer.style.display = 'block';
            capturePhotoBtn.disabled = true;
            retakePhotoBtn.disabled = false;
            confirmPhotoBtn.disabled = false;
            cameraStatus.textContent = 'Photo captured';
            cameraStatus.style.color = '#3498db';
            cameraStream.getTracks().forEach(track => track.stop());
            cameraPreview.srcObject = null;
            cameraPreview.style.display = 'none';
            cameraPlaceholder.style.display = 'flex';
            startCameraBtn.disabled = false;
        }, 'image/jpeg');
    }

    function retakePhoto() {
        capturedBlob = null;
        capturedPhotoContainer.style.display = 'none';
        capturedPhotoPreview.src = '';
        capturePhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        confirmPhotoBtn.disabled = true;
        cameraStatus.textContent = 'Camera ready';
        cameraStatus.style.color = 'var(--text-light)';
        startCameraBtn.disabled = false;
    }

    function confirmPhoto() {
        if (!capturedBlob) return;
        const file = new File([capturedBlob], 'live_photo.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        livePhotoInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);

    function resetCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        cameraPreview.srcObject = null;
        cameraPreview.style.display = 'none';
        cameraPlaceholder.style.display = 'flex';
        startCameraBtn.disabled = false;
        capturePhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        confirmPhotoBtn.disabled = true;
        capturedPhotoContainer.style.display = 'none';
        capturedPhotoPreview.src = '';
        livePhotoInput.value = '';
        cameraStatus.textContent = 'Camera ready';
        cameraStatus.style.color = 'var(--text-light)';
    }

    // ===== DRAG & DROP IMAGE =====
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const previewContainer = document.getElementById('previewContainer');
    const previewImage = document.getElementById('previewImage');

    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) handleDragDrop(e.target.files[0]);
    });
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--rose)';
        dropZone.style.background = 'rgba(219,161,162,0.1)';
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
        if (e.dataTransfer.files.length > 0) handleDragDrop(e.dataTransfer.files[0]);
    });

    function handleDragDrop(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please drop an image file.');
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
        };
        reader.readAsDataURL(file);
    }

    // ===== AUDIO DRAG & DROP =====
    const audioDropZone = document.getElementById('audioDropZone');
    const audioInput = document.getElementById('audioInput');
    const audioPreviewContainer = document.getElementById('audioPreviewContainer');
    const audioPreview = document.getElementById('audioPreview');

    audioDropZone.addEventListener('click', () => audioInput.click());
    audioInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) handleAudio(e.target.files[0]);
    });
    audioDropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        audioDropZone.style.borderColor = 'var(--rose)';
        audioDropZone.style.background = 'rgba(219,161,162,0.1)';
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
        if (e.dataTransfer.files.length > 0) handleAudio(e.dataTransfer.files[0]);
    });

    function handleAudio(file) {
        if (!file.type.startsWith('audio/')) {
            alert('Please drop an audio file.');
            return;
        }
        const url = URL.createObjectURL(file);
        audioPreview.src = url;
        audioPreviewContainer.style.display = 'block';
        const dt = new DataTransfer();
        dt.items.add(file);
        audioInput.files = dt.files;
    }

    // ===== AUDIO RECORDER =====
    const recordBtn = document.getElementById('recordBtn');
    const recordingStatus = document.getElementById('recordingStatus');
    const recordingInput = document.getElementById('recordingInput');
    const recordingPreviewContainer = document.getElementById('recordingPreviewContainer');
    const recordingPreview = document.getElementById('recordingPreview');

    let audioRecorder = { mediaRecorder: null, chunks: [], stream: null, blob: null };

    recordBtn.addEventListener('click', async function() {
        if (audioRecorder.mediaRecorder && audioRecorder.mediaRecorder.state === 'recording') {
            audioRecorder.mediaRecorder.stop();
            recordingStatus.style.display = 'none';
            recordBtn.textContent = '🎙️ Start Recording';
            recordBtn.classList.remove('btn-danger');
            recordBtn.classList.add('btn-secondary');
            return;
        }

        try {
            audioRecorder.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioRecorder.mediaRecorder = new MediaRecorder(audioRecorder.stream);
            audioRecorder.chunks = [];

            audioRecorder.mediaRecorder.ondataavailable = event => {
                audioRecorder.chunks.push(event.data);
            };

            audioRecorder.mediaRecorder.onstop = () => {
                audioRecorder.blob = new Blob(audioRecorder.chunks, { type: 'audio/webm' });
                const file = new File([audioRecorder.blob], 'poem_recording.webm', { type: 'audio/webm' });
                const dt = new DataTransfer();
                dt.items.add(file);
                recordingInput.files = dt.files;

                const url = URL.createObjectURL(file);
                recordingPreview.src = url;
                recordingPreview.load();
                recordingPreviewContainer.style.display = 'block';
                document.getElementById('recordingForm').style.display = 'block';
                recordBtn.textContent = '🎙️ Record Again';
                recordBtn.classList.remove('btn-danger');
                recordBtn.classList.add('btn-secondary');
            };

            audioRecorder.mediaRecorder.start();
            recordingStatus.style.display = 'inline';
            recordBtn.textContent = '⏹️ Stop Recording';
            recordBtn.classList.remove('btn-secondary');
            recordBtn.classList.add('btn-danger');
        } catch (error) {
            alert('Microphone access denied or not available.');
            console.error('Recording error:', error);
        }
    });

    function resetAudioRecorder() {
        if (audioRecorder.stream) {
            audioRecorder.stream.getTracks().forEach(track => track.stop());
            audioRecorder.stream = null;
        }
        audioRecorder.mediaRecorder = null;
        audioRecorder.chunks = [];
        audioRecorder.blob = null;
        recordingPreviewContainer.style.display = 'none';
        recordingPreview.src = '';
        document.getElementById('recordingForm').style.display = 'none';
        recordingStatus.style.display = 'none';
        recordBtn.textContent = '🎙️ Start Recording';
        recordBtn.classList.remove('btn-danger');
        recordBtn.classList.add('btn-secondary');
    }

    // ===== BULK ACTIONS =====
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows?.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn?.addEventListener('click', function() {
        const action = bulkActionSelect.value;
        const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || ids.length === 0) {
            alert('Please select an action and at least one poem.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} poem(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = ids.join(',');
        document.getElementById('bulkForm').submit();
    });
});
</script>

<style>
/* ===== ADMIN LAYOUT ===== */
.admin-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
.admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 20px; }

/* ===== MODAL ===== */
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 2000; }
.modal-content { background: var(--card-bg); border-radius: 16px; padding: 32px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text); transition: color 0.2s; }
.modal-close:hover { color: var(--rose); }

/* ===== CAMERA SECTION ===== */
.camera-section { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: var(--fantasy); margin-top: 8px; }
.camera-preview-container { width: 100%; max-width: 400px; height: 220px; background: var(--vanilla); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; margin: 0 auto; }
.camera-preview-container video { width: 100%; height: 100%; object-fit: cover; display: none; }
.camera-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-light); text-align: center; padding: 24px; }
.camera-placeholder i { font-size: 2.5rem; margin-bottom: 8px; color: var(--rose); }
.camera-placeholder p { margin: 0; font-size: 0.9rem; }
.camera-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center; margin-top: 12px; }
.camera-controls .btn { padding: 6px 14px; font-size: 0.85rem; }
.captured-photo-container { text-align: center; margin-top: 12px; }
.captured-photo-container img { border: 2px solid var(--rose); border-radius: 8px; }
.status-indicator { font-size: 0.85rem; color: var(--text-light); margin-left: 8px; font-weight: 500; }

/* ===== UPLOAD ZONES ===== */
.upload-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--fantasy); }
.upload-zone i { font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block; }
.upload-zone p { margin: 0; color: var(--text-light); }
.upload-zone:hover { border-color: var(--rose); background: rgba(219, 161, 162, 0.05); }

/* ===== RECORDER SECTION ===== */
.recorder-section { background: var(--fantasy); border-radius: 12px; padding: 16px; margin-top: 12px; border: 1px solid var(--border); }
.recorder-section h4 { margin-bottom: 8px; }
.recorder-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.recorder-controls .btn { padding: 8px 16px; }
#recordingStatus { font-weight: 600; color: #e74c3c; }
.recorder-section audio, .recorder-section video { width: 100%; border-radius: 8px; margin-top: 8px; background: var(--bg); }

/* ===== FORM ===== */
.poem-form .form-group { margin-bottom: 16px; }
.poem-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); }
.poem-form input, .poem-form textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.poem-form input:focus, .poem-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.poem-form textarea { resize: vertical; min-height: 60px; }
.poem-form .form-actions { display: flex; gap: 12px; margin-top: 16px; }
.poem-form .form-actions .btn { min-width: 120px; justify-content: center; }
.required { color: #e74c3c; }

@media (max-width: 480px) {
    .modal-content { padding: 20px; }
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
}
</style>

<?php require_once '../includes/footer.php'; ?>