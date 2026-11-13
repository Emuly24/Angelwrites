<?php
/**
 * B1: the Bible version registry. Only complete, licensed versions appear, each with its credit line.
 *
 * Files live in assets/bible/ (denied over HTTP by assets/bible/.htaccess). The older English files
 * are .db (uploaded by hand, the deploy skips *.db); the new ones are .sqlite3 so the normal deploy
 * carries them. Every file has a `bible(book, chapter, verse, text)` table with English book names;
 * the new files also have `headings`, `titles`, `books` (names in the version's language) and
 * `bible.para` (a verse that starts a paragraph).
 */

const AW_BIBLE_VERSIONS = [
    'KJV' => [
        'file' => 'bible_KJV.db', 'name' => 'King James Version', 'short' => 'KJV', 'lang' => 'en', 'language' => 'English',
        'scope' => 'full', 'credit' => 'King James Version (1769). Public domain.', 'license' => '',
    ],
    'WEB' => [
        'file' => 'bible_WEB.db', 'name' => 'World English Bible', 'short' => 'WEB', 'lang' => 'en', 'language' => 'English',
        'scope' => 'full', 'credit' => 'World English Bible. Public domain.', 'license' => '',
    ],
    'ASV' => [
        'file' => 'bible_ASV.db', 'name' => 'American Standard Version', 'short' => 'ASV', 'lang' => 'en', 'language' => 'English',
        'scope' => 'full', 'credit' => 'American Standard Version (1901). Public domain.', 'license' => '',
    ],
    'NYA' => [
        'file' => 'bible_NYA.sqlite3', 'name' => 'Mawu a Mulungu mu Chichewa Chalero', 'short' => 'Chichewa', 'lang' => 'ny', 'language' => 'Chichewa',
        'scope' => 'full',
        // CC BY-SA 4.0: the text is redistributed unmodified (markup removed only), so the Biblica® mark
        // and the notice are kept exactly as the publisher asks.
        'credit' => 'Biblica® Tsekulani Mawu a Mulungu mu Chichewa Chalero™ Mwini © 2002, 2016 ndi Biblica, Inc. '
                  . 'Biblica® Open God’s Word in Contemporary Chichewa™ Copyright © 2002, 2016 by Biblica, Inc. '
                  . '“Biblica” is a trademark registered in the United States Patent and Trademark Office by Biblica, Inc. Used with permission. '
                  . 'Licensed under Creative Commons Attribution-ShareAlike 4.0. Source: ebible.org/nya.',
        'license' => 'https://creativecommons.org/licenses/by-sa/4.0/',
    ],
    'YAO' => [
        'file' => 'bible_YAO.sqlite3', 'name' => 'Lilangano lya Sambano', 'short' => 'Chiyao NT', 'lang' => 'yao', 'language' => 'Chiyao',
        'scope' => 'nt',
        'credit' => 'Lilangano lya Sambano (Yao New Testament). Yao Bible Version Translation Project, copyright © 2018 The Word for the World International. '
                  . 'Licensed under Creative Commons Attribution 4.0. Source: ebible.org/yao. Shown unchanged (formatting codes removed).',
        'license' => 'https://creativecommons.org/licenses/by/4.0/',
    ],
];

/** The versions whose files are present on this server. */
function aw_bible_versions() {
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    foreach (AW_BIBLE_VERSIONS as $code => $v) {
        if (is_file(aw_bible_file($code))) $out[$code] = $v;
    }
    return $out;
}

function aw_bible_file($code) {
    $v = AW_BIBLE_VERSIONS[$code] ?? null;
    return $v ? dirname(__DIR__) . '/assets/bible/' . $v['file'] : '';
}

/** A read-only connection to a version's file, or null. */
function aw_bible_db($code) {
    static $c = [];
    if (array_key_exists($code, $c)) return $c[$code];
    $f = aw_bible_file($code);
    if ($f === '' || !is_file($f)) return $c[$code] = null;
    try {
        $db = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return $c[$code] = $db;
    } catch (Throwable $e) {
        error_log('bible db ' . $code . ': ' . $e->getMessage());
        return $c[$code] = null;
    }
}

function aw_bible_has_table(PDO $db, $t) {
    try { $s = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"); $s->execute([$t]); return (bool)$s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** The 66 books in order (English names), with testament. */
function aw_bible_canon() {
    $ot = ['Genesis','Exodus','Leviticus','Numbers','Deuteronomy','Joshua','Judges','Ruth','1 Samuel','2 Samuel','1 Kings','2 Kings',
           '1 Chronicles','2 Chronicles','Ezra','Nehemiah','Esther','Job','Psalms','Proverbs','Ecclesiastes','Song of Solomon','Isaiah',
           'Jeremiah','Lamentations','Ezekiel','Daniel','Hosea','Joel','Amos','Obadiah','Jonah','Micah','Nahum','Habakkuk','Zephaniah',
           'Haggai','Zechariah','Malachi'];
    $nt = ['Matthew','Mark','Luke','John','Acts','Romans','1 Corinthians','2 Corinthians','Galatians','Ephesians','Philippians',
           'Colossians','1 Thessalonians','2 Thessalonians','1 Timothy','2 Timothy','Titus','Philemon','Hebrews','James','1 Peter',
           '2 Peter','1 John','2 John','3 John','Jude','Revelation'];
    return ['ot' => $ot, 'nt' => $nt];
}

/** Books in a version: [['book' => English, 'name' => in the version's language, 'chapters' => n, 't' => 'ot'|'nt'], ...] */
function aw_bible_books($code) {
    $db = aw_bible_db($code);
    if (!$db) return [];
    $canon = aw_bible_canon();
    try {
        $counts = [];
        foreach ($db->query("SELECT book, MAX(chapter) AS n FROM bible GROUP BY book") as $r) $counts[$r['book']] = (int)$r['n'];
        $names = [];
        if (aw_bible_has_table($db, 'books')) foreach ($db->query("SELECT book, name, short FROM books") as $r) $names[$r['book']] = $r['short'] ?: $r['name'];
        $out = [];
        foreach (['ot', 'nt'] as $t) foreach ($canon[$t] as $b) {
            if (!isset($counts[$b])) continue;
            $out[] = ['book' => $b, 'name' => $names[$b] ?? $b, 'chapters' => $counts[$b], 't' => $t];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('bible books: ' . $e->getMessage());
        return [];
    }
}

/**
 * One chapter, ready to read: ['book_name', 'title', 'verses' => [['v', 'text', 'para', 'heads' => [...]]]].
 * Returns null when the version or chapter does not exist.
 */
function aw_bible_chapter($code, $book, $chapter) {
    $db = aw_bible_db($code);
    if (!$db) return null;
    try {
        $hasPara = false;
        foreach ($db->query("PRAGMA table_info(bible)") as $c) if ($c['name'] === 'para') $hasPara = true;
        $st = $db->prepare("SELECT verse AS v, text" . ($hasPara ? ", para" : ", 0 AS para") . " FROM bible WHERE book = ? AND chapter = ? ORDER BY verse");
        $st->execute([(string)$book, (int)$chapter]);
        $verses = $st->fetchAll();
        if (!$verses) return null;
        $heads = []; $title = null; $bookName = (string)$book;
        if (aw_bible_has_table($db, 'headings')) {
            $h = $db->prepare("SELECT verse, level, text FROM headings WHERE book = ? AND chapter = ? ORDER BY rowid");
            $h->execute([(string)$book, (int)$chapter]);
            foreach ($h as $r) $heads[(int)$r['verse']][] = ['level' => (int)$r['level'], 'text' => $r['text']];
        }
        if (aw_bible_has_table($db, 'titles')) {
            $t = $db->prepare("SELECT text FROM titles WHERE book = ? AND chapter = ?");
            $t->execute([(string)$book, (int)$chapter]);
            $title = $t->fetchColumn() ?: null;
        }
        if (aw_bible_has_table($db, 'books')) {
            $n = $db->prepare("SELECT COALESCE(short, name) FROM books WHERE book = ?");
            $n->execute([(string)$book]);
            $bookName = (string)($n->fetchColumn() ?: $book);
        }
        foreach ($verses as &$v) { $v['v'] = (int)$v['v']; $v['para'] = (int)$v['para']; $v['heads'] = $heads[$v['v']] ?? []; }
        return ['book_name' => $bookName, 'title' => $title, 'verses' => $verses];
    } catch (Throwable $e) {
        error_log('bible chapter: ' . $e->getMessage());
        return null;
    }
}

/** Word search in one version: at least 3 letters, at most 50 results. */
function aw_bible_search($code, $q) {
    $q = trim((string)$q);
    if (mb_strlen($q) < 3) return [];
    $db = aw_bible_db($code);
    if (!$db) return [];
    try {
        $st = $db->prepare("SELECT book, chapter, verse, text FROM bible WHERE text LIKE ? ESCAPE '\\' ORDER BY id LIMIT 50");
        $st->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%']);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('bible search: ' . $e->getMessage());
        return [];
    }
}
