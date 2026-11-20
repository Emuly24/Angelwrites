<?php
/**
 * Admin > Writing prompts (includes/prompts.php): write prompts, give them dates, publish them.
 * A published prompt opens on its start date; the one open today is "this week's prompt".
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/prompts.php';

aw_require('manage_content'); // checks the CSRF token on every POST
aw_prompt_tables($db);
$pa_back = SITE_URL . '/admin/prompts.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['act'] ?? 'save');
    try {
        if ($act === 'delete' && $id) { $db->prepare("DELETE FROM writing_prompts WHERE id = ?")->execute([$id]); $_SESSION['aw_pa_flash'] = ['ok', 'The prompt and its pieces were deleted.']; }
        elseif (($act === 'publish' || $act === 'unpublish') && $id) { $db->prepare("UPDATE writing_prompts SET status = ? WHERE id = ?")->execute([$act === 'publish' ? 'published' : 'draft', $id]); $_SESSION['aw_pa_flash'] = ['ok', $act === 'publish' ? 'Published: it opens on its start date.' : 'Back to draft.']; }
        else {
            $title = trim(mb_substr(strip_tags((string)($_POST['title'] ?? '')), 0, 140));
            $body = trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))), 0, 1500));
            $verse = trim(mb_substr(strip_tags((string)($_POST['verse'] ?? '')), 0, 120));
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['starts_on'] ?? '')) ? $_POST['starts_on'] : date('Y-m-d');
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['ends_on'] ?? '')) ? $_POST['ends_on'] : date('Y-m-d', strtotime($from . ' +6 days'));
            if ($to < $from) $to = $from;
            if ($title === '') { $_SESSION['aw_pa_flash'] = ['error', 'Give the prompt a title.']; header('Location: ' . $pa_back); exit; }
            if ($id) $db->prepare("UPDATE writing_prompts SET title = ?, body = ?, verse = ?, starts_on = ?, ends_on = ? WHERE id = ?")->execute([$title, $body, $verse, $from, $to, $id]);
            else $db->prepare("INSERT INTO writing_prompts (title, body, verse, starts_on, ends_on, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$title, $body, $verse, $from, $to, !empty($_POST['publish_now']) ? 'published' : 'draft', (int)$_SESSION['user_id']]);
            $_SESSION['aw_pa_flash'] = ['ok', 'Saved.'];
        }
        if (function_exists('aw_audit')) aw_audit('prompt_' . $act, 'prompt', $id, '');
    } catch (Throwable $e) { error_log('prompts admin: ' . $e->getMessage()); $_SESSION['aw_pa_flash'] = ['error', 'That could not be saved.']; }
    header('Location: ' . $pa_back); exit;
}
$pa_flash = $_SESSION['aw_pa_flash'] ?? null; unset($_SESSION['aw_pa_flash']);
$pa_rows = $db->query("SELECT p.*, (SELECT COUNT(*) FROM prompt_responses r WHERE r.prompt_id = p.id AND r.status = 'published') AS n FROM writing_prompts p ORDER BY p.starts_on DESC")->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');
$pa_next = date('Y-m-d', strtotime('monday next week'));

$pageTitle = 'Writing prompts';
require_once '../includes/header.php';
?>
<div class="pa">
    <header class="pa-head"><div><h1>Writing prompts</h1><p>A prompt invites members to write a short piece; others leave gentle feedback for the writer only. Give each prompt a week (or any dates) and publish it: it opens on its start date.</p></div>
        <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/prompts.php">See the members' page</a></header>
    <?php if ($pa_flash): ?><div class="alert <?php echo $pa_flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>" role="status"><?php echo htmlspecialchars($pa_flash[1]); ?></div><?php endif; ?>
    <details class="pa-card" <?php echo !$pa_rows ? 'open' : ''; ?>><summary><b>+ A new prompt</b></summary>
        <form method="post" class="pa-form">
            <label>Title<input type="text" name="title" maxlength="140" required placeholder="e.g. Peace in an unexpected place"></label>
            <label>The prompt<textarea name="body" rows="3" maxlength="1500" placeholder="A sentence or two that gets people writing."></textarea></label>
            <div class="pa-row"><label>Verse <small>(optional)</small><input type="text" name="verse" maxlength="120" placeholder="e.g. John 14:27"></label>
                <label>Opens<input type="date" name="starts_on" value="<?php echo $pa_next; ?>"></label><label>Closes<input type="date" name="ends_on" value="<?php echo date('Y-m-d', strtotime($pa_next . ' +6 days')); ?>"></label></div>
            <label class="pa-check"><input type="checkbox" name="publish_now" value="1" checked> Publish (it opens on its start date)</label>
            <button class="btn btn-primary">Save prompt</button>
        </form>
    </details>
    <?php foreach ($pa_rows as $p): $live = $p['status'] === 'published'; $now = $live && $p['starts_on'] <= $today && $p['ends_on'] >= $today; ?>
    <details class="pa-card">
        <summary><span class="pa-badge <?php echo $now ? 'is-now' : ($live ? 'is-live' : ''); ?>"><?php echo $now ? 'Open now' : ($live ? ($p['starts_on'] > $today ? 'Scheduled' : 'Closed') : 'Draft'); ?></span>
            <b><?php echo htmlspecialchars($p['title']); ?></b> <small><?php echo date('j M', strtotime($p['starts_on'])); ?> – <?php echo date('j M Y', strtotime($p['ends_on'])); ?> · <?php echo (int)$p['n']; ?> piece<?php echo (int)$p['n'] === 1 ? '' : 's'; ?></small></summary>
        <form method="post" class="pa-form"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
            <label>Title<input type="text" name="title" maxlength="140" required value="<?php echo htmlspecialchars($p['title']); ?>"></label>
            <label>The prompt<textarea name="body" rows="3" maxlength="1500"><?php echo htmlspecialchars((string)$p['body']); ?></textarea></label>
            <div class="pa-row"><label>Verse<input type="text" name="verse" maxlength="120" value="<?php echo htmlspecialchars((string)$p['verse']); ?>"></label>
                <label>Opens<input type="date" name="starts_on" value="<?php echo htmlspecialchars($p['starts_on']); ?>"></label><label>Closes<input type="date" name="ends_on" value="<?php echo htmlspecialchars($p['ends_on']); ?>"></label></div>
            <div class="pa-acts"><button class="btn btn-primary btn-sm">Save</button>
                <button class="btn btn-outline btn-sm" name="act" value="<?php echo $live ? 'unpublish' : 'publish'; ?>"><?php echo $live ? 'Back to draft' : 'Publish'; ?></button>
                <a class="btn btn-outline btn-sm" href="<?php echo SITE_URL; ?>/prompts.php?p=<?php echo (int)$p['id']; ?>" target="_blank" rel="noopener">Open</a>
                <button class="btn btn-danger btn-sm" name="act" value="delete" onclick="return confirm('Delete this prompt and every piece written for it?');">Delete</button></div>
        </form>
    </details>
    <?php endforeach; ?>
</div>
<style>
.pa { max-width: 960px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.pa-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; } .pa-head h1 { margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; } .pa-head p { margin: 0; color: var(--text-light); max-width: 46em; }
.pa-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; padding: 14px 16px; margin-bottom: 10px; }
.pa-card summary { cursor: pointer; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; min-height: 36px; } .pa-card summary small { color: var(--text-light); } .pa-card[open] summary { margin-bottom: 12px; }
.pa-badge { padding: 2px 10px; border-radius: 999px; background: #f1ebe4; color: #6b5a48; font-size: .76rem; font-weight: 700; } .pa-badge.is-live { background: #e8e4f3; color: #3f3266; } .pa-badge.is-now { background: #e3efe6; color: #24452f; }
.pa-form { display: grid; gap: 10px; } .pa-form label { display: grid; gap: 6px; font-weight: 600; font-size: .92rem; } .pa-form small { font-weight: 400; color: var(--text-light); }
.pa-form input[type=text], .pa-form input[type=date], .pa-form textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.pa-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; } .pa-acts { display: flex; gap: 6px; flex-wrap: wrap; }
.pa-check { display: flex !important; gap: 8px; align-items: center; font-weight: 500 !important; } .pa-check input { accent-color: #9c5457; width: 18px; height: 18px; }
@media (max-width: 640px) { .pa-row { grid-template-columns: 1fr; } }
</style>
<?php require_once '../includes/footer.php'; ?>
