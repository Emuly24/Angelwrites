<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch book
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Check if book has processed HTML content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed_content = $stmt->fetch(PDO::FETCH_ASSOC);

$has_processed = !empty($processed_content) && $processed_content['is_processed'] == 1;

// Determine if the user has access to download/read (e.g., logged in or purchased)
// Simplified for now: if free, user can read. If not free, check purchase logic or session.

$pageTitle = htmlspecialchars($book['title']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="book-landing">
    <div class="container">
        <div class="book-layout">
            <!-- Left: Cover & Action -->
            <div class="book-sidebar">
                <div class="book-cover-wrapper">
                    <?php if ($book['cover_path']): ?>
                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" class="cover-image">
                    <?php else: ?>
                        <div class="placeholder-cover">
                            <i class="fas fa-book"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="book-actions">
                    <?php if ($has_processed): ?>
                        <!-- Primary action: Read -->
                        <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>" class="btn btn-primary btn-block btn-lg">
                            <i class="fas fa-book-open"></i> Read Book
                        </a>
                    <?php else: ?>
                        <!-- If not processed, maybe just download -->
                        <?php if ($book['is_free']): ?>
                            <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary btn-block btn-lg">
                                <i class="fas fa-download"></i> Download (<?php echo strtoupper($book['file_type']); ?>)
                            </a>
                        <?php else: ?>
                            <button class="btn btn-primary btn-block btn-lg" onclick="alert('Purchase required to read this book.')">
                                <i class="fas fa-lock"></i> Purchase to Read
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="book-meta-sidebar">
                    <div class="meta-item">
                        <span class="label">Price:</span>
                        <span class="value">
                            <?php if ($book['is_free']): ?>
                                <span class="free-badge">Free</span>
                            <?php elseif ($book['is_sale']): ?>
                                <span class="sale-price">MWK <?php echo number_format($book['price'], 2); ?></span>
                            <?php else: ?>
                                MWK <?php echo number_format($book['price'], 2); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="label">Author:</span>
                        <span class="value"><?php echo htmlspecialchars($book['author']); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="label">Format:</span>
                        <span class="value"><?php echo strtoupper($book['file_type']); ?></span>
                    </div>
                    <?php if ($book['isbn']): ?>
                        <div class="meta-item">
                            <span class="label">ISBN:</span>
                            <span class="value"><?php echo htmlspecialchars($book['isbn']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Social Sharing -->
                <div class="share-section">
                    <span class="share-label">Share:</span>
                    <div class="share-icons">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/book.php?id=' . $book_id); ?>" target="_blank" class="share-btn facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Check out this book: ' . $book['title']); ?>&url=<?php echo urlencode(SITE_URL . '/book.php?id=' . $book_id); ?>" target="_blank" class="share-btn twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Check out this book: ' . SITE_URL . '/book.php?id=' . $book_id); ?>" target="_blank" class="share-btn whatsapp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right: Full Description & Info -->
            <div class="book-main">
                <div class="book-header">
                    <h1><?php echo htmlspecialchars($book['title']); ?></h1>
                    <p class="byline">by <?php echo htmlspecialchars($book['author']); ?></p>
                </div>

                <div class="book-description">
                    <?php if ($book['description']): ?>
                        <?php echo nl2br(htmlspecialchars($book['description'])); ?>
                    <?php else: ?>
                        <p class="text-muted">No description available.</p>
                    <?php endif; ?>
                </div>

                <!-- Reviews / Rating (optional) -->
                <div class="reviews-section">
                    <h3>What Readers Say</h3>
                    <?php
                    $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'book' AND target_id = ?");
                    $stmt->execute([$book_id]);
                    $rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    $avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
                    $total_reviews = $rating_data['total'] ?? 0;
                    ?>
                    <div class="rating-summary">
                        <div class="stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <span class="rating-value"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                        <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
                    </div>
                    <?php if (isLoggedIn()): ?>
                        <a href="#write-review" class="btn btn-sm btn-outline">Write a Review</a>
                    <?php else: ?>
                        <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate and review this book.</p>
                    <?php endif; ?>
                    <!-- Placeholder for loading existing reviews -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Book Landing Page Styles */
.book-landing { padding: 40px 0 60px; }
.book-layout { display: grid; grid-template-columns: 320px 1fr; gap: 40px; }

.book-sidebar { position: sticky; top: 20px; align-self: start; }

.book-cover-wrapper { margin-bottom: 20px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-hover); }
.cover-image { width: 100%; height: auto; display: block; }
.placeholder-cover { height: 400px; background: var(--vanilla); display: flex; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); }

.book-actions { margin-bottom: 24px; }
.btn-lg { padding: 16px 24px; font-size: 1.1rem; }

.book-meta-sidebar { background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 20px; }
.meta-item { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border); }
.meta-item:last-child { border-bottom: none; }
.meta-item .label { font-weight: 600; color: var(--text-light); }
.meta-item .value { font-weight: 500; }
.free-badge { color: #27ae60; font-weight: 700; }

.share-section { display: flex; align-items: center; gap: 12px; }
.share-label { font-weight: 600; font-size: 0.9rem; color: var(--text-light); }
.share-icons { display: flex; gap: 8px; }
.share-btn { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

.book-main { min-width: 0; }
.book-header { margin-bottom: 24px; }
.book-header h1 { font-size: 2.4rem; margin-bottom: 4px; color: var(--text); }
.book-header .byline { font-size: 1.1rem; color: var(--text-light); }

.book-description { font-size: 1.1rem; line-height: 1.8; color: var(--text); margin-bottom: 32px; }
.book-description p { margin-bottom: 16px; }

.reviews-section { border-top: 1px solid var(--border); padding-top: 24px; margin-top: 24px; }
.rating-summary { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.rating-summary .stars { color: var(--rose); font-size: 1.2rem; }
.rating-summary .stars .empty { color: var(--border); }
.rating-value { font-weight: 700; font-size: 1.1rem; }
.rating-count { color: var(--text-light); }

@media (max-width: 768px) {
    .book-layout { grid-template-columns: 1fr; }
    .book-sidebar { position: static; }
    .book-cover-wrapper { max-width: 300px; margin: 0 auto 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>