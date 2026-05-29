<?php
// No need to start session again - it's already started in header.php
// But we do need access to auth functions
require_once __DIR__ . '/auth.php';

$isLoggedIn = isLoggedIn();
$isAdmin = isAdmin();
$isReader = $isLoggedIn && !$isAdmin;
?>
    </main> <!-- Close main content that was opened in header.php -->

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <!-- Brand column -->
                <div class="footer-brand">
                    <a href="<?php echo SITE_URL; ?>/index.php" class="logo">
    <img src="<?php echo SITE_URL; ?>/assets/images/logo.png" alt="AngelWrites Logo" class="logo-img">
</a>
                    <p class="footer-tagline">Writing with purpose, faith, and passion.</p>
                </div>

                <!-- Quick links column -->
                <div class="footer-links">
                    <h4>Quick Links</h4>
                    <ul>
                        <?php if (!$isLoggedIn): ?>
                            <li><a href="<?php echo SITE_URL; ?>/index.php">Home</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/books.php">Books</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/poetry.php">Poems</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/blog.php">Blog</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/about.php">About</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/contact.php">Contact</a></li>
                        <?php elseif ($isAdmin): ?>
                            <li><a href="<?php echo SITE_URL; ?>/admin/dashboard.php">Dashboard</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/admin/manage_books.php">Manage Books</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php">Manage Poems</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/admin/manage_users.php">Manage Users</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo SITE_URL; ?>/library.php">My Library</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/books.php">All Books</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/poetry.php">Poems</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/community.php">Community Q&A</a></li>
                            <li><a href="<?php echo SITE_URL; ?>/book_session.php">Book a Session</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Social & Newsletter column -->
                <div class="footer-social">
                    <h4>Connect</h4>
                    <div class="social-icons">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                        <a href="#" aria-label="Pinterest"><i class="fab fa-pinterest-p"></i></a>
                    </div>
                    <div class="footer-newsletter">
                        <p>Get updates straight to your inbox.</p>
                        <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="footer-newsletter-form">
                            <input type="email" name="email" placeholder="Your email" required>
                            <button type="submit" aria-label="Subscribe"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <p>
                    &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. 
                    All rights reserved. 
                    <span class="footer-heart">Made with <i class="fas fa-heart" style="color: var(--rose);"></i> in Malawi.</span>
                </p>
                <p class="footer-version">
                    <small>v1.0.0</small>
                </p>
            </div>
        </div>
    </footer>

    <!-- ===== SCRIPTS ===== -->
    <!-- Theme toggle JS is now in assets/js/main.js -->
    <script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>
</body>
</html>