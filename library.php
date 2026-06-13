<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sort_by = isset($_GET['sort']) ? trim($_GET['sort']) : '';

// ===== FETCH READING STATS =====
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'currently reading'");
$stmt->execute([$user_id]);
$stats['reading'] = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'want to read'");
$stmt->execute([$user_id]);
$stats['want_to_read'] = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'");
$stmt->execute([$user_id]);
$stats['finished'] = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ?");
$stmt->execute([$user_id]);
$stats['total'] = $stmt->fetchColumn();

// ===== HANDLE STATUS UPDATE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $book_id = (int)$_POST['book_id'];
    $status = $_POST['status'];
    
    $valid_statuses = ['currently reading', 'want to read', 'finished'];
    if (in_array($status, $valid_statuses)) {
        $stmt = $db->prepare("SELECT id FROM reading_status WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$user_id, $book_id]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $db->prepare("UPDATE reading_status SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
            $stmt->execute([$status, $user_id, $book_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO reading_status (user_id, book_id, status) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $book_id, $status]);
        }
        $success = 'Reading status updated successfully.';
    }
}

// ===== HANDLE REMOVE STATUS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_status'])) {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("DELETE FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $success = 'Book removed from your library.';
}

// ===== FETCH USER'S BOOKS WITH STATUS =====
$sql = "
    SELECT 
        b.id, b.title, b.author, b.cover_path, b.file_path, b.file_type, b.is_free, b.price, b.is_sale,
        rs.status, rs.progress, rs.last_read_page
    FROM books b
    LEFT JOIN reading_status rs ON b.id = rs.book_id AND rs.user_id = ?
    WHERE 1=1
";
$params = [$user_id];

if ($search) {
    $sql .= " AND (b.title LIKE ? OR b.author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter && in_array($status_filter, ['currently reading', 'want to read', 'finished'])) {
    $sql .= " AND rs.status = ?";
    $params[] = $status_filter;
}

// Sorting
switch ($sort_by) {
    case 'title_asc':
        $sql .= " ORDER BY b.title ASC";
        break;
    case 'title_desc':
        $sql .= " ORDER BY b.title DESC";
        break;
    case 'recent':
        $sql .= " ORDER BY rs.updated_at DESC, b.created_at DESC";
        break;
    default:
        $sql .= "
            ORDER BY 
                CASE 
                    WHEN rs.status = 'currently reading' THEN 1
                    WHEN rs.status = 'want to read' THEN 2
                    WHEN rs.status = 'finished' THEN 3
                    ELSE 4
                END,
                b.title ASC
        ";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== GROUP BOOKS BY STATUS =====
$grouped = [
    'currently reading' => [],
    'want to read' => [],
    'finished' => [],
    'none' => []
];

foreach ($books as $book) {
    $status = $book['status'] ?? 'none';
    if (!isset($grouped[$status])) {
        $status = 'none';
    }
    $grouped[$status][] = $book;
}

$pageTitle = 'My Library';
?>
<?php require_once 'includes/header.php'; ?>

<div class="library-page">
    <div class="container">
        <!-- ===== PAGE HEADER ===== -->
        <div class="library-header">
            <div class="library-header-content">
                <h1>📚 My Library</h1>
                <p>Your reading journey, all in one place.</p>
            </div>
            <div class="library-header-stats">
                <div class="stat-pill">
                    <i class="fas fa-book-open" style="color: var(--rose);"></i>
                    <span><?php echo $stats['reading']; ?></span>
                    <small>Reading</small>
                </div>
                <div class="stat-pill">
                    <i class="fas fa-bookmark" style="color: #3498db;"></i>
                    <span><?php echo $stats['want_to_read']; ?></span>
                    <small>Want to Read</small>
                </div>
                <div class="stat-pill">
                    <i class="fas fa-check-circle" style="color: #27ae60;"></i>
                    <span><?php echo $stats['finished']; ?></span>
                    <small>Finished</small>
                </div>
                <div class="stat-pill">
                    <i class="fas fa-layer-group" style="color: var(--text-light);"></i>
                    <span><?php echo $stats['total']; ?></span>
                    <small>Total</small>
                </div>
            </div>
        </div>

        <!-- ===== SEARCH & FILTER ===== -->
        <div class="library-tools">
            <form method="GET" class="search-form">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search your library..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="status" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="currently reading" <?php echo $status_filter === 'currently reading' ? 'selected' : ''; ?>>Currently Reading</option>
                    <option value="want to read" <?php echo $status_filter === 'want to read' ? 'selected' : ''; ?>>Want to Read</option>
                    <option value="finished" <?php echo $status_filter === 'finished' ? 'selected' : ''; ?>>Finished</option>
                </select>
                <select name="sort" class="sort-select">
                    <option value="">Default Order</option>
                    <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
                    <option value="title_desc" <?php echo $sort_by === 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
                    <option value="recent" <?php echo $sort_by === 'recent' ? 'selected' : ''; ?>>Recently Added</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-sliders-h"></i> Apply
                </button>
                <a href="<?php echo SITE_URL; ?>/library.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-times"></i> Clear
                </a>
            </form>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ===== CURRENTLY READING SECTION ===== -->
        <section class="library-section" id="currently-reading">
            <div class="section-header">
                <div class="section-title-group">
                    <h2 class="section-title">
                        <span class="section-icon reading">📖</span>
                        Currently Reading
                        <span class="section-count">(<?php echo count($grouped['currently reading']); ?>)</span>
                    </h2>
                    <p class="section-subtitle">Pick up where you left off.</p>
                </div>
            </div>
            <?php if (count($grouped['currently reading']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['currently reading'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover-wrapper">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="book-cover-placeholder"><i class="fas fa-book"></i></div>
                                <?php endif; ?>
                                <span class="badge status-reading">Reading</span>
                                <?php if ($book['is_free']): ?>
                                    <span class="badge free">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min($book['progress'] ?? 0, 100); ?>%;"></div>
                                    </div>
                                    <span class="progress-text"><?php echo min($book['progress'] ?? 0, 100); ?>% complete</span>
                                </div>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Continue
                                    </a>
                                    <div class="action-group">
                                        <form method="POST" class="status-form" style="display:inline;">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="status" value="finished">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-success" title="Mark as finished">
                                                <i class="fas fa-check"></i> Finish
                                            </button>
                                        </form>
                                        <form method="POST" class="status-form" style="display:inline;" onsubmit="return confirm('Remove this book from your library?');">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="remove_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Remove from library">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
                    <h3>No books in progress</h3>
                    <p>You're not reading any books yet. <a href="<?php echo SITE_URL; ?>/books.php">Browse books</a> to get started.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===== WANT TO READ SECTION ===== -->
        <section class="library-section" id="want-to-read">
            <div class="section-header">
                <div class="section-title-group">
                    <h2 class="section-title">
                        <span class="section-icon want">📌</span>
                        Want to Read
                        <span class="section-count">(<?php echo count($grouped['want to read']); ?>)</span>
                    </h2>
                    <p class="section-subtitle">Books you've saved for later.</p>
                </div>
            </div>
            <?php if (count($grouped['want to read']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['want to read'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover-wrapper">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="book-cover-placeholder"><i class="fas fa-book"></i></div>
                                <?php endif; ?>
                                <span class="badge status-want">Want to Read</span>
                                <?php if ($book['is_free']): ?>
                                    <span class="badge free">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Start Reading
                                    </a>
                                    <div class="action-group">
                                        <form method="POST" class="status-form" style="display:inline;">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="status" value="currently reading">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-arrow-right"></i> Move to Reading
                                            </button>
                                        </form>
                                        <form method="POST" class="status-form" style="display:inline;" onsubmit="return confirm('Remove this book from your library?');">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="remove_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Remove from library">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-bookmark"></i></div>
                    <h3>No books saved</h3>
                    <p>You haven't added any books to your "Want to Read" list. <a href="<?php echo SITE_URL; ?>/books.php">Explore books</a> to save some.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===== FINISHED BOOKS SECTION ===== -->
        <section class="library-section" id="finished">
            <div class="section-header">
                <div class="section-title-group">
                    <h2 class="section-title">
                        <span class="section-icon finished">✅</span>
                        Finished Books
                        <span class="section-count">(<?php echo count($grouped['finished']); ?>)</span>
                    </h2>
                    <p class="section-subtitle">Celebrate your reading achievements.</p>
                </div>
            </div>
            <?php if (count($grouped['finished']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['finished'] as $book): ?>
                        <div class="book-card finished">
                            <div class="book-cover-wrapper">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="book-cover-placeholder"><i class="fas fa-book"></i></div>
                                <?php endif; ?>
                                <span class="badge status-finished">Finished</span>
                                <span class="finished-check">✅</span>
                                <?php if ($book['is_free']): ?>
                                    <span class="badge free">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> Re-read
                                    </a>
                                    <div class="action-group">
                                        <form method="POST" class="status-form" style="display:inline;">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="status" value="want to read">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-redo"></i> Read Again
                                            </button>
                                        </form>
                                        <form method="POST" class="status-form" style="display:inline;" onsubmit="return confirm('Remove this book from your library?');">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <input type="hidden" name="remove_status" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Remove from library">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-check-circle"></i></div>
                    <h3>No finished books yet</h3>
                    <p>You haven't finished any books yet. Keep reading – you've got this!</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===== ALL BOOKS (NO STATUS) SECTION ===== -->
        <section class="library-section" id="all-books">
            <div class="section-header">
                <div class="section-title-group">
                    <h2 class="section-title">
                        <span class="section-icon other">📚</span>
                        All Books
                        <span class="section-count">(<?php echo count($grouped['none']); ?>)</span>
                    </h2>
                    <p class="section-subtitle">Books you haven't added to any list yet.</p>
                </div>
            </div>
            <?php if (count($grouped['none']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['none'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover-wrapper">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="book-cover-placeholder"><i class="fas fa-book"></i></div>
                                <?php endif; ?>
                                <?php if ($book['is_free']): ?>
                                    <span class="badge free">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Read
                                    </a>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                        <select name="status" onchange="this.form.submit()" class="status-select">
                                            <option value="">Add to list...</option>
                                            <option value="currently reading">Currently Reading</option>
                                            <option value="want to read">Want to Read</option>
                                            <option value="finished">Finished</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-layer-group"></i></div>
                    <h3>All books are in your lists</h3>
                    <p>Every book you've explored is organized. <a href="<?php echo SITE_URL; ?>/books.php">Find more books</a> to read.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<style>
/* ===== LIBRARY PAGE ===== */
.library-page {
    padding: 32px 0 60px;
    font-family: 'Inter', sans-serif;
}

/* ===== HEADER ===== */
.library-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    gap: 16px;
}

.library-header-content h1 {
    font-size: 2.4rem;
    margin: 0 0 4px;
    font-weight: 700;
    color: var(--text);
}

.library-header-content p {
    font-size: 1.05rem;
    color: var(--text-light);
    margin: 0;
}

.library-header-stats {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.stat-pill {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 6px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text);
    transition: all 0.2s ease;
    box-shadow: var(--shadow);
}

.stat-pill:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.stat-pill i {
    font-size: 0.9rem;
}

.stat-pill span {
    font-weight: 700;
    font-size: 1rem;
}

.stat-pill small {
    color: var(--text-light);
    font-weight: 400;
    font-size: 0.75rem;
}

/* ===== TOOLS ===== */
.library-tools {
    margin-bottom: 24px;
}

.search-form {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.search-input-group {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.search-input-group i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
}

.search-input-group input {
    width: 100%;
    padding: 10px 12px 10px 36px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 0.95rem;
    background: var(--input-bg);
    color: var(--text);
    transition: all 0.2s ease;
}

.search-input-group input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}

.filter-select,
.sort-select {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 0.9rem;
    background: var(--input-bg);
    color: var(--text);
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-select:focus,
.sort-select:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}

.search-form .btn-sm {
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
}

/* ===== SECTIONS ===== */
.library-section {
    margin-bottom: 48px;
}

.section-header {
    margin-bottom: 16px;
}

.section-title-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.section-title {
    font-size: 1.6rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    color: var(--text);
}

.section-icon {
    display: inline-block;
    font-size: 1.4rem;
}

.section-count {
    font-size: 0.9rem;
    color: var(--text-light);
    font-weight: 400;
}

.section-subtitle {
    font-size: 0.95rem;
    color: var(--text-light);
    margin: 2px 0 0 0;
}

/* ===== BOOK CARDS ===== */
.book-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 20px;
}

.book-card {
    background: var(--card-bg);
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.book-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--shadow-hover);
}

.book-card.finished {
    opacity: 0.85;
}

.book-cover-wrapper {
    position: relative;
    height: 220px;
    background: var(--vanilla);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.book-cover-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-cover-placeholder {
    font-size: 3.5rem;
    color: var(--rose);
}

.badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 14px;
    border-radius: 16px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.badge.status-reading {
    background: var(--rose);
}

.badge.status-want {
    background: #3498db;
}

.badge.status-finished {
    background: #27ae60;
}

.badge.free {
    background: #27ae60;
    top: 12px;
    left: 12px;
    right: auto;
}

.badge.sale {
    background: #e74c3c;
    top: 12px;
    left: 12px;
    right: auto;
}

.finished-check {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 3rem;
    opacity: 0.3;
    pointer-events: none;
}

.book-info {
    padding: 16px;
}

.book-info h3 {
    font-size: 1.05rem;
    margin: 0 0 2px;
    font-weight: 600;
    color: var(--text);
}

.book-author {
    font-size: 0.85rem;
    color: var(--text-light);
    margin: 0 0 8px;
}

.book-progress {
    margin: 8px 0 12px;
}

.progress-bar {
    height: 4px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 2px;
}

.progress-fill {
    height: 100%;
    background: var(--rose);
    border-radius: 2px;
    transition: width 0.4s ease;
}

.progress-text {
    font-size: 0.75rem;
    color: var(--text-light);
    font-weight: 500;
}

.book-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.book-actions .btn-sm {
    padding: 4px 14px;
    font-size: 0.75rem;
    border-radius: 8px;
    font-weight: 600;
}

.action-group {
    display: flex;
    gap: 4px;
}

.status-form {
    display: inline;
}

.status-select {
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid var(--border);
    font-size: 0.8rem;
    background: var(--input-bg);
    color: var(--text);
    cursor: pointer;
    transition: all 0.2s ease;
}

.status-select:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}

/* ===== EMPTY STATE ===== */
.empty-state {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 48px 32px;
    text-align: center;
    color: var(--text-light);
    border: 2px dashed var(--border);
}

.empty-state-icon {
    font-size: 3rem;
    color: var(--rose);
    opacity: 0.5;
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 1.2rem;
    margin: 0 0 4px;
    color: var(--text);
}

.empty-state p {
    margin: 0;
    font-size: 0.95rem;
}

.empty-state a {
    color: var(--rose);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s;
}

.empty-state a:hover {
    text-decoration: underline;
}

/* ===== ALERTS ===== */
.alert {
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #f87171;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #34d399;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .book-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
}

@media (max-width: 768px) {
    .library-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .library-header-stats {
        width: 100%;
        justify-content: flex-start;
    }

    .stat-pill {
        padding: 4px 12px;
        font-size: 0.75rem;
    }

    .stat-pill span {
        font-size: 0.9rem;
    }

    .search-form {
        flex-direction: column;
        align-items: stretch;
    }

    .search-input-group {
        min-width: auto;
    }

    .filter-select,
    .sort-select {
        width: 100%;
    }

    .book-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 12px;
    }

    .book-cover-wrapper {
        height: 160px;
    }

    .book-info h3 {
        font-size: 0.95rem;
    }
}

@media (max-width: 480px) {
    .book-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .book-cover-wrapper {
        height: 140px;
    }

    .section-title {
        font-size: 1.2rem;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>