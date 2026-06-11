<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

$pageTitle = 'Page Not Found — 404';
?>
<?php require_once 'includes/header.php'; ?>

<div class="error-page">
    <div class="container">
        <div class="error-content">
            <div class="error-icon">
                <i class="fas fa-compass" style="font-size: 5rem; color: var(--rose);"></i>
            </div>
            <h1>404</h1>
            <h2>Page Not Found</h2>
            <p>The page you're looking for doesn't exist or has been moved.</p>

            <div class="error-actions">
                <a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Return Home
                </a>
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">
                    <i class="fas fa-book"></i> Browse Books
                </a>
                <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">
                    <i class="fas fa-blog"></i> Read Blog
                </a>
            </div>

            <!-- Search Suggestions -->
            <div class="error-search">
                <h3>Search for what you're looking for</h3>
                <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Search books, poems, reflections..." required>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>
            </div>

            <!-- Newsletter Signup -->
            <div class="error-newsletter">
                <h3>Stay Updated</h3>
                <p>Join the newsletter to receive the latest updates from AngelWrites.</p>
                <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                    <input type="email" name="email" placeholder="Your email address" required>
                    <input type="hidden" name="redirect" value="/404.php">
                    <button type="submit" class="btn btn-primary">Subscribe</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.error-page { padding: 60px 0 80px; text-align: center; }
.error-content { max-width: 600px; margin: 0 auto; }
.error-icon { margin-bottom: 16px; }
.error-content h1 { font-size: 5rem; font-weight: 700; color: var(--rose); margin: 0; line-height: 1; }
.error-content h2 { font-size: 1.8rem; margin: 8px 0 12px; }
.error-content p { color: var(--text-light); font-size: 1.1rem; margin-bottom: 24px; }
.error-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-bottom: 32px; }
.error-actions .btn { padding: 10px 24px; border-radius: 30px; }
.error-search { margin-bottom: 32px; }
.error-search h3 { font-size: 1.2rem; margin-bottom: 12px; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.search-form input { flex: 1; min-width: 200px; padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding: 10px 24px; border-radius: 30px; }
.error-newsletter { background: var(--vanilla); border-radius: 12px; padding: 24px; margin-top: 16px; }
.error-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 8px; }
.error-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.error-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.error-newsletter .btn { padding: 10px 24px; border-radius: 30px; }
@media (max-width: 480px) { .search-form, .error-newsletter .newsletter-form { flex-direction: column; align-items: center; } .search-form input, .error-newsletter .newsletter-form input { width: 100%; max-width: 300px; } }
</style>

<?php require_once 'includes/footer.php'; ?>