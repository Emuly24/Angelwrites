<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== ABOUT CONTENT (settings.about_content, JSON; the defaults below when it is not set) =====
$about_defaults = [
    'bio' => "Angella Bottoman is a passionate writer based in Malawi. She believes that there are treasures stored in each and every person — and that something beautiful can come out of pain once it is placed in the hands of God.",
    'mission' => "To write words that heal, inspire, and transform — and to create a community where women can find encouragement, purpose, and a safe space to share their own stories.",
    'quote' => "There is something beautiful that can come out of pain once it is handed in the hands of God.",
    'photo' => ''
];
$about_content = $about_defaults;
try {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'about_content'");
    $stmt->execute();
    $about_content_json = $stmt->fetchColumn();
    if ($about_content_json) {
        $decoded = json_decode($about_content_json, true);
        if (is_array($decoded)) $about_content = $decoded + ['bio' => '', 'mission' => '', 'quote' => '', 'photo' => ''];
    }
} catch (Throwable $e) {
    error_log('about content: ' . $e->getMessage());
}

// Q39: this page had its own contact form (name, email, message) with no captcha or rate limit, a
// second, weaker way into the same inbox as contact.php. It now links to the contact page instead.

// F221: the About page used to load the visitor's reputation, achievements and reading streak
// (unrelated to the page; a fatal for members on databases without those tables). Removed.

// What is on the site to read, counted from the published content itself (no drafts).
function ab_count(PDO $db, $sql) {
    try {
        return (int)$db->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        error_log('about count: ' . $e->getMessage());
        return 0;
    }
}
$ab_works = [
    ['n' => ab_count($db, "SELECT COUNT(*) FROM poems WHERE id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')"),
     'one' => 'poem', 'many' => 'poems', 'icon' => 'fa-feather-pointed', 'href' => '/poetry.php'],
    ['n' => ab_count($db, "SELECT COUNT(*) FROM books"),
     'one' => 'book', 'many' => 'books', 'icon' => 'fa-book-open', 'href' => '/books.php'],
    ['n' => ab_count($db, "SELECT COUNT(*) FROM blog_posts WHERE status = 'published'"),
     'one' => 'reflection', 'many' => 'reflections', 'icon' => 'fa-seedling', 'href' => '/reflections.php'],
];
$ab_works = array_values(array_filter($ab_works, function ($w) { return $w['n'] > 0; }));

$photo = trim((string)($about_content['photo'] ?? ''));
$photo_url = $photo !== '' ? SITE_URL . '/' . ltrim($photo, '/') : SITE_URL . '/assets/images/angella.jpg';
$quote = trim((string)($about_content['quote'] ?? ''));

$pageTitle = 'About Angella Bottoman';
require_once 'includes/header.php';
require_once 'includes/subscribe_control.php';
$ab_sub_html = aw_subscribe_control($db, 'section');
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-about.css?v=1">

<div class="ab-progress" id="abProgress" aria-hidden="true"></div>

<div class="ab">

    <!-- ===== HERO ===== -->
    <section class="ab-hero" aria-labelledby="abTitle">
        <div class="ab-aurora" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="ab-hero-in">
            <div class="ab-hero-text">
                <p class="ab-eyebrow ab-rise" style="--d:0">About</p>
                <h1 id="abTitle" class="ab-rise" style="--d:1">Angella <span class="ab-script">Bottoman</span></h1>
                <p class="ab-roles ab-rise" style="--d:2">
                    <span>Writer</span><span aria-hidden="true">·</span><span>Speaker</span><span aria-hidden="true">·</span><span>Encourager</span>
                </p>
                <p class="ab-lead ab-rise" style="--d:3">Words that heal, inspire and transform, written from Malawi for every woman who needs to hear that her story still matters.</p>
                <div class="ab-actions ab-rise" style="--d:4">
                    <a class="ab-btn ab-btn-solid" href="#abStory">Read her story <i class="fas fa-arrow-down" aria-hidden="true"></i></a>
                    <a class="ab-btn ab-btn-ghost" href="<?php echo SITE_URL; ?>/contact.php"><i class="fas fa-envelope" aria-hidden="true"></i> Send a message</a>
                </div>
            </div>

            <figure class="ab-portrait ab-rise" style="--d:2">
                <div class="ab-ring" aria-hidden="true"></div>
                <div class="ab-frame">
                    <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="Portrait of Angella Bottoman" width="480" height="600" decoding="async" fetchpriority="high">
                </div>
                <figcaption class="ab-chip"><i class="fas fa-location-dot" aria-hidden="true"></i> <span>Writing from Malawi</span></figcaption>
            </figure>
        </div>
    </section>

    <!-- ===== STORY ===== -->
    <section class="ab-section ab-story" id="abStory" aria-labelledby="abStoryTitle">
        <div class="ab-wrap ab-story-grid">
            <header class="ab-head ab-reveal">
                <p class="ab-kicker">Her story</p>
                <h2 id="abStoryTitle">Treasures stored in every person</h2>
            </header>
            <div class="ab-story-body ab-reveal">
                <p class="ab-big"><?php echo nl2br(htmlspecialchars((string)($about_content['bio'] ?? ''))); ?></p>
                <p><strong>The Beautiful Broken Vessel</strong> was born from a desire to show God's transforming power over the body, soul, and spirit.</p>
            </div>
        </div>
    </section>

    <?php if ($quote !== ''): ?>
    <!-- ===== QUOTE ===== -->
    <section class="ab-quote-band" aria-label="In Angella's words">
        <figure class="ab-wrap ab-quote ab-reveal">
            <span class="ab-qmark" aria-hidden="true">&ldquo;</span>
            <blockquote><p><?php echo htmlspecialchars($quote); ?></p></blockquote>
            <figcaption>— Angella Bottoman</figcaption>
        </figure>
    </section>
    <?php endif; ?>

    <!-- ===== MISSION ===== -->
    <section class="ab-section" aria-labelledby="abMissionTitle">
        <div class="ab-wrap">
            <div class="ab-mission ab-reveal">
                <div class="ab-mission-icon" aria-hidden="true"><i class="fas fa-hand-holding-heart"></i></div>
                <div>
                    <p class="ab-kicker">Her mission</p>
                    <h2 id="abMissionTitle" class="ab-mission-text"><?php echo htmlspecialchars((string)($about_content['mission'] ?? '')); ?></h2>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== OFFERS ===== -->
    <section class="ab-section" aria-labelledby="abOffersTitle">
        <div class="ab-wrap">
            <header class="ab-head ab-head-center ab-reveal">
                <p class="ab-kicker">What she offers</p>
                <h2 id="abOffersTitle">The gifts behind the work</h2>
            </header>
            <ul class="ab-cards" role="list">
                <li class="ab-card ab-reveal" style="--d:0">
                    <span class="ab-card-icon" aria-hidden="true"><i class="fas fa-pen-fancy"></i></span>
                    <h3>Writing</h3>
                    <p>Creative writing, poetry, Christian reflections, and personal narratives.</p>
                </li>
                <li class="ab-card ab-reveal" style="--d:1">
                    <span class="ab-card-icon" aria-hidden="true"><i class="fas fa-hands-praying"></i></span>
                    <h3>Encouragement</h3>
                    <p>Speaking, mentoring, and one-on-one sessions to help women find their purpose.</p>
                </li>
                <li class="ab-card ab-reveal" style="--d:2">
                    <span class="ab-card-icon" aria-hidden="true"><i class="fas fa-book-open"></i></span>
                    <h3>Author</h3>
                    <p>Author of &ldquo;The Beautiful Broken Vessel&rdquo; and several published poems.</p>
                </li>
                <li class="ab-card ab-reveal" style="--d:3">
                    <span class="ab-card-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                    <h3>Community</h3>
                    <p>Building a supportive community through Q&amp;A, reflections, and shared stories.</p>
                </li>
            </ul>
        </div>
    </section>

    <?php if ($ab_works): ?>
    <!-- ===== ON THE SITE ===== -->
    <section class="ab-section ab-works-section" aria-labelledby="abWorksTitle">
        <div class="ab-wrap">
            <header class="ab-head ab-head-center ab-reveal">
                <p class="ab-kicker">On AngelWrites</p>
                <h2 id="abWorksTitle">Something to read today</h2>
            </header>
            <ul class="ab-works" role="list">
                <?php foreach ($ab_works as $i => $w): ?>
                <li class="ab-reveal" style="--d:<?php echo (int)$i; ?>">
                    <a class="ab-work" href="<?php echo SITE_URL . $w['href']; ?>">
                        <i class="fas <?php echo $w['icon']; ?>" aria-hidden="true"></i>
                        <span class="ab-work-n" data-count="<?php echo (int)$w['n']; ?>"><?php echo number_format($w['n']); ?></span>
                        <span class="ab-work-l"><?php echo $w['n'] === 1 ? $w['one'] : $w['many']; ?></span>
                        <span class="ab-work-go">Explore <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($ab_sub_html !== ''): ?>
    <!-- ===== NEWSLETTER (the shared control; hidden for members who are subscribed) ===== -->
    <section class="ab-section" aria-label="Newsletter">
        <div class="ab-wrap">
            <div class="ab-news ab-reveal">
                <?php echo aw_subscribe_assets(); echo $ab_sub_html; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== CONNECT ===== -->
    <section class="ab-section ab-last" aria-labelledby="abConnectTitle">
        <div class="ab-wrap">
            <div class="ab-connect ab-reveal">
                <div class="ab-aurora ab-aurora-soft" aria-hidden="true"><span></span><span></span></div>
                <h2 id="abConnectTitle">Let's connect</h2>
                <p>Read her words, or write to her. Angella would love to hear from you.</p>
                <div class="ab-actions ab-actions-center">
                    <a class="ab-btn ab-btn-solid" href="<?php echo SITE_URL; ?>/books.php"><i class="fas fa-book" aria-hidden="true"></i> Read books</a>
                    <a class="ab-btn ab-btn-ghost" href="<?php echo SITE_URL; ?>/poetry.php"><i class="fas fa-feather-pointed" aria-hidden="true"></i> Read poetry</a>
                    <a class="ab-btn ab-btn-ghost" href="<?php echo SITE_URL; ?>/contact.php"><i class="fas fa-envelope" aria-hidden="true"></i> Contact</a>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="<?php echo SITE_URL; ?>/assets/js/aw-about.js?v=1" defer></script>

<?php require_once 'includes/footer.php'; ?>
