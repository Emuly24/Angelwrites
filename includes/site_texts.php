<?php
/**
 * Page text Angella can change on the site itself, without a code editor.
 *
 * In a page:   echo aw_text('home.vision', 'A world where…');            a paragraph
 *              echo aw_text('home.title', 'Welcome to *AngelWrites*', 'line');   one line
 * The second argument is the original wording (shown until it is changed). In a one-line text,
 * words between *asterisks* are shown in the brand highlight colour (as in "Welcome to AngelWrites").
 * Paragraph texts keep line breaks; a blank line starts a new paragraph.
 *
 * Editing: owners and admins choose "Edit page text" (the pencil button at the bottom of every page,
 * or Admin > Page text). Editable text then has a dotted outline; clicking it opens a box to change
 * it, save it, or put the original wording back. Every page's texts are also listed in
 * Admin > Page text (admin/site_texts.php).
 *
 * Why the database and not the page files: the site is deployed from GitHub, so a change written
 * into a file on the server would be overwritten by the next update, and letting a web page rewrite
 * the site's own code would be a serious security risk. Texts saved here stay through every update.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

function aw_text_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS site_texts (
            text_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_by INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS site_text_keys (
            text_key TEXT PRIMARY KEY,
            page TEXT,
            mode TEXT NOT NULL DEFAULT 'text',
            default_value TEXT,
            seen_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('site texts tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** May the signed-in person change page text? (owner and admins) */
function aw_text_can_edit() {
    return function_exists('aw_can') ? aw_can('manage_content') : (function_exists('isAdmin') && isAdmin());
}

/** Is "Edit page text" switched on for this person? */
function aw_text_editing() {
    return aw_text_can_edit() && !empty($_SESSION['aw_edit_text']);
}

function aw_text_all(PDO $db) {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!aw_text_tables($db)) return $map;
    try { foreach ($db->query("SELECT text_key, value FROM site_texts") as $r) $map[$r['text_key']] = (string)$r['value']; }
    catch (Throwable $e) { error_log('site texts read: ' . $e->getMessage()); }
    return $map;
}

/** Text to HTML: escaped; *highlight* in one-line texts; paragraphs and line breaks in paragraph texts. */
function aw_text_render($value, $mode) {
    $h = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/\*([^*\n]{1,80})\*/u', '<span class="rose-text">$1</span>', $h);
    if ($mode === 'line') return $h;
    if ($mode === 'list') { // one item per line
        $items = array_filter(array_map('trim', preg_split('/\n+/u', trim($h))), 'strlen');
        return implode('', array_map(function ($i) { return '<span class="aw-li">' . $i . '</span>'; }, $items));
    }
    $paras = preg_split('/\n\s*\n/u', trim($h));
    if ($mode === 'para') return implode('<br><br>', array_map('nl2br', $paras));
    return nl2br(trim($h));
}

/**
 * Print a piece of page text.
 * $mode: 'line' one line (headings, buttons), 'text' (default) a paragraph with line breaks,
 *        'para' several paragraphs (blank lines between them), 'list' one item per line.
 */
function aw_text($key, $default, $mode = 'text') {
    $db = $GLOBALS['db'] ?? null;
    $key = (string)$key;
    $map = $db instanceof PDO ? aw_text_all($db) : [];
    $value = array_key_exists($key, $map) ? $map[$key] : (string)$default;
    $html = aw_text_render($value, $mode);
    if (!aw_text_editing()) return $html;
    // editing: remember this text for Admin > Page text, and mark it so a click opens the editor
    static $seen = [];
    if ($db instanceof PDO && !isset($seen[$key])) {
        $seen[$key] = true;
        try {
            $page = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'index.php');
            $db->prepare("INSERT INTO site_text_keys (text_key, page, mode, default_value, seen_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                          ON CONFLICT(text_key) DO UPDATE SET page = excluded.page, mode = excluded.mode, default_value = excluded.default_value, seen_at = CURRENT_TIMESTAMP")
               ->execute([$key, $page, $mode, (string)$default]);
        } catch (Throwable $e) { error_log('site text key: ' . $e->getMessage()); }
    }
    $changed = array_key_exists($key, $map) ? ' data-changed="1"' : '';
    return '<span class="aw-et" data-aw-text="' . htmlspecialchars($key) . '" data-mode="' . $mode . '"' . $changed
        . ' data-raw="' . htmlspecialchars($value) . '" data-default="' . htmlspecialchars((string)$default) . '" tabindex="0" role="button" aria-label="Change this text">'
        . $html . '</span>';
}

/** Save (or with $value === null, put back the original wording). Returns ['ok' => bool, 'html' => ..., 'error' => ...]. */
function aw_text_save(PDO $db, $key, $value, $mode, $user_id) {
    if (!aw_text_tables($db)) return ['ok' => false, 'error' => 'The text could not be saved.'];
    if (!preg_match('/^[a-z0-9_.-]{2,80}$/', (string)$key)) return ['ok' => false, 'error' => 'Unknown text.'];
    if (!in_array($mode, ['line', 'text', 'para', 'list'], true)) $mode = 'text';
    try {
        if ($value === null) {
            $db->prepare("DELETE FROM site_texts WHERE text_key = ?")->execute([$key]);
            $st = $db->prepare("SELECT default_value FROM site_text_keys WHERE text_key = ?");
            $st->execute([$key]);
            $value = (string)$st->fetchColumn();
        } else {
            $value = str_replace("\r\n", "\n", (string)$value);
            if ($mode === 'line') $value = trim(preg_replace('/\s+/u', ' ', $value));
            $value = trim($value);
            if ($value === '') return ['ok' => false, 'error' => 'The text cannot be empty. Use "Original wording" to go back to how it was.'];
            if (mb_strlen($value) > 5000) return ['ok' => false, 'error' => 'That is too long (5,000 characters at most).'];
            $db->prepare("INSERT INTO site_texts (text_key, value, updated_by, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                          ON CONFLICT(text_key) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP")
               ->execute([$key, $value, (int)$user_id]);
        }
        if (function_exists('aw_audit')) aw_audit('site_text_save', 'text', 0, $key);
        return ['ok' => true, 'html' => aw_text_render($value, $mode), 'raw' => $value];
    } catch (Throwable $e) {
        error_log('site text save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The text could not be saved just now. Please try again.'];
    }
}

/** The pencil button and the inline editor (printed once, in the footer, for owners and admins). */
function aw_text_editor_ui() {
    if (!aw_text_can_edit()) return '';
    $on = aw_text_editing();
    $here = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $toggle = SITE_URL . '/site_text_save.php?toggle=' . ($on ? '0' : '1') . '&back=' . rawurlencode($here);
    $h = '<a class="aw-et-toggle' . ($on ? ' is-on' : '') . '" href="' . htmlspecialchars($toggle) . '" title="' . ($on ? 'Stop editing page text' : 'Edit the text on this page') . '">'
       . '<i class="fas fa-pen" aria-hidden="true"></i><span>' . ($on ? 'Done editing' : 'Edit page text') . '</span></a>';
    if (!$on) return $h . aw_text_editor_css();
    $save = htmlspecialchars(SITE_URL . '/site_text_save.php');
    return $h . aw_text_editor_css() . <<<HTML
<div class="aw-et-bar" role="status">Editing page text: click any text with a dotted outline to change it. <a href="{$toggle}">Done</a></div>
<dialog id="awEtDlg" class="aw-et-dlg" aria-labelledby="awEtTitle">
  <form method="dialog" class="aw-et-form">
    <h2 id="awEtTitle">Change this text</h2>
    <p class="aw-et-hint" id="awEtHint"></p>
    <textarea id="awEtVal" rows="5"></textarea>
    <p class="aw-et-msg" role="alert"></p>
    <div class="aw-et-actions">
      <button type="button" class="aw-et-btn" data-reset>Original wording</button>
      <span style="flex:1"></span>
      <button type="button" class="aw-et-btn" data-cancel>Cancel</button>
      <button type="button" class="aw-et-btn p" data-save>Save</button>
    </div>
  </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('awEtDlg'), ta = document.getElementById('awEtVal'), msg = dlg.querySelector('.aw-et-msg'), cur = null;
  var token = (document.querySelector('meta[name="aw-csrf"]') || {}).content || '';
  function open(el) {
    cur = el; msg.textContent = '';
    ta.value = el.getAttribute('data-raw');
    var mode = el.getAttribute('data-mode');
    ta.rows = mode === 'line' ? 2 : 7;
    document.getElementById('awEtHint').textContent = (mode === 'line' ? 'One line. ' : (mode === 'list' ? 'One item per line. ' : 'A blank line starts a new paragraph. ')) + 'Put *asterisks* around words to show them in the highlight colour.';
    dlg.showModal(); ta.focus();
  }
  document.addEventListener('click', function (e) { var el = e.target.closest && e.target.closest('.aw-et'); if (!el) return; e.preventDefault(); e.stopPropagation(); open(el); }, true);
  document.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('aw-et')) { e.preventDefault(); open(document.activeElement); } });
  function send(reset) {
    var fd = new FormData();
    fd.append('key', cur.getAttribute('data-aw-text')); fd.append('mode', cur.getAttribute('data-mode')); fd.append('csrf_token', token);
    if (reset) fd.append('reset', '1'); else fd.append('value', ta.value);
    msg.textContent = 'Saving…';
    fetch('{$save}', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { msg.textContent = d.error || 'Not saved.'; return; }
        cur.innerHTML = d.html; cur.setAttribute('data-raw', d.raw);
        if (reset) cur.removeAttribute('data-changed'); else cur.setAttribute('data-changed', '1');
        cur.classList.add('aw-et-saved'); setTimeout(function () { cur.classList.remove('aw-et-saved'); }, 1400);
        dlg.close();
      })
      .catch(function () { msg.textContent = 'Not saved: check your connection and try again.'; });
  }
  dlg.querySelector('[data-save]').onclick = function () { send(false); };
  dlg.querySelector('[data-reset]').onclick = function () { if (confirm('Put back the original wording?')) send(true); };
  dlg.querySelector('[data-cancel]').onclick = function () { dlg.close(); };
})();
</script>
HTML;
}

function aw_text_editor_css() {
    return '<style>
.aw-et-toggle { position: fixed; left: 16px; bottom: 16px; z-index: 9990; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 999px; background: #3a2629; color: #fff; text-decoration: none; font: 600 .9rem/1 Inter, system-ui, sans-serif; box-shadow: 0 10px 26px rgba(30,18,18,.3); }
.aw-et-toggle:hover { color: #fff; filter: brightness(1.15); }
.aw-et-toggle.is-on { background: #9c5457; }
.aw-et { outline: 2px dashed rgba(156, 84, 87, .65); outline-offset: 3px; border-radius: 4px; cursor: pointer; transition: background-color .2s, outline-color .2s; }
.aw-et:hover, .aw-et:focus-visible { background-color: rgba(214, 168, 92, .18); outline-color: #9c5457; outline-style: solid; }
.aw-et[data-changed] { outline-color: rgba(47, 107, 69, .7); }
.aw-et-saved { background-color: rgba(47, 107, 69, .2) !important; }
.aw-et-bar { position: fixed; left: 50%; transform: translateX(-50%); bottom: 16px; z-index: 9989; max-width: calc(100vw - 220px); padding: 10px 16px; border-radius: 14px; background: #fff8ef; color: #3a2629; border: 1px solid #e6c9a0; font: 500 .88rem/1.4 Inter, system-ui, sans-serif; box-shadow: 0 8px 22px rgba(30,18,18,.15); }
.aw-et-bar a { color: #9c5457; font-weight: 700; }
.aw-et-dlg { border: 0; border-radius: 20px; padding: 22px; width: min(560px, calc(100vw - 24px)); background: var(--card-bg, #fff); color: var(--text, #3a2629); box-shadow: 0 30px 70px rgba(30,18,18,.35); }
.aw-et-dlg::backdrop { background: rgba(30,18,18,.45); }
.aw-et-dlg h2 { margin: 0 0 6px; font: 600 1.25rem "Playfair Display", Georgia, serif; }
.aw-et-hint { margin: 0 0 10px; font-size: .86rem; color: var(--text-light, #6b5a5a); }
.aw-et-dlg textarea { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1.5px solid #dcc3c3; font: inherit; line-height: 1.6; background: var(--card-bg, #fff); color: inherit; }
.aw-et-msg { min-height: 1.3em; margin: 6px 0; color: #a3262e; font-size: .88rem; }
.aw-et-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.aw-et-btn { min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1.5px solid #9c5457; background: transparent; color: #9c5457; font: 600 .92rem Inter, system-ui, sans-serif; cursor: pointer; }
.aw-et-btn.p { background: #9c5457; color: #fff; }
@media (max-width: 640px) { .aw-et-bar { display: none; } }
</style>';
}
