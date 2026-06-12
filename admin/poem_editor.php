<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

$title = '';
$intro = '';
$content = '';
$image_path = '';
$audio_path = '';

if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $poem = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($poem) {
        $title = $poem['title'];
        $intro = $poem['intro'];
        $content = $poem['content'];
        $image_path = $poem['image_path'] ?? '';
        $audio_path = $poem['audio_path'] ?? '';
    } else {
        $error = 'Poem not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $action = $_POST['action'] ?? 'save';

    $uploaded_image_path = $image_path;
    $uploaded_audio_path = $audio_path;

    // ===== LIVE PHOTO CAPTURE =====
    if (!empty($_FILES['live_photo']['name'])) {
        $upload_dir = '../assets/uploads/poems/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'live_poem_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $uploaded_image_path = 'assets/uploads/poems/' . $photo_filename;
        } else {
            $error = 'Failed to upload captured photo.';
        }
    }

    // ===== STANDARD IMAGE UPLOAD =====
    if (empty($error) && !empty($_FILES['image']['name'])) {
        $upload_dir = '../assets/uploads/poems/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $image_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['image']['name']);
        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_filename)) {
            $uploaded_image_path = 'assets/uploads/poems/' . $image_filename;
        } else {
            $error = 'Failed to upload image.';
        }
    }

    // ===== STANDARD AUDIO UPLOAD =====
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

    // ===== LIVE AUDIO RECORDING =====
    if (isset($_FILES['audio_recording']) && $_FILES['audio_recording']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $rec_filename = 'rec_' . time() . '.webm';
        if (move_uploaded_file($_FILES['audio_recording']['tmp_name'], $upload_dir . $rec_filename)) {
            $uploaded_audio_path = 'assets/uploads/audio/' . $rec_filename;
        } else {
            $error = 'Failed to upload recorded audio.';
        }
    }

    if (empty($title) || empty($content)) {
        $error = 'Title and content are required.';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ?, image_path = ?, audio_path = ? WHERE id = ?");
            $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path, $id]);
            $success = 'Poem updated successfully!';
        } else {
            $stmt = $db->prepare("INSERT INTO poems (title, intro, content, image_path, audio_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path]);
            $id = $db->lastInsertId();
            $success = 'Poem created successfully!';

            // ===== ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $subject = 'New Poem Added: ' . $title;
            $body = "A new poem has been added.\n\nTitle: $title\nIntro: $intro\n\nView poem: " . SITE_URL . "/poem_view.php?id=$id";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }

        if ($action === 'save_and_continue') {
            header('Location: ' . SITE_URL . '/admin/poem_editor.php?id=' . $id);
            exit;
        } else {
            header('Location: ' . SITE_URL . '/admin/manage_poems.php');
            exit;
        }
    }
}

$pageTitle = $id > 0 ? 'Edit Poem' : 'Add New Poem';
?>
<?php require_once '../includes/header.php'; ?>

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="poem-editor-page">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo $id > 0 ? 'Edit Poem' : 'Add New Poem'; ?></h1>
            <div class="admin-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Poems
                </a>
                <?php if ($id > 0): ?>
                    <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $id; ?>" class="btn btn-secondary" target="_blank">
                        <i class="fas fa-eye"></i> Preview
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" id="poemForm" class="poem-form" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="save">

            <div class="form-section">
                <div class="form-group">
                    <label for="title">Poem Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                </div>

                <div class="form-group">
                    <label for="intro">Purpose / Introduction</label>
                    <textarea id="intro" name="intro" rows="3" placeholder="Write a short introduction explaining the purpose or inspiration behind this poem..." class="auto-expand"><?php echo htmlspecialchars($intro); ?></textarea>
                    <small class="field-hint">This will appear before the poem. It auto-expands as you type.</small>
                </div>

                <div class="form-group">
                    <label for="content">Poem Content <span class="required">*</span></label>
                    <textarea id="editor" name="content" rows="12"><?php echo htmlspecialchars($content); ?></textarea>
                </div>
            </div>

            <div class="media-section">
                <div class="media-group">
                    <h3>Cover Image</h3>

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
                            <input type="file" id="livePhotoInput" name="live_photo" accept="image/*" style="display:none;">
                        </div>
                    </div>

                    <!-- ===== STANDARD IMAGE UPLOAD ===== -->
                    <div class="form-group">
                        <label>Or Upload Image (Drag & Drop)</label>
                        <div id="dropZone" class="upload-zone">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Drag & drop your image here, or <strong>click to browse</strong></p>
                            <input type="file" id="fileInput" name="image" accept="image/*" style="display:none;">
                            <div id="previewContainer" style="display:none; margin-top:12px;">
                                <img id="previewImage" style="max-width:150px; max-height:150px; border-radius:8px;">
                            </div>
                            <?php if (!empty($image_path)): ?>
                                <div id="currentImageContainer" style="margin-top:12px;">
                                    <p><strong>Current Image:</strong></p>
                                    <img src="<?php echo SITE_URL . '/' . $image_path; ?>" style="max-width:150px; max-height:150px; border-radius:8px;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="media-group">
                    <h3>Audio (Upload or Record)</h3>

                    <!-- ===== STANDARD AUDIO UPLOAD ===== -->
                    <div class="form-group">
                        <label>Upload Audio File (MP3, WAV)</label>
                        <div id="audioDropZone" class="upload-zone">
                            <i class="fas fa-music"></i>
                            <p>Drag & drop an audio file (MP3, WAV), or <strong>click to browse</strong></p>
                            <input type="file" id="audioInput" name="audio" accept="audio/*" style="display:none;">
                            <div id="audioPreviewContainer" style="display:none; margin-top:12px;">
                                <audio controls id="audioPreview" style="width:100%;"><source src="" type="audio/mpeg"></audio>
                            </div>
                            <?php if (!empty($audio_path)): ?>
                                <div id="currentAudioContainer" style="margin-top:12px;">
                                    <p><strong>Current Audio:</strong></p>
                                    <audio controls style="width:100%;"><source src="<?php echo SITE_URL . '/' . $audio_path; ?>" type="audio/mpeg"></audio>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== LIVE AUDIO RECORDER ===== -->
                    <div class="recorder-section">
                        <h4>🎙️ Or Record Directly</h4>
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
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Poem</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save_and_continue'; document.getElementById('poemForm').submit();">
                    Save & Continue
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ===== TINYMCE EDITOR ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#editor',
        height: 500,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons link lists media searchreplace table visualblocks wordcount',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link | code',
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
                document.getElementById('poemForm').submit();
            });
        }
    });
</script>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('poemEditorTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('poemEditorTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== BACK TO TOP =====
    const backToTopBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 400) {
            backToTopBtn.style.display = 'flex';
        } else {
            backToTopBtn.style.display = 'none';
        }
    });

    // ===== AUTO-EXPAND INTRO TEXTAREA =====
    const introTextarea = document.getElementById('intro');
    if (introTextarea) {
        introTextarea.style.height = 'auto';
        introTextarea.style.height = (introTextarea.scrollHeight) + 'px';
        introTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
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

    // ===== DRAG & DROP IMAGE =====
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const previewContainer = document.getElementById('previewContainer');
    const previewImage = document.getElementById('previewImage');
    const currentImageContainer = document.getElementById('currentImageContainer');

    if (dropZone) {
        dropZone.addEventListener('click', function() { fileInput.click(); });
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) handleFile(e.target.files[0]);
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
            if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
        });
    }

    function handleFile(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please drop an image file.');
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
            if (currentImageContainer) currentImageContainer.style.display = 'none';
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
        };
        reader.readAsDataURL(file);
    }

    // ===== DRAG & DROP AUDIO =====
    const audioDropZone = document.getElementById('audioDropZone');
    const audioInput = document.getElementById('audioInput');
    const audioPreviewContainer = document.getElementById('audioPreviewContainer');
    const audioPreview = document.getElementById('audioPreview');
    const currentAudioContainer = document.getElementById('currentAudioContainer');

    if (audioDropZone) {
        audioDropZone.addEventListener('click', function() { audioInput.click(); });
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
    }

    function handleAudio(file) {
        if (!file.type.startsWith('audio/')) {
            alert('Please drop an audio file.');
            return;
        }
        const url = URL.createObjectURL(file);
        audioPreview.src = url;
        audioPreviewContainer.style.display = 'block';
        if (currentAudioContainer) currentAudioContainer.style.display = 'none';
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

    if (recordBtn) {
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
    }
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.poem-editor-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.form-section, .media-section { margin-bottom: 32px; }
.form-section .form-group { margin-bottom: 16px; }
.form-section label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
.form-section input[type="text"], .form-section textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-section input:focus, .form-section textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.form-section textarea { resize: vertical; min-height: 60px; }

.media-section { display: flex; gap: 24px; flex-wrap: wrap; }
.media-group { flex: 1; min-width: 280px; }
.media-group h3 { font-size: 1.1rem; margin-bottom: 12px; }

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
.upload-zone:hover { border-color: var(--rose); background: rgba(219,161,162,0.05); }

/* ===== RECORDER SECTION ===== */
.recorder-section { background: var(--fantasy); border-radius: 12px; padding: 16px; margin-top: 12px; border: 1px solid var(--border); }
.recorder-section h4 { margin-bottom: 8px; }
.recorder-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.recorder-controls .btn { padding: 8px 16px; }
#recordingStatus { font-weight: 600; color: #e74c3c; }
.recorder-section audio { width: 100%; border-radius: 8px; margin-top: 8px; background: var(--bg); }

/* ===== FORM ACTIONS ===== */
.form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); }
.form-actions .btn { min-width: 120px; justify-content: center; padding: 10px 24px; font-weight: 600; border-radius: 30px; }
.btn-primary { background: var(--rose); color: white; }
.btn-primary:hover { background: var(--rose-dark); transform: translateY(-2px); }
.btn-secondary { background: var(--dark); color: white; }
.btn-secondary:hover { background: #1e1414; transform: translateY(-2px); }

/* ===== BACK TO TOP ===== */
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }

@media (max-width: 768px) {
    .media-section { flex-direction: column; }
    .media-group { min-width: auto; }
}
</style>

<?php require_once '../includes/footer.php'; ?>