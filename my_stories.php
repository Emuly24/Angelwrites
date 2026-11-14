<?php
// D2/B: a member's own Stories & Help page: what I shared, the areas I can help with (opt-in),
// and topics I would rather not see (PROGRAM_PLAN.md Phase 3).
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/community_posts.php';

if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/my_stories.php')); exit; }
aw_cp_tables($db);
$me = aw_cp_me();
$notice = ['deleted' => 'Your post was deleted.', 'help' => 'Saved. Thank you for offering to help.', 'topics' => 'Saved. Those topics will be left out of your lists.'][$_GET['msg'] ?? ''] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['save_help'])) {
        $areas = array_values(array_intersect((array)($_POST['help_areas'] ?? []), array_keys(AW_CP_CATEGORIES)));
        aw_cp_member_save($db, $me, ['help_areas' => implode(',', $areas)]);
        header('Location: ' . SITE_URL . '/my_stories.php?msg=help#help'); exit;
    } elseif (isset($_POST['save_topics'])) {
        $hidden = aw_cp_warning_list(implode(',', (array)($_POST['hidden'] ?? [])));
        aw_cp_member_save($db, $me, ['hidden_warnings' => implode(',', $hidden)]);
        header('Location: ' . SITE_URL . '/my_stories.php?msg=topics#topics'); exit;
    }
}

$m = aw_cp_member($db, $me);
$help = array_filter(explode(',', (string)$m['help_areas']));
$hidden = aw_cp_warning_list($m['hidden_warnings']);
$mine = [];
try {
    $st = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM community_replies r WHERE r.post_id = p.id AND r.status = 'published') AS reply_count
                        FROM community_posts p WHERE p.author_id = ? AND p.status <> 'removed' ORDER BY p.created_at DESC");
    $st->execute([$me]);
    $mine = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('my stories: ' . $e->getMessage()); }
$statusLabel = ['published' => 'Shared', 'held' => 'Waiting for a first read', 'draft' => 'Change requested', 'hidden' => 'Paused by the team', 'closed' => 'Replies closed'];
$token = generate_csrf_token();

$pageTitle = 'My Stories & Help';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
?>
<main class="cp" id="main">
<div class="container cp-narrow">
    <header class="cp-head"><div>
        <p class="cp-eyebrow">Stories &amp; Help</p>
        <h1>My Stories &amp; Help</h1>
        <p>Only you can see this page.</p>
    </div><a class="cp-btn" href="<?php echo SITE_URL; ?>/story_write.php"><i class="fas fa-feather" aria-hidden="true"></i> Share something</a></header>
    <?php echo aw_alert('success', $notice); echo aw_alert('error', $error); ?>

    <section class="cp-section" aria-labelledby="mineH">
        <h2 id="mineH">What I shared</h2>
        <p>Posts under a pseudonym show it here so you can recognise them. Nobody else sees this list.</p>
        <?php if (!$mine): ?>
            <p class="cp-hint">Nothing yet. When you share a story, a question or a request for help, it appears here.</p>
        <?php else: ?>
            <ul class="cp-mine">
            <?php foreach ($mine as $p): ?>
                <li>
                    <span><a href="<?php echo SITE_URL; ?>/story.php?id=<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a><br>
                    <small class="cp-hint" style="margin:0"><?php echo htmlspecialchars(AW_CP_TYPES[$p['type']] ?? 'Post'); ?> · <?php echo $p['visibility'] === 'pseudo' ? 'as ' . htmlspecialchars($p['pseudonym']) : ($p['visibility'] === 'team' ? 'private to the team' : 'with your name'); ?> · <?php echo (int)$p['reply_count'] === 1 ? '1 reply' : (int)$p['reply_count'] . ' replies'; ?></small></span>
                    <span class="cp-status cp-status-<?php echo htmlspecialchars($p['status']); ?>"><?php echo htmlspecialchars($statusLabel[$p['status']] ?? $p['status']); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="cp-section" id="help" aria-labelledby="helpH">
        <h2 id="helpH">Areas I can help with</h2>
        <p>If you have been through something and would like to help others in it, choose it here. Your name is shown on requests for help in these areas, as someone who may reply. Leave all unticked to stay private.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <fieldset class="cp-field"><legend class="sr-only">Areas I can help with</legend>
            <div class="cp-checks">
                <?php foreach (AW_CP_CATEGORIES as $k => [$l]): if ($k === 'other') continue; ?>
                    <label class="cp-check"><input type="checkbox" name="help_areas[]" value="<?php echo $k; ?>"<?php echo in_array($k, $help, true) ? ' checked' : ''; ?>> <?php echo htmlspecialchars($l); ?></label>
                <?php endforeach; ?>
            </div></fieldset>
            <p style="margin-top:14px"><button class="cp-btn cp-btn-sm" name="save_help" value="1">Save</button></p>
        </form>
    </section>

    <section class="cp-section" id="topics" aria-labelledby="topicsH">
        <h2 id="topicsH">Topics I would rather not see</h2>
        <p>Posts that mention these are left out of your lists. You can still open them on purpose.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
            <fieldset class="cp-field"><legend class="sr-only">Topics to leave out</legend>
            <div class="cp-checks">
                <?php foreach (AW_CP_WARNINGS as $k => $l): ?><label class="cp-check"><input type="checkbox" name="hidden[]" value="<?php echo $k; ?>"<?php echo in_array($k, $hidden, true) ? ' checked' : ''; ?>> <?php echo htmlspecialchars($l); ?></label><?php endforeach; ?>
            </div></fieldset>
            <p style="margin-top:14px"><button class="cp-btn cp-btn-sm" name="save_topics" value="1">Save</button></p>
        </form>
    </section>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
