<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== FETCH PRIVACY CONTENT FROM DATABASE =====
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== FONT SIZE CONTROLS ===== -->
        <div class="accessibility-controls">
            <button id="fontSizeIncrease" class="btn btn-sm btn-outline">A+</button>
            <button id="fontSizeDecrease" class="btn btn-sm btn-outline">A-</button>
            <button id="resetFontSize" class="btn btn-sm btn-outline">Reset</button>
        </div>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Page Header -->
        <div class="privacy-header">
            <h1><?php echo htmlspecialchars($privacy_content['title'] ?? 'Privacy Policy'); ?></h1>
            <p>Your privacy is important to us. This policy explains how we handle your personal information.</p>
            <p class="last-updated">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>

        <!-- Main Content -->
        <div class="privacy-content" id="privacyContent">
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

        <!-- Newsletter -->
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

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('privacyTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('privacyTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== FONT SIZE CONTROLS =====
    let currentFontSize = parseInt(localStorage.getItem('privacyFontSize')) || 100;
    const content = document.getElementById('privacyContent');
    const increaseBtn = document.getElementById('fontSizeIncrease');
    const decreaseBtn = document.getElementById('fontSizeDecrease');
    const resetBtn = document.getElementById('resetFontSize');

    function applyFontSize(size) {
        currentFontSize = Math.min(160, Math.max(80, size));
        content.style.fontSize = currentFontSize + '%';
        localStorage.setItem('privacyFontSize', currentFontSize);
    }

    increaseBtn.addEventListener('click', () => applyFontSize(currentFontSize + 10));
    decreaseBtn.addEventListener('click', () => applyFontSize(currentFontSize - 10));
    resetBtn.addEventListener('click', () => applyFontSize(100));

    const savedFontSize = parseInt(localStorage.getItem('privacyFontSize'));
    if (savedFontSize) applyFontSize(savedFontSize);
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.privacy-page { padding: 32px 0 60px; }
.privacy-header { text-align: center; margin-bottom: 40px; }
.privacy-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.privacy-header p { color: var(--text-light); font-size: 1.1rem; }
.privacy-header .last-updated { font-size: 0.9rem; color: var(--text-light); margin-top: 4px; }

.privacy-content { max-width: 800px; margin: 0 auto; }
.privacy-section { margin-bottom: 32px; }
.privacy-section h2 { font-size: 1.4rem; margin-bottom: 8px; color: var(--text); }
.privacy-section p { line-height: 1.8; color: var(--text); margin-bottom: 0; }

.accessibility-controls {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 20px;
}
.accessibility-controls .btn-sm {
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
}

.privacy-newsletter { max-width: 800px; margin: 40px auto 0; text-align: center; background: var(--vanilla); border-radius: 12px; padding: 32px; }
.privacy-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 12px; }
.privacy-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.privacy-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.privacy-newsletter .btn { padding: 10px 24px; border-radius: 30px; }

.privacy-footer { text-align: center; margin-top: 32px; color: var(--text-light); }
.privacy-footer a { color: var(--rose); text-decoration: none; }
.privacy-footer a:hover { text-decoration: underline; }

@media (max-width: 480px) {
    .privacy-newsletter .newsletter-form { flex-direction: column; align-items: center; }
    .privacy-newsletter .newsletter-form input { width: 100%; max-width: 300px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>