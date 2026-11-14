<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/saves.php'; // F2: the Save control
require_once 'includes/commerce.php';
require_once 'includes/mail_helper.php';

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

$pageTitle = htmlspecialchars($book['title']);
// Link previews: the book's cover (as a 1200x630 JPEG), its title, author and description.
// Before this the preview always showed the site logo.
require_once __DIR__ . '/includes/og.php';
$og_title = $book['title'] . (trim((string)($book['author'] ?? '')) !== '' ? ' by ' . $book['author'] : '');
$og_description = aw_og_text((string)($book['description'] ?? '') !== '' ? $book['description'] : 'A book on AngelWrites.', 180);
$og_url = rtrim(SITE_URL, '/') . '/book.php?id=' . (int)$book_id;
$og_image = aw_og_image_url($db, 'book', (int)$book_id);
$og_image_width = 1200; $og_image_height = 630;
$og_image_alt = 'Cover of the book “' . $book['title'] . '”';
$aw_share_url = aw_share_url('book', (int)$book_id);
?>
<?php require_once 'includes/header.php'; echo aw_save_assets(); ?>

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

        <!-- ===== MAIN BOOK LAYOUT ===== -->
        <div class="book-content-wrapper">
            <!-- Book Cover Section -->
            <div class="book-cover-section">
                <div class="book-cover-card">
                    <div class="placeholder-cover" aria-hidden="true"><span><?php echo htmlspecialchars($book['title']); ?></span><small><?php echo htmlspecialchars((string)$book['author']); ?></small></div>
                    <?php if ($book['cover_path']): ?>
                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="Cover of <?php echo htmlspecialchars($book['title']); ?>" class="cover-image" onerror="this.remove()">
                    <?php endif; ?>
                </div>

            </div>

            <!-- Title, author and what you can do with the book -->
            <div class="book-head">
                <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
                <p class="book-byline"><?php echo htmlspecialchars((string)$book['author']); ?></p>
                <div class="book-meta">
                    <?php if (!empty($book['file_type'])): ?><span class="meta-format"><?php echo strtoupper(htmlspecialchars((string)$book['file_type'])); ?></span><?php endif; ?>
                    <?php if ($total_reviews > 0): ?><a class="meta-rating" href="#reviews"><i class="fas fa-star" aria-hidden="true"></i> <?php echo number_format($avg_rating, 1); ?> <span>(<?php echo (int)$total_reviews; ?>)</span></a><?php endif; ?>
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
                            <p class="book-owned"><i class="fas fa-circle-check" aria-hidden="true"></i> You own this book · <a href="<?php echo SITE_URL; ?>/my_purchases.php">My purchases</a></p>
                        <?php endif; ?>
                    </div>
                    <?php echo aw_save_button('book', (int)$book_id); ?>
                    <p class="book-groups"><a href="<?php echo SITE_URL; ?>/groups.php?book=<?php echo (int)$book_id; ?>"><i class="fas fa-user-group" aria-hidden="true"></i> Reading groups for this book</a></p><!-- F51 -->
                    <?php if (isLoggedIn()):
                        // F111: add this book to the member's library from its own page (the catalogue
                        // and book page had no way to do so). Posts to library.php's existing handler.
                        $ls = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
                        $ls->execute([$_SESSION['user_id'], $book_id]);
                        $my_status = (string)$ls->fetchColumn();
                        $ls->closeCursor();
                    ?>
                        <form method="POST" action="<?php echo SITE_URL; ?>/library.php" class="library-add-form">
                            <input type="hidden" name="book_id" value="<?php echo (int)$book_id; ?>">
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="return" value="/book.php?id=<?php echo (int)$book_id; ?>">
                            <label for="libraryStatus">My library</label>
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

            </div>

            <!-- Book Details Section -->
            <div class="book-details-section">
                
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
                                            <?php $rv_name = trim((string)($review['author_name'] ?? '')) ?: 'A reader'; // reviews whose author left have no name ?>
                                            <div class="review-avatar"><?php echo htmlspecialchars(mb_substr($rv_name, 0, 1)); ?></div>
                                            <span class="author-name"><?php echo htmlspecialchars($rv_name); ?></span>
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
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($aw_share_url); ?>" target="_blank" class="share-btn facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Check out this book: ' . $book['title']); ?>&url=<?php echo urlencode($aw_share_url); ?>" target="_blank" class="share-btn twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Check out this book: ' . $aw_share_url); ?>" target="_blank" class="share-btn whatsapp">
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

/* ===== LAYOUT: cover on the left; title, actions and description beside it ===== */
.book-content-wrapper {
    display:grid; grid-template-columns:minmax(180px, 300px) minmax(0, 1fr); column-gap:clamp(28px, 5vw, 56px); row-gap:28px;
    grid-template-areas: "cover head" "cover details"; align-items:start;
}
.book-cover-section { grid-area:cover; position:sticky; top:96px; }
.book-head { grid-area:head; display:flex; flex-direction:column; gap:6px; }
.book-details-section { grid-area:details; display:flex; flex-direction:column; gap:28px; min-width:0; }

/* ===== COVER: shaped like a book ===== */
.book-cover-card { position:relative; aspect-ratio:2 / 3; border-radius:5px 12px 12px 5px; overflow:hidden; box-shadow:0 2px 4px rgba(44,30,30,.10), 0 22px 50px rgba(44,30,30,.24); background:none; border:0; padding:0; }
.book-cover-card::after { content:''; position:absolute; inset:0; pointer-events:none; z-index:2; background:linear-gradient(90deg, rgba(0,0,0,.18) 0, rgba(255,255,255,.18) 2.5%, rgba(0,0,0,0) 6%); }
.cover-image { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; border-radius:0; z-index:1; }
.placeholder-cover { position:absolute; inset:0; height:auto; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:24px; text-align:center; border-radius:0; background:linear-gradient(160deg, #e9c9c9 0%, #c48f91 100%); color:#fff; font-size:1rem; }
.placeholder-cover span { font-family:'Playfair Display', Georgia, serif; font-size:1.5rem; line-height:1.3; font-weight:600; }
.placeholder-cover small { font-size:.75rem; letter-spacing:.1em; text-transform:uppercase; opacity:.9; }
html[data-theme="dark"] .book-cover-card { box-shadow:0 22px 50px rgba(0,0,0,.6); }

/* ===== TITLE, AUTHOR, FACTS ===== */
.book-title { font-size:2.4rem; margin:0; line-height:1.15; letter-spacing:-.01em; }
.book-byline { margin:0; font-size:1.15rem; color:var(--text-light); }
.book-meta { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:10px 0 4px; color:var(--text-light); font-size:.85rem; }
.meta-format, .meta-rating { display:inline-flex; align-items:center; gap:5px; min-height:28px; padding:0 12px; border-radius:999px; background:rgba(219,161,162,.16); color:var(--text); font-weight:600; font-size:.78rem; letter-spacing:.04em; text-decoration:none; }
.meta-rating i { color:#b07a35; }
.meta-rating span { color:var(--text-light); font-weight:500; }

/* ===== PRICE AND ACTIONS ===== */
.book-action-row { display:flex; flex-direction:column; align-items:flex-start; gap:12px; margin-top:14px; }
.price-display { display:flex; }
.free-badge { background:none; color:#2f6b45; padding:0; font-weight:700; font-size:1.05rem; }
.sale-price { color:#9e3c46; font-weight:700; font-size:1.1rem; }
.sale-price s { color:var(--text-light); font-weight:400; margin-right:4px; }
.regular-price { font-weight:700; font-size:1.1rem; color:var(--text); }
html[data-theme="dark"] .free-badge { color:#8fd1a5; }
html[data-theme="dark"] .sale-price { color:#f2a7b0; }
.action-buttons { display:flex; flex-direction:column; align-items:flex-start; gap:8px; }
.action-buttons .btn-lg { min-height:48px; padding:0 30px; border-radius:999px; font-size:1rem; font-weight:700; display:inline-flex; align-items:center; gap:10px; background:#9c5457; border-color:#9c5457; color:#fff; box-shadow:none; }
.action-buttons .btn-lg:hover { filter:brightness(.93); transform:none; }
.action-buttons .btn-outline.btn-lg { background:transparent; color:var(--text-light); border:1px solid var(--border); }
html[data-theme="dark"] .action-buttons .btn-primary.btn-lg { background:#f0c4c5; border-color:#f0c4c5; color:#2c1e1e; }
.book-owned { margin:0; font-size:.88rem; color:var(--text-light); }
.book-owned i { color:#2f6b45; }
.book-groups { margin:0; font-size:.9rem; }
.book-groups a { color:var(--text); text-decoration:none; display:inline-flex; align-items:center; gap:8px; min-height:36px; }
.book-groups a:hover { color:#9c5457; text-decoration:underline; }
.library-add-form { display:flex; align-items:center; gap:10px; margin:0; font-size:.9rem; color:var(--text-light); }
.library-add-form select { min-height:38px; padding:0 34px 0 14px; border-radius:999px; border:1px solid var(--border); background:var(--card-bg) url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") no-repeat right 12px center / 14px; color:var(--text); font:inherit; font-size:.88rem; -webkit-appearance:none; appearance:none; cursor:pointer; }

/* ===== DESCRIPTION ===== */
.book-description { border-top:1px solid var(--border); padding-top:20px; }
.description-content { line-height:1.8; color:var(--text); font-size:1.02rem; max-width:44em; }
.read-more-btn { margin-top:10px; border-radius:999px; }

/* ===== REVIEWS ===== */
.reviews-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; margin-bottom:16px; }
.reviews-header h3 { font-size:1.4rem; margin:0; }
.rating-summary { display:flex; align-items:center; gap:8px; }
.rating-summary .stars { display:flex; gap:2px; }
.rating-summary .stars .filled { color:#c9965a; }
.rating-summary .stars .empty { color:#e5d5d5; }
.rating-value { font-weight:700; font-size:1.1rem; }
.rating-count { color:var(--text-light); font-size:0.9rem; }

.review-form-container { background:var(--vanilla); border-radius:20px; padding:20px 24px; margin-bottom:24px; }
.review-form-container h4 { margin-bottom:12px; }
.review-form .star-rating { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.review-form .stars { display:flex; flex-direction:row-reverse; gap:2px; }
.review-form .stars input { display:none; }
.review-form .stars label { font-size:1.4rem; color:#e5d5d5; cursor:pointer; transition:color 0.2s; }
.review-form .stars label:hover, .review-form .stars label:hover ~ label { color:#c9965a; }
.review-form .stars input:checked ~ label { color:#c9965a; }
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
.review-rating .filled { color:#c9965a; }
.review-rating .empty { color:#e5d5d5; }
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
@media (max-width:720px) {
    /* phones: the cover, then the title and the main button, then the rest */
    .book-content-wrapper { grid-template-columns:minmax(0, 1fr); grid-template-areas: "cover" "head" "details"; row-gap:22px; }
    .book-cover-section { position:relative; top:0; display:flex; justify-content:center; }
    /* Q22: the cover is capped so the price and the Read/Download button show without scrolling. */
    .book-cover-card { width:min(40vw, 190px); }
    .book-head { align-items:center; text-align:center; }
    .book-meta, .book-action-row, .action-buttons { justify-content:center; align-items:center; }
    .book-title { font-size:1.9rem; }
    .reviews-header { flex-direction:column; align-items:flex-start; gap:8px; }
}
@media (max-width:480px) {
    .book-title { font-size:1.6rem; }
    .action-buttons .btn-lg { width:100%; justify-content:center; }
    .action-buttons, .book-action-row { align-self:stretch; }
}
</style>

<?php require_once 'includes/footer.php'; ?>