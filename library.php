<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Only logged-in users can access
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// ===== HANDLE STATUS UPDATE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $book_id = (int)$_POST['book_id'];
    $status = $_POST['status'];
    
    $valid_statuses = ['currently reading', 'want to read', 'finished'];
    if (in_array($status, $valid_statuses)) {
        // Check if status exists
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
        $success = 'Reading status updated.';
    }
}

// ===== FETCH USER'S BOOKS WITH STATUS =====
$stmt = $db->prepare("
    SELECT 
        b.id, b.title, b.author, b.cover_path, b.file_path, b.file_type,
        rs.status, rs.progress, rs.last_read_page
    FROM books b
    LEFT JOIN reading_status rs ON b.id = rs.book_id AND rs.user_id = ?
    ORDER BY 
        CASE 
            WHEN rs.status = 'currently reading' THEN 1
            WHEN rs.status = 'want to read' THEN 2
            WHEN rs.status = 'finished' THEN 3
            ELSE 4
        END,
        b.title ASC
");
$stmt->execute([$user_id]);
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
        <!-- Page Header -->
        <div class="library-header">
            <h1>My Library</h1>
            <p>Your reading journey, all in one place.</p>
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

        <!-- Currently Reading Section -->
        <section class="library-section">
            <h2 class="section-title">
                <i class="fas fa-book-open" style="color: var(--rose);"></i>
                Currently Reading
                <span class="section-count">(<?php echo count($grouped['currently reading']); ?>)</span>
            </h2>
            <?php if (count($grouped['currently reading']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['currently reading'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php else: ?>
                                    <div class="book-cover-placeholder">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="badge reading">Reading</span>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo min($book['progress'] ?? 0, 100); ?>%;"></div>
                                    </div>
                                    <span class="progress-text"><?php echo min($book['progress'] ?? 0, 100); ?>%</span>
                                </div>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Continue Reading
                                    </a>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                        <input type="hidden" name="status" value="finished">
                                        <input type="hidden" name="update_status" value="1">
                                        <button type="submit" class="btn btn-sm btn-secondary" title="Mark as finished">
                                            <i class="fas fa-check"></i> Finished
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>You're not reading any books yet. <a href="<?php echo SITE_URL; ?>/books.php">Browse books</a> to get started.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Want to Read Section -->
        <section class="library-section">
            <h2 class="section-title">
                <i class="fas fa-bookmark" style="color: #3498db;"></i>
                Want to Read
                <span class="section-count">(<?php echo count($grouped['want to read']); ?>)</span>
            </h2>
            <?php if (count($grouped['want to read']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['want to read'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php else: ?>
                                    <div class="book-cover-placeholder">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="badge want">Want to Read</span>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Start Reading
                                    </a>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                        <input type="hidden" name="status" value="currently reading">
                                        <input type="hidden" name="update_status" value="1">
                                        <button type="submit" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-arrow-right"></i> Start Now
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>No books in your "Want to Read" list. <a href="<?php echo SITE_URL; ?>/books.php">Explore books</a> to save some.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Finished Books Section -->
        <section class="library-section">
            <h2 class="section-title">
                <i class="fas fa-check-circle" style="color: #27ae60;"></i>
                Finished Books
                <span class="section-count">(<?php echo count($grouped['finished']); ?>)</span>
            </h2>
            <?php if (count($grouped['finished']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['finished'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php else: ?>
                                    <div class="book-cover-placeholder">
                                        <i class="fas fa-book"></i>
                                    </div>
                                <?php endif; ?>
                                <span class="badge finished">Finished</span>
                            </div>
                            <div class="book-info">
                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                <div class="book-actions">
                                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> Re-read
                                    </a>
                                    <form method="POST" class="status-form">
                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                        <input type="hidden" name="status" value="want to read">
                                        <input type="hidden" name="update_status" value="1">
                                        <button type="submit" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-redo"></i> Read Again
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>You haven't finished any books yet. Keep reading!</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- All Books (no status) -->
        <section class="library-section">
            <h2 class="section-title">
                <i class="fas fa-layer-group" style="color: var(--text-light);"></i>
                All Books
                <span class="section-count">(<?php echo count($grouped['none']); ?>)</span>
            </h2>
            <?php if (count($grouped['none']) > 0): ?>
                <div class="book-grid">
                    <?php foreach ($grouped['none'] as $book): ?>
                        <div class="book-card">
                            <div class="book-cover">
                                <?php if ($book['cover_path']): ?>
                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php else: ?>
                                    <div class="book-cover-placeholder">
                                        <i class="fas fa-book"></i>
                                    </div>
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
                    <p>All books are in your reading lists. <a href="<?php echo SITE_URL; ?>/books.php">Explore more books</a>.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.library-page {
    padding: 32px 0;
}
.library-header {
    margin-bottom: 32px;
    text-align: center;
}
.library-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.library-header p {
    color: var(--text-light);
    font-size: 1.1rem;
}

.library-section {
    margin-bottom: 40px;
}
.section-title {
    font-size: 1.6rem;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.section-count {
    font-size: 0.9rem;
    color: var(--text-light);
    font-weight: 400;
}

.book-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 20px;
}

.book-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    transition: transform var(--transition), box-shadow var(--transition);
}
.book-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.book-cover {
    position: relative;
    height: 200px;
    background: var(--vanilla);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.book-cover img {
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
    padding: 4px 12px;
    border-radius: 16px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.badge.reading { background: var(--rose); color: white; }
.badge.want { background: #3498db; color: white; }
.badge.finished { background: #27ae60; color: white; }

.book-info {
    padding: 16px;
}
.book-info h3 {
    font-size: 1.05rem;
    margin-bottom: 2px;
}
.book-author {
    font-size: 0.85rem;
    color: var(--text-light);
    margin-bottom: 8px;
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
}

.book-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.status-form {
    display: inline;
}
.status-select {
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    font-size: 0.8rem;
    background: var(--input-bg);
    color: var(--text);
    cursor: pointer;
}

.empty-state {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 32px;
    text-align: center;
    color: var(--text-light);
    border: 1px dashed var(--border);
}
.empty-state a {
    color: var(--rose);
    font-weight: 500;
}
.empty-state a:hover {
    text-decoration: underline;
}

@media (max-width: 480px) {
    .book-grid {
        grid-template-columns: 1fr 1fr;
    }
    .book-cover {
        height: 140px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>