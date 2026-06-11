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
    
    $stmt = $db->prepare("SELECT thumbnail FROM videos WHERE id = ?");
    $stmt->execute([$id]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($video) {
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        if (!empty($video['thumbnail']) && file_exists($doc_root . '/' . $video['thumbnail'])) {
            @unlink($doc_root . '/' . $video['thumbnail']);
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
    } elseif (empty($video_url)) {
        $error = 'Video URL is required.';
    } else {
        $thumbnail = null;
        if (!empty($_FILES['thumbnail']['name'])) {
            $upload_dir = '../assets/uploads/videos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $thumb_filename = 'thumb_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['thumbnail']['name']);
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_dir . $thumb_filename)) {
                $thumbnail = 'assets/uploads/videos/' . $thumb_filename;
            }
        }

        if ($id > 0) {
            $stmt = $db->prepare("UPDATE videos SET title = ?, description = ?, video_url = ?, type = ?, thumbnail = COALESCE(?, thumbnail) WHERE id = ?");
            $stmt->execute([$title, $description, $video_url, $type, $thumbnail, $id]);
            $success = 'Video updated successfully.';
        } else {
            $stmt = $db->prepare("INSERT INTO videos (title, description, video_url, type, thumbnail) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $video_url, $type, $thumbnail]);
            $success = 'Video added successfully.';
        }
        header('Location: ' . SITE_URL . '/admin/manage_videos.php');
        exit;
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
                    <h2>Add New Video</h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="admin-form">
                        <input type="hidden" name="video_id" id="video_id" value="0">
                        <input type="hidden" name="save_video" value="1">
                        <div class="form-group">
                            <label for="title">Video Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="video_url">Video URL <span class="required">*</span></label>
                            <input type="text" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=..." required>
                        </div>
                        <div class="form-group">
                            <label for="type">Type</label>
                            <select id="type" name="type">
                                <option value="short">Short</option>
                                <option value="full">Full</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="thumbnail">Thumbnail Image</label>
                            <input type="file" id="thumbnail" name="thumbnail" accept="image/*">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Video</button>
                            <button type="button" class="btn btn-outline" id="cancelForm">Cancel</button>
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
                                    <th>URL</th>
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
                                        <td><a href="<?php echo htmlspecialchars($video['video_url']); ?>" target="_blank" class="video-link"><i class="fas fa-external-link-alt"></i> View</a></td>
                                        <td><?php echo date('M j, Y', strtotime($video['created_at'])); ?></td>
                                        <td class="actions">
                                            <button class="btn btn-sm btn-secondary edit-btn" data-id="<?php echo $video['id']; ?>" data-title="<?php echo htmlspecialchars($video['title']); ?>" data-description="<?php echo htmlspecialchars($video['description'] ?? ''); ?>" data-url="<?php echo htmlspecialchars($video['video_url']); ?>" data-type="<?php echo $video['type']; ?>">
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
    const showAddBtn = document.getElementById('showAddForm');
    const formContainer = document.getElementById('videoFormContainer');
    const cancelBtn = document.getElementById('cancelForm');
    const videoIdInput = document.getElementById('video_id');

    function toggleForm(show) {
        formContainer.style.display = show ? 'block' : 'none';
        if (show) formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        else window.location.href = '<?php echo SITE_URL; ?>/admin/manage_videos.php';
    }

    showAddBtn.addEventListener('click', function() {
        videoIdInput.value = 0;
        document.getElementById('title').value = '';
        document.getElementById('description').value = '';
        document.getElementById('video_url').value = '';
        document.getElementById('type').value = 'short';
        document.querySelector('.card-header h2').textContent = 'Add New Video';
        toggleForm(true);
    });

    cancelBtn.addEventListener('click', function() { toggleForm(false); });

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            videoIdInput.value = this.dataset.id;
            document.getElementById('title').value = this.dataset.title;
            document.getElementById('description').value = this.dataset.description;
            document.getElementById('video_url').value = this.dataset.url;
            document.getElementById('type').value = this.dataset.type;
            document.querySelector('.card-header h2').textContent = 'Edit Video';
            toggleForm(true);
        });
    });
});
</script>

<style>
.admin-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }

.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }

.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 8px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.95rem; }
.admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.08); }
.admin-table tbody tr:last-child td { border-bottom: none; }
.table-responsive { overflow-x: auto; margin-bottom: 16px; border-radius: 12px; }
.no-items { text-align: center; padding: 40px 0; color: var(--text-light); }

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.available { background: #2ecc71; color: white; }
.status-badge.missing { background: #e74c3c; color: white; }

.actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }
.video-link { color: var(--rose); text-decoration: none; }
.video-link:hover { text-decoration: underline; }
</style>

<?php require_once '../includes/footer.php'; ?>