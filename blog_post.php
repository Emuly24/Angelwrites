<?php
// ===== HANDLE COMMENT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && isLoggedIn()) {
    $reflection_id = (int)$_POST['reflection_id'];
    $comment = trim($_POST['comment']);
    
    if (!empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reflection_comments (reflection_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->execute([$reflection_id, $_SESSION['user_id'], $comment]);
        $success = 'Your comment has been posted!';
        header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
        exit;
    }
}

// ===== HANDLE PRAYER REQUEST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_prayer']) && isLoggedIn()) {
    $reflection_id = (int)$_POST['reflection_id'];
    $message = trim($_POST['message'] ?? 'I would like prayer for this reflection.');
    
    $stmt = $db->prepare("INSERT INTO prayer_requests (user_id, reflection_id, request_type, message) VALUES (?, ?, 'prayer', ?)");
    $stmt->execute([$_SESSION['user_id'], $reflection_id, $message]);
    $success = 'Your prayer request has been sent!';
    header('Location: ' . SITE_URL . '/blog_post.php?slug=' . $slug);
    exit;
}
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if (empty($slug)) {
    header('Location: ' . SITE_URL . '/blog.php');
    exit;
}

// Fetch the blog post
$stmt = $db->prepare("
    SELECT * FROM blog_posts 
    WHERE slug = ? AND status = 'published'
");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: ' . SITE_URL . '/blog.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?");
$stmt->execute([$post['id']]);

// Fetch related posts (same category, excluding current)
$stmt = $db->prepare("
    SELECT id, title, slug, created_at 
    FROM blog_posts 
    WHERE category = ? AND id != ? AND status = 'published'
    ORDER BY created_at DESC 
    LIMIT 3
");
$stmt->execute([$post['category'], $post['id']]);
$related_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($post['title']) . ' — Blog';
?>
<?php require_once 'includes/header.php'; ?>

<div class="blog-post-page">
    <div class="container">
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
                <?php echo nl2br(htmlspecialchars($post['content'])); ?>
            </div>
            <!-- ===== REFLECTION ACTIONS ===== -->
            <div class="reflection-actions">
                <!-- Request Prayer Button -->
                <form method="POST" class="prayer-form">
                    <input type="hidden" name="request_prayer" value="1">
                    <input type="hidden" name="reflection_id" value="<?php echo $id; ?>">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-hands-praying"></i> Request Prayer
                    </button>
                </form>
                
                <!-- Book a Session Button -->
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
<!-- ===== COMMENTS SECTION ===== -->
<section class="reflection-comments">
    <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments on this Reflection</h3>
    
    <?php
    // Fetch existing comments
    $stmt = $db->prepare("
        SELECT c.*, u.name AS author_name 
        FROM reflection_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.reflection_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
    <?php if (count($comments) > 0): ?>
        <div class="comments-list">
            <?php foreach ($comments as $comment): ?>
                <div class="comment-item">
                    <div class="comment-author">
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($comment['author_name']); ?>
                    </div>
                    <div class="comment-date"><?php echo date('M j, Y', strtotime($comment['created_at'])); ?></div>
                    <div class="comment-body"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="no-comments">No comments yet.</p>
    <?php endif; ?>

    <!-- Comment Form (logged in users only) -->
    <?php if (isLoggedIn()): ?>
        <div class="comment-form-container">
            <h4>Add a Comment</h4>
            <form method="POST" class="comment-form">
                <input type="hidden" name="add_comment" value="1">
                <input type="hidden" name="reflection_id" value="<?php echo $id; ?>">
                <div class="form-group">
                    <textarea name="comment" rows="3" placeholder="Share your thoughts about this reflection..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Post Comment</button>
            </form>
        </div>
    <?php else: ?>
        <div class="login-prompt">
            <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to comment on this reflection.</p>
        </div>
    <?php endif; ?>
</section>
<!-- ===== STYLES ===== -->
<style>
.blog-post-page {
    padding: 32px 0 60px;
}

.blog-post-nav {
    margin-bottom: 24px;
}
.back-link {
    color: var(--text-light);
    font-size: 0.95rem;
    transition: color var(--transition);
}
.back-link:hover {
    color: var(--rose);
}
.back-link i {
    margin-right: 6px;
}

.blog-post-article {
    max-width: 780px;
    margin: 0 auto;
}

.post-header {
    margin-bottom: 32px;
}
.post-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    color: var(--text-light);
    font-size: 0.9rem;
    margin-bottom: 12px;
}
.post-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.post-category {
    background: var(--vanilla);
    padding: 2px 12px;
    border-radius: 12px;
    font-weight: 500;
    color: var(--text);
}
.post-header h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(1.8rem, 3.5vw, 2.8rem);
    line-height: 1.2;
    margin-bottom: 12px;
}
.post-excerpt {
    font-size: 1.1rem;
    color: var(--text-light);
    line-height: 1.7;
    font-style: italic;
}

.post-content {
    line-height: 1.9;
    color: var(--text);
    font-size: 1.05rem;
    margin-bottom: 32px;
}
.post-content p {
    margin-bottom: 16px;
}
.post-content h2,
.post-content h3,
.post-content h4 {
    margin: 24px 0 12px;
}
.post-content ul,
.post-content ol {
    padding-left: 24px;
    margin-bottom: 16px;
}
.post-content blockquote {
    border-left: 4px solid var(--rose);
    padding-left: 16px;
    margin: 16px 0;
    color: var(--text-light);
    font-style: italic;
}

.post-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
}
.post-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.tag {
    background: var(--vanilla);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    color: var(--text);
}

.share-section {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
    color: var(--text-light);
}
.share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: white;
    font-size: 0.9rem;
    transition: transform var(--transition), opacity var(--transition);
}
.share-btn:hover {
    transform: scale(1.05);
    opacity: 0.85;
}
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }

/* ===== Related Posts ===== */
.related-posts {
    max-width: 780px;
    margin: 40px auto 0;
}
.related-posts h3 {
    font-size: 1.4rem;
    margin-bottom: 16px;
}
.related-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}
.related-card {
    background: var(--card-bg);
    border-radius: 10px;
    padding: 16px;
    border: 1px solid var(--border);
    transition: transform var(--transition), box-shadow var(--transition);
}
.related-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-hover);
}
.related-card a {
    color: var(--text);
    text-decoration: none;
}
.related-card h4 {
    font-size: 1rem;
    margin-bottom: 4px;
    transition: color var(--transition);
}
.related-card a:hover h4 {
    color: var(--rose);
}
.related-card small {
    color: var(--text-light);
    font-size: 0.8rem;
}

/* ===== Newsletter CTA ===== */
.blog-newsletter-cta {
    max-width: 780px;
    margin: 48px auto 0;
    background: var(--vanilla);
    border-radius: 12px;
    padding: 32px;
    text-align: center;
}
.blog-newsletter-cta .cta-inner h3 {
    font-size: 1.4rem;
    margin-bottom: 4px;
}
.blog-newsletter-cta .cta-inner p {
    color: var(--text-light);
    margin-bottom: 16px;
}
.cta-form {
    display: flex;
    gap: 12px;
    max-width: 450px;
    margin: 0 auto 8px;
    flex-wrap: wrap;
    justify-content: center;
}
.cta-form input {
    flex: 1;
    min-width: 200px;
    padding: 10px 16px;
    border: 1px solid var(--border);
    border-radius: 30px;
    font-size: 0.95rem;
    background: var(--input-bg);
    color: var(--text);
}
.cta-form input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.cta-form .btn {
    padding: 10px 28px;
    border-radius: 30px;
}
.blog-newsletter-cta small {
    color: var(--text-light);
    font-size: 0.8rem;
}

@media (max-width: 480px) {
    .post-meta {
        flex-direction: column;
        gap: 4px;
    }
    .post-footer {
        flex-direction: column;
        align-items: stretch;
    }
    .related-grid {
        grid-template-columns: 1fr;
    }
    .cta-form {
        flex-direction: column;
    }
    .cta-form input {
        min-width: unset;
    }
    .cta-form .btn {
        width: 100%;
    }
}
.reflection-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin: 24px 0;
    justify-content: center;
}

.prayer-form {
    display: inline;
}
</style>


<?php require_once 'includes/footer.php'; ?>