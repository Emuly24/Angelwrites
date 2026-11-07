<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';
require_once 'includes/mail_helper.php';
require_once 'includes/reputation.php';

// ===== ERROR HANDLING =====
// S7 (F88, F211): errors are logged, never printed to visitors (they leaked server paths
// and corrupted this page's JSON responses).
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ===== GET BOOK ID =====
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$book_id) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// ===== FETCH BOOK =====
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// ============================================================
// UPDATE VIEW COUNT & LOG DEEP DIVE STATS (Guests & Users)
// ============================================================
// 1. Update the aggregate counter
$db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?")->execute([$book_id]);

// 2. Log the detailed record for the Modal
try {
    $user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
    $stmt = $db->prepare("INSERT INTO view_logs (target_type, target_id, user_id, ip_address) VALUES ('book', ?, ?, ?)");
    $stmt->execute([$book_id, $user_id, $_SERVER['REMOTE_ADDR']]);
} catch (Exception $e) {
    // Table might not exist yet, silently skip
}

// ===== CHECK FOR PROCESSED HTML CONTENT (optional) =====
$has_processed = false;
try {
    $stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $processed_content = $stmt->fetch(PDO::FETCH_ASSOC);
    $has_processed = !empty($processed_content) && $processed_content['is_processed'] == 1;
} catch (Exception $e) {
    // Table might not exist – just continue
}

// ===== FETCH REVIEWS WITH REACTIONS =====
$reviews = [];
$avg_rating = 0;
$total_reviews = 0;

try {
    // Check if reviews table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reviews'");
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            SELECT r.*, u.name AS author_name, u.avatar,
                   (SELECT COUNT(*) FROM review_reactions WHERE review_id = r.id AND reaction_type = 'like') as likes,
                   (SELECT COUNT(*) FROM review_reactions WHERE review_id = r.id AND reaction_type = 'love') as loves,
                   (SELECT COUNT(*) FROM review_reactions WHERE review_id = r.id AND reaction_type = 'pray') as prays
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.target_type = 'book' AND r.target_id = ? AND r.deleted_at IS NULL -- F157: reviews deleted by the admin
            ORDER BY r.created_at DESC, r.id DESC
        ");
        $stmt->execute([$book_id]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // P14: all reviews were shown at once; 10 per page now, newest first.
        require_once __DIR__ . '/includes/pagination.php';
        [$rpage, $rper] = aw_page(10);
        $reviews_total = count($reviews);
        [$rpage, $roffset] = aw_clamp($reviews_total, $rpage, $rper);
        $reviews = array_slice($reviews, $roffset, $rper);
        
        // Average rating
        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'book' AND target_id = ? AND deleted_at IS NULL"); // F157
        $stmt->execute([$book_id]);
        $rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
        $total_reviews = $rating_data['total'] ?? 0;
    }
} catch (Exception $e) {
    // Reviews table doesn't exist – skip
}

// ===== HANDLE REVIEW SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && isLoggedIn()) {
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    
    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        try {
            // F93: one review per member and book. A member could post any number of reviews of
            // the same book (each counted in the average); a second submission now updates theirs.
            $ex = $db->prepare("SELECT id FROM reviews WHERE target_type = 'book' AND target_id = ? AND user_id = ?
                                AND parent_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $ex->execute([$book_id, $_SESSION['user_id']]);
            $existing_review = $ex->fetchColumn();
            $ex->closeCursor();
            if ($existing_review) {
                $db->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ?")->execute([$rating, $comment, $existing_review]);
            } else {
                $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute(['book', $book_id, $_SESSION['user_id'], $rating, $comment]);
            }
            
            // Award reputation points (if function exists) – first review only (F93)
            if (!$existing_review && function_exists('awardReputation')) {
                awardReputation($_SESSION['user_id'], 3, 'Reviewed a book');
            }
            
            // Admin notification
            $admin_email = aw_admin_email();
            $subject = '📖 New Book Review: ' . $book['title'];
            $body = "<h2>New Book Review</h2>";
            $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
            $body .= "<p><strong>Rating:</strong> " . $rating . " stars</p>";
            $body .= "<p><strong>Comment:</strong><br>" . nl2br(htmlspecialchars($comment)) . "</p>";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
            
            header('Location: ' . SITE_URL . '/book.php?id=' . $book_id . '&review_added=1');
            exit;
        } catch (Exception $e) {
            // Review table might not exist – continue
        }
    }
}

// ===== HANDLE REACTION TO REVIEW (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['react_to_review']) && isLoggedIn()) {
    $review_id = (int)$_POST['review_id'];
    $reaction_type = $_POST['reaction_type'];
    
    try {
        $stmt = $db->prepare("SELECT id FROM review_reactions WHERE review_id = ? AND user_id = ? AND reaction_type = ?");
        $stmt->execute([$review_id, $_SESSION['user_id'], $reaction_type]);
        if (!$stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO review_reactions (review_id, user_id, reaction_type) VALUES (?, ?, ?)");
            $stmt->execute([$review_id, $_SESSION['user_id'], $reaction_type]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Reaction table missing']);
    }
    exit;
}

// ===== USER REPUTATION =====
$user_rep = null;
$achievements = [];
$reading_streak = 0;
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $db->prepare("SELECT points, level, badges FROM user_reputations WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_rep = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("SELECT achievement_type, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC LIMIT 3");
        $stmt->execute([$user_id]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $reading_streak = (int)$stmt->fetchColumn(); // F87: fetchColumn() returns false with no row; ?? 0 kept the false (blank)
    } catch (Exception $e) {
        // Tables might not exist – skip
    }
}

$pageTitle = htmlspecialchars($book['title']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="book-page">
    <div class="container">
        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Back Link -->
        <div class="book-back-link">
            <a href="<?php echo SITE_URL; ?>/books.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Books
            </a>
        </div>

        <!-- ===== USER REPUTATION DISPLAY ===== -->
        <?php if ($user_rep): ?>
            <div class="user-reputation-bar">
                <span class="rep-item">🏆 <strong><?php echo $user_rep['points']; ?></strong> pts · Level <?php echo $user_rep['level']; ?></span>
                <span class="rep-item">🔥 <?php echo $reading_streak; ?> day streak</span>
                <?php if ($achievements): ?>
                    <span class="achievement-badges">
                        <?php foreach ($achievements as $a): ?>
                            <span class="achievement-badge" title="<?php echo ucfirst(str_replace('_', ' ', $a['achievement_type'])); ?>">
                                🏆
                            </span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ===== MAIN BOOK LAYOUT ===== -->
        <div class="book-content-wrapper">
            <!-- Book Cover Section -->
            <div class="book-cover-section">
                <div class="book-cover-card">
                    <?php if ($book['cover_path']): ?>
                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" class="cover-image">
                    <?php else: ?>
                        <div class="placeholder-cover">
                            <i class="fas fa-book"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Price & Actions -->
                <div class="book-action-row">
                    <div class="price-display">
                        <?php if ($book['is_free']): ?>
                            <span class="free-badge">Free</span>
                        <?php elseif ((float)$book['price'] <= 0): // F80: not free, no price yet ?>
                            <span class="regular-price">Not yet on sale</span>
                        <?php elseif (aw_book_on_sale($book)): ?>
                            <span class="sale-price"><s><?php echo aw_mwk($book['price']); ?></s> <?php echo aw_mwk($book['sale_price']); ?></span><!-- F79 -->
                        <?php else: ?>
                            <span class="regular-price"><?php echo aw_mwk($book['price']); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons">
                        <?php
                        // Q14: one rule for what this visitor can do. Item 3 (F32) still applies: the raw file
                        // is never linked, downloads go through download.php. A paid book with reader content
                        // used to show "Read Book" to non-buyers (the reader then sent them back here), and
                        // there was no way to buy from this page or any hint for guests.
                        $is_free_book = !empty($book['is_free']);
                        $owns_book = $is_free_book || isAdmin() || (isLoggedIn() && aw_has_purchased($db, $_SESSION['user_id'], $book_id));
                        $back_here = urlencode('/book.php?id=' . (int)$book_id);
                        ?>
                        <?php if ($owns_book && $has_processed): ?>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book_id; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-book-open"></i> Read Book
                            </a>
                        <?php elseif ($owns_book && isLoggedIn()): ?>
                            <a href="<?php echo SITE_URL; ?>/download.php?book_id=<?php echo (int)$book_id; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-download"></i> Download (<?php echo strtoupper(htmlspecialchars((string)$book['file_type'])); ?>)
                            </a>
                        <?php elseif ($owns_book): ?>
                            <a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo $back_here; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt"></i> Log in to download (free)
                            </a>
                        <?php elseif ((float)$book['price'] <= 0): ?>
                            <span class="btn btn-outline btn-lg" aria-disabled="true">Not yet on sale</span>
                        <?php elseif (!isLoggedIn()): ?>
                            <a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo $back_here; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt"></i> Log in to buy this book
                            </a>
                        <?php else: ?>
                            <a href="<?php echo SITE_URL; ?>/books.php?buy=<?php echo (int)$book_id; ?>#book-<?php echo (int)$book_id; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-shopping-cart"></i> Buy this book (<?php echo aw_mwk(aw_book_price($book)); ?>)
                            </a>
                        <?php endif; ?>
                        <?php if ($owns_book && !$is_free_book && !isAdmin()): ?>
                            <p style="margin-top:8px;">✅ You own this book. <a href="<?php echo SITE_URL; ?>/my_purchases.php">My purchases</a></p>
                        <?php endif; ?>
                    </div>
                    <p style="text-align:center;margin-top:8px;"><a href="<?php echo SITE_URL; ?>/groups.php?book=<?php echo (int)$book_id; ?>">👥 Reading groups for this book</a></p><!-- F51 -->
                    <?php if (isLoggedIn()):
                        // F111: add this book to the member's library from its own page (the catalogue
                        // and book page had no way to do so). Posts to library.php's existing handler.
                        $ls = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
                        $ls->execute([$_SESSION['user_id'], $book_id]);
                        $my_status = (string)$ls->fetchColumn();
                        $ls->closeCursor();
                    ?>
                        <form method="POST" action="<?php echo SITE_URL; ?>/library.php" class="library-add-form" style="margin-top:10px;text-align:center;">
                            <input type="hidden" name="book_id" value="<?php echo (int)$book_id; ?>">
                            <input type="hidden" name="update_status" value="1">
                            <label for="libraryStatus" style="font-size:0.9rem;">My library:</label>
                            <select id="libraryStatus" name="status" onchange="this.form.submit()">
                                <option value=""<?php echo $my_status === '' ? ' selected' : ''; ?>>Add to my library…</option>
                                <option value="want to read"<?php echo $my_status === 'want to read' ? ' selected' : ''; ?>>Want to Read</option>
                                <option value="currently reading"<?php echo $my_status === 'currently reading' ? ' selected' : ''; ?>>Currently Reading</option>
                                <option value="finished"<?php echo $my_status === 'finished' ? ' selected' : ''; ?>>Finished</option>
                            </select>
                            <noscript><button type="submit" class="btn btn-sm btn-outline">Save</button></noscript>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Meta Info -->
                <div class="book-meta">
                    <span class="meta-author">by <?php echo htmlspecialchars($book['author']); ?></span>
                    <span class="meta-format"><?php echo strtoupper($book['file_type']); ?></span>
                </div>
            </div>

            <!-- Book Details Section -->
            <div class="book-details-section">
                <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
                
                <div class="book-description" id="bookDescription">
                    <?php if ($book['description']): ?>
                        <div class="description-content" id="descContent">
                            <?php echo nl2br(htmlspecialchars($book['description'])); ?>
                        </div>
                        <?php if (strlen($book['description']) > 600): ?>
                            <button id="toggleDescBtn" class="btn btn-sm btn-outline read-more-btn">
                                <i class="fas fa-chevron-down"></i> Read More
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted">No description available.</p>
                    <?php endif; ?>
                </div>

                <!-- ===== REVIEWS SECTION ===== -->
                <div class="reviews-section" id="reviews">
                    <div class="reviews-header">
                        <h3>What Readers Say</h3>
                        <div class="rating-summary">
                            <div class="stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="rating-value"><?php echo number_format($avg_rating, 1); ?></span>
                            <span class="rating-count">(<?php echo (int)$total_reviews; ?> review<?php echo (int)$total_reviews === 1 ? '' : 's'; ?>)</span><!-- Q22 -->
                        </div>
                    </div>

                    <!-- Review Form -->
                    <?php if (isLoggedIn()): ?>
                        <div class="review-form-container">
                            <h4>Write a Review</h4>
                            <form method="POST" enctype="multipart/form-data" class="review-form">
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
                                <button type="submit" name="submit_review" class="btn btn-primary btn-block">Submit Review</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="login-prompt">
                            <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate and review this book.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Reviews List -->
                    <?php if (!empty($reviews_total)): ?>
                        <div class="reviews-list">
                            <?php foreach ($reviews as $review): ?>
                                <div class="review-item" id="review-<?php echo $review['id']; ?>">
                                    <div class="review-header">
                                        <div class="review-author">
                                            <div class="review-avatar"><?php echo substr($review['author_name'], 0, 1); ?></div>
                                            <span class="author-name"><?php echo htmlspecialchars($review['author_name']); ?></span>
                                        </div>
                                        <span class="review-date"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                                    </div>
                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'filled' : 'empty'; ?>"></i>
                                        <?php endfor; ?>
                                        <span class="rating-label"><?php echo $review['rating']; ?>/5</span>
                                    </div>
                                    <div class="review-comment">
                                        <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                    </div>
                                    <div class="review-footer">
                                        <div class="reactions">
                                            <button class="reaction-btn" onclick="reactToReview(<?php echo $review['id']; ?>, 'like')">
                                                👍 <span id="likes-<?php echo $review['id']; ?>"><?php echo $review['likes']; ?></span>
                                            </button>
                                            <button class="reaction-btn" onclick="reactToReview(<?php echo $review['id']; ?>, 'love')">
                                                ❤️ <span id="loves-<?php echo $review['id']; ?>"><?php echo $review['loves']; ?></span>
                                            </button>
                                            <button class="reaction-btn" onclick="reactToReview(<?php echo $review['id']; ?>, 'pray')">
                                                🙏 <span id="prays-<?php echo $review['id']; ?>"><?php echo $review['prays']; ?></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php echo aw_pager($reviews_total, $rpage, $rper, 'reviews'); // P14 ?>
                    <?php elseif (!$isLoggedIn || empty($reviews_total)): ?>
                        <div class="no-reviews">
                            <i class="fas fa-star-half-alt"></i>
                            <p>No reviews yet. Be the first to review this book!</p>
                        </div>
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

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== DESCRIPTION TOGGLE =====
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
        if (content.scrollHeight > 400) {
            content.style.maxHeight = '400px';
            content.style.overflow = 'hidden';
            toggleBtn.style.display = 'inline-block';
        } else {
            toggleBtn.style.display = 'none';
        }
    }

    // ===== REACT TO REVIEW =====
    window.reactToReview = function(reviewId, reaction) {
        const formData = new FormData();
        formData.append('react_to_review', '1');
        formData.append('review_id', reviewId);
        formData.append('reaction_type', reaction);
        fetch('<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const countSpan = document.getElementById(reaction + 's-' + reviewId);
                if (countSpan) {
                    countSpan.textContent = parseInt(countSpan.textContent) + 1;
                }
            }
        })
        .catch(error => console.error('Error:', error));
    };
});
</script>

<style>
/* ===== BRAND VARIABLES (AngelWrites) ===== */
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

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background 0.3s, color 0.3s; }

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
p { line-height:1.6; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS ===== */
.btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
    border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
    cursor:pointer; text-decoration:none; transition:all var(--transition);
    box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px;
}
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-lg { padding:16px 32px; font-size:1.1rem; }

/* ===== PAGE LAYOUT ===== */
.book-page { padding:40px 0 80px; }

/* ===== BACK LINK ===== */
.book-back-link { margin-bottom:24px; }
.back-link { color:var(--text-light); font-size:0.95rem; transition:color 0.2s; }
.back-link:hover { color:var(--rose); }
.back-link i { margin-right:6px; }

/* ===== REPUTATION BAR ===== */
.user-reputation-bar {
    display:flex; flex-wrap:wrap; gap:16px; align-items:center;
    padding:12px 20px; background:var(--vanilla); border-radius:20px;
    margin-bottom:32px; border:1px solid var(--rose-light); box-shadow:var(--shadow);
}
.rep-item { font-size:0.9rem; color:var(--text); display:flex; align-items:center; gap:4px; }
.achievement-badges { display:flex; gap:4px; }
.achievement-badge { background:var(--rose); color:white; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.8rem; }

/* ===== BOOK CONTENT WRAPPER ===== */
.book-content-wrapper {
    display:grid; grid-template-columns:1fr 1fr; gap:40px; align-items:start;
}
@media (max-width:992px) {
    .book-content-wrapper { grid-template-columns:1fr; }
}

/* ===== COVER SECTION ===== */
.book-cover-section {
    display:flex; flex-direction:column; gap:16px; position:sticky; top:20px;
}
.book-cover-card {
    background:var(--card-bg); border-radius:20px; padding:20px;
    border:1px solid var(--border); box-shadow:var(--shadow);
    display:flex; align-items:center; justify-content:center;
    transition:all var(--transition);
}
.book-cover-card:hover { box-shadow:var(--shadow-hover); border-color:var(--rose-light); }
.cover-image { width:100%; height:auto; border-radius:12px; }
.placeholder-cover {
    width:100%; height:300px; display:flex; align-items:center; justify-content:center;
    background:var(--vanilla); border-radius:12px; font-size:4rem; color:var(--rose);
}

/* ===== ACTIONS ===== */
.book-action-row { display:flex; flex-direction:column; gap:12px; }
.price-display { display:flex; justify-content:center; }
.free-badge { background:#27ae60; color:white; padding:4px 16px; border-radius:20px; font-weight:700; font-size:1rem; }
.sale-price { color:#e74c3c; font-weight:700; font-size:1.1rem; }
.regular-price { font-weight:700; font-size:1.1rem; color:var(--text); }
.action-buttons { display:flex; justify-content:center; }
.book-meta { text-align:center; color:var(--text-light); font-size:0.9rem; }
.meta-author { display:block; }
.meta-format { display:inline-block; background:var(--vanilla); padding:2px 12px; border-radius:12px; margin-top:4px; }

/* ===== DETAILS SECTION ===== */
.book-details-section { display:flex; flex-direction:column; gap:24px; }
.book-title { font-size:2.4rem; margin:0 0 4px; line-height:1.2; }
.book-description { border-top:1px solid var(--border); padding-top:16px; }
.description-content { line-height:1.8; color:var(--text); }
.read-more-btn { margin-top:8px; }

/* ===== REVIEWS ===== */
.reviews-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; margin-bottom:16px; }
.reviews-header h3 { font-size:1.4rem; margin:0; }
.rating-summary { display:flex; align-items:center; gap:8px; }
.rating-summary .stars { display:flex; gap:2px; }
.rating-summary .stars .filled { color:#f1c40f; }
.rating-summary .stars .empty { color:#ddd; }
.rating-value { font-weight:700; font-size:1.1rem; }
.rating-count { color:var(--text-light); font-size:0.9rem; }

.review-form-container { background:var(--vanilla); border-radius:20px; padding:20px 24px; margin-bottom:24px; }
.review-form-container h4 { margin-bottom:12px; }
.review-form .star-rating { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.review-form .stars { display:flex; flex-direction:row-reverse; gap:2px; }
.review-form .stars input { display:none; }
.review-form .stars label { font-size:1.4rem; color:#ddd; cursor:pointer; transition:color 0.2s; }
.review-form .stars label:hover, .review-form .stars label:hover ~ label { color:#f1c40f; }
.review-form .stars input:checked ~ label { color:#f1c40f; }
.review-form .form-group textarea { width:100%; padding:12px; border:1px solid var(--border); border-radius:12px; resize:vertical; min-height:80px; background:var(--input-bg); color:var(--text); }
.review-form .form-group textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.review-form .btn-block { width:100%; justify-content:center; margin-top:8px; }

.login-prompt { text-align:center; padding:20px; background:var(--fantasy); border-radius:20px; color:var(--text-light); }
.login-prompt a { color:var(--rose); font-weight:600; text-decoration:none; }
.login-prompt a:hover { text-decoration:underline; }

.reviews-list { display:flex; flex-direction:column; gap:12px; }
.review-item { background:var(--card-bg); border-radius:16px; padding:20px; border:1px solid var(--border); box-shadow:var(--shadow); }
.review-item:hover { box-shadow:var(--shadow-hover); }
.review-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.review-author { display:flex; align-items:center; gap:10px; }
.review-avatar { width:32px; height:32px; border-radius:50%; background:var(--rose); color:white; display:flex; align-items:center; justify-content:center; font-weight:700; }
.author-name { font-weight:600; }
.review-date { font-size:0.8rem; color:var(--text-light); }
.review-rating { margin-bottom:6px; }
.review-rating .filled { color:#f1c40f; }
.review-rating .empty { color:#ddd; }
.rating-label { font-size:0.8rem; color:var(--text-light); margin-left:4px; }
.review-comment { line-height:1.6; color:var(--text); }
.review-footer { margin-top:10px; border-top:1px solid var(--border); padding-top:8px; }
.reactions { display:flex; gap:8px; }
.reaction-btn { background:transparent; border:none; cursor:pointer; color:var(--text-light); font-size:0.9rem; display:flex; align-items:center; gap:4px; transition:color 0.2s; }
.reaction-btn:hover { color:var(--rose); }
.reaction-btn span { font-weight:600; }

.no-reviews { text-align:center; padding:40px; color:var(--text-light); }
.no-reviews i { font-size:2.5rem; color:var(--rose); opacity:0.5; margin-bottom:8px; }

/* ===== SHARE ===== */
.share-section { display:flex; align-items:center; gap:12px; padding-top:16px; border-top:1px solid var(--border); }
.share-label { font-size:0.9rem; color:var(--text-light); font-weight:500; }
.share-icons { display:flex; gap:8px; }
.share-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; color:white; transition:transform 0.2s; font-size:0.9rem; }
.share-btn:hover { transform:scale(1.05); }
.share-btn.facebook { background:#1877f2; }
.share-btn.twitter { background:#1da1f2; }
.share-btn.whatsapp { background:#25d366; }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) {
    .book-title { font-size:2rem; }
    .reviews-header { flex-direction:column; align-items:flex-start; gap:8px; }
    .book-cover-section { position:relative; top:0; }
    .book-cover-card { padding:12px; }
    /* Q22: a full-width cover filled the first phone screen and pushed the price and Read/Download
       button below the fold; it is now capped so they are visible without scrolling. */
    .cover-image { width:auto; max-width:100%; max-height:42vh; object-fit:contain; }
    .placeholder-cover { height:160px; }
}
@media (max-width:480px) {
    .book-title { font-size:1.6rem; }
    .book-content-wrapper { gap:20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>