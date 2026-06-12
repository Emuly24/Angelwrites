<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$pageTitle = 'Accessibility Statement';
$metaDescription = 'AngelWrites is committed to making our website accessible to all users, including those with disabilities. Learn about our accessibility features and how to get support.';
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== SKIP TO CONTENT LINK ===== -->
<a href="#mainContent" class="skip-link" style="position:absolute;top:-40px;left:0;background:var(--rose);color:white;padding:8px 16px;z-index:9999;border-radius:0 0 8px 0;transition:top 0.2s;text-decoration:none;font-weight:600;">Skip to main content</a>

<style>
/* Skip link visibility on focus */
.skip-link:focus { top: 0; }

/* High contrast mode styles */
.high-contrast {
    --rose: #c0392b;
    --rose-dark: #922b21;
    --vanilla: #f5f0e8;
    --fantasy: #faf3ef;
    --card-bg: #ffffff;
    --border: #222222;
    --text: #000000;
    --text-light: #333333;
    --dark: #000000;
    --bg: #fcfcfc;
    --input-bg: #ffffff;
    --shadow: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-hover: 0 6px 20px rgba(0,0,0,0.4);
}
.high-contrast .theme-toggle { color: #000; }
.high-contrast a { text-decoration: underline; }
.high-contrast .btn { border: 2px solid #000; }
</style>

<div class="accessibility-page" id="mainContent">
    <div class="container">
        <!-- Page Header -->
        <div class="accessibility-header">
            <h1>Accessibility Statement</h1>
            <p>AngelWrites is committed to making our website accessible to all users, including those with disabilities.</p>
        </div>

        <!-- ===== THEME TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- Accessibility Controls -->
        <div class="accessibility-controls">
            <button id="highContrastToggle" class="btn btn-outline">
                <i class="fas fa-adjust"></i> Toggle High Contrast Mode
            </button>
            <button id="resetAccessibility" class="btn btn-outline">
                <i class="fas fa-undo"></i> Reset to Default
            </button>
        </div>

        <!-- Main Content -->
        <div class="accessibility-content">
            <section class="a11y-section">
                <h2>Our Commitment</h2>
                <p>AngelWrites strives to ensure that our website is accessible to people of all abilities. We continuously work to improve the user experience for everyone by following the <strong>Web Content Accessibility Guidelines (WCAG) 2.1 Level AA</strong> standards.</p>
                <p>We believe that everyone should have equal access to Christian writing, poetry, reflections, and community resources.</p>
            </section>

            <section class="a11y-section">
                <h2>Accessibility Features</h2>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-keyboard"></i></div>
                        <h3>Keyboard Navigation</h3>
                        <p>All interactive elements can be accessed using the <strong>Tab</strong> key. Use <strong>Enter</strong> or <strong>Space</strong> to activate links and buttons.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-universal-access"></i></div>
                        <h3>Screen Reader Support</h3>
                        <p>Proper heading structure, ARIA labels, and descriptive text ensure compatibility with screen readers like JAWS, NVDA, and VoiceOver.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-text-height"></i></div>
                        <h3>Text Resizing</h3>
                        <p>Use your browser's zoom function (Ctrl/Cmd + / -) or the built-in font size controls in our <strong>reader</strong> and <strong>bible reader</strong> pages.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-adjust"></i></div>
                        <h3>High Contrast Mode</h3>
                        <p>Click the <strong>"Toggle High Contrast Mode"</strong> button above to increase contrast for better readability.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-image"></i></div>
                        <h3>Alternative Text</h3>
                        <p>All images include descriptive alternative text (alt text) for users who rely on screen readers.</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-video"></i></div>
                        <h3>Captions & Transcripts</h3>
                        <p>Videos on AngelWrites include captions and transcripts where available.</p>
                    </div>
                </div>
            </section>

            <section class="a11y-section">
                <h2>Known Limitations</h2>
                <p>We are actively working to improve the following areas:</p>
                <ul>
                    <li><strong>Some older PDF documents</strong> may not be fully screen-reader accessible. We are updating these as we identify them.</li>
                    <li><strong>Third-party integrations</strong> (e.g., Google OAuth, YouTube embeds) may have their own accessibility limitations.</li>
                    <li><strong>Interactive features</strong> in the Bible reader and poem viewer are being tested for full keyboard navigation.</li>
                </ul>
                <p>If you encounter any barriers while using our site, please let us know.</p>
            </section>

            <section class="a11y-section">
                <h2>Need Help?</h2>
                <p>If you have difficulty accessing any part of AngelWrites, please contact us and we will work with you to provide the information or service you need.</p>
                <div class="contact-actions">
                    <a href="<?php echo SITE_URL; ?>/contact.php" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Contact Us
                    </a>
                    <a href="mailto:angelwrites@zohomail.com" class="btn btn-outline">
                        <i class="fas fa-paper-plane"></i> Email Support
                    </a>
                </div>
                <p class="contact-details">
                    <strong>Email:</strong> <a href="mailto:angelwrites@zohomail.com">angelwrites@zohomail.com</a><br>
                    <strong>Response Time:</strong> We typically respond within 2 business days.
                </p>
            </section>

            <section class="a11y-section">
                <h2>Compliance & Standards</h2>
                <p>AngelWrites is committed to meeting the <a href="https://www.w3.org/TR/WCAG21/" target="_blank">WCAG 2.1 Level AA</a> standards. We conduct regular audits and user testing to ensure ongoing compliance.</p>
                <p>This accessibility statement was last updated on <strong><?php echo date('F j, Y'); ?></strong>.</p>
            </section>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT FOR ACCESSIBILITY ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('accessibilityTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('accessibilityTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    }

    // ===== HIGH CONTRAST TOGGLE =====
    const highContrastBtn = document.getElementById('highContrastToggle');
    const resetBtn = document.getElementById('resetAccessibility');
    const html = document.documentElement;

    highContrastBtn.addEventListener('click', function() {
        html.classList.toggle('high-contrast');
        const isHighContrast = html.classList.contains('high-contrast');
        localStorage.setItem('highContrastMode', isHighContrast ? 'true' : 'false');
        this.innerHTML = isHighContrast 
            ? '<i class="fas fa-adjust"></i> Disable High Contrast' 
            : '<i class="fas fa-adjust"></i> Toggle High Contrast Mode';
    });

    resetBtn.addEventListener('click', function() {
        html.classList.remove('high-contrast');
        localStorage.setItem('highContrastMode', 'false');
        highContrastBtn.innerHTML = '<i class="fas fa-adjust"></i> Toggle High Contrast Mode';
    });

    const savedMode = localStorage.getItem('highContrastMode');
    if (savedMode === 'true') {
        html.classList.add('high-contrast');
        highContrastBtn.innerHTML = '<i class="fas fa-adjust"></i> Disable High Contrast';
    }
});
</script>

<style>
/* ===== ACCESSIBILITY PAGE STYLES ===== */
.accessibility-page { padding: 32px 0 60px; }
.accessibility-header { text-align: center; margin-bottom: 24px; }
.accessibility-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.accessibility-header p { color: var(--text-light); font-size: 1.05rem; }
.accessibility-controls { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-bottom: 32px; }
.accessibility-controls .btn { padding: 10px 20px; border-radius: 30px; font-weight: 500; }
.a11y-section { background: var(--card-bg); border-radius: 16px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.a11y-section h2 { font-size: 1.6rem; margin-bottom: 12px; color: var(--text); }
.a11y-section p { line-height: 1.7; color: var(--text); margin-bottom: 12px; }
.a11y-section ul { padding-left: 24px; margin-bottom: 12px; }
.a11y-section ul li { line-height: 1.7; color: var(--text); margin-bottom: 4px; }
.features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; margin-top: 12px; }
.feature-card { background: var(--vanilla); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid var(--border); transition: transform 0.2s, box-shadow 0.2s; }
.feature-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.feature-icon { font-size: 2.2rem; color: var(--rose); margin-bottom: 8px; }
.feature-card h3 { font-size: 1.05rem; margin-bottom: 4px; }
.feature-card p { font-size: 0.9rem; color: var(--text-light); line-height: 1.5; margin: 0; }
.contact-actions { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0; }
.contact-actions .btn { padding: 10px 24px; border-radius: 30px; }
.contact-details { margin-top: 8px; }
.contact-details a { color: var(--rose); text-decoration: none; }
.contact-details a:hover { text-decoration: underline; }
@media (max-width: 480px) {
    .accessibility-header h1 { font-size: 1.8rem; }
    .features-grid { grid-template-columns: 1fr; }
    .accessibility-controls { flex-direction: column; align-items: center; }
    .accessibility-controls .btn { width: 100%; max-width: 280px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>