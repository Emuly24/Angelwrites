<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';
$edit_poem = null;

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // Get poem to delete files
    $stmt = $db->prepare("SELECT image_path, audio_path FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $poem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($poem) {
        // Delete image file
        if ($poem['image_path']) {
            $image_file = '../' . $poem['image_path'];
            if (file_exists($image_file)) {
                unlink($image_file);
            }
        }
        // Delete audio file
        if ($poem['audio_path']) {
            $audio_file = '../' . $poem['audio_path'];
            if (file_exists($audio_file)) {
                unlink($audio_file);
            }
        }
    }
    
    // Delete poem from database
    $stmt = $db->prepare("DELETE FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Poem deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_poems.php');
    exit;
}

// ===== HANDLE EDIT FETCH =====
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
    $stmt->execute([$id]);
    $edit_poem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_poem) {
        $error = 'Poem not found.';
    }
}

// ===== HANDLE FORM SUBMISSION (ADD / UPDATE) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['poem_id']) ? (int)$_POST['poem_id'] : 0;
    $title = trim($_POST['title']);
    $intro = trim($_POST['intro']);
    $content = trim($_POST['content']);

    // Basic validation
    if (empty($title)) {
        $error = 'Poem title is required.';
    } elseif (empty($content)) {
        $error = 'Poem content is required.';
    } else {
        // Handle image upload
        $image_path = $edit_poem['image_path'] ?? '';
        if (!empty($_FILES['image']['name'])) {
            $upload_dir = '../assets/uploads/poems/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Validate image type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = $_FILES['image']['type'];
            if (!in_array($file_type, $allowed_types)) {
                $error = 'Please upload a valid image (JPEG, PNG, GIF, WEBP).';
            } else {
                // Delete old image if exists
                if ($edit_poem && $edit_poem['image_path']) {
                    $old_image = '../' . $edit_poem['image_path'];
                    if (file_exists($old_image)) {
                        unlink($old_image);
                    }
                }
                
                $image_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['image']['name']);
                $image_path = 'assets/uploads/poems/' . $image_filename;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], '../' . $image_path)) {
                    $error = 'Failed to upload image file.';
                }
            }
        }

        // Handle audio upload
        $audio_path = $edit_poem['audio_path'] ?? '';
        if (empty($error) && !empty($_FILES['audio']['name'])) {
            $upload_dir = '../assets/uploads/audio/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Validate audio type
            $allowed_types = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg'];
            $file_type = $_FILES['audio']['type'];
            $file_ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_type, $allowed_types) && !in_array($file_ext, ['mp3', 'wav', 'ogg'])) {
                $error = 'Please upload a valid audio file (MP3, WAV, OGG).';
            } else {
                // Delete old audio if exists
                if ($edit_poem && $edit_poem['audio_path']) {
                    $old_audio = '../' . $edit_poem['audio_path'];
                    if (file_exists($old_audio)) {
                        unlink($old_audio);
                    }
                }
                
                $audio_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['audio']['name']);
                $audio_path = 'assets/uploads/audio/' . $audio_filename;
                if (!move_uploaded_file($_FILES['audio']['tmp_name'], '../' . $audio_path)) {
                    $error = 'Failed to upload audio file.';
                }
            }
        }

        if (empty($error)) {
            if ($id > 0) {
                // Update existing poem
                $stmt = $db->prepare("
                    UPDATE poems SET 
                        title = ?, intro = ?, content = ?, image_path = ?, audio_path = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $intro, $content, $image_path, $audio_path, $id]);
                $success = 'Poem updated successfully.';
            } else {
                // Insert new poem
                $stmt = $db->prepare("
                    INSERT INTO poems (title, intro, content, image_path, audio_path) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $intro, $content, $image_path, $audio_path]);
                $success = 'Poem added successfully.';
            }
            // Redirect to clear form
            header('Location: ' . SITE_URL . '/admin/manage_poems.php');
            exit;
        }
    }
}

// ===== FETCH ALL POEMS FOR LISTING =====
$stmt = $db->query("SELECT * FROM poems ORDER BY created_at DESC");
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Poems';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- Page Header -->
        <div class="admin-header">
            <h1>Manage Poems</h1>
            <div class="admin-actions">
                <button id="showAddForm" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> Add New Poem
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Poem Form (hidden by default) -->
        <div class="poem-form-container" id="poemFormContainer" style="display: <?php echo ($edit_poem || isset($_GET['edit'])) ? 'block' : 'none'; ?>;">
            <div class="card">
                <div class="card-header">
                    <h2><?php echo $edit_poem ? 'Edit Poem' : 'Add New Poem'; ?></h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="poem-form">
                        <input type="hidden" name="poem_id" value="<?php echo $edit_poem['id'] ?? 0; ?>">

                        <div class="form-group">
                            <label for="title">Poem Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($edit_poem['title'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="intro">Purpose / Introduction</label>
                            <textarea id="intro" name="intro" rows="3" placeholder="Write a short introduction explaining the purpose or inspiration behind this poem."><?php echo htmlspecialchars($edit_poem['intro'] ?? ''); ?></textarea>
                            <small class="field-hint">This will appear before the poem as an introductory section.</small>
                        </div>

                        <div class="form-group">
                            <label for="content">Poem Content <span class="required">*</span></label>
                            <textarea id="content" name="content" rows="8" placeholder="Write your poem here..." required><?php echo htmlspecialchars($edit_poem['content'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="image">Poem Image</label>
                            <input type="file" id="image" name="image" accept="image/*">
                            <?php if ($edit_poem && $edit_poem['image_path']): ?>
                                <div class="current-file">
                                    <img src="<?php echo SITE_URL . '/' . $edit_poem['image_path']; ?>" alt="Poem image" style="max-width: 150px; border-radius: 8px; margin-top: 8px;">
                                    <small>Current image. Upload new to replace.</small>
                                </div>
                            <?php endif; ?>
                            <small class="field-hint">Upload a beautiful image that sets the mood for your poem.</small>
                        </div>

                        <div class="form-group">
                            <label for="audio">Audio Recording (optional)</label>
                            <input type="file" id="audio" name="audio" accept="audio/*">
                            <?php if ($edit_poem && $edit_poem['audio_path']): ?>
                                <div class="current-file">
                                    <audio controls>
                                        <source src="<?php echo SITE_URL . '/' . $edit_poem['audio_path']; ?>" type="audio/mpeg">
                                    </audio>
                                    <small>Current audio. Upload new to replace.</small>
                                </div>
                            <?php endif; ?>
                            <small class="field-hint">Upload an MP3 or WAV file of you reading the poem.</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Poem
                            </button>
                            <button type="button" class="btn btn-outline" id="cancelForm">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Poem List -->
        <div class="poem-list">
            <div class="card">
                <div class="card-header">
                    <h2>All Poems (<?php echo count($poems); ?>)</h2>
                </div>
                <div class="card-body">
                    <?php if (count($poems) > 0): ?>
                        <div class="poem-list-table">
                            <div class="poem-list-header">
                                <div class="col-image">Image</div>
                                <div class="col-title">Title</div>
                                <div class="col-intro">Introduction</div>
                                <div class="col-audio">Audio</div>
                                <div class="col-views">Views</div>
                                <div class="col-actions">Actions</div>
                            </div>
                            <?php foreach ($poems as $poem): ?>
                                <div class="poem-list-row">
                                    <div class="col-image">
                                        <?php if ($poem['image_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;">
                                        <?php else: ?>
                                            <div style="width: 50px; height: 50px; background: var(--vanilla); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--text-light);">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-title">
                                        <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                        <small class="poem-date"><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                    </div>
                                    <div class="col-intro">
                                        <?php if ($poem['intro']): ?>
                                            <span class="intro-preview"><?php echo htmlspecialchars(substr($poem['intro'], 0, 60)); ?>...</span>
                                        <?php else: ?>
                                            <span class="text-muted">No introduction</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-audio">
                                        <?php if ($poem['audio_path']): ?>
                                            <i class="fas fa-music" style="color: var(--rose);"></i>
                                            <span class="audio-label">Yes</span>
                                        <?php else: ?>
                                            <span class="text-muted">No</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-views">
                                        <span class="view-count"><?php echo number_format($poem['view_count'] ?? 0); ?></span>
                                    </div>
                                    <div class="col-actions">
                                        <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?edit=<?php echo $poem['id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?delete=<?php echo $poem['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this poem?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No poems yet. Click "Add New Poem" to get started.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== INLINE JAVASCRIPT ===== -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const showAddBtn = document.getElementById('showAddForm');
        const formContainer = document.getElementById('poemFormContainer');
        const cancelBtn = document.getElementById('cancelForm');

        function toggleForm(show) {
            formContainer.style.display = show ? 'block' : 'none';
            if (show) {
                formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.location.href = '<?php echo SITE_URL; ?>/admin/manage_poems.php';
            }
        }

        if (showAddBtn) {
            showAddBtn.addEventListener('click', function() {
                if (window.location.search.includes('edit')) {
                    window.location.href = '<?php echo SITE_URL; ?>/admin/manage_poems.php';
                } else {
                    toggleForm(true);
                }
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                toggleForm(false);
            });
        }

        if (window.location.search.includes('edit')) {
            toggleForm(true);
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>