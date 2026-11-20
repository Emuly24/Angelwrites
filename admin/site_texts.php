<?php
/**
 * Page text (see includes/site_texts.php): every text Angella can change, grouped by page.
 * The easiest way is on the page itself ("Edit page text"); this list also lets her change a text
 * here, or put back its original wording. A text appears here once its page has been opened while
 * "Edit page text" is on (or after it has been changed).
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site_texts.php';

aw_require('manage_content'); // checks the CSRF token on every POST
aw_text_tables($db);

$st_notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $k = (string)($_POST['key'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'text');
    $r = aw_text_save($db, $k, isset($_POST['reset']) ? null : (string)($_POST['value'] ?? ''), $mode, (int)$_SESSION['user_id']);
    $st_notice = [!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok']) ? (isset($_POST['reset']) ? 'The original wording is back.' : 'Saved. The page shows the new text now.') : $r['error']];
}

$st_rows = [];
try {
    $st_rows = $db->query("SELECT k.text_key, k.page, k.mode, k.default_value, t.value, t.updated_at
                           FROM site_text_keys k LEFT JOIN site_texts t ON t.text_key = k.text_key
                           ORDER BY k.page, k.text_key")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('site texts list: ' . $e->getMessage()); }
$st_pages = [];
foreach ($st_rows as $r) $st_pages[$r['page'] ?: 'other'][] = $r;
// Chichewa versions ("key@ny", includes/i18n.php)
$st_ny = [];
try { foreach ($db->query("SELECT text_key, value FROM site_texts WHERE text_key LIKE '%@ny'") as $r) $st_ny[substr($r['text_key'], 0, -3)] = (string)$r['value']; } catch (Throwable $e) { error_log('site texts ny: ' . $e->getMessage()); }
$st_known = ['index.php' => 'Home page', 'about.php' => 'About', 'books.php' => 'Books', 'poetry.php' => 'Poetry', 'reflections.php' => 'Reflections',
             'blog.php' => 'Blog', 'videos.php' => 'Videos', 'contact.php' => 'Contact', 'stories.php' => 'Stories & Help', 'community.php' => 'Community Q&A',
             'groups.php' => 'Reading groups', 'book_session.php' => 'Book a session', 'share_book.php' => 'Share a book',
             'community_guidelines.php' => 'Community guidelines', 'terms.php' => 'Terms of Service', 'privacy.php' => 'Privacy Policy',
             'accessibility.php' => 'Accessibility', '404.php' => 'Page not found'];
$st_back = function ($page) { return SITE_URL . '/site_text_save.php?toggle=1&back=' . rawurlencode('/' . $page); };

$pageTitle = 'Page text';
require_once '../includes/header.php';
?>
<style>
.stx { max-width: 1000px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.stx h1 { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem, 3vw, 2.1rem); margin: 0 0 6px; }
.stx-lead { color: var(--text-light); line-height: 1.6; max-width: 48em; }
.stx-pages { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0 22px; }
.stx-pages a { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--border); text-decoration: none; color: var(--text); font-weight: 600; }
.stx-pages a:hover { border-color: #9c5457; color: #9c5457; }
.stx-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; padding: 18px; margin-bottom: 16px; box-shadow: 0 6px 20px rgba(44,30,30,.06); }
.stx-card h2 { display: flex; align-items: center; gap: 10px; font-size: 1.15rem; margin: 0 0 12px; }
.stx-card h2 a { margin-left: auto; font-size: .86rem; font-weight: 600; color: #9c5457; }
.stx-item { border-top: 1px solid var(--border); padding: 12px 0; }
.stx-item:first-of-type { border-top: 0; }
.stx-item label { display: flex; justify-content: space-between; gap: 10px; font-weight: 600; font-size: .9rem; margin: 0 0 6px; }
.stx-item small { font-weight: 500; color: var(--text-light); }
.stx-item textarea { width: 100%; min-height: 64px; padding: 10px 12px; border-radius: 12px; font: inherit; line-height: 1.5; }
.stx-acts { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.stx-acts .btn { min-height: 40px; }
.stx-changed { color: #2f6b45; }
.stx-ny { margin: -4px 0 8px; padding: 0 0 8px 14px; border-left: 3px solid #d6a85c; } .stx-ny summary { cursor: pointer; font-weight: 600; font-size: .88rem; min-height: 36px; display: flex; gap: 8px; align-items: center; } .stx-ny summary small { font-weight: 400; color: var(--text-light); }
.stx-alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 500; }
.stx-alert.ok { background: #e3efe6; color: #2d5039; } .stx-alert.error { background: #f6e1e3; color: #6e2a31; }
</style>
<div class="stx">
    <h1>Page text</h1>
    <p class="stx-lead">Change the words on the site's pages without any code. The easiest way: open a page below with <strong>Edit page text</strong>, click any text with a dotted outline, change it and save. You can also change texts here. <code>*one asterisk*</code> shows words in the highlight colour, <code>**two**</code> makes them bold and <code>[words](/page.php)</code> makes a link. "Original wording" always brings back the first version.</p>
    <?php if ($st_notice): ?><div class="stx-alert <?php echo $st_notice[0] === 'ok' ? 'ok' : 'error'; ?>" role="status"><?php echo htmlspecialchars($st_notice[1]); ?></div><?php endif; ?>
    <nav class="stx-pages" aria-label="Edit a page">
        <?php foreach ($st_known as $f => $label): ?><a href="<?php echo htmlspecialchars($st_back($f)); ?>"><i class="fas fa-pen" aria-hidden="true"></i> <?php echo htmlspecialchars($label); ?></a><?php endforeach; ?>
    </nav>
    <?php if (!$st_pages): ?>
        <div class="stx-card"><p style="margin:0;">No texts listed yet. Open a page above with Edit page text: its texts will be listed here too.</p></div>
    <?php endif; ?>
    <?php foreach ($st_pages as $page => $rows): ?>
    <section class="stx-card" aria-labelledby="stx-<?php echo md5($page); ?>">
        <h2 id="stx-<?php echo md5($page); ?>"><?php echo htmlspecialchars($st_known[$page] ?? $page); ?><a href="<?php echo htmlspecialchars($st_back($page)); ?>">Edit on the page</a></h2>
        <?php foreach ($rows as $r): $val = $r['value'] !== null ? $r['value'] : $r['default_value']; $id = 'stx-' . md5($r['text_key']); ?>
        <form method="post" class="stx-item">
            <input type="hidden" name="key" value="<?php echo htmlspecialchars($r['text_key']); ?>"><input type="hidden" name="mode" value="<?php echo htmlspecialchars($r['mode']); ?>">
            <label for="<?php echo $id; ?>"><span><?php echo htmlspecialchars($r['text_key']); ?></span><small><?php echo $r['value'] !== null ? '<span class="stx-changed">Changed ' . htmlspecialchars(date('j M Y', strtotime($r['updated_at']))) . '</span>' : 'Original wording'; ?> · <?php echo ['line' => 'one line', 'text' => 'paragraph', 'para' => 'paragraphs', 'list' => 'one item per line'][$r['mode']] ?? 'text'; ?></small></label>
            <textarea id="<?php echo $id; ?>" name="value" rows="<?php echo $r['mode'] === 'line' ? 2 : 4; ?>"><?php echo htmlspecialchars($val); ?></textarea>
            <div class="stx-acts"><button type="submit" class="btn btn-primary btn-sm">Save</button><?php if ($r['value'] !== null): ?><button type="submit" name="reset" value="1" class="btn btn-outline btn-sm" onclick="return confirm('Put back the original wording?');">Original wording</button><?php endif; ?></div>
        </form>
        <details class="stx-ny"<?php echo isset($st_ny[$r['text_key']]) ? '' : ''; ?>><summary><i class="fas fa-language" aria-hidden="true"></i> Chichewa <?php echo isset($st_ny[$r['text_key']]) ? '<span class="stx-changed">translated</span>' : '<small>not translated yet: readers see the English</small>'; ?></summary>
            <form method="post" class="stx-item">
                <input type="hidden" name="key" value="<?php echo htmlspecialchars($r['text_key'] . '@ny'); ?>"><input type="hidden" name="mode" value="<?php echo htmlspecialchars($r['mode']); ?>">
                <textarea name="value" lang="ny" aria-label="Chichewa for <?php echo htmlspecialchars($r['text_key']); ?>" rows="<?php echo $r['mode'] === 'line' ? 2 : 4; ?>" placeholder="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($st_ny[$r['text_key']] ?? ''); ?></textarea>
                <div class="stx-acts"><button type="submit" class="btn btn-primary btn-sm">Save Chichewa</button><?php if (isset($st_ny[$r['text_key']])): ?><button type="submit" name="reset" value="1" class="btn btn-outline btn-sm">Remove the translation</button><?php endif; ?></div>
            </form>
        </details>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php require_once '../includes/footer.php'; ?>
