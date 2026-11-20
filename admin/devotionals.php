<?php
/**
 * Admin > Devotionals: write and publish devotional plans (includes/devotionals.php).
 * A plan has a title, a short summary, a colour and days; each day has a passage (book, chapter,
 * verses), a reflection, a question and a prayer. Drafts are seen only by the team (preview on
 * plans.php); publishing shows the plan to members.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/devotionals.php';
require_once '../includes/bible_versions.php';

aw_require('devotionals_team'); // checks the CSRF token on every POST
aw_devo_tables($db);
$dv_back = SITE_URL . '/admin/devotionals.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $pid = (int)($_POST['plan_id'] ?? 0);
    $clean = function ($k, $max) { return trim(mb_substr(strip_tags(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max)); };
    $books = array_merge(aw_bible_canon()['ot'], aw_bible_canon()['nt']);
    try {
        if ($act === 'plan') {
            $title = $clean('title', 120);
            if ($title === '') { $_SESSION['aw_dv_flash'] = ['error', 'Give the plan a title.']; header('Location: ' . $dv_back . ($pid ? '?plan=' . $pid : '?new=1')); exit; }
            $vals = [$title, $clean('summary', 400), max(0, min(count(AW_DEVO_PALETTES) - 1, (int)($_POST['palette'] ?? 0)))];
            if ($pid) $db->prepare("UPDATE devo_plans SET title = ?, summary = ?, palette = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute(array_merge($vals, [$pid]));
            else { $db->prepare("INSERT INTO devo_plans (title, summary, palette, status, created_by) VALUES (?, ?, ?, 'draft', ?)")->execute(array_merge($vals, [(int)$_SESSION['user_id']])); $pid = (int)$db->lastInsertId(); }
            $_SESSION['aw_dv_flash'] = ['ok', 'The plan was saved.'];
        } elseif ($act === 'day' && $pid) {
            $did = (int)($_POST['day_id'] ?? 0);
            $book = in_array((string)($_POST['book'] ?? ''), $books, true) ? (string)$_POST['book'] : null;
            $vals = [$clean('day_title', 120), $book, $book ? max(1, (int)($_POST['chapter'] ?? 1)) : null, (int)($_POST['verse_from'] ?? 0) ?: null, (int)($_POST['verse_to'] ?? 0) ?: null,
                     $clean('reflection', 6000), $clean('question', 400), $clean('prayer', 2000)];
            if ($did) $db->prepare("UPDATE devo_days SET title = ?, book = ?, chapter = ?, verse_from = ?, verse_to = ?, reflection = ?, question = ?, prayer = ? WHERE id = ? AND plan_id = ?")->execute(array_merge($vals, [$did, $pid]));
            else {
                $next = (int)$db->query("SELECT COALESCE(MAX(day_no), 0) + 1 FROM devo_days WHERE plan_id = " . $pid)->fetchColumn();
                $db->prepare("INSERT INTO devo_days (plan_id, day_no, title, book, chapter, verse_from, verse_to, reflection, question, prayer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_merge([$pid, $next], $vals));
                $did = (int)$db->lastInsertId();
            }
            $db->prepare("UPDATE devo_plans SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pid]);
            $_SESSION['aw_dv_flash'] = ['ok', 'The day was saved.'];
            header('Location: ' . $dv_back . '?plan=' . $pid . '#d' . $did); exit;
        } elseif ($act === 'delete_day' && $pid) {
            $db->prepare("DELETE FROM devo_days WHERE id = ? AND plan_id = ?")->execute([(int)($_POST['day_id'] ?? 0), $pid]);
            // renumber so days stay 1, 2, 3...
            $n = 1; foreach ($db->query("SELECT id FROM devo_days WHERE plan_id = $pid ORDER BY day_no")->fetchAll(PDO::FETCH_COLUMN) as $id) { $db->prepare("UPDATE devo_days SET day_no = ? WHERE id = ?")->execute([-$n, $id]); $n++; }
            $db->exec("UPDATE devo_days SET day_no = -day_no WHERE plan_id = $pid");
            $_SESSION['aw_dv_flash'] = ['ok', 'The day was removed.'];
        } elseif (($act === 'publish' || $act === 'unpublish') && $pid) {
            $days = (int)$db->query("SELECT COUNT(*) FROM devo_days WHERE plan_id = $pid")->fetchColumn();
            if ($act === 'publish' && !$days) $_SESSION['aw_dv_flash'] = ['error', 'Add at least one day before publishing.'];
            else { $db->prepare("UPDATE devo_plans SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$act === 'publish' ? 'published' : 'draft', $pid]); $_SESSION['aw_dv_flash'] = ['ok', $act === 'publish' ? 'Published: members can now read it.' : 'Back to draft: only the team sees it.']; }
        } elseif ($act === 'delete_plan' && $pid) {
            $db->prepare("DELETE FROM devo_plans WHERE id = ?")->execute([$pid]);
            $_SESSION['aw_dv_flash'] = ['ok', 'The plan was deleted.'];
            header('Location: ' . $dv_back); exit;
        }
        if (function_exists('aw_audit')) aw_audit('devotional_' . $act, 'devotional', $pid, '');
    } catch (Throwable $e) {
        error_log('devotionals admin: ' . $e->getMessage());
        $_SESSION['aw_dv_flash'] = ['error', 'That could not be saved. Please try again.'];
    }
    header('Location: ' . $dv_back . ($pid ? '?plan=' . $pid : '')); exit;
}
$dv_flash = $_SESSION['aw_dv_flash'] ?? null; unset($_SESSION['aw_dv_flash']);
$dv_plan = isset($_GET['plan']) ? aw_devo_plan($db, (int)$_GET['plan']) : null;
$dv_new = isset($_GET['new']);
$dv_canon = aw_bible_canon();
$dv_readers = $dv_plan ? (int)$db->query("SELECT COUNT(*) FROM devo_progress WHERE plan_id = " . (int)$dv_plan['id'])->fetchColumn() : 0;

$pageTitle = 'Devotionals';
require_once '../includes/header.php';
$book_select = function ($sel) use ($dv_canon) {
    $h = '<option value="">No passage</option>';
    foreach (['ot' => 'Old Testament', 'nt' => 'New Testament'] as $t => $l) {
        $h .= '<optgroup label="' . $l . '">';
        foreach ($dv_canon[$t] as $b) $h .= '<option' . ($b === $sel ? ' selected' : '') . '>' . htmlspecialchars($b) . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
};
?>
<div class="dva">
    <header class="dva-head">
        <div><h1>Devotionals</h1><p>Write daily devotionals: a passage, a reflection, a question and a prayer for each day. Drafts are seen only by the team.</p></div>
        <div class="dva-acts"><?php if ($dv_plan || $dv_new): ?><a class="btn btn-outline" href="<?php echo $dv_back; ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> All plans</a><?php else: ?><a class="btn btn-primary" href="?new=1"><i class="fas fa-plus" aria-hidden="true"></i> New plan</a><?php endif; ?></div>
    </header>
    <?php if ($dv_flash): ?><div class="alert <?php echo $dv_flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>" role="status"><?php echo htmlspecialchars($dv_flash[1]); ?></div><?php endif; ?>

    <?php if (!$dv_plan && !$dv_new): $plans = aw_devo_plans($db, 0, true); ?>
        <?php if (!$plans): ?><p>No plans yet. <a href="?new=1">Write the first one</a>.</p><?php endif; ?>
        <div class="dva-list">
        <?php foreach ($plans as $p): $readers = (int)$db->query("SELECT COUNT(*) FROM devo_progress WHERE plan_id = " . (int)$p['id'])->fetchColumn(); ?>
            <a class="dva-plan" href="?plan=<?php echo (int)$p['id']; ?>">
                <span class="dva-badge <?php echo $p['status'] === 'published' ? 'is-live' : ''; ?>"><?php echo $p['status'] === 'published' ? 'Published' : 'Draft'; ?></span>
                <strong><?php echo htmlspecialchars($p['title']); ?></strong>
                <small><?php echo (int)$p['days']; ?> days · <?php echo $readers; ?> reading</small>
            </a>
        <?php endforeach; ?>
        </div>
    <?php else: $p = $dv_plan ?: ['id' => 0, 'title' => '', 'summary' => '', 'palette' => 0, 'status' => 'draft', 'days' => []]; ?>
        <section class="dva-card">
            <h2><?php echo $p['id'] ? 'The plan' : 'A new plan'; ?> <?php if ($p['id']): ?><span class="dva-badge <?php echo $p['status'] === 'published' ? 'is-live' : ''; ?>"><?php echo $p['status'] === 'published' ? 'Published' : 'Draft'; ?></span> <small><?php echo $dv_readers; ?> member<?php echo $dv_readers === 1 ? '' : 's'; ?> reading</small><?php endif; ?></h2>
            <form method="post" class="dva-form">
                <input type="hidden" name="act" value="plan"><input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>">
                <label>Title<input type="text" name="title" maxlength="120" required value="<?php echo htmlspecialchars($p['title']); ?>"></label>
                <label>Summary (who it is for, what it offers)<textarea name="summary" rows="2" maxlength="400"><?php echo htmlspecialchars((string)$p['summary']); ?></textarea></label>
                <fieldset class="dva-pal"><legend>Colour</legend><?php foreach (AW_DEVO_PALETTES as $i => $pal): ?><label style="--a:<?php echo $pal[0]; ?>;--b:<?php echo $pal[1]; ?>"><input type="radio" name="palette" value="<?php echo $i; ?>"<?php echo (int)$p['palette'] === $i ? ' checked' : ''; ?>><span class="sr-only">Colour <?php echo $i + 1; ?></span></label><?php endforeach; ?></fieldset>
                <div class="dva-row"><button class="btn btn-primary">Save</button>
                <?php if ($p['id']): ?>
                    <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/plans.php?plan=<?php echo (int)$p['id']; ?>" target="_blank" rel="noopener">Preview</a>
                    <button class="btn btn-outline" name="act" value="<?php echo $p['status'] === 'published' ? 'unpublish' : 'publish'; ?>"><?php echo $p['status'] === 'published' ? 'Back to draft' : 'Publish'; ?></button>
                    <button class="btn btn-danger" name="act" value="delete_plan" onclick="return confirm('Delete this plan and all its days? Members\' progress in it is forgotten.');">Delete plan</button>
                <?php endif; ?></div>
            </form>
        </section>
        <?php if ($p['id']): ?>
            <?php foreach (array_merge($p['days'], [['id' => 0, 'day_no' => count($p['days']) + 1, 'title' => '', 'book' => '', 'chapter' => '', 'verse_from' => '', 'verse_to' => '', 'reflection' => '', 'question' => '', 'prayer' => '']]) as $d): ?>
            <details class="dva-card dva-day" id="d<?php echo (int)$d['id']; ?>"<?php echo (!$d['id'] && !$p['days']) ? ' open' : ''; ?>>
                <summary><b><?php echo $d['id'] ? 'Day ' . (int)$d['day_no'] : '+ Add day ' . (int)$d['day_no']; ?></b> <?php echo htmlspecialchars((string)$d['title']); ?> <small><?php echo htmlspecialchars(aw_devo_ref($d)); ?></small></summary>
                <form method="post" class="dva-form">
                    <input type="hidden" name="act" value="day"><input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="day_id" value="<?php echo (int)$d['id']; ?>">
                    <label>Title of the day<input type="text" name="day_title" maxlength="120" value="<?php echo htmlspecialchars((string)$d['title']); ?>" placeholder="e.g. When you feel alone"></label>
                    <div class="dva-passage">
                        <label>Book<select name="book"><?php echo $book_select((string)$d['book']); ?></select></label>
                        <label>Chapter<input type="number" name="chapter" min="1" max="150" value="<?php echo htmlspecialchars((string)$d['chapter']); ?>"></label>
                        <label>From verse<input type="number" name="verse_from" min="1" max="176" value="<?php echo htmlspecialchars((string)$d['verse_from']); ?>"></label>
                        <label>To verse<input type="number" name="verse_to" min="1" max="176" value="<?php echo htmlspecialchars((string)$d['verse_to']); ?>"></label>
                    </div>
                    <label>Reflection (a blank line starts a new paragraph)<textarea name="reflection" rows="6" maxlength="6000"><?php echo htmlspecialchars((string)$d['reflection']); ?></textarea></label>
                    <label>A question to sit with<input type="text" name="question" maxlength="400" value="<?php echo htmlspecialchars((string)$d['question']); ?>"></label>
                    <label>A prayer<textarea name="prayer" rows="3" maxlength="2000"><?php echo htmlspecialchars((string)$d['prayer']); ?></textarea></label>
                    <div class="dva-row"><button class="btn btn-primary"><?php echo $d['id'] ? 'Save day ' . (int)$d['day_no'] : 'Add this day'; ?></button>
                        <?php if ($d['id']): ?><button class="btn btn-danger" name="act" value="delete_day" onclick="return confirm('Remove this day?');">Remove day</button><?php endif; ?></div>
                </form>
            </details>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<style>
.dva { max-width: 980px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.dva .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.dva-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
.dva-head h1 { margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; } .dva-head p { margin: 0; color: var(--text-light); max-width: 46em; }
.dva-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.dva-plan { display: grid; gap: 4px; padding: 16px; border-radius: 18px; background: var(--card-bg, #fff); border: 1px solid var(--border); text-decoration: none; color: inherit; }
.dva-plan:hover { border-color: #9c5457; } .dva-plan small { color: var(--text-light); }
.dva-badge { justify-self: start; padding: 2px 10px; border-radius: 999px; background: #f1ebe4; color: #6b5a48; font-size: .76rem; font-weight: 700; } .dva-badge.is-live { background: #e3efe6; color: #24452f; }
.dva-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 20px; padding: 18px; margin-bottom: 12px; }
.dva-card h2 { margin: 0 0 12px; font-size: 1.15rem; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; } .dva-card h2 small { color: var(--text-light); font-weight: 500; font-size: .85rem; }
.dva-form { display: grid; gap: 12px; } .dva-form label { display: grid; gap: 6px; font-weight: 600; font-size: .92rem; }
.dva-form input, .dva-form textarea, .dva-form select { width: 100%; padding: 10px 12px; border-radius: 12px; font: inherit; font-weight: 400; }
.dva-passage { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px; }
.dva-row { display: flex; gap: 8px; flex-wrap: wrap; }
.dva-pal { border: 0; padding: 0; margin: 0; display: flex; gap: 10px; } .dva-pal legend { font-weight: 600; font-size: .92rem; margin-bottom: 6px; }
.dva-pal label { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--a), var(--b)); cursor: pointer; position: relative; display: block; }
.dva-pal input { position: absolute; inset: 0; opacity: 0; cursor: pointer; } .dva-pal label:has(input:checked) { outline: 3px solid #9c5457; outline-offset: 3px; }
.dva-day summary { cursor: pointer; min-height: 32px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; } .dva-day summary small { color: var(--text-light); }
.dva-day[open] summary { margin-bottom: 12px; }
@media (max-width: 640px) { .dva-passage { grid-template-columns: 1fr 1fr; } }
</style>
<?php require_once '../includes/footer.php'; ?>
