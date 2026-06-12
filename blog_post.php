<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== FETCH POST =====
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if (empty($slug)) {
    header('Location: ' . SITE_URL . '/blog.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: ' . SITE_URL . '/blog.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?");
$stmt->execute([$post['id']]);

// ===== TRACKING: User read this blog post =====
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO blog_reads (user_id, blog_post_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $post['id']]);
}

// ===== HANDLE COMMENT SUBMISSION (with live photo) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && isLoggedIn()) {
    $reflection_id = (int)$_POST['reflection_id'];
    $comment = trim($_POST['comment']);
    $photo_path = null;
    
    // ===== LIVE PHOTO CAPTURE =====
    if (!empty($_FILES['live_comment_photo']['name'])) {
        $upload_dir = '../assets/uploads/comments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'comment_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_comment_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $photo_path = 'assets/uploads/comments/' . $photo_filename;
        }
    }
    
    if (!empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reflection_comments (reflection_id, user_id, comment, photo_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$reflection_id, $_SESSION['user_id'], $comment, $photo_path]);
        
        // Send email notification to admin
        $user_id = $_SESSION['user_id'];
        $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $user_name = $user['name'] ?? 'A user';
        
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '💬 New Comment on: ' . $post['title'];
        $body = "<h2>New Comment Posted</h2>";
        $body .= "<p><strong>Post:</strong> " . $post['title'] . "</p>";
        $body .= "<p><strong>User:</strong> " . $user_name . "</p>";
        $body .= "<p><strong>Comment:</strong><br>" . nl2br(htmlspecialchars($comment)) . "</p>";
        if ($photo_path) {
            $body .= "<p><strong>Photo:</strong><br><img src='" . SITE_URL . "/" . $photo_path . "' style='max-width:200px;'></p>";
        }
        $body .= "<p><a href='" . SITE_URL . "/blog_post.php?slug=" . $slug . "'>View Post</a></p>";
        
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
        
        $success = 'Your comment has been posted!';
        header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
        exit;
    }
}

// ===== HANDLE VOICE COMMENT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voice_comment']) && isLoggedIn()) {
    $reflection_id = (int)$_POST['reflection_id'];
    if (isset($_FILES['voice_file']) && $_FILES['voice_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../assets/uploads/voice_comments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = 'voice_' . time() . '.webm';
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['voice_file']['tmp_name'], $target)) {
            $voice_path = 'assets/uploads/voice_comments/' . $filename;
            $stmt = $db->prepare("INSERT INTO reflection_comments (reflection_id, user_id, comment, voice_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$reflection_id, $_SESSION['user_id'], '🎙️ Voice comment', $voice_path]);
            
            // Send email notification to admin
            $user_id = $_SESSION['user_id'];
            $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $user_name = $user['name'] ?? 'A user';
            
            $admin_email = 'angelwrites@zohomail.com';
            $subject = '🎙️ New Voice Comment on: ' . $post['title'];
            $body = "<h2>New Voice Comment Posted</h2>";
            $body .= "<p><strong>Post:</strong> " . $post['title'] . "</p>";
            $body .= "<p><strong>User:</strong> " . $user_name . "</p>";
            $body .= "<p><a href='" . SITE_URL . "/blog_post.php?slug=" . $slug . "'>View Post</a></p>";
            
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
            
            $success = 'Voice comment posted!';
            header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
            exit;
        }
    }
}

// ===== HANDLE PRAYER REQUEST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_prayer']) && isLoggedIn()) {
    $reflection_id = (int)$_POST['reflection_id'];
    $message = trim($_POST['message'] ?? 'I would like prayer for this reflection.');
    
    $stmt = $db->prepare("INSERT INTO prayer_requests (user_id, reflection_id, request_type, message) VALUES (?, ?, 'prayer', ?)");
    $stmt->execute([$_SESSION['user_id'], $reflection_id, $message]);
    
    // Send email notification to admin
    $user_id = $_SESSION['user_id'];
    $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $user_name = $user['name'] ?? 'A user';
    
    $admin_email = 'angelwrites@zohomail.com';
    $subject = '🙏 New Prayer Request on: ' . $post['title'];
    $body = "<h2>New Prayer Request Submitted</h2>";
    $body .= "<p><strong>Post:</strong> " . $post['title'] . "</p>";
    $body .= "<p><strong>User:</strong> " . $user_name . "</p>";
    $body .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
    $body .= "<p><a href='" . SITE_URL . "/blog_post.php?slug=" . $slug . "'>View Post</a></p>";
    
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
    
    $success = 'Your prayer request has been sent!';
    header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
    exit;
}

// ===== HANDLE ADMIN REPLY =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin_reply']) && isAdmin()) {
    $reflection_id = (int)$_POST['reflection_id'];
    $reply = trim($_POST['admin_reply']);
    
    if (!empty($reply)) {
        $stmt = $db->prepare("INSERT INTO reflection_comments (reflection_id, user_id, comment, is_admin_reply) VALUES (?, ?, ?, 1)");
        $stmt->execute([$reflection_id, $_SESSION['user_id'], $reply]);
        $success = 'Admin reply posted!';
        header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
        exit;
    }
}

// ===== FETCH RELATED POSTS =====
$stmt = $db->prepare("
    SELECT id, title, slug, created_at FROM blog_posts 
    WHERE category = ? AND id != ? AND status = 'published' 
    ORDER BY created_at DESC LIMIT 3
");
$stmt->execute([$post['category'], $post['id']]);
$related_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH COMMENTS WITH REACTIONS =====
$stmt = $db->prepare("
    SELECT c.*, u.name AS author_name, u.avatar,
           (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction_type = 'like') as likes,
           (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction_type = 'love') as loves,
           (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = c.id AND reaction_type = 'pray') as prays
    FROM reflection_comments c
    JOIN users u ON c.user_id = u.id
    WHERE c.reflection_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$post['id']]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH READING PROGRESS FOR THIS POST (user) =====
$reading_progress = null;
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT progress_percent FROM reading_progress WHERE user_id = ? AND book_id = (SELECT book_id FROM blog_posts WHERE id = ?)");
    $stmt->execute([$_SESSION['user_id'], $post['id']]);
    $reading_progress = $stmt->fetchColumn() ?? 0;
}

$pageTitle = htmlspecialchars($post['title']) . ' — Blog';
?>
<?php require_once 'includes/header.php'; ?>

<div class="blog-post-page">
    <div class="container">
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Navigation -->
        <div class="blog-post-nav">
            <a href="<?php echo SITE_URL; ?>/blog.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Blog
            </a>
        </div>

        <!-- Article Header -->
        <article class="blog-post-article">
            <header class="post-header">
                <div class="post-meta">
                    <span class="post-category"><?php echo htmlspecialchars($post['category']); ?></span>
                    <span class="post-date">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('F j, Y', strtotime($post['created_at'])); ?>
                    </span>
                    <span class="post-views">
                        <i class="fas fa-eye"></i>
                        <?php echo number_format($post['views'] ?? 0); ?> views
                    </span>
                </div>
                <h1><?php echo htmlspecialchars($post['title']); ?></h1>
                <?php if ($post['excerpt']): ?>
                    <p class="post-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                <?php endif; ?>
            </header>

            <!-- Post Content -->
            <div class="post-content">
                <?php echo $post['content']; ?>
            </div>

            <!-- Reading Progress -->
            <?php if ($reading_progress !== null): ?>
                <div class="reading-progress-indicator">
                    <span class="progress-label">Your reading progress</span>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $reading_progress; ?>%;"></div>
                    </div>
                    <span class="progress-percent"><?php echo $reading_progress; ?>%</span>
                </div>
            <?php endif; ?>

            <!-- Reflection Actions -->
            <div class="reflection-actions">
                <form method="POST" class="prayer-form">
                    <input type="hidden" name="request_prayer" value="1">
                    <input type="hidden" name="reflection_id" value="<?php echo $post['id']; ?>">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-hands-praying"></i> Request Prayer
                    </button>
                </form>
                <a href="/book_session.php" class="btn btn-secondary">
                    <i class="fas fa-calendar-check"></i> Book a Session
                </a>
            </div>

            <!-- Post Footer -->
            <div class="post-footer">
                <div class="post-tags">
                    <?php if ($post['tags']): ?>
                        <?php foreach (explode(',', $post['tags']) as $tag): ?>
                            <span class="tag">#<?php echo htmlspecialchars(trim($tag)); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="share-section">
                    <span>Share:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/blog_post.php?slug=' . $slug); ?>" target="_blank" class="share-btn facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($post['title'] . ' — read this reflection by Angella Bottoman'); ?>&url=<?php echo urlencode(SITE_URL . '/blog_post.php?slug=' . $slug); ?>" target="_blank" class="share-btn twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode($post['title'] . ' — read this reflection: ' . SITE_URL . '/blog_post.php?slug=' . $slug); ?>" target="_blank" class="share-btn whatsapp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                </div>
            </div>
        </article>

        <!-- Related Posts -->
        <?php if (count($related_posts) > 0): ?>
            <section class="related-posts">
                <h3>Related Reflections</h3>
                <div class="related-grid">
                    <?php foreach ($related_posts as $rp): ?>
                        <div class="related-card">
                            <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $rp['slug']; ?>">
                                <h4><?php echo htmlspecialchars($rp['title']); ?></h4>
                                <small><?php echo date('M j, Y', strtotime($rp['created_at'])); ?></small>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Comments Section -->
        <section class="reflection-comments">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Questions</h3>
            
            <?php if (count($comments) > 0): ?>
                <div class="comments-list">
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item <?php echo $comment['is_admin_reply'] ? 'admin-reply' : ''; ?>">
                            <div class="comment-author">
                                <i class="fas fa-user-circle"></i>
                                <?php echo htmlspecialchars($comment['author_name']); ?>
                                <?php if ($comment['is_admin_reply']): ?>
                                    <span class="admin-badge">🛡️ Angella's Reply</span>
                                <?php endif; ?>
                            </div>
                            <div class="comment-date"><?php echo date('M j, Y', strtotime($comment['created_at'])); ?></div>
                            <div class="comment-body">
                                <?php if ($comment['voice_path']): ?>
                                    <audio controls>
                                        <source src="<?php echo SITE_URL . '/' . $comment['voice_path']; ?>" type="audio/webm">
                                    </audio>
                                <?php else: ?>
                                    <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                <?php endif; ?>
                                <?php if ($comment['photo_path']): ?>
                                    <div class="comment-photo">
                                        <img src="<?php echo SITE_URL . '/' . $comment['photo_path']; ?>" alt="Comment photo" style="max-width:200px; border-radius:8px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-footer">
                                <div class="reactions">
                                    <button class="reaction-btn" onclick="reactToComment(<?php echo $comment['id']; ?>, 'like')">
                                        👍 <span id="likes-<?php echo $comment['id']; ?>"><?php echo $comment['likes']; ?></span>
                                    </button>
                                    <button class="reaction-btn" onclick="reactToComment(<?php echo $comment['id']; ?>, 'love')">
                                        ❤️ <span id="loves-<?php echo $comment['id']; ?>"><?php echo $comment['loves']; ?></span>
                                    </button>
                                    <button class="reaction-btn" onclick="reactToComment(<?php echo $comment['id']; ?>, 'pray')">
                                        🙏 <span id="prays-<?php echo $comment['id']; ?>"><?php echo $comment['prays']; ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-comments">No comments yet. Share your thoughts or ask a question.</p>
            <?php endif; ?>

            <!-- Comment Form -->
            <?php if (isLoggedIn()): ?>
                <div class="comment-form-container">
                    <h4>Add a Comment</h4>
                    
                    <!-- Text Comment with Live Photo -->
                    <form method="POST" enctype="multipart/form-data" class="comment-form">
                        <input type="hidden" name="add_comment" value="1">
                        <input type="hidden" name="reflection_id" value="<?php echo $post['id']; ?>">
                        <div class="form-group">
                            <textarea name="comment" rows="3" placeholder="Share your thoughts about this reflection..." required></textarea>
                        </div>
                        
                        <!-- ===== LIVE PHOTO CAPTURE ===== -->
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
                                <input type="file" id="livePhotoInput" name="live_comment_photo" accept="image/*" style="display:none;">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Post Comment</button>
                    </form>

                    <!-- Voice Comment -->
                    <div class="voice-comment-section">
                        <button id="recordBtn" class="btn btn-secondary">🎙️ Record Voice Comment</button>
                        <span id="recordingStatus" style="display:none;">🔴 Recording...</span>
                        <form method="POST" enctype="multipart/form-data" id="voiceForm" style="display:none;">
                            <input type="hidden" name="submit_voice_comment" value="1">
                            <input type="hidden" name="reflection_id" value="<?php echo $post['id']; ?>">
                            <input type="file" name="voice_file" id="voiceFileInput" accept="audio/webm" required>
                            <button type="submit" class="btn btn-success">Upload Voice</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to comment or ask a question.</p>
                </div>
            <?php endif; ?>

            <!-- Admin Reply Form -->
            <?php if (isAdmin()): ?>
                <div class="admin-reply-container">
                    <h4>🛡️ Angella's Reply</h4>
                    <form method="POST" class="admin-reply-form">
                        <input type="hidden" name="add_admin_reply" value="1">
                        <input type="hidden" name="reflection_id" value="<?php echo $post['id']; ?>">
                        <div class="form-group">
                            <textarea name="admin_reply" rows="3" placeholder="Reply to this post directly..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Post Reply</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>

        <!-- Newsletter CTA -->
        <section class="blog-newsletter-cta">
            <div class="cta-inner">
                <h3>Stay Inspired</h3>
                <p>Receive new reflections and updates from Angella directly to your inbox.</p>
                <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="cta-form">
                    <input type="email" name="email" placeholder="Your email address" required>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Subscribe Free
                    </button>
                </form>
                <small>No spam. Unsubscribe anytime.</small>
            </div>
        </section>
    </div>
</div>

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('blogPostTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('blogPostTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== BACK TO TOP =====
    const backToTopBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 400) {
            backToTopBtn.style.display = 'flex';
        } else {
            backToTopBtn.style.display = 'none';
        }
    });

    // ===== CAMERA (LIVE PHOTO) =====
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
        const file = new File([capturedBlob], 'comment_photo.jpg', { type: 'image/jpeg' });
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

    // ===== VOICE RECORDER =====
    const recordBtn = document.getElementById('recordBtn');
    const recordingStatus = document.getElementById('recordingStatus');
    const voiceForm = document.getElementById('voiceForm');
    const voiceFileInput = document.getElementById('voiceFileInput');
    let mediaRecorder = null;
    let audioChunks = [];

    if (recordBtn) {
        recordBtn.addEventListener('click', async function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                recordingStatus.style.display = 'none';
                recordBtn.textContent = '⏹️ Stopped';
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
                    voiceForm.style.display = 'block';
                    recordBtn.textContent = '🎙️ Record Again';
                };

                mediaRecorder.start();
                recordingStatus.style.display = 'inline';
                recordBtn.textContent = '⏹️ Stop Recording';
            } catch (error) {
                alert('Microphone access denied or not available.');
                console.error('Recording error:', error);
            }
        });
    }

    // ===== REACTIONS =====
    window.reactToComment = function(commentId, reactionType) {
        const formData = new FormData();
        formData.append('action', 'react_to_comment');
        formData.append('comment_id', commentId);
        formData.append('reaction_type', reactionType);
        fetch('/comment_reactions.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('likes-' + commentId).textContent = data.likes;
                document.getElementById('loves-' + commentId).textContent = data.loves;
                document.getElementById('prays-' + commentId).textContent = data.prays;
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

.blog-post-page { padding: 32px 0 60px; }
.blog-post-nav { margin-bottom: 24px; }
.back-link { color: var(--text-light); font-size: 0.95rem; transition: color 0.2s; }
.back-link:hover { color: var(--rose); }
.back-link i { margin-right: 6px; }
.blog-post-article { max-width: 780px; margin: 0 auto; }
.post-header { margin-bottom: 32px; }
.post-meta { display: flex; flex-wrap: wrap; gap: 16px; color: var(--text-light); font-size: 0.9rem; margin-bottom: 12px; }
.post-meta span { display: flex; align-items: center; gap: 4px; }
.post-category { background: var(--vanilla); padding: 2px 12px; border-radius: 12px; font-weight: 500; color: var(--text); }
.post-header h1 { font-family: 'Playfair Display', serif; font-size: clamp(1.8rem, 3.5vw, 2.8rem); line-height: 1.2; margin-bottom: 12px; }
.post-excerpt { font-size: 1.1rem; color: var(--text-light); line-height: 1.7; font-style: italic; }
.post-content { line-height: 1.9; color: var(--text); font-size: 1.05rem; margin-bottom: 32px; }
.post-content p { margin-bottom: 16px; }
.post-content h2, .post-content h3, .post-content h4 { margin: 24px 0 12px; }
.post-content ul, .post-content ol { padding-left: 24px; margin-bottom: 16px; }
.post-content blockquote { border-left: 4px solid var(--rose); padding-left: 16px; margin: 16px 0; color: var(--text-light); font-style: italic; }
.post-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 16px 0; }
.post-content video { max-width: 100%; border-radius: 8px; margin: 16px 0; }
.post-content audio { width: 100%; margin: 16px 0; }
.post-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding-top: 24px; border-top: 1px solid var(--border); }
.post-tags { display: flex; flex-wrap: wrap; gap: 6px; }
.tag { background: var(--vanilla); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; color: var(--text); }
.share-section { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-light); }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); opacity: 0.85; }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

/* ===== REFLECTION ACTIONS ===== */
.reflection-actions { display: flex; gap: 12px; flex-wrap: wrap; margin: 24px 0; justify-content: center; }
.prayer-form { display: inline; }
.reflection-actions .btn { padding: 10px 20px; font-size: 0.9rem; }

/* ===== RELATED POSTS ===== */
.related-posts { max-width: 780px; margin: 40px auto 0; }
.related-posts h3 { font-size: 1.4rem; margin-bottom: 16px; }
.related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.related-card { background: var(--card-bg); border-radius: 10px; padding: 16px; border: 1px solid var(--border); transition: transform 0.2s; }
.related-card:hover { transform: translateY(-3px); }
.related-card a { color: var(--text); text-decoration: none; }
.related-card h4 { font-size: 1rem; margin-bottom: 4px; transition: color 0.2s; }
.related-card a:hover h4 { color: var(--rose); }
.related-card small { color: var(--text-light); font-size: 0.8rem; }

/* ===== COMMENTS SECTION ===== */
.reflection-comments { max-width: 780px; margin: 48px auto 0; }
.reflection-comments h3 { font-size: 1.4rem; margin-bottom: 16px; }

.comments-list { display: flex; flex-direction: column; gap: 16px; }
.comment-item { background: var(--card-bg); border-radius: 12px; padding: 16px 20px; border: 1px solid var(--border); }
.comment-item.admin-reply { background: var(--vanilla); border-left: 5px solid var(--rose); }
.comment-author { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.comment-author i { color: var(--rose); }
.admin-badge { background: var(--rose); color: white; font-size: 0.7rem; padding: 2px 10px; border-radius: 12px; font-weight: 600; }
.comment-date { font-size: 0.85rem; color: var(--text-light); margin: 2px 0 6px; }
.comment-body { line-height: 1.6; color: var(--text); }
.comment-body audio { width: 100%; border-radius: 8px; margin-top: 4px; }
.comment-photo { margin-top: 8px; }
.comment-photo img { border-radius: 8px; border: 1px solid var(--border); }
.comment-footer { margin-top: 8px; display: flex; gap: 8px; align-items: center; }
.reactions { display: flex; gap: 6px; }
.reaction-btn { background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 0.85rem; transition: color 0.2s; display: flex; align-items: center; gap: 2px; }
.reaction-btn:hover { color: var(--rose); }

.comment-form-container { background: var(--fantasy); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.comment-form-container h4 { margin-bottom: 12px; }
.comment-form .form-group { margin-bottom: 12px; }
.comment-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; background: var(--input-bg); color: var(--text); }
.comment-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.comment-form .btn { margin-top: 4px; }

.voice-comment-section { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.voice-comment-section .btn { margin-right: 8px; }
#recordingStatus { font-weight: 600; color: #e74c3c; }

.admin-reply-container { background: var(--vanilla); border-radius: 12px; padding: 20px; border-left: 5px solid var(--rose); margin-top: 16px; }
.admin-reply-container h4 { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; color: var(--dark); }
.admin-reply-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; background: var(--input-bg); color: var(--text); }
.admin-reply-form .btn { margin-top: 8px; }

/* ===== NEWSLETTER CTA ===== */
.blog-newsletter-cta { max-width: 780px; margin: 48px auto 0; background: var(--vanilla); border-radius: 12px; padding: 32px; text-align: center; }
.blog-newsletter-cta .cta-inner h3 { font-size: 1.4rem; margin-bottom: 4px; }
.blog-newsletter-cta .cta-inner p { color: var(--text-light); margin-bottom: 16px; }
.cta-form { display: flex; gap: 12px; max-width: 450px; margin: 0 auto 8px; flex-wrap: wrap; justify-content: center; }
.cta-form input { flex: 1; min-width: 200px; padding: 10px 16px; border: 1px solid var(--border); border-radius: 30px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.cta-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.cta-form .btn { padding: 10px 28px; border-radius: 30px; }
.blog-newsletter-cta small { color: var(--text-light); font-size: 0.8rem; }

/* ===== CAMERA SECTION ===== */
.camera-section { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: var(--fantasy); margin-top: 8px; }
.camera-preview-container { width: 100%; max-width: 400px; height: 220px; background: var(--vanilla); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; margin: 0 auto; }
.camera-preview-container video { width: 100%; height: 100%; object-fit: cover; display: none; }
.camera-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-light); text-align: center; padding: 24px; }
.camera-placeholder i { font-size: 2.5rem; margin-bottom: 8px; color: var(--rose); }
.camera-placeholder p { margin: 0; font-size: 0.9rem; }
.camera-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center; margin-top: 12px; }
.camera-controls .btn { padding: 6px 14px; font-size: 0.85rem; }
.captured-photo-container { text-align: center; margin-top: 12px; }
.captured-photo-container img { border: 2px solid var(--rose); border-radius: 8px; }
.status-indicator { font-size: 0.85rem; color: var(--text-light); margin-left: 8px; font-weight: 500; }

/* ===== BACK TO TOP ===== */
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .post-meta { flex-direction: column; gap: 4px; }
    .post-footer { flex-direction: column; align-items: stretch; }
    .related-grid { grid-template-columns: 1fr; }
    .cta-form { flex-direction: column; }
    .cta-form input { min-width: unset; }
    .cta-form .btn { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>