<?php
/**
 * B2: the Bible reader, rebuilt as a quiet, focused reading room (docs/BIBLE_PLAN.md §E, §F).
 *
 * The page is only the passage. No site header, footer, prompts or badges: one thin bar (reference,
 * version, search, text settings) that steps aside while you read and comes back on a tap, a scroll
 * up, a key press or keyboard focus. Everything else opens on request in a sheet.
 *
 * URL: bible_reader.php?v=KJV&b=John&c=3[&vs=16] (the old ?version=&book=&chapter=&verse= still works).
 * Data: bible_api.php (versions, books, chapters, search). Members' highlights, notes, bookmarks and
 * reading place: bible_reader_ajax.php (POST + CSRF). Guests keep settings and their place on the device.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/bible_versions.php';

$versions = aw_bible_versions();
$v = strtoupper((string)($_GET['v'] ?? $_GET['version'] ?? ''));
$b = (string)($_GET['b'] ?? $_GET['book'] ?? '');
$c = (int)($_GET['c'] ?? $_GET['chapter'] ?? 0);
$vs = (int)($_GET['vs'] ?? $_GET['verse'] ?? 0);
$fromLink = $b !== '';

// a member's last place, when the link names no passage
$resume = null;
if (isLoggedIn()) {
    try {
        $st = $db->prepare("SELECT book, chapter, verse FROM bible_reading_positions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1");
        $st->execute([(int)$_SESSION['user_id']]);
        $resume = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('bible resume: ' . $e->getMessage()); }
}
if (!isset($versions[$v])) $v = isset($versions['KJV']) ? 'KJV' : (string)array_key_first($versions);
$books = aw_bible_books($v);
$bookList = array_column($books, null, 'book');
if (!$fromLink && $resume && isset($bookList[$resume['book']])) { $b = $resume['book']; $c = (int)$resume['chapter']; $vs = (int)$resume['verse']; }
if (!isset($bookList[$b])) { $b = isset($bookList['John']) ? 'John' : ($books[0]['book'] ?? 'Genesis'); $c = $c ?: 1; }
$c = max(1, min($c ?: 1, (int)($bookList[$b]['chapters'] ?? 1)));

// the first chapter is rendered on the server, so the page reads even before scripts run
$chapter = aw_bible_chapter($v, $b, $c);
$ver = $versions[$v] ?? null;
$langAttr = $ver ? $ver['lang'] : 'en';
$title = ($chapter['book_name'] ?? $b) . ' ' . $c . ' · ' . ($ver['short'] ?? $v);
$csrf = isLoggedIn() ? generate_csrf_token() : '';

function aw_br_verses($chapter) {
    if (!$chapter) return '<p class="br-empty">This chapter is not in this version.</p>';
    $h = '';
    if (!empty($chapter['title'])) $h .= '<p class="br-title">' . htmlspecialchars($chapter['title']) . '</p>';
    $open = false;
    foreach ($chapter['verses'] as $i => $vv) {
        foreach ($vv['heads'] as $hd) {
            if ($open) { $h .= '</p>'; $open = false; }
            $h .= '<h2 class="br-head' . ($hd['level'] > 1 ? ' is-minor' : '') . '">' . htmlspecialchars($hd['text']) . '</h2>';
        }
        if (!$open || $vv['para']) { if ($open) $h .= '</p>'; $h .= '<p class="br-para">'; $open = true; }
        $poem = strpos($vv['text'], "\n") !== false;
        $h .= '<span class="br-v' . ($poem ? ' is-poem' : '') . '" id="v' . $vv['v'] . '" data-v="' . $vv['v'] . '" tabindex="-1"><sup class="br-num" aria-hidden="true">' . $vv['v'] . '</sup>'
            . '<span class="sr-only">verse ' . $vv['v'] . ' </span>' . nl2br(htmlspecialchars($vv['text']), false) . '</span> ';
    }
    if ($open) $h .= '</p>';
    return $h;
}
?><!doctype html>
<html lang="<?php echo htmlspecialchars($langAttr); ?>" data-br-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#F7F3ED">
<title><?php echo htmlspecialchars($title); ?> · AngelWrites Bible</title>
<meta name="description" content="Read the Bible quietly on AngelWrites, in English, Chichewa and Chiyao.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;1,7..72,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/bible.css?v=2">
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-saves.css?v=1"><script src="<?php echo SITE_URL; ?>/assets/js/aw-saves.js?v=1" defer></script>
<script>
// apply the reader's settings before the first paint (no flash of the wrong theme or size)
(function () { try { var s = JSON.parse(localStorage.getItem('awBible') || '{}'), r = document.documentElement;
  if (s.theme) r.setAttribute('data-br-theme', s.theme);
  else if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) r.setAttribute('data-br-theme', 'dark');
  ['size','leading','width','font','nums'].forEach(function (k) { if (s[k] != null) r.setAttribute('data-br-' + k, s[k]); });
} catch (e) {} })();
</script>
</head>
<body>
<a class="br-skip" href="#brText">Skip to the reading</a>

<header class="br-bar" id="brBar">
    <a class="br-icon" href="<?php echo SITE_URL; ?>/<?php echo isLoggedIn() ? 'dashboard.php' : 'index.php'; ?>" aria-label="Leave the Bible and go back to AngelWrites">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div class="br-bar-mid">
        <button type="button" class="br-ref" id="brRefBtn" aria-haspopup="dialog" aria-controls="brChooser">
            <span id="brRefLabel"><?php echo htmlspecialchars(($chapter['book_name'] ?? $b) . ' ' . $c); ?></span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <button type="button" class="br-ver" id="brVerBtn" aria-haspopup="dialog" aria-controls="brVersions" aria-label="Version: <?php echo htmlspecialchars($ver['name'] ?? $v); ?>"><span id="brVerLabel"><?php echo htmlspecialchars($ver['short'] ?? $v); ?></span></button>
    </div>
    <button type="button" class="br-icon" id="brSearchBtn" aria-label="Search" aria-haspopup="dialog" aria-controls="brSearch">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
    </button>
    <button type="button" class="br-icon" id="brSetBtn" aria-label="Reading settings" aria-haspopup="dialog" aria-controls="brSettings">
        <span class="br-aa" aria-hidden="true">Aa</span>
    </button>
</header>

<main class="br-main" id="brMain">
    <?php if (!$fromLink && $resume && $resume['book'] === $b): ?>
        <p class="br-resume" id="brResume" role="status">Welcome back. You were reading here.</p>
    <?php endif; ?>
    <div class="br-cols" id="brCols">
        <article class="br-text" id="brText" lang="<?php echo htmlspecialchars($langAttr); ?>" aria-labelledby="brChapterTitle">
            <h1 class="br-chapter" id="brChapterTitle"><span class="br-chapter-book"><?php echo htmlspecialchars($chapter['book_name'] ?? $b); ?></span> <span class="br-chapter-num"><?php echo $c; ?></span></h1>
            <div id="brBody"><?php echo aw_br_verses($chapter); ?></div>
        </article>
        <article class="br-text br-text-b" id="brTextB" hidden aria-label="Second version"></article>
    </div>
    <nav class="br-pager" aria-label="Chapters">
        <button type="button" class="br-pg" id="brPrev"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg><span>Previous</span></button>
        <button type="button" class="br-pg" id="brNext"><span>Next</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
    </nav>
    <footer class="br-credit" id="brCredit">
        <?php if ($ver): ?><?php echo htmlspecialchars($ver['credit']); ?><?php if ($ver['license']): ?> <a href="<?php echo htmlspecialchars($ver['license']); ?>" rel="license noopener" target="_blank">Licence</a><?php endif; ?><?php endif; ?>
    </footer>
</main>

<!-- book and chapter -->
<div class="br-sheet" id="brChooser" role="dialog" aria-modal="true" aria-labelledby="brChooserTitle" hidden>
    <div class="br-sheet-box br-sheet-tall">
        <div class="br-sheet-head">
            <h2 id="brChooserTitle">Choose a passage</h2>
            <button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="br-tabs" role="tablist" aria-label="Testament">
            <button type="button" role="tab" id="brTabOt" aria-selected="false" data-t="ot">Old Testament</button>
            <button type="button" role="tab" id="brTabNt" aria-selected="true" data-t="nt">New Testament</button>
        </div>
        <div class="br-books" id="brBooks" role="list"></div>
        <div class="br-chapters" id="brChapters" hidden>
            <button type="button" class="br-back-books" id="brBackBooks"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> <span id="brChBook">Books</span></button>
            <div class="br-ch-grid" id="brChGrid" role="list"></div>
        </div>
    </div>
</div>

<!-- versions -->
<div class="br-sheet" id="brVersions" role="dialog" aria-modal="true" aria-labelledby="brVersionsTitle" hidden>
    <div class="br-sheet-box">
        <div class="br-sheet-head">
            <h2 id="brVersionsTitle">Version</h2>
            <button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <div class="br-ver-list" id="brVerList"></div>
        <h3 class="br-sub">Read two side by side</h3>
        <div class="br-ver-list br-ver-par" id="brParList"></div>
    </div>
</div>

<!-- search -->
<div class="br-sheet" id="brSearch" role="dialog" aria-modal="true" aria-labelledby="brSearchTitle" hidden>
    <div class="br-sheet-box br-sheet-tall">
        <div class="br-sheet-head">
            <h2 id="brSearchTitle">Find</h2>
            <button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <form class="br-search-form" id="brSearchForm" role="search">
            <label class="sr-only" for="brSearchInput">A reference like John 3:16, or words to find</label>
            <input type="search" id="brSearchInput" placeholder="John 3:16, or words to find" autocomplete="off" enterkeyhint="search">
            <button type="submit" class="br-btn">Go</button>
        </form>
        <p class="br-hint" id="brSearchHint">Type a reference (Psalm 23, Yohane 3:16) to go there, or at least 3 letters to search this version.</p>
        <ol class="br-results" id="brResults" aria-live="polite"></ol>
    </div>
</div>

<!-- reading settings -->
<div class="br-sheet" id="brSettings" role="dialog" aria-modal="true" aria-labelledby="brSettingsTitle" hidden>
    <div class="br-sheet-box">
        <div class="br-sheet-head">
            <h2 id="brSettingsTitle">Reading settings</h2>
            <button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <fieldset class="br-set"><legend>Theme</legend>
            <div class="br-seg br-themes">
                <label><input type="radio" name="theme" value="light"><span class="br-sw br-sw-light"></span>Light</label>
                <label><input type="radio" name="theme" value="sepia"><span class="br-sw br-sw-sepia"></span>Sepia</label>
                <label><input type="radio" name="theme" value="dark"><span class="br-sw br-sw-dark"></span>Dark</label>
            </div>
        </fieldset>
        <fieldset class="br-set"><legend>Typeface</legend>
            <div class="br-seg"><label><input type="radio" name="font" value="serif"><span class="br-f-serif">Literata</span></label><label><input type="radio" name="font" value="sans"><span class="br-f-sans">Inter</span></label></div>
        </fieldset>
        <div class="br-set"><span class="br-set-label" id="brSizeLbl">Text size</span>
            <div class="br-stepper" role="group" aria-labelledby="brSizeLbl"><button type="button" data-step="size:-1" aria-label="Smaller text">A−</button><output id="brSizeOut">3</output><button type="button" data-step="size:1" aria-label="Larger text">A+</button></div>
        </div>
        <fieldset class="br-set"><legend>Line spacing</legend>
            <div class="br-seg"><label><input type="radio" name="leading" value="1">Snug</label><label><input type="radio" name="leading" value="2">Easy</label><label><input type="radio" name="leading" value="3">Airy</label></div>
        </fieldset>
        <fieldset class="br-set"><legend>Page width</legend>
            <div class="br-seg"><label><input type="radio" name="width" value="1">Narrow</label><label><input type="radio" name="width" value="2">Comfortable</label><label><input type="radio" name="width" value="3">Wide</label></div>
        </fieldset>
        <label class="br-toggle"><input type="checkbox" id="brNums"> <span>Show verse numbers</span></label>
        <p class="br-hint">Keys: ← → chapters · G go to · / find · + − size · T theme · Esc close</p>
    </div>
</div>

<!-- a verse -->
<div class="br-pop" id="brVerseMenu" role="dialog" aria-modal="false" aria-labelledby="brVmTitle" hidden>
    <div class="br-pop-head"><strong id="brVmTitle">Verse</strong><button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
    <div class="br-tints" role="group" aria-label="Highlight">
        <button type="button" class="br-tint t-yellow" data-tint="yellow" aria-label="Highlight gold"></button>
        <button type="button" class="br-tint t-pink" data-tint="pink" aria-label="Highlight rose"></button>
        <button type="button" class="br-tint t-green" data-tint="green" aria-label="Highlight sage"></button>
        <button type="button" class="br-tint t-blue" data-tint="blue" aria-label="Highlight sky"></button>
        <button type="button" class="br-tint t-none" data-tint="none" aria-label="Remove highlight"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
    <div class="br-actions">
        <button type="button" data-act="note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></svg>Note</button>
        <button type="button" data-act="bookmark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10v16l-5-3.5L7 20z"/></svg><span>Bookmark</span></button>
        <button type="button" data-act="save"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21l-1.4-1.3C5.4 15 2 12 2 8.5 2 5.4 4.4 3 7.5 3c1.7 0 3.4.8 4.5 2.1C13.1 3.8 14.8 3 16.5 3 19.6 3 22 5.4 22 8.5c0 3.5-3.4 6.5-8.6 11.2z"/></svg>Save</button><!-- F2: to the library -->
        <button type="button" data-act="copy"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="12" rx="2"/><path d="M5 16V5a1 1 0 0 1 1-1h9"/></svg>Copy</button>
        <button type="button" data-act="share"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3M7 8l5-5 5 5M5 13v7h14v-7"/></svg>Share</button>
    </div>
    <p class="br-hint" id="brVmHint" hidden><a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI'] ?? '/bible_reader.php'); ?>">Sign in</a> to keep highlights, notes and bookmarks.</p>
</div>

<!-- note -->
<div class="br-sheet" id="brNote" role="dialog" aria-modal="true" aria-labelledby="brNoteTitle" hidden>
    <div class="br-sheet-box">
        <div class="br-sheet-head"><h2 id="brNoteTitle">Note</h2><button type="button" class="br-icon br-close" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form id="brNoteForm">
            <label class="sr-only" for="brNoteText">Your note</label>
            <textarea id="brNoteText" rows="5" maxlength="2000" placeholder="What is this verse saying to you?"></textarea>
            <label class="br-toggle"><input type="checkbox" id="brNotePrivate" checked> <span>Only I can see this note</span></label>
            <div class="br-row"><button type="submit" class="br-btn">Save note</button></div>
        </form>
        <div id="brNoteList" class="br-notes" aria-live="polite"></div>
    </div>
</div>

<div class="br-toast" id="brToast" role="status" aria-live="polite" hidden></div>

<script>
window.AW_BIBLE = <?php echo json_encode([
    'site' => SITE_URL,
    'v' => $v, 'b' => $b, 'c' => $c, 'vs' => $vs,
    'versions' => array_map(function ($code, $x) { return ['code' => $code, 'name' => $x['name'], 'short' => $x['short'], 'lang' => $x['lang'], 'language' => $x['language'], 'scope' => $x['scope'], 'credit' => $x['credit'], 'license' => $x['license']]; }, array_keys($versions), $versions),
    'books' => $books,
    'member' => isLoggedIn(),
    'csrf' => $csrf,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/bible.js?v=3" defer></script>
</body>
</html>
