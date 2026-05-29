<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch poem
$stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
$stmt->execute([$id]);
$poem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$poem) {
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $valid_statuses = ['draft', 'review', 'published', 'archived'];
    if (in_array($new_status, $valid_statuses)) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO poem_status (poem_id, status, reviewed_at, reviewed_by) VALUES (?, ?, CURRENT_TIMESTAMP, ?)");
        $stmt->execute([$id, $new_status, $_SESSION['user_id']]);
        $success = 'Poem status updated to ' . ucfirst($new_status) . '.';
    }
}

// Fetch current status
$stmt = $db->prepare("SELECT status FROM poem_status WHERE poem_id = ?");
$stmt->execute([$id]);
$status_row = $stmt->fetch(PDO::FETCH_ASSOC);
$current_status = $status_row['status'] ?? 'published';

$pageTitle = 'Preview: ' . htmlspecialchars($poem['title']);
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-preview-page">
    <div class="container">
        <div class="preview-header">
            <h1>Preview: <?php echo htmlspecialchars($poem['title']); ?></h1>
            <div class="preview-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?edit=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Edit Poem
                </a>
                <form method="POST" class="status-form">
                    <select name="status" onchange="this.form.submit()" class="status-select">
                        <option value="draft" <?php echo $current_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="review" <?php echo $current_status === 'review' ? 'selected' : ''; ?>>In Review</option>
                        <option value="published" <?php echo $current_status === 'published' ? 'selected' : ''; ?>>Published</option>
                        <option value="archived" <?php echo $current_status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                    <input type="hidden" name="update_status" value="1">
                    <button type="submit" class="btn btn-sm btn-primary">Update Status</button>
                </form>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Poem Display (exactly as public sees it) -->
        <div class="poem-preview-content">
            <div class="poem-view-page">
                <header class="poem-header">
                    <h1><?php echo htmlspecialchars($poem['title']); ?></h1>
                    <div class="poem-meta">
                        <span class="poem-date"><?php echo date('F j, Y', strtotime($poem['created_at'])); ?></span>
                        <span class="poem-status-badge <?php echo $current_status; ?>"><?php echo ucfirst($current_status); ?></span>
                    </div>
                </header>

                <?php if ($poem['image_path']): ?>
                    <div class="poem-image-container">
                        <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" class="poem-feature-image">
                    </div>
                <?php endif; ?>

                <?php if ($poem['audio_path']): ?>
                    <div class="poem-audio-player">
                        <audio controls>
                            <source src="<?php echo SITE_URL . '/' . $poem['audio_path']; ?>" type="audio/mpeg">
                        </audio>
                    </div>
                <?php endif; ?>

                <?php if ($poem['intro']): ?>
                    <div class="poem-intro-section">
                        <div class="intro-label">✧ Purpose of this poem</div>
                        <div class="intro-body"><?php echo nl2br(htmlspecialchars($poem['intro'])); ?></div>
                    </div>
                <?php endif; ?>

                <div class="poem-content-section">
                    <div class="poem-body"><?php echo nl2br(htmlspecialchars($poem['content'])); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.admin-preview-page { padding: 32px 0 60px; }
.preview-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.preview-header h1 { font-size: 1.8rem; margin: 0; }
.preview-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.status-form { display: flex; gap: 8px; align-items: center; }
.status-select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.poem-status-badge { padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.poem-status-badge.draft { background: #95a5a6; color: white; }
.poem-status-badge.review { background: #f39c12; color: white; }
.poem-status-badge.published { background: #2ecc71; color: white; }
.poem-status-badge.archived { background: #e74c3c; color: white; }
.poem-preview-content { max-width: 780px; margin: 0 auto; }
</style>

<?php require_once '../includes/footer.php'; ?>