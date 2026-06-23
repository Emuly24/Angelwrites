<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ============================================================
// 1. FULL-PAGE CACHE FOR ANONYMOUS USERS
// ============================================================
$cacheFile = __DIR__ . '/cache/index.html';
$cacheTime = 300; // 5 minutes

// If user is not logged in, serve cached version if available
if (!isLoggedIn()) {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        readfile($cacheFile);
        exit;
    }
    ob_start();
    $doCache = true;
} else {
    $doCache = false;
}

// ============================================================
// 2. CSRF PROTECTION HELPER (if not already defined)
// ============================================================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ============================================================
// 3. WEBP IMAGE HELPER
// ============================================================
if (!function_exists('get_image_url')) {
    function get_image_url($path) {
        if (empty($path)) return '';
        $base = rtrim(SITE_URL, '/');
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $webp_support = strpos($accept, 'image/webp') !== false;
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($webp_support && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/', '.webp', $path);
            $full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $webp_path;
            if (file_exists($full_path)) {
                return $base . '/' . $webp_path;
            }
        }
        return $base . '/' . ltrim($path, '/');
    }
}

// ============================================================
// 4. FETCH CONTENT
// ============================================================
$isLoggedIn = isLoggedIn();
$userId = $isLoggedIn ? $_SESSION['user_id'] : 0;

// Featured Books (latest 3)
$stmt = $db->prepare("SELECT * FROM books ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$featured_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest Poems (latest 3)
$stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$latest_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest Blog Posts (published, latest 3)
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 3");
$stmt->execute();
$latest_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH TESTIMONIALS (real, approved) =====
$stmt = $db->prepare("SELECT * FROM testimonials WHERE approved = 1 ORDER BY created_at DESC LIMIT 8");
$stmt->execute();
$testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH LIVE STATS =====
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM books WHERE is_free = 1");
$stmt->execute();
$free_books = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM prayer_requests");
$stmt->execute();
$total_prayers = $stmt->fetchColumn();

// ===== PERSONALIZED RECOMMENDATIONS =====
$recommended_books = [];
if ($isLoggedIn) {
    $stmt = $db->prepare("
        SELECT b.* FROM books b
        WHERE b.id NOT IN (
            SELECT book_id FROM reading_status WHERE user_id = ?
        )
        ORDER BY RANDOM() LIMIT 2
    ");
    $stmt->execute([$userId]);
    $recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$greeting = $isLoggedIn ? "Welcome back, " . htmlspecialchars($_SESSION['name'] ?? 'Friend') . "!" : "Welcome Home.";

// ===== NEWSLETTER (with CSRF) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $newsletter_error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['newsletter_email']);
        $name = isset($_POST['newsletter_name']) ? trim($_POST['newsletter_name']) : '';
        
        if (empty($email)) {
            $newsletter_error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $newsletter_error = 'Please enter a valid email address.';
        } else {
            $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['is_active'] == 1) {
                    $newsletter_error = 'This email is already subscribed.';
                } else {
                    $stmt = $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$existing['id']]);
                    $newsletter_success = 'Your subscription has been reactivated. Welcome back!';
                    
                    $admin_email = 'angelwrites@zohomail.com';
                    $subject = 'Newsletter Subscription Reactivated';
                    $body = "A user has reactivated their newsletter subscription.\n\nEmail: $email\nName: " . ($name ?: 'Not provided');
                    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                }
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token) VALUES (?, ?, 1, ?)");
                if ($stmt->execute([$email, $name, $token])) {
                    $newsletter_success = 'Thank you for subscribing! You will receive updates from Angella.';
                    
                    $admin_email = 'angelwrites@zohomail.com';
                    $subject = 'New Newsletter Subscriber';
                    $body = "A new user has subscribed to the newsletter.\n\nEmail: $email\nName: " . ($name ?: 'Not provided');
                    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                } else {
                    $newsletter_error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

// ===== TESTIMONIAL SUBMISSION (with CSRF) =====
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_testimonial'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $testimonial_error = 'Invalid request. Please try again.';
    } else {
        $testimony = trim($_POST['testimony']);
        $is_public = isset($_POST['is_public']) ? 1 : 0;
        
        if (empty($testimony)) {
            $testimonial_error = 'Please share your story.';
        } elseif (strlen($testimony) < 20) {
            $testimonial_error = 'Please write at least 20 characters.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO testimonials (user_id, testimony, is_public, approved, created_at)
                VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$userId, $testimony, $is_public]);
            $testimonial_success = 'Thank you for sharing! Your story will be reviewed and featured soon.';
            
            $admin_email = 'angelwrites@zohomail.com';
            $subject = 'New Testimonial Submission';
            $body = "A new testimonial has been submitted.\n\nUser: " . $_SESSION['name'] . "\nPublic: " . ($is_public ? 'Yes' : 'No') . "\n\n$testimony";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }
    }
}

// ===== FETCH POEM WITH MOST COMMENTS FOR CAROUSEL =====
$carousel_poem = null;
$carousel_comments = [];
$stmt = $db->prepare("
    SELECT p.*, COUNT(r.id) as comment_count
    FROM poems p
    JOIN reviews r ON r.target_type = 'poem' AND r.target_id = p.id
    WHERE r.deleted_at IS NULL AND r.is_private = 0
    GROUP BY p.id
    ORDER BY comment_count DESC
    LIMIT 1
");
$stmt->execute();
$carousel_poem = $stmt->fetch(PDO::FETCH_ASSOC);

if ($carousel_poem) {
    $stmt = $db->prepare("
        SELECT r.*, u.name AS author_name
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.target_type = 'poem' AND r.target_id = ? AND r.deleted_at IS NULL AND r.is_private = 0
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$carousel_poem['id']]);
    $carousel_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($carousel_comments)) {
        $stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $carousel_poem = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($carousel_poem) {
            $stmt = $db->prepare("
                SELECT r.*, u.name AS author_name
                FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.target_type = 'poem' AND r.target_id = ? AND r.deleted_at IS NULL AND r.is_private = 0
                ORDER BY r.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$carousel_poem['id']]);
            $carousel_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$pageTitle = 'AngelWrites — Christian Writing & Community';
?>
<?php require_once 'includes/header.php'; ?>

<!-- PWA Manifest and Service Worker -->
<link rel="manifest" href="/manifest.json">
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
</script>

<!-- ===== CSS (same as before, but we keep it concise) ===== -->
<style>
/* ... (all previous CSS, please keep it) ... */
/* We are not duplicating all CSS here to save space; it's the same as the previous index */
</style>

<!-- ===== HTML CONTENT ===== -->

<!-- HERO -->
<section class="hero">
    <div class="container hero-content">
        <div class="hero-text">
            <span class="hero-badge">✧ A Safe Place for Your Heart</span>
            <h1>
                <?php if ($isLoggedIn): ?>
                    Welcome Back, <span class="rose-text">Home</span>.
                <?php else: ?>
                    Your Story <span class="rose-text">Lives Here</span>
                <?php endif; ?>
            </h1>
            <p class="hero-sub">
                <?php if ($isLoggedIn): ?>
                    You are home. Dive deeper into faith, healing, and community.
                <?php else: ?>
                    No judgment. No criticism. Just kindness, love, and a God who holds your pain with you. Here, you can show up exactly as you are — raw, broken, honest — and be met with grace.
                <?php endif; ?>
            </p>
            <div class="hero-buttons">
                <?php if ($isLoggedIn): ?>
                    <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-primary">Browse Books</a>
                    <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Read Poetry</a>
                    <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-secondary">Book a Session</a>
                <?php else: ?>
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Join Free — No Credit Card</a>
                    <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline">Sign In</a>
                    <a href="#about" class="btn btn-secondary">See What's Here for You</a>
                <?php endif; ?>
            </div>
            <div class="hero-search">
                <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Search books, poems, reflections..." required>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>
        </div>
        <div class="hero-image">
            <div class="hero-image-container">
                <img src="<?php echo get_image_url('/assets/images/hero-logo.png'); ?>" alt="AngelWrites - Your Story Lives Here" loading="lazy">
            </div>
            <p class="hero-quote">"You don't have to be fixed before you walk in. Just come as you are."</p>
        </div>
    </div>
</section>

<!-- ABOUT -->
<section class="about-section section-padding" id="about">
    <div class="container">
        <div class="about-text">
            <h2>Welcome to <span class="rose-text">AngelWrites</span></h2>
            <div class="about-lead-wrapper">
                <p class="about-lead">You're here because something inside you is crying out for hope. You've been carrying pain, confusion, or loneliness — and you're looking for a place where you can just be real. You've found it.</p>
            </div>
            <p class="about-body">AngelWrites is not about one person. It's about <strong>you</strong> and every human like you who needs to know that God hasn't given up on you. This is a <strong>community</strong> where you can heal, grow, and discover that your story matters.</p>
            <p class="about-body-intro">Here, you will find:</p>
            <div class="about-features-grid">
                <div class="about-feature"><i class="fas fa-book-reader"></i><h4>Books &amp; Poems</h4><p>Read words that speak to your soul.</p></div>
                <div class="about-feature"><i class="fas fa-pen-fancy"></i><h4>Reflections &amp; Blog</h4><p>Daily thoughts, honest stories, and insights.</p></div>
                <div class="about-feature"><i class="fas fa-hands-praying"></i><h4>Prayer Support</h4><p>You don't have to pray alone.</p></div>
                <div class="about-feature"><i class="fas fa-comments"></i><h4>1-on-1 Chats</h4><p>Book a free, confidential session with Angella.</p></div>
                <div class="about-feature"><i class="fas fa-users"></i><h4>Reading Groups</h4><p>Join or create a circle where you can grow together.</p></div>
                <div class="about-feature"><i class="fas fa-bible"></i><h4>Bible Reader</h4><p>All common translations with highlights and notes.</p></div>
            </div>
            <p>This community was built by <strong>Angella Bottoman</strong> — a Christian writer who believes every broken vessel holds a beautiful story.</p>
            <div class="about-cta">
                <?php if (!$isLoggedIn): ?>
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Join the Community — It's Free</a>
                    <p class="about-small">Already a member? <a href="<?php echo SITE_URL; ?>/login.php">Sign in</a></p>
                <?php else: ?>
                    <a href="<?php echo SITE_URL; ?>/dashboard.php" class="btn btn-primary">Go to My Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- STATS -->
<section class="stats-section section-padding" style="background-color: var(--vanilla);">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $total_users; ?>">0</div>
                <div class="stat-label">Members</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $free_books; ?>">0</div>
                <div class="stat-label">Free Books Downloaded</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $total_prayers; ?>">0</div>
                <div class="stat-label">Prayers Offered</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo count($testimonials); ?>">0</div>
                <div class="stat-label">Testimonies Shared</div>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIALS -->
<section class="testimonials-section section-padding">
    <div class="container">
        <div class="section-header">
            <h2>Real Stories. <span class="rose-text">Real Hope.</span></h2>
            <p>Hear from members whose lives have been touched by AngelWrites.</p>
        </div>
        <?php if ($isLoggedIn): ?>
        <div class="testimonial-prompt">
            <p><i class="fas fa-heart" style="color: var(--rose);"></i> Share your AngelWrites story – your testimony could be the hope someone needs today.</p>
            <button class="btn btn-primary btn-sm" id="testimonialPromptBtn">Share Your Story</button>
        </div>
        <?php endif; ?>
        <div class="testimonial-carousel" id="testimonialCarousel">
            <?php if (count($testimonials) > 0): ?>
                <?php foreach ($testimonials as $index => $testimonial): ?>
                    <?php 
                    $colors = ['#DBA1A2', '#F7B7A3', '#A8D5BA', '#F3D8C7', '#C4A5C9', '#E8C9A0', '#A3C6D4', '#F0D4D4'];
                    $color = $colors[$index % count($colors)];
                    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $stmt->execute([$testimonial['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $name = $user ? $user['name'] : 'Anonymous';
                    ?>
                    <div class="testimonial-card" style="--card-color: <?php echo $color; ?>;">
                        <div class="card-inner">
                            <div class="card-front">
                                <div class="testimonial-avatar"><i class="fas fa-user-circle"></i></div>
                                <p class="testimonial-quote">"<?php echo htmlspecialchars($testimonial['testimony']); ?>"</p>
                                <span class="testimonial-author">– <?php echo htmlspecialchars($name); ?></span>
                            </div>
                            <div class="card-back">
                                <i class="fas fa-pray"></i>
                                <p class="testimonial-prayer">Praying for you, <?php echo htmlspecialchars($name); ?>. May God's peace fill your heart.</p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-content">No stories yet. Be the first to share yours.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- TESTIMONIAL MODAL -->
<div id="testimonialModal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>Share Your AngelWrites Story</h3>
        <p>Your testimony could be the hope someone needs today.</p>
        <?php if (isset($testimonial_error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($testimonial_error); ?></div><?php endif; ?>
        <?php if (isset($testimonial_success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($testimonial_success); ?></div><?php endif; ?>
        <form method="POST" action="<?php echo SITE_URL; ?>/index.php#testimonials">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="submit_testimonial" value="1">
            <div class="form-group">
                <label for="testimony">Your Story</label>
                <textarea id="testimony" name="testimony" rows="4" placeholder="Share how AngelWrites has impacted your life..." required></textarea>
                <small>Minimum 20 characters</small>
            </div>
            <div class="checkbox-group">
                <input type="checkbox" id="is_public" name="is_public" checked>
                <label for="is_public">Yes, I want my story featured publicly</label>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">Submit</button>
                <button type="button" class="btn btn-secondary" id="closeTestimonialModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== CONTENT GATING ===== -->
<?php if (!$isLoggedIn): ?>
    <div class="content-gate">
        <div class="container">
            <div class="gate-message">
                <i class="fas fa-lock gate-icon"></i>
                <h2>Explore the Full Community</h2>
                <p>Books, poems, reading groups, and more – all waiting for you.</p>
                <div class="gate-buttons">
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Create Free Account</a>
                    <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline">Already a member? Log In</a>
                </div>
            </div>
        </div>
    </div>
    <div class="sticky-cta" id="stickyCta">
        <div class="container">
            <p><strong>Join AngelWrites Free</strong> – No credit card required. Start your healing journey.</p>
            <div class="sticky-cta-buttons">
                <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary btn-sm">Get Started</a>
                <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline btn-sm">Sign In</a>
            </div>
            <button class="sticky-cta-close" onclick="document.getElementById('stickyCta').style.display='none'">×</button>
        </div>
    </div>
<?php else: ?>

    <!-- RECOMMENDED BOOKS -->
    <?php if (!empty($recommended_books)): ?>
    <section class="recommended-section section-padding" style="background-color: var(--vanilla);">
        <div class="container">
            <div class="section-header">
                <h2>Recommended for <span class="rose-text">You</span></h2>
                <p>Based on your reading journey – books we think you'll love.</p>
            </div>
            <div class="books-grid">
                <?php foreach ($recommended_books as $book): ?>
                <div class="book-card">
                    <div class="book-cover-wrapper">
                        <?php if ($book['cover_path']): ?>
                            <img src="<?php echo get_image_url($book['cover_path']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                        <?php if ($book['is_free']): ?><span class="badge free">Free</span><?php endif; ?>
                        <?php if ($book['is_sale']): ?><span class="badge sale">Sale</span><?php endif; ?>
                    </div>
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <p class="book-author">by Angella Bottoman</p>
                        <div class="book-description-wrapper">
                            <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                            </div>
                            <?php if (strlen($book['description'] ?? '') > 100): ?>
                                <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                            <?php endif; ?>
                        </div>
                        <div class="book-bottom">
                            <div class="book-price">
                                <?php if ($book['is_free']): ?>
                                    <span class="free-text">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php else: ?>
                                    <span>MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-book-open"></i> Read
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- FEATURED BOOKS -->
    <?php if (!empty($featured_books)): ?>
    <section class="featured-books section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Featured <span class="rose-text">Books</span></h2>
                <p>Explore Angella's latest writings and download free or purchase.</p>
            </div>
            <div class="books-grid">
                <?php foreach ($featured_books as $book): ?>
                <div class="book-card">
                    <div class="book-cover-wrapper">
                        <?php if ($book['cover_path']): ?>
                            <img src="<?php echo get_image_url($book['cover_path']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                        <?php if ($book['is_free']): ?><span class="badge free">Free</span><?php endif; ?>
                        <?php if ($book['is_sale']): ?><span class="badge sale">Sale</span><?php endif; ?>
                    </div>
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <p class="book-author">by Angella Bottoman</p>
                        <div class="book-description-wrapper">
                            <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                            </div>
                            <?php if (strlen($book['description'] ?? '') > 100): ?>
                                <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                            <?php endif; ?>
                        </div>
                        <div class="book-bottom">
                            <div class="book-price">
                                <?php if ($book['is_free']): ?>
                                    <span class="free-text">Free</span>
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php else: ?>
                                    <span>MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-book-open"></i> Read
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">View All Books →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- LATEST POEMS -->
    <?php if (!empty($latest_poems)): ?>
    <section class="latest-poems section-padding" style="background-color: var(--vanilla);">
        <div class="container">
            <div class="section-header">
                <h2>Latest <span class="rose-text">Poems</span></h2>
                <p>Words that speak to the soul.</p>
            </div>
            <div class="poem-grid">
                <?php foreach ($latest_poems as $poem): ?>
                    <?php 
                    $intro_parts = explode("\n\n", $poem['intro'] ?? '');
                    $verse = $intro_parts[0] ?? '';
                    $purpose = $intro_parts[1] ?? '';
                    ?>
                    <div class="poem-card">
                        <?php if ($poem['image_path']): ?>
                            <div class="poem-thumbnail">
                                <img src="<?php echo get_image_url($poem['image_path']); ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="poem-content">
                            <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                            <?php if ($verse): ?>
                                <div class="poem-intro-preview">
                                    <span class="intro-label">✧ Verse</span>
                                    <p><?php echo htmlspecialchars(substr($verse, 0, 150)); ?><?php if (strlen($verse) > 150) echo '...'; ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($purpose): ?>
                                <p class="poem-excerpt"><?php echo htmlspecialchars(substr($purpose, 0, 120)); ?><?php if (strlen($purpose) > 120) echo '...'; ?></p>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="read-more">Read full poem →</a>
                        </div>
                        <?php if ($poem['audio_path']): ?>
                            <div class="poem-audio">
                                <audio controls>
                                    <source src="<?php echo SITE_URL . '/' . $poem['audio_path']; ?>" type="audio/mpeg">
                                </audio>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Explore All Poems →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA SECTION -->
    <section class="cta-section section-padding" style="background: linear-gradient(135deg, #DBA1A2 0%, #EFD8D6 100%);">
        <div class="container">
            <div class="cta-content">
                <div class="cta-text">
                    <h2>Need Guidance or a Listening Ear?</h2>
                    <p>Book a 1-on-1 live session with Angella. She is passionate about helping women discover their purpose and find healing through faith.</p>
                    <div class="cta-buttons">
                        <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-white">Book a Session</a>
                        <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-white-outline">Join Community Q&A</a>
                    </div>
                </div>
                <div class="cta-image"><i class="fas fa-hands-praying"></i></div>
            </div>
        </div>
    </section>

    <!-- BLOG POSTS -->
    <?php if (!empty($latest_posts)): ?>
    <section class="latest-blog section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Christian <span class="rose-text">Reflections</span></h2>
                <p>Faith, hope, and encouragement for everyday life.</p>
            </div>
            <div class="blog-grid">
                <?php foreach ($latest_posts as $post): ?>
                    <div class="blog-card">
                        <?php if ($post['featured_image']): ?>
                            <div class="blog-thumbnail">
                                <img src="<?php echo get_image_url($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="blog-content">
                            <div class="blog-meta">
                                <span class="blog-category"><?php echo htmlspecialchars($post['category']); ?></span>
                                <span class="blog-date"><?php echo date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])); ?></span>
                            </div>
                            <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                            <?php if ($post['excerpt']): ?>
                                <p class="blog-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                            <?php else: ?>
                                <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['content'], 0, 120)); ?>...</p>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="read-more">Read full reflection →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">Read All Reflections →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

<?php endif; ?>

<!-- ===== CYCLIC COMMENT CAROUSEL ===== -->
<section class="comment-carousel-section">
    <div class="container">
        <div class="section-header">
            <h2>What the <span class="rose-text">Community</span> Is Saying</h2>
            <p>Real voices from real readers – <em><?php echo htmlspecialchars($carousel_poem ? $carousel_poem['title'] : 'our latest poem'); ?></em></p>
        </div>
        <?php if ($carousel_poem && count($carousel_comments) > 0): ?>
            <div class="carousel-container">
                <div class="carousel-wrapper" id="carouselWrapper">
                    <?php foreach ($carousel_comments as $index => $comment): ?>
                        <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                            <div class="comment-card">
                                <div class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['author_name']); ?>
                                </div>
                                <?php if ($comment['rating'] > 0): ?>
                                    <div class="comment-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $comment['rating'] ? 'filled' : 'empty'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="comment-text">
                                    <?php echo nl2br(htmlspecialchars(substr($comment['comment'], 0, 200))); ?>
                                    <?php if (strlen($comment['comment']) > 200): ?>...<?php endif; ?>
                                </div>
                                <div class="comment-date">
                                    <?php echo date('M j, Y', strtotime($comment['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="carousel-controls">
                <button id="carouselPrev"><i class="fas fa-chevron-left"></i></button>
                <button id="carouselNext"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="carousel-indicators" id="carouselIndicators">
                <?php foreach ($carousel_comments as $index => $comment): ?>
                    <span class="dot <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="carousel-pause-indicator">Hover to pause</div>
            <div style="text-align:center; margin-top:12px;">
                <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $carousel_poem['id']; ?>" class="btn btn-outline btn-sm">Read this poem →</a>
            </div>
        <?php else: ?>
            <div class="empty-state" style="text-align:center; padding:40px;">
                <i class="fas fa-comments" style="font-size:2.5rem; color:var(--rose);"></i>
                <p>No comments yet. Be the first to share your thoughts!</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- NEWSLETTER -->
<section class="newsletter-section section-padding" style="background-color: var(--fantasy);">
    <div class="container">
        <div class="newsletter-content">
            <h2>Stay <span class="rose-text">Inspired</span></h2>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources directly to your inbox.</p>
            <?php if (isset($newsletter_error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($newsletter_error); ?></div><?php endif; ?>
            <?php if (isset($newsletter_success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($newsletter_success); ?></div><?php endif; ?>
            <form action="<?php echo SITE_URL; ?>/index.php" method="POST" class="newsletter-form">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="email" name="newsletter_email" placeholder="Your email address" required>
                <input type="text" name="newsletter_name" placeholder="Your name (optional)">
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
            <small>No spam. Unsubscribe anytime.</small>
        </div>
    </div>
</section>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Description (from books)
    document.querySelectorAll('.toggle-desc-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const desc = document.getElementById('desc-' + id);
            if (desc.classList.contains('expanded')) {
                desc.classList.remove('expanded');
                this.textContent = 'Read More';
            } else {
                desc.classList.add('expanded');
                this.textContent = 'Show Less';
            }
        });
    });

    // Stats Counter
    const statNumbers = document.querySelectorAll('.stat-number');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const target = parseInt(entry.target.dataset.target);
                animateNumber(entry.target, target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    statNumbers.forEach(stat => observer.observe(stat));

    function animateNumber(element, target) {
        let current = 0;
        const increment = Math.ceil(target / 50);
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                element.textContent = target;
                clearInterval(timer);
            } else {
                element.textContent = current;
            }
        }, 30);
    }

    // Testimonial Modal
    const modal = document.getElementById('testimonialModal');
    const openBtn = document.getElementById('testimonialPromptBtn');
    const closeBtn = document.getElementById('closeTestimonialModal');
    if (openBtn && modal) {
        openBtn.addEventListener('click', function() { modal.style.display = 'flex'; });
    }
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
        window.addEventListener('click', function(e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    // Sticky CTA Close
    const stickyCta = document.getElementById('stickyCta');
    if (stickyCta) {
        const closeBtn = stickyCta.querySelector('.sticky-cta-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() { stickyCta.style.display = 'none'; });
        }
    }

    // ===== CYCLIC COMMENT CAROUSEL =====
    const wrapper = document.getElementById('carouselWrapper');
    const slides = wrapper ? wrapper.querySelectorAll('.carousel-slide') : [];
    const prevBtn = document.getElementById('carouselPrev');
    const nextBtn = document.getElementById('carouselNext');
    const dots = document.querySelectorAll('.carousel-indicators .dot');
    let currentIndex = 0;
    let autoPlayTimer = null;
    const interval = 5000; // 5 seconds

    if (slides.length > 0) {
        function goTo(index) {
            if (index < 0) index = slides.length - 1;
            if (index >= slides.length) index = 0;
            currentIndex = index;
            slides.forEach((slide, i) => {
                slide.classList.remove('active', 'prev', 'next');
                if (i === index) {
                    slide.classList.add('active');
                } else if (i === (index - 1 + slides.length) % slides.length) {
                    slide.classList.add('prev');
                } else if (i === (index + 1) % slides.length) {
                    slide.classList.add('next');
                } else {
                    // hide others
                    slide.style.opacity = 0;
                    setTimeout(() => { slide.style.opacity = ''; }, 800);
                }
            });
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });
        }

        function nextSlide() { goTo(currentIndex + 1); }
        function prevSlide() { goTo(currentIndex - 1); }

        function startAutoPlay() {
            if (autoPlayTimer) clearInterval(autoPlayTimer);
            autoPlayTimer = setInterval(nextSlide, interval);
        }

        function stopAutoPlay() {
            if (autoPlayTimer) {
                clearInterval(autoPlayTimer);
                autoPlayTimer = null;
            }
        }

        // Initialize
        goTo(0);
        startAutoPlay();

        // Event listeners
        if (prevBtn) prevBtn.addEventListener('click', () => { stopAutoPlay(); prevSlide(); startAutoPlay(); });
        if (nextBtn) nextBtn.addEventListener('click', () => { stopAutoPlay(); nextSlide(); startAutoPlay(); });
        dots.forEach(dot => {
            dot.addEventListener('click', function() {
                const idx = parseInt(this.dataset.index);
                stopAutoPlay();
                goTo(idx);
                startAutoPlay();
            });
        });

        // Pause on hover
        const container = document.querySelector('.carousel-container');
        if (container) {
            container.addEventListener('mouseenter', stopAutoPlay);
            container.addEventListener('mouseleave', startAutoPlay);
        }
    }
});
</script>

<?php
// ============================================================
// 5. SAVE CACHE FOR ANONYMOUS USERS
// ============================================================
if ($doCache) {
    $content = ob_get_contents();
    file_put_contents($cacheFile, $content);
    ob_end_flush();
}
?>

<?php require_once 'includes/footer.php'; ?>