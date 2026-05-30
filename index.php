<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
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
                <a href="/books.php" class="btn btn-primary">Browse Books</a>
                <a href="/poetry.php" class="btn btn-outline">Read Poetry</a>
                <a href="/book_session.php" class="btn btn-secondary">Book a Session</a>
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
        <div class="book-grid">
            <?php
            $stmt = $db->prepare("SELECT * FROM books ORDER BY created_at DESC LIMIT 3");
            $stmt->execute();
            $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($books) > 0):
                foreach ($books as $book):
            ?>
            <div class="book-card">
                <div class="book-cover">
                    <?php if ($book['cover_path']): ?>
                        <img src="<?php echo $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                    <?php else: ?>
                        <div class="book-cover-placeholder"><i class="fas fa-book"></i></div>
                    <?php endif; ?>
                    <?php if ($book['is_free']): ?>
                        <span class="badge free">Free</span>
                    <?php elseif ($book['is_sale']): ?>
                        <span class="badge sale">Sale</span>
                    <?php endif; ?>
                </div>
                <div class="book-info">
                    <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                    <p class="book-author">by Angella Bottoman</p>
                    <p class="book-desc"><?php echo htmlspecialchars(substr($book['description'], 0, 80)); ?>...</p>
                    <div class="book-actions">
                        <a href="/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Read</a>
                        <?php if ($book['price'] > 0): ?>
                            <span class="book-price">$<?php echo number_format($book['price'], 2); ?></span>
                        <?php else: ?>
                            <span class="book-price free-text">Free</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <p class="no-content">No books yet. Check back soon.</p>
            <?php endif; ?>
        </div>
        <div class="section-footer">
            <a href="/books.php" class="btn btn-outline">View All Books →</a>
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
            <?php
            $stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 3");
            $stmt->execute();
            $poems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($poems) > 0):
                foreach ($poems as $poem):
                    // Split the introduction into verse (first para) and purpose (second para)
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
                    
                    <!-- Verse inside the box -->
                    <?php if ($verse): ?>
                    <div class="poem-intro-preview">
                        <span class="intro-label">✧ Verse</span>
                        <p><?php echo htmlspecialchars(substr($verse, 0, 150)); ?><?php if (strlen($verse) > 150) echo '...'; ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Purpose outside the box -->
                    <?php if ($purpose): ?>
                    <p class="poem-excerpt"><?php echo htmlspecialchars(substr($purpose, 0, 120)); ?><?php if (strlen($purpose) > 120) echo '...'; ?></p>
                    <?php endif; ?>

                    <a href="/poem_view.php?id=<?php echo $poem['id']; ?>" class="read-more">Read full poem →</a>
                </div>
                <?php if ($poem['audio_path']): ?>
                <div class="poem-audio">
                    <audio controls>
                        <source src="<?php echo $poem['audio_path']; ?>" type="audio/mpeg">
                    </audio>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; else: ?>
            <p class="no-content">No poems yet. Stay tuned.</p>
            <?php endif; ?>
        </div>
        <div class="section-footer">
            <a href="/poetry.php" class="btn btn-outline">Explore All Poems →</a>
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
                    <a href="/book_session.php" class="btn btn-white">Book a Session</a>
                    <a href="/community.php" class="btn btn-white-outline">Join Community Q&A</a>
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
            <?php
            // Fetch latest 3 published blog posts
            $stmt = $db->prepare("
                SELECT * FROM blog_posts 
                WHERE status = 'published' 
                ORDER BY published_at DESC 
                LIMIT 3
            ");
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (count($posts) > 0): ?>
                <?php foreach ($posts as $post): ?>
                <div class="blog-card">
                    <?php if ($post['featured_image']): ?>
                        <div class="blog-thumbnail">
                            <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="blog-content">
                        <div class="blog-meta">
                            <span class="blog-category"><?php echo htmlspecialchars($post['category']); ?></span>
                            <span class="blog-date"><?php echo date('M j, Y', strtotime($post['published_at'])); ?></span>
                        </div>
                        <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                        <?php if ($post['excerpt']): ?>
                            <p class="blog-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                        <?php else: ?>
                            <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['content'], 0, 120)); ?>...</p>
                        <?php endif; ?>
                        <a href="/blog_post.php?slug=<?php echo $post['slug']; ?>" class="read-more">Read full reflection →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="placeholder-card">
                    <div class="placeholder-icon"><i class="fas fa-blog"></i></div>
                    <h3>Coming Soon</h3>
                    <p>Daily reflections and devotions from Angella will be available here soon.</p>
                    <a href="/blog.php" class="btn btn-outline">Visit Blog</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="section-footer">
            <a href="/blog.php" class="btn btn-outline">Read All Reflections →</a>
        </div>
    </div>
</section>

<!-- NEWSLETTER SIGNUP -->
<section class="newsletter-section section-padding" style="background-color: var(--fantasy);">
    <div class="container">
        <div class="newsletter-content">
            <h2>Stay <span class="rose-text">Inspired</span></h2>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources directly to your inbox.</p>
            <form action="/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
            <small>No spam. Unsubscribe anytime.</small>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
<style>
    /* ===== POEM CARD WITH IMAGE ===== */
.poem-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: all var(--transition);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
}

.poem-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.poem-thumbnail {
    width: 100%;
    height: 180px;
    overflow: hidden;
}

.poem-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}

.poem-card:hover .poem-thumbnail img {
    transform: scale(1.05);
}

.poem-content {
    padding: 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.poem-content h3 {
    font-size: 1.2rem;
    margin-bottom: 6px;
}

.poem-intro-preview {
    background: var(--vanilla);
    padding: 8px 12px;
    border-radius: 6px;
    margin: 6px 0 10px;
    border-left: 3px solid var(--rose);
}

.poem-intro-preview .intro-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--rose);
    margin-bottom: 2px;
}

.poem-excerpt {
    color: var(--text-light);
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 12px;
    flex: 1;
}

.poem-audio {
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
/* ===== BLOG CARDS ===== */
.blog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
}

.blog-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: all var(--transition);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
}

.blog-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.blog-thumbnail {
    width: 100%;
    height: 180px;
    overflow: hidden;
    background: var(--vanilla);
}

.blog-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}

.blog-card:hover .blog-thumbnail img {
    transform: scale(1.05);
}

.blog-content {
    padding: 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.blog-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    font-size: 0.85rem;
    color: var(--text-light);
}

.blog-category {
    background: var(--vanilla);
    padding: 2px 10px;
    border-radius: 12px;
    font-weight: 500;
    color: var(--text);
}

.blog-content h3 {
    font-size: 1.15rem;
    margin-bottom: 6px;
}

.blog-excerpt {
    color: var(--text-light);
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 12px;
    flex: 1;
}

.placeholder-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    grid-column: 1 / -1;
}

.placeholder-icon {
    font-size: 2.5rem;
    color: var(--rose);
    margin-bottom: 12px;
}

.placeholder-card h3 {
    font-size: 1.2rem;
    margin-bottom: 4px;
}

.placeholder-card p {
    color: var(--text-light);
    margin-bottom: 16px;
}
</style>