<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== FETCH ABOUT CONTENT FROM DATABASE =====
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'about_content'");
$stmt->execute();
$about_content_json = $stmt->fetchColumn();

if (!$about_content_json) {
    $about_content = [
        'bio' => "Angella Bottoman is a passionate writer based in Malawi. She believes that there are treasures stored in each and every person — and that something beautiful can come out of pain once it is placed in the hands of God.",
        'mission' => "To write words that heal, inspire, and transform — and to create a community where women can find encouragement, purpose, and a safe space to share their own stories.",
        'quote' => "There is something beautiful that can come out of pain once it is handed in the hands of God.",
        'photo' => ''
    ];
} else {
    $about_content = json_decode($about_content_json, true) ?: [];
}

// ===== HANDLE CONTACT FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $message = trim($_POST['message']);
    
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $stmt = $db->prepare("INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $message]);
        
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '📩 New Contact Message from ' . $name;
        $body = "<h2>New Contact Message</h2>";
        $body .= "<p><strong>Name:</strong> $name</p>";
        $body .= "<p><strong>Email:</strong> $email</p>";
        $body .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
        
        $success = 'Message sent successfully!';
    }
}

// ===== USER REPUTATION & ACHIEVEMENTS =====
$user_rep = null;
$achievements = [];
$reading_streak = 0;
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT points, level, badges FROM user_reputations WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_rep = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT achievement_type, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $reading_streak = $stmt->fetchColumn() ?? 0;
}

$pageTitle = 'About Angella Bottoman';
?>
<?php require_once 'includes/header.php'; ?>

<div class="about-page">
    <div class="container">
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- ===== HERO ===== -->
        <div class="about-hero">
            <div class="about-hero-content">
                <h1>About <span class="rose-text">Angella</span></h1>
                <p class="subtitle">Writer · Speaker · Encourager</p>
                <div class="hero-divider"></div>
            </div>
        </div>

        <!-- ===== MAIN CONTENT ===== -->
        <div class="about-content-wrapper">
            <div class="about-grid">
                <!-- Photo -->
                <div class="about-photo-section">
                    <div class="about-photo">
                        <?php if ($about_content['photo'] ?? ''): ?>
                            <img src="<?php echo SITE_URL . '/' . $about_content['photo']; ?>" alt="Angella Bottoman">
                        <?php else: ?>
                            <img src="<?php echo SITE_URL; ?>/assets/images/angella.jpg" alt="Angella Bottoman">
                        <?php endif; ?>
                    </div>
                    <p class="about-photo-caption">Angella Bottoman — passionate writer.</p>
                </div>

                <!-- Bio -->
                <div class="about-bio-section">
                    <h2>Her Story</h2>
                    <p class="bio-text"><?php echo htmlspecialchars($about_content['bio'] ?? ''); ?></p>
                    <p><strong>The Beautiful Broken Vessel</strong> was born from a desire to show God's transforming power over the body, soul, and spirit.</p>
                    
                    <h3>Her Mission</h3>
                    <p class="mission-text"><?php echo htmlspecialchars($about_content['mission'] ?? ''); ?></p>
                    
                    <div class="mission-statement">
                        <blockquote>
                            <span class="quote-mark left"><i class="fas fa-quote-left"></i></span>
                            <?php echo htmlspecialchars($about_content['quote'] ?? ''); ?>
                            <span class="quote-mark right"><i class="fas fa-quote-right"></i></span>
                        </blockquote>
                    </div>

                    <!-- ===== USER REPUTATION DISPLAY ===== -->
                    <?php if ($user_rep): ?>
                        <div class="user-reputation-box">
                            <h4>🏆 Your Reputation</h4>
                            <div class="rep-stats">
                                <span class="rep-points"><?php echo $user_rep['points']; ?> pts</span>
                                <span class="rep-level">Level <?php echo $user_rep['level']; ?></span>
                                <span class="rep-streak">🔥 <?php echo $reading_streak; ?> day streak</span>
                            </div>
                            <?php if ($achievements): ?>
                                <div class="achievement-badges">
                                    <?php foreach ($achievements as $a): ?>
                                        <span class="achievement-badge" title="<?php echo ucfirst(str_replace('_', ' ', $a['achievement_type'])); ?>">
                                            🏆 <?php echo date('M j, Y', strtotime($a['unlocked_at'])); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== SKILLS ===== -->
        <div class="about-skills-section">
            <div class="section-header">
                <h2>What She <span class="rose-text">Offers</span></h2>
                <p>Angella's gifts and the heart behind her work.</p>
            </div>
            <div class="skills-grid">
                <div class="skill-card">
                    <div class="skill-icon"><i class="fas fa-pen-fancy"></i></div>
                    <h3>Writing</h3>
                    <p>Creative writing, poetry, Christian reflections, and personal narratives.</p>
                </div>
                <div class="skill-card">
                    <div class="skill-icon"><i class="fas fa-hands-praying"></i></div>
                    <h3>Encouragement</h3>
                    <p>Speaking, mentoring, and one-on-one sessions to help women find their purpose.</p>
                </div>
                <div class="skill-card">
                    <div class="skill-icon"><i class="fas fa-book-open"></i></div>
                    <h3>Author</h3>
                    <p>Author of "The Beautiful Broken Vessel" and several published poems.</p>
                </div>
                <div class="skill-card">
                    <div class="skill-icon"><i class="fas fa-users"></i></div>
                    <h3>Community</h3>
                    <p>Building a supportive community through Q&A, reflections, and shared stories.</p>
                </div>
            </div>
        </div>

        <!-- ===== CONTACT FORM ===== -->
        <div class="contact-section">
            <div class="section-header">
                <h2>Send a <span class="rose-text">Message</span></h2>
                <p>Have a question or want to connect? Angella would love to hear from you.</p>
            </div>
            <div class="contact-form-wrapper">
                <?php if (isset($error)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="POST" class="contact-form">
                    <div class="form-group">
                        <label for="name">Your Name</label>
                        <input type="text" id="name" name="name" placeholder="Enter your name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" rows="4" placeholder="Write your message here..." required></textarea>
                    </div>
                    <button type="submit" name="send_message" class="btn btn-primary btn-block">Send Message</button>
                </form>
            </div>
        </div>

        <!-- ===== NEWSLETTER ===== -->
        <div class="about-newsletter">
            <h3>Stay <span class="rose-text">Inspired</span></h3>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources.</p>
            <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
        </div>

        <!-- ===== CTA ===== -->
        <div class="about-cta">
            <h2>Let's <span class="rose-text">Connect</span></h2>
            <div class="cta-buttons">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-primary">
                    <i class="fas fa-book"></i> Read Books
                </a>
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">
                    <i class="fas fa-pen"></i> Read Poetry
                </a>
                <a href="<?php echo SITE_URL; ?>/contact.php" class="btn btn-secondary">
                    <i class="fas fa-envelope"></i> Contact
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// ===== THEME TOGGLE =====
const themeToggle = document.getElementById('themeToggle');
const currentTheme = localStorage.getItem('aboutTheme') || 'light';
if (currentTheme === 'dark') {
    document.body.classList.add('dark-mode');
    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
}

function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('aboutTheme', isDark ? 'dark' : 'light');
    themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
}

// ===== READING PROGRESS BAR =====
window.addEventListener('scroll', function() {
    const scrollTop = window.scrollY;
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    const scrollPercent = (scrollTop / docHeight) * 100;
    document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
});
</script>

<style>
/* ===== BRAND VARIABLES (Matches all other AngelWrites files) ===== */
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
h1, h2, h3, h4, h5 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
p { line-height:1.7; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS (Unified with all other AngelWrites pages) ===== */
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
.btn-block { width:100%; justify-content:center; }

/* ===== PAGE LAYOUT ===== */
.about-page { padding:40px 0 80px; }

/* ===== HERO ===== */
.about-hero { text-align:center; margin-bottom:48px; padding:20px 0; }
.about-hero h1 { font-size:3rem; margin-bottom:4px; }
.about-hero .subtitle { font-size:1.15rem; color:var(--text-light); font-weight:400; }
.hero-divider { width:60px; height:3px; background:var(--rose); margin:12px auto 0; border-radius:4px; }

/* ===== ABOUT GRID ===== */
.about-grid { display:grid; grid-template-columns:280px 1fr; gap:48px; align-items:start; margin-bottom:48px; max-width:1000px; margin-left:auto; margin-right:auto; }

/* ===== PHOTO ===== */
.about-photo-section { display:flex; flex-direction:column; align-items:center; }
.about-photo { width:100%; aspect-ratio:1/1; border-radius:50%; overflow:hidden; background:var(--vanilla); border:4px solid var(--rose); box-shadow:var(--shadow); }
.about-photo img { width:100%; height:100%; object-fit:cover; }
.about-photo-caption { margin-top:12px; color:var(--text-light); font-size:0.85rem; text-align:center; font-style:italic; }

/* ===== BIO ===== */
.about-bio-section h2 { font-size:2rem; margin-bottom:12px; }
.about-bio-section h3 { font-size:1.3rem; margin:24px 0 12px; }
.bio-text, .mission-text { font-size:1.05rem; line-height:1.8; color:var(--text); }
.about-bio-section strong { color:var(--rose-dark); }

/* ===== MISSION STATEMENT ===== */
.mission-statement { background:var(--vanilla); border-radius:16px; padding:24px 32px; margin:20px 0; text-align:center; border-left:4px solid var(--rose); box-shadow:var(--shadow); }
.mission-statement blockquote { font-family:'Playfair Display',Georgia,serif; font-size:1.2rem; font-style:italic; color:var(--dark); line-height:1.6; }
.mission-statement .quote-mark { color:var(--rose); font-size:1.2rem; margin:0 6px; }

/* ===== USER REPUTATION ===== */
.user-reputation-box { background:var(--card-bg); border-radius:16px; padding:20px; margin:20px 0; border:1px solid var(--border); box-shadow:var(--shadow); }
.user-reputation-box h4 { margin-bottom:8px; font-size:1.1rem; }
.rep-stats { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:6px; }
.rep-points, .rep-level, .rep-streak { font-weight:600; color:var(--text); font-size:0.95rem; }
.achievement-badges { display:flex; flex-wrap:wrap; gap:4px; }
.achievement-badge { background:var(--vanilla); padding:4px 12px; border-radius:20px; font-size:0.75rem; color:var(--text-light); border:1px solid var(--border); }

/* ===== SKILLS ===== */
.about-skills-section { margin-bottom:48px; }
.skills-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:24px; max-width:960px; margin:0 auto; }
.skill-card { background:var(--card-bg); border-radius:16px; padding:28px 20px; text-align:center; border:1px solid var(--border); box-shadow:var(--shadow); transition:all var(--transition); }
.skill-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color:var(--rose-light); }
.skill-icon { width:56px; height:56px; border-radius:50%; background:rgba(219,161,162,0.12); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; }
.skill-icon i { font-size:1.6rem; color:var(--rose); }
.skill-card h3 { font-size:1.05rem; margin-bottom:4px; }
.skill-card p { font-size:0.9rem; color:var(--text-light); line-height:1.5; margin:0; }

/* ===== CONTACT ===== */
.contact-section { max-width:600px; margin:0 auto 48px; }
.contact-form-wrapper { background:var(--card-bg); border-radius:20px; padding:32px; border:1px solid var(--border); box-shadow:var(--shadow); }
.contact-form .form-group { margin-bottom:16px; }
.contact-form label { display:block; font-weight:600; margin-bottom:6px; font-size:0.9rem; color:var(--text); }
.contact-form input, .contact-form textarea { width:100%; padding:12px 16px; border:1px solid var(--border); border-radius:12px; font-size:0.95rem; background:var(--input-bg); color:var(--text); transition:border-color 0.2s; }
.contact-form input:focus, .contact-form textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.contact-form textarea { resize:vertical; min-height:120px; font-family:'Inter',sans-serif; }

/* ===== NEWSLETTER ===== */
.about-newsletter { text-align:center; background:var(--vanilla); border-radius:20px; padding:36px; margin-bottom:32px; border:1px solid var(--rose-light); }
.about-newsletter h3 { font-size:1.4rem; margin-bottom:4px; }
.about-newsletter p { color:var(--text-light); margin-bottom:12px; }
.newsletter-form { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; }
.newsletter-form input { padding:10px 16px; border:1px solid var(--border); border-radius:50px; min-width:240px; background:var(--card-bg); color:var(--text); font-size:0.9rem; }
.newsletter-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.newsletter-form .btn { padding:10px 28px; }

/* ===== CTA ===== */
.about-cta { text-align:center; background:var(--fantasy); border-radius:20px; padding:48px; border:1px solid var(--rose-light); }
.about-cta h2 { font-size:2rem; margin-bottom:16px; }
.cta-buttons { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }

/* ===== ALERTS ===== */
.alert { padding:12px 16px; border-radius:12px; margin-bottom:16px; font-weight:500; }
.alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }

/* ===== RESPONSIVE ===== */
@media (max-width:992px) {
    .about-grid { grid-template-columns:1fr; gap:32px; }
    .about-photo { max-width:200px; margin:0 auto; }
    .about-photo-section { align-items:center; }
}
@media (max-width:768px) {
    .about-hero h1 { font-size:2.2rem; }
    .skills-grid { grid-template-columns:1fr 1fr; }
    .cta-buttons { flex-direction:column; align-items:center; }
    .cta-buttons .btn { width:100%; max-width:280px; }
    .newsletter-form { flex-direction:column; align-items:center; }
    .newsletter-form input { min-width:auto; width:100%; }
}
@media (max-width:480px) {
    .skills-grid { grid-template-columns:1fr; }
    .about-hero h1 { font-size:1.8rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>