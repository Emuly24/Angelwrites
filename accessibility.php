<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/site_texts.php'; // page text can be changed on the site
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
            <h1><?php echo aw_text('accessibility.h11', 'Accessibility Statement', 'line'); ?></h1>
            <p><?php echo aw_text('accessibility.p1', 'AngelWrites is committed to making our website accessible to all users, including those with disabilities.', 'text'); ?></p>
            <div class="header-decoration">
                <span class="decoration-line"></span>
            </div>
        </div>


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
                <h2><?php echo aw_text('accessibility.h21', 'Our Commitment', 'line'); ?></h2>
                <p><?php echo aw_text('accessibility.p2', 'AngelWrites strives to ensure that our website is accessible to people of all abilities. We are working towards the **Web Content Accessibility Guidelines (WCAG) 2.1 Level AA**. The site does not fully meet that standard yet; the known gaps are listed below.', 'text'); ?></p><!-- F224 -->
                <p><?php echo aw_text('accessibility.p3', 'We believe that everyone should have equal access to Christian writing, poetry, reflections, and community resources.', 'text'); ?></p>
            </section>

            <section class="a11y-section">
                <h2><?php echo aw_text('accessibility.h22', 'Accessibility Features', 'line'); ?></h2>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-keyboard"></i></div>
                        <h3><?php echo aw_text('accessibility.h31', 'Keyboard Navigation', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p4', 'All interactive elements can be accessed using the **Tab** key. Use **Enter** or **Space** to activate links and buttons.', 'text'); ?></p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-universal-access"></i></div>
                        <h3><?php echo aw_text('accessibility.h32', 'Screen Reader Support', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p5', 'We use headings and labels so pages can be read with screen readers such as JAWS, NVDA and VoiceOver. Some controls are not labelled yet (see Known Limitations).', 'text'); ?></p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-text-height"></i></div>
                        <h3><?php echo aw_text('accessibility.h33', 'Text Resizing', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p6', 'Use your browser\'s zoom function (Ctrl/Cmd + / -) or the built-in font size controls in our **reader** and **bible reader** pages.', 'text'); ?></p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-adjust"></i></div>
                        <h3><?php echo aw_text('accessibility.h34', 'High Contrast Mode', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p7', 'Click the **"Toggle High Contrast Mode"** button above to increase contrast for better readability.', 'text'); ?></p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-image"></i></div>
                        <h3><?php echo aw_text('accessibility.h35', 'Alternative Text', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p8', 'Book covers and most content images have alternative text (alt text). Some decorative and member-uploaded images do not yet.', 'text'); ?></p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-video"></i></div>
                        <h3><?php echo aw_text('accessibility.h36', 'Captions & Transcripts', 'line'); ?></h3>
                        <p><?php echo aw_text('accessibility.p9', 'Videos do not have captions or transcripts yet. If you need one, contact us and we will provide a transcript.', 'text'); ?></p>
                    </div>
                </div>
            </section>

            <section class="a11y-section">
                <h2><?php echo aw_text('accessibility.h23', 'Known Limitations', 'line'); ?></h2>
                <p><?php echo aw_text('accessibility.p10', 'We know of the following problems and are working on them:', 'text'); ?></p>
                <ul>
                    <li><?php echo aw_text('accessibility.li1', '**Colour contrast:** some light pink text and buttons may not have enough contrast. The High Contrast Mode above helps.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li2', '**Unlabelled controls:** some icon-only buttons (for example reactions and some reader tools) have no text label for screen readers.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li3', '**Images and video:** not every image has alt text, and videos have no captions or transcripts yet.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li4', '**Pop-ups and forms:** some pop-up windows (such as the book order form) do not yet move keyboard focus or announce themselves to screen readers.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li5', '**Some older PDF documents** may not be fully screen-reader accessible. We are updating these as we identify them.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li6', '**Third-party integrations** (e.g., Google OAuth, YouTube embeds) may have their own accessibility limitations.', 'text'); ?></li>
                    <li><?php echo aw_text('accessibility.li7', '**Interactive features** in the Bible reader and poem viewer are being tested for full keyboard navigation.', 'text'); ?></li>
                </ul>
                <p><?php echo aw_text('accessibility.p11', 'If you encounter any barriers while using our site, please let us know.', 'text'); ?></p>
            </section>

            <section class="a11y-section">
                <h2><?php echo aw_text('accessibility.h24', 'Need Help?', 'line'); ?></h2>
                <p><?php echo aw_text('accessibility.p12', 'If you have difficulty accessing any part of AngelWrites, please contact us and we will work with you to provide the information or service you need.', 'text'); ?></p>
                <div class="contact-actions">
                    <a href="<?php echo SITE_URL; ?>/contact.php" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Contact Us
                    </a>
                    <a href="mailto:angelwrites@zohomail.com" class="btn btn-outline">
                        <i class="fas fa-paper-plane"></i> Email Support
                    </a>
                </div>
                <p class="contact-details">
                    <?php echo aw_text('accessibility.p13', '**Response Time:** We typically respond within 2 business days.', 'text'); ?>
                </p>
            </section>

            <section class="a11y-section">
                <h2><?php echo aw_text('accessibility.h25', 'Compliance & Standards', 'line'); ?></h2>
                <p><?php echo aw_text('accessibility.p14', 'AngelWrites **partially conforms** to [WCAG 2.1 Level AA](https://www.w3.org/TR/WCAG21/): some parts of the site do not yet meet the standard (see Known Limitations). This statement is based on our own review of the site; it has not been independently audited.', 'text'); ?></p><!-- F224 -->
                <p>This accessibility statement was last updated on <strong>6 November 2026</strong><!-- F222: a fixed date (it used to print today's date on every visit) -->.</p>
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
    --rose: #94404a;
    --rose-dark: #94404a;
    --vanilla: #f5f0e8;
    --fantasy: #faf3ef;
    --card-bg: #ffffff;
    --border: #2c1e1e;
    --text: #000000;
    --text-light: #3d2e2e;
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