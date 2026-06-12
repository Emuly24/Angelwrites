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

        <!-- Page Header -->
        <div class="about-header">
            <h1>About Angella</h1>
            <p>Writer · Speaker · Encourager</p>
        </div>

        <!-- Main Content -->
        <div class="about-content">
            <div class="about-photo-section">
                <div class="about-photo">
                    <?php if ($about_content['photo'] ?? ''): ?>
                        <img src="<?php echo SITE_URL . '/' . $about_content['photo']; ?>" alt="Angella Bottoman">
                    <?php else: ?>
                        <img src="<?php echo SITE_URL; ?>/assets/images/angella.jpg" alt="Angella Bottoman">
                    <?php endif; ?>
                </div>
                <div class="about-photo-caption">
                    <p>Angella Bottoman — passionate writer.</p>
                </div>
            </div>

            <div class="about-bio-section">
                <h2>Her Story</h2>
                <p><?php echo htmlspecialchars($about_content['bio'] ?? ''); ?></p>
                <p><strong>The Beautiful Broken Vessel</strong> was born from a desire to show God's transforming power over the body, soul, and spirit.</p>
                
                <h3>Her Mission</h3>
                <p><?php echo htmlspecialchars($about_content['mission'] ?? ''); ?></p>
                
                <div class="mission-statement">
                    <blockquote>
                        <i class="fas fa-quote-left" style="color: var(--rose);"></i>
                        <?php echo htmlspecialchars($about_content['quote'] ?? ''); ?>
                        <i class="fas fa-quote-right" style="color: var(--rose);"></i>
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

        <!-- Skills -->
        <div class="about-skills-section">
            <h2>What She Offers</h2>
            <div class="skills-grid">
                <div class="skill-card">
                    <i class="fas fa-pen-fancy"></i>
                    <h3>Writing</h3>
                    <p>Creative writing, poetry, Christian reflections, and personal narratives.</p>
                </div>
                <div class="skill-card">
                    <i class="fas fa-hands-praying"></i>
                    <h3>Encouragement</h3>
                    <p>Speaking, mentoring, and one-on-one sessions to help women find their purpose.</p>
                </div>
                <div class="skill-card">
                    <i class="fas fa-book-open"></i>
                    <h3>Author</h3>
                    <p>Author of "The Beautiful Broken Vessel" and several published poems.</p>
                </div>
                <div class="skill-card">
                    <i class="fas fa-users"></i>
                    <h3>Community</h3>
                    <p>Building a supportive community through Q&A, reflections, and shared stories.</p>
                </div>
            </div>
        </div>

        <!-- Contact Form (Simple – No Camera) -->
        <div class="contact-section">
            <h2>Send a Message</h2>
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

        <!-- Newsletter -->
        <div class="about-newsletter">
            <h3>Stay Inspired</h3>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources.</p>
            <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
        </div>

        <!-- CTA -->
        <div class="about-cta">
            <h2>Let's Connect</h2>
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

.about-page { padding: 32px 0 60px; }
.about-header { text-align: center; margin-bottom: 40px; }
.about-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.about-header p { color: var(--text-light); font-size: 1.1rem; }
.about-content { display: grid; grid-template-columns: 1fr 2fr; gap: 40px; margin-bottom: 48px; }
.about-photo-section { display: flex; flex-direction: column; align-items: center; }
.about-photo { width: 100%; max-width: 280px; aspect-ratio: 1/1; border-radius: 50%; overflow: hidden; background: var(--vanilla); border: 4px solid var(--rose); box-shadow: var(--shadow); display: flex; align-items: center; justify-content: center; }
.about-photo img { width: 100%; height: 100%; object-fit: cover; }
.about-photo-caption { margin-top: 12px; text-align: center; color: var(--text-light); font-size: 0.9rem; }
.about-bio-section h2 { font-size: 1.8rem; margin-bottom: 16px; }
.about-bio-section h3 { font-size: 1.3rem; margin: 24px 0 12px; }
.about-bio-section p { line-height: 1.8; color: var(--text); margin-bottom: 12px; }
.mission-statement { background: var(--vanilla); border-radius: 12px; padding: 24px; margin: 16px 0; text-align: center; border-left: 4px solid var(--rose); }
.mission-statement blockquote { font-size: 1.15rem; font-style: italic; color: var(--text); line-height: 1.6; }
.mission-statement i { font-size: 1.2rem; margin: 0 6px; }

.user-reputation-box { background: var(--card-bg); border-radius: 12px; padding: 16px; margin: 16px 0; border: 1px solid var(--border); }
.rep-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 8px; }
.rep-points, .rep-level, .rep-streak { font-weight: 600; color: var(--text); }
.achievement-badges { display: flex; flex-wrap: wrap; gap: 4px; }
.achievement-badge { background: var(--vanilla); padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; color: var(--text-light); }

.about-skills-section { margin-bottom: 48px; }
.about-skills-section h2 { text-align: center; font-size: 1.8rem; margin-bottom: 24px; }
.skills-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; max-width: 1000px; margin: 0 auto; padding: 0 20px; }
.skill-card { background: var(--card-bg); border-radius: 12px; padding: 24px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.2s, box-shadow 0.2s; }
.skill-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.skill-card i { font-size: 2.2rem; color: var(--rose); margin-bottom: 8px; }
.skill-card h3 { font-size: 1.05rem; margin-bottom: 4px; }
.skill-card p { font-size: 0.9rem; color: var(--text-light); line-height: 1.5; }

.contact-section { max-width: 600px; margin: 0 auto 48px; }
.contact-form-wrapper { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.contact-form .form-group { margin-bottom: 16px; }
.contact-form label { display: block; font-weight: 600; margin-bottom: 6px; }
.contact-form input, .contact-form textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text); }
.contact-form input:focus, .contact-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.contact-form textarea { resize: vertical; min-height: 120px; }
.contact-form .btn-block { width: 100%; padding: 14px; font-size: 1.05rem; justify-content: center; }

.about-newsletter { text-align: center; background: var(--vanilla); border-radius: 12px; padding: 32px; margin-bottom: 32px; }
.about-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 12px; }
.about-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.about-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.about-newsletter .btn { padding: 10px 24px; border-radius: 30px; }

.about-cta { text-align: center; background: var(--vanilla); border-radius: 12px; padding: 40px; }
.cta-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

@media (max-width: 768px) {
    .about-content { grid-template-columns: 1fr; gap: 24px; }
    .about-photo { max-width: 200px; }
    .about-skills-section .skills-grid { grid-template-columns: 1fr 1fr; }
    .cta-buttons { flex-direction: column; align-items: center; }
    .cta-buttons .btn { width: 100%; max-width: 280px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>