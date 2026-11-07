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
            // F223: the old text named Zoho as the email processor (mail is sent through Gmail) and left out
            // most of what is collected: IP logging, date of birth, gender, phone, camera and voice uploads,
            // reading activity, orders, Google sign-in and the CDNs every page loads.
            ['heading' => '1. Who we are', 'content' => 'AngelWrites (angelwrites.gt.tc) is run from Malawi and is responsible for the personal information described here. Contact: angelwrites@zohomail.com.'],
            ['heading' => '2. Information we collect', 'content' => 'When you register: first and last name, username, email address, password (stored only as a secure hash), gender, country, phone number, date of birth and how you heard about us.

If you add them: a profile photo (uploaded or taken with your camera), a short bio and contact details on your profile.

What you send us: comments, voice comments (recorded with your microphone only when you choose to record one), reviews, community questions and answers, reading-group posts, prayer requests, testimonials, contact-form messages and session bookings (date, time and time zone).

Your reading activity: books, poems and posts you open, reading progress and time, bookmarks, highlights and notes.

Book orders: the book, amount, the payment method you choose, the phone number you pay from and any transaction ID you give us. We never receive card or mobile-money PINs; you pay through your own mobile-money or bank service and we check the payment by hand.

Technical information: your IP address is recorded with login, registration and contact-form attempts (to stop abuse) and with some page views and reactions. See also Cookies below.'],
            ['heading' => '3. How we use your information', 'content' => 'To run your account and the features you use; to deliver books you have bought; to confirm orders and send receipts; to answer messages, prayer requests and session bookings; to send the newsletter if you subscribe (each email has an unsubscribe link); to keep the site secure and prevent abuse; and to understand which writing is read so we can improve the site. We do not sell your information or use it for advertising.'],
            ['heading' => '4. Services that process your information', 'content' => 'Hosting: InfinityFree hosts the website and its database.

Email: our emails (verification codes, password resets, receipts, notifications, newsletters) are sent through Google\'s Gmail service, which therefore handles your email address and the message. Replies go to our Zoho Mail mailbox.

Web fonts and code libraries: pages load fonts and scripts from Google Fonts, cdnjs (Cloudflare), jsDelivr and unpkg. Your browser contacts these services directly, so they receive your IP address and browser details.

Payments: the mobile-money or bank service you choose to pay with (for example Airtel Money, TNM Mpamba or National Bank of Malawi) processes your payment under its own terms.

Sign in with Google or Facebook: only if this option is available and you choose it; that provider then shares your name and email address with us.

Share buttons: Facebook, X (Twitter) and WhatsApp are contacted only when you press a share button.

Each of these services has its own privacy policy.'],
            ['heading' => '5. Cookies and browser storage', 'content' => 'We use a session cookie to keep you logged in, and, if you tick "Remember me", cookies that keep you logged in on that device. The site remembers your cookie choice and display preferences (such as dark mode and text size) in your browser. We do not use advertising or tracking cookies. You can clear cookies in your browser settings at any time; you will then need to log in again.'],
            ['heading' => '6. How long we keep it', 'content' => 'We keep your account information while your account exists. Order records are kept so that your purchases stay available to you and for our accounts. Records of login and contact-form attempts are used to prevent abuse. If you ask us to delete your account, we delete or anonymise your personal information unless we must keep part of it (for example a record of a payment).'],
            ['heading' => '7. Security', 'content' => 'Passwords are stored as secure hashes, and access to member and admin areas requires logging in. No website is completely secure, so please use a password you do not use elsewhere.'],
            ['heading' => '8. Your rights', 'content' => 'You can see and correct most of your details on your profile page, and unsubscribe from the newsletter at any time. You may ask us for a copy of the personal information we hold about you, or ask us to correct or delete it, by writing to angelwrites@zohomail.com. These rights are provided under the laws of Malawi.'],
            ['heading' => '9. Changes to this policy', 'content' => 'If we change this policy we will update this page and its "Last updated" date.'],
            ['heading' => '10. Contact us', 'content' => 'Questions about this policy or your information: angelwrites@zohomail.com, or use the contact page.']
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
            <p class="last-updated">Last updated: 6 November 2026</p><!-- F222: a fixed date (it used to print today's date on every visit) -->
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