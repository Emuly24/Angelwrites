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

$pageTitle = htmlspecialchars($book['title']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="book-landing">
    <div class="container">
        <!-- Back Link -->
        <div class="book-back-link">
            <a href="<?php echo SITE_URL; ?>/books.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Books
            </a>
        </div>

        <!-- Main Book Layout -->
        <div class="book-content-wrapper">
            
            <!-- Centered Cover -->
            <div class="book-cover-section">
                <div class="book-cover-centered">
                    <?php if ($book['cover_path']): ?>
                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" class="cover-image">
                    <?php else: ?>
                        <div class="placeholder-cover">
                            <i class="fas fa-book"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Price & Action Buttons (Below Cover) -->
                <div class="book-action-row">
                    <div class="price-display">
                        <?php if ($book['is_free']): ?>
                            <span class="free-badge">Free</span>
                        <?php elseif ($book['is_sale']): ?>
                            <span class="sale-price">MWK <?php echo number_format($book['price'], 2); ?></span>
                        <?php else: ?>
                            <span class="regular-price">MWK <?php echo number_format($book['price'], 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons">
                        <?php if ($has_processed): ?>
                            <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-book-open"></i> Read Book
                            </a>
                        <?php else: ?>
                            <?php if ($book['is_free']): ?>
                                <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary btn-lg">
                                    <i class="fas fa-download"></i> Download (<?php echo strtoupper($book['file_type']); ?>)
                                </a>
                            <?php else: ?>
                                <button class="btn btn-primary btn-lg" onclick="alert('Purchase required to read this book.')">
                                    <i class="fas fa-lock"></i> Purchase to Read
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Meta Info (Below Buttons) -->
                <div class="book-meta-centered">
                    <span class="meta-author">by <?php echo htmlspecialchars($book['author']); ?></span>
                    <span class="meta-format">Format: <?php echo strtoupper($book['file_type']); ?></span>
                    <?php if ($book['isbn']): ?>
                        <span class="meta-isbn">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Full Description (Justified) -->
            <div class="book-description-section">
                <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
                
                <div class="book-description" id="bookDescription">
                    <?php if ($book['description']): ?>
                        <div class="description-content" id="descContent">
                            <?php echo nl2br(htmlspecialchars($book['description'])); ?>
                        </div>
                        <?php if (strlen($book['description']) > 800): ?>
                            <button id="toggleDescBtn" class="btn btn-sm btn-outline read-more-btn">
                                <i class="fas fa-chevron-down"></i> Read More
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted">No description available.</p>
                    <?php endif; ?>
                </div>

                <!-- Reviews Section -->
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
                </div>

                <!-- Share Section -->
                <div class="share-section">
                    <span class="share-label">Share this book:</span>
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
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleDescBtn');
    if (toggleBtn) {
        const content = document.getElementById('descContent');
        let isExpanded = false;
        toggleBtn.addEventListener('click', function() {
            isExpanded = !isExpanded;
            if (isExpanded) {
                content.style.maxHeight = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Show Less';
            } else {
                content.style.maxHeight = '400px';
                toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Read More';
            }
        });
        // Start collapsed if description is long
        if (content.scrollHeight > 400) {
            content.style.maxHeight = '400px';
            content.style.overflow = 'hidden';
            toggleBtn.style.display = 'inline-block';
        } else {
            toggleBtn.style.display = 'none';
        }
    }
});
</script>

<style>
.book-landing { padding: 40px 0 60px; background: var(--bg); }

/* Back Link */
.book-back-link { margin-bottom: 30px; }
.back-link { color: var(--text-light); font-size: 0.95rem; text-decoration: none; transition: color 0.2s; }
.back-link:hover { color: var(--rose); }
.back-link i { margin-right: 6px; }

/* Main Wrapper */
.book-content-wrapper { max-width: 850px; margin: 0 auto; }

/* Cover Section - Centered */
.book-cover-section { text-align: center; margin-bottom: 40px; }
.book-cover-centered {
    display: inline-block;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    max-width: 350px;
    width: 100%;
}
.cover-image { width: 100%; height: auto; display: block; }
.placeholder-cover { height: 450px; display: flex; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); background: var(--vanilla); }

/* Action Row (Price + Buttons) */
.book-action-row { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 20px; margin-top: 20px; }
.price-display { font-size: 1.2rem; font-weight: 700; color: var(--text); }
.free-badge { color: #27ae60; }
.sale-price { color: #e74c3c; }
.regular-price { color: var(--text); }
.action-buttons .btn-lg { padding: 12px 32px; border-radius: 30px; font-size: 1rem; }

/* Meta Info */
.book-meta-centered { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; margin-top: 12px; font-size: 0.9rem; color: var(--text-light); }
.meta-author { font-weight: 500; color: var(--text); }
.meta-format, .meta-isbn { background: var(--vanilla); padding: 2px 12px; border-radius: 12px; }

/* Description Section */
.book-description-section { margin-top: 30px; }
.book-title { font-size: 2.4rem; text-align: center; margin-bottom: 20px; font-family: 'Playfair Display', serif; color: var(--text); }

.book-description { font-size: 1.05rem; line-height: 1.9; color: var(--text); margin-bottom: 32px; }
.book-description .description-content { transition: max-height 0.5s ease; }
.read-more-btn { margin-top: 12px; border-radius: 30px; }

/* Reviews */
.reviews-section { border-top: 1px solid var(--border); padding-top: 24px; margin-top: 24px; }
.reviews-section h3 { text-align: center; margin-bottom: 12px; }
.rating-summary { display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 16px; }
.rating-summary .stars { color: var(--rose); font-size: 1.2rem; }
.rating-summary .stars .empty { color: var(--border); }
.rating-value { font-weight: 700; font-size: 1.1rem; }
.rating-count { color: var(--text-light); }

/* Share */
.share-section { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
.share-label { font-weight: 500; color: var(--text-light); }
.share-icons { display: flex; gap: 8px; }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

@media (max-width: 768px) {
    .book-title { font-size: 1.8rem; }
    .book-cover-centered { max-width: 250px; }
    .book-action-row { flex-direction: column; gap: 12px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>