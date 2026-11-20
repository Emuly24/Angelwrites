<?php
/**
 * My journal: private writing for each member (includes/journal.php).
 * List, write, edit, delete, search, export as text, and turn an entry into a Stories & Help draft.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/journal.php';

if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/journal.php')); exit; }
$jn_uid = (int)$_SESSION['user_id'];
aw_journal_table($db);
$jn_self = SITE_URL . '/journal.php';

// export: the member's whole journal as a text file
if (isset($_GET['export'])) {
    $all = array_reverse(aw_journal_list($db, $jn_uid));
    $txt = "My AngelWrites journal\nExported " . date('j F Y') . "\n\n";
    foreach ($all as $e) {
        $txt .= str_repeat('=', 40) . "\n" . date('l j F Y', strtotime($e['entry_date'])) . ($e['mood'] ? ' · ' . AW_JOURNAL_MOODS[$e['mood']][0] : '') . "\n"
              . ($e['title'] !== '' ? $e['title'] . "\n" : '') . ($e['verse'] !== '' ? $e['verse'] . "\n" : '') . "\n" . $e['body'] . "\n\n";
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-journal-' . date('Y-m-d') . '.txt"');
    header('Cache-Control: private, no-store');
    echo $txt;
    exit;
}

$jn_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $jn_err = 'This page had been open a long time, so nothing was saved. Your words are still below: please save again.';
    } else {
        $act = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);
        if ($act === 'delete') {
            $_SESSION['aw_jn_flash'] = aw_journal_delete($db, $jn_uid, $id) ? 'The entry was deleted.' : 'That entry could not be deleted.';
            header('Location: ' . $jn_self); exit;
        }
        if ($act === 'share') {
            // a draft in Stories & Help: the member chooses who sees it, and can change anything before sending
            $e = aw_journal_get($db, $jn_uid, $id);
            if ($e) { $_SESSION['aw_story_from_journal'] = ['title' => $e['title'] !== '' ? $e['title'] : 'From my journal', 'body' => $e['body']]; header('Location: ' . SITE_URL . '/story_write.php?type=story'); exit; }
            header('Location: ' . $jn_self); exit;
        }
        $r = aw_journal_save($db, $jn_uid, $id, $_POST);
        if (!empty($r['ok'])) { $_SESSION['aw_jn_flash'] = 'Saved. Only you can read it.'; header('Location: ' . $jn_self . '?open=' . (int)$r['id'] . '#e' . (int)$r['id']); exit; }
        $jn_err = $r['error'];
    }
}
$jn_flash = $_SESSION['aw_jn_flash'] ?? '';
unset($_SESSION['aw_jn_flash']);

$jn_q = trim((string)($_GET['q'] ?? ''));
$jn_all = aw_journal_list($db, $jn_uid, $jn_q);
$jn_edit = isset($_GET['edit']) ? aw_journal_get($db, $jn_uid, (int)$_GET['edit']) : null;
$jn_new = isset($_GET['new']) || $jn_edit || $jn_err !== '' || (!$jn_all && $jn_q === '');
$jn_v = $jn_err !== '' ? ['id' => (int)($_POST['id'] ?? 0), 'title' => (string)($_POST['title'] ?? ''), 'body' => (string)($_POST['body'] ?? ''), 'verse' => (string)($_POST['verse'] ?? ''), 'mood' => (string)($_POST['mood'] ?? ''), 'entry_date' => (string)($_POST['entry_date'] ?? date('Y-m-d'))]
    : ($jn_edit ?: ['id' => 0, 'title' => mb_substr(trim(strip_tags((string)($_GET['prompt'] ?? ''))), 0, 160), 'body' => '', 'verse' => '', 'mood' => '', 'entry_date' => date('Y-m-d')]);
$jn_open = (int)($_GET['open'] ?? 0);
// entries grouped by month
$jn_months = [];
foreach ($jn_all as $e) $jn_months[date('F Y', strtotime($e['entry_date']))][] = $e;
$jn_days = count(array_unique(array_map(function ($e) { return $e['entry_date']; }, aw_journal_list($db, $jn_uid))));

$pageTitle = 'My journal';
$aw_noindex = true;
require_once 'includes/header.php';
?>
<main class="jn">
    <header class="jn-head">
        <div>
            <p class="jn-kicker"><i class="fas fa-lock" aria-hidden="true"></i> Private · only you can read this</p>
            <h1>My journal</h1>
            <p class="jn-lead">A quiet place for prayers, thoughts and what you are learning. What you write is encrypted and never shown to anyone, the AngelWrites team included.</p>
        </div>
        <div class="jn-head-acts">
            <?php if (!$jn_new): ?><a class="jn-btn jn-btn-light" href="<?php echo $jn_self; ?>?new=1"><i class="fas fa-pen" aria-hidden="true"></i> New entry</a><?php endif; ?>
            <?php if ($jn_days): ?><a class="jn-btn jn-btn-ghost" href="<?php echo $jn_self; ?>?export=1"><i class="fas fa-file-arrow-down" aria-hidden="true"></i> Download my journal</a><?php endif; ?>
        </div>
    </header>

    <?php if ($jn_flash): ?><div class="jn-alert is-ok" role="status"><i class="fas fa-circle-check" aria-hidden="true"></i> <?php echo htmlspecialchars($jn_flash); ?></div><?php endif; ?>
    <?php if ($jn_err): ?><div class="jn-alert is-error" role="alert"><?php echo htmlspecialchars($jn_err); ?></div><?php endif; ?>

    <?php if ($jn_new): ?>
    <section class="jn-card jn-write" aria-labelledby="jnWriteT">
        <h2 id="jnWriteT"><?php echo $jn_v['id'] ? 'Edit your entry' : 'Write'; ?></h2>
        <?php if (!$jn_v['id']): ?><p class="jn-prompt"><i class="fas fa-feather-pointed" aria-hidden="true"></i> <span>Today's prompt: <em><?php echo htmlspecialchars(aw_journal_prompt()); ?></em></span> <button type="button" class="jn-link" id="jnUsePrompt">Use it</button></p><?php endif; ?>
        <form method="post" action="<?php echo $jn_self; ?>" class="jn-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$jn_v['id']; ?>">
            <div class="jn-row">
                <label class="jn-f"><span>Title <small>(optional)</small></span><input type="text" name="title" id="jnTitle" maxlength="160" value="<?php echo htmlspecialchars($jn_v['title']); ?>"></label>
                <label class="jn-f jn-date"><span>Date</span><input type="date" name="entry_date" value="<?php echo htmlspecialchars($jn_v['entry_date']); ?>" max="<?php echo date('Y-m-d'); ?>"></label>
            </div>
            <label class="jn-f"><span>Your words</span><textarea name="body" id="jnBody" rows="12" maxlength="<?php echo AW_JOURNAL_MAX; ?>" required placeholder="Write freely. Nobody else will read this."><?php echo htmlspecialchars($jn_v['body']); ?></textarea></label>
            <fieldset class="jn-moods">
                <legend>How are you feeling? <small>(optional)</small></legend>
                <label class="jn-mood"><input type="radio" name="mood" value=""<?php echo $jn_v['mood'] === '' ? ' checked' : ''; ?>><span>Rather not say</span></label>
                <?php foreach (AW_JOURNAL_MOODS as $k => [$l, $em]): ?>
                <label class="jn-mood"><input type="radio" name="mood" value="<?php echo $k; ?>"<?php echo $jn_v['mood'] === $k ? ' checked' : ''; ?>><span><span aria-hidden="true"><?php echo $em; ?></span> <?php echo $l; ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <label class="jn-f"><span>A verse to hold on to <small>(optional)</small></span><input type="text" name="verse" maxlength="200" placeholder="e.g. Isaiah 41:10 — Fear not, for I am with you" value="<?php echo htmlspecialchars($jn_v['verse']); ?>"></label>
            <div class="jn-acts">
                <button type="submit" class="jn-btn jn-btn-primary"><i class="fas fa-lock" aria-hidden="true"></i> Save privately</button>
                <?php if ($jn_all || $jn_v['id']): ?><a class="jn-btn jn-btn-ghost" href="<?php echo $jn_self; ?>">Cancel</a><?php endif; ?>
                <span class="jn-count" id="jnCount" aria-live="polite"></span>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($jn_days): ?>
    <section aria-labelledby="jnListT">
        <div class="jn-list-head">
            <h2 id="jnListT"><?php echo $jn_q !== '' ? count($jn_all) . ' entr' . (count($jn_all) === 1 ? 'y' : 'ies') . ' with "' . htmlspecialchars($jn_q) . '"' : 'Your entries'; ?> <small><?php echo $jn_q === '' ? $jn_days . ' day' . ($jn_days === 1 ? '' : 's') . ' written' : ''; ?></small></h2>
            <form method="get" class="jn-search" role="search"><label class="sr-only" for="jnQ">Search your journal</label><input type="search" id="jnQ" name="q" value="<?php echo htmlspecialchars($jn_q); ?>" placeholder="Search your journal"><button class="jn-btn jn-btn-ghost" type="submit"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><span class="sr-only">Search</span></button><?php if ($jn_q !== ''): ?><a href="<?php echo $jn_self; ?>" class="jn-link">Show all</a><?php endif; ?></form>
        </div>
        <?php if (!$jn_all): ?><p class="jn-none">Nothing found.</p><?php endif; ?>
        <?php foreach ($jn_months as $m => $list): ?>
        <h3 class="jn-month"><?php echo htmlspecialchars($m); ?></h3>
        <div class="jn-entries">
            <?php foreach ($list as $e): $mo = AW_JOURNAL_MOODS[$e['mood']] ?? null; ?>
            <details class="jn-entry" id="e<?php echo $e['id']; ?>"<?php echo $jn_open === $e['id'] ? ' open' : ''; ?>>
                <summary>
                    <span class="jn-day"><b><?php echo date('j', strtotime($e['entry_date'])); ?></b><small><?php echo date('D', strtotime($e['entry_date'])); ?></small></span>
                    <span class="jn-sum"><strong><?php echo htmlspecialchars($e['title'] !== '' ? $e['title'] : mb_strimwidth($e['body'], 0, 70, '…')); ?></strong>
                        <small><?php if ($mo): ?><span aria-hidden="true"><?php echo $mo[1]; ?></span> <?php echo $mo[0]; ?> · <?php endif; ?><?php echo number_format(str_word_count($e['body'])); ?> words</small></span>
                </summary>
                <div class="jn-body">
                    <?php if ($e['verse'] !== ''): ?><p class="jn-verse"><i class="fas fa-book-bible" aria-hidden="true"></i> <?php echo htmlspecialchars($e['verse']); ?></p><?php endif; ?>
                    <div class="jn-text"><?php echo nl2br(htmlspecialchars($e['body'])); ?></div>
                    <div class="jn-entry-acts">
                        <a class="jn-btn jn-btn-ghost jn-sm" href="<?php echo $jn_self; ?>?edit=<?php echo $e['id']; ?>"><i class="fas fa-pen" aria-hidden="true"></i> Edit</a>
                        <form method="post" action="<?php echo $jn_self; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                            <button class="jn-btn jn-btn-ghost jn-sm" name="action" value="share" title="Opens a Stories &amp; Help draft: you choose who sees it before anything is shared"><i class="fas fa-seedling" aria-hidden="true"></i> Share as a story…</button>
                            <button class="jn-btn jn-btn-ghost jn-sm jn-del" name="action" value="delete" onclick="return confirm('Delete this entry for good?');"><i class="fas fa-trash" aria-hidden="true"></i> Delete</button>
                        </form>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
<script>
(function () {
  var b = document.getElementById('jnBody'), c = document.getElementById('jnCount'), use = document.getElementById('jnUsePrompt');
  if (b && c) { var f = function () { var n = (b.value.trim().match(/\S+/g) || []).length; c.textContent = n ? n + ' word' + (n === 1 ? '' : 's') : ''; }; b.addEventListener('input', f); f(); }
  if (use && b) use.addEventListener('click', function () { var t = document.getElementById('jnTitle'); var p = use.parentNode.querySelector('em').textContent; if (t && !t.value) t.value = p; b.focus(); });
  // unsaved words are not lost by accident
  var dirty = false; if (b) { b.addEventListener('input', function () { dirty = true; }); b.form.addEventListener('submit', function () { dirty = false; }); window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } }); }
})();
</script>
<style>
.jn { --jn-rose: #9c5457; --jn-card: var(--card-bg, #fff); --jn-line: rgba(156, 84, 87, .16); --jn-mute: #6d5a5a; max-width: 900px; margin: 0 auto; padding: 26px 16px 64px; color: var(--text, #3a2629); }
html[data-theme="dark"] .jn { --jn-rose: #f0c4c5; --jn-mute: #cdb8b8; --jn-line: rgba(240, 196, 197, .16); }
.jn .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.jn-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; padding: 28px 26px; border-radius: 26px; color: #fff;
           background: radial-gradient(120% 140% at 0% 0%, rgba(214, 168, 92, .35), transparent 55%), linear-gradient(140deg, #3b2b4f, #6d3a52 60%, #9c5457); box-shadow: 0 18px 40px rgba(59, 43, 79, .28); }
.jn-kicker { margin: 0; font-size: .78rem; letter-spacing: .1em; text-transform: uppercase; font-weight: 700; opacity: .9; }
.jn-head h1 { margin: 6px 0; font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.8rem, 4vw, 2.5rem); color: #fff; }
.jn-lead { margin: 0; max-width: 40em; opacity: .95; line-height: 1.55; }
.jn-head-acts { display: flex; gap: 8px; flex-wrap: wrap; }
.jn-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1.5px solid var(--jn-rose); background: transparent; color: var(--jn-rose); font: 600 .92rem Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; }
.jn-btn:hover { background: var(--jn-rose); color: #fff; } html[data-theme="dark"] .jn-btn:hover { color: #2c1e1e; }
.jn-btn-primary { background: var(--jn-rose); color: #fff; } html[data-theme="dark"] .jn-btn-primary { color: #2c1e1e; }
.jn-head .jn-btn-light { background: #fff; color: #5a2a45; border-color: #fff; } .jn-head .jn-btn-ghost { color: #fff; border-color: rgba(255, 255, 255, .5); } .jn-head .jn-btn-ghost:hover { background: rgba(255, 255, 255, .15); }
.jn-sm { min-height: 38px; padding: 0 12px; font-size: .84rem; }
.jn-del { border-color: rgba(179, 38, 30, .35); color: #b3261e; } .jn-del:hover { background: #b3261e; border-color: #b3261e; color: #fff; }
html[data-theme="dark"] .jn-del { color: #ffb4ab; }
.jn-alert { margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; font-weight: 500; } .jn-alert.is-ok { background: #e3efe6; color: #24452f; } .jn-alert.is-error { background: #f6e1e3; color: #6e2a31; }
.jn-card { margin-top: 18px; background: var(--jn-card); border: 1px solid var(--jn-line); border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px rgba(44, 30, 30, .06); }
.jn-card h2 { margin: 0 0 8px; font-family: 'Playfair Display', Georgia, serif; font-size: 1.35rem; }
.jn-prompt { display: flex; gap: 10px; flex-wrap: wrap; align-items: baseline; margin: 0 0 14px; padding: 12px 14px; border-radius: 14px; background: #fff7ec; color: #5a3c1c; line-height: 1.5; }
html[data-theme="dark"] .jn-prompt { background: #2e2620; color: #f3dfc3; }
.jn-link { background: none; border: 0; color: var(--jn-rose); font-weight: 700; cursor: pointer; text-decoration: underline; padding: 4px; }
.jn-form { display: grid; gap: 14px; }
.jn-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; }
.jn-f { display: grid; gap: 6px; font-weight: 600; } .jn-f small { font-weight: 400; color: var(--jn-mute); }
.jn-f input, .jn-f textarea { width: 100%; padding: 12px 14px; border-radius: 14px; font: inherit; font-weight: 400; }
.jn-f textarea { font-family: Georgia, 'Times New Roman', serif; font-size: 1.06rem; line-height: 1.75; resize: vertical; min-height: 240px; }
.jn-moods { border: 0; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px; } .jn-moods legend { font-weight: 600; margin-bottom: 8px; } .jn-moods legend small { font-weight: 400; color: var(--jn-mute); }
.jn-mood input { position: absolute; opacity: 0; } .jn-mood > span { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--jn-line); cursor: pointer; font-size: .9rem; }
.jn-mood input:checked + span { background: var(--jn-rose); color: #fff; border-color: var(--jn-rose); } html[data-theme="dark"] .jn-mood input:checked + span { color: #2c1e1e; }
.jn-mood input:focus-visible + span { outline: 3px solid rgba(156, 84, 87, .45); outline-offset: 2px; }
.jn-acts { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; } .jn-count { color: var(--jn-mute); font-size: .88rem; }
.jn-list-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin: 28px 0 6px; }
.jn-list-head h2 { margin: 0; font-size: 1.25rem; font-family: 'Playfair Display', Georgia, serif; } .jn-list-head h2 small { font-family: Inter, sans-serif; font-size: .82rem; color: var(--jn-mute); font-weight: 500; margin-left: 8px; }
.jn-search { display: flex; gap: 6px; align-items: center; } .jn-search input { min-height: 44px; padding: 0 14px; border-radius: 999px; min-width: 0; width: 220px; }
.jn-month { margin: 20px 0 8px; font-size: .82rem; letter-spacing: .1em; text-transform: uppercase; color: var(--jn-mute); }
.jn-entries { display: grid; gap: 8px; }
.jn-entry { background: var(--jn-card); border: 1px solid var(--jn-line); border-radius: 18px; overflow: hidden; transition: box-shadow .2s, border-color .2s; }
.jn-entry[open] { border-color: rgba(156, 84, 87, .4); box-shadow: 0 10px 26px rgba(44, 30, 30, .08); }
.jn-entry summary { display: flex; gap: 14px; align-items: center; padding: 12px 16px; cursor: pointer; list-style: none; }
.jn-entry summary::-webkit-details-marker { display: none; }
.jn-day { width: 50px; height: 54px; border-radius: 14px; display: grid; place-items: center; align-content: center; background: linear-gradient(160deg, #f6e4e4, #ead1d2); color: #6d3a46; flex: none; }
html[data-theme="dark"] .jn-day { background: linear-gradient(160deg, #4a3036, #3a262b); color: #f6dede; }
.jn-day b { font-size: 1.25rem; line-height: 1; } .jn-day small { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; }
.jn-sum { display: grid; gap: 2px; min-width: 0; } .jn-sum strong { overflow-wrap: anywhere; } .jn-sum small { color: var(--jn-mute); }
.jn-body { padding: 0 18px 16px 80px; }
.jn-verse { margin: 0 0 10px; padding: 10px 12px; border-left: 3px solid #d6a85c; background: rgba(214, 168, 92, .1); border-radius: 0 10px 10px 0; font-style: italic; }
.jn-text { font-family: Georgia, 'Times New Roman', serif; font-size: 1.04rem; line-height: 1.8; overflow-wrap: anywhere; }
.jn-entry-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; } .jn-entry-acts form { display: contents; }
.jn-none { color: var(--jn-mute); }
@media (max-width: 600px) { .jn-row { grid-template-columns: 1fr; } .jn-body { padding-left: 18px; } .jn-search input { width: 100%; } .jn-search { width: 100%; } }
</style>
<?php require_once 'includes/footer.php'; ?>
