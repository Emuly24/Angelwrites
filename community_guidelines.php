<?php
// D2: the safeguarding policy members see (PROGRAM_PLAN.md 2b, approved by the owner, with decisions 2, 4, 5, 13 and 15).
require_once 'includes/config.php';
require_once __DIR__ . '/includes/site_texts.php'; // page text can be changed on the site
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/components.php';
require_once 'includes/community_posts.php';
$pageTitle = 'How we look after each other';
require_once 'includes/header.php';
echo aw_cp_assets();
?>
<main class="cp" id="main">
<div class="container cp-narrow cp-guide">
    <header class="cp-head"><div>
        <p class="cp-eyebrow"><?php echo aw_text('communityguidelines.p1', 'Stories & Help', 'text'); ?></p>
        <h1><?php echo aw_text('communityguidelines.h11', 'How we look after each other', 'line'); ?></h1>
        <p><?php echo aw_text('communityguidelines.p2', 'Stories & Help is a place to share what you carry and to help one another. These are the promises that keep it safe.', 'text'); ?></p>
    </div></header>

    <?php echo aw_cp_crisis_block(); ?>

    <section class="cp-section">
        <h2><?php echo aw_text('communityguidelines.h21', 'Our promises to each other', 'line'); ?></h2>
        <ul>
            <li><?php echo aw_text('communityguidelines.li1', 'No blaming, criticism or judgement. Respond with love, respect, understanding and acceptance.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li2', 'Speak from your own experience. Say what helped you, not what someone else must do.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li3', 'Do not give medical or legal instructions. Encourage people to seek qualified help.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li4', 'What is shared here stays here. Do not copy someone\'s story elsewhere.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li5', 'Do not share anyone\'s full name, address, phone number or other private details.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li6', 'Stories & Help is for adults, 18 and over. Reading is open to members; sharing and replying ask you to confirm your age.', 'text'); ?></li>
        </ul>
    </section>

    <section class="cp-section">
        <h2><?php echo aw_text('communityguidelines.h22', 'Who can see what', 'line'); ?></h2>
        <p><?php echo aw_text('communityguidelines.p3', 'Using a pseudonym hides your name from other members. It does not hide it from the team, so we can keep everyone safe.', 'text'); ?></p>
        <table class="cp-table">
            <thead><tr><th scope="col">When you share</th><th scope="col">Other members see</th><th scope="col">The owner and admins see</th></tr></thead>
            <tbody>
                <tr><th scope="row">Public, with my name</th><td>Your post and your name</td><td>Everything</td></tr>
                <tr><th scope="row">Public, under a pseudonym</th><td>Your post and that post's pseudonym only</td><td>Your post, the pseudonym and your account</td></tr>
                <tr><th scope="row">Private, only the team</th><td>Nothing; they do not know it exists</td><td>Your post and your account</td></tr>
            </tbody>
        </table>
        <ul style="margin-top:12px">
            <li><?php echo aw_text('communityguidelines.li7', 'You choose a new pseudonym for each post. When you reply on your own post, you reply under that post\'s pseudonym.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li8', 'Moderators who are not admins help look after public posts, but they never see who is behind a pseudonym and never see private posts.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li9', 'Every time the owner or an admin looks at who wrote a pseudonymous post, or opens a private one, it is written to a log that cannot be changed.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li10', 'The team does not contact you outside the site about what you shared. Your identity is only ever shared outside the team if two of us agree that the law requires it or that a life is at risk.', 'text'); ?></li>
        </ul>
    </section>

    <section class="cp-section">
        <h2><?php echo aw_text('communityguidelines.h23', 'Gentle warnings and tender topics', 'line'); ?></h2>
        <ul>
            <li><?php echo aw_text('communityguidelines.li11', 'If a post mentions things like abuse, self-harm or death, the writer ticks a warning. Readers see the warning first and choose when to open the post.', 'text'); ?></li>
            <li>You can choose topics you would rather not see in <a href="<?php echo SITE_URL; ?>/my_stories.php#topics">My Stories &amp; Help</a>.</li>
            <li><?php echo aw_text('communityguidelines.li12', 'Posts about trauma, anxiety, depression, grief, shame and addiction are read by the team before others see them.', 'text'); ?></li>
        </ul>
    </section>

    <section class="cp-section">
        <h2><?php echo aw_text('communityguidelines.h24', 'If something does not feel kind or safe', 'line'); ?></h2>
        <ul>
            <li><?php echo aw_text('communityguidelines.li13', 'Every post and reply has a way to report it. The person will not know who reported them.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li14', 'The team reads every report. We start with a private, gentle word; we remove what harms others; we pause posting for repeated or serious harm.', 'text'); ?></li>
            <li>You will hear what we decided. If your own post is removed and you think it was a mistake, tell us through <a href="<?php echo SITE_URL; ?>/contact.php">Contact</a> and a different person will look again.</li>
            <li><?php echo aw_text('communityguidelines.li15', 'If you are worried someone is in danger, report the post and choose "Talks about self-harm or someone in danger". The team is told at once.', 'text'); ?></li>
        </ul>
    </section>

    <section class="cp-section">
        <h2><?php echo aw_text('communityguidelines.h25', 'Your words are yours', 'line'); ?></h2>
        <ul>
            <li><?php echo aw_text('communityguidelines.li16', 'You can edit or delete what you shared at any time.', 'line'); ?></li>
            <li><?php echo aw_text('communityguidelines.li17', 'Deleted and removed posts are kept hidden for 90 days (so a decision can be looked at again) and then erased.', 'text'); ?></li>
            <li><?php echo aw_text('communityguidelines.li18', 'Reports and the team\'s log are kept for two years.', 'line'); ?></li>
        </ul>
    </section>
    <p><a class="cp-btn" href="<?php echo SITE_URL; ?>/stories.php">Go to Stories &amp; Help</a></p>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
