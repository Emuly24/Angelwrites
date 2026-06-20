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
        <div class="font-size-controls">
            <button id="fontSizeDecrease" class="btn btn-sm btn-outline" aria-label="Decrease font size">A−</button>
            <button id="resetFontSize" class="btn btn-sm btn-outline" aria-label="Reset font size">Reset</button>
            <button id="fontSizeIncrease" class="btn btn-sm btn-outline" aria-label="Increase font size">A+</button>
        </div>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Page Header -->
        <div class="privacy-header">
            <h1><?php echo htmlspecialchars($privacy_content['title'] ?? 'Privacy Policy'); ?></h1>
            <p>Your privacy is important to us. This policy explains how we handle your personal information.</p>
            <div class="header-decoration">
                <span class="decoration-line"></span>
            </div>
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

    increaseBtn.addEventListener('click', function() {
        applyFontSize(currentFontSize + 10);
    });
    decreaseBtn.addEventListener('click', function() {
        applyFontSize(currentFontSize - 10);
    });
    resetBtn.addEventListener('click', function() {
        applyFontSize(100);
    });

    // Apply saved font size if exists
    const savedFontSize = parseInt(localStorage.getItem('privacyFontSize'));
    if (savedFontSize) applyFontSize(savedFontSize);
});
</script>

<style>
/* ===== BRAND VARIABLES (AngelWrites) ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background 0.3s, color 0.3s; }

/* ===== DARK MODE ===== */
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --text: #e8dddd;
    --text-light: #a08a8a;
    --border: #4a3a3a;
    --vanilla: #2c1e1e;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
p { line-height:1.6; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS ===== */
.btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
    border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
    cursor:pointer; text-decoration:none; transition:all var(--transition);
    box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px;
}
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-sm { padding:8px 20px; font-size:0.85rem; }

/* ===== PAGE LAYOUT ===== */
.privacy-page { padding:40px 0 80px; }

/* ===== HEADER ===== */
.privacy-header { text-align:center; margin-bottom:40px; }
.privacy-header h1 { font-size:2.8rem; margin-bottom:4px; }
.privacy-header p { color:var(--text-light); font-size:1.1rem; }
.privacy-header .last-updated { font-size:0.9rem; color:var(--text-light); margin-top:4px; }
.header-decoration { display:flex; justify-content:center; margin:8px 0 12px; }
.decoration-line { width:60px; height:3px; background:var(--rose); border-radius:4px; }

/* ===== FONT SIZE CONTROLS ===== */
.font-size-controls {
    display:flex; gap:8px; justify-content:center; margin-bottom:24px;
}
.font-size-controls .btn-sm {
    padding:6px 14px; border-radius:50px; font-weight:600; font-size:0.9rem;
}

/* ===== CONTENT ===== */
.privacy-content { max-width:800px; margin:0 auto; }
.privacy-section { margin-bottom:32px; padding-bottom:24px; border-bottom:1px solid var(--border); }
.privacy-section:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }
.privacy-section h2 { font-size:1.5rem; margin-bottom:8px; color:var(--dark); }
.privacy-section p { line-height:1.8; color:var(--text); margin:0; }

/* ===== NEWSLETTER ===== */
.privacy-newsletter {
    max-width:800px; margin:48px auto 0; text-align:center;
    background:var(--vanilla); border-radius:20px; padding:36px 32px;
    border:1px solid var(--rose-light); box-shadow:var(--shadow);
}
.privacy-newsletter h3 { font-size:1.4rem; margin-bottom:4px; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.privacy-newsletter p { color:var(--text-light); margin-bottom:12px; }
.privacy-newsletter .newsletter-form { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; margin-top:12px; }
.privacy-newsletter .newsletter-form input {
    padding:10px 16px; border:1px solid var(--border); border-radius:50px;
    min-width:220px; background:var(--card-bg); color:var(--text); transition:border-color 0.2s;
}
.privacy-newsletter .newsletter-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.privacy-newsletter .btn { padding:10px 24px; border-radius:50px; }

/* ===== FOOTER ===== */
.privacy-footer { text-align:center; margin-top:32px; color:var(--text-light); font-size:0.95rem; }
.privacy-footer a { color:var(--rose); text-decoration:none; transition:color 0.2s; }
.privacy-footer a:hover { color:var(--rose-dark); text-decoration:underline; }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) {
    .privacy-header h1 { font-size:2.2rem; }
    .privacy-newsletter { padding:24px; }
    .privacy-newsletter .newsletter-form input { min-width:150px; }
}
@media (max-width:480px) {
    .privacy-header h1 { font-size:1.8rem; }
    .font-size-controls { gap:4px; }
    .font-size-controls .btn-sm { padding:4px 10px; font-size:0.8rem; }
    .privacy-newsletter .newsletter-form { flex-direction:column; align-items:center; }
    .privacy-newsletter .newsletter-form input { width:100%; min-width:auto; }
}
</style>

<?php require_once 'includes/footer.php'; ?>