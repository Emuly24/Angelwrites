<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$type = isset($_GET['type']) ? $_GET['type'] : 'blog';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';

$title = '';
$intro = '';
$content = '';
$category = 'Christian Reflections';
$tags = '';
$status = 'draft';
$featured_image = '';

if ($type === 'reflection') {
    $category = 'Christian Reflections';
}

if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($post) {
        $title = $post['title'];
        $intro = $post['excerpt'] ?? '';
        $content = $post['content'];
        $category = $post['category'] ?? 'Christian Reflections';
        $tags = $post['tags'] ?? '';
        $status = $post['status'] ?? 'draft';
        $featured_image = $post['featured_image'] ?? '';
    } else {
        $error = 'Post not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);
    $category = trim($_POST['category'] ?? 'Christian Reflections');
    $tags = trim($_POST['tags'] ?? '');
    $status = trim($_POST['status'] ?? 'draft');
    $action = $_POST['action'] ?? 'save';

    $uploaded_featured_image = $featured_image;

    // ===== LIVE PHOTO CAPTURE =====
    if (!empty($_FILES['live_photo']['name'])) {
        $upload_dir = '../assets/uploads/blog/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'live_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $uploaded_featured_image = 'assets/uploads/blog/' . $photo_filename;
        } else {
            $error = 'Failed to upload captured photo.';
        }
    }

    // ===== STANDARD IMAGE UPLOAD =====
    if (empty($error) && !empty($_FILES['featured_image']['name'])) {
        $upload_dir = '../assets/uploads/blog/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $feat_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['featured_image']['name']);
        if (move_uploaded_file($_FILES['featured_image']['tmp_name'], $upload_dir . $feat_filename)) {
            $uploaded_featured_image = 'assets/uploads/blog/' . $feat_filename;
        } else {
            $error = 'Failed to upload featured image.';
        }
    }

    // ===== AUDIO RECORDING =====
    if (isset($_FILES['audio_recording']) && $_FILES['audio_recording']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $rec_filename = 'rec_' . time() . '.webm';
        if (move_uploaded_file($_FILES['audio_recording']['tmp_name'], $upload_dir . $rec_filename)) {
            // Attach audio to post (you might store it in a separate table or as metadata)
        } else {
            $error = 'Failed to upload recorded audio.';
        }
    }

    // ===== VIDEO RECORDING =====
    if (isset($_FILES['video_recording']) && $_FILES['video_recording']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/videos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $video_filename = 'vid_' . time() . '.webm';
        if (move_uploaded_file($_FILES['video_recording']['tmp_name'], $upload_dir . $video_filename)) {
            // Attach video to post
        } else {
            $error = 'Failed to upload recorded video.';
        }
    }

    if (empty($title) || empty($content)) {
        $error = 'Title and content are required.';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE blog_posts SET title = ?, content = ?, excerpt = ?, category = ?, tags = ?, status = ?, featured_image = ? WHERE id = ?");
            $stmt->execute([$title, $content, $intro, $category, $tags, $status, $uploaded_featured_image, $id]);
            $success = 'Blog post updated successfully!';
        } else {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
            $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, category, tags, status, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $slug, $content, $intro, $category, $tags, $status, $uploaded_featured_image]);
            $id = $db->lastInsertId();
            $success = 'Blog post created successfully!';

            // Admin notification via Zoho SMTP
            $admin_email = 'angelwrites@zohomail.com';
            $subject = 'New Blog Post: ' . $title;
            $body = "A new blog post has been added.\n\nTitle: $title\nCategory: $category\n\nView post: " . SITE_URL . "/blog.php?slug=" . $slug;
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }

        // ===== NEWSLETTER BROADCAST (using Zoho SMTP) =====
        if (isset($_POST['send_newsletter'])) {
            $subject = $title;
            $full_message = "<html><body>";
            $full_message .= "<h2>$title</h2>";
            if (!empty($intro)) $full_message .= "<p><em>$intro</em></p>";
            $full_message .= "<div>" . nl2br($content) . "</div>";
            $full_message .= "<hr><p>Unsubscribe: " . SITE_URL . "/newsletter.php?unsubscribe=1&token=[TOKEN]</p>";
            $full_message .= "</body></html>";

            $stmt = $db->prepare("SELECT email, unsubscribe_token FROM newsletter WHERE is_active = 1");
            $stmt->execute();
            $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sent_count = 0;
            foreach ($subscribers as $sub) {
                $personalized_message = str_replace('[TOKEN]', $sub['unsubscribe_token'], $full_message);
                if (sendEmail($sub['email'], $subject, $personalized_message, 'no-reply@angelwrites.gt.tc', SITE_NAME . ' Blog')) {
                    $sent_count++;
                }
                usleep(500000);
            }
            $success .= " Broadcast sent to $sent_count subscribers.";
        }

        if ($action === 'save_and_continue') {
            header('Location: ' . SITE_URL . '/admin/editor.php?type=' . $type . '&id=' . $id);
            exit;
        } else {
            header('Location: ' . SITE_URL . '/admin/manage_blog.php');
            exit;
        }
    }
}

$pageTitle = $id > 0 ? 'Edit Blog Post' : 'Add New Blog';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-editor">
    <div class="container">
        <div class="admin-header">
            <h1><?php echo $id > 0 ? 'Edit Blog Post' : 'Add New Blog'; ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-outline">
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
                    <!-- Title -->
                    <div class="form-group">
                        <label for="title">Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" required>
                    </div>

                    <!-- Introduction -->
                    <div class="form-group">
                        <label for="intro">Introduction / Purpose</label>
                        <textarea id="intro" name="intro" rows="3"><?php echo htmlspecialchars($intro); ?></textarea>
                    </div>

                    <!-- Blog Fields -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($category); ?>" placeholder="e.g. Christian Reflections">
                        </div>
                        <div class="form-group">
                            <label for="tags">Tags (comma separated)</label>
                            <input type="text" id="tags" name="tags" value="<?php echo htmlspecialchars($tags); ?>" placeholder="e.g. faith, hope, healing">
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo $status === 'published' ? 'selected' : ''; ?>>Published</option>
                                <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
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
                            <input type="file" id="livePhotoInput" name="live_photo" accept="image/*" style="display:none;">
                        </div>
                    </div>

                    <!-- Standard Image Upload (Fallback) -->
                    <div class="form-group">
                        <label for="featured_image">Or Upload Featured Image</label>
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

                    <!-- Content -->
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

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Blog</button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save_and_continue'; document.getElementById('editorForm').submit();">
                            Save & Continue
                        </button>
                        <button type="submit" name="send_newsletter" value="1" class="btn btn-info btn-block">📨 Save & Send to Newsletter</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ===== TINYMCE EDITOR ===== -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
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
</script>

<!-- ===== RECORDER JAVASCRIPT (Camera, Audio, Video) ===== -->
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
    // 2. LIVE PHOTO CAPTURE
    // ============================================================
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
            // Stop camera stream
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
        // Restart camera if needed
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

    // ============================================================
    // 3. AUDIO RECORDER
    // ============================================================
    const recordBtn = document.getElementById('recordBtn');
    const recordingStatus = document.getElementById('recordingStatus');
    const recordingInput = document.getElementById('recordingInput');
    const audioPreviewRecorderContainer = document.getElementById('audioPreviewRecorderContainer');
    const audioPreviewRecorder = document.getElementById('audioPreviewRecorder');

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
                    const url = URL.createObjectURL(audioRecorder.blob);
                    audioPreviewRecorder.src = url;
                    audioPreviewRecorder.load();
                    audioPreviewRecorderContainer.style.display = 'block';

                    document.querySelector('.recorder-controls').innerHTML = `
                        <audio controls src="${url}" style="width:100%;"></audio>
                        <div style="display:flex; gap:12px; margin-top:10px;">
                            <button type="button" id="confirmAudioBtn" class="btn btn-success btn-sm">✅ Use This</button>
                            <button type="button" id="retakeAudioBtn" class="btn btn-warning btn-sm">🔄 Retake</button>
                        </div>
                    `;

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
                        location.reload();
                    });

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
    // 4. VIDEO RECORDER
    // ============================================================
    const videoRecordBtn = document.getElementById('videoRecordBtn');
    const videoRecordingStatus = document.getElementById('videoRecordingStatus');
    const videoRecordingInput = document.getElementById('videoRecordingInput');
    const videoPreviewContainer = document.getElementById('videoPreviewContainer');
    const videoPreview = videoPreviewContainer ? videoPreviewContainer.querySelector('video') : null;
    const videoRecordingForm = document.getElementById('videoRecordingForm');

    let videoRecorder = { mediaRecorder: null, chunks: [], stream: null, blob: null };

    if (videoRecordBtn) {
        videoRecordBtn.addEventListener('click', async function() {
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

                    document.querySelector('.recorder-controls').innerHTML = `
                        <video controls src="${url}" width="100%"></video>
                        <div style="display:flex; gap:12px; margin-top:10px;">
                            <button type="button" id="confirmVideoBtn" class="btn btn-success btn-sm">✅ Use This</button>
                            <button type="button" id="retakeVideoBtn" class="btn btn-warning btn-sm">🔄 Retake</button>
                        </div>
                    `;

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
.admin-editor { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
.admin-form input[type="text"], .admin-form textarea, .admin-form select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.admin-form textarea { resize: vertical; min-height: 60px; }
.required { color: #dc2626; }

.form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 12px; }
.form-row .form-group { flex: 1; min-width: 150px; }

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

/* ===== UPLOAD ZONE ===== */
.upload-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--fantasy); }
.upload-zone i { font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; display: block; }
.upload-zone p { margin: 0; color: var(--text-light); }
.upload-zone:hover { border-color: var(--rose); background: rgba(219, 161, 162, 0.05); }

/* ===== RECORDER SECTION ===== */
.recorder-section { background: var(--fantasy); border-radius: 12px; padding: 20px; margin-top: 16px; border: 1px solid var(--border); }
.recorder-section h3 { margin-bottom: 12px; }
.recorder-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 8px; }
.recorder-controls .btn { padding: 8px 16px; }
#recordingStatus, #videoRecordingStatus { font-weight: 600; color: #e74c3c; }
.recorder-section audio, .recorder-section video { width: 100%; border-radius: 8px; margin-top: 8px; background: var(--bg); }

.form-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
.form-actions .btn { min-width: 120px; justify-content: center; padding: 10px 24px; font-weight: 600; border-radius: 30px; }
.btn-primary { background: var(--rose); color: white; }
.btn-primary:hover { background: var(--rose-dark); transform: translateY(-2px); }
.btn-secondary { background: var(--dark); color: white; }
.btn-secondary:hover { background: #1e1414; transform: translateY(-2px); }
.btn-info { background: #3498db; color: white; }
.btn-info:hover { background: #2980b9; transform: translateY(-2px); }
.btn-block { width: 100%; }

@media (max-width: 768px) {
    .form-row { flex-direction: column; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; }
}
</style>

<?php require_once '../includes/footer.php'; ?>