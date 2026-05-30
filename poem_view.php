<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch poem from database
$stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
$stmt->execute([$id]);
$poem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$poem) {
    header('Location: ' . SITE_URL . '/poetry.php');
    exit;
}

// ===== HANDLE REVIEW SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && isLoggedIn()) {
    $target_type = $_POST['target_type'];
    $target_id = (int)$_POST['target_id'];
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    
    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$target_type, $target_id, $_SESSION['user_id'], $rating, $comment]);
        $success = 'Your review has been posted!';
        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
        exit;
    }
}

// Increment view count
$stmt = $db->prepare("UPDATE poems SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$id]);

$pageTitle = htmlspecialchars($poem['title']) . ' — Poetry';
?>
<?php require_once 'includes/header.php'; ?>

<div class="poem-view-page">
    <div class="container">
        <!-- Navigation back -->
        <div class="poem-nav">
            <a href="<?php echo SITE_URL; ?>/poetry.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Poetry
            </a>
        </div>

        <!-- Poem Header -->
        <header class="poem-header">
            <h1><?php echo htmlspecialchars($poem['title']); ?></h1>
            <div class="poem-meta">
                <span class="poem-date"><?php echo date('F j, Y', strtotime($poem['created_at'])); ?></span>
                <span class="poem-views">
                    <i class="fas fa-eye"></i>
                    <?php echo number_format($poem['view_count'] ?? 1); ?> views
                </span>
            </div>
        </header>

        <!-- Poem Image -->
        <?php if ($poem['image_path']): ?>
            <div class="poem-image-container">
                <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" 
                     alt="<?php echo htmlspecialchars($poem['title']); ?>" 
                     class="poem-feature-image">
            </div>
        <?php endif; ?>

        <!-- Audio Player (if available) -->
        <?php if ($poem['audio_path']): ?>
            <div class="poem-audio-player">
                <div class="audio-label">
                    <i class="fas fa-headphones"></i>
                    <span>Listen to this poem</span>
                </div>
                <audio controls>
                    <source src="<?php echo SITE_URL . '/' . $poem['audio_path']; ?>" type="audio/mpeg">
                </audio>
            </div>
        <?php endif; ?>

        <!-- Poem Introduction (Purpose) -->
        <?php if ($poem['intro']): ?>
            <div class="poem-intro-section">
                <div class="intro-label">✧ Purpose of this poem</div>
                <div class="intro-body">
                    <?php echo nl2br(htmlspecialchars($poem['intro'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Poem Content -->
        <div class="poem-content-section">
            <div class="poem-body">
                <?php echo $poem['content']; ?>
            </div>
        </div>

        <!-- Reviews Section -->
        <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            
            <?php
            $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'poem' AND target_id = ?");
            $stmt->execute([$id]);
            $rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
            $total_reviews = $rating_data['total'] ?? 0;
            ?>
            <div class="rating-summary">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-score"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
            </div>

            <!-- Review Form -->
            <?php if (isLoggedIn()): ?>
                <div class="review-form-container">
                    <h4>Write a Review</h4>
                    <form method="POST" class="review-form">
                        <input type="hidden" name="target_type" value="poem">
                        <input type="hidden" name="target_id" value="<?php echo $id; ?>">
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
                            <textarea name="comment" rows="3" placeholder="Share your thoughts about this poem..." required></textarea>
                        </div>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Review
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate and review this poem.</p>
                </div>
            <?php endif; ?>

            <!-- Existing Reviews -->
            <?php
            $stmt = $db->prepare("
                SELECT r.*, u.name AS author_name 
                FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.target_type = 'poem' AND r.target_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$id]);
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

        <!-- Poem Footer Actions -->
        <div class="poem-footer-actions">
            <div class="share-section">
                <span>Share:</span>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/poem_view.php?id=' . $id); ?>" target="_blank" class="share-btn facebook">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($poem['title'] . ' — a poem by Angella Bottoman'); ?>&url=<?php echo urlencode(SITE_URL . '/poem_view.php?id=' . $id); ?>" target="_blank" class="share-btn twitter">
                    <i class="fab fa-twitter"></i>
                </a>
                <a href="https://api.whatsapp.com/send?text=<?php echo urlencode($poem['title'] . ' — read this poem: ' . SITE_URL . '/poem_view.php?id=' . $id); ?>" target="_blank" class="share-btn whatsapp">
                    <i class="fab fa-whatsapp"></i>
                </a>
            </div>
            <div class="reading-actions">
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> More Poems
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.poem-view-page {
    padding: 32px 0 60px;
}

.poem-nav {
    margin-bottom: 24px;
}
.poem-nav .back-link {
    color: var(--text-light);
    font-size: 0.95rem;
    transition: color var(--transition);
}
.poem-nav .back-link:hover {
    color: var(--rose);
}
.poem-nav .back-link i {
    margin-right: 6px;
}

.poem-header {
    text-align: center;
    margin-bottom: 24px;
}
.poem-header h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(2rem, 4vw, 3.2rem);
    color: var(--dark);
    margin-bottom: 8px;
    line-height: 1.2;
}
.poem-meta {
    display: flex;
    justify-content: center;
    gap: 24px;
    color: var(--text-light);
    font-size: 0.9rem;
}
.poem-meta i {
    margin-right: 4px;
}

/* ===== POEM IMAGE ===== */
.poem-image-container {
    margin: 0 auto 24px;
    max-width: 700px;
    text-align: center;
}
.poem-feature-image {
    width: 100%;
    height: auto;
    max-height: 500px;
    object-fit: cover;
    border-radius: 12px;
    box-shadow: var(--shadow-hover);
}

.poem-audio-player {
    background: var(--vanilla);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
}
.audio-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}
.audio-label i {
    color: var(--rose);
    font-size: 1.2rem;
}
.poem-audio-player audio {
    width: 100%;
    border-radius: 8px;
}

.poem-intro-section {
    background: var(--fantasy);
    border-left: 4px solid var(--rose);
    border-radius: 0 12px 12px 0;
    padding: 20px 24px;
    margin-bottom: 24px;
}
.intro-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--rose);
    margin-bottom: 6px;
}
.intro-body {
    font-style: italic;
    font-size: 1.05rem;
    color: var(--text);
    line-height: 1.8;
}

.poem-content-section {
    margin-bottom: 40px;
}
.poem-body {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-size: 1.1rem;
    line-height: 2;
    color: var(--text);
    padding: 4px 0;
    max-width: 700px;
    margin: 0 auto;
}
.poem-body p {
    margin-bottom: 12px;
}
.poem-body img {
    max-width: 100%;
    height: auto;
    margin: 8px 0;
}
.poem-body .wp-block-paragraph {
    margin-bottom: 12px;
}

.poem-footer-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    max-width: 700px;
    margin: 0 auto;
}

.share-section {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
    color: var(--text-light);
}
.share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: white;
    font-size: 0.9rem;
    transition: transform var(--transition), opacity var(--transition);
}
.share-btn:hover {
    transform: scale(1.05);
    opacity: 0.85;
}
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

.reading-actions .btn {
    font-size: 0.85rem;
}

@media (max-width: 480px) {
    .poem-header h1 {
        font-size: 1.8rem;
    }
    .poem-meta {
        flex-direction: column;
        gap: 4px;
        align-items: center;
    }
    .poem-footer-actions {
        flex-direction: column;
        align-items: center;
    }
    .poem-body {
        font-size: 1rem;
        line-height: 1.8;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>