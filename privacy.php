<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== FETCH PRIVACY CONTENT FROM DATABASE (if exists) =====
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'privacy_content'");
$stmt->execute();
$privacy_content = $stmt->fetchColumn();

// If no content in database, use static default
if (!$privacy_content) {
    $privacy_content = [
        'title' => 'Privacy Policy',
        'sections' => [
            ['heading' => '1. Information We Collect', 'content' => 'We collect personal information you provide to us, such as name, email address, phone number, and payment information when you register, book a session, or subscribe to our newsletter.'],
            ['heading' => '2. How We Use Your Information', 'content' => 'We use your information to provide and improve our services, process transactions, send notifications, and communicate with you about updates.'],
            ['heading' => '3. Data Security', 'content' => 'We implement reasonable security measures to protect your personal information from unauthorized access, alteration, or disclosure.'],
            ['heading' => '4. Third-Party Services', 'content' => 'We may use third-party services (e.g., Zoho SMTP for email, InfinityFree for hosting) that have their own privacy policies. We do not share your data unnecessarily.'],
            ['heading' => '5. Your Rights', 'content' => 'You have the right to access, correct, or delete your personal data. You may also unsubscribe from our newsletter at any time.'],
            ['heading' => '6. Cookies', 'content' => 'We use cookies to improve your browsing experience. You can manage cookie preferences in your browser settings.'],
            ['heading' => '7. Changes to This Policy', 'content' => 'We may update this privacy policy from time to time. Changes will be posted on this page.'],
            ['heading' => '8. Contact Us', 'content' => 'If you have any questions about this privacy policy, please contact us at angelwrites@zohomail.com.']
        ]
    ];
} else {
    $privacy_content = json_decode($privacy_content, true) ?: [];
}

$pageTitle = htmlspecialchars($privacy_content['title'] ?? 'Privacy Policy');
?>
<?php require_once 'includes/header.php'; ?>

<div class="privacy-page">
    <div class="container">
        <div class="privacy-header">
            <h1><?php echo htmlspecialchars($privacy_content['title'] ?? 'Privacy Policy'); ?></h1>
            <p>Your privacy is important to us. This policy explains how we handle your personal information.</p>
            <p class="last-updated">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <div class="privacy-content">
            <?php if (isset($privacy_content['sections']) && is_array($privacy_content['sections'])): ?>
                <?php foreach ($privacy_content['sections'] as $section): ?>
                    <div class="privacy-section">
                        <h2><?php echo htmlspecialchars($section['heading'] ?? ''); ?></h2>
                        <p><?php echo nl2br(htmlspecialchars($section['content'] ?? '')); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="privacy-section">
                    <h2>Privacy Policy</h2>
                    <p>Content coming soon.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Newsletter Signup -->
        <div class="privacy-newsletter">
            <h3>Stay Informed</h3>
            <p>Join our newsletter for updates about how we handle your data and new features.</p>
            <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <input type="hidden" name="redirect" value="/privacy.php">
                <button type="submit" class="btn btn-primary">Subscribe</button>
            </form>
        </div>

        <div class="privacy-footer">
            <p>If you have any questions about this privacy policy, please <a href="<?php echo SITE_URL; ?>/contact.php">contact us</a>.</p>
        </div>
    </div>
</div>

<style>
.privacy-page { padding: 32px 0 60px; }
.privacy-header { text-align: center; margin-bottom: 40px; }
.privacy-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.privacy-header p { color: var(--text-light); font-size: 1.1rem; }
.privacy-header .last-updated { font-size: 0.9rem; color: var(--text-light); margin-top: 4px; }
.privacy-content { max-width: 800px; margin: 0 auto; }
.privacy-section { margin-bottom: 32px; }
.privacy-section h2 { font-size: 1.4rem; margin-bottom: 8px; color: var(--text); }
.privacy-section p { line-height: 1.8; color: var(--text); margin-bottom: 0; }
.privacy-newsletter { max-width: 800px; margin: 40px auto 0; text-align: center; background: var(--vanilla); border-radius: 12px; padding: 32px; }
.privacy-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 12px; }
.privacy-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.privacy-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.privacy-newsletter .btn { padding: 10px 24px; border-radius: 30px; }
.privacy-footer { text-align: center; margin-top: 32px; color: var(--text-light); }
.privacy-footer a { color: var(--rose); text-decoration: none; }
.privacy-footer a:hover { text-decoration: underline; }
@media (max-width: 480px) { .privacy-newsletter .newsletter-form { flex-direction: column; align-items: center; } .privacy-newsletter .newsletter-form input { width: 100%; max-width: 300px; } }
</style>

<?php require_once 'includes/footer.php'; ?>