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

// ===== HANDLE TEXT REVIEW SUBMISSION =====
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

// ===== HANDLE VOICE COMMENT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voice_comment']) && isLoggedIn()) {
    $target_id = (int)$_POST['target_id'];
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    
    if (isset($_FILES['voice_file']) && $_FILES['voice_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/voice_comments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = 'voice_' . time() . '.webm';
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['voice_file']['tmp_name'], $target)) {
            $voice_path = 'assets/uploads/voice_comments/' . $filename;
            // Insert as a review with a special marker
            $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment, voice_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['poem', $target_id, $_SESSION['user_id'], $rating, '🎙️ Voice comment', $voice_path]);
            $success = 'Your voice comment has been posted!';
            header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
            exit;
        }
    }
}

// ===== HANDLE ADMIN REPLY =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin_reply']) && isAdmin()) {
    $target_id = (int)$_POST['target_id'];
    $reply = trim($_POST['admin_reply']);
    
    if (!empty($reply)) {
        $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, comment, is_admin_reply) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['poem', $target_id, $_SESSION['user_id'], $reply, 1]);
        $success = 'Your reply has been posted!';
        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
        exit;
    }
}

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $poem_id = (int)$_GET['id'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO poem_reads (user_id, poem_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $poem_id]);
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

        <!-- Audio Player -->
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

        <!-- Poem Introduction -->
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

        <!-- ===== REVIEWS & COMMENTS SECTION ===== -->
        <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            
            <?php
            // Fetch average rating
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

            <!-- Review Form (Text) – Logged in users only -->
            <?php if (isLoggedIn()): ?>
                <div class="review-form-container">
                    <h4>Write a Text Review</h4>
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

                <!-- Voice Comment Form -->
                <div class="voice-comment-section">
                    <h4>🎙️ Record a Voice Comment</h4>
                    <div class="recorder-wrapper">
                        <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                        <span id="recordingStatus" style="display:none; font-weight:600; color:#e74c3c;">🔴 Recording...</span>
                        <form method="POST" enctype="multipart/form-data" id="voiceForm" style="display:none; margin-top:10px;">
                            <input type="hidden" name="submit_voice_comment" value="1">
                            <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                            <input type="file" name="voice_file" id="voiceFileInput" accept="audio/webm" required>
                            <button type="submit" class="btn btn-success btn-sm">Upload Voice Comment</button>
                        </form>
                        <div id="voicePreviewContainer" style="display:none; margin-top:10px;">
                            <audio controls id="voicePreview" style="width:100%;"><source src="" type="audio/webm"></audio>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to rate, review, or leave a voice comment.</p>
                </div>
            <?php endif; ?>

            <!-- Admin Reply Form (Only for Admin) -->
            <?php if (isAdmin()): ?>
                <div class="admin-reply-container">
                    <h4>🛡️ Angella's Reply</h4>
                    <form method="POST" class="admin-reply-form">
                        <input type="hidden" name="add_admin_reply" value="1">
                        <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <textarea name="admin_reply" rows="3" placeholder="Reply to this poem directly..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Post Reply</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Existing Reviews & Comments -->
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
                        <div class="review-item <?php echo $review['is_admin_reply'] ? 'admin-reply' : ''; ?>">
                            <div class="review-header">
                                <span class="review-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($review['author_name']); ?>
                                    <?php if ($review['is_admin_reply']): ?>
                                        <span class="admin-badge">🛡️ Angella</span>
                                    <?php endif; ?>
                                </span>
                                <span class="review-date"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                            <?php if ($review['rating'] > 0): ?>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'filled' : 'empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                            <div class="review-comment">
                                <?php if (!empty($review['voice_path'])): ?>
                                    <div class="voice-comment-player">
                                        <audio controls>
                                            <source src="<?php echo SITE_URL . '/' . $review['voice_path']; ?>" type="audio/webm">
                                        </audio>
                                    </div>
                                <?php else: ?>
                                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                <?php endif; ?>
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

<style>
/* ===== POEM VIEW PAGE ===== */
.poem-view-page { padding: 32px 0 60px; }
.poem-nav { margin-bottom: 24px; }
.poem-nav .back-link { color: var(--text-light); font-size: 0.95rem; transition: color var(--transition); }
.poem-nav .back-link:hover { color: var(--rose); }
.poem-nav .back-link i { margin-right: 6px; }

.poem-header { text-align: center; margin-bottom: 32px; }
.poem-header h1 { font-family: 'Playfair Display', serif; font-size: clamp(2rem, 4vw, 3.2rem); color: var(--dark); margin-bottom: 8px; line-height: 1.2; }
.poem-meta { display: flex; justify-content: center; gap: 24px; color: var(--text-light); font-size: 0.9rem; }
.poem-meta i { margin-right: 4px; }

.poem-image-container { margin: 0 auto 32px; max-width: 700px; text-align: center; }
.poem-feature-image { width: 100%; height: auto; border: 6px solid var(--rose); border-radius: 16px; box-shadow: var(--shadow-hover); display: block; }

.poem-audio-player { max-width: 700px; margin: 0 auto 24px; background: var(--vanilla); border-radius: 12px; padding: 20px 24px; border: 1px solid var(--border); }
.audio-label { display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
.audio-label i { color: var(--rose); font-size: 1.2rem; }
.poem-audio-player audio { width: 100%; border-radius: 8px; }

.poem-intro-section { max-width: 700px; margin: 0 auto 32px; background: var(--fantasy); border-left: 4px solid var(--rose); border-radius: 0 12px 12px 0; padding: 20px 24px; }
.intro-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--rose); margin-bottom: 6px; }
.intro-body { font-style: italic; font-size: 1.05rem; color: var(--text); line-height: 1.8; text-align: justify; }

.poem-content-section { max-width: 700px; margin: 0 auto 32px; border: 4px solid var(--rose); border-radius: 16px; padding: 32px; background: var(--card-bg); box-shadow: var(--shadow-hover); }
.poem-body { font-family: 'Georgia', serif; font-size: 1.15rem; line-height: 2.4; color: var(--text); text-align: center; padding: 0; }
.poem-body p { margin-bottom: 24px; }
.poem-body p:last-child { margin-bottom: 0; }
.poem-body br { display: block; content: ""; margin: 12px 0; }
.poem-body img { max-width: 100%; height: auto; margin: 16px auto; display: block; border-radius: 8px; }

/* ===== REVIEWS & COMMENTS ===== */
.reviews-section { max-width: 700px; margin: 48px auto 0; }
.reviews-section h3 { font-size: 1.4rem; margin-bottom: 16px; }

.rating-summary { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.rating-stars { display: flex; gap: 2px; }
.rating-stars .filled { color: #f1c40f; }
.rating-stars .empty { color: #ddd; }
.rating-score { font-weight: 700; font-size: 1.1rem; }
.rating-count { color: var(--text-light); font-size: 0.9rem; }

.review-form-container { background: var(--vanilla); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.review-form-container h4 { margin-bottom: 12px; }
.review-form .star-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.review-form .stars { display: flex; flex-direction: row-reverse; gap: 2px; }
.review-form .stars input { display: none; }
.review-form .stars label { font-size: 1.4rem; color: #ddd; cursor: pointer; transition: color 0.2s; }
.review-form .stars label:hover, .review-form .stars label:hover ~ label { color: #f1c40f; }
.review-form .stars input:checked ~ label { color: #f1c40f; }
.review-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; background: var(--input-bg); color: var(--text); }
.review-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.review-form .btn { margin-top: 8px; }

.voice-comment-section { margin-top: 20px; padding: 16px; background: var(--fantasy); border-radius: 12px; }
.voice-comment-section h4 { margin-bottom: 12px; }
.recorder-wrapper { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
#recordingStatus { font-weight: 600; }
.recorder-wrapper .btn { padding: 8px 16px; }

.admin-reply-container { background: var(--vanilla); border-radius: 12px; padding: 20px; border-left: 5px solid var(--rose); margin-top: 16px; }
.admin-reply-container h4 { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; color: var(--dark); }
.admin-reply-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; background: var(--input-bg); color: var(--text); }
.admin-reply-form .btn { margin-top: 8px; }

.reviews-list { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
.review-item { background: var(--card-bg); border-radius: 12px; padding: 16px 20px; border: 1px solid var(--border); }
.review-item.admin-reply { background: var(--vanilla); border-left: 5px solid var(--rose); }
.review-author { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.review-author i { color: var(--rose); }
.admin-badge { background: var(--rose); color: white; font-size: 0.7rem; padding: 2px 10px; border-radius: 12px; font-weight: 600; }
.review-date { font-size: 0.85rem; color: var(--text-light); margin: 2px 0 6px; }
.review-rating { margin-bottom: 6px; }
.review-rating .filled { color: #f1c40f; }
.review-rating .empty { color: #ddd; }
.review-comment { line-height: 1.6; color: var(--text); }
.voice-comment-player { margin: 6px 0; }
.voice-comment-player audio { width: 100%; border-radius: 8px; }

.poem-footer-actions { max-width: 700px; margin: 32px auto 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding-top: 24px; border-top: 1px solid var(--border); }
.share-section { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-light); }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform var(--transition), opacity var(--transition); }
.share-btn:hover { transform: scale(1.05); opacity: 0.85; }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

.reading-actions .btn { font-size: 0.85rem; }

@media (max-width: 480px) {
    .poem-header h1 { font-size: 1.8rem; }
    .poem-meta { flex-direction: column; gap: 4px; align-items: center; }
    .poem-footer-actions { flex-direction: column; align-items: center; }
    .poem-body { font-size: 1rem; line-height: 2; }
}
</style>

<!-- ===== VOICE RECORDING JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const recordBtn = document.getElementById('recordBtn');
    const recordingStatus = document.getElementById('recordingStatus');
    const voiceForm = document.getElementById('voiceForm');
    const voiceFileInput = document.getElementById('voiceFileInput');
    const voicePreviewContainer = document.getElementById('voicePreviewContainer');
    const voicePreview = document.getElementById('voicePreview');

    let mediaRecorder = null;
    let audioChunks = [];

    if (recordBtn) {
        recordBtn.addEventListener('click', async function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                recordingStatus.style.display = 'none';
                recordBtn.textContent = '🎙️ Start Recording';
                recordBtn.classList.remove('btn-danger');
                recordBtn.classList.add('btn-secondary');
                return;
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];

                mediaRecorder.ondataavailable = event => {
                    audioChunks.push(event.data);
                };

                mediaRecorder.onstop = () => {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const file = new File([audioBlob], 'voice_comment.webm', { type: 'audio/webm' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    voiceFileInput.files = dt.files;

                    const url = URL.createObjectURL(file);
                    voicePreview.src = url;
                    voicePreviewContainer.style.display = 'block';
                    voiceForm.style.display = 'block';
                    recordBtn.textContent = '🎙️ Record Again';
                };

                mediaRecorder.start();
                recordingStatus.style.display = 'inline';
                recordBtn.textContent = '⏹️ Stop Recording';
                recordBtn.classList.remove('btn-secondary');
                recordBtn.classList.add('btn-danger');
            } catch (error) {
                alert('Microphone access denied or not available.');
                console.error('Recording error:', error);
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>