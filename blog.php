<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// ===== PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 6;
$offset = ($page - 1) * $limit;

// ===== CATEGORY FILTER =====
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';

// ===== FETCH CATEGORIES =====
$stmt = $db->query("SELECT DISTINCT category FROM blog_posts WHERE status = 'published' ORDER BY category ASC");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ===== FETCH TOTAL POSTS =====
$count_sql = "SELECT COUNT(*) FROM blog_posts WHERE status = 'published'";
$count_params = [];
if ($category_filter) {
    $count_sql .= " AND category = ?";
    $count_params[] = $category_filter;
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_posts = $stmt->fetchColumn();
$total_pages = ceil($total_posts / $limit);

// ===== FETCH POSTS =====
$sql = "
    SELECT * FROM blog_posts 
    WHERE status = 'published'
";
$params = [];
if ($category_filter) {
    $sql .= " AND category = ?";
    $params[] = $category_filter;
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH RECENT POSTS (for sidebar) =====
$stmt = $db->query("SELECT id, title, slug, created_at FROM blog_posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 5");
$recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = $category_filter ? htmlspecialchars($category_filter) . ' — Blog' : 'Blog — Christian Reflections';
?>
<?php require_once 'includes/header.php'; ?>

<div class="blog-page">
    <div class="container">
        <!-- Page Header -->
        <div class="blog-header">
            <h1>Christian Reflections</h1>
            <p>Faith, hope, and encouragement for everyday life — written by Angella.</p>
        </div>

        <!-- Category Filter -->
        <?php if (count($categories) > 0): ?>
            <div class="category-filter">
                <span>Filter by category:</span>
                <a href="<?php echo SITE_URL; ?>/blog.php" class="category-link <?php echo !$category_filter ? 'active' : ''; ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="<?php echo SITE_URL; ?>/blog.php?category=<?php echo urlencode($cat); ?>" class="category-link <?php echo $category_filter === $cat ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Blog Layout -->
        <div class="blog-layout">
            <!-- Main Content -->
            <div class="blog-main">
                <?php if (count($posts) > 0): ?>
                    <div class="posts-grid">
                        <?php foreach ($posts as $post): ?>
                            <article class="post-card">
                                <div class="post-card-content">
                                    <div class="post-meta">
                                        <span class="post-category"><?php echo htmlspecialchars($post['category']); ?></span>
                                        <span class="post-date">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo date('M j, Y', strtotime($post['created_at'])); ?>
                                        </span>
                                    </div>
                                    <h3>
                                        <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>">
                                            <?php echo htmlspecialchars($post['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="post-excerpt">
                                        <?php echo htmlspecialchars(substr($post['excerpt'] ?? $post['content'], 0, 150)); ?>
                                        <?php if (strlen($post['excerpt'] ?? $post['content']) > 150) echo '...'; ?>
                                    </p>
                                    <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="read-more">
                                        Read full reflection <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo SITE_URL; ?>/blog.php?page=<?php echo $page - 1; ?><?php echo $category_filter ? '&category=' . urlencode($category_filter) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="<?php echo SITE_URL; ?>/blog.php?page=<?php echo $i; ?><?php echo $category_filter ? '&category=' . urlencode($category_filter) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo SITE_URL; ?>/blog.php?page=<?php echo $page + 1; ?><?php echo $category_filter ? '&category=' . urlencode($category_filter) : ''; ?>" class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-blog" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                        <h3>No Posts Yet</h3>
                        <p><?php echo $category_filter ? 'No posts in this category.' : 'Check back soon for new reflections from Angella.'; ?></p>
                        <?php if ($category_filter): ?>
                            <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">View all posts</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside class="blog-sidebar">
                <!-- Recent Posts -->
                <div class="sidebar-card">
                    <h4><i class="fas fa-clock" style="color: var(--rose);"></i> Recent Posts</h4>
                    <?php if (count($recent_posts) > 0): ?>
                        <ul class="recent-posts-list">
                            <?php foreach ($recent_posts as $rp): ?>
                                <li>
                                    <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $rp['slug']; ?>">
                                        <?php echo htmlspecialchars($rp['title']); ?>
                                    </a>
                                    <small><?php echo date('M j, Y', strtotime($rp['created_at'])); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No posts yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Categories -->
                <div class="sidebar-card">
                    <h4><i class="fas fa-tags" style="color: var(--rose);"></i> Categories</h4>
                    <?php if (count($categories) > 0): ?>
                        <ul class="categories-list">
                            <li><a href="<?php echo SITE_URL; ?>/blog.php" class="<?php echo !$category_filter ? 'active' : ''; ?>">All</a></li>
                            <?php foreach ($categories as $cat): ?>
                                <li>
                                    <a href="<?php echo SITE_URL; ?>/blog.php?category=<?php echo urlencode($cat); ?>" class="<?php echo $category_filter === $cat ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($cat); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No categories yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Newsletter -->
                <div class="sidebar-card">
                    <h4><i class="fas fa-envelope" style="color: var(--rose);"></i> Stay Updated</h4>
                    <p>Get new reflections delivered to your inbox.</p>
                    <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="sidebar-newsletter">
                        <input type="email" name="email" placeholder="Your email address" required>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Subscribe
                        </button>
                    </form>
                    <small>No spam. Unsubscribe anytime.</small>
                </div>
            </aside>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.blog-page {
    padding: 32px 0 60px;
}

.blog-header {
    text-align: center;
    margin-bottom: 32px;
}
.blog-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.blog-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

/* ===== Category Filter ===== */
.category-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 32px;
    justify-content: center;
}
.category-filter span {
    color: var(--text-light);
    font-size: 0.9rem;
    margin-right: 4px;
}
.category-link {
    padding: 4px 14px;
    border-radius: 20px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 0.85rem;
    transition: all var(--transition);
}
.category-link:hover {
    border-color: var(--rose);
}
.category-link.active {
    background: var(--rose);
    color: white;
    border-color: var(--rose);
}

/* ===== Blog Layout ===== */
.blog-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 32px;
}

/* ===== Posts Grid ===== */
.posts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.post-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform var(--transition), box-shadow var(--transition);
    display: flex;
    flex-direction: column;
}
.post-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.post-card-content {
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.post-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    font-size: 0.85rem;
}
.post-category {
    background: var(--vanilla);
    padding: 2px 12px;
    border-radius: 12px;
    font-weight: 500;
    color: var(--text);
}
.post-date {
    color: var(--text-light);
}
.post-date i {
    margin-right: 4px;
}

.post-card h3 {
    font-size: 1.15rem;
    margin-bottom: 6px;
    line-height: 1.3;
}
.post-card h3 a {
    color: var(--text);
    transition: color var(--transition);
}
.post-card h3 a:hover {
    color: var(--rose);
}

.post-excerpt {
    color: var(--text-light);
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 12px;
    flex: 1;
}

.read-more {
    color: var(--rose);
    font-weight: 500;
    font-size: 0.9rem;
    transition: color var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.read-more:hover {
    color: var(--rose-dark);
}
.read-more i {
    font-size: 0.8rem;
    transition: transform var(--transition);
}
.read-more:hover i {
    transform: translateX(4px);
}

/* ===== Pagination ===== */
.pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 32px;
    flex-wrap: wrap;
}
.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 8px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 0.9rem;
    transition: all var(--transition);
    min-width: 36px;
}
.page-link:hover {
    border-color: var(--rose);
}
.page-link.active {
    background: var(--rose);
    color: white;
    border-color: var(--rose);
}

/* ===== Sidebar ===== */
.blog-sidebar {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.sidebar-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.sidebar-card h4 {
    font-size: 1.05rem;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.recent-posts-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.recent-posts-list li {
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.recent-posts-list li:last-child {
    border-bottom: none;
}
.recent-posts-list li a {
    display: block;
    font-weight: 500;
    color: var(--text);
    transition: color var(--transition);
}
.recent-posts-list li a:hover {
    color: var(--rose);
}
.recent-posts-list li small {
    display: block;
    color: var(--text-light);
    font-size: 0.8rem;
    margin-top: 2px;
}

.categories-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.categories-list li {
    padding: 6px 0;
}
.categories-list li a {
    color: var(--text);
    transition: color var(--transition);
    font-size: 0.95rem;
}
.categories-list li a:hover {
    color: var(--rose);
}
.categories-list li a.active {
    color: var(--rose);
    font-weight: 600;
}

.sidebar-newsletter input {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 12px;
    background: var(--input-bg);
    color: var(--text);
}
.sidebar-newsletter input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.sidebar-newsletter .btn-block {
    width: 100%;
}
.sidebar-newsletter small {
    display: block;
    text-align: center;
    margin-top: 8px;
    color: var(--text-light);
    font-size: 0.8rem;
}

.text-muted {
    color: var(--text-light);
    font-style: italic;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-light);
    grid-column: 1 / -1;
}
.empty-state h3 {
    font-size: 1.3rem;
    margin-bottom: 6px;
}

@media (max-width: 768px) {
    .blog-layout {
        grid-template-columns: 1fr;
    }
    .posts-grid {
        grid-template-columns: 1fr;
    }
    .blog-sidebar {
        order: -1;
    }
    .category-filter {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .post-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>