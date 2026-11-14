<?php
// D2: Stories & Help, the list (PROGRAM_PLAN.md Phase 3). One entry point: "Get help · Offer help".
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/pagination.php';
require_once 'includes/community_posts.php';

if (!aw_cp_enabled() && !aw_cp_staff_mod()) { http_response_code(404); $pageTitle = 'Not available'; require 'includes/header.php'; echo '<div class="container" style="padding:60px 0">' . aw_empty('Stories & Help is resting', 'Please come back soon.', [SITE_URL . '/index.php', 'Go home'], 'fa-seedling') . '</div>'; require 'includes/footer.php'; exit; }
aw_cp_tables($db);

$type = isset(AW_CP_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$cat = isset(AW_CP_CATEGORIES[$_GET['category'] ?? '']) ? $_GET['category'] : '';
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$showHidden = !empty($_GET['all']);
[$page, $per] = aw_page(12);
$sensitive = $cat !== '' && aw_cp_is_sensitive($cat);
$needLogin = $sensitive && !isLoggedIn();
$res = $needLogin ? ['rows' => [], 'total' => 0, 'hidden' => 0] : aw_cp_list($db, ['type' => $type, 'category' => $cat, 'q' => $q, 'page' => $page, 'show_hidden' => $showHidden], $per);

function cp_url(array $over) {
    $p = array_filter(array_merge(['type' => $_GET['type'] ?? '', 'category' => $_GET['category'] ?? '', 'q' => $_GET['q'] ?? ''], $over), function ($v) { return $v !== '' && $v !== null; });
    return SITE_URL . '/stories.php' . ($p ? '?' . http_build_query($p) : '');
}

$pageTitle = 'Stories & Help';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
?>
<main class="cp" id="main">
<div class="container">
    <header class="cp-head">
        <div>
            <p class="cp-eyebrow">Community</p>
            <h1>Stories &amp; Help</h1>
            <p>A gentle place to share what you are carrying, ask, and help one another. No judging, only love, respect and understanding. <a href="<?php echo SITE_URL; ?>/community_guidelines.php">How we look after each other</a>.</p>
        </div>
        <?php if (isLoggedIn()): ?><div class="cp-submit"><a class="cp-btn cp-btn-quiet" href="<?php echo SITE_URL; ?>/my_stories.php">My posts &amp; choices</a><a class="cp-btn" href="<?php echo SITE_URL; ?>/story_write.php"><i class="fas fa-feather" aria-hidden="true"></i> Share something</a></div><?php endif; ?>
    </header>

    <div class="cp-doors">
        <a class="cp-door" href="<?php echo SITE_URL; ?>/story_write.php?type=help">
            <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
            <span><strong>Get help</strong><span>Ask the community, or ask only the team in private.</span></span>
        </a>
        <a class="cp-door" href="<?php echo cp_url(['type' => 'help', 'category' => '', 'q' => '']); ?>">
            <i class="fas fa-people-carry-box" aria-hidden="true"></i>
            <span><strong>Offer help</strong><span>Read requests from others<?php echo isLoggedIn() ? ', and choose the areas you can help with' : ''; ?>.</span></span>
        </a>
    </div>

    <?php if ($cat !== '' && $sensitive) echo aw_cp_crisis_block(true); ?>

    <div class="cp-filters">
        <nav class="cp-chips" aria-label="What kind of post">
            <a class="cp-chip" href="<?php echo cp_url(['type' => '']); ?>"<?php echo $type === '' ? ' aria-current="page"' : ''; ?>>Everything</a>
            <?php foreach (AW_CP_TYPES as $k => $l): ?>
                <a class="cp-chip" href="<?php echo cp_url(['type' => $k]); ?>"<?php echo $type === $k ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars(['story' => 'Stories', 'experience' => 'Experiences', 'question' => 'Questions', 'help' => 'Requests for help'][$k] ?? $l); ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" action="<?php echo SITE_URL; ?>/stories.php" role="search">
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>"><?php endif; ?>
            <label class="sr-only" for="cpCat">Area of life</label>
            <select id="cpCat" name="category" onchange="this.form.submit()">
                <option value="">All areas of life</option>
                <?php foreach (AW_CP_CATEGORIES as $k => [$l]): ?><option value="<?php echo $k; ?>"<?php echo $cat === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
            </select>
            <label class="sr-only" for="cpQ">Search</label>
            <input type="search" id="cpQ" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search">
            <button type="submit" class="cp-btn cp-btn-soft cp-btn-sm">Search</button>
        </form>
    </div>

    <?php if ($res['hidden'] > 0): ?>
        <p class="cp-hidden-note"><?php echo (int)$res['hidden']; ?> <?php echo $res['hidden'] === 1 ? 'post is' : 'posts are'; ?> not shown because of the topics you chose not to see. <a href="<?php echo SITE_URL; ?>/my_stories.php#topics">Change this</a> · <a href="<?php echo cp_url(['all' => 1]); ?>">Show them this time</a></p>
    <?php endif; ?>

    <?php if ($needLogin): ?>
        <?php echo aw_empty('Please sign in to read this area', 'Stories about ' . mb_strtolower(aw_cp_category_label($cat)) . ' can be tender. We ask readers to sign in first, to keep them safe.', [SITE_URL . '/login.php?redirect=' . urlencode('/stories.php?category=' . $cat), 'Sign in'], 'fa-lock'); ?>
    <?php elseif (!$res['rows']): ?>
        <?php echo aw_empty($q !== '' || $cat !== '' || $type !== '' ? 'Nothing here yet' : 'Be the first to share',
            $q !== '' ? 'Nothing matches your search. Try other words.' : 'When members share stories, questions and requests for help, they appear here.',
            isLoggedIn() ? [SITE_URL . '/story_write.php' . ($type ? '?type=' . $type : ''), 'Share something'] : [SITE_URL . '/login.php?redirect=' . urlencode('/stories.php'), 'Sign in to share'], 'fa-seedling'); ?>
    <?php else: ?>
        <div class="cp-list">
        <?php foreach ($res['rows'] as $p): $warn = aw_cp_warning_list($p['warnings']); ?>
            <article class="cp-card">
                <div class="cp-meta">
                    <span class="cp-type"><?php echo htmlspecialchars(AW_CP_TYPES[$p['type']] ?? 'Post'); ?></span>
                    <span><?php echo htmlspecialchars(aw_cp_category_label($p['category'])); ?></span>
                    <?php if ($p['visibility'] === 'team'): ?><span class="cp-tag cp-tag-team">Private to the team</span><?php endif; ?>
                    <?php if ($p['status'] === 'closed'): ?><span class="cp-tag">Replies closed</span><?php endif; ?>
                </div>
                <h2><a href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a></h2>
                <?php if ($warn): ?>
                    <p class="cp-gate"><i class="fas fa-circle-info" aria-hidden="true"></i> This post mentions <?php echo htmlspecialchars(implode(', ', array_map(function ($w) { return mb_strtolower(AW_CP_WARNINGS[$w]); }, $warn))); ?>. Open it when you feel ready.</p>
                <?php else: ?>
                    <p class="cp-excerpt"><?php echo htmlspecialchars(aw_cp_excerpt($p['body'])); ?></p>
                <?php endif; ?>
                <p class="cp-byline"><span><strong><?php echo htmlspecialchars(aw_cp_author_label($db, $p)); ?></strong></span><span><?php echo htmlspecialchars(date('j M Y', strtotime($p['published_at'] ?: $p['created_at']))); ?></span><span><?php echo (int)$p['reply_count'] === 1 ? '1 reply' : (int)$p['reply_count'] . ' replies'; ?></span></p>
            </article>
        <?php endforeach; ?>
        </div>
        <?php echo aw_pager($res['total'], $page, $per); ?>
    <?php endif; ?>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
