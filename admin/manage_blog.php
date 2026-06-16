<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Blog post deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_blog.php');
    exit;
}

// ===== BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids = isset($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
    $action = $_POST['bulk_action'];

    if (!empty($ids) && $action === 'delete') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM blog_posts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts deleted.';
    } elseif (!empty($ids) && $action === 'publish') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE blog_posts SET status = 'published' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts published.';
    } elseif (!empty($ids) && $action === 'draft') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE blog_posts SET status = 'draft' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $success = count($ids) . ' blog posts moved to draft.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_blog.php');
    exit;
}

// ===== FETCH TOTAL POSTS =====
$count_sql = "SELECT COUNT(*) FROM blog_posts";
$count_params = [];
if ($search) {
    $count_sql .= " WHERE title LIKE ? OR content LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_posts = $stmt->fetchColumn();
$total_pages = ceil($total_posts / $limit);

// ===== FETCH POSTS =====
$sql = "SELECT * FROM blog_posts";
$params = [];
if ($search) {
    $sql .= " WHERE title LIKE ? OR content LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Blog';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Blog</h1>
            <div class="admin-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <button id="openCameraBtn" class="btn btn-secondary">
                    <i class="fas fa-camera"></i> Capture Image
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Post
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
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

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search posts by title or content..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Posts (<?php echo $total_posts; ?>)</h2>
                <div class="card-header-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="bulkActionSelect" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border);font-size:0.85rem;">
                        <option value="">Bulk Actions</option>
                        <option value="publish">Publish Selected</option>
                        <option value="draft">Move to Draft</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($posts) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllRows"></th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Views</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posts as $post): ?>
                                        <tr>
                                            <td><input type="checkbox" class="row-select" value="<?php echo $post['id']; ?>"></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                                <br><small><?php echo date('M j, Y', strtotime($post['created_at'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($post['category'] ?? 'General'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $post['status']; ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($post['views'] ?? 0); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($post['created_at'])); ?></td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog&id=<?php echo $post['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?delete=<?php echo $post['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this post?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                                <?php if ($post['status'] === 'published'): ?>
                                                    <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-items">No blog posts yet. <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=blog">Create the first post</a>.</p>
                <?php endif; ?>
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

<!-- Hidden input for captured image -->
<input type="file" id="liveThumbnailInput" name="live_thumbnail" accept="image/*" style="display:none;">

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --vanilla: #EFD8D6;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44, 30, 30, 0.08);
    --shadow-hover: 0 8px 30px rgba(44, 30, 30, 0.15);
    --input-bg: #ffffff;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text: #e8dddd;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

/* ===== ADMIN PAGE STYLES ===== */
.admin-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }
.admin-table tbody tr:last-child td { border-bottom: none; }
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.draft { background: #f1c40f; color: #fff; }
.status-badge.published { background: #27ae60; color: #fff; }
.status-badge.archived { background: #e74c3c; color: #fff; }

.actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }

.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== FULL-SCREEN CAMERA MODAL STYLES ===== */
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

@media (max-width: 480px) {
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
    .admin-table th, .admin-table td { padding: 8px 10px; font-size: 0.85rem; }
    .camera-bottom-controls { bottom: 16px; padding: 0 12px; padding-bottom: 16px; }
    .camera-shutter-btn { width: 64px; height: 64px; }
    .camera-shutter-btn .shutter-inner { width: 48px; height: 48px; }
    .camera-btn { font-size: 0.75rem; padding: 6px 12px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // THEME TOGGLE
    // ============================================================
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('blogManagerTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('blogManagerTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ============================================================
    // BULK ACTIONS
    // ============================================================
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn.addEventListener('click', function() {
        const action = bulkActionSelect.value;
        const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || ids.length === 0) {
            alert('Please select an action and at least one post.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} post(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = ids.join(',');
        document.getElementById('bulkForm').submit();
    });

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
    const liveThumbnailInput = document.getElementById('liveThumbnailInput');
    const modeBtns = document.querySelectorAll('.mode-btn');
    const openCameraBtn = document.getElementById('openCameraBtn');

    let cameraStream = null;
    let mediaRecorder = null;
    let recordedChunks = [];
    let capturedBlob = null;
    let recordedBlob = null;
    let currentMode = 'photo';

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

    function confirmMedia() {
        if (currentMode === 'photo' && capturedBlob) {
            const file = new File([capturedBlob], 'captured_image.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            liveThumbnailInput.files = dt.files;
            closeCamera();
            alert('✅ Image captured and ready for upload!');
        } else if (currentMode === 'video' && recordedBlob) {
            const file = new File([recordedBlob], 'captured_video.webm', { type: 'video/webm' });
            const dt = new DataTransfer();
            dt.items.add(file);
            liveThumbnailInput.files = dt.files;
            closeCamera();
            alert('✅ Video captured and ready for upload!');
        }
    }

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
});
</script>

<?php require_once '../includes/footer.php'; ?>