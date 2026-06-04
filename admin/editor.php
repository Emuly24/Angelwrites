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

    // Handle regular image upload
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

    // Handle audio recording
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

    // Handle video recording
    if (isset($_FILES['video_recording']) && $_FILES['video_recording']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/videos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $video_filename = 'vid_' . time() . '.webm';
        if (move_uploaded_file($_FILES['video_recording']['tmp_name'], $upload_dir . $video_filename)) {
            $uploaded_video_path = 'assets/uploads/videos/' . $video_filename;
        } else {
            $error = 'Failed to upload recorded video.';
        }
    }

    // Handle featured image for blog
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
                        <div id="featDropZone" class="upload-zone">
                            <i class="fas fa-image"></i>
                            <p>Click to upload a featured image</p>
                            <input type="file" id="featFileInput" name="featured_image" accept="image/*" style="display:none;">
                            <div id="featPreviewContainer" style="display:none; margin-top:12px;">
                                <img id="featPreviewImage" style="max-width:150px; max-height:150px; border-radius:8px;">
                            </div>
                            <?php if (!empty($featured_image)): ?>
                                <div id="currentFeatContainer" style="margin-top:12px;">
                                    <p><strong>Current Featured Image:</strong></p>
                                    <img src="<?php echo SITE_URL . '/' . $featured_image; ?>" style="max-width:150px; max-height:150px; border-radius:8px;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($type === 'poem'): ?>
                    <!-- ===== POEM COVER IMAGE ===== -->
                    <div class="form-group">
                        <label>Poem Cover Image</label>
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

                    <!-- ===== POEM AUDIO ===== -->
                    <div class="form-group">
                        <label>Poem Audio (MP3 or WAV) – optional</label>
                        <div id="audioDropZone" class="upload-zone">
                            <i class="fas fa-music"></i>
                            <p>Drag & drop an audio file, or <strong>click to browse</strong></p>
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
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="content">Content <span class="required">*</span></label>
                        <textarea id="editor" name="content" rows="20"><?php echo htmlspecialchars($content); ?></textarea>
                    </div>

                    <!-- ===== AUDIO RECORDER ===== -->
                    <div class="recorder-section">
                        <h3>🎙️ Record Audio</h3>
                        <div class="recorder-controls">
                            <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                            <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="recordingForm" style="display:none;">
                                <input type="file" name="audio_recording" id="recordingInput" accept="audio/webm">
                            </form>
                            <div id="audioPreviewRecorderContainer" style="display:none; margin-top:10px;">
                                <audio controls id="audioPreviewRecorder" style="width:100%;"><source src="" type="audio/webm"></audio>
                            </div>
                        </div>
                        <p class="field-hint">Record your audio directly in the browser. The recording will be saved when you submit the form.</p>
                    </div>

                    <!-- ===== VIDEO RECORDER ===== -->
                    <div class="recorder-section">
                        <h3>🎥 Record Video</h3>
                        <div class="recorder-controls">
                            <button type="button" id="videoRecordBtn" class="btn btn-secondary btn-sm">🎥 Start Recording</button>
                            <span id="videoRecordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="videoRecordingForm" style="display:none;">
                                <input type="file" name="video_recording" id="videoRecordingInput" accept="video/webm">
                            </form>
                            <div id="videoPreviewContainer" style="display:none; margin-top:10px;">
                                <video controls width="100%"><source src="" type="video/webm"></video>
                            </div>
                        </div>
                        <p class="field-hint">Record a video directly in your browser. The recording will be saved when you submit the form.</p>
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
</script>

<!-- ===== FULL RECORDER JAVASCRIPT (Audio + Video with Confirm/Retake) ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // 1. DRAG & DROP FOR FEATURED IMAGE
    // ============================================================
    const featDropZone = document.getElementById('featDropZone');
    const featFileInput = document.getElementById('featFileInput');
    const featPreviewContainer = document.getElementById('featPreviewContainer');
    const featPreviewImage = document.getElementById('featPreviewImage');

    if (featDropZone) {
        featDropZone.addEventListener('click', () => featFileInput.click());
        featFileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) handleFeatFile(e.target.files[0]);
        });
        featDropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            featDropZone.style.borderColor = 'var(--rose)';
            featDropZone.style.background = 'rgba(219, 161, 162, 0.1)';
        });
        featDropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            featDropZone.style.borderColor = 'var(--border)';
            featDropZone.style.background = 'transparent';
        });
        featDropZone.addEventListener('drop', (e) => {
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
        reader.onload = (e) => {
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

    // ============================================================
    // 2. SHARED RECORDER STATE
    // ============================================================
    let audioRecorder = { mediaRecorder: null, chunks: [], stream: null, blob: null };
    let videoRecorder = { mediaRecorder: null, chunks: [], stream: null, blob: null };

    // ============================================================
    // 3. AUDIO RECORDER (with preview + retake)
    // ============================================================
    const recordBtn = document.getElementById('recordBtn');
    const recordingStatus = document.getElementById('recordingStatus');
    const recordingInput = document.getElementById('recordingInput');
    const audioPreviewRecorderContainer = document.getElementById('audioPreviewRecorderContainer');
    const audioPreviewRecorder = document.getElementById('audioPreviewRecorder');

    if (recordBtn) {
        recordBtn.addEventListener('click', async function() {
            // If currently recording, stop it
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
                    const url = URL.createObjectURL(audioRecorder.blob);
                    audioPreviewRecorder.src = url;
                    audioPreviewRecorder.load();
                    audioPreviewRecorderContainer.style.display = 'block';

                    // Replace controls with preview + confirm/retake
                    document.querySelector('.recorder-controls').innerHTML = `
                        <audio controls src="${url}" style="width:100%;"></audio>
                        <div style="display:flex; gap:12px; margin-top:10px;">
                            <button type="button" id="confirmAudioBtn" class="btn btn-success btn-sm">✅ Use This</button>
                            <button type="button" id="retakeAudioBtn" class="btn btn-warning btn-sm">🔄 Retake</button>
                        </div>
                    `;

                    // Confirm Audio
                    document.getElementById('confirmAudioBtn').addEventListener('click', function() {
                        const dt = new DataTransfer();
                        dt.items.add(new File([audioRecorder.blob], 'audio_recording.webm', { type: 'audio/webm' }));
                        recordingInput.files = dt.files;
                        document.getElementById('recordingForm').style.display = 'block';
                        document.querySelector('.recorder-controls').innerHTML = `
                            <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                            <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="recordingForm" style="display:none;">
                                <input type="file" name="audio_recording" id="recordingInput" accept="audio/webm">
                            </form>
                            <div id="audioPreviewRecorderContainer" style="display:none; margin-top:10px;">
                                <audio controls id="audioPreviewRecorder" style="width:100%;"><source src="" type="audio/webm"></audio>
                            </div>
                        `;
                        // Re-bind the record button by re-initializing the event listener (or reload)
                        location.reload(); // simplest
                    });

                    // Retake Audio
                    document.getElementById('retakeAudioBtn').addEventListener('click', function() {
                        audioRecorder.blob = null;
                        audioPreviewRecorderContainer.style.display = 'none';
                        document.querySelector('.recorder-controls').innerHTML = `
                            <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                            <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="recordingForm" style="display:none;">
                                <input type="file" name="audio_recording" id="recordingInput" accept="audio/webm">
                            </form>
                            <div id="audioPreviewRecorderContainer" style="display:none; margin-top:10px;">
                                <audio controls id="audioPreviewRecorder" style="width:100%;"><source src="" type="audio/webm"></audio>
                            </div>
                        `;
                        location.reload();
                    });
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

    // ============================================================
    // 4. VIDEO RECORDER (with preview + retake)
    // ============================================================
    const videoRecordBtn = document.getElementById('videoRecordBtn');
    const videoRecordingStatus = document.getElementById('videoRecordingStatus');
    const videoRecordingInput = document.getElementById('videoRecordingInput');
    const videoPreviewContainer = document.getElementById('videoPreviewContainer');
    const videoPreview = videoPreviewContainer ? videoPreviewContainer.querySelector('video') : null;
    const videoRecordingForm = document.getElementById('videoRecordingForm');

    if (videoRecordBtn) {
        videoRecordBtn.addEventListener('click', async function() {
            // If currently recording, stop it
            if (videoRecorder.mediaRecorder && videoRecorder.mediaRecorder.state === 'recording') {
                videoRecorder.mediaRecorder.stop();
                videoRecordingStatus.textContent = '⏹️ Stopped';
                videoRecordBtn.textContent = '🎥 Start Recording';
                videoRecordBtn.classList.remove('btn-danger');
                videoRecordBtn.classList.add('btn-secondary');
                return;
            }

            try {
                videoRecorder.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
                videoRecorder.mediaRecorder = new MediaRecorder(videoRecorder.stream);
                videoRecorder.chunks = [];

                // Show live preview during recording
                if (videoPreview) {
                    videoPreview.srcObject = videoRecorder.stream;
                    videoPreview.muted = true;
                    videoPreview.style.display = 'block';
                    videoPreviewContainer.style.display = 'block';
                    videoPreview.play();
                }

                videoRecorder.mediaRecorder.ondataavailable = event => {
                    videoRecorder.chunks.push(event.data);
                };

                videoRecorder.mediaRecorder.onstop = () => {
                    videoRecorder.blob = new Blob(videoRecorder.chunks, { type: 'video/webm' });
                    const url = URL.createObjectURL(videoRecorder.blob);
                    if (videoPreview) {
                        videoPreview.srcObject = null;
                        videoPreview.src = url;
                        videoPreview.muted = false;
                        videoPreview.load();
                        videoPreview.style.display = 'block';
                    }
                    videoPreviewContainer.style.display = 'block';

                    // Replace controls with preview + confirm/retake
                    document.querySelector('.recorder-controls').innerHTML = `
                        <video controls src="${url}" width="100%"></video>
                        <div style="display:flex; gap:12px; margin-top:10px;">
                            <button type="button" id="confirmVideoBtn" class="btn btn-success btn-sm">✅ Use This</button>
                            <button type="button" id="retakeVideoBtn" class="btn btn-warning btn-sm">🔄 Retake</button>
                        </div>
                    `;

                    // Confirm Video
                    document.getElementById('confirmVideoBtn').addEventListener('click', function() {
                        const dt = new DataTransfer();
                        dt.items.add(new File([videoRecorder.blob], 'video_recording.webm', { type: 'video/webm' }));
                        videoRecordingInput.files = dt.files;
                        document.getElementById('videoRecordingForm').style.display = 'block';
                        document.querySelector('.recorder-controls').innerHTML = `
                            <button type="button" id="videoRecordBtn" class="btn btn-secondary btn-sm">🎥 Start Recording</button>
                            <span id="videoRecordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="videoRecordingForm" style="display:none;">
                                <input type="file" name="video_recording" id="videoRecordingInput" accept="video/webm">
                            </form>
                            <div id="videoPreviewContainer" style="display:none; margin-top:10px;">
                                <video controls width="100%"><source src="" type="video/webm"></video>
                            </div>
                        `;
                        location.reload();
                    });

                    // Retake Video
                    document.getElementById('retakeVideoBtn').addEventListener('click', function() {
                        videoRecorder.blob = null;
                        videoPreviewContainer.style.display = 'none';
                        document.querySelector('.recorder-controls').innerHTML = `
                            <button type="button" id="videoRecordBtn" class="btn btn-secondary btn-sm">🎥 Start Recording</button>
                            <span id="videoRecordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                            <form id="videoRecordingForm" style="display:none;">
                                <input type="file" name="video_recording" id="videoRecordingInput" accept="video/webm">
                            </form>
                            <div id="videoPreviewContainer" style="display:none; margin-top:10px;">
                                <video controls width="100%"><source src="" type="video/webm"></video>
                            </div>
                        `;
                        location.reload();
                    });
                };

                videoRecorder.mediaRecorder.start();
                videoRecordingStatus.textContent = '🔴 Recording...';
                videoRecordBtn.textContent = '⏹️ Stop Recording';
                videoRecordBtn.classList.remove('btn-secondary');
                videoRecordBtn.classList.add('btn-danger');
            } catch (error) {
                alert('Camera/microphone access denied.');
                console.error('Recording error:', error);
            }
        });
    }
});
</script>

<style>
    .form-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
    .form-row .form-group { flex: 1; min-width: 150px; }
    .form-actions { display: flex; gap: 12px; margin-top: 16px; }
    .card { margin-bottom: 24px; }
    .card-body { padding: 20px; }
    .admin-editor { padding: 32px 0 60px; }
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

    .recorder-section { background: var(--fantasy); border-radius: 12px; padding: 20px; margin-top: 16px; border: 1px solid var(--border); }
    .recorder-section h3 { margin-bottom: 12px; }
    .recorder-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 8px; }
    .recorder-controls .btn { padding: 8px 16px; }
    #recordingStatus, #videoRecordingStatus { font-weight: 600; color: #e74c3c; }
    .recorder-section audio, .recorder-section video { width: 100%; border-radius: 8px; margin-top: 8px; background: var(--bg); }

    .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); }
    .form-actions .btn { min-width: 120px; justify-content: center; }
    .btn-primary { background: var(--rose); color: white; }
    .btn-primary:hover { background: var(--rose-dark); transform: translateY(-2px); }
    .btn-secondary { background: var(--dark); color: white; }
    .btn-secondary:hover { background: #1e1414; transform: translateY(-2px); }
    .btn-danger { background: #e74c3c; color: white; }
    .btn-danger:hover { background: #c0392b; }

    /* Blinking animation for the recording status */
    @keyframes blink-animation {
      0% { opacity: 1; }
      50% { opacity: 0; }
      100% { opacity: 1; }
    }
    #videoRecordingStatus.recording {
      animation: blink-animation 1s infinite;
      color: #e74c3c;
      font-weight: bold;
    }

    /* Ensure the video preview container is visible during recording */
    #videoPreviewContainer {
      display: block !important;
      width: 100%;
      margin-top: 10px;
    }
    #videoPreviewContainer video {
      width: 100%;
      max-width: 100%;
      border-radius: 8px;
      background: #000;
      display: block;
    }

    @media (max-width: 768px) {
        .media-section { flex-direction: column; }
        .media-group { min-width: auto; }
    }
</style>

<?php require_once '../includes/footer.php'; ?>