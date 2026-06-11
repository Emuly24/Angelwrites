<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== HOMEPAGE CONTENT FETCH =====

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

// ===== HANDLE NEWSLETTER SUBSCRIPTION (via AJAX or direct POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    $email = trim($_POST['newsletter_email']);
    $name = isset($_POST['newsletter_name']) ? trim($_POST['newsletter_name']) : '';
    
    if (empty($email)) {
        $newsletter_error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $newsletter_error = 'Please enter a valid email address.';
    } else {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['is_active'] == 1) {
                $newsletter_error = 'This email is already subscribed.';
            } else {
                // Reactivate
                $stmt = $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$existing['id']]);
                $newsletter_success = 'Your subscription has been reactivated. Welcome back!';
                
                // ===== ZOHO SMTP NOTIFICATION TO ADMIN =====
                $admin_email = 'angelwrites@zohomail.com';
                $subject = 'Newsletter Subscription Reactivated';
                $body = "A user has reactivated their newsletter subscription.\n\nEmail: $email\nName: " . ($name ?: 'Not provided');
                sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
            }
        } else {
            // Generate unique unsubscribe token
            $token = bin2hex(random_bytes(32));

            // Insert new subscriber
            $stmt = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token) VALUES (?, ?, 1, ?)");
            if ($stmt->execute([$email, $name, $token])) {
                $newsletter_success = 'Thank you for subscribing! You will receive updates from Angella.';
                
                // ===== ZOHO SMTP NOTIFICATION TO ADMIN =====
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

$pageTitle = 'AngelWrites — Christian Writing & Community';
?>
<?php require_once 'includes/header.php'; ?>

<!-- HERO SECTION -->
<section class="hero" style="background: linear-gradient(135deg, #DBA1A2 0%, #EFD8D6 50%, #F7F3ED 100%);">
    <div class="container hero-content">
        <div class="hero-text">
            <span class="hero-badge">✧ Christian Writer &amp; Speaker</span>
            <h1>Beautiful Broken <span class="rose-text">Vessel</span></h1>
            <p class="hero-sub">Transforming pain into purpose through faith, writing, and community.</p>
            <div class="hero-buttons">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-primary">Browse Books</a>
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Read Poetry</a>
                <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-secondary">Book a Session</a>
            </div>
            <!-- ADDED: Search Bar -->
            <div class="hero-search">
                <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Search books, poems, reflections..." required>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>
        </div>
        <div class="hero-image">
            <div class="hero-placeholder">
                <i class="fas fa-book-open"></i>
                <span>Her Story Lives Here</span>
            </div>
        </div>
    </div>
</section>

<!-- FEATURED BOOKS -->
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
                            <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
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

<!-- LATEST POEMS -->
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
                                <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>">
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

<!-- COMMUNITY & SESSION CALL TO ACTION -->
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

<!-- LATEST FROM THE BLOG (Christian Reflections) -->
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
                                <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
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

<!-- NEWSLETTER SIGNUP -->
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
.hero-image { display: flex; justify-content: center; align-items: center; }
.hero-placeholder { width: 280px; height: 280px; border-radius: 50%; background: var(--rose-light); display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); border: 4px solid var(--rose); box-shadow: var(--shadow-hover); }
.hero-placeholder span { font-size: 1rem; color: var(--text); margin-top: 8px; font-weight: 500; }

/* ===== FEATURED BOOKS ===== */
.featured-books .books-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 32px; max-width: 1000px; margin: 0 auto; }
.featured-books .book-card { background: var(--card-bg); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid var(--border); transition: transform 0.3s; }
.featured-books .book-card:hover { transform: translateY(-6px); box-shadow: 0 12px 40px rgba(0,0,0,0.10); }
.featured-books .book-cover-wrapper { position: relative; height: 280px; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.featured-books .book-cover-wrapper img { width: auto; height: 100%; object-fit: cover; }
.featured-books .placeholder-cover { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); }
.featured-books .badge { position: absolute; top: 12px; right: 12px; padding: 4px 16px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; color: white; }
.featured-books .badge.free { background: #27ae60; }
.featured-books .badge.sale { background: #e74c3c; }
.featured-books .book-details { padding: 24px; }
.featured-books .book-details h3 { font-size: 1.4rem; margin-bottom: 4px; text-align: center; }
.featured-books .book-author { text-align: center; color: var(--text-light); font-size: 0.95rem; margin-bottom: 12px; }
.featured-books .book-description-wrapper { flex: 1; }
.featured-books .book-description { font-size: 0.95rem; line-height: 1.7; color: var(--text); text-align: justify; max-height: 120px; overflow: hidden; transition: max-height 0.5s ease; }
.featured-books .book-description.expanded { max-height: none; }
.featured-books .toggle-desc-btn { background: none; border: none; color: var(--rose); font-size: 0.85rem; font-weight: 600; cursor: pointer; padding: 0; margin-bottom: 8px; }
.featured-books .toggle-desc-btn:hover { text-decoration: underline; }
.featured-books .book-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.featured-books .book-price { font-weight: 700; font-size: 1.1rem; }
.featured-books .free-text { color: #27ae60; }
.featured-books .sale-text { color: #e74c3c; }
.featured-books .book-bottom .btn { padding: 8px 24px; border-radius: 30px; }

/* ===== POEM CARD ===== */
.poem-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); transition: all var(--transition); display: flex; flex-direction: column; }
.poem-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.poem-thumbnail { width: 100%; height: 180px; overflow: hidden; }
.poem-thumbnail img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.poem-card:hover .poem-thumbnail img { transform: scale(1.05); }
.poem-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
.poem-content h3 { font-size: 1.2rem; margin-bottom: 6px; }
.poem-intro-preview { background: var(--vanilla); padding: 8px 12px; border-radius: 6px; margin: 6px 0 10px; border-left: 3px solid var(--rose); }
.poem-intro-preview .intro-label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--rose); margin-bottom: 2px; }
.poem-excerpt { color: var(--text-light); font-size: 0.95rem; line-height: 1.6; margin-bottom: 12px; flex: 1; }
.poem-audio { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); }
.read-more { color: var(--rose); font-weight: 500; text-decoration: none; }
.read-more:hover { text-decoration: underline; }

/* ===== BLOG CARDS ===== */
.blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
.blog-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); transition: all var(--transition); display: flex; flex-direction: column; }
.blog-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.blog-thumbnail { width: 100%; height: 180px; overflow: hidden; background: var(--vanilla); }
.blog-thumbnail img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.blog-card:hover .blog-thumbnail img { transform: scale(1.05); }
.blog-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
.blog-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 0.85rem; color: var(--text-light); }
.blog-category { background: var(--vanilla); padding: 2px 10px; border-radius: 12px; font-weight: 500; color: var(--text); }
.blog-content h3 { font-size: 1.15rem; margin-bottom: 6px; }
.blog-excerpt { color: var(--text-light); font-size: 0.95rem; line-height: 1.6; margin-bottom: 12px; flex: 1; }

/* ===== CTA SECTION ===== */
.cta-section .cta-content { display: grid; grid-template-columns: 1fr 0.5fr; gap: 32px; align-items: center; }
.cta-text h2 { font-size: 2.2rem; margin-bottom: 8px; }
.cta-text p { font-size: 1.05rem; color: var(--text-light); margin-bottom: 16px; }
.cta-buttons { display: flex; gap: 12px; flex-wrap: wrap; }
.btn-white { background: white; color: var(--rose); border: 2px solid white; padding: 10px 24px; border-radius: 30px; font-weight: 600; transition: all 0.3s; }
.btn-white:hover { background: transparent; color: white; }
.btn-white-outline { background: transparent; color: white; border: 2px solid white; padding: 10px 24px; border-radius: 30px; font-weight: 600; transition: all 0.3s; }
.btn-white-outline:hover { background: white; color: var(--rose); }
.cta-image { display: flex; justify-content: center; align-items: center; font-size: 6rem; color: white; }

/* ===== NEWSLETTER ===== */
.newsletter-section .newsletter-content { text-align: center; }
.newsletter-section h2 { font-size: 2.2rem; margin-bottom: 8px; }
.newsletter-section p { font-size: 1.05rem; color: var(--text-light); margin-bottom: 16px; }
.newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin: 0 auto; max-width: 500px; }
.newsletter-form input { flex: 1; min-width: 200px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.newsletter-form .btn { padding: 12px 24px; border-radius: 30px; }
.newsletter-section small { display: block; margin-top: 8px; color: var(--text-light); font-size: 0.85rem; }

/* ===== SECTION COMMON ===== */
.section-padding { padding: 60px 0; }
.section-header { text-align: center; margin-bottom: 32px; }
.section-header h2 { font-size: 2.2rem; margin-bottom: 4px; }
.section-header h2 .rose-text { color: var(--rose); }
.section-header p { color: var(--text-light); font-size: 1.05rem; }
.section-footer { text-align: center; margin-top: 32px; }
.placeholder-card { background: var(--card-bg); border-radius: 12px; padding: 40px; text-align: center; border: 1px solid var(--border); grid-column: 1/-1; }
.placeholder-icon { font-size: 2.5rem; color: var(--rose); margin-bottom: 12px; }
.placeholder-card h3 { font-size: 1.2rem; margin-bottom: 4px; }
.placeholder-card p { color: var(--text-light); margin-bottom: 16px; }
.no-content { text-align: center; color: var(--text-light); padding: 20px; grid-column: 1/-1; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .hero-content { grid-template-columns: 1fr; text-align: center; }
    .hero h1 { font-size: 2.2rem; }
    .hero-sub { margin-left: auto; margin-right: auto; }
    .hero-buttons { justify-content: center; }
    .hero-search { margin: 0 auto; }
    .hero-search .search-form { flex-direction: column; }
    .hero-placeholder { width: 180px; height: 180px; font-size: 3rem; }
    .cta-section .cta-content { grid-template-columns: 1fr; text-align: center; }
    .cta-buttons { justify-content: center; }
    .newsletter-form { flex-direction: column; }
    .newsletter-form input { width: 100%; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle description for featured books
    const toggleBtns = document.querySelectorAll('.featured-books .toggle-desc-btn');
    toggleBtns.forEach(btn => {
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
});
</script>

<?php require_once 'includes/footer.php'; ?>