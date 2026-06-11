<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== FETCH ABOUT CONTENT FROM DATABASE (if exists) ====
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'about_content'");
$stmt->execute();
$about_content = $stmt->fetchColumn();

// If no content in database, use static default
if (!$about_content) {
    $about_content = [
        'bio' => "Angella Bottoman is a passionate writer based in Malawi. She believes that there are treasures stored in each and every person — and that something beautiful can come out of pain once it is placed in the hands of God.",
        'mission' => "To write words that heal, inspire, and transform — and to create a community where women can find encouragement, purpose, and a safe space to share their own stories.",
        'quote' => "There is something beautiful that can come out of pain once it is handed in the hands of God."
    ];
} else {
    $about_content = json_decode($about_content, true) ?: [];
}

$pageTitle = 'About Angella Bottoman';
?>
<?php require_once 'includes/header.php'; ?>

<div class="about-page">
    <div class="container">
        <!-- Page Header -->
        <div class="about-header">
            <h1>About Angella</h1>
            <p>Writer · Speaker · Encourager</p>
        </div>

        <!-- Main Content Layout -->
        <div class="about-content">
            <!-- Photo Section -->
            <div class="about-photo-section">
                <div class="about-photo">
                    <img src="<?php echo SITE_URL; ?>/assets/images/angella.jpg" alt="Angella Bottoman" class="about-photo-img">
                </div>
                <div class="about-photo-caption">
                    <p>Angella Bottoman — passionate writer.</p>
                </div>
            </div>

            <!-- Bio Section -->
            <div class="about-bio-section">
                <h2>Her Story</h2>
                <p><?php echo htmlspecialchars($about_content['bio'] ?? ''); ?></p>
                <p><strong>The Beautiful Broken Vessel</strong> was born from a desire to show God's transforming power over the body, soul, and spirit. Angella loves to encourage others through her writings and works, intersecting faith with the real world.</p>
                <p>She is an emerging author with one book published and several poems to her name. Her writing reflects a deep faith, a love for storytelling, and a commitment to helping others find hope in difficult places.</p>

                <h3>Her Mission</h3>
                <p><?php echo htmlspecialchars($about_content['mission'] ?? ''); ?></p>

                <div class="mission-statement">
                    <blockquote>
                        <i class="fas fa-quote-left" style="color: var(--rose);"></i>
                        <?php echo htmlspecialchars($about_content['quote'] ?? ''); ?>
                        <i class="fas fa-quote-right" style="color: var(--rose);"></i>
                    </blockquote>
                </div>
            </div>
        </div>

        <!-- Skills & Services -->
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

        <!-- Newsletter Signup -->
        <div class="about-newsletter">
            <h3>Stay Inspired</h3>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources directly to your inbox.</p>
            <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="newsletter-form">
                <input type="email" name="email" placeholder="Your email address" required>
                <input type="hidden" name="redirect" value="/about.php">
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
            <small>No spam. Unsubscribe anytime.</small>
        </div>

        <!-- Call to Action -->
        <div class="about-cta">
            <h2>Let's Connect</h2>
            <p>Whether you'd like to read her writings, book a session, or simply say hello — Angella would love to hear from you.</p>
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

<style>
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
.about-skills-section { margin-bottom: 48px; }
.about-skills-section h2 { text-align: center; font-size: 1.8rem; margin-bottom: 24px; }
.skills-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; max-width: 1000px; margin: 0 auto; padding: 0 20px; }
.skill-card { background: var(--card-bg); border-radius: 12px; padding: 24px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform var(--transition), box-shadow var(--transition); }
.skill-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.skill-card i { font-size: 2.2rem; color: var(--rose); margin-bottom: 8px; }
.skill-card h3 { font-size: 1.05rem; margin-bottom: 4px; }
.skill-card p { font-size: 0.9rem; color: var(--text-light); line-height: 1.5; }
.about-newsletter { text-align: center; background: var(--vanilla); border-radius: 12px; padding: 32px; margin-bottom: 32px; }
.about-newsletter .newsletter-form { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 12px; }
.about-newsletter .newsletter-form input { padding: 10px 16px; border: 1px solid var(--border); border-radius: 8px; min-width: 250px; background: var(--input-bg); color: var(--text); }
.about-newsletter .newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.about-newsletter .btn { padding: 10px 24px; border-radius: 30px; }
.about-cta { text-align: center; background: var(--vanilla); border-radius: 12px; padding: 40px; }
.cta-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
@media (max-width: 768px) { .about-content { grid-template-columns: 1fr; gap: 24px; } .about-photo { max-width: 200px; } .about-skills-section .skills-grid { grid-template-columns: 1fr 1fr; } .cta-buttons { flex-direction: column; align-items: center; } .cta-buttons .btn { width: 100%; max-width: 280px; } }
</style>

<?php require_once 'includes/footer.php'; ?>