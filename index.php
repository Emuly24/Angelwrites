<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
// We do NOT include header/footer here — we will include them at the top and bottom.
// However, since header.php is dynamic and requires session, we include it below after potential logic.
// For clarity, let's structure it with the header inclusion at the top.
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
            <!-- Replace with a real image of Angella or her book cover -->
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
            // Fetch latest 3 books
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
            // Fetch latest 3 poems
            $stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 3");
            $stmt->execute();
            $poems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($poems) > 0):
                foreach ($poems as $poem):
            ?>
            <div class="poem-card">
                <div class="poem-content">
                    <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                    <p class="poem-excerpt"><?php echo htmlspecialchars(substr($poem['intro'] ?: $poem['content'], 0, 120)); ?>...</p>
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
    <div class="container cta-content">
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
            // Fetch latest 3 blog posts (assuming there is a 'blog_posts' table)
            // For now we'll use a placeholder query — adjust when you have the table.
            // For demonstration, we'll use a direct query that could work if you had the table.
            // If not, we'll show a placeholder.
            /*
            $stmt = $db->prepare("SELECT * FROM blog_posts ORDER BY created_at DESC LIMIT 3");
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            */
            // Placeholder data for now:
            $posts = [];
            ?>
            <?php if (count($posts) > 0): ?>
                <?php foreach ($posts as $post): ?>
                <div class="blog-card">
                    <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                    <p><?php echo htmlspecialchars(substr($post['content'], 0, 100)); ?>...</p>
                    <a href="/blog_post.php?id=<?php echo $post['id']; ?>" class="read-more">Read full reflection →</a>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="placeholder-card">
                    <p>Coming soon — daily reflections and devotions from Angella.</p>
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