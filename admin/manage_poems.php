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

            <!-- ===== CAMERA & IMAGE ===== -->
            <div class="form-group">
                <label>Image (Live Photo or Upload)</label>
                <div class="camera-trigger-group">
                    <button type="button" id="openCameraBtn" class="btn btn-secondary">
                        <i class="fas fa-camera"></i> Open Camera (Photo)
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
                <p class="field-hint">Record your poem directly in the browser. The recording will be saved when you submit the form.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Poem</button>
                <button type="button" class="btn btn-outline modal-close">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== FULL-SCREEN CAMERA MODAL ===== -->
<div id="cameraModal" class="camera-modal" style="display:none;">
    <div class="camera-modal-inner">
        <div class="camera-preview-wrapper">
            <video id="cameraPreview" autoplay muted playsinline></video>
        </div>
        
        <!-- Top Bar -->
        <div class="camera-top-bar">
            <button type="button" class="camera-close-btn" id="cameraCloseBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="camera-mode-switch">
                <button type="button" class="mode-btn active" data-mode="photo">📷 Photo</button>
                <button type="button" class="mode-btn" data-mode="video">🎥 Video</button>
            </div>
        </div>

        <!-- Bottom Controls -->
        <div class="camera-bottom-controls">
            <div class="camera-controls-left">
                <button type="button" id="retakeMediaBtn" class="camera-btn" disabled>
                    <i class="fas fa-redo"></i> Retake
                </button>
            </div>
            <div class="camera-controls-center">
                <button type="button" id="captureBtn" class="camera-shutter-btn">
                    <span class="shutter-ring"></span>
                    <span class="shutter-inner"></span>
                </button>
            </div>
            <div class="camera-controls-right">
                <button type="button" id="confirmMediaBtn" class="camera-btn" disabled>
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>

        <!-- Status / Timer -->
        <div id="cameraStatus" class="camera-status">Ready</div>
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

    // ============================================================
    // FULL-SCREEN CAMERA MODAL
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

    // For video, we map to audio input (or use a hidden video input)
    const audioInput = document.getElementById('audioInput');

    let cameraStream = null;
    let mediaRecorder = null;
    let recordedChunks = [];
    let capturedBlob = null;
    let recordedBlob = null;
    let currentMode = 'photo';

    // ===== OPEN CAMERA =====
    function openCamera(mode) {
        currentMode = mode;
        cameraModal.style.display = 'flex';
        cameraStatus.textContent = 'Starting camera...';

        modeBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        if (mode === 'photo') {
            captureBtn.classList.remove('recording');
            captureBtn.querySelector('.shutter-inner').style.borderRadius = '50%';
        } else {
            captureBtn.classList.remove('recording');
            captureBtn.querySelector('.shutter-inner').style.borderRadius = '50%';
        }

        retakeBtn.disabled = true;
        confirmBtn.disabled = true;
        capturedBlob = null;
        recordedBlob = null;
        recordedChunks = [];

        startCameraStream();
    }

    function closeCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        cameraPreview.srcObject = null;
        cameraPreview.src = '';
        cameraModal.style.display = 'none';
    }

    async function startCameraStream() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                cameraStatus.textContent = '❌ Camera not supported';
                return;
            }
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: currentMode === 'video' });
            cameraPreview.srcObject = cameraStream;
            cameraStatus.textContent = currentMode === 'photo' ? 'Ready' : 'Ready to record';
        } catch (error) {
            cameraStatus.textContent = '❌ Camera access denied: ' + error.message;
        }
    }

    // ===== CAPTURE PHOTO =====
    function capturePhoto() {
        if (!cameraStream) return;
        const canvas = document.createElement('canvas');
        canvas.width = cameraPreview.videoWidth || 1280;
        canvas.height = cameraPreview.videoHeight || 720;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            capturedBlob = blob;
            retakeBtn.disabled = false;
            confirmBtn.disabled = false;
            cameraStatus.textContent = '✅ Photo captured';
            cameraStream.getTracks().forEach(track => track.stop());
            cameraPreview.srcObject = null;
        }, 'image/jpeg');
    }

    // ===== VIDEO RECORDING =====
    function startRecording() {
        if (!cameraStream) return;
        recordedChunks = [];
        mediaRecorder = new MediaRecorder(cameraStream);
        mediaRecorder.ondataavailable = function(e) {
            if (e.data.size > 0) recordedChunks.push(e.data);
        };
        mediaRecorder.onstop = function() {
            const blob = new Blob(recordedChunks, { type: 'video/webm' });
            if (blob.size > 0) {
                recordedBlob = blob;
                retakeBtn.disabled = false;
                confirmBtn.disabled = false;
                cameraStatus.textContent = '✅ Recording complete';
                cameraStream.getTracks().forEach(track => track.stop());
                cameraPreview.srcObject = null;
            }
        };
        mediaRecorder.start();
        captureBtn.classList.add('recording');
        captureBtn.querySelector('.shutter-inner').style.borderRadius = '6px';
        cameraStatus.textContent = '🔴 Recording...';
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
            captureBtn.classList.remove('recording');
        }
    }

    // ===== RETAKES =====
    function retakeMedia() {
        capturedBlob = null;
        recordedBlob = null;
        recordedChunks = [];
        retakeBtn.disabled = true;
        confirmBtn.disabled = true;
        cameraStatus.textContent = 'Retaking...';
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        startCameraStream();
    }

    // ===== CONFIRM MEDIA =====
    function confirmMedia() {
        if (currentMode === 'photo' && capturedBlob) {
            const file = new File([capturedBlob], 'live_photo.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            livePhotoInput.files = dt.files;
            photoStatus.textContent = '✅ Photo confirmed!';
            photoStatus.style.color = '#2ecc71';
            closeCamera();
        } else if (currentMode === 'video' && recordedBlob) {
            const file = new File([recordedBlob], 'live_video.webm', { type: 'video/webm' });
            const dt = new DataTransfer();
            dt.items.add(file);
            audioInput.files = dt.files;
            document.getElementById('audioPreviewContainer').style.display = 'block';
            const url = URL.createObjectURL(file);
            document.getElementById('audioPreview').src = url;
            closeCamera();
            alert('✅ Video captured and saved as audio!');
        }
    }

    // ===== EVENT LISTENERS =====
    openCameraBtn.addEventListener('click', function() { openCamera('photo'); });
    cameraCloseBtn.addEventListener('click', closeCamera);

    captureBtn.addEventListener('click', function() {
        if (currentMode === 'photo') {
            capturePhoto();
        } else {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                stopRecording();
            } else {
                startRecording();
            }
        }
    });

    retakeBtn.addEventListener('click', retakeMedia);
    confirmBtn.addEventListener('click', confirmMedia);

    modeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const mode = this.dataset.mode;
            if (mode === currentMode) return;
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            closeCamera();
            setTimeout(() => openCamera(mode), 200);
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && cameraModal.style.display !== 'none') {
            closeCamera();
        }
    });

    function resetCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        cameraPreview.srcObject = null;
        cameraPreview.src = '';
        cameraModal.style.display = 'none';
        livePhotoInput.value = '';
        photoStatus.textContent = 'No photo captured';
        photoStatus.style.color = 'var(--text-light)';
        // Reset video input if it was used
        audioInput.value = '';
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
    const audioInputFallback = document.getElementById('audioInput');
    const audioPreviewContainer = document.getElementById('audioPreviewContainer');
    const audioPreview = document.getElementById('audioPreview');

    audioDropZone.addEventListener('click', () => audioInputFallback.click());
    audioInputFallback.addEventListener('change', function(e) {
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
        audioInputFallback.files = dt.files;
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

/* ===== CAMERA TRIGGER ===== */
.camera-trigger-group { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.status-indicator { font-size: 0.9rem; color: var(--text-light); font-weight: 500; }

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

/* ===== FULL-SCREEN CAMERA MODAL ===== */
.camera-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: #000;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.camera-modal-inner {
    width: 100%;
    height: 100%;
    position: relative;
    display: flex;
    flex-direction: column;
    background: #000;
}

.camera-preview-wrapper {
    flex: 1;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #000;
    overflow: hidden;
}

.camera-preview-wrapper video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.camera-top-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    padding: 20px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.6) 0%, transparent 100%);
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 2;
}

.camera-close-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    font-size: 1.5rem;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.camera-close-btn:hover { background: rgba(255,255,255,0.3); }

.camera-mode-switch {
    display: flex;
    gap: 4px;
    background: rgba(255,255,255,0.2);
    border-radius: 30px;
    padding: 4px;
}

.camera-mode-switch .mode-btn {
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.6);
    padding: 8px 20px;
    border-radius: 26px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.camera-mode-switch .mode-btn.active {
    background: rgba(255,255,255,0.9);
    color: #000;
}

.camera-bottom-controls {
    position: absolute;
    bottom: 30px;
    left: 0;
    right: 0;
    padding: 0 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 2;
    background: linear-gradient(to top, rgba(0,0,0,0.6) 0%, transparent 100%);
    padding-top: 30px;
    padding-bottom: 30px;
}

.camera-controls-left,
.camera-controls-right {
    flex: 0 0 80px;
    display: flex;
    justify-content: center;
}

.camera-controls-center {
    flex: 1;
    display: flex;
    justify-content: center;
}

.camera-shutter-btn {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.8);
    background: transparent;
    cursor: pointer;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.camera-shutter-btn .shutter-ring {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.3);
    top: -4px;
    left: -4px;
    pointer-events: none;
}

.camera-shutter-btn .shutter-inner {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #fff;
    transition: all 0.2s;
}

.camera-shutter-btn:hover .shutter-inner { opacity: 0.8; }

.camera-shutter-btn.recording .shutter-inner {
    background: #ff3b30;
    width: 24px;
    height: 24px;
    border-radius: 6px;
}

.camera-btn {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.camera-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.camera-btn:hover:not(:disabled) { background: rgba(255,255,255,0.3); }

.camera-status {
    position: absolute;
    bottom: 110px;
    left: 50%;
    transform: translateX(-50%);
    color: #fff;
    font-size: 1rem;
    font-weight: 500;
    text-shadow: 0 0 20px rgba(0,0,0,0.5);
    z-index: 2;
    background: rgba(0,0,0,0.5);
    padding: 6px 16px;
    border-radius: 20px;
    backdrop-filter: blur(4px);
}

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
    .camera-bottom-controls { bottom: 16px; padding: 0 12px; padding-bottom: 16px; }
    .camera-shutter-btn { width: 64px; height: 64px; }
    .camera-shutter-btn .shutter-inner { width: 48px; height: 48px; }
    .camera-btn { font-size: 0.75rem; padding: 6px 12px; }
}
</style>

<?php require_once '../includes/footer.php'; ?>