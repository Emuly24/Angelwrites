<?php
// D2: share or edit a Stories & Help post (PROGRAM_PLAN.md Phase 3).
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/community_posts.php';

if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/story_write.php')); exit; }
if (!aw_cp_enabled() && !aw_cp_staff_mod()) { header('Location: ' . SITE_URL . '/stories.php'); exit; }
aw_cp_tables($db);
$me = aw_cp_me();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$old = $id ? aw_cp_get($db, $id) : null;
if ($id && (!$old || (int)$old['author_id'] !== $me || $old['status'] === 'removed')) { header('Location: ' . SITE_URL . '/stories.php'); exit; }

$errors = [];
$v = $old ? [
    'type' => $old['type'], 'visibility' => $old['visibility'], 'pseudonym' => (string)$old['pseudonym'], 'category' => $old['category'],
    'warnings' => aw_cp_warning_list($old['warnings']), 'no_warnings' => $old['warnings'] === '', 'title' => $old['title'], 'body' => $old['body'],
] : [
    'type' => isset(AW_CP_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '',
    'visibility' => isset(AW_CP_VISIBILITY[$_GET['visibility'] ?? '']) ? $_GET['visibility'] : '',
    'pseudonym' => aw_cp_suggest_pseudonym(), 'category' => isset(AW_CP_CATEGORIES[$_GET['category'] ?? '']) ? $_GET['category'] : '',
    'warnings' => [], 'no_warnings' => false, 'title' => '', 'body' => '',
];
if (!$old && !empty($_GET['pray'])) { $v['title'] = 'Please pray with me'; $v['category'] = $v['category'] ?: 'faith'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Your words are still below; please send again.';
    } else {
        $in = [
            'id' => $id, 'type' => $_POST['type'] ?? '', 'visibility' => $_POST['visibility'] ?? '', 'pseudonym' => $_POST['pseudonym'] ?? '',
            'category' => $_POST['category'] ?? '', 'warnings' => (array)($_POST['warnings'] ?? []), 'no_warnings' => !empty($_POST['no_warnings']),
            'title' => $_POST['title'] ?? '', 'body' => $_POST['body'] ?? '', 'age_ok' => !empty($_POST['age_ok']),
        ];
        $r = aw_cp_save_post($db, $in);
        if ($r['ok']) { header('Location: ' . SITE_URL . '/story.php?id=' . $r['id'] . '&msg=saved'); exit; }
        $errors = $r['errors'];
    }
    $v = ['type' => (string)($_POST['type'] ?? ''), 'visibility' => (string)($_POST['visibility'] ?? ''), 'pseudonym' => (string)($_POST['pseudonym'] ?? ''),
          'category' => (string)($_POST['category'] ?? ''), 'warnings' => aw_cp_warning_list(implode(',', (array)($_POST['warnings'] ?? []))),
          'no_warnings' => !empty($_POST['no_warnings']), 'title' => (string)($_POST['title'] ?? ''), 'body' => (string)($_POST['body'] ?? '')];
}
$member = aw_cp_member($db, $me);
$restricted = aw_cp_restriction($db, $me);
$err = function ($k) use ($errors) { return isset($errors[$k]) ? '<span class="cp-err" id="err_' . $k . '">' . htmlspecialchars($errors[$k]) . '</span>' : ''; };
$cls = function ($k) use ($errors) { return isset($errors[$k]) ? ' has-err' : ''; };
$general = array_filter($errors, 'is_int', ARRAY_FILTER_USE_KEY);

$typeHelp = ['story' => 'Something that happened to you, and what you found along the way.', 'experience' => 'A short experience or a lesson you want to pass on.',
             'question' => 'Something you would like others\' wisdom on.', 'help' => 'Something you are going through and would like help with.'];
$visHelp = ['named' => 'Members see your name.', 'pseudo' => 'Members see only the pseudonym below. The owner and admins can see it is you, to keep everyone safe.',
            'team' => 'Only the owner and admins see it. Nobody else knows it exists.'];

$pageTitle = $old ? 'Edit your post' : 'Share something';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
echo aw_cp_editor_assets();
?>
<main class="cp" id="main">
<div class="container cp-narrow">
    <p><a href="<?php echo SITE_URL; ?>/<?php echo $old ? 'story.php?id=' . $id : 'stories.php'; ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> <?php echo $old ? 'Back to your post' : 'Stories & Help'; ?></a></p>
    <header class="cp-head"><div>
        <h1><?php echo $old ? 'Edit your post' : 'Share something'; ?></h1>
        <p>Take your time. You can edit or delete what you share at any time.</p>
    </div></header>

    <?php echo aw_cp_crisis_block(true); ?>

    <?php if ($restricted): ?>
        <?php echo aw_alert('warning', $restricted); ?>
    <?php else: ?>
    <?php if ($general): ?><div class="alert alert-error" role="alert"><?php echo implode('<br>', array_map('htmlspecialchars', $general)); ?></div><?php endif; ?>
    <?php if (count($errors) > count($general)): ?><div class="alert alert-error" role="alert">Please check the <?php echo count($errors) - count($general) === 1 ? 'item' : 'items'; ?> marked below.</div><?php endif; ?>

    <form method="post" class="cp-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <?php if ($old): ?><input type="hidden" name="id" value="<?php echo $id; ?>"><?php endif; ?>

        <fieldset class="cp-field<?php echo $cls('type'); ?>">
            <legend class="cp-label">What are you sharing?</legend>
            <div class="cp-options">
                <?php foreach (AW_CP_TYPES as $k => $l): ?>
                    <label class="cp-option"><input type="radio" name="type" value="<?php echo $k; ?>"<?php echo $v['type'] === $k ? ' checked' : ''; ?> required><span><strong><?php echo htmlspecialchars($l); ?></strong><small><?php echo htmlspecialchars($typeHelp[$k]); ?></small></span></label>
                <?php endforeach; ?>
            </div>
            <?php echo $err('type'); ?>
        </fieldset>

        <div class="cp-field<?php echo $cls('category'); ?>">
            <label for="cpCategory">Which area of life is it about?</label>
            <span class="cp-hint" id="cpCatHint">Posts in some areas are read by the team before others see them, to keep everyone safe.</span>
            <select id="cpCategory" name="category" required aria-describedby="cpCatHint<?php echo isset($errors['category']) ? ' err_category' : ''; ?>">
                <option value="">Choose one…</option>
                <?php foreach (AW_CP_CATEGORIES as $k => [$l, $sens]): ?><option value="<?php echo $k; ?>" data-sensitive="<?php echo $sens ? '1' : '0'; ?>"<?php echo $v['category'] === $k ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
            </select>
            <?php echo $err('category'); ?>
        </div>

        <fieldset class="cp-field<?php echo $cls('visibility') . $cls('pseudonym'); ?>">
            <legend class="cp-label">Who can see it?</legend>
            <div class="cp-options">
                <?php foreach (AW_CP_VISIBILITY as $k => $l): ?>
                    <label class="cp-option"><input type="radio" name="visibility" value="<?php echo $k; ?>"<?php echo $v['visibility'] === $k ? ' checked' : ''; ?> required><span><strong><?php echo htmlspecialchars($l); ?></strong><small><?php echo htmlspecialchars($visHelp[$k]); ?></small></span></label>
                <?php endforeach; ?>
            </div>
            <?php echo $err('visibility'); ?>
            <div data-pseudo-row style="margin-top:12px">
                <label class="cp-label" for="cpPseudonym">Pseudonym for this post</label>
                <span class="cp-hint">A new name for each post. It cannot be a member's name or sound like the team.</span>
                <div class="cp-pseudo">
                    <input type="text" id="cpPseudonym" name="pseudonym" maxlength="40" value="<?php echo htmlspecialchars($v['pseudonym']); ?>"<?php echo isset($errors['pseudonym']) ? ' aria-describedby="err_pseudonym" aria-invalid="true"' : ''; ?>>
                    <button type="button" class="cp-btn cp-btn-soft cp-btn-sm" data-suggest>Suggest another</button>
                </div>
                <?php echo $err('pseudonym'); ?>
            </div>
            <p class="cp-who-sees"><i class="fas fa-shield-heart" aria-hidden="true"></i> The AngelWrites team (the owner and admins) can always see who wrote a post, so we can keep everyone safe. Other members cannot see who is behind a pseudonym.</p>
        </fieldset>

        <fieldset class="cp-field<?php echo $cls('warnings'); ?>">
            <legend class="cp-label">Does it mention any of these? <span class="cp-hint" style="display:inline">(readers see a gentle warning first)</span></legend>
            <div class="cp-checks">
                <?php foreach (AW_CP_WARNINGS as $k => $l): ?><label class="cp-check"><input type="checkbox" name="warnings[]" value="<?php echo $k; ?>"<?php echo in_array($k, $v['warnings'], true) ? ' checked' : ''; ?>> <?php echo htmlspecialchars($l); ?></label><?php endforeach; ?>
                <label class="cp-check"><input type="checkbox" name="no_warnings" value="1"<?php echo !empty($v['no_warnings']) && !$v['warnings'] ? ' checked' : ''; ?>> None of these</label>
            </div>
            <?php echo $err('warnings'); ?>
        </fieldset>

        <div class="cp-field<?php echo $cls('title'); ?>">
            <label for="cpTitle">Title</label>
            <input type="text" id="cpTitle" name="title" maxlength="<?php echo AW_CP_LIMITS['title']; ?>" value="<?php echo htmlspecialchars($v['title']); ?>" required<?php echo isset($errors['title']) ? ' aria-describedby="err_title" aria-invalid="true"' : ''; ?>>
            <?php echo $err('title'); ?>
        </div>

        <div class="cp-field<?php echo $cls('body'); ?>">
            <label for="ed_body">Your words</label>
            <span class="cp-hint">Speak from your own experience. Please do not include anyone's full name, address or phone number.</span>
            <?php echo aw_cp_editor('body', $v['body'], 'Your words', ['placeholder' => 'Write as much or as little as you like…']); ?>
            <?php echo $err('body'); ?>
        </div>

        <?php if (empty($member['age_confirmed_at']) && !aw_cp_staff_mod()): ?>
        <div class="cp-field<?php echo $cls('age_ok'); ?>">
            <label class="cp-age"><input type="checkbox" name="age_ok" value="1" required> <span>I confirm I am 18 or older. Stories &amp; Help is for adults.</span></label>
            <?php echo $err('age_ok'); ?>
        </div>
        <?php endif; ?>

        <div class="cp-submit">
            <button type="submit" class="cp-btn"><i class="fas fa-feather" aria-hidden="true"></i> <?php echo $old ? 'Save changes' : 'Share'; ?></button>
            <a href="<?php echo SITE_URL; ?>/community_guidelines.php">How we look after each other</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</main>
<script>
(function () {
  var row = document.querySelector('[data-pseudo-row]');
  function sync() { var m = document.querySelector('input[name=visibility]:checked'); if (row) row.hidden = !m || m.value !== 'pseudo'; }
  document.querySelectorAll('input[name=visibility]').forEach(function (r) { r.addEventListener('change', sync); }); sync();
  var A = ['Quiet','Gentle','Hopeful','Morning','Still','Brave','Kind','Patient','Steady','Tender','Bright','Faithful','Healing','Evening','Rising'];
  var B = ['Willow','Dove','River','Cedar','Sparrow','Lily','Harbour','Meadow','Olive','Lantern','Rain','Stone','Fig Tree','Shepherd','Garden'];
  var s = document.querySelector('[data-suggest]');
  if (s) s.addEventListener('click', function () { document.getElementById('cpPseudonym').value = A[Math.floor(Math.random() * A.length)] + ' ' + B[Math.floor(Math.random() * B.length)]; });
  // "None of these" and the warnings exclude each other
  var none = document.querySelector('input[name=no_warnings]'), boxes = document.querySelectorAll('input[name="warnings[]"]');
  if (none) none.addEventListener('change', function () { if (none.checked) boxes.forEach(function (b) { b.checked = false; }); });
  boxes.forEach(function (b) { b.addEventListener('change', function () { if (b.checked && none) none.checked = false; }); });
  var first = document.querySelector('.has-err input, .has-err select');
  if (first) first.focus();
})();
</script>
<?php require_once 'includes/footer.php'; ?>
