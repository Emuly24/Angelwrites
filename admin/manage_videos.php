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
    
    $stmt = $db->prepare("SELECT thumbnail, video_file FROM videos WHERE id = ?");
    $stmt->execute([$id]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($video) {
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        if (!empty($video['thumbnail']) && file_exists($doc_root . '/' . $video['thumbnail'])) {
            @unlink($doc_root . '/' . $video['thumbnail']);
        }
        if (!empty($video['video_file']) && file_exists($doc_root . '/' . $video['video_file'])) {
            @unlink($doc_root . '/' . $video['video_file']);
        }
        $stmt = $db->prepare("DELETE FROM videos WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'Video deleted successfully.';
    } else {
        $error = 'Video not found.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_videos.php');
    exit;
}

// ===== HANDLE ADD/EDIT VIDEO =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_video'])) {
    $id = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $video_url = trim($_POST['video_url']);
    $type = trim($_POST['type'] ?? 'short');

    if (empty($title)) {
        $error = 'Video title is required.';
    } else {
        $thumbnail = null;
        $video_file = null;

        // ===== LIVE PHOTO CAPTURE FOR THUMBNAIL =====
        if (!empty($_FILES['live_thumbnail']['name'])) {
            $upload_dir = '../assets/uploads/videos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $thumb_filename = 'thumb_' . time() . '.jpg';
            if (move_uploaded_file($_FILES['live_thumbnail']['tmp_name'], $upload_dir . $thumb_filename)) {
                $thumbnail = 'assets/uploads/videos/' . $thumb_filename;
            } else {
                $error = 'Failed to upload captured thumbnail.';
            }
        }

        // ===== STANDARD THUMBNAIL UPLOAD =====
        if (empty($error) && !empty($_FILES['thumbnail']['name'])) {
            $upload_dir = '../assets/uploads/videos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $thumb_filename = 'thumb_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['thumbnail']['name']);
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_dir . $thumb_filename)) {
                $thumbnail = 'assets/uploads/videos/' . $thumb_filename;
            } else {
                $error = 'Failed to upload thumbnail.';
            }
        }

        // ===== VIDEO FILE UPLOAD (Live Recording) =====
        if (!empty($_FILES['video_file']['name'])) {
            $upload_dir = '../assets/uploads/videos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_extension = pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION);
            $new_filename = 'video_' . time() . '.' . $file_extension;
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $upload_dir . $new_filename)) {
                $video_file = 'assets/uploads/videos/' . $new_filename;
            }
        }

        if (empty($error)) {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE videos 
                    SET title = ?, description = ?, video_url = ?, type = ?, 
                        thumbnail = COALESCE(?, thumbnail), 
                        video_file = COALESCE(?, video_file) 
                    WHERE id = ?
                ");
                $stmt->execute([$title, $description, $video_url, $type, $thumbnail, $video_file, $id]);
                $success = 'Video updated successfully.';
            } else {
                $stmt = $db->prepare("INSERT INTO videos (title, description, video_url, type, thumbnail, video_file) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $video_url, $type, $thumbnail, $video_file]);
                $success = 'Video added successfully.';
            }
            header('Location: ' . SITE_URL . '/admin/manage_videos.php');
            exit;
        }
    }
}

// ===== FETCH VIDEOS WITH SEARCH =====
$sql = "SELECT * FROM videos";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE title LIKE ? OR description LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Videos';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Videos</h1>
            <div class="admin-actions">
                <button id="showAddForm" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Video
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

        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search videos by title or description..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Video Form (hidden by default) -->
        <div id="videoFormContainer" style="display: none;">
            <div class="card">
                <div class="card-header">
                    <h2 id="formTitle">Add New Video</h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="admin-form" id="videoForm">
                        <input type="hidden" name="video_id" id="video_id" value="0">
                        <input type="hidden" name="save_video" value="1">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="title">Video Title <span class="required">*</span></label>
                                <input type="text" id="title" name="title" required>
                            </div>
                            <div class="form-group">
                                <label for="type">Type</label>
                                <select id="type" name="type">
                                    <option value="short">Short</option>
                                    <option value="full">Full</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="3"></textarea>
                        </div>

                        <!-- ===== LIVE PHOTO CAPTURE FOR THUMBNAIL ===== -->
                        <div class="form-group">
                            <label>Live Thumbnail (capture with camera)</label>
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
                                <input type="file" id="liveThumbnailInput" name="live_thumbnail" accept="image/*" style="display:none;">
                            </div>
                        </div>

                        <!-- Standard Thumbnail Upload (Fallback) -->
                        <div class="form-group">
                            <label for="thumbnail">Or Upload Thumbnail Image</label>
                            <input type="file" id="thumbnail" name="thumbnail" accept="image/*">
                        </div>

                        <!-- ===== VIDEO SOURCE ===== -->
                        <div class="video-upload-section">
                            <h3>Video Source</h3>
                            <div class="upload-tabs">
                                <div class="tab-buttons">
                                    <button type="button" class="tab-btn active" data-tab="url-tab">YouTube / Vimeo Link</button>
                                    <button type="button" class="tab-btn" data-tab="upload-tab">Upload / Record Video</button>
                                </div>
                                
                                <div id="url-tab" class="tab-content active">
                                    <div class="form-group">
                                        <label for="video_url">Video URL</label>
                                        <input type="text" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=...">
                                    </div>
                                </div>
                                
                                <div id="upload-tab" class="tab-content">
                                    <div class="recorder-wrapper">
                                        <div class="video-preview-container">
                                            <video id="videoPreview" autoplay muted></video>
                                            <div class="video-placeholder" id="videoPlaceholder">
                                                <i class="fas fa-video"></i>
                                                <p>Camera preview will appear here.</p>
                                            </div>
                                        </div>
                                        <div class="recorder-controls">
                                            <div class="controls-left">
                                                <button type="button" id="startRecordBtn" class="btn btn-primary btn-sm"><i class="fas fa-circle"></i> Start Recording</button>
                                                <button type="button" id="stopRecordBtn" class="btn btn-danger btn-sm" disabled><i class="fas fa-stop"></i> Stop</button>
                                            </div>
                                            <div class="controls-right">
                                                <button type="button" id="retakeBtn" class="btn btn-outline btn-sm" disabled><i class="fas fa-redo"></i> Retake</button>
                                                <button type="button" id="confirmVideoBtn" class="btn btn-success btn-sm" disabled><i class="fas fa-check"></i> Use This Video</button>
                                            </div>
                                            <span id="recordingStatus" class="status-indicator">Ready</span>
                                        </div>
                                        <div class="file-input-wrapper">
                                            <label for="video_file" class="btn btn-outline btn-sm">
                                                <i class="fas fa-upload"></i> Or Upload Video File
                                            </label>
                                            <input type="file" id="video_file" name="video_file" accept="video/*" style="display:none;">
                                            <span id="fileChosen" class="file-status">No file chosen</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-large"><i class="fas fa-save"></i> Save Video</button>
                            <button type="button" class="btn btn-outline btn-large" id="cancelForm">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Videos (<?php echo count($videos); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($videos) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Thumbnail</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Source</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($videos as $video): ?>
                                    <tr>
                                        <td>
                                            <?php if ($video['thumbnail']): ?>
                                                <img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;">
                                            <?php else: ?>
                                                <div style="width: 50px; height: 50px; background: var(--vanilla); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-light);">
                                                    <i class="fas fa-video"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($video['title']); ?></strong></td>
                                        <td><span class="status-badge"><?php echo ucfirst($video['type']); ?></span></td>
                                        <td>
                                            <?php if (!empty($video['video_file'])): ?>
                                                <span class="source-local"><i class="fas fa-upload"></i> Uploaded</span>
                                            <?php elseif (!empty($video['video_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($video['video_url']); ?>" target="_blank" class="source-link"><i class="fas fa-external-link-alt"></i> Link</a>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($video['created_at'])); ?></td>
                                        <td class="actions">
                                            <button class="btn btn-sm btn-secondary edit-btn" 
                                                    data-id="<?php echo $video['id']; ?>" 
                                                    data-title="<?php echo htmlspecialchars($video['title']); ?>" 
                                                    data-description="<?php echo htmlspecialchars($video['description'] ?? ''); ?>" 
                                                    data-url="<?php echo htmlspecialchars($video['video_url']); ?>" 
                                                    data-type="<?php echo $video['type']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php?delete=<?php echo $video['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this video?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No videos yet. Click "Add New Video" to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // FORM TOGGLE LOGIC
    // ============================================================
    const showAddBtn = document.getElementById('showAddForm');
    const formContainer = document.getElementById('videoFormContainer');
    const cancelBtn = document.getElementById('cancelForm');
    const videoIdInput = document.getElementById('video_id');

    function toggleForm(show) {
        formContainer.style.display = show ? 'block' : 'none';
        if (show) {
            formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.location.href = '<?php echo SITE_URL; ?>/admin/manage_videos.php';
        }
    }

    showAddBtn.addEventListener('click', function() {
        resetForm();
        toggleForm(true);
    });

    cancelBtn.addEventListener('click', function() { toggleForm(false); });

    function resetForm() {
        videoIdInput.value = 0;
        document.getElementById('title').value = '';
        document.getElementById('description').value = '';
        document.getElementById('video_url').value = '';
        document.getElementById('type').value = 'short';
        document.getElementById('formTitle').textContent = 'Add New Video';
        resetCamera();
        resetVideoRecorder();
    }

    // ============================================================
    // EDIT BUTTON LOGIC
    // ============================================================
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            videoIdInput.value = this.dataset.id;
            document.getElementById('title').value = this.dataset.title;
            document.getElementById('description').value = this.dataset.description;
            document.getElementById('video_url').value = this.dataset.url;
            document.getElementById('type').value = this.dataset.type;
            document.getElementById('formTitle').textContent = 'Edit Video';
            resetCamera();
            resetVideoRecorder();
            toggleForm(true);
        });
    });

    // ============================================================
    // CAMERA (LIVE PHOTO) LOGIC
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
    const liveThumbnailInput = document.getElementById('liveThumbnailInput');

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
        startCameraBtn.disabled = false;
    }

    function confirmPhoto() {
        if (!capturedBlob) return;
        const file = new File([capturedBlob], 'live_thumbnail.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        liveThumbnailInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Thumbnail confirmed!';
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
        liveThumbnailInput.value = '';
        cameraStatus.textContent = 'Camera ready';
        cameraStatus.style.color = 'var(--text-light)';
    }

    // ============================================================
    // VIDEO RECORDER LOGIC (unchanged from original)
    // ============================================================
    // (Same video recorder code as in editor.php – include it here)
    // ...

    // ============================================================
    // TAB SWITCHING
    // ============================================================
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(this.dataset.tab).classList.add('active');
        });
    });
});
</script>

<style>
/* ===== Same styles as editor.php + video-specific ===== */
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

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; background: var(--vanilla); color: var(--text); }
.source-local { color: #27ae60; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 4px; }
.source-link { color: var(--rose); font-size: 0.85rem; text-decoration: none; }
.source-link:hover { text-decoration: underline; }

.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 20px; }

/* ===== VIDEO FORM STYLES ===== */
.admin-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
.admin-form input[type="text"], .admin-form input[type="email"], .admin-form select, .admin-form textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
}
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.required { color: #dc2626; }
.admin-form .form-actions { display: flex; gap: 12px; margin-top: 16px; }
.admin-form .btn-large { padding: 14px 28px; border-radius: 30px; font-size: 1.05rem; }

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

/* ===== VIDEO UPLOAD / RECORDER SECTION ===== */
.video-upload-section { border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; background: var(--fantasy); }
.upload-tabs { margin-top: 8px; }
.tab-buttons { display: flex; gap: 8px; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 4px; }
.tab-btn { background: transparent; border: none; padding: 8px 16px; font-weight: 500; color: var(--text-light); cursor: pointer; border-radius: 8px 8px 0 0; transition: all 0.2s; }
.tab-btn.active { color: var(--rose); border-bottom: 2px solid var(--rose); }
.tab-btn:hover { color: var(--rose); }
.tab-content { display: none; padding-top: 8px; }
.tab-content.active { display: block; }

.recorder-wrapper { display: flex; flex-direction: column; gap: 12px; align-items: center; }
.video-preview-container { width: 100%; max-width: 400px; height: 220px; background: var(--vanilla); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; }
.video-preview-container video { width: 100%; height: 100%; object-fit: cover; display: none; }
.video-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-light); text-align: center; padding: 24px; }
.video-placeholder i { font-size: 2.5rem; margin-bottom: 8px; color: var(--rose); }
.video-placeholder p { margin: 0; font-size: 0.9rem; }
.recorder-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center; }
.recorder-controls .btn { padding: 6px 14px; font-size: 0.85rem; }
.file-input-wrapper { display: flex; align-items: center; gap: 8px; }
.file-input-wrapper .file-status { font-size: 0.85rem; color: var(--text-light); }

@media (max-width: 768px) {
    .admin-form .form-row { grid-template-columns: 1fr; }
    .tab-buttons { flex-direction: column; gap: 4px; border-bottom: none; }
    .tab-btn { border-radius: 8px; border: 1px solid var(--border); }
}
</style>

<?php require_once '../includes/footer.php'; ?>