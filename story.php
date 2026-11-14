<?php
// D2: one Stories & Help post with its replies (PROGRAM_PLAN.md Phase 3).
// Every change is a POST with the CSRF token; then the page redirects (no resubmits on reload).
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/saves.php'; // F2: the Save control
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/community_posts.php';
require_once 'includes/moderation.php';
require_once 'includes/messaging.php'; // I2

aw_cp_tables($db);
$id = (int)($_GET['id'] ?? 0);
$post = $id ? aw_cp_get($db, $id) : null;
$me = aw_cp_me();

if (!$post || (!aw_cp_enabled() && !aw_cp_staff_mod()) || !aw_cp_can_view($post)) {
    // the same answer for "does not exist" and "not yours to see", so nothing about private posts leaks
    http_response_code(404);
    $pageTitle = 'Not found';
    require 'includes/header.php';
    echo '<div class="container" style="padding:60px 0">' . aw_empty('This post is not available', 'It may have been removed, or it may be private.', [SITE_URL . '/stories.php', 'Back to Stories & Help'], 'fa-seedling') . '</div>';
    require 'includes/footer.php';
    exit;
}
$sensitive = aw_cp_is_sensitive($post['category']);
if ($sensitive && !isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/story.php?id=' . $id)); exit; }
$isAuthor = $me && (int)$post['author_id'] === $me;
$self = SITE_URL . '/story.php?id=' . $id;

// Every look at a private (team-only) post by the team is written to the audit log (2b, staff conduct).
if ($post['visibility'] === 'team' && !$isAuthor && aw_cp_staff_full() && $_SERVER['REQUEST_METHOD'] === 'GET') aw_audit('private_post_viewed', 'community_post', $id, '');

$errors = [];
$revealed = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/story.php?id=' . $id)); exit; }
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'reply') {
            $r = aw_cp_add_reply($db, $post, ['body' => $_POST['body'] ?? '', 'mode' => $_POST['mode'] ?? 'named', 'pseudonym' => $_POST['pseudonym'] ?? '', 'age_ok' => !empty($_POST['age_ok'])]);
            if ($r['ok']) { header('Location: ' . $self . '&msg=replied#reply-' . $r['id']); exit; }
            $errors = $r['errors'];
        } elseif ($act === 'thank') {
            aw_cp_thank($db, (int)($_POST['reply_id'] ?? 0));
            header('Location: ' . $self . '#reply-' . (int)($_POST['reply_id'] ?? 0)); exit;
        } elseif ($act === 'delete_reply') {
            aw_cp_delete_own($db, 'reply', (int)($_POST['reply_id'] ?? 0));
            header('Location: ' . $self . '&msg=reply_deleted#replies'); exit;
        } elseif ($act === 'delete_post' && $isAuthor) {
            aw_cp_delete_own($db, 'post', $id);
            header('Location: ' . SITE_URL . '/my_stories.php?msg=deleted'); exit;
        } elseif ($act === 'review' && aw_cp_staff_mod()) {
            aw_cp_review($db, $id, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''));
            header('Location: ' . $self . '&msg=reviewed'); exit;
        } elseif ($act === 'reveal' && aw_cp_staff_full()) {
            $revealed = aw_cp_reveal_identity($db, $id, (int)($_POST['reply_id'] ?? 0));
            $revealedFor = (int)($_POST['reply_id'] ?? 0);
        }
    }
}

$replies = aw_cp_replies($db, $post);
$thanked = [];
$thanksCount = [];
if ($replies) {
    $ids = implode(',', array_map('intval', array_column($replies, 'id')));
    try {
        if ($me) foreach ($db->query("SELECT reply_id FROM community_thanks WHERE user_id = $me AND reply_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $t) $thanked[(int)$t] = true;
        foreach ($db->query("SELECT reply_id, COUNT(*) FROM community_thanks WHERE reply_id IN ($ids) GROUP BY reply_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $thanksCount[(int)$k] = (int)$v;
    } catch (Throwable $e) { error_log('story thanks: ' . $e->getMessage()); }
}
$warn = aw_cp_warning_list($post['warnings']);
$member = $me ? aw_cp_member($db, $me) : null;
$canReply = $me && $post['status'] === 'published' && ($post['visibility'] !== 'team' || $isAuthor || aw_cp_staff_full());
$helpers = ($post['type'] === 'help' && $me && $post['visibility'] !== 'team') ? aw_cp_helpers($db, $post['category'], (int)$post['author_id']) : [];
$msg = ['replied' => 'Your reply is shared. Thank you for your kindness.', 'reply_deleted' => 'Your reply was deleted.', 'reviewed' => 'Done. The author has been told.', 'saved' => 'Saved.'][$_GET['msg'] ?? ''] ?? '';
$token = generate_csrf_token();

$pageTitle = $post['title'] . ' · Stories & Help';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
echo aw_save_assets();
echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/css/aw-messages.css?v=1">';
if ($canReply) echo aw_cp_editor_assets();
?>
<main class="cp" id="main">
<div class="container cp-narrow">
    <p><a href="<?php echo SITE_URL; ?>/stories.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Stories &amp; Help</a></p>
    <?php echo aw_alert('success', $msg); ?>
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>

    <?php if ($isAuthor && $post['status'] === 'held'): ?>
        <p class="cp-banner cp-banner-warn"><strong>Waiting for a first read.</strong> Posts about <?php echo htmlspecialchars(mb_strtolower(aw_cp_category_label($post['category']))); ?> are read by the team before others see them. You will get a notification when it is shared.</p>
    <?php elseif ($isAuthor && $post['status'] === 'draft'): ?>
        <p class="cp-banner cp-banner-warn"><strong>The team asked for a small change before this is shared.</strong><?php echo $post['review_note'] ? ' “' . htmlspecialchars($post['review_note']) . '”' : ''; ?> <a href="<?php echo SITE_URL; ?>/story_write.php?id=<?php echo $id; ?>">Edit it</a></p>
    <?php elseif ($post['status'] === 'hidden'): ?>
        <p class="cp-banner cp-banner-warn"><strong>Paused by the team.</strong> Only you and the team can see this.<?php echo $post['review_note'] ? ' “' . htmlspecialchars($post['review_note']) . '”' : ''; ?></p>
    <?php elseif ($post['visibility'] === 'team'): ?>
        <p class="cp-banner cp-banner-calm"><i class="fas fa-lock" aria-hidden="true"></i> <strong>Private.</strong> Only <?php echo $isAuthor ? 'you' : 'the author'; ?> and the AngelWrites team (the owner and admins) can see this conversation.</p>
    <?php elseif ($isAuthor && $post['visibility'] === 'pseudo'): ?>
        <p class="cp-banner">Shared as <strong><?php echo htmlspecialchars($post['pseudonym']); ?></strong>. Other members cannot see that it is you.</p>
    <?php endif; ?>

    <?php if (aw_cp_staff_mod() && ($post['visibility'] !== 'team' || aw_cp_staff_full())): ?>
    <section class="cp-staff" aria-labelledby="cpStaffH">
        <h2 id="cpStaffH">For the team · status: <?php echo htmlspecialchars($post['status']); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="action" value="review">
            <label class="sr-only" for="cpNote">Note to the author</label>
            <input type="text" id="cpNote" name="note" maxlength="300" placeholder="Note to the author (optional, they will see it)">
            <?php if ($post['status'] !== 'published'): ?><button class="cp-btn cp-btn-sm" name="decision" value="<?php echo $post['status'] === 'closed' ? 'reopen' : 'publish'; ?>"><?php echo $post['status'] === 'closed' ? 'Open replies' : 'Share it'; ?></button><?php endif; ?>
            <?php if ($post['status'] === 'held'): ?><button class="cp-btn cp-btn-soft cp-btn-sm" name="decision" value="return">Ask for a change</button><?php endif; ?>
            <?php if ($post['status'] === 'published'): ?><button class="cp-btn cp-btn-soft cp-btn-sm" name="decision" value="close">Close replies</button><?php endif; ?>
            <?php if ($post['status'] !== 'hidden'): ?><button class="cp-btn cp-btn-quiet cp-btn-sm" name="decision" value="hide" onclick="return confirm('Pause this post? Only the author and the team will see it.');">Pause it</button><?php endif; ?>
        </form>
        <?php if (aw_cp_staff_full() && $post['visibility'] !== 'named'): ?>
            <?php if ($revealed && empty($revealedFor)): ?>
                <p class="cp-who">Written by <strong><?php echo htmlspecialchars($revealed['name']); ?></strong> (@<?php echo htmlspecialchars($revealed['username']); ?>, <?php echo htmlspecialchars($revealed['email']); ?>). This look was written to the audit log.</p>
            <?php else: ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="reveal"><button class="cp-linkbtn" type="submit">Show who wrote this</button> <small>(owner and admins only; every look is logged)</small></form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (aw_cp_needs_crisis($post)) echo aw_cp_crisis_block(true); ?>

    <article class="cp-post">
        <div class="cp-meta">
            <span class="cp-type"><?php echo htmlspecialchars(AW_CP_TYPES[$post['type']] ?? 'Post'); ?></span>
            <a href="<?php echo SITE_URL; ?>/stories.php?category=<?php echo urlencode($post['category']); ?>"><?php echo htmlspecialchars(aw_cp_category_label($post['category'])); ?></a>
            <?php foreach ($warn as $w): ?><span class="cp-tag cp-tag-warn"><?php echo htmlspecialchars(AW_CP_WARNINGS[$w]); ?></span><?php endforeach; ?>
        </div>
        <h1><?php echo htmlspecialchars($post['title']); ?></h1>
        <p class="cp-byline"><span><strong><?php echo htmlspecialchars(aw_cp_author_label($db, $post)); ?></strong></span><?php if ($post['visibility'] === 'named' && function_exists('aw_msg_button')) echo ' ' . aw_msg_button((int)$post['author_id']); /* I2: named authors only */ ?><span><?php echo htmlspecialchars(date('j F Y', strtotime($post['published_at'] ?: $post['created_at']))); ?></span><?php if ($post['updated_at']): ?><span>edited</span><?php endif; ?></p>
        <?php if ($warn && !$isAuthor): ?>
            <details class="cp-warnbox" id="cpGate">
                <summary class="cp-btn cp-btn-soft cp-btn-sm">Show the post</summary>
                <div class="cp-body" style="margin-top:14px"><?php echo aw_clean_html($post['body'], 'member'); ?></div>
            </details>
            <p class="cp-hint" style="margin-top:8px">This post mentions <?php echo htmlspecialchars(implode(', ', array_map(function ($w) { return mb_strtolower(AW_CP_WARNINGS[$w]); }, $warn))); ?>. Take care of yourself; open it when you feel ready.</p>
        <?php else: ?>
            <div class="cp-body"><?php echo aw_clean_html($post['body'], 'member'); ?></div>
        <?php endif; ?>
        <div class="cp-actions">
            <?php if ($post['status'] === 'published' && $post['visibility'] !== 'team') echo aw_save_button('story', $id); ?>
            <?php if ($isAuthor): ?>
                <a class="cp-btn cp-btn-soft cp-btn-sm" href="<?php echo SITE_URL; ?>/story_write.php?id=<?php echo $id; ?>"><i class="fas fa-pen" aria-hidden="true"></i> Edit</a>
                <form method="post" onsubmit="return confirm('Delete this post? Replies to it will go too. You cannot undo this.');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="delete_post"><button class="cp-btn cp-btn-danger cp-btn-sm" type="submit">Delete</button></form>
            <?php elseif ($me && $post['status'] === 'published'): ?>
                <?php if ($post['type'] === 'help' && $canReply): ?><a class="cp-btn cp-btn-sm" href="#reply" data-help-prompt>I can help</a><?php endif; ?>
                <span class="aw-rb" data-type="community_post" data-id="<?php echo $id; ?>" data-user="" data-name=""><button type="button" class="cp-linkbtn aw-rb-btn" data-act="report">This didn't feel kind or safe</button></span>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($helpers): ?>
    <section class="cp-help" aria-labelledby="cpHelpH">
        <h2 id="cpHelpH">People who have been through this</h2>
        <p>These members offered to help with <?php echo htmlspecialchars(mb_strtolower(aw_cp_category_label($post['category']))); ?>. They may reply here when they see this.</p>
        <ul><?php foreach ($helpers as $h): ?><li><?php echo htmlspecialchars($h['name']); ?></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <section class="cp-replies" id="replies" aria-labelledby="cpRepH">
        <h2 id="cpRepH"><?php echo $replies ? (count($replies) === 1 ? '1 reply' : count($replies) . ' replies') : 'Replies'; ?></h2>
        <?php if (!$replies && $post['status'] === 'published'): ?><p class="cp-hint">No replies yet. <?php echo $canReply ? 'A kind word can mean a lot.' : ''; ?></p><?php endif; ?>
        <?php foreach ($replies as $r): $rid = (int)$r['id']; $mine = $me && (int)$r['author_id'] === $me; $byAuthor = (int)$r['author_id'] === (int)$post['author_id'] && empty($r['as_team']); ?>
            <article class="cp-reply<?php echo !empty($r['as_team']) ? ' cp-reply-team' : ''; ?>" id="reply-<?php echo $rid; ?>">
                <p class="cp-reply-head"><strong><?php echo htmlspecialchars(aw_cp_reply_label($db, $r, $post)); ?></strong><?php if ($byAuthor): ?> <span class="cp-mark">Author</span><?php endif; ?><?php if ($mine && $r['pseudonym']): ?> <span class="cp-mark">You</span><?php endif; ?> <span><?php echo htmlspecialchars(date('j M Y, g:i a', strtotime($r['created_at']))); ?></span></p>
                <div class="cp-body"><?php echo aw_clean_html($r['body'], 'member'); ?></div>
                <p class="cp-reply-foot">
                    <?php if ($mine): ?>
                        <?php if (!empty($thanksCount[$rid])): ?><span class="cp-thanked"><i class="fas fa-heart" aria-hidden="true"></i> <?php echo $thanksCount[$rid] === 1 ? 'Someone said this helped them' : $thanksCount[$rid] . ' people said this helped them'; ?></span><?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete your reply?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="delete_reply"><input type="hidden" name="reply_id" value="<?php echo $rid; ?>"><button class="cp-linkbtn" type="submit">Delete</button></form>
                    <?php elseif ($me): ?>
                        <?php if (!empty($thanked[$rid])): ?><span class="cp-thanked"><i class="fas fa-heart" aria-hidden="true"></i> You said this helped</span>
                        <?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="thank"><input type="hidden" name="reply_id" value="<?php echo $rid; ?>"><button class="cp-btn cp-btn-soft cp-btn-sm" type="submit"><i class="far fa-heart" aria-hidden="true"></i> Thank you, this helped</button></form><?php endif; ?>
                        <?php if (empty($r['as_team'])): ?><span class="aw-rb" data-type="community_reply" data-id="<?php echo $rid; ?>" data-user="" data-name=""><button type="button" class="cp-linkbtn aw-rb-btn" data-act="report">Report</button></span><?php endif; ?>
                    <?php endif; ?>
                    <?php if (aw_cp_staff_full() && ($r['pseudonym'] || $post['visibility'] === 'team') && empty($r['as_team']) && !$mine): ?>
                        <?php if ($revealed && !empty($revealedFor) && $revealedFor === $rid): ?>
                            <span class="cp-who">Written by <strong><?php echo htmlspecialchars($revealed['name']); ?></strong> (@<?php echo htmlspecialchars($revealed['username']); ?>). Logged.</span>
                        <?php else: ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="action" value="reveal"><input type="hidden" name="reply_id" value="<?php echo $rid; ?>"><button class="cp-linkbtn" type="submit">Show who</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </article>
        <?php endforeach; ?>

        <?php if ($canReply): ?>
        <form method="post" class="cp-form cp-section" id="reply" style="margin-top:18px" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="action" value="reply">
            <div class="cp-field">
                <label class="cp-label" for="ed_body">Your reply</label>
                <span class="cp-hint" id="cpReplyHint"><?php echo $post['type'] === 'help' ? 'What helped you when you went through something like this? What would you want to hear?' : 'Speak from your own experience. No judging, only kindness.'; ?></span>
                <?php echo aw_cp_editor('body', $_POST['body'] ?? '', 'Your reply', ['min_height' => 140, 'placeholder' => 'Write with kindness…']); ?>
            </div>
            <?php if ($post['visibility'] === 'team'): ?>
                <p class="cp-hint"><?php echo $isAuthor ? 'Only the team will read this.' : 'You are replying as the AngelWrites Team.'; ?></p>
            <?php elseif ($isAuthor && $post['visibility'] === 'pseudo'): ?>
                <p class="cp-hint">You will reply as <strong><?php echo htmlspecialchars($post['pseudonym']); ?></strong>, the name on your post.</p>
            <?php else: $mode = $_POST['mode'] ?? 'named'; ?>
                <fieldset class="cp-field">
                    <legend class="cp-label">Reply as</legend>
                    <div class="cp-checks">
                        <label class="cp-check"><input type="radio" name="mode" value="named"<?php echo $mode === 'named' ? ' checked' : ''; ?>> My name</label>
                        <label class="cp-check"><input type="radio" name="mode" value="pseudo"<?php echo $mode === 'pseudo' ? ' checked' : ''; ?>> A pseudonym</label>
                        <?php if (aw_cp_staff_mod()): ?><label class="cp-check"><input type="radio" name="mode" value="team"<?php echo $mode === 'team' ? ' checked' : ''; ?>> The AngelWrites Team</label><?php endif; ?>
                    </div>
                    <div class="cp-pseudo" style="margin-top:8px" data-pseudo-row>
                        <label class="sr-only" for="cpPseudo">Pseudonym for this reply</label>
                        <input type="text" id="cpPseudo" name="pseudonym" maxlength="40" value="<?php echo htmlspecialchars($_POST['pseudonym'] ?? aw_cp_suggest_pseudonym()); ?>">
                    </div>
                </fieldset>
            <?php endif; ?>
            <?php if (empty($member['age_confirmed_at']) && !aw_cp_staff_mod()): ?>
                <label class="cp-age"><input type="checkbox" name="age_ok" value="1" required> <span>I confirm I am 18 or older. Stories &amp; Help is for adults.</span></label>
            <?php endif; ?>
            <div class="cp-submit"><button type="submit" class="cp-btn"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send reply</button> <a href="<?php echo SITE_URL; ?>/community_guidelines.php">Community guidelines</a></div>
        </form>
        <?php elseif (!$me && $post['status'] === 'published' && $post['visibility'] !== 'team'): ?>
            <p><a class="cp-btn cp-btn-soft" href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/story.php?id=' . $id); ?>">Sign in to reply</a></p>
        <?php elseif ($post['status'] === 'closed'): ?>
            <p class="cp-hint">Replies are closed on this post.</p>
        <?php endif; ?>
    </section>
</div>
</main>
<?php echo aw_report_assets(); ?>
<script>
(function () {
  var row = document.querySelector('[data-pseudo-row]');
  function sync() { if (!row) return; var m = document.querySelector('input[name=mode]:checked'); row.hidden = !m || m.value !== 'pseudo'; }
  document.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); }); sync();
  var help = document.querySelector('[data-help-prompt]');
  if (help) help.addEventListener('click', function () {
    setTimeout(function () { var ed = document.querySelector('#reply .ql-editor') || document.getElementById('ed_body'); if (ed) ed.focus(); }, 50);
  });
})();
</script>
<?php require_once 'includes/footer.php'; ?>
