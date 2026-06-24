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
    $stmt = $db->prepare("SELECT cover_path, file_path FROM books WHERE id = ?");
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($book) {
        $doc_root = $_SERVER['DOCUMENT_ROOT'];
        if (!empty($book['cover_path']) && file_exists($doc_root . '/' . $book['cover_path'])) {
            @unlink($doc_root . '/' . $book['cover_path']);
        }
        if (!empty($book['file_path']) && file_exists($doc_root . '/' . $book['file_path'])) {
            @unlink($doc_root . '/' . $book['file_path']);
        }
        $stmt = $db->prepare("DELETE FROM books WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'Book deleted successfully.';
    } else {
        $error = 'Book not found.';
    }
    header('Location: ' . SITE_URL . '/admin/manage_books.php');
    exit;
}

// ===== HANDLE EDIT FETCH =====
$edit_book = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
    $stmt->execute([$id]);
    $edit_book = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_book) {
        $error = 'Book not found.';
    }
}

// ===== HANDLE FORM SUBMISSION (ADD / UPDATE) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
    $title = trim($_POST['title']);
    $author = trim($_POST['author'] ?? 'Angella Bottoman');
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $is_free = isset($_POST['is_free']) ? 1 : 0;
    $is_sale = isset($_POST['is_sale']) ? 1 : 0;

    if (empty($title)) {
        $error = 'Book title is required.';
    } else {
        $cover_path = $edit_book['cover_path'] ?? '';
        $file_path = $edit_book['file_path'] ?? '';
        $file_type = $edit_book['file_type'] ?? '';
        $file_size = $edit_book['file_size'] ?? 0;
        $file_author = $edit_book['file_author'] ?? '';
        $release_date = $edit_book['release_date'] ?? '';

        // ===== LIVE PHOTO CAPTURE =====
        if (!empty($_FILES['live_cover']['name'])) {
            $upload_dir = '../assets/uploads/books/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $photo_filename = 'live_cover_' . time() . '.jpg';
            if (move_uploaded_file($_FILES['live_cover']['tmp_name'], $upload_dir . $photo_filename)) {
                $cover_path = 'assets/uploads/books/' . $photo_filename;
            } else {
                $error = 'Failed to upload captured cover photo.';
            }
        }

        // ===== STANDARD COVER UPLOAD =====
        if (empty($error) && !empty($_FILES['cover']['name'])) {
            $upload_dir = '../assets/uploads/books/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $cover_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
            if (move_uploaded_file($_FILES['cover']['tmp_name'], $upload_dir . $cover_filename)) {
                $cover_path = 'assets/uploads/books/' . $cover_filename;
            } else {
                $error = 'Failed to upload cover image.';
            }
        }

        // ===== BOOK FILE UPLOAD =====
        if (!empty($_FILES['book_file']['name'])) {
            $upload_dir = '../assets/uploads/books/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['book_file']['name']);
            $file_path = 'assets/uploads/books/' . $file_filename;
            if (!move_uploaded_file($_FILES['book_file']['tmp_name'], '../' . $file_path)) {
                $error = 'Failed to upload book file.';
            } else {
                $file_size = $_FILES['book_file']['size'];
                $ext = strtolower(pathinfo($file_filename, PATHINFO_EXTENSION));
                $file_type = in_array($ext, ['pdf', 'epub', 'doc', 'docx']) ? $ext : 'unknown';

                // Extract metadata (simplified)
                $full_path = '../' . $file_path;
                if ($file_type === 'pdf' && file_exists($full_path)) {
                    // Simple PDF metadata extraction
                    $pdf_info = @file_get_contents($full_path);
                    if ($pdf_info) {
                        preg_match('/\/Author\s*\(([^)]+)\)/', $pdf_info, $author_match);
                        $file_author = $author_match[1] ?? '';
                        preg_match('/\/CreationDate\s*\(D:(\d{4})(\d{2})(\d{2})/', $pdf_info, $date_match);
                        $release_date = isset($date_match[1]) ? $date_match[1] . '-' . $date_match[2] . '-' . $date_match[3] : '';
                    }
                }
                if (empty($file_author)) $file_author = $edit_book['author'] ?? '';
                if (empty($release_date)) $release_date = date('Y-m-d');
            }
        }

        if (empty($error)) {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE books SET title = ?, author = ?, description = ?, price = ?, is_free = ?, is_sale = ?, cover_path = ?, file_path = ?, file_type = ?, file_size = ?, file_author = ?, release_date = ? WHERE id = ?");
                $stmt->execute([$title, $author, $description, $price, $is_free, $is_sale, $cover_path, $file_path, $file_type, $file_size, $file_author, $release_date, $id]);
                $success = 'Book updated successfully.';
            } else {
                $stmt = $db->prepare("INSERT INTO books (title, author, description, price, is_free, is_sale, cover_path, file_path, file_type, file_size, file_author, release_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $author, $description, $price, $is_free, $is_sale, $cover_path, $file_path, $file_type, $file_size, $file_author, $release_date]);
                $success = 'Book added successfully.';

                // Admin notification via Zoho SMTP
                $admin_email = 'angelwrites@zohomail.com';
                $subject = '📚 New Book Added: ' . $title;
                $body = "<h2>New Book Added to AngelWrites</h2>";
                $body .= "<p><strong>Title:</strong> " . $title . "</p>";
                $body .= "<p><strong>Author:</strong> " . $author . "</p>";
                $body .= "<p><a href='" . SITE_URL . "/admin/manage_books.php'>View all books</a></p>";
                sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
            }
            header('Location: ' . SITE_URL . '/admin/manage_books.php');
            exit;
        }
    }
}

// ===== FETCH TOTAL BOOKS =====
$count_sql = "SELECT COUNT(*) FROM books";
$count_params = [];
if (!empty($search)) {
    $count_sql .= " WHERE title LIKE ? OR author LIKE ? OR description LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_books = $stmt->fetchColumn();
$total_pages = ceil($total_books / $limit);

// ===== FETCH BOOKS =====
$sql = "SELECT * FROM books";
$params = [];
if (!empty($search)) {
    $sql .= " WHERE title LIKE ? OR author LIKE ? OR description LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Books';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- ===== HERO / HEADER ===== -->
        <div class="admin-hero">
            <div class="admin-hero-content">
                <h1>📚 Manage Books</h1>
                <p class="admin-hero-sub">Add, edit, and manage all books on AngelWrites.</p>
            </div>
            <div class="admin-hero-actions">
                <button id="showAddForm" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Book
                </button>
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ===== SEARCH BAR ===== -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search books by title, author, or description..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== BOOK FORM ===== -->
        <div class="book-form-container" id="bookFormContainer" style="display: <?php echo ($edit_book || isset($_GET['edit'])) ? 'block' : 'none'; ?>;">
            <div class="card">
                <div class="card-header" style="background: var(--vanilla); border-bottom: 1px solid var(--border);">
                    <h2 id="formTitle" style="font-family:'Playfair Display'; color:var(--dark); margin:0;"><?php echo $edit_book ? 'Edit Book' : 'Add New Book'; ?></h2>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <button type="submit" form="bookForm" class="btn btn-primary btn-sm" style="background:var(--rose);color:#fff;border:none;"><i class="fas fa-save"></i> Save Book</button>
                        <button type="button" class="btn btn-outline btn-sm" id="cancelForm"><i class="fas fa-times"></i> Close</button>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="admin-form" id="bookForm">
                        <input type="hidden" name="book_id" value="<?php echo $edit_book['id'] ?? 0; ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="title">Book Title <span class="required">*</span></label>
                                <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($edit_book['title'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="author">Author</label>
                                <input type="text" id="author" name="author" value="<?php echo htmlspecialchars($edit_book['author'] ?? 'Angella Bottoman'); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($edit_book['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price">Price (MWK)</label>
                                <input type="number" step="0.01" id="price" name="price" value="<?php echo $edit_book['price'] ?? '0'; ?>">
                            </div>
                            <div class="form-group checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_free" <?php echo ($edit_book['is_free'] ?? 0) ? 'checked' : ''; ?>>
                                    <span>Free</span>
                                </label>
                            </div>
                            <div class="form-group checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_sale" <?php echo ($edit_book['is_sale'] ?? 0) ? 'checked' : ''; ?>>
                                    <span>For Sale</span>
                                </label>
                            </div>
                        </div>

                        <!-- ===== LIVE PHOTO CAPTURE ===== -->
                        <div class="form-group">
                            <label>Live Cover Photo (capture with camera)</label>
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
                                <input type="file" id="liveCoverInput" name="live_cover" accept="image/*" style="display:none;">
                            </div>
                        </div>

                        <!-- Standard Cover Upload (Fallback) -->
                        <div class="form-group">
                            <label for="cover">Or Upload Cover Image</label>
                            <input type="file" id="cover" name="cover" accept="image/*">
                            <?php if ($edit_book && $edit_book['cover_path']): ?>
                                <div class="current-file">
                                    <img src="<?php echo SITE_URL . '/' . $edit_book['cover_path']; ?>" alt="Current cover" style="max-width:100px; border-radius:8px;">
                                    <small>Current cover. Upload new to replace.</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Book File Upload -->
                        <div class="form-group">
                            <label for="book_file">Book File (PDF, EPUB, DOC, DOCX)</label>
                            <input type="file" id="book_file" name="book_file" accept=".pdf,.epub,.doc,.docx">
                            <?php if ($edit_book && $edit_book['file_path']): ?>
                                <div class="current-file">
                                    <small>📁 Current file: <?php echo basename($edit_book['file_path']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions" style="display:none;">
                            <!-- Hidden standard form actions, since we moved the button up to the header -->
                            <button type="submit" class="btn btn-primary" style="display:none;"></button>
                            <button type="button" class="btn btn-outline" id="cancelFormBtn" style="display:none;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===== BOOK LIST ===== -->
        <div class="book-list">
            <div class="card">
                <div class="card-header">
                    <h2>All Books <span class="count-badge"><?php echo $total_books; ?></span></h2>
                    <div class="card-header-actions">
                        <select id="bulkActionSelect" class="bulk-select">
                            <option value="">Bulk Actions</option>
                            <option value="delete">🗑️ Delete Selected</option>
                        </select>
                        <button id="executeBulkAction" class="btn btn-sm btn-primary" disabled>Apply</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($books) > 0): ?>
                        <form method="POST" id="bulkForm">
                            <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                            <input type="hidden" name="selected_ids" id="selectedIdsInput" value="">
                            <div class="table-responsive">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th class="check-col"><input type="checkbox" id="selectAllRows" class="styled-checkbox"></th>
                                            <th class="cover-col">Cover</th>
                                            <th>Title</th>
                                            <th>Author</th>
                                            <th>Price</th>
                                            <th>File Info</th>
                                            <th class="actions-col">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($books as $book): ?>
                                            <tr>
                                                <td class="check-col" data-label="">
                                                    <input type="checkbox" class="row-select styled-checkbox" value="<?php echo $book['id']; ?>">
                                                </td>
                                                <td class="cover-col" data-label="Cover">
                                                    <?php if ($book['cover_path']): ?>
                                                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" class="book-thumb">
                                                    <?php else: ?>
                                                        <div class="book-thumb-placeholder"><i class="fas fa-book"></i></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Title">
                                                    <div class="book-title-cell">
                                                        <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                                        <?php if ($book['release_date']): ?>
                                                            <span class="cell-meta">Released: <?php echo htmlspecialchars($book['release_date']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($book['file_author']): ?>
                                                            <span class="cell-meta">File Author: <?php echo htmlspecialchars($book['file_author']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($book['file_size']): ?>
                                                            <span class="cell-meta">Size: <?php echo number_format($book['file_size'] / 1024, 1); ?> KB</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-label="Author"><?php echo htmlspecialchars($book['author']); ?></td>
                                                <td data-label="Price">
                                                    <?php if ($book['is_free']): ?>
                                                        <span class="badge free">Free</span>
                                                    <?php elseif ($book['is_sale']): ?>
                                                        <span class="badge sale">MWK <?php echo number_format($book['price'], 2); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge">MWK <?php echo number_format($book['price'], 2); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="File">
                                                    <?php if ($book['file_path']): ?>
                                                        <span class="status-badge available"><?php echo strtoupper($book['file_type'] ?? 'PDF'); ?></span>
                                                    <?php else: ?>
                                                        <span class="status-badge missing">No file</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="actions-col" data-label="Actions">
                                                    <div class="actions-cell">
                                                        <a href="<?php echo SITE_URL; ?>/admin/manage_books.php?edit=<?php echo $book['id']; ?>" class="btn btn-sm btn-secondary action-btn" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-info action-btn" title="Process">
                                                            <i class="fas fa-cogs"></i>
                                                        </a>
                                                        <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary action-btn" target="_blank" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="<?php echo SITE_URL; ?>/admin/manage_books.php?delete=<?php echo $book['id']; ?>" class="btn btn-sm btn-danger action-btn" onclick="return confirm('Delete this book?');" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>

                        <!-- ===== PAGINATION ===== -->
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
                        <div class="empty-state">
                            <i class="fas fa-book empty-icon"></i>
                            <h3>No books yet</h3>
                            <p>Click "Add New Book" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== FORM TOGGLE =====
    const showAddBtn = document.getElementById('showAddForm');
    const formContainer = document.getElementById('bookFormContainer');
    const cancelFormBtn = document.getElementById('cancelForm');
    const cancelHeaderBtn = document.getElementById('cancelForm');
    const formTitle = document.getElementById('formTitle');

    function toggleForm(show) {
        formContainer.style.display = show ? 'block' : 'none';
        if (show) {
            setTimeout(() => {
                formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        } else {
            window.location.href = '<?php echo SITE_URL; ?>/admin/manage_books.php';
        }
    }

    showAddBtn.addEventListener('click', function() {
        resetForm();
        toggleForm(true);
    });

    if (cancelFormBtn) cancelFormBtn.addEventListener('click', function() { toggleForm(false); });
    if (cancelHeaderBtn) cancelHeaderBtn.addEventListener('click', function() { toggleForm(false); });

    function resetForm() {
        document.querySelector('input[name="book_id"]').value = 0;
        document.getElementById('title').value = '';
        document.getElementById('author').value = 'Angella Bottoman';
        document.getElementById('description').value = '';
        document.getElementById('price').value = '0';
        document.querySelector('input[name="is_free"]').checked = false;
        document.querySelector('input[name="is_sale"]').checked = false;
        document.getElementById('cover').value = '';
        document.getElementById('book_file').value = '';
        formTitle.textContent = 'Add New Book';
        resetCamera();
    }

    if (window.location.search.includes('edit')) {
        toggleForm(true);
    }

    // ===== CAMERA =====
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraPlaceholder = document.getElementById('cameraPlaceholder');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const capturePhotoBtn = document.getElementById('capturePhotoBtn');
    const retakePhotoBtn = document.getElementById('retakePhotoBtn');
    const confirmPhotoBtn = document.getElementById('confirmPhotoBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const capturedPhotoContainer = document.getElementById('capturedPhotoContainer');
    const capturedPhotoPreview = document.getElementById('capturedPhotoPreview');
    const liveCoverInput = document.getElementById('liveCoverInput');

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
        const file = new File([capturedBlob], 'live_cover.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        liveCoverInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
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
        liveCoverInput.value = '';
        cameraStatus.textContent = 'Camera ready';
        cameraStatus.style.color = 'var(--text-light)';
    }

    // ===== BULK ACTIONS =====
    const selectAllRows = document.getElementById('selectAllRows');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const executeBulkBtn = document.getElementById('executeBulkAction');
    const bulkActionSelect = document.getElementById('bulkActionSelect');

    selectAllRows?.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });
    rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkButton));

    function updateBulkButton() {
        const checked = document.querySelectorAll('.row-select:checked').length;
        executeBulkBtn.disabled = (checked === 0);
    }

    executeBulkBtn?.addEventListener('click', function() {
        const action = bulkActionSelect.value;
        const ids = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
        if (!action || ids.length === 0) {
            alert('Please select an action and at least one book.');
            return;
        }
        if (!confirm(`Apply "${action}" to ${ids.length} book(s)?`)) return;
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('selectedIdsInput').value = ids.join(',');
        document.getElementById('bulkForm').submit();
    });
});
</script>

<!-- ===== STYLES ===== -->
<style>
/* ===== BRAND VARIABLES ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}

/* ===== PAGE ===== */
.admin-page { padding: 32px 0 60px; font-family: 'Inter', sans-serif; background: var(--bg); }

/* ===== HERO ===== */
.admin-hero {
    background: linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius: 20px;
    padding: 24px 32px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    border: 1px solid var(--rose-light);
    box-shadow: var(--shadow);
}
.admin-hero-content h1 { font-size: 2rem; margin: 0 0 4px 0; font-family: 'Playfair Display', Georgia, serif; color: var(--dark); }
.admin-hero-sub { color: var(--text-light); font-size: 1.05rem; margin: 0; }
.admin-hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }

/* ===== ALERTS ===== */
.alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; font-weight: 500; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

/* ===== SEARCH BAR ===== */
.search-bar { margin-bottom: 24px; }
.search-form { display: flex; gap: 8px; flex-wrap: wrap; }
.search-form input { flex: 1; min-width: 200px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 50px; font-size: 0.95rem; background: var(--card-bg); color: var(--text); transition: border-color 0.2s; }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding: 8px 20px; font-size: 0.85rem; border-radius: 50px; }

/* ===== BUTTONS ===== */
.btn {
    display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px;
    border-radius: 50px; font-weight: 700; font-size: 0.95rem; border: none;
    cursor: pointer; text-decoration: none; transition: all var(--transition);
    box-shadow: 0 3px 10px rgba(44,30,30,0.12); letter-spacing: 0.3px;
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.btn-primary { background: var(--rose); color: var(--white); border: 2px solid var(--rose); }
.btn-primary:hover { background: var(--rose-dark); border-color: var(--rose-dark); }
.btn-secondary { background: var(--vanilla); color: var(--dark); border: 2px solid var(--vanilla); }
.btn-secondary:hover { background: var(--rose-light); border-color: var(--rose-light); }
.btn-outline { background: transparent; border: 2px solid var(--rose); color: var(--rose); }
.btn-outline:hover { background: var(--rose); color: var(--white); }
.btn-sm { padding: 8px 20px; font-size: 0.85rem; }
.btn-info { background: #17a2b8; color: white; border: 2px solid #17a2b8; }
.btn-info:hover { background: #138496; border-color: #138496; }
.btn-danger { background: #dc3545; color: white; border: 2px solid #dc3545; }
.btn-danger:hover { background: #c82333; border-color: #c82333; }
.btn-success { background: #28a745; color: white; border: 2px solid #28a745; }
.btn-success:hover { background: #218838; border-color: #218838; }
.btn-warning { background: #ffc107; color: #212529; border: 2px solid #ffc107; }
.btn-warning:hover { background: #e0a800; border-color: #e0a800; }

/* ===== CARDS ===== */
.card { background: var(--card-bg); border-radius: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 24px; transition: all var(--transition); }
.card:hover { box-shadow: var(--shadow-hover); }
.card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: var(--vanilla); }
.card-header h2 { font-size: 1.3rem; margin: 0; font-family: 'Playfair Display', Georgia, serif; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.count-badge { background: var(--rose); color: white; padding: 2px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.card-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.card-body { padding: 24px; }

/* ===== FORM ===== */
.book-form-container .card-header { background: var(--vanilla); }
.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); font-size: 0.9rem; }
.admin-form .required { color: #dc3545; }
.admin-form input, .admin-form textarea, .admin-form select {
    width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px;
    font-size: 0.95rem; background: var(--input-bg); color: var(--text); transition: border-color 0.2s;
}
.admin-form input:focus, .admin-form textarea:focus, .admin-form select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.admin-form textarea { resize: vertical; min-height: 80px; font-family: 'Inter', sans-serif; }
.admin-form input[type="file"] { padding: 10px; border: 2px dashed var(--border); border-radius: 12px; background: var(--vanilla); cursor: pointer; }
.admin-form input[type="file"]:hover { border-color: var(--rose); background: rgba(219,161,162,0.05); }
.admin-form .current-file { display: flex; align-items: center; gap: 10px; margin-top: 8px; font-size: 0.85rem; color: var(--text-light); padding: 8px 12px; background: var(--fantasy); border-radius: 8px; border: 1px solid var(--border); }
.admin-form .current-file img { border-radius: 8px; }

.form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 8px; }
.form-row .form-group { flex: 1; min-width: 200px; }

.checkbox-group { display: flex; align-items: center; gap: 8px; }
.checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
.checkbox-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--rose); }

/* ===== CAMERA ===== */
.camera-section { border: 1px solid var(--border); border-radius: 16px; padding: 16px; background: var(--fantasy); margin-top: 8px; }
.camera-preview-container { width: 100%; max-width: 400px; height: 220px; background: var(--vanilla); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; margin: 0 auto; }
.camera-preview-container video { width: 100%; height: 100%; object-fit: cover; display: none; }
.camera-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-light); text-align: center; padding: 24px; }
.camera-placeholder i { font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; }
.camera-placeholder p { margin: 0; font-size: 0.9rem; }
.camera-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center; margin-top: 12px; }
.camera-controls .btn { padding: 6px 16px; font-size: 0.85rem; border-radius: 50px; }
.captured-photo-container { text-align: center; margin-top: 12px; }
.captured-photo-container img { border: 3px solid var(--rose); border-radius: 12px; }
.status-indicator { font-size: 0.85rem; color: var(--text-light); margin-left: 8px; font-weight: 500; }

/* ===== DESKTOP TABLE ===== */
.admin-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.admin-table thead { background: var(--vanilla); }
.admin-table th { text-align: left; padding: 14px 20px; font-weight: 600; color: var(--text); border-bottom: 2px solid var(--border); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
.admin-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); font-size: 0.9rem; }
.admin-table tbody tr:hover { background: rgba(219,161,162,0.08); }
.admin-table .check-col { width: 40px; }
.admin-table .cover-col { width: 60px; }
.admin-table .actions-col { width: 180px; }

.styled-checkbox { width: 18px; height: 18px; accent-color: var(--rose); cursor: pointer; }
.book-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
.book-thumb-placeholder { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; background: var(--vanilla); border-radius: 8px; font-size: 1.5rem; color: var(--rose); }
.book-title-cell { display: flex; flex-direction: column; gap: 2px; }
.cell-meta { font-size: 0.75rem; color: var(--text-light); }
.table-responsive { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }

/* ===== BADGES ===== */
.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.badge.free { background: #28a745; color: white; }
.badge.sale { background: #dc3545; color: white; }
.status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.status-badge.available { background: #28a745; color: white; }
.status-badge.missing { background: #dc3545; color: white; }

/* ===== ACTIONS ===== */
.actions-cell { display: flex; gap: 4px; flex-wrap: wrap; }
.action-btn { padding: 6px 10px; font-size: 0.8rem; border-radius: 8px; min-width: 32px; justify-content: center; }
.bulk-select { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--card-bg); color: var(--text); font-size: 0.85rem; }

/* ===== PAGINATION ===== */
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align: center; padding: 40px 20px; color: var(--text-light); }
.empty-icon { font-size: 3rem; color: var(--rose); margin-bottom: 16px; opacity: 0.6; }
.empty-state h3 { font-size: 1.3rem; margin-bottom: 4px; color: var(--text); }
.empty-state p { margin: 0; font-size: 0.95rem; }

/* ============================================================
   MOBILE RESPONSIVE OVERRIDE (Card Grid Layout)
   Prevents Horizontal Scrolling & Stacks Table Rows on Mobile
   ============================================================ */
@media (max-width: 768px) {
    .admin-hero { flex-direction: column; text-align: center; align-items: center; }
    .admin-hero-actions { justify-content: center; }
    .admin-hero-content h1 { font-size: 1.6rem; }
    .form-row { flex-direction: column; }
    
    .table-responsive { border: none; box-shadow: none; border-radius: 0; overflow-x: visible; }
    .admin-table, .admin-table thead, .admin-table tbody, .admin-table th, .admin-table td, .admin-table tr { display: block; }
    .admin-table thead { display: none; } /* Hide header row on mobile */
    
    .admin-table tr {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 16px;
        padding: 12px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .admin-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        padding: 4px 0;
        font-size: 0.85rem;
        border-bottom: 1px solid var(--border);
        text-align: right; /* align text to right to match label */
        word-break: break-word;
    }
    .admin-table td:last-child { border-bottom: none; }
    .admin-table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-light);
        font-size: 0.75rem;
        text-transform: uppercase;
        margin-right: 8px;
        white-space: nowrap;
    }
    /* Specific column overrides for Mobile */
    .admin-table .check-col { border-bottom: none; padding-bottom: 4px; margin-bottom: 2px; justify-content: flex-start; }
    .admin-table .check-col::before { content: ''; display: none; }
    .admin-table .cover-col { display: none; } /* Hide cover thumbnail on mobile */
    .admin-table .actions-col { justify-content: flex-end; flex-wrap: wrap; gap: 4px; border-bottom: none; }
    .admin-table .actions-col::before { align-self: flex-start; margin-top: 4px; }
    .book-title-cell { text-align: right; flex: 1; }
    .cell-meta { font-size: 0.7rem; }
    .actions-cell { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
}
</style>

<?php require_once '../includes/footer.php'; ?>