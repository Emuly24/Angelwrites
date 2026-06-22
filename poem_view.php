<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
$stmt->execute([$id]);
$poem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$poem) {
    header('Location: ' . SITE_URL . '/poetry.php');
    exit;
}

// ============================================================
// 🚀 FIX: HONEST VIEW COUNTER (Increments on EVERY visit)
// ============================================================
$stmt = $db->prepare("UPDATE poems SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$id]);
$poem['view_count'] = ($poem['view_count'] ?? 0) + 1;

// ===== TRACKING (Only for logged-in users) =====
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_exists = $stmt->fetchColumn();
    if ($user_exists && $poem) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ? AND poem_id = ?");
        $stmt->execute([$user_id, $id]);
        $already_read = $stmt->fetchColumn();
        if (!$already_read) {
            try {
                $stmt = $db->prepare("INSERT INTO poem_reads (user_id, poem_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $id]);
            } catch (PDOException $e) {
                error_log("Poem tracking failed: " . $e->getMessage());
            }
        }
    }
}

// ===== HANDLE TEXT REVIEW =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && isLoggedIn()) {
    $target_type = $_POST['target_type'];
    $target_id = (int)$_POST['target_id'];
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$target_type, $target_id, $_SESSION['user_id'], $rating, $comment]);
        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
        exit;
    }
}

// ===== HANDLE VOICE COMMENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voice_comment']) && isLoggedIn()) {
    $target_id = (int)$_POST['target_id'];
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    if (isset($_FILES['voice_file']) && $_FILES['voice_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/voice_comments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = 'voice_' . time() . '.webm';
        if (move_uploaded_file($_FILES['voice_file']['tmp_name'], $upload_dir . $filename)) {
            $voice_path = 'assets/uploads/voice_comments/' . $filename;
            $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment, voice_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['poem', $target_id, $_SESSION['user_id'], $rating, '🎙️ Voice comment', $voice_path]);
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
        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
        exit;
    }
}

// ===== READING TIME =====
function readingTime($content) {
    $word_count = str_word_count(strip_tags($content));
    $minutes = ceil($word_count / 200);
    return $minutes < 1 ? '1 min read' : $minutes . ' min read';
}

// ===== FETCH REVIEWS =====
$stmt = $db->prepare("
    SELECT r.*, u.name AS author_name 
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.target_type = 'poem' AND r.target_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== AVERAGE RATING =====
$stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type = 'poem' AND target_id = ?");
$stmt->execute([$id]);
$rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
$avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
$total_reviews = $rating_data['total'] ?? 0;

/// ============================================================
// 🚀 SHARE & OG DATA (Bulletproof URL formatting)
// ============================================================
$base_url = rtrim((defined('SITE_URL') && !empty(SITE_URL) ? SITE_URL : 'https://angelwrites.gt.tc'), '/');
$full_url = $base_url . '/poem_view.php?id=' . $id;
$encoded_url = urlencode($full_url);
$encoded_title = urlencode($poem['title']);
$wa_text = urlencode($poem['title'] . ' — read this poem on AngelWrites: ' . $full_url);
$twitter_text = urlencode($poem['title'] . ' — a poem by Angella Bottoman');

$pageTitle = htmlspecialchars($poem['title']) . ' — Poetry';

// 🖼️ OG Variables (To be read by header.php)
$og_title = htmlspecialchars($poem['title']);
$og_url = $full_url;
$og_description = htmlspecialchars(substr($poem['intro'] ?? strip_tags($poem['content']), 0, 150));

// 📸 Image Path (Foolproof)
$og_image = '';
if (!empty($poem['image_path'])) {
    // Remove leading slash from stored path, then append to clean base URL
    $og_image = $base_url . '/' . ltrim($poem['image_path'], '/');
} else {
    // 📌 Make sure this file physically exists in your assets folder!
    $og_image = $base_url . '/assets/images/angelwrites-logo.png'; 
}

// 🖼️ OG Image Dimensions (Helps WhatsApp/Facebook process it faster)
$og_image_width = 1200;
$og_image_height = 630;
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="poem-view-page">
    <div class="container">
        <!-- Navigation -->
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
                <span class="poem-views"><i class="fas fa-eye"></i> <?php echo number_format($poem['view_count'] ?? 1); ?> views</span>
                <span class="poem-reading-time"><i class="fas fa-clock"></i> <?php echo readingTime($poem['content']); ?></span>
            </div>
        </header>

        <!-- 🚀 FIXED: Bulletproof IMG URL -->
        <?php if ($poem['image_path']): ?>
            <div class="poem-image-container">
                <img src="<?php echo rtrim(SITE_URL, '/') . '/' . ltrim($poem['image_path'], '/'); ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" class="poem-feature-image">
            </div>
        <?php endif; ?>

        <!-- ===== ENHANCED AUDIO PLAYER WITH WAVE VISUALIZER ===== -->
        <?php if ($poem['audio_path']): ?>
            <div class="poem-audio-player">
                <div class="audio-label">
                    <i class="fas fa-headphones"></i>
                    <span>Listen to this poem</span>
                </div>
                <div id="customAudioPlayer">
                    <canvas id="waveCanvas"></canvas>
                    <!-- 🚀 FIXED: Bulletproof AUDIO URL -->
                    <audio id="audioSource" src="<?php echo rtrim(SITE_URL, '/') . '/' . ltrim($poem['audio_path'], '/'); ?>" preload="metadata"></audio>
                    <div class="audio-controls-bar">
                        <button id="playPauseBtn" class="play-btn" aria-label="Play">
                            <i class="fas fa-play"></i>
                        </button>
                        <div class="progress-container">
                            <div class="progress-bar" id="progressBar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>
                        </div>
                        <span class="time-display" id="timeDisplay">0:00 / 0:00</span>
                        <div class="volume-control">
                            <button id="muteBtn" aria-label="Mute"><i class="fas fa-volume-up"></i></button>
                            <input type="range" id="volumeSlider" min="0" max="1" step="0.05" value="1">
                        </div>
                    </div>
                </div>
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

        <!-- ===== REVIEWS & COMMENTS ===== -->
        <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            
            <div class="rating-summary">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-score"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
            </div>

            <!-- Text Review Form -->
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
                    <p><a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode(SITE_URL . '/poem_view.php?id=' . $id); ?>">Login</a> to rate, review, or leave a voice comment.</p>
                </div>
            <?php endif; ?>

            <!-- Admin Reply Form -->
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

            <!-- Reviews List -->
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
                                            <source src="<?php echo rtrim(SITE_URL, '/') . '/' . ltrim($review['voice_path'], '/'); ?>" type="audio/webm">
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
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $encoded_url; ?>" target="_blank" class="share-btn facebook">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://twitter.com/intent/tweet?text=<?php echo $twitter_text; ?>&url=<?php echo $encoded_url; ?>" target="_blank" class="share-btn twitter">
                    <i class="fab fa-twitter"></i>
                </a>
                <a href="https://api.whatsapp.com/send?text=<?php echo $wa_text; ?>" target="_blank" class="share-btn whatsapp">
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

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ===== JAVASCRIPT (Unchanged) ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });
    const backToTopBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 400) {
            backToTopBtn.style.display = 'flex';
        } else {
            backToTopBtn.style.display = 'none';
        }
    });
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
    const audio = document.getElementById('audioSource');
    const playBtn = document.getElementById('playPauseBtn');
    const playIcon = playBtn.querySelector('i');
    const progressFill = document.getElementById('progressFill');
    const progressBar = document.getElementById('progressBar');
    const timeDisplay = document.getElementById('timeDisplay');
    const muteBtn = document.getElementById('muteBtn');
    const volumeSlider = document.getElementById('volumeSlider');
    const canvas = document.getElementById('waveCanvas');
    const ctx = canvas.getContext('2d');
    let isPlaying = false;
    let audioContext = null;
    let analyser = null;
    let source = null;
    let animationId = null;
    let dataArray = null;
    function resizeCanvas() {
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width;
        canvas.height = 100;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
    function initAudioContext() {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.8;
            source = audioContext.createMediaElementSource(audio);
            source.connect(analyser);
            analyser.connect(audioContext.destination);
            dataArray = new Uint8Array(analyser.frequencyBinCount);
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
    }
    function drawWave() {
        if (!analyser) return;
        animationId = requestAnimationFrame(drawWave);
        analyser.getByteFrequencyData(dataArray);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const barCount = 64;
        const barWidth = (canvas.width / barCount) * 0.6;
        const gap = (canvas.width / barCount) * 0.4;
        const halfHeight = canvas.height / 2;
        const gradient = ctx.createLinearGradient(0, 0, canvas.width, 0);
        gradient.addColorStop(0, '#DBA1A2');
        gradient.addColorStop(0.5, '#e8c0c0');
        gradient.addColorStop(1, '#DBA1A2');
        ctx.fillStyle = gradient;
        for (let i = 0; i < barCount; i++) {
            const value = dataArray[i] / 255;
            const barHeight = value * halfHeight * 1.5;
            const x = i * (barWidth + gap) + gap / 2;
            const y = halfHeight - barHeight / 2;
            const radius = 4;
            ctx.beginPath();
            ctx.moveTo(x + radius, y);
            ctx.lineTo(x + barWidth - radius, y);
            ctx.quadraticCurveTo(x + barWidth, y, x + barWidth, y + radius);
            ctx.lineTo(x + barWidth, y + barHeight - radius);
            ctx.quadraticCurveTo(x + barWidth, y + barHeight, x + barWidth - radius, y + barHeight);
            ctx.lineTo(x + radius, y + barHeight);
            ctx.quadraticCurveTo(x, y + barHeight, x, y + barHeight - radius);
            ctx.lineTo(x, y + radius);
            ctx.quadraticCurveTo(x, y, x + radius, y);
            ctx.closePath();
            ctx.fill();
        }
        const overlayGradient = ctx.createLinearGradient(0, 0, canvas.width, 0);
        overlayGradient.addColorStop(0, 'rgba(219, 161, 162, 0.15)');
        overlayGradient.addColorStop(0.5, 'rgba(219, 161, 162, 0)');
        overlayGradient.addColorStop(1, 'rgba(219, 161, 162, 0.15)');
        ctx.fillStyle = overlayGradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    function stopVisualizer() {
        if (animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
        }
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
    playBtn.addEventListener('click', function() {
        if (audio.paused) {
            initAudioContext();
            audio.play();
            isPlaying = true;
            playIcon.className = 'fas fa-pause';
            if (!animationId) drawWave();
        } else {
            audio.pause();
            isPlaying = false;
            playIcon.className = 'fas fa-play';
            stopVisualizer();
        }
    });
    audio.addEventListener('ended', function() {
        isPlaying = false;
        playIcon.className = 'fas fa-play';
        stopVisualizer();
        progressFill.style.width = '0%';
        updateTimeDisplay();
    });
    audio.addEventListener('timeupdate', function() {
        const percent = (audio.currentTime / audio.duration) * 100;
        progressFill.style.width = percent + '%';
        updateTimeDisplay();
    });
    progressBar.addEventListener('click', function(e) {
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percent = x / rect.width;
        audio.currentTime = percent * audio.duration;
    });
    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }
    function updateTimeDisplay() {
        const current = formatTime(audio.currentTime || 0);
        const total = formatTime(audio.duration || 0);
        timeDisplay.textContent = current + ' / ' + total;
    }
    audio.addEventListener('loadedmetadata', updateTimeDisplay);
    volumeSlider.addEventListener('input', function() {
        audio.volume = this.value;
        muteBtn.querySelector('i').className = this.value == 0 ? 'fas fa-volume-mute' : 'fas fa-volume-up';
    });
    muteBtn.addEventListener('click', function() {
        if (audio.volume > 0) {
            audio.volume = 0;
            volumeSlider.value = 0;
            muteBtn.querySelector('i').className = 'fas fa-volume-mute';
        } else {
            audio.volume = 1;
            volumeSlider.value = 1;
            muteBtn.querySelector('i').className = 'fas fa-volume-up';
        }
    });
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && isPlaying && !animationId) {
            drawWave();
        }
        if (document.hidden && animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
        }
    });
    window.addEventListener('beforeunload', function() {
        if (audioContext) {
            audioContext.close();
        }
    });
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44, 30, 30, 0.08);
    --shadow-hover: 0 8px 30px rgba(44, 30, 30, 0.15);
    --input-bg: #ffffff;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text: #e8dddd;
    --text-light: #aaa;
    --vanilla: #2a2a2a;
    --fantasy: #1a1a1a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
    --input-bg: #333;
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }
.poem-view-page { padding: 32px 0 60px; }
.poem-nav { margin-bottom: 24px; }
.poem-nav .back-link { color: var(--text-light); font-size: 0.95rem; transition: color 0.2s; }
.poem-nav .back-link:hover { color: var(--rose); }
.poem-nav .back-link i { margin-right: 6px; }
.poem-header { text-align: center; margin-bottom: 32px; }
.poem-header h1 { font-family: 'Playfair Display', serif; font-size: clamp(2rem, 4vw, 3.2rem); color: var(--dark); margin-bottom: 8px; line-height: 1.2; }
.poem-meta { display: flex; justify-content: center; gap: 24px; color: var(--text-light); font-size: 0.9rem; flex-wrap: wrap; }
.poem-meta i { margin-right: 4px; }
.poem-image-container { margin: 0 auto 32px; max-width: 700px; text-align: center; }
.poem-feature-image { width: 100%; height: auto; border: 6px solid var(--rose); border-radius: 16px; box-shadow: var(--shadow-hover); display: block; }
.poem-audio-player { max-width: 700px; margin: 0 auto 24px; background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.audio-label { display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
.audio-label i { color: var(--rose); font-size: 1.2rem; }
#customAudioPlayer { position: relative; }
#waveCanvas { width: 100%; height: 100px; border-radius: 8px; display: block; }
.audio-controls-bar { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.audio-controls-bar .play-btn { background: var(--rose); border: none; color: white; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; }
.audio-controls-bar .play-btn:hover { background: var(--rose-dark); }
.progress-container { flex: 1; min-width: 80px; }
.progress-bar { height: 4px; background: var(--border); border-radius: 2px; cursor: pointer; position: relative; }
.progress-fill { height: 100%; background: var(--rose); border-radius: 2px; width: 0%; transition: width 0.1s; }
.time-display { font-size: 0.85rem; color: var(--text-light); min-width: 70px; text-align: center; }
.volume-control { display: flex; align-items: center; gap: 4px; }
.volume-control button { background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 0.9rem; padding: 2px; }
.volume-control input[type="range"] { width: 60px; accent-color: var(--rose); background: var(--border); height: 4px; border-radius: 2px; }
.volume-control input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 12px; height: 12px; border-radius: 50%; background: var(--rose); cursor: pointer; }
.volume-control input[type="range"]::-moz-range-thumb { width: 12px; height: 12px; border-radius: 50%; background: var(--rose); cursor: pointer; border: none; }
.poem-intro-section { max-width: 700px; margin: 0 auto 32px; background: var(--fantasy); border-left: 4px solid var(--rose); border-radius: 0 12px 12px 0; padding: 20px 24px; }
.intro-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--rose); margin-bottom: 6px; }
.intro-body { font-style: italic; font-size: 1.05rem; color: var(--text); line-height: 1.8; text-align: justify; }
.poem-content-section { max-width: 700px; margin: 0 auto 32px; border: 4px solid var(--rose); border-radius: 16px; padding: 32px; background: var(--card-bg); box-shadow: var(--shadow-hover); }
.poem-body { font-family: 'Georgia', serif; font-size: 1.15rem; line-height: 2.4; color: var(--text); text-align: center; padding: 0; }
.poem-body p { margin-bottom: 24px; }
.poem-body p:last-child { margin-bottom: 0; }
.poem-body br { display: block; content: ""; margin: 12px 0; }
.poem-body img { max-width: 100%; height: auto; margin: 16px auto; display: block; border-radius: 8px; }
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
.review-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
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
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); opacity: 0.85; }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }
.reading-actions .btn { font-size: 0.85rem; }
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }
@media (max-width: 480px) {
    .poem-header h1 { font-size: 1.8rem; }
    .poem-meta { flex-direction: column; gap: 4px; align-items: center; }
    .poem-footer-actions { flex-direction: column; align-items: center; }
    .poem-body { font-size: 1rem; line-height: 2; }
    .audio-controls-bar { gap: 6px; }
    .volume-control input[type="range"] { width: 40px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>