<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// You can optionally fetch stats or content from the database
// For now, we'll use static content that Angella can update later.

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
                    <!-- Replace with actual photo of Angella -->
                    <div class="photo-placeholder">
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
                <div class="about-photo-caption">
                    <p>Angella Bottoman — passionate writer based in Malawi.</p>
                </div>
            </div>

            <!-- Bio Section -->
            <div class="about-bio-section">
                <h2>Her Story</h2>
                <p>
                    Angella Bottoman is a passionate writer based in Malawi. She believes that there are treasures stored in each and every person — and that something beautiful can come out of pain once it is placed in the hands of God.
                </p>
                <p>
                    <strong>The Beautiful Broken Vessel</strong> was born from a desire to show God's transforming power over the body, soul, and spirit. Angella loves to encourage others through her writings and works, intersecting faith with the real world.
                </p>
                <p>
                    She is an emerging author with one book published and several poems to her name. Her writing reflects a deep faith, a love for storytelling, and a commitment to helping others find hope in difficult places.
                </p>

                <h3>Her Mission</h3>
                <p>
                    To write words that heal, inspire, and transform — and to create a community where women can find encouragement, purpose, and a safe space to share their own stories.
                </p>

                <div class="mission-statement">
                    <blockquote>
                        <i class="fas fa-quote-left" style="color: var(--rose);"></i>
                        There is something beautiful that can come out of pain once it is handed in the hands of God.
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

        <!-- Call to Action -->
        <div class="about-cta">
            <h2>Let's Connect</h2>
            <p>Whether you'd like to read her writings, book a session, or simply say hello — Angella would love to hear from you.</p>
            <div class="cta-buttons">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-primary">
                    <i class="fas fa-book"></i> Read Books
                </a>
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">
                    <i class="fas fa-feather-alt"></i> Read Poetry
                </a>
                <a href="<?php echo SITE_URL; ?>/contact.php" class="btn btn-secondary">
                    <i class="fas fa-envelope"></i> Contact
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.about-page {
    padding: 32px 0 60px;
}

.about-header {
    text-align: center;
    margin-bottom: 40px;
}
.about-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.about-header p {
    color: var(--text-light);
    font-size: 1.1rem;
}

/* ===== Content Layout ===== */
.about-content {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 40px;
    margin-bottom: 48px;
}

/* ===== Photo Section ===== */
.about-photo-section {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.about-photo {
    width: 100%;
    max-width: 280px;
    aspect-ratio: 1 / 1;
    border-radius: 50%;
    overflow: hidden;
    background: var(--vanilla);
    border: 4px solid var(--rose);
    box-shadow: var(--shadow);
    display: flex;
    align-items: center;
    justify-content: center;
}
.photo-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 6rem;
    color: var(--rose);
}
.about-photo-caption {
    margin-top: 12px;
    text-align: center;
    color: var(--text-light);
    font-size: 0.9rem;
}

/* ===== Bio Section ===== */
.about-bio-section h2 {
    font-size: 1.8rem;
    margin-bottom: 16px;
}
.about-bio-section h3 {
    font-size: 1.3rem;
    margin: 24px 0 12px;
}
.about-bio-section p {
    line-height: 1.8;
    color: var(--text);
    margin-bottom: 12px;
}

.mission-statement {
    background: var(--vanilla);
    border-radius: 12px;
    padding: 24px;
    margin: 16px 0;
    text-align: center;
    border-left: 4px solid var(--rose);
}
.mission-statement blockquote {
    font-size: 1.15rem;
    font-style: italic;
    color: var(--text);
    line-height: 1.6;
}
.mission-statement i {
    font-size: 1.2rem;
    margin: 0 6px;
}

/* ===== Skills Section ===== */
.about-skills-section {
    margin-bottom: 48px;
}
.about-skills-section h2 {
    text-align: center;
    font-size: 1.8rem;
    margin-bottom: 24px;
}
.skills-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
}
.skill-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform var(--transition), box-shadow var(--transition);
}
.skill-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}
.skill-card i {
    font-size: 2.2rem;
    color: var(--rose);
    margin-bottom: 8px;
}
.skill-card h3 {
    font-size: 1.05rem;
    margin-bottom: 4px;
}
.skill-card p {
    font-size: 0.9rem;
    color: var(--text-light);
    line-height: 1.5;
}

/* ===== CTA Section ===== */
.about-cta {
    text-align: center;
    background: var(--vanilla);
    border-radius: 12px;
    padding: 40px;
}
.about-cta h2 {
    font-size: 1.8rem;
    margin-bottom: 8px;
}
.about-cta p {
    color: var(--text-light);
    margin-bottom: 20px;
    font-size: 1.05rem;
}
.cta-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .about-content {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .about-photo-section {
        order: -1;
    }
    .about-photo {
        max-width: 200px;
    }
    .skill-card {
        padding: 16px;
    }
    .about-cta {
        padding: 24px;
    }
    .cta-buttons {
        flex-direction: column;
        align-items: center;
    }
    .cta-buttons .btn {
        width: 100%;
        max-width: 280px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>