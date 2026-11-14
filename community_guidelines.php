<?php
// D2: the safeguarding policy members see (PROGRAM_PLAN.md 2b, approved by the owner, with decisions 2, 4, 5, 13 and 15).
require_once 'includes/config.php';
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
        <p class="cp-eyebrow">Stories &amp; Help</p>
        <h1>How we look after each other</h1>
        <p>Stories &amp; Help is a place to share what you carry and to help one another. These are the promises that keep it safe.</p>
    </div></header>

    <?php echo aw_cp_crisis_block(); ?>

    <section class="cp-section">
        <h2>Our promises to each other</h2>
        <ul>
            <li>No blaming, criticism or judgement. Respond with love, respect, understanding and acceptance.</li>
            <li>Speak from your own experience. Say what helped you, not what someone else must do.</li>
            <li>Do not give medical or legal instructions. Encourage people to seek qualified help.</li>
            <li>What is shared here stays here. Do not copy someone's story elsewhere.</li>
            <li>Do not share anyone's full name, address, phone number or other private details.</li>
            <li>Stories &amp; Help is for adults, 18 and over. Reading is open to members; sharing and replying ask you to confirm your age.</li>
        </ul>
    </section>

    <section class="cp-section">
        <h2>Who can see what</h2>
        <p>Using a pseudonym hides your name from other members. It does not hide it from the team, so we can keep everyone safe.</p>
        <table class="cp-table">
            <thead><tr><th scope="col">When you share</th><th scope="col">Other members see</th><th scope="col">The owner and admins see</th></tr></thead>
            <tbody>
                <tr><th scope="row">Public, with my name</th><td>Your post and your name</td><td>Everything</td></tr>
                <tr><th scope="row">Public, under a pseudonym</th><td>Your post and that post's pseudonym only</td><td>Your post, the pseudonym and your account</td></tr>
                <tr><th scope="row">Private, only the team</th><td>Nothing; they do not know it exists</td><td>Your post and your account</td></tr>
            </tbody>
        </table>
        <ul style="margin-top:12px">
            <li>You choose a new pseudonym for each post. When you reply on your own post, you reply under that post's pseudonym.</li>
            <li>Moderators who are not admins help look after public posts, but they never see who is behind a pseudonym and never see private posts.</li>
            <li>Every time the owner or an admin looks at who wrote a pseudonymous post, or opens a private one, it is written to a log that cannot be changed.</li>
            <li>The team does not contact you outside the site about what you shared. Your identity is only ever shared outside the team if two of us agree that the law requires it or that a life is at risk.</li>
        </ul>
    </section>

    <section class="cp-section">
        <h2>Gentle warnings and tender topics</h2>
        <ul>
            <li>If a post mentions things like abuse, self-harm or death, the writer ticks a warning. Readers see the warning first and choose when to open the post.</li>
            <li>You can choose topics you would rather not see in <a href="<?php echo SITE_URL; ?>/my_stories.php#topics">My Stories &amp; Help</a>.</li>
            <li>Posts about trauma, anxiety, depression, grief, shame and addiction are read by the team before others see them.</li>
        </ul>
    </section>

    <section class="cp-section">
        <h2>If something does not feel kind or safe</h2>
        <ul>
            <li>Every post and reply has a way to report it. The person will not know who reported them.</li>
            <li>The team reads every report. We start with a private, gentle word; we remove what harms others; we pause posting for repeated or serious harm.</li>
            <li>You will hear what we decided. If your own post is removed and you think it was a mistake, tell us through <a href="<?php echo SITE_URL; ?>/contact.php">Contact</a> and a different person will look again.</li>
            <li>If you are worried someone is in danger, report the post and choose "Talks about self-harm or someone in danger". The team is told at once.</li>
        </ul>
    </section>

    <section class="cp-section">
        <h2>Your words are yours</h2>
        <ul>
            <li>You can edit or delete what you shared at any time.</li>
            <li>Deleted and removed posts are kept hidden for 90 days (so a decision can be looked at again) and then erased.</li>
            <li>Reports and the team's log are kept for two years.</li>
        </ul>
    </section>
    <p><a class="cp-btn" href="<?php echo SITE_URL; ?>/stories.php">Go to Stories &amp; Help</a></p>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
