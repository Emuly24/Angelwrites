<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== ERROR HANDLING =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
            WHERE r.target_type = 'book' AND r.target_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$book_id]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Average rating
        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'book' AND target_id = ?");
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
    $photo_path = null;
    
    // Live photo capture
    if (!empty($_FILES['live_review_photo']['name'])) {
        $upload_dir = 'assets/uploads/reviews/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'review_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_review_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $photo_path = 'assets/uploads/reviews/' . $photo_filename;
        }
    }
    
    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        try {
            $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment, photo_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['book', $book_id, $_SESSION['user_id'], $rating, $comment, $photo_path]);
            
            // Award reputation points (if function exists)
            if (function_exists('awardReputation')) {
                awardReputation($_SESSION['user_id'], 3, 'Reviewed a book');
            }
            
            // Admin notification
            $admin_email = 'angelwrites@zohomail.com';
            $subject = '📖 New Book Review: ' . $book['title'];
            $body = "<h2>New Book Review</h2>";
            $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
            $body .= "<p><strong>Rating:</strong> " . $rating . " stars</p>";
            $body .= "<p><strong>Comment:</strong><br>" . nl2br(htmlspecialchars($comment)) . "</p>";
            if ($photo_path) {
                $body .= "<p><strong>Photo:</strong><br><img src='" . SITE_URL . "/" . $photo_path . "' style='max-width:200px;'></p>";
            }
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
        $reading_streak = $stmt->fetchColumn() ?? 0;
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
                        <?php elseif ($book['is_sale']): ?>
                            <span class="sale-price">MWK <?php echo number_format($book['price'], 2); ?></span>
                        <?php else: ?>
                            <span class="regular-price">MWK <?php echo number_format($book['price'], 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons">
                        <?php if ($has_processed): ?>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.phpid=<?php echo $book_id; ?>" class="btn btn-primary btn-lg">
                                <i class="fas fa-book-open"></i> Read Book
                            </a>
                        <?php else: ?>
                            <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary btn-lg">
                                <i class="fas fa-download"></i> Download (<?php echo strtoupper($book['file_type']); ?>)
                            </a>
                        <?php endif; ?>
                    </div>
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
                <div class="reviews-section">
                    <div class="reviews-header">
                        <h3>What Readers Say</h3>
                        <div class="rating-summary">
                            <div class="stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="rating-value"><?php echo number_format($avg_rating, 1); ?></span>
                            <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
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
                                
                                <!-- Live Photo Capture -->
                                <div class="form-group">
                                    <label>Add a photo (optional)</label>
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
                                        <input type="file" id="livePhotoInput" name="live_review_photo" accept="image/*" style="display:none;">
                                    </div>
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
                    <?php if (count($reviews) > 0): ?>
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
                                        <?php if ($review['photo_path']): ?>
                                            <div class="review-photo">
                                                <img src="<?php echo SITE_URL . '/' . $review['photo_path']; ?>" alt="Review photo">
                                            </div>
                                        <?php endif; ?>
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
                    <?php elseif (!$isLoggedIn || count($reviews) === 0): ?>
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
    const livePhotoInput = document.getElementById('livePhotoInput');

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
        const file = new File([capturedBlob], 'review_photo.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        livePhotoInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);

    // ===== REACTIONS =====
    window.reactToReview = function(reviewId, reactionType) {
        const formData = new FormData();
        formData.append('react_to_review', '1');
        formData.append('review_id', reviewId);
        formData.append('reaction_type', reactionType);
        fetch('<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const likeSpan = document.getElementById('likes-' + reviewId);
                const loveSpan = document.getElementById('loves-' + reviewId);
                const praySpan = document.getElementById('prays-' + reviewId);
                if (reactionType === 'like' && likeSpan) {
                    likeSpan.textContent = parseInt(likeSpan.textContent) + 1;
                } else if (reactionType === 'love' && loveSpan) {
                    loveSpan.textContent = parseInt(loveSpan.textContent) + 1;
                } else if (reactionType === 'pray' && praySpan) {
                    praySpan.textContent = parseInt(praySpan.textContent) + 1;
                }
            }
        });
    };
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

/* ===== PAGE ===== */
.book-page { padding: 40px 0 60px; }

/* ===== BACK LINK ===== */
.book-back-link { margin-bottom: 24px; }
.back-link { color: var(--text-light); font-size: 0.95rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: color 0.2s; }
.back-link:hover { color: var(--rose); }

/* ===== REPUTATION BAR ===== */
.user-reputation-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    padding: 10px 16px;
    background: var(--card-bg);
    border-radius: 10px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.rep-item { font-size: 0.9rem; color: var(--text); }
.achievement-badges { display: flex; gap: 4px; flex-wrap: wrap; }
.achievement-badge { font-size: 1.2rem; }

/* ===== BOOK COVER ===== */
.book-content-wrapper {
    max-width: 900px;
    margin: 0 auto;
}
.book-cover-section {
    text-align: center;
    margin-bottom: 32px;
}
.book-cover-card {
    display: inline-block;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    max-width: 300px;
    width: 100%;
    transition: transform 0.3s ease;
}
.book-cover-card:hover {
    transform: translateY(-4px);
}
.cover-image {
    width: 100%;
    height: auto;
    display: block;
}
.placeholder-cover {
    height: 400px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--rose);
    background: var(--vanilla);
}

/* ===== ACTIONS ===== */
.book-action-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 16px;
    margin-top: 16px;
}
.price-display {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--text);
}
.free-badge { color: #27ae60; }
.sale-price { color: #e74c3c; }
.regular-price { color: var(--text); }
.action-buttons .btn-lg {
    padding: 10px 28px;
    border-radius: 30px;
    font-size: 1rem;
    font-weight: 600;
}

/* ===== META ===== */
.book-meta {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 12px;
    margin-top: 10px;
    font-size: 0.9rem;
    color: var(--text-light);
}
.meta-author { font-weight: 500; color: var(--text); }
.meta-format {
    background: var(--vanilla);
    padding: 2px 12px;
    border-radius: 12px;
}

/* ===== DETAILS ===== */
.book-details-section { margin-top: 24px; }
.book-title {
    font-size: 2.2rem;
    text-align: center;
    margin-bottom: 16px;
    font-family: 'Playfair Display', serif;
    color: var(--text);
}
.book-description {
    text-align: justify;
    font-size: 1.05rem;
    line-height: 1.8;
    color: var(--text);
    margin-bottom: 24px;
}
.book-description .description-content {
    transition: max-height 0.5s ease;
}
.read-more-btn {
    margin-top: 8px;
    border-radius: 30px;
    font-weight: 500;
}

/* ===== REVIEWS ===== */
.reviews-section {
    border-top: 1px solid var(--border);
    padding-top: 24px;
    margin-top: 24px;
}
.reviews-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.reviews-header h3 { font-size: 1.2rem; margin: 0; }
.rating-summary {
    display: flex;
    align-items: center;
    gap: 8px;
}
.rating-summary .stars { color: var(--rose); font-size: 1rem; }
.rating-summary .stars .empty { color: var(--border); }
.rating-value { font-weight: 700; font-size: 1rem; }
.rating-count { color: var(--text-light); font-size: 0.85rem; }

/* ===== REVIEW FORM ===== */
.review-form-container {
    background: var(--vanilla);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
}
.review-form .star-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}
.review-form .stars {
    display: flex;
    flex-direction: row-reverse;
    gap: 2px;
}
.review-form .stars input { display: none; }
.review-form .stars label {
    font-size: 1.4rem;
    color: #ddd;
    cursor: pointer;
    transition: color 0.2s;
}
.review-form .stars label:hover,
.review-form .stars label:hover ~ label { color: #f1c40f; }
.review-form .stars input:checked ~ label { color: #f1c40f; }
.review-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    resize: vertical;
    min-height: 60px;
    background: var(--input-bg);
    color: var(--text);
    font-size: 0.95rem;
}
.review-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219,161,162,0.15);
}
.review-form .btn-block {
    width: 100%;
    padding: 10px;
    margin-top: 8px;
    font-size: 0.95rem;
    border-radius: 30px;
    font-weight: 600;
}

.login-prompt {
    text-align: center;
    padding: 16px;
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 20px;
}
.login-prompt a { color: var(--rose); font-weight: 600; text-decoration: none; }
.login-prompt a:hover { text-decoration: underline; }

/* ===== REVIEWS LIST ===== */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.review-item {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 16px 20px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: all 0.2s;
}
.review-item:hover { box-shadow: var(--shadow-hover); }

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
}
.review-author {
    display: flex;
    align-items: center;
    gap: 8px;
}
.review-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--rose);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.8rem;
}
.author-name { font-weight: 600; color: var(--text); }
.review-date { font-size: 0.8rem; color: var(--text-light); }

.review-rating {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
}
.review-rating .filled { color: #f1c40f; }
.review-rating .empty { color: #ddd; }
.rating-label { font-size: 0.8rem; color: var(--text-light); }

.review-comment {
    line-height: 1.6;
    color: var(--text);
    font-size: 0.95rem;
}
.review-photo {
    margin-top: 8px;
}
.review-photo img {
    max-width: 160px;
    border-radius: 8px;
    border: 1px solid var(--border);
}

.review-footer {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 6px;
}
.reactions {
    display: flex;
    gap: 6px;
}
.reaction-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-light);
    font-size: 0.85rem;
    transition: color 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 20px;
}
.reaction-btn:hover {
    color: var(--rose);
    background: rgba(219,161,162,0.1);
}

.no-reviews {
    text-align: center;
    padding: 24px;
    color: var(--text-light);
}
.no-reviews i { font-size: 2rem; color: var(--border); display: block; margin-bottom: 8px; }

/* ===== SHARE ===== */
.share-section {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.share-label { font-weight: 500; color: var(--text-light); }
.share-icons { display: flex; gap: 6px; }
.share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    color: white;
    font-size: 0.85rem;
    transition: transform 0.2s;
}
.share-btn:hover { transform: scale(1.08); }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

/* ===== CAMERA ===== */
.camera-section {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px;
    background: var(--card-bg);
    margin-top: 8px;
}
.camera-preview-container {
    width: 100%;
    max-width: 320px;
    height: 180px;
    background: var(--vanilla);
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    margin: 0 auto;
}
.camera-preview-container video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: none;
}
.camera-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-light);
    text-align: center;
    padding: 16px;
}
.camera-placeholder i { font-size: 2rem; margin-bottom: 4px; color: var(--rose); }
.camera-placeholder p { margin: 0; font-size: 0.85rem; }
.camera-controls {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 6px;
    align-items: center;
    margin-top: 8px;
}
.camera-controls .btn { padding: 4px 12px; font-size: 0.75rem; border-radius: 20px; }
.captured-photo-container { text-align: center; margin-top: 8px; }
.captured-photo-container img {
    border: 2px solid var(--rose);
    border-radius: 8px;
    max-width: 160px;
}
.status-indicator { font-size: 0.8rem; color: var(--text-light); margin-left: 4px; font-weight: 500; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .book-title { font-size: 1.6rem; }
    .book-cover-card { max-width: 220px; }
    .placeholder-cover { height: 300px; font-size: 3rem; }
    .book-action-row { flex-direction: column; gap: 10px; }
    .reviews-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .camera-preview-container { height: 140px; }
}

@media (max-width: 480px) {
    .book-title { font-size: 1.3rem; }
    .book-cover-card { max-width: 160px; }
    .placeholder-cover { height: 220px; font-size: 2.2rem; }
    .review-item { padding: 12px; }
    .review-avatar { width: 28px; height: 28px; }
    .share-btn { width: 30px; height: 30px; font-size: 0.75rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>