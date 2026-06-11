<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== FETCH TERMS CONTENT FROM DATABASE (if exists) =====
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'terms_content'");
$stmt->execute();
$terms_content = $stmt->fetchColumn();

// If no content in database, use static default
if (!$terms_content) {
    $terms_content = [
        'title' => 'Terms of Service',
        'sections' => [
            ['heading' => '1. Acceptance of Terms', 'content' => 'By accessing and using AngelWrites, you agree to comply with and be bound by these Terms of Service. If you do not agree to these terms, please do not use the site.'],
            ['heading' => '2. Use of Content', 'content' => 'All content provided on AngelWrites, including books, poems, reflections, blog posts, and videos, is for informational and educational purposes only. You may not reproduce, distribute, or commercially use any content without written permission from Angella Bottoman.'],
            ['heading' => '3. User Accounts', 'content' => 'You are responsible for maintaining the confidentiality of your account credentials. You are fully responsible for all activities that occur under your account.'],
            ['heading' => '4. Community Guidelines', 'content' => 'You agree to treat other users with respect and refrain from posting any content that is harmful, abusive, or defamatory.'],
            ['heading' => '5. Session Bookings', 'content' => 'Session bookings are subject to availability. Cancellations must be made at least 24 hours in advance.'],
            ['heading' => '6. Privacy', 'content' => 'Your privacy is important to us. Please review our Privacy Policy for details on how we handle your data.'],
            ['heading' => '7. Changes to Terms', 'content' => 'We reserve the right to update these terms at any time. Changes will be effective immediately upon posting.'],
            ['heading' => '8. Contact', 'content' => 'If you have any questions about these Terms of Service, please contact us at angelwrites@zohomail.com.']
        ]
    ];
} else {
    $terms_content = json_decode($terms_content, true) ?: [];
}

$pageTitle = htmlspecialchars($terms_content['title'] ?? 'Terms of Service');
?>
<?php require_once 'includes/header.php'; ?>

<div class="terms-page">
    <div class="container">
        <div class="terms-header">
            <h1><?php echo htmlspecialchars($terms_content['title'] ?? 'Terms of Service'); ?></h1>
            <p>Please read these terms carefully before using AngelWrites.</p>
            <p class="last-updated">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="terms-content">
            <?php if (isset($terms_content['sections']) && is_array($terms_content['sections'])): ?>
                <?php foreach ($terms_content['sections'] as $section): ?>
                    <div class="terms-section">
                        <h2><?php echo htmlspecialchars($section['heading'] ?? ''); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($section['content'] ?? '')); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="terms-section">
                    <h2>Terms of Service</h2>
                    <p>Content coming soon.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Newsletter Signup -->
        <div class="terms-newsletter">
            <h3>Stay Updated</h3>
            <p>Join the newsletter to receive the latest updates from AngelWrites.</p>
            <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <input type="hidden" name="redirect" value="/terms.php">
                <button type="submit" class="btn btn-primary">Subscribe</button>
            </form>
        </div>

        <div class="terms-footer">
            <p>If you have any questions, please <a href="<?php echo SITE_URL; ?>/contact.php">contact us</a>.</p>
        </div>
    </div>
</div>

<style>
.terms-page { padding: 32px 0 60px; }
.terms-header { text-align: center; margin-bottom: 40px; }
.terms-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.terms-header p { color: var(--text-light); font-size: 1.1rem; }
.terms-header .last-updated { font-size: 0.9rem; color: var(--text-light); margin-top: 4px; }
.terms-content { max-width: 800px; margin: 0 auto; }
.terms-section { margin-bottom: 32px; }
.terms-section h2 { font-size: 1.4rem; margin-bottom: 8px; color: var(--text); }
.terms-section p { line-height: 1.8; color: var(--text); margin-bottom: 0; }
.terms-newsletter { max-width: 800px; margin: 40px auto 0; text-align: center; background: var(--vanilla); border-radius: 12px; padding: 32px; }
.terms-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 12px; }
.terms-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.terms-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.terms-newsletter .btn { padding: 10px 24px; border-radius: 30px; }
.terms-footer { text-align: center; margin-top: 32px; color: var(--text-light); }
.terms-footer a { color: var(--rose); text-decoration: none; }
.terms-footer a:hover { text-decoration: underline; }
@media (max-width: 480px) { .terms-newsletter .newsletter-form { flex-direction: column; align-items: center; } .terms-newsletter .newsletter-form input { width: 100%; max-width: 300px; } }
</style>

<?php require_once 'includes/footer.php'; ?>