<?php
/**
 * Admin > Translations (includes/i18n.php): the site's menus and buttons in Chichewa.
 * Each English word or phrase can be given its Chichewa; untranslated ones stay in English.
 * Suggestions are offered for common words but are never shown to readers until accepted here.
 * Page text (paragraphs, headings) is translated in Admin > Page text, or on the page itself.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/i18n.php';

aw_require('translations_team'); // checks the CSRF token on every POST
aw_i18n_tables($db);
$tr_lang = 'ny';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = 0;
    try {
        $db->beginTransaction();
        $up = $db->prepare("INSERT INTO ui_strings (lang, src, val, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(lang, src) DO UPDATE SET val = excluded.val, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP");
        $del = $db->prepare("DELETE FROM ui_strings WHERE lang = ? AND src = ?");
        if (!empty($_POST['accept_all'])) {
            $have = aw_ui_map($db, $tr_lang);
            foreach (AW_NY_SUGGEST as $src => $val) if (!isset($have[$src])) { $up->execute([$tr_lang, $src, $val, (int)$_SESSION['user_id']]); $n++; }
        } else {
            foreach ((array)($_POST['t'] ?? []) as $i => $val) {
                $src = (string)($_POST['src'][$i] ?? '');
                if ($src === '' || mb_strlen($src) > 200) continue;
                $val = trim(mb_substr(strip_tags((string)$val), 0, 300));
                if ($val === '') $del->execute([$tr_lang, $src]); else $up->execute([$tr_lang, $src, $val, (int)$_SESSION['user_id']]);
                $n++;
            }
        }
        $db->commit();
        $_SESSION['aw_tr_flash'] = ['ok', 'Saved. Readers who choose Chichewa see the new words now.'];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('translations: ' . $e->getMessage()); $_SESSION['aw_tr_flash'] = ['error', 'That could not be saved.']; }
    if (function_exists('aw_audit')) aw_audit('translations_save', 'i18n', 0, $n . ' phrases');
    header('Location: ' . SITE_URL . '/admin/translations.php' . (isset($_GET['show']) ? '?show=' . urlencode((string)$_GET['show']) : '')); exit;
}
$tr_flash = $_SESSION['aw_tr_flash'] ?? null; unset($_SESSION['aw_tr_flash']);
$tr_map = aw_ui_map($db, $tr_lang);
$tr_all = array_keys(AW_NY_SUGGEST);
try { foreach ($db->query("SELECT src FROM ui_string_keys ORDER BY seen_at") as $r) $tr_all[] = $r['src']; } catch (Throwable $e) {}
foreach (array_keys($tr_map) as $k) $tr_all[] = $k;
$tr_all = array_values(array_unique($tr_all));
$tr_show = (string)($_GET['show'] ?? 'todo');
$tr_rows = array_values(array_filter($tr_all, function ($s) use ($tr_map, $tr_show) { return $tr_show === 'all' || ($tr_show === 'done' ? isset($tr_map[$s]) : !isset($tr_map[$s])); }));
$tr_done = count(array_filter($tr_all, function ($s) use ($tr_map) { return isset($tr_map[$s]); }));
$tr_pending_suggest = count(array_filter(array_keys(AW_NY_SUGGEST), function ($s) use ($tr_map) { return !isset($tr_map[$s]); }));

$pageTitle = 'Translations';
require_once '../includes/header.php';
?>
<div class="tr">
    <header class="tr-head"><div><h1>Translations</h1>
        <p>The site's menus and buttons in Chichewa. Readers choose English or Chichewa at the bottom of every page; anything not translated yet stays in English. Paragraphs and headings are translated in <a href="<?php echo SITE_URL; ?>/admin/site_texts.php">Page text</a> (or on the page with Edit page text while the site is in Chichewa).</p></div>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars(SITE_URL . '/index.php?lang=ny'); ?>" target="_blank" rel="noopener">See the site in Chichewa</a></header>
    <?php if ($tr_flash): ?><div class="alert <?php echo $tr_flash[0] === 'ok' ? 'alert-success' : 'alert-error'; ?>" role="status"><?php echo htmlspecialchars($tr_flash[1]); ?></div><?php endif; ?>
    <div class="tr-bar">
        <span class="tr-prog"><b><?php echo $tr_done; ?></b> of <?php echo count($tr_all); ?> translated</span>
        <nav><?php foreach (['todo' => 'Not translated', 'done' => 'Translated', 'all' => 'All'] as $k => $l): ?><a href="?show=<?php echo $k; ?>"<?php echo $tr_show === $k ? ' class="is-on"' : ''; ?>><?php echo $l; ?></a><?php endforeach; ?></nav>
        <?php if ($tr_pending_suggest): ?><form method="post"><button class="btn btn-sm btn-outline" name="accept_all" value="1" onclick="return confirm('Use all <?php echo $tr_pending_suggest; ?> suggestions as they are? Please check them first: they were written to help, but a Chichewa speaker should confirm them.');">Use all <?php echo $tr_pending_suggest; ?> suggestions</button></form><?php endif; ?>
    </div>
    <p class="tr-note"><i class="fas fa-circle-info" aria-hidden="true"></i> Suggestions (in grey) are a starting point and are not shown to readers until you accept them. More words appear in this list as pages are opened while the site is in Chichewa.</p>
    <?php if (!$tr_rows): ?><p class="tr-none">Nothing here.</p><?php else: ?>
    <form method="post" class="tr-form">
        <div class="tr-table" role="table" aria-label="Translations">
            <div class="tr-row tr-th" role="row"><span role="columnheader">English</span><span role="columnheader">Chichewa</span></div>
            <?php foreach ($tr_rows as $i => $src): $sug = AW_NY_SUGGEST[$src] ?? ''; ?>
            <div class="tr-row" role="row">
                <label role="cell" for="tr<?php echo $i; ?>"><?php echo htmlspecialchars($src); ?></label>
                <span role="cell" class="tr-cell"><input type="hidden" name="src[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($src); ?>">
                    <input type="text" id="tr<?php echo $i; ?>" name="t[<?php echo $i; ?>]" lang="ny" maxlength="300" value="<?php echo htmlspecialchars($tr_map[$src] ?? ''); ?>" placeholder="<?php echo htmlspecialchars($sug !== '' ? 'Suggestion: ' . $sug : 'Chichewa'); ?>">
                    <?php if ($sug !== '' && !isset($tr_map[$src])): ?><button type="button" class="tr-use" onclick="var i=document.getElementById('tr<?php echo $i; ?>');i.value=<?php echo htmlspecialchars(json_encode($sug), ENT_QUOTES); ?>;i.focus();">Use suggestion</button><?php endif; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="tr-save"><button class="btn btn-primary">Save translations</button><span>Leave a box empty to show the English.</span></div>
    </form>
    <?php endif; ?>
</div>
<style>
.tr { max-width: 980px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.tr-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; } .tr-head h1 { margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; } .tr-head p { margin: 0; color: var(--text-light); max-width: 48em; line-height: 1.55; } .tr-head a { color: #9c5457; }
.tr-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 12px 14px; border-radius: 16px; background: var(--card-bg, #fff); border: 1px solid var(--border); }
.tr-prog b { font-size: 1.2rem; color: #9c5457; } .tr-bar nav { display: flex; gap: 6px; flex-wrap: wrap; margin-left: auto; }
.tr-bar nav a { min-height: 36px; display: inline-flex; align-items: center; padding: 0 12px; border-radius: 999px; border: 1px solid var(--border); text-decoration: none; color: inherit; font-weight: 600; font-size: .86rem; } .tr-bar nav a.is-on { background: #9c5457; border-color: #9c5457; color: #fff; }
.tr-note { color: var(--text-light); font-size: .9rem; margin: 10px 0; }
.tr-table { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; overflow: hidden; }
.tr-row { display: grid; grid-template-columns: 1fr 1.2fr; gap: 12px; align-items: center; padding: 8px 14px; border-top: 1px solid var(--border); } .tr-row:first-child { border-top: 0; }
.tr-th { font-weight: 700; font-size: .82rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-light); background: rgba(156, 84, 87, .05); }
.tr-row label { font-weight: 600; overflow-wrap: anywhere; } .tr-cell { display: flex; gap: 6px; align-items: center; } .tr-cell input[type=text] { flex: 1; min-width: 0; min-height: 40px; padding: 0 12px; border-radius: 10px; font: inherit; }
.tr-cell input::placeholder { color: #8a7a7a; font-style: italic; }
.tr-use { white-space: nowrap; min-height: 36px; padding: 0 10px; border-radius: 999px; border: 1px solid #9c5457; background: transparent; color: #9c5457; font-weight: 600; font-size: .8rem; cursor: pointer; }
.tr-save { position: sticky; bottom: 12px; display: flex; gap: 12px; align-items: center; margin-top: 12px; padding: 10px 14px; border-radius: 16px; background: var(--card-bg, #fff); border: 1px solid var(--border); box-shadow: 0 8px 22px rgba(44, 30, 30, .1); } .tr-save span { color: var(--text-light); font-size: .88rem; }
@media (max-width: 640px) { .tr-row { grid-template-columns: 1fr; } .tr-th { display: none; } }
</style>
<?php require_once '../includes/footer.php'; ?>
