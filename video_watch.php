<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $db->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$id]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$video) {
    header('Location: ' . SITE_URL . '/videos.php');
    exit;
}

// Handle comment submission with live photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    $content = trim($_POST['comment_content']);
    $image_path = null;

    // Handle live photo upload
    if (!empty($_FILES['live_comment_photo']['name'])) {
        $upload_dir = 'assets/uploads/comments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'comment_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_comment_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $image_path = $upload_dir . $photo_filename;
        }
    }

    if (empty($content)) {
        $error = 'Comment content is required.';
    } else {
        $stmt = $db->prepare("INSERT INTO video_comments (video_id, user_id, content, image_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $_SESSION['user_id'], $content, $image_path]);
        $success = 'Comment added successfully.';
        $video['comment_count'] = ($video['comment_count'] ?? 0) + 1;
    }
}

// Fetch comments
$stmt = $db->prepare("SELECT c.*, u.username, u.display_name, u.avatar FROM video_comments c JOIN users u ON c.user_id = u.id WHERE c.video_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($video['title']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="video-watch-page">
    <div class="container">
        <div class="video-player-wrapper">
            <?php if ($video['video_file']): ?>
                <video controls width="100%" poster="<?php echo $video['thumbnail'] ? SITE_URL . '/' . $video['thumbnail'] : ''; ?>">
                    <source src="<?php echo SITE_URL . '/' . $video['video_file']; ?>" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            <?php elseif ($video['video_url']): ?>
                <div class="video-embed-container">
                    <iframe width="560" height="315" src="<?php echo htmlspecialchars($video['video_url']); ?>" title="<?php echo htmlspecialchars($video['title']); ?>" frameborder="0" allowfullscreen></iframe>
                </div>
            <?php endif; ?>
        </div>

        <div class="video-details">
            <h1><?php echo htmlspecialchars($video['title']); ?></h1>
            <p><?php echo nl2br(htmlspecialchars($video['description'] ?? '')); ?></p>
        </div>

        <!-- Comments Section -->
        <div class="comments-section">
            <h3>Comments (<?php echo count($comments); ?>)</h3>

            <?php if (isLoggedIn()): ?>
                <div class="comment-form-wrapper">
                    <form method="POST" enctype="multipart/form-data" class="comment-form">
                        <div class="form-group">
                            <label for="comment_content">Your Comment</label>
                            <textarea id="comment_content" name="comment_content" rows="3" placeholder="Share your thoughts..." required></textarea>
                        </div>

                        <!-- Live Photo Capture for Comment -->
                        <div class="form-group">
                            <label>Add a photo (optional)</label>
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
                                <input type="file" id="liveCommentPhotoInput" name="live_comment_photo" accept="image/*" style="display:none;">
                            </div>
                        </div>

                        <button type="submit" name="submit_comment" class="btn btn-primary">Post Comment</button>
                    </form>
                </div>
            <?php else: ?>
                <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to post a comment.</p>
            <?php endif; ?>

            <?php if (count($comments) > 0): ?>
                <div class="comments-list">
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-avatar">
                                <?php if ($comment['avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($comment['avatar']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <?php echo strtoupper(substr($comment['display_name'] ?: $comment['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-body">
                                <div class="comment-meta">
                                    <strong><?php echo htmlspecialchars($comment['display_name'] ?: $comment['username']); ?></strong>
                                    <small><?php echo time_ago($comment['created_at']); ?></small>
                                </div>
                                <p class="comment-content"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                                <?php if ($comment['image_path']): ?>
                                    <div class="comment-image">
                                        <img src="<?php echo SITE_URL . '/' . $comment['image_path']; ?>" alt="Comment photo" style="max-width:200px; border-radius:8px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-comments">No comments yet. Be the first to comment!</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ===== CAMERA LOGIC FOR COMMENT FORM =====
document.addEventListener('DOMContentLoaded', function() {
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraPlaceholder = document.getElementById('cameraPlaceholder');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const capturePhotoBtn = document.getElementById('capturePhotoBtn');
    const retakePhotoBtn = document.getElementById('retakePhotoBtn');
    const confirmPhotoBtn = document.getElementById('confirmPhotoBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const capturedPhotoContainer = document.getElementById('capturedPhotoContainer');
    const capturedPhotoPreview = document.getElementById('capturedPhotoPreview');
    const liveCommentPhotoInput = document.getElementById('liveCommentPhotoInput');

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
        const file = new File([capturedBlob], 'comment_photo.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        liveCommentPhotoInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);
});
</script>

<style>
.video-watch-page { padding: 32px 0 60px; }
.video-player-wrapper { margin-bottom: 24px; background: var(--vanilla); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.video-player-wrapper video { width: 100%; display: block; }
.video-embed-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; }
.video-embed-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.video-details h1 { margin-bottom: 8px; }

.comments-section { margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
.comment-form-wrapper { background: var(--fantasy); padding: 20px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 24px; }
.comment-form .form-group { margin-bottom: 16px; }
.comment-form label { display: block; font-weight: 600; margin-bottom: 4px; }
.comment-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; resize: vertical; }

/* ===== CAMERA SECTION IN COMMENT FORM ===== */
.camera-section { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: var(--card-bg); margin-top: 8px; }
.camera-preview-container { width: 100%; max-width: 300px; height: 180px; background: var(--vanilla); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; margin: 0 auto; }
.camera-preview-container video { width: 100%; height: 100%; object-fit: cover; display: none; }
.camera-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-light); text-align: center; padding: 16px; }
.camera-placeholder i { font-size: 2rem; margin-bottom: 4px; color: var(--rose); }
.camera-placeholder p { margin: 0; font-size: 0.85rem; }
.camera-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; align-items: center; margin-top: 8px; }
.camera-controls .btn { padding: 4px 12px; font-size: 0.8rem; }
.captured-photo-container { text-align: center; margin-top: 8px; }
.captured-photo-container img { border: 2px solid var(--rose); border-radius: 6px; }
.status-indicator { font-size: 0.8rem; color: var(--text-light); margin-left: 6px; font-weight: 500; }

.comments-list { display: flex; flex-direction: column; gap: 16px; }
.comment-item { display: flex; gap: 12px; background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
.comment-avatar { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; overflow: hidden; background: var(--rose); }
.comment-avatar img { width: 100%; height: 100%; object-fit: cover; }
.avatar-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
.comment-body { flex: 1; }
.comment-meta { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.comment-meta strong { font-size: 0.95rem; }
.comment-meta small { font-size: 0.8rem; color: var(--text-light); }
.comment-content { margin: 6px 0 0; line-height: 1.6; }
.comment-image { margin-top: 8px; }
.comment-image img { border-radius: 8px; border: 1px solid var(--border); }
.no-comments { text-align: center; padding: 24px 0; color: var(--text-light); }

@media (max-width: 480px) {
    .comment-item { flex-direction: column; }
}
</style>

<?php require_once 'includes/footer.php'; ?>