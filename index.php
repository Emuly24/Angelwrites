<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== FETCH CONTENT =====
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
// Users count
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

// Books downloaded count (placeholder - you'd need a downloads table)
$stmt = $db->prepare("SELECT COUNT(*) FROM books WHERE is_free = 1");
$stmt->execute();
$free_books = $stmt->fetchColumn();

// Prayers count (placeholder - you'd need a prayer_requests table)
$stmt = $db->prepare("SELECT COUNT(*) FROM prayer_requests");
$stmt->execute();
$total_prayers = $stmt->fetchColumn();

// ===== PERSONALIZED RECOMMENDATIONS (if logged in) =====
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

// ===== HERO GREETING =====
$greeting = $isLoggedIn ? "Welcome back, " . htmlspecialchars($_SESSION['name'] ?? 'Friend') . "!" : "Welcome Home.";

// ===== NEWSLETTER SUBSCRIPTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
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

// ===== HANDLE TESTIMONIAL SUBMISSION =====
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_testimonial'])) {
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
        
        // Notify admin
        $admin_email = 'angelwrites@zohomail.com';
        $subject = 'New Testimonial Submission';
        $body = "A new testimonial has been submitted.\n\nUser: " . $_SESSION['name'] . "\nPublic: " . ($is_public ? 'Yes' : 'No') . "\n\n$testimony";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
    }
}

$pageTitle = 'AngelWrites — Christian Writing & Community';
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== NEW HERO SECTION – With Warmth, Safety & "Your Story Lives Here" ===== -->
<section class="hero" style="background: linear-gradient(135deg, #DBA1A2 0%, #EFD8D6 50%, #F7F3ED 100%);">
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
            <!-- Search Bar (visible to all) -->
            <div class="hero-search">
                <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Search books, poems, reflections..." required>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>
        </div>
        <div class="hero-image">
            <div class="hero-placeholder" style="background: var(--rose-light); border-color: var(--rose);">
                <i class="fas fa-heart" style="color: var(--rose);"></i>
                <span style="color: var(--text); font-weight: 600;">Your Story Lives Here</span>
            </div>
            <div style="text-align: center; margin-top: 12px; max-width: 250px; margin-left: auto; margin-right: auto;">
                <p style="font-size: 0.85rem; color: var(--text-light); line-height: 1.5; font-style: italic;">
                    "You don't have to be fixed before you walk in. Just come as you are."
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ===== ABOUT SECTION – User-Centered, Warm, Welcoming ===== -->
<section class="about-section section-padding" id="about">
    <div class="container">
        <div class="about-grid">
            <div class="about-text">
                <h2>Welcome to <span class="rose-text">AngelWrites</span></h2>
                <p class="about-lead">You're here because something inside you is crying out for hope. You've been carrying pain, confusion, or loneliness — and you're looking for a place where you can just be real. You've found it.</p>
                
                <p>AngelWrites is not about one person. It's about <strong>you</strong> and every human like you who needs to know that God hasn't given up on you. This is a <strong>community</strong> where you can heal, grow, and discover that your story matters.</p>
                
                <p>Here, you will find:</p>
                
                <div class="about-features-grid">
                    <div class="about-feature">
                        <i class="fas fa-book-reader"></i>
                        <h4>Books &amp; Poems</h4>
                        <p>Read words that speak to your soul — written by someone who has walked through the fire and come out holding God's hand.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-pen-fancy"></i>
                        <h4>Reflections &amp; Blog</h4>
                        <p>Daily thoughts, honest stories, and insights to pull you out of the pit and point you to hope.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-hands-praying"></i>
                        <h4>Prayer Support</h4>
                        <p>You don't have to pray alone. Ask the community — and Angella — to stand with you in prayer.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-comments"></i>
                        <h4>1-on-1 Chats</h4>
                        <p>Book a free, confidential session with Angella. Your story is safe here.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-users"></i>
                        <h4>Reading Groups</h4>
                        <p>Join or create a circle where you can discuss, question, and grow — together.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-bible"></i>
                        <h4>Bible Reader</h4>
                        <p>All common translations, with highlights, notes, parallel mode, and your own reading progress — built just for you.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-comment-dots"></i>
                        <h4>Community Q&amp;A</h4>
                        <p>Ask questions. Answer questions. See that you are not alone in what you're going through.</p>
                    </div>
                    <div class="about-feature">
                        <i class="fas fa-download"></i>
                        <h4>Free Downloads</h4>
                        <p>Free books and resources to help you on your journey. If you can't pay, you still belong here.</p>
                    </div>
                </div>
                
                <p>This community was built by <strong>Angella Bottoman</strong> — a Christian writer, speaker, and mentor who believes that every broken vessel holds a beautiful story. But she doesn't see herself as the star. She sees herself as the one who opens the door, holds the light, and walks alongside you. The real story? It's yours. And this is the place where you can start writing it.</p>
                
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
    </div>
</section>

<!-- ===== LIVE STATS COUNTER ===== -->
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

<!-- ===== TESTIMONIALS CAROUSEL ===== -->
<section class="testimonials-section section-padding">
    <div class="container">
        <div class="section-header">
            <h2>Real Stories. <span class="rose-text">Real Hope.</span></h2>
            <p>Hear from members whose lives have been touched by AngelWrites.</p>
        </div>

        <?php if ($isLoggedIn): ?>
        <!-- ===== SHARED TESTIMONIAL PROMPT ===== -->
        <div class="testimonial-prompt">
            <p><i class="fas fa-heart" style="color: var(--rose);"></i> Share your AngelWrites story – your testimony could be the hope someone needs today.</p>
            <button class="btn btn-primary btn-sm" id="testimonialPromptBtn">Share Your Story</button>
        </div>
        <?php endif; ?>

        <div class="testimonial-carousel-container">
            <div class="testimonial-carousel" id="testimonialCarousel">
                <?php if (count($testimonials) > 0): ?>
                    <?php foreach ($testimonials as $index => $testimonial): ?>
                        <?php 
                        // Generate a random color for each card
                        $colors = ['#DBA1A2', '#F7B7A3', '#A8D5BA', '#F3D8C7', '#C4A5C9', '#E8C9A0', '#A3C6D4', '#F0D4D4'];
                        $color = $colors[$index % count($colors)];
                        // Get user name
                        $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                        $stmt->execute([$testimonial['user_id']]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        $name = $user ? $user['name'] : 'Anonymous';
                        ?>
                        <div class="testimonial-card" style="--card-color: <?php echo $color; ?>;">
                            <div class="card-inner">
                                <div class="card-front">
                                    <div class="testimonial-avatar">
                                        <i class="fas fa-user-circle"></i>
                                    </div>
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
                    <div class="placeholder-testimonial">
                        <p>No testimonials yet. Be the first to share your story.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ===== TESTIMONIAL SUBMISSION MODAL ===== -->
<div id="testimonialModal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>Share Your AngelWrites Story</h3>
        <p>Your testimony could be the hope someone needs today.</p>
        <?php if (isset($testimonial_error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($testimonial_error); ?></div>
        <?php endif; ?>
        <?php if (isset($testimonial_success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($testimonial_success); ?></div>
        <?php endif; ?>
        <form method="POST" action="<?php echo SITE_URL; ?>/index.php#testimonials">
            <input type="hidden" name="submit_testimonial" value="1">
            <div class="form-group">
                <label for="testimony">Your Story</label>
                <textarea id="testimony" name="testimony" rows="4" placeholder="Share how AngelWrites has impacted your life..." required></textarea>
                <small>Minimum 20 characters</small>
            </div>
            <div class="form-group checkbox-group">
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
    <!-- Guest view: lock overlay + signup prompt -->
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

    <!-- ===== STICKY CTA FOR GUESTS ===== -->
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
    <!-- ===== LOGGED-IN CONTENT ===== -->

    <!-- ===== PERSONALIZED RECOMMENDATIONS ===== -->
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
                            <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                        <?php if ($book['is_free']): ?>
                            <span class="badge free">Free</span>
                        <?php elseif ($book['is_sale']): ?>
                            <span class="badge sale">Sale</span>
                        <?php endif; ?>
                    </div>
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <p class="book-author">by Angella Bottoman</p>
                        <div class="book-description-wrapper">
                            <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                            </div>
                            <?php if (strlen($book['description'] ?? '') > 400): ?>
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
                            <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary">
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

    <!-- ===== FEATURED BOOKS ===== -->
    <section class="featured-books section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Featured <span class="rose-text">Books</span></h2>
                <p>Explore Angella's latest writings and download free or purchase.</p>
            </div>

            <div class="books-grid">
                <?php if (count($featured_books) > 0): ?>
                    <?php foreach ($featured_books as $book): ?>
                    <div class="book-card">
                        <div class="book-cover-wrapper">
                            <?php if ($book['cover_path']): ?>
                                <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                            <?php endif; ?>
                            <?php if ($book['is_free']): ?>
                                <span class="badge free">Free</span>
                            <?php elseif ($book['is_sale']): ?>
                                <span class="badge sale">Sale</span>
                            <?php endif; ?>
                        </div>
                        <div class="book-details">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-author">by Angella Bottoman</p>
                            <div class="book-description-wrapper">
                                <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                    <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                                </div>
                                <?php if (strlen($book['description'] ?? '') > 400): ?>
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
                                <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-book-open"></i> Read
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-content">No books yet. Check back soon.</p>
                <?php endif; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">View All Books →</a>
            </div>
        </div>
    </section>

    <!-- ===== LATEST POEMS ===== -->
    <section class="latest-poems section-padding" style="background-color: var(--vanilla);">
        <div class="container">
            <div class="section-header">
                <h2>Latest <span class="rose-text">Poems</span></h2>
                <p>Words that speak to the soul.</p>
            </div>
            <div class="poem-grid">
                <?php if (count($latest_poems) > 0): ?>
                    <?php foreach ($latest_poems as $poem): ?>
                        <?php 
                        $intro_parts = explode("\n\n", $poem['intro'] ?? '');
                        $verse = $intro_parts[0] ?? '';
                        $purpose = $intro_parts[1] ?? '';
                        ?>
                        <div class="poem-card">
                            <?php if ($poem['image_path']): ?>
                                <div class="poem-thumbnail">
                                    <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" loading="lazy">
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
                <?php else: ?>
                    <p class="no-content">No poems yet. Stay tuned.</p>
                <?php endif; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Explore All Poems →</a>
            </div>
        </div>
    </section>

    <!-- ===== COMMUNITY & SESSION CALL TO ACTION ===== -->
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
                <div class="cta-image">
                    <i class="fas fa-hands-praying"></i>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== LATEST FROM THE BLOG ===== -->
    <section class="latest-blog section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Christian <span class="rose-text">Reflections</span></h2>
                <p>Faith, hope, and encouragement for everyday life.</p>
            </div>
            <div class="blog-grid">
                <?php if (count($latest_posts) > 0): ?>
                    <?php foreach ($latest_posts as $post): ?>
                        <div class="blog-card">
                            <?php if ($post['featured_image']): ?>
                                <div class="blog-thumbnail">
                                    <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy">
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
                <?php else: ?>
                    <div class="placeholder-card">
                        <div class="placeholder-icon"><i class="fas fa-blog"></i></div>
                        <h3>Coming Soon</h3>
                        <p>Daily reflections and devotions from Angella will be available here soon.</p>
                        <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">Visit Blog</a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">Read All Reflections →</a>
            </div>
        </div>
    </section>

<?php endif; // End logged-in content ?>

<!-- ===== NEWSLETTER SIGNUP (visible to everyone) ===== -->
<section class="newsletter-section section-padding" style="background-color: var(--fantasy);">
    <div class="container">
        <div class="newsletter-content">
            <h2>Stay <span class="rose-text">Inspired</span></h2>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources directly to your inbox.</p>
            
            <?php if (isset($newsletter_error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($newsletter_error); ?></div>
            <?php endif; ?>
            <?php if (isset($newsletter_success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($newsletter_success); ?></div>
            <?php endif; ?>
            
            <form action="<?php echo SITE_URL; ?>/index.php" method="POST" class="newsletter-form">
                <input type="email" name="newsletter_email" placeholder="Your email address" required>
                <input type="text" name="newsletter_name" placeholder="Your name (optional)">
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
            <small>No spam. Unsubscribe anytime.</small>
        </div>
    </div>
</section>

<!-- ===== STYLES ===== -->
<style>
/* ===== HERO SECTION ===== */
.hero { padding: 60px 0; }
.hero-content { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: center; }
.hero-badge { display: inline-block; background: var(--rose); color: white; padding: 4px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 12px; }
.hero h1 { font-size: 3rem; margin: 0 0 12px; line-height: 1.2; }
.hero h1 .rose-text { color: var(--rose); }
.hero-sub { font-size: 1.2rem; color: var(--text-light); margin-bottom: 24px; max-width: 480px; }
.hero-buttons { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.hero-search { max-width: 400px; }
.hero-search .search-form { display: flex; gap: 8px; }
.hero-search .search-form input { flex: 1; padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.hero-search .search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.hero-image { display: flex; justify-content: center; align-items: center; flex-direction: column; }
.hero-placeholder { width: 280px; height: 280px; border-radius: 50%; background: var(--rose-light); display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); border: 4px solid var(--rose); box-shadow: var(--shadow-hover); }
.hero-placeholder span { font-size: 1rem; color: var(--text); margin-top: 8px; font-weight: 500; }

/* ===== ABOUT SECTION ===== */
.about-section { padding: 60px 0; }
.about-grid { display: grid; grid-template-columns: 1fr 0.8fr; gap: 40px; align-items: center; }
.about-text h2 { font-size: 2.4rem; margin-bottom: 8px; }
.about-lead { font-size: 1.2rem; color: var(--text-light); margin-bottom: 16px; font-weight: 500; font-style: italic; }
.about-features-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 20px 0; }
.about-feature { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; transition: all 0.2s; }
.about-feature:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
.about-feature i { font-size: 1.2rem; color: var(--rose); margin-bottom: 4px; display: block; }
.about-feature h4 { font-size: 0.95rem; margin-bottom: 2px; }
.about-feature p { font-size: 0.85rem; color: var(--text-light); margin: 0; }
.about-cta { margin-top: 20px; }
.about-small { margin-top: 8px; font-size: 0.9rem; color: var(--text-light); }
.about-image { display: flex; justify-content: center; align-items: center; }
.about-placeholder { width: 300px; height: 300px; border-radius: 50%; background: var(--rose-light); display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 5rem; color: var(--rose); border: 4px solid var(--rose); box-shadow: var(--shadow-hover); }
.about-placeholder span { font-size: 1.2rem; color: var(--text); margin-top: 8px; font-weight: 600; }

/* ===== STATS SECTION ===== */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 24px; text-align: center; }
.stat-item { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); }
.stat-number { font-size: 2.4rem; font-weight: 700; color: var(--rose); }
.stat-label { font-size: 0.9rem; color: var(--text-light); margin-top: 4px; }

/* ===== TESTIMONIALS ===== */
.testimonials-section { padding: 60px 0; }
.testimonial-prompt { text-align: center; padding: 16px; background: var(--vanilla); border-radius: 12px; margin-bottom: 24px; border: 1px solid var(--border); }
.testimonial-prompt p { font-size: 1rem; margin-bottom: 8px; }
.testimonial-carousel-container { perspective: 1000px; overflow: hidden; padding: 20px 0; }
.testimonial-carousel { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; }
.testimonial-card { width: 280px; height: 200px; perspective: 800px; cursor: pointer; margin: 10px; }
.testimonial-card .card-inner { position: relative; width: 100%; height: 100%; transition: transform 0.8s; transform-style: preserve-3d; }
.testimonial-card:hover .card-inner { transform: rotateY(180deg); }
.testimonial-card .card-front, .testimonial-card .card-back { position: absolute; width: 100%; height: 100%; backface-visibility: hidden; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }
.testimonial-card .card-front { background: var(--card-bg); color: var(--text); }
.testimonial-card .card-back { background: var(--rose); color: white; transform: rotateY(180deg); }
.testimonial-avatar { font-size: 2.5rem; color: var(--rose); margin-bottom: 8px; }
.testimonial-quote { font-size: 0.95rem; line-height: 1.6; margin-bottom: 8px; }
.testimonial-author { font-weight: 600; font-size: 0.85rem; color: var(--text-light); }
.testimonial-prayer { font-size: 1rem; line-height: 1.5; font-style: italic; }
.placeholder-testimonial { text-align: center; padding: 40px; color: var(--text-light); }

/* ===== CONTENT GATE ===== */
.content-gate { padding: 60px 0; background: var(--vanilla); border-top: 2px solid var(--rose); border-bottom: 2px solid var(--rose); }
.gate-message { text-align: center; max-width: 600px; margin: 0 auto; }
.gate-icon { font-size: 3rem; color: var(--rose); margin-bottom: 12px; }
.gate-message h2 { font-size: 2rem; margin-bottom: 8px; }
.gate-message p { font-size: 1.1rem; color: var(--text-light); margin-bottom: 20px; }
.gate-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

/* ===== STICKY CTA ===== */
.sticky-cta { position: fixed; bottom: 0; left: 0; width: 100%; background: var(--rose); color: white; padding: 12px 0; z-index: 999; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); }
.sticky-cta .container { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.sticky-cta p { margin: 0; font-size: 1rem; }
.sticky-cta-buttons { display: flex; gap: 8px; }
.sticky-cta .btn-outline { border-color: white; color: white; }
.sticky-cta .btn-outline:hover { background: white; color: var(--rose); }
.sticky-cta-close { background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; padding: 0 4px; }

/* ===== MODAL ===== */
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center; }
.modal-content { background: var(--card-bg); border-radius: 16px; padding: 32px; max-width: 500px; width: 90%; box-shadow: var(--shadow-hover); }
.modal-content h3 { margin-top: 0; }
.modal-content .form-group { margin-bottom: 12px; }
.modal-content textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 80px; background: var(--input-bg); color: var(--text); font-size: 0.95rem; }
.modal-content textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.modal-content small { display: block; margin-top: 4px; color: var(--text-light); font-size: 0.8rem; }
.checkbox-group { display: flex; align-items: center; gap: 8px; margin: 8px 0; }
.checkbox-group input[type="checkbox"] { width: auto; accent-color: var(--rose); }
.modal-actions { display: flex; gap: 12px; margin-top: 12px; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .hero-content { grid-template-columns: 1fr; text-align: center; }
    .hero h1 { font-size: 2.2rem; }
    .hero-sub { margin-left: auto; margin-right: auto; }
    .hero-buttons { justify-content: center; }
    .hero-search { margin: 0 auto; }
    .hero-search .search-form { flex-direction: column; }
    .hero-placeholder { width: 180px; height: 180px; font-size: 3rem; }
    .about-grid { grid-template-columns: 1fr; text-align: center; }
    .about-features-grid { grid-template-columns: 1fr; }
    .about-image { order: -1; }
    .sticky-cta .container { flex-direction: column; text-align: center; }
    .testimonial-card { width: 240px; height: 180px; }
}
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== TOGGLE DESCRIPTION =====
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

    // ===== LIVE STATS COUNTER =====
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

    // ===== TESTIMONIAL MODAL =====
    const modal = document.getElementById('testimonialModal');
    const openBtn = document.getElementById('testimonialPromptBtn');
    const closeBtn = document.getElementById('closeTestimonialModal');

    if (openBtn && modal) {
        openBtn.addEventListener('click', function() {
            modal.style.display = 'flex';
        });
    }

    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });
        window.addEventListener('click', function(e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    // ===== STICKY CTA CLOSE =====
    const stickyCta = document.getElementById('stickyCta');
    if (stickyCta) {
        const closeBtn = stickyCta.querySelector('.sticky-cta-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                stickyCta.style.display = 'none';
            });
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>