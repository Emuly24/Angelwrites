<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch existing poem data
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $action = $_POST['action'] ?? 'save';

    // Handle image upload
    $uploaded_image_path = $image_path;
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

    // Handle audio upload (from file)
    $uploaded_audio_path = $audio_path;
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

    // Handle audio recording (from the recorder)
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
            // Update existing poem
            $stmt = $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ?, image_path = ?, audio_path = ? WHERE id = ?");
            $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path, $id]);
            $success = 'Poem updated successfully!';
        } else {
            // Insert new poem
            $stmt = $db->prepare("INSERT INTO poems (title, intro, content, image_path, audio_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $intro, $content, $uploaded_image_path, $uploaded_audio_path]);
            $id = $db->lastInsertId();
            $success = 'Poem created successfully!';
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

<div class="poem-editor-page">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo $id > 0 ? 'Edit Poem' : 'Add New Poem'; ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Poems
                </a>
                <?php if ($id > 0): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/preview_poem.php?id=<?php echo $id; ?>" class="btn btn-secondary" target="_blank">
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
                <!-- Title -->
                <div class="form-group">
                    <label for="title">Poem Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                </div>

                <!-- Introduction / Purpose (Auto-expanding) -->
                <div class="form-group">
                    <label for="intro">Purpose / Introduction</label>
                    <textarea id="intro" name="intro" rows="3" placeholder="Write a short introduction explaining the purpose or inspiration behind this poem..." oninput="autoResize(this)"><?php echo htmlspecialchars($intro); ?></textarea>
                    <small class="field-hint">This will appear before the poem. It auto-expands as you type.</small>
                </div>

                <!-- Poem Content -->
                <div class="form-group">
                    <label for="content">Poem Content <span class="required">*</span></label>
                    <textarea id="editor" name="content" rows="12"><?php echo htmlspecialchars($content); ?></textarea>
                </div>
            </div>

            <div class="media-section">
                <!-- Poem Cover Image -->
                <div class="media-group">
                    <h3>Cover Image</h3>
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

                <!-- Audio Upload & Recording -->
                <div class="media-group">
                    <h3>Audio (Upload or Record)</h3>
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

                    <div class="recorder-section">
                        <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                        <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                        <form id="recordingForm" style="display:none;">
                            <input type="file" name="audio_recording" id="recordingInput" accept="audio/webm">
                        </form>
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

<!-- ===== TINYMCE EDITOR ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#editor',
        height: 500,
        menubar: false,
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

<script>
    // ===== AUTO-EXPAND INTRO TEXTAREA =====
    function autoResize(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight) + 'px';
    }

    // ===== DRAG & DROP IMAGE =====
    document.addEventListener('DOMContentLoaded', function() {
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
        const currentAudioContainer = document.getElementById('currentAudioContainer');

        if (audioDropZone) {
            audioDropZone.addEventListener('click', function() { audioInput.click(); });
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
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            audioInput.files = dataTransfer.files;
        }
    });

    // ===== AUDIO RECORDER =====
    document.addEventListener('DOMContentLoaded', function() {
        const recordBtn = document.getElementById('recordBtn');
        const recordingStatus = document.getElementById('recordingStatus');
        const recordingInput = document.getElementById('recordingInput');

        let mediaRecorder = null;
        let audioChunks = [];

        if (recordBtn) {
            recordBtn.addEventListener('click', async function() {
                if (mediaRecorder && mediaRecorder.state === 'recording') {
                    // Stop recording
                    mediaRecorder.stop();
                    recordingStatus.style.display = 'none';
                    recordBtn.textContent = '🎙️ Start Recording';
                    recordBtn.classList.remove('btn-danger');
                    recordBtn.classList.add('btn-secondary');
                    return;
                }

                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];

                    mediaRecorder.ondataavailable = event => {
                        audioChunks.push(event.data);
                    };

                    mediaRecorder.onstop = () => {
                        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                        const file = new File([audioBlob], 'poem_recording.webm', { type: 'audio/webm' });
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        recordingInput.files = dataTransfer.files;

                        // Update the audio preview with the new recording
                        const url = URL.createObjectURL(file);
                        document.getElementById('audioPreview').src = url;
                        document.getElementById('audioPreviewContainer').style.display = 'block';

                        // Hide the current audio container if it exists
                        const currentAudioContainer = document.getElementById('currentAudioContainer');
                        if (currentAudioContainer) currentAudioContainer.style.display = 'none';

                        // Show a success message or flash the button
                        recordBtn.textContent = '✅ Recording Saved';
                        setTimeout(() => {
                            recordBtn.textContent = '🎙️ Record Again';
                        }, 2000);
                    };

                    mediaRecorder.start();
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
    .poem-editor-page { padding: 32px 0 60px; }
    .admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
    .admin-header h1 { font-size: 2rem; margin: 0; }
    .admin-actions { display: flex; gap: 12px; }

    .form-section, .media-section { margin-bottom: 32px; }
    .form-section .form-group { margin-bottom: 16px; }
    .form-section label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
    .form-section input[type="text"], .form-section textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
    .form-section input:focus, .form-section textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
    .form-section textarea { resize: vertical; min-height: 60px; }
    .required { color: #dc2626; }
    .field-hint { display: block; margin-top: 4px; font-size: 0.85rem; color: var(--text-light); }

    .media-section { display: flex; gap: 24px; flex-wrap: wrap; }
    .media-group { flex: 1; min-width: 280px; }
    .media-group h3 { font-size: 1.1rem; margin-bottom: 12px; }

    .upload-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--fantasy); }
    .upload-zone i { font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block; }
    .upload-zone p { margin: 0; color: var(--text-light); }

    .recorder-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .recorder-section .btn { padding: 8px 16px; }
    #recordingStatus { font-weight: 600; color: #e74c3c; }

    .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); }
    .form-actions .btn { min-width: 120px; justify-content: center; }
    .btn-primary { background: var(--rose); color: white; }
    .btn-primary:hover { background: var(--rose-dark); transform: translateY(-2px); }
    .btn-secondary { background: var(--dark); color: white; }
    .btn-secondary:hover { background: #1e1414; transform: translateY(-2px); }
    .btn-danger { background: #e74c3c; color: white; }
    .btn-danger:hover { background: #c0392b; }

    @media (max-width: 768px) {
        .media-section { flex-direction: column; }
        .media-group { min-width: auto; }
    }
</style>

<?php require_once '../includes/footer.php'; ?>