<?php
/**
 * The site in English and Chichewa.
 *
 *  aw_lang()          'en' or 'ny': ?lang=ny switches (remembered in a cookie for a year)
 *  aw_t('Books')      an interface word or phrase (menus, buttons) in the reader's language. The
 *                     English is the key; Chichewa comes from Admin > Translations (ui_strings).
 *                     Untranslated phrases stay in English.
 *  Page text (includes/site_texts.php) has a Chichewa version of every text too: stored under
 *  "key@ny", edited on the page with Edit page text while the site is in Chichewa, or side by side
 *  in Admin > Page text.
 *
 * Suggested Chichewa for common interface words is offered in Admin > Translations, but nothing is
 * shown to readers until a person has checked and accepted it.
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_LANGS = ['en' => ['English', 'English'], 'ny' => ['Chichewa', 'Chichewa']];

function aw_lang() {
    static $lang = null;
    if ($lang !== null) return $lang;
    $want = (string)($_GET['lang'] ?? '');
    if (isset(AW_LANGS[$want])) {
        $lang = $want;
        if (!headers_sent()) setcookie('aw_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        return $lang;
    }
    $c = (string)($_COOKIE['aw_lang'] ?? '');
    return $lang = isset(AW_LANGS[$c]) ? $c : 'en';
}

function aw_lang_attr() { return aw_lang() === 'ny' ? 'ny' : 'en'; }

/** A link to the same page in another language. */
function aw_lang_url($lang) {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($uri, PHP_URL_PATH);
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
    $q['lang'] = $lang;
    return $path . '?' . http_build_query($q);
}

function aw_i18n_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ui_strings (lang TEXT NOT NULL, src TEXT NOT NULL, val TEXT NOT NULL, updated_by INTEGER, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (lang, src))");
        $db->exec("CREATE TABLE IF NOT EXISTS ui_string_keys (src TEXT PRIMARY KEY, seen_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $ok = true;
    } catch (Throwable $e) { error_log('i18n tables: ' . $e->getMessage()); return $ok = false; }
}

function aw_ui_map(PDO $db, $lang) {
    static $maps = [];
    if (isset($maps[$lang])) return $maps[$lang];
    $maps[$lang] = [];
    if (!aw_i18n_tables($db)) return $maps[$lang];
    try { $st = $db->prepare("SELECT src, val FROM ui_strings WHERE lang = ?"); $st->execute([$lang]); foreach ($st->fetchAll(PDO::FETCH_NUM) as [$s, $v]) $maps[$lang][$s] = $v; }
    catch (Throwable $e) { error_log('i18n map: ' . $e->getMessage()); }
    return $maps[$lang];
}

/** An interface phrase in the reader's language (escaped for HTML unless $raw). */
function aw_t($src, $raw = false) {
    $src = (string)$src;
    $db = $GLOBALS['db'] ?? null;
    $out = $src;
    if ($db instanceof PDO && aw_lang() !== 'en') {
        $map = aw_ui_map($db, aw_lang());
        if (isset($map[$src]) && $map[$src] !== '') $out = $map[$src];
        aw_t_seen($db, $src);
    }
    return $raw ? $out : htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}

/** Remember phrases met while the site is shown in another language, for Admin > Translations. */
function aw_t_seen(PDO $db, $src) {
    static $seen = [], $known = null;
    if (isset($seen[$src])) return;
    $seen[$src] = true;
    try {
        if ($known === null) { $known = []; foreach ($db->query("SELECT src FROM ui_string_keys") as $r) $known[$r['src']] = true; }
        if (!isset($known[$src]) && mb_strlen($src) <= 200) { $db->prepare("INSERT OR IGNORE INTO ui_string_keys (src) VALUES (?)")->execute([$src]); $known[$src] = true; }
    } catch (Throwable $e) { /* the tables appear on first use */ }
}

/** Suggested Chichewa for common interface words: shown in Admin > Translations, live only once accepted. */
const AW_NY_SUGGEST = [
    'Home' => 'Kunyumba', 'Books' => 'Mabuku', 'Poems' => 'Ndakatulo', 'Reflections' => 'Zosinkhasinkha', 'Blog' => 'Blogu', 'About' => 'Za ife', 'Contact' => 'Lumikizanani nafe',
    'Community' => 'Gulu', 'Log in' => 'Lowani', 'Log out' => 'Tulukani', 'Join free' => 'Lowani mwaulere', 'Search' => 'Sakani', 'My library' => 'Laibulale yanga',
    'Dashboard' => 'Tsamba langa', 'Messages' => 'Mauthenga', 'Notifications' => 'Zidziwitso', 'Profile' => 'Mbiri yanga', 'Settings' => 'Zokonda',
    'Prayer wall' => 'Khoma la mapemphero', 'Devotionals' => 'Mapemphero a tsiku ndi tsiku', 'Events' => 'Zochitika', 'Mentoring' => 'Kulangiza',
    'Writing prompts' => 'Zoyambitsa kulemba', 'Reading groups' => 'Magulu owerenga', 'Bible' => 'Baibulo', 'Videos' => 'Makanema', 'My journal' => 'Zolemba zanga',
    'Stories & Help' => 'Nkhani ndi Thandizo', 'Community Q&A' => 'Mafunso a gulu', 'Book a session' => 'Pemphani nthawi yokambirana', 'Language' => 'Chiyankhulo',
    'Quick Links' => 'Maulalo', 'Connect' => 'Lumikizanani', 'Read' => 'Werengani', 'You' => 'Inu', 'Explore' => 'Fufuzani',
];
