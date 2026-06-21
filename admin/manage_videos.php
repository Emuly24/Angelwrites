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
                            <label>Thumbnail</label>
                            <div class="camera-trigger-group">
                                <button type="button" class="btn btn-secondary" id="openPhotoCameraBtn">
                                    <i class="fas fa-camera"></i> Open Camera (Photo)
                                </button>
                                <span id="photoStatus" class="status-indicator">No photo captured</span>
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
                                        <button type="button" class="btn btn-secondary" id="openVideoCameraBtn">
                                            <i class="fas fa-video"></i> Open Camera (Video)
                                        </button>
                                        <span id="videoStatus" class="status-indicator">No video recorded</span>
                                        <div class="file-input-wrapper" style="margin-top:10px;">
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

<!-- ===== FULL-SCREEN CAMERA MODAL (ENHANCED) ===== -->
<div id="cameraModal" class="camera-modal" style="display:none;">
    <div class="camera-modal-inner">
        <!-- Shutter Flash Overlay -->
        <div id="shutterFlash" class="shutter-flash" style="display:none;"></div>
        
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
            
            <!-- 🚀 NEW: Filter Effects -->
            <div class="camera-effects">
                <button type="button" class="filter-btn active" data-filter="none">Normal</button>
                <button type="button" class="filter-btn" data-filter="grayscale(100%)">B&W</button>
                <button type="button" class="filter-btn" data-filter="sepia(100%)">Sepia</button>
                <button type="button" class="filter-btn" data-filter="invert(100%)">Invert</button>
                <button type="button" class="filter-btn" data-filter="contrast(150%) brightness(120%)">Vivid</button>
            </div>
        </div>

        <!-- Bottom Controls -->
        <div class="camera-bottom-controls">
            <div class="camera-controls-left">
                <!-- 🚀 NEW: Flip Camera Button -->
                <button type="button" id="flipCameraBtn" class="camera-btn">
                    <i class="fas fa-sync-alt"></i> Flip
                </button>
                <button type="button" id="retakeMediaBtn" class="camera-btn" disabled>
                    <i class="fas fa-redo"></i> Retake
                </button>
                <button type="button" id="timerBtn" class="camera-btn" data-timer="off">
                    <i class="fas fa-clock"></i> <span id="timerLabel">Timer: Off</span>
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

        <!-- Status / Timer Countdown -->
        <div id="cameraStatus" class="camera-status">Ready</div>
        <div id="timerCountdown" class="camera-timer" style="display:none;"></div>
    </div>
</div>

<style>
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

/* 🚀 Shutter Flash Effect */
.shutter-flash {
    position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
    background: rgba(255, 255, 255, 0.9); pointer-events: none; z-index: 6; display: none;
}

/* Top Bar */
.camera-top-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    padding: 20px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.6) 0%, transparent 100%);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    z-index: 2;
    flex-wrap: wrap;
    gap: 10px;
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

/* 🚀 Camera Filter Effects UI */
.camera-effects {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    background: rgba(0,0,0,0.4);
    padding: 6px 10px;
    border-radius: 30px;
    backdrop-filter: blur(2px);
}
.filter-btn {
    background: transparent; border: none; color: rgba(255,255,255,0.6); 
    padding: 4px 8px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; cursor: pointer; transition: all 0.2s;
}
.filter-btn.active { background: rgba(255,255,255,0.9); color: #000; }
.filter-btn:hover:not(.active) { color: #fff; background: rgba(255,255,255,0.1); }

/* Bottom Controls */
.camera-bottom-controls {
    position: absolute;
    bottom: 30px;
    left: 0;
    right: 0;
    padding: 0 20px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    z-index: 2;
    background: linear-gradient(to top, rgba(0,0,0,0.6) 0%, transparent 100%);
    padding-top: 30px;
    padding-bottom: 30px;
}

.camera-controls-left, .camera-controls-right {
    flex: 0 0 100px;
    display: flex;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
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
    width: 100%; height: 100%;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.3);
    top: -4px; left: -4px;
    pointer-events: none;
}

.camera-shutter-btn .shutter-inner {
    width: 56px; height: 56px;
    border-radius: 50%;
    background: #fff;
    transition: all 0.2s;
}
.camera-shutter-btn:hover .shutter-inner { opacity: 0.8; }
.camera-shutter-btn.recording .shutter-inner {
    background: #ff3b30; width: 24px; height: 24px; border-radius: 6px;
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
.camera-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.camera-btn:hover:not(:disabled) { background: rgba(255,255,255,0.3); }

/* Status & Timer */
.camera-status, .camera-timer {
    position: absolute;
    left: 50%; transform: translateX(-50%);
    color: #fff; font-size: 1.2rem; font-weight: 500;
    text-shadow: 0 0 20px rgba(0,0,0,0.5);
    z-index: 2;
    background: rgba(0,0,0,0.5);
    padding: 6px 16px;
    border-radius: 20px;
    backdrop-filter: blur(4px);
}
.camera-status { bottom: 110px; }
.camera-timer { bottom: 150px; display: none; font-size: 2.5rem; padding: 10px 20px; }

/* ===== EXISTING STYLES ===== */
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
.admin-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.95rem; }
.admin-form input[type="text"], .admin-form input[type="email"], .admin-form select, .admin-form textarea {
    width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text);
}
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.required { color: #dc2626; }
.admin-form .form-actions { display: flex; gap: 12px; margin-top: 16px; }
.admin-form .btn-large { padding: 14px 28px; border-radius: 30px; font-size: 1.05rem; }
.video-upload-section { border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; background: var(--fantasy); }
.upload-tabs { margin-top: 8px; }
.tab-buttons { display: flex; gap: 8px; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 4px; }
.tab-btn { background: transparent; border: none; padding: 8px 16px; font-weight: 500; color: var(--text-light); cursor: pointer; border-radius: 8px 8px 0 0; transition: all 0.2s; }
.tab-btn.active { color: var(--rose); border-bottom: 2px solid var(--rose); }
.tab-btn:hover { color: var(--rose); }
.tab-content { display: none; padding-top: 8px; }
.tab-content.active { display: block; }
.recorder-wrapper { padding: 12px 0; }
.camera-trigger-group { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.status-indicator { font-size: 0.9rem; color: var(--text-light); }
.file-status { font-size: 0.85rem; color: var(--text-light); }

@media (max-width: 768px) {
    .admin-form .form-row { grid-template-columns: 1fr; }
    .tab-buttons { flex-direction: column; gap: 4px; border-bottom: none; }
    .tab-btn { border-radius: 8px; border: 1px solid var(--border); }
    .camera-modal-inner { padding: 0; }
    .camera-top-bar { padding: 12px; flex-direction: column; align-items: flex-end; }
    .camera-bottom-controls { bottom: 16px; padding: 0 12px; padding-bottom: 16px; }
    .camera-shutter-btn { width: 64px; height: 64px; }
    .camera-shutter-btn .shutter-inner { width: 48px; height: 48px; }
    .camera-btn { font-size: 0.75rem; padding: 6px 12px; }
}
</style>

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
            toggleForm(true);
        });
    });

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

    // ============================================================
    // FULL-SCREEN CAMERA MODAL (ENHANCED)
    // ============================================================
    const cameraModal = document.getElementById('cameraModal');
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraCloseBtn = document.getElementById('cameraCloseBtn');
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeMediaBtn');
    const confirmBtn = document.getElementById('confirmMediaBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const liveThumbnailInput = document.getElementById('liveThumbnailInput');
    const videoFileInput = document.getElementById('video_file');
    const fileChosen = document.getElementById('fileChosen');
    const photoStatus = document.getElementById('photoStatus');
    const videoStatus = document.getElementById('videoStatus');
    const openPhotoBtn = document.getElementById('openPhotoCameraBtn');
    const openVideoBtn = document.getElementById('openVideoCameraBtn');
    const modeBtns = document.querySelectorAll('.mode-btn');
    const flipBtn = document.getElementById('flipCameraBtn');
    const filterBtns = document.querySelectorAll('.filter-btn');
    const timerBtn = document.getElementById('timerBtn');
    const timerLabel = document.getElementById('timerLabel');
    const timerCountdown = document.getElementById('timerCountdown');
    const shutterFlash = document.getElementById('shutterFlash');

    let cameraStream = null;
    let mediaRecorder = null;
    let recordedChunks = [];
    let capturedBlob = null;
    let recordedBlob = null;
    let currentMode = 'photo';
    let currentFacingMode = 'user'; // 'user' (front) or 'environment' (back)
    let currentFilter = 'none';
    let timerEnabled = false; // false = instant, true = 3s delay
    let countdownInterval = null;

    // ===== OPEN CAMERA =====
    function openCamera(mode) {
        currentMode = mode;
        cameraModal.style.display = 'flex';
        cameraStatus.textContent = 'Starting camera...';
        modeBtns.forEach(btn => btn.classList.toggle('active', btn.dataset.mode === mode));
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
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
            timerCountdown.style.display = 'none';
        }
    }

    async function startCameraStream() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                cameraStatus.textContent = '❌ Camera not supported';
                return;
            }
            cameraStream = await navigator.mediaDevices.getUserMedia({ 
                video: { facingMode: currentFacingMode }, 
                audio: currentMode === 'video' 
            });
            cameraPreview.srcObject = cameraStream;
            // Re-apply filter when stream restarts
            cameraPreview.style.filter = currentFilter;
            cameraStatus.textContent = currentMode === 'photo' ? 'Ready' : 'Ready to record';
        } catch (error) {
            cameraStatus.textContent = '❌ Camera access denied: ' + error.message;
        }
    }

    // 🚀 FLIP CAMERA LOGIC
    flipBtn.addEventListener('click', function() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
        startCameraStream();
        cameraStatus.textContent = `Switched to ${currentFacingMode === 'user' ? 'Front' : 'Back'} Camera`;
    });

    // 🚀 FILTER EFFECTS LOGIC
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            if (cameraPreview) cameraPreview.style.filter = currentFilter;
        });
    });

    // 🚀 TIMER LOGIC (Toggle between Off, 3s, 5s)
    timerBtn.addEventListener('click', function() {
        if (timerEnabled === false) {
            timerEnabled = 3;
            timerLabel.textContent = 'Timer: 3s';
            timerBtn.classList.add('active');
        } else if (timerEnabled === 3) {
            timerEnabled = 5;
            timerLabel.textContent = 'Timer: 5s';
        } else {
            timerEnabled = false;
            timerLabel.textContent = 'Timer: Off';
            timerBtn.classList.remove('active');
        }
    });

    function startCountdown(duration) {
        let seconds = duration;
        timerCountdown.textContent = seconds;
        timerCountdown.style.display = 'block';
        captureBtn.disabled = true;

        countdownInterval = setInterval(() => {
            seconds--;
            timerCountdown.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                timerCountdown.style.display = 'none';
                captureBtn.disabled = false;
                if (currentMode === 'photo') {
                    capturePhoto();
                } else {
                    startRecording();
                }
            }
        }, 1000);
    }

    // 🚀 SHUTTER FLASH EFFECT
    function triggerFlash() {
        shutterFlash.style.display = 'block';
        shutterFlash.style.opacity = 1;
        setTimeout(() => {
            shutterFlash.style.opacity = 0;
            setTimeout(() => shutterFlash.style.display = 'none', 300);
        }, 150);
    }

    // ===== CAPTURE PHOTO =====
    function capturePhoto() {
        if (!cameraStream) return;
        triggerFlash();
        const canvas = document.createElement('canvas');
        canvas.width = cameraPreview.videoWidth || 1280;
        canvas.height = cameraPreview.videoHeight || 720;
        const ctx = canvas.getContext('2d');
        ctx.filter = currentFilter; // Apply filter to captured image!
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
        // Apply filter to video preview element, the recorder captures it natively
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
            captureBtn.querySelector('.shutter-inner').style.borderRadius = '50%';
        }
    }

    function retakeMedia() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
            timerCountdown.style.display = 'none';
            captureBtn.disabled = false;
        }
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
            const file = new File([capturedBlob], 'live_thumbnail.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            liveThumbnailInput.files = dt.files;
            photoStatus.textContent = '✅ Photo confirmed!';
            photoStatus.style.color = '#2ecc71';
            closeCamera();
        } else if (currentMode === 'video' && recordedBlob) {
            const file = new File([recordedBlob], 'recorded_video.webm', { type: 'video/webm' });
            const dt = new DataTransfer();
            dt.items.add(file);
            videoFileInput.files = dt.files;
            fileChosen.textContent = '✅ Video confirmed!';
            videoStatus.textContent = '✅ Video confirmed!';
            videoStatus.style.color = '#2ecc71';
            closeCamera();
        }
    }

    // ===== EVENT LISTENERS =====
    openPhotoBtn.addEventListener('click', function() { openCamera('photo'); });
    openVideoBtn.addEventListener('click', function() { openCamera('video'); });
    cameraCloseBtn.addEventListener('click', closeCamera);

    captureBtn.addEventListener('click', function() {
        if (currentMode === 'photo') {
            if (timerEnabled !== false) {
                startCountdown(timerEnabled);
                cameraStatus.textContent = `⏳ ${timerEnabled} seconds countdown!`;
            } else {
                capturePhoto();
            }
        } else {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                stopRecording();
            } else {
                if (timerEnabled !== false) {
                    startCountdown(timerEnabled);
                } else {
                    startRecording();
                }
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
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                timerCountdown.style.display = 'none';
                captureBtn.disabled = false;
            }
            closeCamera();
            setTimeout(() => openCamera(mode), 200);
        });
    });

    // File input changes
    document.getElementById('thumbnail').addEventListener('change', function() {
        if (this.files && this.files[0]) {
            document.querySelector('.camera-trigger-group .status-indicator').textContent = '📎 ' + this.files[0].name;
        }
    });
    videoFileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            fileChosen.textContent = '📎 ' + this.files[0].name;
        }
    });

    // Keyboard shortcut: Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && cameraModal.style.display !== 'none') {
            closeCamera();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>