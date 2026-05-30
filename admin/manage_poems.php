<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // Get poem to delete files
    $stmt = $db->prepare("SELECT image_path, audio_path FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $poem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($poem) {
        if ($poem['image_path']) {
            $image_file = '../' . $poem['image_path'];
            if (file_exists($image_file)) {
                unlink($image_file);
            }
        }
        if ($poem['audio_path']) {
            $audio_file = '../' . $poem['audio_path'];
            if (file_exists($audio_file)) {
                unlink($audio_file);
            }
        }
    }
    
    $stmt = $db->prepare("DELETE FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Poem deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== FETCH ALL POEMS =====
$stmt = $db->query("SELECT * FROM poems ORDER BY created_at DESC");
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Poems';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Manage Poems</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=poem" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> Add New Poem
                </a>
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

        <div class="card">
            <div class="card-header">
                <h2>All Poems (<?php echo count($poems); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($poems) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Title</th>
                                    <th>Introduction</th>
                                    <th>Audio</th>
                                    <th>Views</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($poems as $poem): ?>
                                    <tr>
                                        <td>
                                            <?php if ($poem['image_path']): ?>
                                                <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;">
                                            <?php else: ?>
                                                <div style="width: 50px; height: 50px; background: var(--vanilla); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-light);">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                            <br><small><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($poem['intro']): ?>
                                                <span class="intro-preview"><?php echo htmlspecialchars(substr($poem['intro'], 0, 60)); ?>...</span>
                                            <?php else: ?>
                                                <span class="text-muted">No introduction</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($poem['audio_path']): ?>
                                                <i class="fas fa-music" style="color: var(--rose);"></i>
                                                <span class="audio-label">Yes</span>
                                            <?php else: ?>
                                                <span class="text-muted">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($poem['view_count'] ?? 0); ?></td>
                                        <td class="actions">
                                            <a href="<?php echo SITE_URL; ?>/admin/editor.php?type=poem&id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?delete=<?php echo $poem['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this poem?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No poems yet. Click "Add New Poem" to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== ADMIN TABLE STYLES ===== */
.admin-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 8px;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
}

.admin-table thead {
    background: var(--vanilla);
}

.admin-table th {
    text-align: left;
    padding: 14px 20px;
    font-weight: 600;
    color: var(--text);
    border-bottom: 2px solid var(--border);
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.admin-table td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    color: var(--text);
    font-size: 0.95rem;
}

.admin-table tbody tr {
    transition: all var(--transition);
}

.admin-table tbody tr:hover {
    background: rgba(219, 161, 162, 0.08);
    cursor: default;
}

.admin-table tbody tr:last-child td {
    border-bottom: none;
}

.table-responsive {
    overflow-x: auto;
    margin-bottom: 16px;
    border-radius: 12px;
}

/* Small screens tweaks */
@media (max-width: 768px) {
    .admin-table th,
    .admin-table td {
        padding: 10px 12px;
        font-size: 0.85rem;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>