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

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="accessibility-page" id="mainContent">
    <div class="container">
        <!-- ===== PAGE HEADER ===== -->
        <div class="accessibility-header">
            <h1>Accessibility Statement</h1>
            <p>AngelWrites is committed to making our website accessible to all users, including those with disabilities.</p>
            <div class="header-decoration">
                <span class="decoration-line"></span>
            </div>
        </div>

        <!-- ===== THEME TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== ACCESSIBILITY CONTROLS ===== -->
        <div class="accessibility-controls">
            <button id="highContrastToggle" class="btn btn-outline">
                <i class="fas fa-adjust"></i> Toggle High Contrast Mode
            </button>
            <button id="resetAccessibility" class="btn btn-outline">
                <i class="fas fa-undo"></i> Reset to Default
            </button>
        </div>

        <!-- ===== MAIN CONTENT ===== -->
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

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

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

    // ===== MAKE toggleTheme GLOBAL =====
    window.toggleTheme = toggleTheme;
});
</script>

<style>
/* ===== BRAND VARIABLES ===== */
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

/* ===== HIGH CONTRAST MODE ===== */
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

/* ===== PAGE LAYOUT ===== */
.accessibility-page { padding:40px 0 80px; }

/* ===== HEADER ===== */
.accessibility-header { text-align:center; margin-bottom:32px; }
.accessibility-header h1 { font-size:2.8rem; margin-bottom:4px; }
.accessibility-header p { color:var(--text-light); font-size:1.1rem; }
.header-decoration { display:flex; justify-content:center; margin:8px 0 12px; }
.decoration-line { width:60px; height:3px; background:var(--rose); border-radius:4px; }

/* ===== ACCESSIBILITY CONTROLS ===== */
.accessibility-controls {
    display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin-bottom:32px;
}
.accessibility-controls .btn { padding:10px 24px; border-radius:50px; font-weight:600; }

/* ===== SECTIONS ===== */
.a11y-section {
    background:var(--card-bg); border-radius:20px; padding:28px 32px;
    border:1px solid var(--border); box-shadow:var(--shadow); margin-bottom:28px;
    transition:all var(--transition);
}
.a11y-section:hover { box-shadow:var(--shadow-hover); }
.a11y-section h2 { font-size:1.6rem; margin-bottom:12px; color:var(--dark); }
.a11y-section p { line-height:1.8; color:var(--text); margin-bottom:12px; }
.a11y-section ul { padding-left:24px; margin-bottom:12px; }
.a11y-section ul li { line-height:1.8; color:var(--text); margin-bottom:4px; }

/* ===== FEATURES GRID ===== */
.features-grid {
    display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));
    gap:16px; margin-top:12px;
}
.feature-card {
    background:var(--vanilla); border-radius:16px; padding:24px 20px;
    text-align:center; border:1px solid var(--border);
    transition:all var(--transition);
}
.feature-card:hover {
    transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color:var(--rose-light);
}
.feature-icon { font-size:2.2rem; color:var(--rose); margin-bottom:8px; display:block; }
.feature-card h3 { font-size:1.05rem; margin-bottom:4px; color:var(--dark); }
.feature-card p { font-size:0.9rem; color:var(--text-light); line-height:1.5; margin:0; }

/* ===== CONTACT ===== */
.contact-actions { display:flex; flex-wrap:wrap; gap:12px; margin:16px 0; }
.contact-actions .btn { padding:10px 28px; border-radius:50px; }
.contact-details { margin-top:8px; }
.contact-details a { color:var(--rose); text-decoration:none; transition:color 0.2s; }
.contact-details a:hover { color:var(--rose-dark); text-decoration:underline; }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) {
    .accessibility-header h1 { font-size:2.2rem; }
    .accessibility-controls { flex-direction:column; align-items:center; }
    .accessibility-controls .btn { width:100%; max-width:280px; justify-content:center; }
}
@media (max-width:480px) {
    .accessibility-header h1 { font-size:1.8rem; }
    .features-grid { grid-template-columns:1fr; }
    .a11y-section { padding:20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>