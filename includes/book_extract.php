<?php
/**
 * Automatic book preparation for the reader.
 *
 * A book file (PDF, EPUB, DOCX, ODT, RTF or TXT) is turned into reader pages without anyone having
 * to correct or publish it by hand. It runs when a book file is uploaded (admin/manage_books.php)
 * and, for any book that has no reader text yet, the first time someone opens it in the reader.
 *
 * Text that was corrected and published by hand earlier is kept: it is only replaced when a new
 * file is uploaded, or when the team chooses "Prepare again" in admin/process_book.php.
 *
 * The original file is never sent to visitors; it stays in assets/uploads/books (refused over HTTP
 * by that folder's .htaccess) and is only read from disk here.
 *
 * Nothing in this file throws or stops the page: every public function returns a value and logs.
 */

if (!defined('AW_BOOK_MAX_EXTRACT_BYTES')) define('AW_BOOK_MAX_EXTRACT_BYTES', 15 * 1024 * 1024);
if (!defined('AW_BOOK_WORDS_PER_PAGE')) define('AW_BOOK_WORDS_PER_PAGE', 350);
if (!defined('AW_BOOK_HEADING')) define('AW_BOOK_HEADING', "\x1E"); // marks a heading line in extracted text

/** Formats the reader can prepare. */
function aw_book_formats() {
    return ['pdf' => 'PDF', 'epub' => 'EPUB', 'docx' => 'Word (DOCX)', 'odt' => 'OpenDocument (ODT)', 'rtf' => 'RTF', 'txt' => 'Plain text'];
}

/** Absolute path of a stored book file ('' when missing). */
function aw_book_file_abs($rel) {
    $rel = ltrim((string)$rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false) return '';
    $abs = dirname(__DIR__) . '/' . $rel;
    return is_file($abs) ? $abs : '';
}

/** The file's real format, decided by its content (the name is only a last hint). */
function aw_book_type($abs) {
    $head = (string)@file_get_contents($abs, false, null, 0, 1024);
    if ($head === '') return '';
    if (strncmp($head, '%PDF', 4) === 0 || strpos(substr($head, 0, 1024), '%PDF-') !== false) return 'pdf';
    if (strncmp($head, '{\\rtf', 5) === 0) return 'rtf';
    if (strncmp($head, "PK\x03\x04", 4) === 0) {
        if (strpos($head, 'mimetypeapplication/epub+zip') !== false) return 'epub';
        if (strpos($head, 'mimetypeapplication/vnd.oasis.opendocument.text') !== false) return 'odt';
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($abs) === true) {
                $k = '';
                if ($z->locateName('word/document.xml') !== false) $k = 'docx';
                elseif ($z->locateName('META-INF/container.xml') !== false) $k = 'epub';
                elseif ($z->locateName('content.xml') !== false) $k = 'odt';
                $z->close();
                return $k;
            }
        }
        return '';
    }
    // plain text: no NUL bytes in the first kilobyte
    if (strpos($head, "\0") === false) return 'txt';
    return '';
}

/** Make text UTF-8 (files from older Windows programs are often Windows-1252). */
function aw_book_utf8($text) {
    $text = (string)$text;
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
    if (strncmp($text, "\xFF\xFE", 2) === 0 || strncmp($text, "\xFE\xFF", 2) === 0) {
        $c = @mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        if ($c !== false) return $c;
    }
    if (mb_check_encoding($text, 'UTF-8')) return $text;
    $c = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    return $c !== false ? $c : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
}

/** A heading line in extracted text. */
function aw_book_h($title) {
    $title = trim(preg_replace('/\s+/u', ' ', (string)$title));
    return $title === '' ? '' : "\n\n" . AW_BOOK_HEADING . $title . "\n\n";
}

/** Tidy whitespace: single spaces, paragraphs separated by one blank line. */
function aw_book_tidy($text) {
    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], (string)$text);
    $text = preg_replace("/[ \t\f\v]+/u", ' ', $text);
    $text = preg_replace("/ *\n */u", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/** (X)HTML to text; h1/h2 become headings. */
function aw_book_html_to_text($html) {
    $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', (string)$html);
    $html = preg_replace_callback('#<h([12])\b[^>]*>(.*?)</h\1>#is', function ($m) {
        return aw_book_h(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }, $html);
    $html = preg_replace('#<br\b[^>]*/?>#i', "\n", $html);
    $html = preg_replace('#</(p|div|h[3-6]|li|blockquote|section|tr|pre)>#i', "\n\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return aw_book_tidy($text);
}

function aw_book_extract_docx($abs) {
    $z = new ZipArchive();
    if ($z->open($abs) !== true) return false;
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if ($xml === false) return false;
    $out = '';
    if (preg_match_all('#<w:p\b[^>]*?(?:/>|>(.*?)</w:p>)#s', $xml, $ps)) {
        foreach ($ps[1] as $p) {
            $style = preg_match('#<w:pStyle w:val="([^"]+)"#', $p, $sm) ? $sm[1] : '';
            $p = preg_replace('#<w:tab\s*/>#', ' ', $p);
            $p = preg_replace('#<w:(br|cr)\b[^>]*/>#', "\n", $p);
            $p = preg_replace('#<w:(delText|instrText)\b[^>]*>.*?</w:\1>#s', '', $p); // deleted text, field codes
            $t = '';
            if (preg_match_all('#<w:t\b[^>]*>(.*?)</w:t>|(\n)#s', $p, $ts, PREG_SET_ORDER)) {
                foreach ($ts as $tm) $t .= isset($tm[2]) && $tm[2] === "\n" ? "\n" : $tm[1];
            }
            $t = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (trim($t) === '') continue;
            $out .= preg_match('/^(Heading ?[12]|Title|Titre|Titel|Kop[12]|berschrift ?[12])$/i', $style) ? aw_book_h($t) : $t . "\n\n";
        }
    }
    return aw_book_tidy($out);
}

function aw_book_extract_odt($abs) {
    $z = new ZipArchive();
    if ($z->open($abs) !== true) return false;
    $xml = $z->getFromName('content.xml');
    $z->close();
    if ($xml === false) return false;
    $xml = preg_replace('#<text:(line-break)\s*/>#', "\n", $xml);
    $xml = preg_replace('#<text:tab\s*/>#', ' ', $xml);
    $xml = preg_replace_callback('#<text:s(?: text:c="(\d+)")?\s*/>#', function ($m) { return str_repeat(' ', max(1, (int)($m[1] ?? 1))); }, $xml);
    $xml = preg_replace('#<text:note\b.*?</text:note>#s', '', $xml); // footnote bodies
    $out = '';
    if (preg_match_all('#<text:(h|p)\b([^>]*?)(?:/>|>(.*?)</text:\1>)#s', $xml, $ps, PREG_SET_ORDER)) {
        foreach ($ps as $m) {
            $t = html_entity_decode(strip_tags($m[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (trim($t) === '') continue;
            $level = preg_match('#text:outline-level="(\d+)"#', $m[2], $lm) ? (int)$lm[1] : 1;
            $out .= ($m[1] === 'h' && $level <= 2) ? aw_book_h($t) : $t . "\n\n";
        }
    }
    return aw_book_tidy($out);
}

function aw_book_extract_epub($abs) {
    $z = new ZipArchive();
    if ($z->open($abs) !== true) return false;
    $opf_path = null;
    $container = $z->getFromName('META-INF/container.xml');
    if ($container && preg_match('/full-path="([^"]+)"/', $container, $m)) $opf_path = $m[1];
    for ($i = 0; !$opf_path && $i < $z->numFiles; $i++) {
        if (substr($z->getNameIndex($i), -4) === '.opf') $opf_path = $z->getNameIndex($i);
    }
    $opf_xml = $opf_path ? $z->getFromName($opf_path) : false;
    if (!$opf_xml) { $z->close(); return false; }
    $prev = libxml_use_internal_errors(true);
    $opf = simplexml_load_string($opf_xml);
    libxml_use_internal_errors($prev);
    if (!$opf) { $z->close(); return false; }
    $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');
    $base = (dirname($opf_path) === '.') ? '' : dirname($opf_path) . '/';
    $manifest = [];
    foreach ($opf->xpath('//opf:manifest/opf:item') ?: [] as $item) {
        $manifest[(string)$item['id']] = ['href' => (string)$item['href'], 'type' => (string)$item['media-type'], 'props' => (string)$item['properties']];
    }
    $order = [];
    foreach ($opf->xpath('//opf:spine/opf:itemref') ?: [] as $ref) {
        $id = (string)$ref['idref'];
        if (isset($manifest[$id]) && strpos($manifest[$id]['props'], 'nav') === false) $order[] = $manifest[$id]['href'];
    }
    if (!$order) foreach ($manifest as $it) if ($it['type'] === 'application/xhtml+xml' && strpos($it['props'], 'nav') === false) $order[] = $it['href'];
    $parts = [];
    foreach ($order as $href) {
        $c = $z->getFromName($base . rawurldecode(strtok($href, '#')));
        if ($c !== false && ($t = aw_book_html_to_text($c)) !== '') $parts[] = $t;
    }
    $z->close();
    return $parts ? implode("\n\n", $parts) : false;
}

function aw_book_extract_rtf($abs) {
    $rtf = (string)@file_get_contents($abs);
    if ($rtf === '') return false;
    // drop destinations that hold no body text
    foreach (['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'listtable', 'listoverridetable', 'rsidtbl', 'xmlnstbl', 'themedata', 'colorschememapping', 'datastore', 'latentstyles', 'generator'] as $d) {
        $rtf = aw_book_rtf_drop_group($rtf, '\\' . $d);
    }
    $rtf = aw_book_rtf_drop_group($rtf, '\\*');
    $rtf = preg_replace_callback("/\\\\'([0-9a-fA-F]{2})/", function ($m) { return mb_convert_encoding(chr(hexdec($m[1])), 'UTF-8', 'Windows-1252'); }, $rtf);
    $rtf = preg_replace_callback('/\\\\u(-?\d+)\??/', function ($m) { $n = (int)$m[1]; if ($n < 0) $n += 65536; return mb_chr($n, 'UTF-8'); }, $rtf);
    $rtf = preg_replace('/\\\\(par|line|sect|page)\b ?/', "\n\n", $rtf);
    $rtf = preg_replace('/\\\\tab\b ?/', ' ', $rtf);
    $rtf = str_replace(['\\{', '\\}', '\\\\'], ["\x01", "\x02", "\x03"], $rtf);
    $rtf = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $rtf);
    $rtf = str_replace(['{', '}', "\x01", "\x02", "\x03"], ['', '', '{', '}', '\\'], $rtf);
    return aw_book_tidy($rtf);
}

/** Remove every {<marker> ...} group, nested braces included. */
function aw_book_rtf_drop_group($rtf, $marker) {
    $needle = '{' . $marker;
    $guard = 0;
    while (($p = strpos($rtf, $needle)) !== false && $guard++ < 5000) {
        $nextCh = substr($rtf, $p + strlen($needle), 1);
        if ($marker !== '\\*' && $nextCh !== '' && ctype_alpha($nextCh)) { // \info must not match \infofoo
            $rtf = substr($rtf, 0, $p) . "\x04" . substr($rtf, $p + 1); // leave this group, look further
            continue;
        }
        $depth = 0; $len = strlen($rtf);
        for ($i = $p; $i < $len; $i++) {
            $ch = $rtf[$i];
            if ($ch === '\\') { $i++; continue; }
            if ($ch === '{') $depth++;
            elseif ($ch === '}' && --$depth === 0) break;
        }
        $rtf = substr($rtf, 0, $p) . substr($rtf, $i + 1);
    }
    return str_replace("\x04", '{', $rtf);
}

function aw_book_extract_txt($abs) {
    $t = (string)@file_get_contents($abs);
    if ($t === '') return false;
    $t = aw_book_utf8($t);
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    if (substr_count($t, "\n\n") < 3) $t = preg_replace("/\n/", "\n\n", $t); // one paragraph per line
    return aw_book_tidy($t);
}

/**
 * PDF lines are broken where the printed line ended. Join them back into paragraphs: a paragraph
 * ends after a short line or a line ending a sentence that is followed by a capital letter.
 */
function aw_book_reflow($text) {
    $text = str_replace(["\r\n", "\r", "\f"], "\n", (string)$text);
    $lines = array_map('trim', explode("\n", $text));
    $lens = [];
    foreach ($lines as $l) { $n = mb_strlen($l); if ($n > 20) $lens[] = $n; }
    sort($lens);
    $typical = $lens ? $lens[(int)(count($lens) * 0.75)] : 70;
    $out = []; $cur = '';
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $l = $lines[$i];
        if ($l === '') { if ($cur !== '') { $out[] = $cur; $cur = ''; } continue; }
        if (preg_match('/^\d{1,4}$/', $l)) continue; // printed page number
        if (aw_book_is_section_start($l)) { if ($cur !== '') $out[] = $cur; $out[] = AW_BOOK_HEADING . $l; $cur = ''; continue; }
        if ($cur === '') $cur = $l;
        elseif (preg_match('/[a-z]-$/u', $cur)) $cur = mb_substr($cur, 0, -1) . $l; // hyphenated word
        else $cur .= ' ' . $l;
        $next = $lines[$i + 1] ?? '';
        $short = mb_strlen($l) < $typical * 0.7;
        $ends = preg_match('/[.!?:;"”’)\]]$/u', $l);
        if ($next === '' || ($short && ($ends || preg_match('/^[\p{Lu}"“‘(\d]/u', $next))) ) { $out[] = $cur; $cur = ''; }
    }
    if ($cur !== '') $out[] = $cur;
    return implode("\n\n", $out);
}

/** PDF: one entry per printed page (so page numbers match the printed book). */
function aw_book_extract_pdf_pages($abs) {
    if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
        $auto = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($auto)) require_once $auto;
    }
    if (!class_exists('\\Smalot\\PdfParser\\Parser')) return false;
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($abs);
        $pages = [];
        foreach ($pdf->getPages() as $pg) {
            $pages[] = aw_book_utf8((string)$pg->getText());
        }
        if (!$pages) $pages = [aw_book_utf8((string)$pdf->getText())];
        return $pages;
    } catch (Throwable $e) {
        error_log('book pdf ' . basename($abs) . ': ' . $e->getMessage());
        return false;
    }
}

/** A chapter or front/back-matter heading line. */
function aw_book_is_section_start($line) {
    $t = trim((string)$line);
    if ($t === '' || mb_strlen($t) > 80) return false;
    if (preg_match('/^(Chapter|CHAPTER|Chap\.?|Mutu|MUTU|Part|PART)\s+(\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Eleven|Twelve)\b/iu', $t)) return true;
    foreach (['Acknowledgement', 'Acknowledgment', "Author's Note", 'About the Author', 'Preface', 'Foreword', 'Introduction', 'Dedication', 'Prologue', 'Epilogue', 'Conclusion', 'Afterword'] as $k) {
        if (preg_match('/^' . preg_quote($k, '/') . 's?\b/iu', $t) && mb_strlen($t) < 60) return true;
    }
    return false;
}

/** Text with heading markers -> list of blocks [['h'|'p', text], ...]. */
function aw_book_blocks($text) {
    $blocks = [];
    foreach (preg_split('/\n\s*\n/u', (string)$text) as $para) {
        $para = trim($para);
        if ($para === '' || preg_match('/^\d{1,4}$/', $para)) continue;
        if ($para[0] === AW_BOOK_HEADING) { $blocks[] = ['h', trim(substr($para, 1))]; continue; }
        if (aw_book_is_section_start($para)) { $blocks[] = ['h', $para]; continue; }
        $blocks[] = ['p', $para];
    }
    return $blocks;
}

/** Blocks -> pages of about $words words; every heading starts a new page. */
function aw_book_paginate(array $blocks, $words = AW_BOOK_WORDS_PER_PAGE) {
    $pages = []; $cur = []; $count = 0;
    foreach ($blocks as $b) {
        $w = max(1, count(preg_split('/\s+/u', $b[1], -1, PREG_SPLIT_NO_EMPTY)));
        if ($cur && ($b[0] === 'h' || $count + $w > $words * 1.15)) {
            // keep a heading with the text that follows it
            $pages[] = $cur; $cur = []; $count = 0;
        }
        $cur[] = $b; $count += $w;
    }
    if ($cur) $pages[] = $cur;
    return $pages;
}

/**
 * Extract a book file into pages of blocks.
 * Returns ['ok' => true, 'pages' => [[blocks]...], 'type' => ...] or ['ok' => false, 'error' => message].
 */
function aw_book_extract($abs) {
    if ($abs === '' || !is_file($abs)) return ['ok' => false, 'error' => 'The book file is missing. Upload it again in Manage books.'];
    $size = (int)filesize($abs);
    if ($size > AW_BOOK_MAX_EXTRACT_BYTES) {
        return ['ok' => false, 'error' => 'This file is ' . round($size / 1048576, 1) . ' MB; the host can prepare books up to ' . (AW_BOOK_MAX_EXTRACT_BYTES / 1048576) . ' MB. Save it without large pictures, or as EPUB or DOCX, and upload it again.'];
    }
    $type = aw_book_type($abs);
    try {
        if ($type === 'pdf') {
            $raw = aw_book_extract_pdf_pages($abs);
            if ($raw === false) return ['ok' => false, 'type' => 'pdf', 'error' => 'This PDF could not be read (it may be damaged or password-protected). Save it again from the original program, or upload it as DOCX or EPUB.'];
            $pages = [];
            foreach ($raw as $txt) {
                $b = aw_book_blocks(aw_book_reflow($txt));
                if ($b) $pages[] = $b;
            }
            // a chapter that begins part-way down a printed page starts a new reader page, so every
            // page belongs to one chapter; very long PDF pages are cut into reader-sized pages
            $split = [];
            foreach ($pages as $pg) {
                $cur = [];
                foreach ($pg as $b) {
                    if ($b[0] === 'h' && $cur && !(count($cur) === 1 && $cur[0][0] === 'h')) { $split[] = $cur; $cur = []; }
                    $cur[] = $b;
                }
                if ($cur) $split[] = $cur;
            }
            $pages = $split;
            $fixed = [];
            foreach ($pages as $pg) {
                $w = 0; foreach ($pg as $b) $w += str_word_count($b[1]);
                if ($w > 900) { foreach (aw_book_paginate($pg) as $sub) $fixed[] = $sub; } else $fixed[] = $pg;
            }
            $pages = $fixed;
        } else {
            switch ($type) {
                case 'epub': $text = aw_book_extract_epub($abs); break;
                case 'docx': $text = aw_book_extract_docx($abs); break;
                case 'odt':  $text = aw_book_extract_odt($abs); break;
                case 'rtf':  $text = aw_book_extract_rtf($abs); break;
                case 'txt':  $text = aw_book_extract_txt($abs); break;
                default:
                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                    return ['ok' => false, 'error' => ($ext === 'doc' ? 'Old Word .doc files cannot be read. Open it in Word and save it as .docx, then upload it again.' : 'This file type cannot be shown in the reader. Use ' . implode(', ', aw_book_formats()) . '.')];
            }
            if ($text === false) return ['ok' => false, 'type' => $type, 'error' => 'The ' . strtoupper($type) . ' file could not be opened (it may be damaged). Save it again from the original program and upload it.'];
            $pages = aw_book_paginate(aw_book_blocks(aw_book_utf8($text)));
        }
    } catch (Throwable $e) {
        error_log('book extract ' . basename($abs) . ': ' . $e->getMessage());
        return ['ok' => false, 'type' => $type, 'error' => 'The file could not be read. Save it again from the original program and upload it.'];
    }
    $words = 0;
    foreach ($pages as $pg) foreach ($pg as $b) $words += str_word_count($b[1]);
    if ($words < 50) {
        return ['ok' => false, 'type' => $type, 'error' => $type === 'pdf'
            ? 'This PDF has almost no text that can be read: it is probably scanned (pictures of the pages). Upload a PDF exported from Word, or a DOCX or EPUB of the book.'
            : 'Almost no text was found in this file. Check that it is the finished book and upload it again.'];
    }
    return ['ok' => true, 'type' => $type, 'pages' => $pages, 'words' => $words];
}

/** Pages of blocks -> [content_html, toc]. */
function aw_book_render(array $pages, $title, $author) {
    $html = "<h1 class='book-title'>" . htmlspecialchars((string)$title) . "</h1>\n";
    if ((string)$author !== '') $html .= "<p class='book-author'>by " . htmlspecialchars((string)$author) . "</p>\n";
    $toc = [];
    foreach ($pages as $i => $pg) {
        $n = $i + 1;
        $html .= '<div class="page-content" data-page="' . $n . '">';
        foreach ($pg as $b) {
            $t = htmlspecialchars($b[1], ENT_QUOTES, 'UTF-8');
            if ($b[0] === 'h') {
                $html .= '<h2 class="chapter-heading">' . $t . '</h2>';
                $toc[] = ['title' => $b[1], 'page' => $n];
            } else {
                $html .= '<p>' . nl2br($t, false) . '</p>';
            }
        }
        $html .= '</div><div class="page-break" data-page="' . $n . '"></div>' . "\n";
    }
    return [$html, $toc];
}

/** Small exclusive lock so two first readers do not prepare the same book at once. */
function aw_book_lock($book_id) {
    $dir = dirname(__DIR__) . '/data/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fh = @fopen($dir . '/book_prepare_' . (int)$book_id . '.lock', 'c');
    if (!$fh) return null;
    if (!@flock($fh, LOCK_EX)) { fclose($fh); return null; }
    return $fh;
}

/**
 * Prepare a book for the reader.
 *   $force = false: only when it has no reader text yet, or its automatic text came from another
 *                   file (text published by hand is kept);
 *   $force = true:  always, from the current file (a new upload, or "Prepare again").
 * Returns ['ok' => bool, 'status' => 'ready'|'kept'|'prepared'|'failed', 'error' => ..., 'pages' => n, ...].
 */
function aw_book_prepare(PDO $db, $book_id, $force = false) {
    $book_id = (int)$book_id;
    try {
        $st = $db->prepare("SELECT id, title, author, file_path FROM books WHERE id = ?");
        $st->execute([$book_id]);
        $book = $st->fetch(PDO::FETCH_ASSOC);
        if (!$book) return ['ok' => false, 'status' => 'failed', 'error' => 'Book not found.'];
        $abs = aw_book_file_abs($book['file_path'] ?? '');
        if ($abs === '') return ['ok' => false, 'status' => 'failed', 'error' => 'This book has no file to read. Upload one in Manage books.'];
        $sha = (string)@sha1_file($abs);

        $lock = aw_book_lock($book_id);
        $st = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
        $st->execute([$book_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $meta = $row ? (json_decode((string)($row['metadata_json'] ?? ''), true) ?: []) : [];

        if (!$force && $row) {
            $ready = (int)$row['is_processed'] === 1 && trim((string)$row['content_html']) !== '';
            if ($ready && (empty($meta['auto']) || ($meta['source_sha1'] ?? '') === $sha)) {
                if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
                return ['ok' => true, 'status' => empty($meta['auto']) ? 'kept' : 'ready'];
            }
            // the same file already failed: do not try again on every visit
            if (!$ready && ($row['processing_status'] ?? '') === 'failed' && ($meta['source_sha1'] ?? '') === $sha) {
                if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
                return ['ok' => false, 'status' => 'failed', 'error' => (string)($meta['error'] ?? 'This book could not be prepared.')];
            }
        }

        @set_time_limit(120);
        $mem = ini_get('memory_limit');
        if ($mem !== false && $mem !== '-1' && (int)$mem < 256) @ini_set('memory_limit', '256M');
        $t0 = microtime(true);
        $ex = aw_book_extract($abs);

        if (empty($ex['ok'])) {
            $fail = json_encode(['auto' => 1, 'source_sha1' => $sha, 'error' => $ex['error'], 'type' => $ex['type'] ?? '', 'at' => date('c')]);
            if (!$row) {
                $db->prepare("INSERT INTO book_content (book_id, title, content_html, toc_json, metadata_json, is_processed, processing_status) VALUES (?, ?, '', '[]', ?, 0, 'failed')")
                   ->execute([$book_id, $book['title'], $fail]);
            } elseif ((int)$row['is_processed'] !== 1) {
                $db->prepare("UPDATE book_content SET metadata_json = ?, processing_status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE book_id = ?")->execute([$fail, $book_id]);
            } // a readable earlier text stays in place when the new file cannot be read
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            error_log('book prepare ' . $book_id . ': ' . $ex['error']);
            return ['ok' => false, 'status' => 'failed', 'error' => $ex['error']];
        }

        list($html, $toc) = aw_book_render($ex['pages'], $book['title'], $book['author'] ?? '');
        $chapters = count($toc);
        $newMeta = json_encode(['auto' => 1, 'source_sha1' => $sha, 'type' => $ex['type'], 'words' => $ex['words'],
                                'pages' => count($ex['pages']), 'chapters' => $chapters, 'seconds' => round(microtime(true) - $t0, 1), 'at' => date('c')]);

        $db->beginTransaction();
        if ($row && trim((string)$row['content_html']) !== '') {
            // keep the text being replaced, in case it held hand corrections
            try {
                $v = (int)$db->query("SELECT COALESCE(MAX(version), 0) FROM book_content_history WHERE book_id = " . $book_id)->fetchColumn() + 1;
                $db->prepare("INSERT INTO book_content_history (book_id, content_html, toc_json, metadata_json, version, note) VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$book_id, $row['content_html'], $row['toc_json'], $row['metadata_json'], $v, 'Kept before automatic preparation on ' . date('j M Y')]);
            } catch (Throwable $e) {
                error_log('book prepare history ' . $book_id . ': ' . $e->getMessage());
            }
        }
        if ($row) {
            $db->prepare("UPDATE book_content SET title = ?, content_html = ?, toc_json = ?, metadata_json = ?, is_processed = 1, processing_status = 'complete', version = COALESCE(version, 0) + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?")
               ->execute([$book['title'], $html, json_encode($toc), $newMeta, $book_id]);
        } else {
            $db->prepare("INSERT INTO book_content (book_id, title, content_html, toc_json, metadata_json, is_processed, processing_status, published_version, version) VALUES (?, ?, ?, ?, ?, 1, 'complete', 1, 1)")
               ->execute([$book_id, $book['title'], $html, json_encode($toc), $newMeta]);
        }
        // Saved places are kept at the same share of the book (the page numbers changed).
        if ($row) {
            try {
                $n = max(1, count($ex['pages']));
                $db->prepare("UPDATE reading_progress SET position_section = MAX(1, MIN(?, CAST(ROUND(COALESCE(progress_percent, 0) * ? / 100.0) AS INTEGER))), position_offset = 0 WHERE book_id = ?")
                   ->execute([$n, $n, $book_id]);
            } catch (Throwable $e) {
                error_log('book prepare progress ' . $book_id . ': ' . $e->getMessage());
            }
        }
        $db->commit();
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return ['ok' => true, 'status' => 'prepared', 'pages' => count($ex['pages']), 'words' => $ex['words'], 'chapters' => $chapters, 'type' => $ex['type']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (!empty($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
        error_log('book prepare ' . $book_id . ': ' . $e->getMessage());
        return ['ok' => false, 'status' => 'failed', 'error' => 'The book could not be prepared just now. Please try again.'];
    }
}
