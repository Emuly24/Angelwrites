<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';
$edit_book = null;

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM books WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Book deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_books.php');
    exit;
}

// ===== HANDLE EDIT FETCH =====
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

    // Basic validation
    if (empty($title)) {
        $error = 'Book title is required.';
    } else {
        // Handle cover upload
        $cover_path = $edit_book['cover_path'] ?? '';
        if (!empty($_FILES['cover']['name'])) {
            $upload_dir = '../assets/uploads/books/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $cover_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
            $cover_path = 'assets/uploads/books/' . $cover_filename;
            if (!move_uploaded_file($_FILES['cover']['tmp_name'], '../' . $cover_path)) {
                $error = 'Failed to upload cover image.';
            }
        }

        // ===== Handle Book File Upload (PDF, EPUB, DOC, DOCX) =====
        $file_path = $edit_book['file_path'] ?? '';
        $file_type = $edit_book['file_type'] ?? '';
        $file_size = $edit_book['file_size'] ?? 0;
        $file_author = $edit_book['file_author'] ?? '';
        $release_date = $edit_book['release_date'] ?? '';

        if (!empty($_FILES['book_file']['name'])) {
            $upload_dir = '../assets/uploads/books/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['book_file']['name']);
            $file_path = 'assets/uploads/books/' . $file_filename;

            if (!move_uploaded_file($_FILES['book_file']['tmp_name'], '../' . $file_path)) {
                $error = 'Failed to upload book file.';
            } else {
                // Get file size
                $file_size = $_FILES['book_file']['size'];

                // Determine file type
                $ext = strtolower(pathinfo($file_filename, PATHINFO_EXTENSION));
                $allowed_extensions = ['pdf', 'epub', 'doc', 'docx'];
                if (in_array($ext, $allowed_extensions)) {
                    $file_type = $ext;
                } else {
                    $file_type = 'unknown';
                }

                // ===== EXTRACT METADATA (Author, Release Date) =====
                $full_path = '../' . $file_path;
                $file_author = '';
                $release_date = '';

                if ($file_type === 'pdf' && file_exists($full_path)) {
                    // Try to extract PDF metadata using built-in functions
                    try {
                        $pdf_info = @file_get_contents($full_path);
                        if ($pdf_info) {
                            // Simple regex to find Author and CreationDate in PDF
                            preg_match('/\/Author\s*\(([^)]+)\)/', $pdf_info, $author_match);
                            if (isset($author_match[1])) {
                                $file_author = $author_match[1];
                            }
                            preg_match('/\/CreationDate\s*\(D:(\d{4})(\d{2})(\d{2})/', $pdf_info, $date_match);
                            if (isset($date_match[1])) {
                                $release_date = $date_match[1] . '-' . $date_match[2] . '-' . $date_match[3];
                            }
                        }
                    } catch (Exception $e) {
                        // Silently ignore errors
                    }
                } elseif ($file_type === 'docx' && file_exists($full_path)) {
                    // DOCX metadata extraction (ZIP based)
                    try {
                        $zip = new ZipArchive();
                        if ($zip->open($full_path) === true) {
                            $xml = $zip->getFromName('docProps/core.xml');
                            if ($xml) {
                                $dom = new DOMDocument();
                                $dom->loadXML($xml);
                                $creators = $dom->getElementsByTagName('creator');
                                if ($creators->length > 0) {
                                    $file_author = $creators->item(0)->textContent;
                                }
                                $dates = $dom->getElementsByTagName('created');
                                if ($dates->length > 0) {
                                    $date_str = $dates->item(0)->textContent;
                                    $release_date = substr($date_str, 0, 10);
                                }
                            }
                            $zip->close();
                        }
                    } catch (Exception $e) {
                        // Silently ignore
                    }
                } elseif ($file_type === 'epub' && file_exists($full_path)) {
                    // EPUB metadata extraction (ZIP based)
                    try {
                        $zip = new ZipArchive();
                        if ($zip->open($full_path) === true) {
                            $xml = $zip->getFromName('META-INF/container.xml');
                            if ($xml) {
                                $dom = new DOMDocument();
                                $dom->loadXML($xml);
                                $rootfiles = $dom->getElementsByTagName('rootfile');
                                if ($rootfiles->length > 0) {
                                    $opf_path = $rootfiles->item(0)->getAttribute('full-path');
                                    $opf_xml = $zip->getFromName($opf_path);
                                    if ($opf_xml) {
                                        $opf_dom = new DOMDocument();
                                        $opf_dom->loadXML($opf_xml);
                                        $creators = $opf_dom->getElementsByTagName('creator');
                                        if ($creators->length > 0) {
                                            $file_author = $creators->item(0)->textContent;
                                        }
                                        $dates = $opf_dom->getElementsByTagName('date');
                                        if ($dates->length > 0) {
                                            $release_date = substr($dates->item(0)->textContent, 0, 10);
                                        }
                                    }
                                }
                            }
                            $zip->close();
                        }
                    } catch (Exception $e) {
                        // Silently ignore
                    }
                }

                // If no metadata found, fallback to filename-based extraction
                if (empty($file_author)) {
                    $file_author = $edit_book['author'] ?? '';
                }
                if (empty($release_date)) {
                    $release_date = date('Y-m-d');
                }
            }
        }

        if (empty($error)) {
            if ($id > 0) {
                // Update existing book
                $stmt = $db->prepare("
                    UPDATE books SET 
                        title = ?, author = ?, description = ?, price = ?, 
                        is_free = ?, is_sale = ?, cover_path = ?, file_path = ?, 
                        file_type = ?, file_size = ?, file_author = ?, release_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $author, $description, $price, 
                    $is_free, $is_sale, $cover_path, $file_path, 
                    $file_type, $file_size, $file_author, $release_date, 
                    $id
                ]);
                $success = 'Book updated successfully.';
            } else {
                // Insert new book
                $stmt = $db->prepare("
                    INSERT INTO books (
                        title, author, description, price, is_free, is_sale, 
                        cover_path, file_path, file_type, file_size, file_author, release_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title, $author, $description, $price, 
                    $is_free, $is_sale, $cover_path, $file_path, 
                    $file_type, $file_size, $file_author, $release_date
                ]);
                $success = 'Book added successfully.';
            }
            // Redirect to clear form
            header('Location: ' . SITE_URL . '/admin/manage_books.php');
            exit;
        }
    }
}

// ===== FETCH ALL BOOKS FOR LISTING =====
$stmt = $db->query("SELECT * FROM books ORDER BY created_at DESC");
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add schema columns if missing
$stmt = $db->exec("
    CREATE TABLE IF NOT EXISTS books (
        file_size INTEGER DEFAULT 0,
        file_author TEXT,
        release_date TEXT
    )
");

$pageTitle = 'Manage Books';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- Page Header -->
        <div class="admin-header">
            <h1>Manage Books</h1>
            <div class="admin-actions">
                <button id="showAddForm" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> Add New Book
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

        <!-- Book Form (hidden by default) -->
        <div class="book-form-container" id="bookFormContainer" style="display: <?php echo ($edit_book || isset($_GET['edit'])) ? 'block' : 'none'; ?>;">
            <div class="card">
                <div class="card-header">
                    <h2><?php echo $edit_book ? 'Edit Book' : 'Add New Book'; ?></h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="admin-form">
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
                                <label for="price">Price (MKW)</label>
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

                        <div class="form-row">
                            <div class="form-group">
                                <label for="cover">Cover Image</label>
                                <input type="file" id="cover" name="cover" accept="image/*">
                                <?php if ($edit_book && $edit_book['cover_path']): ?>
                                    <div class="current-file">
                                        <img src="<?php echo SITE_URL . '/' . $edit_book['cover_path']; ?>" alt="Current cover" style="max-width: 100px; margin-top: 8px;">
                                        <small>Current cover. Upload new to replace.</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="book_file">Book File (PDF, EPUB, DOC, DOCX)</label>
                                <input type="file" id="book_file" name="book_file" accept=".pdf,.epub,.doc,.docx">
                                <?php if ($edit_book && $edit_book['file_path']): ?>
                                    <div class="current-file">
                                        <small>Current file: <?php echo basename($edit_book['file_path']); ?></small>
                                    </div>
                                <?php endif; ?>
                                <small class="field-hint">Upload a book file. Metadata (author, date) will be extracted automatically.</small>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Book
                            </button>
                            <button type="button" class="btn btn-outline" id="cancelForm">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Book List -->
        <div class="book-list">
            <div class="card">
                <div class="card-header">
                    <h2>All Books (<?php echo count($books); ?>)</h2>
                </div>
                <div class="card-body">
                    <?php if (count($books) > 0): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Cover</th>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Price</th>
                                        <th>File Info</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($books as $book): ?>
                                        <tr>
                                            <td>
                                                <?php if ($book['cover_path']): ?>
                                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" style="max-width: 50px; border-radius: 4px;">
                                                <?php else: ?>
                                                    <i class="fas fa-book" style="font-size: 1.5rem; color: var(--rose);"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                                <?php if ($book['release_date']): ?>
                                                    <br><small>Released: <?php echo htmlspecialchars($book['release_date']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($book['file_author']): ?>
                                                    <br><small>File Author: <?php echo htmlspecialchars($book['file_author']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($book['file_size']): ?>
                                                    <br><small>Size: <?php echo number_format($book['file_size'] / 1024, 1); ?> KB</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                                            <td>
                                                <?php if ($book['is_free']): ?>
                                                    <span class="badge free">Free</span>
                                                <?php elseif ($book['is_sale']): ?>
                                                    <span class="badge sale">$<?php echo number_format($book['price'], 2); ?></span>
                                                <?php else: ?>
                                                    <span class="badge">$<?php echo number_format($book['price'], 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($book['file_path']): ?>
                                                    <span class="status-badge available">
                                                        <?php echo strtoupper($book['file_type'] ?? 'Unknown'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge missing">No file</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions">
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php?edit=<?php echo $book['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php?delete=<?php echo $book['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this book?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                                <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No books yet. Click "Add New Book" to get started.</p>
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
        const formContainer = document.getElementById('bookFormContainer');
        const cancelBtn = document.getElementById('cancelForm');

        function toggleForm(show) {
            formContainer.style.display = show ? 'block' : 'none';
            if (show) {
                formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.location.href = '<?php echo SITE_URL; ?>/admin/manage_books.php';
            }
        }

        if (showAddBtn) {
            showAddBtn.addEventListener('click', function() {
                if (window.location.search.includes('edit')) {
                    window.location.href = '<?php echo SITE_URL; ?>/admin/manage_books.php';
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
<style>
    /* ===== SMART BOOK FORM STYLING ===== */
    .book-form-container .card {
        border-radius: 16px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        margin-top: 16px;
    }
    .book-form-container .card-header {
        background: var(--vanilla);
        padding: 16px 24px;
        border-radius: 16px 16px 0 0;
        border-bottom: 1px solid var(--border);
    }
    .book-form-container .card-header h2 {
        font-size: 1.3rem;
        margin: 0;
        color: var(--dark);
    }
    .book-form-container .card-body {
        padding: 24px;
    }

    /* ===== FORM ROW & GROUP ===== */
    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 12px;
    }
    .form-row .form-group {
        flex: 1;
        min-width: 200px;
    }

    .admin-form .form-group {
        margin-bottom: 16px;
    }
    .admin-form label {
        display: block;
        font-weight: 600;
        margin-bottom: 4px;
        color: var(--text);
        font-size: 0.95rem;
    }
    .admin-form .required {
        color: #e74c3c;
    }

    /* ===== INPUTS ===== */
    .admin-form input[type="text"],
    .admin-form input[type="number"],
    .admin-form textarea,
    .admin-form select {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid var(--border);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--input-bg);
        color: var(--text);
        transition: all 0.3s ease;
    }
    .admin-form input[type="text"]:focus,
    .admin-form input[type="number"]:focus,
    .admin-form textarea:focus,
    .admin-form select:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 4px rgba(219, 161, 162, 0.15);
    }
    .admin-form textarea {
        resize: vertical;
        min-height: 80px;
    }

    /* ===== CHECKBOXES ===== */
    .admin-form .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 6px 0;
        padding: 4px 0;
    }
    .admin-form .checkbox-group input[type="checkbox"] {
        appearance: none;
        -webkit-appearance: none;
        width: 20px;
        height: 20px;
        border: 2px solid var(--border);
        border-radius: 6px;
        background: var(--input-bg);
        cursor: pointer;
        transition: all 0.2s ease;
        flex-shrink: 0;
        position: relative;
    }
    .admin-form .checkbox-group input[type="checkbox"]:checked {
        background: var(--rose);
        border-color: var(--rose);
    }
    .admin-form .checkbox-group input[type="checkbox"]:checked::after {
        content: '✓';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: white;
        font-size: 14px;
        font-weight: 700;
    }
    .admin-form .checkbox-group input[type="checkbox"]:focus {
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .admin-form .checkbox-group label {
        font-weight: 400;
        font-size: 0.95rem;
        margin: 0;
        cursor: pointer;
    }

    /* ===== FILE INPUTS ===== */
    .admin-form input[type="file"] {
        padding: 8px 12px;
        border: 2px dashed var(--border);
        border-radius: 10px;
        background: var(--vanilla);
        width: 100%;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 0.9rem;
        color: var(--text);
    }
    .admin-form input[type="file"]:hover {
        border-color: var(--rose);
        background: rgba(219, 161, 162, 0.05);
    }
    .admin-form input[type="file"]:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }

    /* ===== CURRENT FILE PREVIEW ===== */
    .admin-form .current-file {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 6px;
        font-size: 0.85rem;
        color: var(--text-light);
        padding: 6px 12px;
        background: var(--fantasy);
        border-radius: 6px;
        border: 1px solid var(--border);
    }
    .admin-form .current-file img {
        border-radius: 4px;
        border: 1px solid var(--border);
    }
    .admin-form .current-file small {
        color: var(--text-light);
    }

    /* ===== FIELD HINT ===== */
    .admin-form .field-hint {
        display: block;
        margin-top: 4px;
        font-size: 0.8rem;
        color: var(--text-light);
        font-style: italic;
    }

    /* ===== FORM ACTIONS ===== */
    .admin-form .form-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
    }
    .admin-form .form-actions .btn {
        min-width: 120px;
        justify-content: center;
        padding: 10px 24px;
        font-weight: 600;
        border-radius: 30px;
        transition: all 0.3s ease;
    }
    .admin-form .form-actions .btn-primary {
        background: var(--rose);
        color: white;
    }
    .admin-form .form-actions .btn-primary:hover {
        background: var(--rose-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(219, 161, 162, 0.3);
    }
    .admin-form .form-actions .btn-outline {
        border: 2px solid var(--border);
        background: transparent;
        color: var(--text);
    }
    .admin-form .form-actions .btn-outline:hover {
        border-color: var(--rose);
        background: var(--rose);
        color: white;
        transform: translateY(-2px);
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 600px) {
        .form-row {
            flex-direction: column;
            gap: 0;
        }
        .form-row .form-group {
            min-width: 100%;
        }
        .admin-form .form-actions {
            flex-direction: column;
        }
        .admin-form .form-actions .btn {
            width: 100%;
        }
    }
</style>