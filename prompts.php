<?php
/**
 * Writing prompts (includes/prompts.php): this week's prompt, members' pieces, gentle feedback.
 *   prompts.php         this week's prompt (or the latest)
 *   prompts.php?p=3     a given prompt
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/prompts.php';
if (is_file(__DIR__ . '/includes/notifications.php')) require_once 'includes/notifications.php';
if (is_file(__DIR__ . '/includes/moderation.php')) require_once 'includes/moderation.php';

$wp_uid = (int)($_SESSION['user_id'] ?? 0);
$wp_self = SITE_URL . '/prompts.php';
aw_prompt_tables($db);
$wp_team = function_exists('aw_can') && aw_can('prompts_team');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $wp_uid) {
    $back = $wp_self . '?p=' . (int)($_POST['prompt_id'] ?? 0);
    $r = ['ok' => false, 'error' => 'This page had been open a long time. Please try again.'];
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        switch ((string)($_POST['action'] ?? '')) {
            case 'write': $r = aw_prompt_write($db, $wp_uid, (int)$_POST['prompt_id'], $_POST); if (!empty($r['ok'])) { $r['message'] = !empty($r['updated']) ? 'Your piece was updated.' : 'Thank you for sharing your words.'; $back .= '#r' . (int)$r['id']; } else $_SESSION['aw_wp_form'] = $_POST; break;
            case 'feedback': $r = aw_prompt_feedback($db, $wp_uid, (int)$_POST['response_id'], (string)($_POST['touched'] ?? ''), (string)($_POST['more'] ?? '')); if (!empty($r['ok'])) $r['message'] = 'Thank you. Your feedback was shared with the writer only.'; $back .= '#r' . (int)$_POST['response_id']; break;
            case 'delete': $r = aw_prompt_delete_own($db, $wp_uid, (int)$_POST['response_id']) ? ['ok' => true, 'message' => 'Your piece was deleted.'] : ['ok' => false, 'error' => 'That could not be deleted.']; break;
        }
    }
    $_SESSION['aw_wp_flash'] = [!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok']) ? $r['message'] : $r['error']];
    header('Location: ' . $back); exit;
}
$wp_flash = $_SESSION['aw_wp_flash'] ?? null; unset($_SESSION['aw_wp_flash']);
$wp_form = $_SESSION['aw_wp_form'] ?? []; unset($_SESSION['aw_wp_form']);

$wp_prompt = isset($_GET['p']) ? aw_prompt_get($db, (int)$_GET['p']) : aw_prompt_current($db);
if ($wp_prompt && $wp_prompt['status'] !== 'published' && !$wp_team) $wp_prompt = null;
if (!$wp_prompt) { $past = aw_prompt_past($db, 1); $wp_prompt = $past[0] ?? null; }
$wp_open = $wp_prompt && $wp_prompt['starts_on'] <= date('Y-m-d') && $wp_prompt['status'] === 'published';
$wp_current = $wp_prompt && $wp_prompt['ends_on'] >= date('Y-m-d');
$wp_list = ($wp_prompt && $wp_uid) ? aw_prompt_responses($db, (int)$wp_prompt['id'], $wp_uid) : [];
$wp_mine = null; foreach ($wp_list as $r) if ((int)$r['user_id'] === $wp_uid) $wp_mine = $r;
$wp_past = aw_prompt_past($db, 12);
$wp_token = $wp_uid ? generate_csrf_token() : '';

$pageTitle = 'Writing prompts';
require_once 'includes/header.php';
if (function_exists('aw_report_assets')) echo aw_report_assets();
?>
<main class="wp">
    <header class="wp-hero">
        <p class="wp-kicker"><i class="fas fa-feather-pointed" aria-hidden="true"></i> Writing prompts<?php echo $wp_prompt ? ' · ' . ($wp_current ? 'this week' : 'from ' . date('j M Y', strtotime($wp_prompt['starts_on']))) : ''; ?></p>
        <?php if ($wp_prompt): ?>
            <h1><?php echo htmlspecialchars($wp_prompt['title']); ?></h1>
            <p class="wp-lead"><?php echo nl2br(htmlspecialchars((string)$wp_prompt['body'])); ?></p>
            <?php if ($wp_prompt['verse']): ?><p class="wp-verse"><i class="fas fa-book-bible" aria-hidden="true"></i> <?php echo htmlspecialchars($wp_prompt['verse']); ?></p><?php endif; ?>
            <?php if ($wp_current): ?><p class="wp-when">Open until <?php echo date('l j F', strtotime($wp_prompt['ends_on'])); ?></p><?php endif; ?>
            <?php if ($wp_prompt['status'] !== 'published'): ?><p class="wp-when"><strong>Draft:</strong> only the team sees this.</p><?php endif; ?>
        <?php else: ?>
            <h1>Write with us</h1><p class="wp-lead">A new prompt arrives each week: a few words to start you writing, a poem, a prayer, a memory. Please come back soon.</p>
        <?php endif; ?>
    </header>
    <?php if ($wp_flash): ?><div class="wp-alert <?php echo $wp_flash[0] === 'ok' ? 'is-ok' : 'is-error'; ?>" role="status"><?php echo htmlspecialchars($wp_flash[1]); ?></div><?php endif; ?>

    <?php if ($wp_prompt && !$wp_uid): ?>
        <section class="wp-card wp-guest"><h2>Join to write and read</h2><p>Members share their pieces and encourage one another. Joining is free.</p>
            <p><a class="wp-btn wp-btn-primary" href="<?php echo SITE_URL; ?>/register.php">Join free</a> <a class="wp-btn" href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode('/prompts.php'); ?>">Log in</a></p></section>
    <?php elseif ($wp_prompt): ?>
    <div class="wp-layout">
        <section class="wp-pieces" aria-labelledby="wpPiecesT">
            <h2 id="wpPiecesT"><?php echo count($wp_list); ?> piece<?php echo count($wp_list) === 1 ? '' : 's'; ?> so far</h2>
            <p class="wp-sub">When a piece speaks to you, tell the writer what touched you. Feedback goes to the writer only.</p>
            <?php if (!$wp_list): ?><div class="wp-empty"><i class="fas fa-pen-nib" aria-hidden="true"></i><p>Nobody has written yet. Yours could be the first.</p></div><?php endif; ?>
            <?php foreach ($wp_list as $i => $r): $mine = (int)$r['user_id'] === $wp_uid; ?>
            <article class="wp-piece<?php echo $mine ? ' is-mine' : ''; ?>" id="r<?php echo (int)$r['id']; ?>" style="--i:<?php echo min($i, 10); ?>">
                <header><strong><?php echo htmlspecialchars($r['title'] !== '' && $r['title'] !== null ? $r['title'] : 'Untitled'); ?></strong><small>by <?php echo htmlspecialchars($mine ? 'you' : awDisplayName($r)); ?> · <?php echo date('j M', strtotime($r['created_at'])); ?></small></header>
                <div class="wp-text<?php echo mb_strlen($r['body']) > 700 ? ' is-long' : ''; ?>"><?php echo nl2br(htmlspecialchars($r['body'])); ?></div>
                <?php if (mb_strlen($r['body']) > 700): ?><button type="button" class="wp-more" onclick="this.previousElementSibling.classList.remove('is-long');this.remove()">Read it all</button><?php endif; ?>
                <footer>
                <?php if ($mine): $fb = aw_prompt_feedback_for($db, (int)$r['id'], $wp_uid); ?>
                    <details class="wp-fb-mine"<?php echo $fb ? ' open' : ''; ?>><summary><?php echo $fb ? count($fb) . ' gentle word' . (count($fb) === 1 ? '' : 's') . ' for you' : 'No feedback yet'; ?></summary>
                        <?php foreach ($fb as $f): ?><div class="wp-fb"><p><b>What touched <?php echo htmlspecialchars(awDisplayName($f)); ?>:</b> <?php echo nl2br(htmlspecialchars((string)$f['touched'])); ?></p><?php if (trim((string)$f['more']) !== ''): ?><p><b>What they would love more of:</b> <?php echo nl2br(htmlspecialchars((string)$f['more'])); ?></p><?php endif; ?>
                            <?php if (function_exists('aw_report_controls')) echo aw_report_controls('prompt_feedback', (int)$f['id'], (int)$f['user_id'], awDisplayName($f)); ?></div><?php endforeach; ?>
                    </details>
                    <form method="post" class="wp-own"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($wp_token); ?>"><input type="hidden" name="prompt_id" value="<?php echo (int)$wp_prompt['id']; ?>"><input type="hidden" name="response_id" value="<?php echo (int)$r['id']; ?>">
                        <a class="wp-btn wp-sm" href="#wpWrite">Edit</a> <button class="wp-btn wp-sm wp-del" name="action" value="delete" onclick="return confirm('Delete your piece?');">Delete</button></form>
                <?php else: ?>
                    <details class="wp-give"><summary><?php echo !empty($r['i_gave']) ? 'You shared feedback · change it' : 'Leave gentle feedback'; ?></summary>
                        <form method="post" class="wp-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($wp_token); ?>"><input type="hidden" name="action" value="feedback"><input type="hidden" name="prompt_id" value="<?php echo (int)$wp_prompt['id']; ?>"><input type="hidden" name="response_id" value="<?php echo (int)$r['id']; ?>">
                            <label>What touched me<textarea name="touched" rows="2" maxlength="600" required placeholder="A line, an image, a feeling that stayed with you"></textarea></label>
                            <label>What I would love more of <small>(optional)</small><textarea name="more" rows="2" maxlength="600" placeholder="Something you would like to hear more about"></textarea></label>
                            <button class="wp-btn wp-btn-primary wp-sm">Send to the writer</button></form>
                    </details>
                    <?php if (function_exists('aw_report_controls')) echo aw_report_controls('prompt_response', (int)$r['id'], (int)$r['user_id'], awDisplayName($r)); ?>
                <?php endif; ?>
                </footer>
            </article>
            <?php endforeach; ?>
        </section>
        <aside class="wp-side">
            <?php if ($wp_open): ?>
            <section class="wp-card" id="wpWrite" aria-labelledby="wpWriteT">
                <h2 id="wpWriteT"><?php echo $wp_mine ? 'Your piece' : 'Write your piece'; ?></h2>
                <form method="post" class="wp-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($wp_token); ?>"><input type="hidden" name="action" value="write"><input type="hidden" name="prompt_id" value="<?php echo (int)$wp_prompt['id']; ?>">
                    <label>Title <small>(optional)</small><input type="text" name="title" maxlength="120" value="<?php echo htmlspecialchars((string)($wp_form['title'] ?? ($wp_mine['title'] ?? ''))); ?>"></label>
                    <label>Your words<textarea name="body" rows="10" maxlength="<?php echo AW_PROMPT_MAX; ?>" required placeholder="A poem, a prayer, a memory, a few honest lines."><?php echo htmlspecialchars((string)($wp_form['body'] ?? ($wp_mine['body'] ?? ''))); ?></textarea></label>
                    <p class="wp-sub">Members can read it and send you gentle feedback. Please follow the <a href="<?php echo SITE_URL; ?>/community_guidelines.php">community guidelines</a>.</p>
                    <button class="wp-btn wp-btn-primary"><?php echo $wp_mine ? 'Save changes' : 'Share my piece'; ?></button>
                </form>
            </section>
            <?php endif; ?>
            <?php if (count($wp_past) > 1): ?>
            <section class="wp-card"><h2>Earlier prompts</h2><ul class="wp-past"><?php foreach ($wp_past as $p): ?><li><a href="<?php echo $wp_self; ?>?p=<?php echo (int)$p['id']; ?>"<?php echo (int)$p['id'] === (int)$wp_prompt['id'] ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></a><small><?php echo date('j M', strtotime($p['starts_on'])); ?> · <?php echo (int)$p['n']; ?> piece<?php echo (int)$p['n'] === 1 ? '' : 's'; ?></small></li><?php endforeach; ?></ul></section>
            <?php endif; ?>
            <?php if ($wp_team): ?><p class="wp-team"><a href="<?php echo SITE_URL; ?>/admin/prompts.php"><i class="fas fa-pen" aria-hidden="true"></i> Write or schedule prompts</a></p><?php endif; ?>
        </aside>
    </div>
    <?php elseif ($wp_team): ?><p class="wp-team"><a href="<?php echo SITE_URL; ?>/admin/prompts.php"><i class="fas fa-pen" aria-hidden="true"></i> Write or schedule prompts</a></p><?php endif; ?>
</main>
<style>
.wp { --wp-rose: #9c5457; --wp-card: var(--card-bg, #fff); --wp-line: rgba(156, 84, 87, .16); --wp-mute: #6d5a5a; max-width: 1150px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .wp { --wp-rose: #f0c4c5; --wp-mute: #cdb8b8; --wp-line: rgba(240, 196, 197, .16); }
.wp-hero { padding: 32px 30px; border-radius: 28px; color: #fff; background: radial-gradient(90% 130% at 0% 0%, rgba(255, 223, 170, .4), transparent 55%), linear-gradient(140deg, #26324a, #5a3d5c 55%, #9c5457); box-shadow: 0 20px 44px rgba(38, 50, 74, .28); }
.wp-kicker { margin: 0; text-transform: uppercase; letter-spacing: .12em; font-size: .78rem; font-weight: 700; opacity: .92; }
.wp-hero h1 { margin: 8px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.9rem, 4.6vw, 2.8rem); color: #fff; }
.wp-lead { margin: 0; max-width: 44em; font-size: 1.08rem; line-height: 1.65; opacity: .96; }
.wp-verse { margin: 12px 0 0; font-style: italic; opacity: .92; } .wp-when { margin: 10px 0 0; font-size: .9rem; opacity: .85; }
.wp-alert { margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .wp-alert.is-ok { background: #e3efe6; color: #24452f; } .wp-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.wp-layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 18px; margin-top: 18px; align-items: start; }
.wp-side { position: sticky; top: 84px; display: grid; gap: 14px; }
.wp-card { background: var(--wp-card); border: 1px solid var(--wp-line); border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); }
.wp-card h2, .wp-pieces > h2 { margin: 0 0 8px; font-family: 'Playfair Display', Georgia, serif; font-size: 1.3rem; }
.wp-guest { margin-top: 18px; text-align: center; }
.wp-sub { color: var(--wp-mute); font-size: .9rem; margin: 0 0 12px; line-height: 1.5; } .wp-sub a { color: var(--wp-rose); }
.wp-empty { text-align: center; padding: 36px 16px; border: 1px dashed var(--wp-line); border-radius: 20px; color: var(--wp-mute); } .wp-empty i { font-size: 1.8rem; color: var(--wp-rose); }
.wp-piece { background: var(--wp-card); border: 1px solid var(--wp-line); border-radius: 22px; padding: 20px; margin-bottom: 12px; animation: wpIn .45s ease both; animation-delay: calc(var(--i) * 40ms); }
@keyframes wpIn { from { opacity: 0; transform: translateY(8px); } }
.wp-piece.is-mine { border-color: rgba(214, 168, 92, .6); box-shadow: inset 4px 0 0 #d6a85c; }
.wp-piece header { display: grid; gap: 2px; margin-bottom: 10px; } .wp-piece header strong { font-family: 'Playfair Display', Georgia, serif; font-size: 1.2rem; } .wp-piece header small { color: var(--wp-mute); }
.wp-text { font-family: Georgia, 'Times New Roman', serif; font-size: 1.05rem; line-height: 1.8; overflow-wrap: anywhere; }
.wp-text.is-long { max-height: 15em; overflow: hidden; -webkit-mask-image: linear-gradient(#000 70%, transparent); mask-image: linear-gradient(#000 70%, transparent); }
.wp-more { background: none; border: 0; color: var(--wp-rose); font-weight: 700; cursor: pointer; padding: 6px 0; }
.wp-piece footer { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; margin-top: 12px; }
.wp-give, .wp-fb-mine { flex: 1 1 100%; } .wp-give summary, .wp-fb-mine summary { cursor: pointer; color: var(--wp-rose); font-weight: 700; min-height: 40px; display: inline-flex; align-items: center; }
.wp-fb { padding: 10px 12px; border-radius: 14px; background: rgba(156, 84, 87, .06); margin-top: 8px; } .wp-fb p { margin: 0 0 4px; line-height: 1.55; }
.wp-form { display: grid; gap: 10px; margin-top: 6px; } .wp-form label { display: grid; gap: 6px; font-weight: 600; font-size: .92rem; } .wp-form small { font-weight: 400; color: var(--wp-mute); }
.wp-form input, .wp-form textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.wp-form textarea[name=body] { font-family: Georgia, 'Times New Roman', serif; font-size: 1.04rem; line-height: 1.75; }
.wp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--wp-rose); background: transparent; color: var(--wp-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; justify-self: start; }
.wp-btn:hover { background: var(--wp-rose); color: #fff; } html[data-theme="dark"] .wp-btn:hover { color: #2c1e1e; }
.wp-btn-primary { background: var(--wp-rose); color: #fff; } html[data-theme="dark"] .wp-btn-primary { color: #2c1e1e; }
.wp-sm { min-height: 38px; padding: 0 12px; font-size: .84rem; } .wp-del { border-color: rgba(179, 38, 30, .35); color: #b3261e; } .wp-del:hover { background: #b3261e; border-color: #b3261e; color: #fff; }
.wp-own { display: flex; gap: 6px; }
.wp-past { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } .wp-past a { color: var(--wp-rose); font-weight: 600; text-decoration: none; } .wp-past a[aria-current] { color: var(--text); } .wp-past small { display: block; color: var(--wp-mute); }
.wp-team a { color: var(--wp-rose); font-weight: 600; }
@media (max-width: 900px) { .wp-layout { grid-template-columns: 1fr; } .wp-side { position: static; order: -1; } }
@media (prefers-reduced-motion: reduce) { .wp-piece { animation: none; } }
</style>
<?php require_once 'includes/footer.php'; ?>
