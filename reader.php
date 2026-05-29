<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch book from database
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// Get reading status for logged-in user
$user_status = null;
$user_progress = 0;
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT * FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$_SESSION['user_id'], $book_id]);
    $user_status = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_status) {
        $user_progress = $user_status['progress'] ?? 0;
    }
}

// Handle status update via POST (AJAX later)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && isset($_POST['update_status'])) {
    $status = $_POST['status'];
    $valid_statuses = ['currently reading', 'want to read', 'finished'];
    if (in_array($status, $valid_statuses)) {
        $user_id = $_SESSION['user_id'];
        // Check if exists
        $stmt = $db->prepare("SELECT id FROM reading_status WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$user_id, $book_id]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE reading_status SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
            $stmt->execute([$status, $user_id, $book_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO reading_status (user_id, book_id, status) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $book_id, $status]);
        }
        // Redirect to refresh
        header('Location: ' . SITE_URL . '/reader.php?id=' . $book_id);
        exit;
    }
}

$pageTitle = htmlspecialchars($book['title']) . ' — Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="reader-page">
    <div class="container">
        <!-- Book Header -->
        <div class="reader-header">
            <div class="reader-header-left">
                <a href="<?php echo SITE_URL; ?>/books.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Books
                </a>
                <div class="book-title-block">
                    <h1><?php echo htmlspecialchars($book['title']); ?></h1>
                    <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                </div>
            </div>
            <div class="reader-header-right">
                <?php if (isLoggedIn()): ?>
                    <div class="status-controls">
                        <form method="POST" class="status-form">
                            <input type="hidden" name="update_status" value="1">
                            <select name="status" onchange="this.form.submit()" class="status-select">
                                <option value="">Reading Status</option>
                                <option value="currently reading" <?php echo ($user_status && $user_status['status'] === 'currently reading') ? 'selected' : ''; ?>>Currently Reading</option>
                                <option value="want to read" <?php echo ($user_status && $user_status['status'] === 'want to read') ? 'selected' : ''; ?>>Want to Read</option>
                                <option value="finished" <?php echo ($user_status && $user_status['status'] === 'finished') ? 'selected' : ''; ?>>Finished</option>
                            </select>
                        </form>
                        <?php if ($user_status): ?>
                            <span class="status-display <?php echo $user_status['status']; ?>">
                                <?php echo ucfirst($user_status['status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="reader-login-prompt">
                        <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-sm btn-primary">Login to track reading</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Book Cover & Info -->
        <div class="reader-info-row">
            <div class="reader-cover">
                <?php if ($book['cover_path']): ?>
                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                <?php else: ?>
                    <div class="reader-cover-placeholder">
                        <i class="fas fa-book"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="reader-description">
                <?php if ($book['description']): ?>
                    <p><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                <?php else: ?>
                    <p class="text-muted">No description available.</p>
                <?php endif; ?>
                <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            <?php
            $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'book' AND target_id = ?");
            $stmt->execute([$book_id]);
            $rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
            $total_reviews = $rating_data['total'] ?? 0;
            ?>

            <!-- Average Rating -->
            <div class="rating-summary">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-score"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
            </div>

            <!-- Review Form (logged in users only) -->
            <?php if (isLoggedIn()): ?>
                <div class="review-form-container">
                    <h4>Write a Review</h4>
                    <form method="POST" class="review-form">
                        <input type="hidden" name="target_type" value="book">
                        <input type="hidden" name="target_id" value="<?php echo $book_id; ?>">
                        
                        <div class="star-rating">
                            <span>Your rating:</span>
                            <div class="stars">
                                <input type="radio" name="rating" value="5" id="star5"><label for="star5"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="4" id="star4"><label for="star4"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="3" id="star3"><label for="star3"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="2" id="star2"><label for="star2"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="1" id="star1"><label for="star1"><i class="fas fa-star"></i></label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <textarea name="comment" rows="3" placeholder="Share your thoughts about this book..." required></textarea>
                        </div>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Review
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate and review this book.</p>
                </div>
            <?php endif; ?>

            <!-- Existing Reviews -->
            <?php
            $stmt = $db->prepare("
                SELECT r.*, u.name AS author_name 
                FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.target_type = 'book' AND r.target_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$book_id]);
            $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <?php if (count($reviews) > 0): ?>
                <div class="reviews-list">
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <span class="review-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($review['author_name']); ?>
                                </span>
                                <span class="review-date"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                            <div class="review-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'filled' : 'empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <div class="review-comment">
                                <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
                <div class="reader-meta">
                    <?php if ($book['is_free']): ?>
                        <span class="badge free">Free</span>
                    <?php elseif ($book['is_sale']): ?>
                        <span class="badge sale">$<?php echo number_format($book['price'], 2); ?></span>
                    <?php else: ?>
                        <span class="badge">$<?php echo number_format($book['price'], 2); ?></span>
                    <?php endif; ?>
                    <span class="file-info">
                        <i class="fas fa-file"></i>
                        <?php echo strtoupper($book['file_type'] ?? 'Unknown'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Reader Content -->
        <?php if ($book['file_path']): ?>
            <div class="reader-content">
                <div class="reader-toolbar">
                    <span class="reader-toolbar-title">
                        <i class="fas fa-book-open"></i> Reading: <?php echo htmlspecialchars($book['title']); ?>
                    </span>
                    <div class="reader-toolbar-actions">
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-sm btn-outline">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>

                <?php if ($book['file_type'] === 'pdf'): ?>
                    <!-- PDF Reader using iframe -->
                    <div class="pdf-reader">
                        <iframe src="<?php echo SITE_URL . '/' . $book['file_path']; ?>" width="100%" height="700px" frameborder="0" allowfullscreen></iframe>
                    </div>
                <?php elseif ($book['file_type'] === 'epub'): ?>
                    <!-- EPUB Reader using epub.js -->
                    <div class="epub-reader">
                        <div id="epub-viewer"></div>
                        <div class="epub-controls">
                            <button id="epub-prev" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-left"></i></button>
                            <span id="epub-current">Page 1</span>
                            <button id="epub-next" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            if (typeof ePub !== 'undefined') {
                                const book = ePub("<?php echo SITE_URL . '/' . $book['file_path']; ?>");
                                const viewer = document.getElementById('epub-viewer');
                                const prevBtn = document.getElementById('epub-prev');
                                const nextBtn = document.getElementById('epub-next');
                                const currentLabel = document.getElementById('epub-current');

                                let rendition = book.renderTo(viewer, {
                                    width: '100%',
                                    height: '600px',
                                    spread: 'none'
                                });

                                rendition.display();

                                rendition.on('relocated', function(location) {
                                    const current = location.start.cfi;
                                    const total = location.end.cfi;
                                    currentLabel.textContent = 'Page ' + (location.start.displayedPage || 1);
                                });

                                prevBtn.addEventListener('click', function() {
                                    rendition.prev();
                                });

                                nextBtn.addEventListener('click', function() {
                                    rendition.next();
                                });
                            }
                        });
                    </script>
                <?php else: ?>
                    <div class="reader-error">
                        <p><i class="fas fa-exclamation-triangle"></i> This file type is not supported for online reading.</p>
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary">Download to read</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="reader-error">
                <p><i class="fas fa-exclamation-circle"></i> No file available for this book.</p>
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">Browse other books</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.reader-page {
    padding: 32px 0;
}

.reader-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.back-link {
    color: var(--text-light);
    font-size: 0.95rem;
    transition: color var(--transition);
}
.back-link:hover {
    color: var(--rose);
}
.back-link i {
    margin-right: 6px;
}
.book-title-block h1 {
    font-size: 2rem;
    margin-top: 8px;
    margin-bottom: 2px;
}
.book-title-block .book-author {
    color: var(--text-light);
    font-size: 0.95rem;
}

.status-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.status-select {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--input-bg);
    color: var(--text);
    font-size: 0.9rem;
    cursor: pointer;
}
.status-display {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}
.status-display.currently reading { background: var(--rose); color: white; }
.status-display.want to read { background: #3498db; color: white; }
.status-display.finished { background: #27ae60; color: white; }

.reader-info-row {
    display: flex;
    gap: 24px;
    margin-bottom: 32px;
    flex-wrap: wrap;
}
.reader-cover {
    flex: 0 0 180px;
}
.reader-cover img {
    width: 100%;
    height: auto;
    border-radius: 12px;
    box-shadow: var(--shadow);
}
.reader-cover-placeholder {
    width: 100%;
    aspect-ratio: 2/3;
    background: var(--vanilla);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--rose);
}
.reader-description {
    flex: 1;
    min-width: 200px;
}
.reader-description p {
    line-height: 1.7;
    color: var(--text);
    margin-bottom: 12px;
}
.reader-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.badge {
    padding: 4px 12px;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 600;
}
.badge.free { background: #2ecc71; color: white; }
.badge.sale { background: #e67e22; color: white; }
.file-info {
    color: var(--text-light);
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.reader-content {
    margin-top: 16px;
}
.reader-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card-bg);
    padding: 12px 16px;
    border-radius: 12px 12px 0 0;
    border: 1px solid var(--border);
    border-bottom: none;
}
.reader-toolbar-title {
    font-weight: 600;
    font-size: 0.95rem;
}
.reader-toolbar-title i {
    color: var(--rose);
    margin-right: 6px;
}

.pdf-reader {
    border: 1px solid var(--border);
    border-radius: 0 0 12px 12px;
    overflow: hidden;
}

.epub-reader {
    border: 1px solid var(--border);
    border-radius: 0 0 12px 12px;
    overflow: hidden;
}
.epub-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 12px;
    background: var(--card-bg);
    border-top: 1px solid var(--border);
}
#epub-viewer {
    min-height: 500px;
}
#epub-current {
    font-size: 0.9rem;
    color: var(--text-light);
}

.reader-error {
    text-align: center;
    padding: 60px 20px;
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border);
}
.reader-error i {
    font-size: 3rem;
    color: var(--rose);
    margin-bottom: 16px;
}
.reader-error p {
    color: var(--text-light);
    margin-bottom: 16px;
}

@media (max-width: 600px) {
    .reader-info-row {
        flex-direction: column;
        align-items: center;
    }
    .reader-cover {
        flex: 0 0 auto;
        max-width: 150px;
    }
    .reader-header {
        flex-direction: column;
    }
    .reader-toolbar {
        flex-direction: column;
        gap: 8px;
        text-align: center;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>