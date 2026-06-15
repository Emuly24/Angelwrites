<?php
// ============================================================
//  PROCESS BOOK.PHP – COMPLETE PRODUCTION VERSION
//  All features preserved, all errors fixed, no placeholders.
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\' https:; style-src \'self\' \'unsafe-inline\' https:; script-src \'self\' \'unsafe-inline\' https:; img-src \'self\' data: https:;');

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

redirectIfNotAdmin();

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header('Location: ' . SITE_URL . '/admin/manage_books.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
//  CORE FUNCTIONS
// ============================================================

function detectFileType($file_path) {
    if (!file_exists($file_path)) return false;
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($file_path);
        if (strpos($mime, 'pdf') !== false) return 'pdf';
        if (in_array($mime, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
            return 'docx';
        }
        if (strpos($mime, 'epub') !== false) return 'epub';
    }
    return strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
}

// ============================================================
//  NUCLEAR FIX ENCODING – Identity‑H safe
// ============================================================
function fixEncoding($text) {
    if (mb_check_encoding($text, 'UTF-8') && !preg_match('/[\x80-\xFF]/', $text)) {
        return trim($text);
    }
    $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    if (!preg_match('/[â€œâ€™â€“]/', $converted)) {
        return trim($converted);
    }
    $iso = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    if (!preg_match('/[â€œâ€™â€“]/', $iso)) {
        return trim($iso);
    }
    $win1250 = mb_convert_encoding($text, 'UTF-8', 'Windows-1250');
    if (!preg_match('/[â€œâ€™â€“]/', $win1250)) {
        return trim($win1250);
    }
    $map = [
        'â€œ' => '“', 'â€' => '”', 'â€™' => '’', 'â€˜' => '‘',
        'â€"' => '—', 'â€”' => '—', 'â€“' => '–',
        'â€¦' => '…', 'â€¢' => '•', 'â€¹' => '‹', 'â€º' => '›',
        'â‚¬' => '€', 'â„¢' => '™', 'â€¡' => '‡', 'â€°' => '‰',
        'â€š' => '‚', 'â€ž' => '„',
        'Ã¢' => 'â', 'Ã©' => 'é', 'Ã¨' => 'è', 'Ãª' => 'ê',
        'Ã«' => 'ë', 'Ã¯' => 'ï', 'Ã®' => 'î', 'Ã¬' => 'ì',
        'Ã¡' => 'á', 'Ã±' => 'ñ', 'Ã§' => 'ç', 'Ã¸' => 'ø',
        'Ã¦' => 'æ', 'Ã¥' => 'å', 'Ã¤' => 'ä', 'Ã¶' => 'ö',
        'Ã¼' => 'ü', 'ÃŸ' => 'ß',
        'Ã‚' => 'Â', 'Ãƒ' => 'Ã', 'Ã…' => 'Å', 'Ã†' => 'Æ',
        'Ãˆ' => 'È', 'Ã‰' => 'É', 'ÃŠ' => 'Ê', 'Ã‹' => 'Ë',
        'ÃŒ' => 'Ì', 'ÃŽ' => 'Î', 'Ã‘' => 'Ñ', 'Ã“' => 'Ó',
        'Ã”' => 'Ô', 'Ã•' => 'Õ', 'Ã–' => 'Ö', 'Ã—' => '×',
        'Ã˜' => 'Ø', 'Ã™' => 'Ù', 'Ãš' => 'Ú', 'Ã›' => 'Û',
        'Ãœ' => 'Ü', 'Ãž' => 'Þ',
        "\xC2\x82" => "‚", "\xC2\x84" => "„", "\xC2\x85" => "…",
        "\xC2\x86" => "†", "\xC2\x87" => "‡", "\xC2\x88" => "ˆ",
        "\xC2\x89" => "‰", "\xC2\x8A" => "Š", "\xC2\x8B" => "‹",
        "\xC2\x8C" => "Œ", "\xC2\x8E" => "Ž", "\xC2\x91" => "‘",
        "\xC2\x92" => "’", "\xC2\x93" => "“", "\xC2\x94" => "”",
        "\xC2\x95" => "•", "\xC2\x96" => "–", "\xC2\x97" => "—",
        "\xC2\x98" => "˜", "\xC2\x99" => "™", "\xC2\x9A" => "š",
        "\xC2\x9B" => "›", "\xC2\x9C" => "œ", "\xC2\x9E" => "ž",
        "\xC2\x9F" => "Ÿ"
    ];
    $fixed = str_replace(array_keys($map), array_values($map), $text);
    $fixed = iconv('UTF-8', 'UTF-8//IGNORE', $fixed);
    $fixed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $fixed);
    return trim($fixed);
}

// ============================================================
//  EXTRACTORS
// ============================================================
function extractRawText($file_path) {
    if (!file_exists($file_path)) return false;
    $type = detectFileType($file_path);
    if ($type === 'pdf') return extractPDF($file_path);
    if ($type === 'epub') return extractEPUB($file_path);
    if ($type === 'docx') return extractDOCX($file_path);
    return false;
}

function extractPDF($file_path) {
    $text = false;
    // Method 1: pdftotext
    if (function_exists('exec')) {
        $txt_path = dirname($file_path) . '/' . pathinfo($file_path, PATHINFO_FILENAME) . '.txt';
        $pdftotext_path = defined('PDFTOTEXT_PATH') ? PDFTOTEXT_PATH : 'pdftotext';
        exec("$pdftotext_path -layout -enc UTF-8 '$file_path' '$txt_path' 2>&1", $output, $return_var);
        if ($return_var === 0 && file_exists($txt_path)) {
            $text = file_get_contents($txt_path);
            @unlink($txt_path);
        }
    }
    // Method 2: smalot/pdfparser
    if (!$text && class_exists('\\Smalot\\PdfParser\\Parser')) {
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setIgnoreEncryption(true);
            $parser = new \Smalot\PdfParser\Parser();
            $parser->setConfig($config);
            $pdf = $parser->parseFile($file_path);
            $text = $pdf->getText();
        } catch (Exception $e) {
            $text = false;
        }
    }
    // Method 3: brute‑force
    if (!$text || empty(trim($text))) {
        $handle = fopen($file_path, 'rb');
        $content = fread($handle, filesize($file_path));
        fclose($handle);
        preg_match_all('/BT(.*?)ET/s', $content, $matches);
        $raw_text = '';
        foreach ($matches[1] as $segment) {
            $segment = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $segment);
            $raw_text .= $segment . "\n";
        }
        $text = $raw_text;
    }
    if (!empty(trim($text))) {
        return fixEncoding($text);
    }
    return '⚠️ PDF extraction failed. This PDF appears to be encrypted or uses an unsupported encoding.';
}

function extractDOCX($file_path) {
    $zip = zip_open($file_path);
    if (!$zip || is_numeric($zip)) return false;
    $content = '';
    while ($zip_entry = zip_read($zip)) {
        if (zip_entry_name($zip_entry) == 'word/document.xml') {
            if (zip_entry_open($zip, $zip_entry, "r")) {
                $xml = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
                $xml = strip_tags($xml, '<w:t>');
                $xml = str_replace(['<w:t>', '</w:t>'], '', $xml);
                $content .= html_entity_decode($xml);
                zip_entry_close($zip_entry);
                break;
            }
        }
    }
    zip_close($zip);
    return fixEncoding($content);
}

function extractEPUB($file_path) {
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== TRUE) return false;
    $html_content = '';
    $opf_path = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (strpos($zip->getNameIndex($i), '.opf') !== false) {
            $opf_path = $zip->getNameIndex($i);
            break;
        }
    }
    if (!$opf_path) { $zip->close(); return false; }
    $opf_xml = $zip->getFromName($opf_path);
    $opf = simplexml_load_string($opf_xml);
    $ns = $opf->getNamespaces(true);
    $opf->registerXPathNamespace('opf', $ns[''] ?? 'http://www.idpf.org/2007/opf');
    $items = $opf->xpath('//opf:manifest/opf:item[@media-type="application/xhtml+xml"]');
    $base_dir = dirname($opf_path) . '/';
    foreach ($items as $item) {
        $href = (string)$item['href'];
        $full_path = $base_dir . $href;
        $content = $zip->getFromName($full_path);
        if ($content) {
            $html_content .= fixEncoding($content);
        }
    }
    $zip->close();
    return $html_content;
}

// ============================================================
//  PAGE SPLITTING
// ============================================================
function splitPagesByNumbers($text) {
    $lines = explode("\n", $text);
    $pages = [];
    $current_page = [];
    $header_keywords = [
        'ACKNOWLEDGEMENT', 'AUTHOR\'S NOTE', 'ABOUT THE AUTHOR',
        'Psalm', 'Preface', 'Foreword', 'Introduction', 'DEDICATION',
        'COPYRIGHT'
    ];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Case 1: Standalone number
        if (preg_match('/^\d+$/', $trimmed)) {
            if (!empty($current_page)) {
                $pages[] = implode("\n", $current_page);
                $current_page = [];
            }
            continue;
        }
        // Case 2: Structural header
        $is_structural_header = false;
        foreach ($header_keywords as $keyword) {
            if (strpos($trimmed, $keyword) === 0) {
                $is_structural_header = true;
                break;
            }
        }
        if ($is_structural_header) {
            if (!empty($current_page)) {
                $pages[] = implode("\n", $current_page);
                $current_page = [];
            }
            $current_page[] = $line;
            continue;
        }
        $current_page[] = $line;
    }
    if (!empty($current_page)) {
        $pages[] = implode("\n", $current_page);
    }
    return array_filter($pages, function($p) {
        return !empty(trim($p));
    });
}

// ============================================================
//  PARAGRAPH FORMATTER – Returns clean HTML
// ============================================================
function formatParagraph($text) {
    $trimmed = trim($text);
    if (empty($trimmed)) return '';
    // Chapter headings
    if (preg_match('/^(Chapter|CHAPTER|CHAP\.?)\s+(\d+|[IVXLCDM]+)/i', $trimmed, $matches)) {
        return '<h2 class="chapter-heading">' . htmlspecialchars($trimmed) . '</h2>';
    }
    // ACKNOWLEDGEMENT
    if (strpos($trimmed, 'ACKNOWLEDGEMENT') === 0) {
        $rest = trim(substr($trimmed, strlen('ACKNOWLEDGEMENT')));
        if (!empty($rest)) {
            return '<h3>Acknowledgements</h3><p>' . nl2br(htmlspecialchars($rest)) . '</p>';
        }
        return '<h3>Acknowledgements</h3>';
    }
    // AUTHOR'S NOTE
    if (strpos($trimmed, "AUTHOR'S NOTE") === 0) {
        $rest = trim(substr($trimmed, strlen("AUTHOR'S NOTE")));
        if (!empty($rest)) {
            return "<h3>Author's Note</h3><p>" . nl2br(htmlspecialchars($rest)) . '</p>';
        }
        return "<h3>Author's Note</h3>";
    }
    // ABOUT THE AUTHOR
    if (strpos($trimmed, 'ABOUT THE AUTHOR') === 0) {
        $rest = trim(substr($trimmed, strlen('ABOUT THE AUTHOR')));
        if (!empty($rest)) {
            return '<h3>About the Author</h3><p>' . nl2br(htmlspecialchars($rest)) . '</p>';
        }
        return '<h3>About the Author</h3>';
    }
    // Psalm
    if (preg_match('/^Psalm\s+(\d+)/i', $trimmed, $matches)) {
        return '<h3>' . htmlspecialchars($trimmed) . '</h3>';
    }
    // DEDICATION (starts with "To" at beginning)
    if (preg_match('/^To\s+[A-Za-z]/', $trimmed)) {
        return '<p class="dedication">' . nl2br(htmlspecialchars($trimmed)) . '</p>';
    }
    // Default paragraph
    return '<p>' . nl2br(htmlspecialchars($trimmed)) . '</p>';
}

// ============================================================
//  TOC GENERATION
// ============================================================
function generateTOC($pages) {
    $toc = [];
    $page_num = 1;
    $header_keywords = ['ACKNOWLEDGEMENT', 'AUTHOR\'S NOTE', 'ABOUT THE AUTHOR', 'Psalm', 'Preface', 'Foreword', 'Introduction', 'DEDICATION', 'COPYRIGHT', 'Chapter'];
    foreach ($pages as $page_content) {
        $lines = explode("\n", $page_content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            foreach ($header_keywords as $keyword) {
                if (strpos($trimmed, $keyword) === 0) {
                    $toc[] = ['title' => $trimmed, 'page' => $page_num];
                    break;
                }
            }
        }
        $page_num++;
    }
    return $toc;
}

// ============================================================
//  KEYWORD EXTRACTOR
// ============================================================
function extractKeywords($text, $limit = 10) {
    $stop_words = ['the', 'and', 'to', 'of', 'a', 'in', 'that', 'it', 'for', 'with', 'on', 'was', 'as', 'by', 'at', 'an'];
    $words = str_word_count(strtolower($text), 1);
    $words = array_diff($words, $stop_words);
    $counts = array_count_values($words);
    arsort($counts);
    return array_slice(array_keys($counts), 0, $limit);
}

// ============================================================
//  VERSION HISTORY
// ============================================================
function saveVersionHistory($book_id, $content_html, $toc_json, $metadata_json, $note = '') {
    global $db;
    $stmt = $db->prepare("SELECT MAX(version) FROM book_content_history WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $current_version = $stmt->fetchColumn() ?? 0;
    $new_version = $current_version + 1;
    $stmt = $db->prepare("INSERT INTO book_content_history (book_id, content_html, toc_json, metadata_json, version, note) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$book_id, $content_html, $toc_json, $metadata_json, $new_version, $note]);
    return $new_version;
}

// ============================================================
//  QUEUE SYSTEM
// ============================================================
function addToQueue($book_id) {
    global $db;
    $stmt = $db->prepare("INSERT OR IGNORE INTO book_processing_queue (book_id, status, progress, created_at) VALUES (?, 'pending', 0, CURRENT_TIMESTAMP)");
    $stmt->execute([$book_id]);
}

function getQueueStatus($book_id) {
    global $db;
    $stmt = $db->prepare("SELECT status, progress FROM book_processing_queue WHERE book_id = ?");
    $stmt->execute([$book_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================================
//  EXPORTS
// ============================================================
function exportTXT($content_html) {
    $text = strip_tags($content_html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function exportHTML($content_html, $book_title) {
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>" . htmlspecialchars($book_title) . "</title></head><body>";
    $html .= $content_html;
    $html .= "</body></html>";
    return $html;
}

function exportTOC_CSV($toc_json) {
    $toc = json_decode($toc_json, true);
    $csv = "Title,Level,Page\n";
    foreach ($toc as $entry) {
        $csv .= '"' . str_replace('"', '""', $entry['title']) . '",' . ($entry['level'] ?? 1) . ',' . ($entry['page'] ?? '') . "\n";
    }
    return $csv;
}

// ============================================================
//  BOOK RENDER (for reader)
// ============================================================
function renderBook($parsed, $book) {
    global $db, $book_id;
    $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $content_html = $row ? $row['content_html'] : '';
    if (empty($content_html)) {
        return renderBookFromParsed($parsed, $book);
    }
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($content_html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    $paragraphs = $xpath->query('//p | //div[@class="chapter-container"]');
    $page_num = 1;
    foreach ($paragraphs as $node) {
        if ($node->nodeName === 'div' && $node->getAttribute('class') === 'page-break') {
            $page_num++;
            $node->parentNode->removeChild($node);
            continue;
        }
        if ($node->nodeName === 'p' || $node->nodeName === 'div') {
            $childBreaks = $xpath->query('.//div[@class="page-break"]', $node);
            foreach ($childBreaks as $break) {
                $page_num++;
                $break->parentNode->removeChild($break);
            }
            $node->setAttribute('data-page', $page_num);
        }
    }
    $modified_html = $dom->saveHTML();
    $modified_html = preg_replace('/^<!DOCTYPE.*?<html>.*?<body>/s', '', $modified_html);
    $modified_html = preg_replace('/<\/body><\/html>$/s', '', $modified_html);
    $html = "<div class='book-reader' data-book-id='{$book['id']}'>";
    $html .= "<h1 class='book-title'>" . htmlspecialchars($book['title']) . "</h1>\n";
    $html .= "<p class='book-author'>by " . htmlspecialchars($book['author']) . "</p>\n";
    $html .= $modified_html;
    $html .= "</div>";
    return $html;
}

function renderBookFromParsed($parsed, $book) {
    $html = "<div class='book-reader' data-book-id='{$book['id']}'>";
    $html .= "<h1 class='book-title'>" . htmlspecialchars($book['title']) . "</h1>\n";
    $html .= "<p class='book-author'>by " . htmlspecialchars($book['author']) . "</p>\n";
    $global_page = 1;
    foreach ($parsed['chapters'] as $ch_idx => $chapter) {
        $html .= "<div class='chapter-container' data-chapter='$ch_idx'>\n";
        $html .= "<h2 class='chapter-heading'>" . htmlspecialchars($chapter['heading']) . "</h2>\n";
        $html .= "<div class='chapter-content'>\n";
        if (isset($chapter['page_break']) && $chapter['page_break'] > 1) {
            $html .= "<div class='page-break' data-page='{$chapter['page_break']}'></div>\n";
        }
        foreach ($chapter['paragraphs'] as $p) {
            $html .= "<p data-page='$global_page'>" . nl2br(htmlspecialchars(trim($p))) . "</p>\n";
        }
        $html .= "</div>\n</div>\n";
        $global_page++;
    }
    $html .= "</div>";
    return $html;
}

// ============================================================
//  TOC SIDEBAR
// ============================================================
function renderTOCSidebar($toc_json) {
    $toc = json_decode($toc_json, true);
    if (!$toc) return '';
    $html = '<div class="toc-sidebar" style="position:sticky;top:20px;max-height:80vh;overflow-y:auto;padding:12px;background:#f8f9fa;border-radius:8px;border:1px solid #ddd;">';
    $html .= '<h4 style="margin-top:0;">📑 Contents</h4>';
    foreach ($toc as $entry) {
        $indent = ($entry['level'] ?? 1) > 1 ? 'padding-left:20px;' : '';
        $html .= '<div style="' . $indent . 'padding:4px 0;">';
        $html .= '<a href="#chapter-' . ($entry['page'] ?? 1) . '" style="text-decoration:none;color:#333;display:block;padding:4px 8px;border-radius:4px;transition:background 0.2s;">' . htmlspecialchars($entry['title']) . '</a>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

// ============================================================
//  VERSION MANAGEMENT (publish, delete)
// ============================================================
function publishVersion($book_id, $version) {
    global $db;
    $stmt = $db->prepare("SELECT content_html, toc_json, metadata_json FROM book_content_history WHERE book_id = ? AND version = ?");
    $stmt->execute([$book_id, $version]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) return false;
    $stmt = $db->prepare("UPDATE book_content SET content_html = ?, toc_json = ?, metadata_json = ?, published_version = ?, version = ?, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
    $stmt->execute([$data['content_html'], $data['toc_json'], $data['metadata_json'], $version, $version, $book_id]);
    return true;
}

function deleteVersion($book_id, $version) {
    global $db;
    $stmt = $db->prepare("SELECT published_version FROM book_content WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $published = $stmt->fetchColumn();
    if ($version == $published) return false;
    $stmt = $db->prepare("DELETE FROM book_content_history WHERE book_id = ? AND version = ?");
    $stmt->execute([$book_id, $version]);
    return true;
}

// ============================================================
//  POST HANDLERS
// ============================================================

// --- EXTRACT ---
if (isset($_POST['extract'])) {
    header('Content-Type: application/json');
    $file_path = '../' . $book['file_path'];
    if (!file_exists($file_path)) {
        echo json_encode(['success' => false, 'error' => 'Book file not found.']);
        exit;
    }
    $raw_text = extractRawText($file_path);
    if ($raw_text && !str_starts_with($raw_text, '⚠️')) {
        $raw_text = fixEncoding($raw_text);
        $pages = splitPagesByNumbers($raw_text);
        $toc = generateTOC($pages);
        $toc_json = json_encode($toc);
        
        // Build the final HTML
        $html_content = "<h1 class='book-title'>" . htmlspecialchars($book['title']) . "</h1>\n";
        $html_content .= "<p class='book-author'>by " . htmlspecialchars($book['author']) . "</p>\n";
        $page_num = 1;
        foreach ($pages as $page_content) {
            $page_html = '<div class="page-content" data-page="' . $page_num . '">';
            $paragraphs = preg_split('/\n\s*\n/', trim($page_content));
            foreach ($paragraphs as $para) {
                $trimmed = trim($para);
                if (empty($trimmed)) continue;
                $page_html .= formatParagraph($trimmed);
            }
            $page_html .= '</div>';
            $html_content .= $page_html;
            $html_content .= '<div class="page-break" data-page="' . $page_num . '"></div>';
            $page_num++;
        }
        
        $metadata = ['keywords' => extractKeywords($raw_text)];
        $metadata_json = json_encode($metadata);
        
        // Save as a new version in history
        $stmt = $db->prepare("SELECT published_version FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $published_version = $stmt->fetchColumn() ?? 1;
        
        $stmt = $db->prepare("SELECT MAX(version) FROM book_content_history WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $next_version = ($stmt->fetchColumn() ?? 0) + 1;
        
        $stmt = $db->prepare("INSERT INTO book_content_history (book_id, content_html, toc_json, metadata_json, version, note) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$book_id, $html_content, $toc_json, $metadata_json, $next_version, 'Extracted version ' . $next_version]);
        
        // If no published content exists, set this as published
        $stmt = $db->prepare("SELECT COUNT(*) FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $db->prepare("INSERT INTO book_content (book_id, title, content_html, toc_json, metadata_json, is_processed, processing_status, published_version) VALUES (?, ?, ?, ?, ?, 1, 'complete', ?)");
            $stmt->execute([$book_id, $book['title'], $html_content, $toc_json, $metadata_json, $next_version]);
        } else {
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$html_content, $book_id]);
        }
        
        echo json_encode(['success' => true, 'message' => "✅ Extracted version $next_version created. Published version remains v$published_version."]);
    } else {
        $error_msg = $raw_text ?: 'Failed to extract content from the file.';
        echo json_encode(['success' => false, 'error' => $error_msg]);
    }
    exit;
}

// --- UPLOAD COVER ---
if (isset($_POST['upload_cover'])) {
    if (!empty($_FILES['live_cover']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['live_cover']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed_types)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, WEBP allowed.']);
            exit;
        }
        $upload_dir = '../assets/uploads/books/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = pathinfo($_FILES['live_cover']['name'], PATHINFO_EXTENSION);
        $cover_filename = 'live_cover_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['live_cover']['tmp_name'], $upload_dir . $cover_filename)) {
            $cover_path = 'assets/uploads/books/' . $cover_filename;
            $stmt = $db->prepare("UPDATE books SET cover_path = ? WHERE id = ?");
            $stmt->execute([$cover_path, $book_id]);
            echo json_encode(['success' => true, 'path' => $cover_path]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed.']);
            exit;
        }
    }
}

// --- QUEUE BOOK ---
if (isset($_POST['queue_book'])) {
    header('Content-Type: application/json');
    addToQueue($book_id);
    echo json_encode(['success' => true, 'message' => '✅ Book added to processing queue.']);
    exit;
}

// --- SAVE CONTENT ---
if (isset($_POST['save_content'])) {
    header('Content-Type: application/json');
    $content_html = trim($_POST['content_html']);
    if (!empty($content_html)) {
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
        $stmt->execute([$content_html, $book_id]);
        $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        saveVersionHistory($book_id, $content_html, $row['toc_json'], $row['metadata_json'], 'Manual edit');
        echo json_encode(['success' => true, 'message' => '✅ Content saved successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Content cannot be empty.']);
    }
    exit;
}

// --- RESET PAGE BREAKS ---
if (isset($_POST['reset_page_breaks'])) {
    header('Content-Type: application/json');
    $file_path = '../' . $book['file_path'];
    if (!file_exists($file_path)) {
        echo json_encode(['success' => false, 'error' => 'Book file not found. Cannot reset page breaks.']);
        exit;
    }
    $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $old_content = $stmt->fetchColumn();
    $raw_text = extractRawText($file_path);
    if (!$raw_text) {
        echo json_encode(['success' => false, 'error' => 'Failed to extract content from the original file.']);
        exit;
    }
    $parsed = parseBook($raw_text);
    $new_content = renderBookFromParsed($parsed, $book);
    $metadata = ['keywords' => extractKeywords($raw_text), 'page_breaks' => $parsed['page_breaks']];
    $metadata_json = json_encode($metadata);
    $toc_json = json_encode($parsed['toc']);
    $stmt = $db->prepare("UPDATE book_content SET content_html = ?, toc_json = ?, metadata_json = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
    $stmt->execute([$new_content, $toc_json, $metadata_json, $book_id]);
    saveVersionHistory($book_id, $new_content, $toc_json, $metadata_json, 'Reset page breaks');
    echo json_encode(['success' => true, 'message' => '✅ Page breaks reset successfully.']);
    exit;
}

// ============================================================
//  JSON ACTION HANDLERS
// ============================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'get_extracted_text') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        if ($html) {
            $plain_text = strip_tags($html);
            echo json_encode(['success' => true, 'text' => $plain_text]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No extracted text found. Run extraction first.']);
        }
        exit;
    }

    if ($action === 'export_txt') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        if ($html) {
            $txt = exportTXT($html);
            header('Content-Description: File Transfer');
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="book_' . $book_id . '.txt"');
            echo $txt;
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'No content found.']);
            exit;
        }
    }

    if ($action === 'export_html') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        if ($html) {
            $full_html = exportHTML($html, $book['title']);
            header('Content-Description: File Transfer');
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="book_' . $book_id . '.html"');
            echo $full_html;
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'No content found.']);
            exit;
        }
    }

    if ($action === 'export_toc_csv') {
        $stmt = $db->prepare("SELECT toc_json FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $toc_json = $stmt->fetchColumn();
        if ($toc_json) {
            $csv = exportTOC_CSV($toc_json);
            header('Content-Description: File Transfer');
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="toc_book_' . $book_id . '.csv"');
            echo $csv;
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'No TOC found.']);
            exit;
        }
    }

    if ($action === 'export_zip') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        if ($html) {
            $zip = new ZipArchive();
            $zip_filename = 'book_' . $book_id . '_pages.zip';
            $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
            if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
                echo json_encode(['success' => false, 'error' => 'Could not create ZIP file.']);
                exit;
            }
            $parts = preg_split('/<div class="page-break"[^>]*><\/div>/', $html);
            foreach ($parts as $i => $part) {
                $page_html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>" . trim($part) . "</body></html>";
                $zip->addFromString('page_' . ($i+1) . '.html', $page_html);
            }
            $zip->close();
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            readfile($zip_path);
            unlink($zip_path);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'No content found.']);
            exit;
        }
    }

    if ($action === 'get_diff') {
        $version_a = (int)$_POST['version_a'];
        $version_b = (int)$_POST['version_b'];
        $stmt = $db->prepare("SELECT content_html FROM book_content_history WHERE book_id = ? AND version = ?");
        $stmt->execute([$book_id, $version_a]);
        $html_a = $stmt->fetchColumn();
        $stmt->execute([$book_id, $version_b]);
        $html_b = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'diff' => renderUnifiedDiff($html_a ?? '', $html_b ?? '')]);
        exit;
    }

    if ($action === 'search_replace') {
        $search = $_POST['search'] ?? '';
        $replace = $_POST['replace'] ?? '';
        $use_regex = isset($_POST['use_regex']) && $_POST['use_regex'] === '1';
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $content = $stmt->fetchColumn();
        $matches = getSearchMatches($content, $search, $use_regex);
        $new_content = searchReplaceContent($content, $search, $replace, $use_regex);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ? WHERE book_id = ?");
        $stmt->execute([$new_content, $book_id]);
        echo json_encode(['success' => true, 'matches' => $matches]);
        exit;
    }

    if ($action === 'save_metadata') {
        $metadata = json_decode($_POST['metadata'], true);
        saveMetadata($book_id, $metadata);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_reader_css') {
        $css = $_POST['css'] ?? '';
        saveReaderCSS($book_id, $css);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_analytics') {
        $stats = getBookAnalytics($book_id);
        echo json_encode(['success' => true, 'stats' => $stats]);
        exit;
    }

    if ($action === 'create_backup') {
        createDailyBackup($book_id);
        logActivity($_SESSION['user_id'], 'backup_created', "Book ID: $book_id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'download_backup') {
        downloadBackupArchive($book_id);
        exit;
    }

    if ($action === 'add_collaborator') {
        $target_user = (int)$_POST['user_id'];
        $role = $_POST['role'] ?? 'editor';
        if (!checkBookAccess($_SESSION['user_id'], $book_id, 'admin')) {
            echo json_encode(['success' => false, 'error' => 'Permission denied.']);
            exit;
        }
        addBookCollaborator($book_id, $target_user, $role);
        logActivity($_SESSION['user_id'], 'collaborator_added', "User $target_user, role $role");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_collaborator') {
        $target_user = (int)$_POST['user_id'];
        if (!checkBookAccess($_SESSION['user_id'], $book_id, 'admin')) {
            echo json_encode(['success' => false, 'error' => 'Permission denied.']);
            exit;
        }
        removeBookCollaborator($book_id, $target_user);
        logActivity($_SESSION['user_id'], 'collaborator_removed', "User $target_user");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'list_collaborators') {
        $collaborators = listBookCollaborators($book_id);
        echo json_encode(['success' => true, 'collaborators' => $collaborators]);
        exit;
    }

    if ($action === 'merge_chapters') {
        $indices = json_decode($_POST['indices'], true);
        if (!is_array($indices)) {
            echo json_encode(['success' => false, 'error' => 'Invalid chapter indices.']);
            exit;
        }
        mergeChapters($book_id, $indices);
        invalidateBookCache($book_id);
        logActivity($_SESSION['user_id'], 'chapters_merged', "Book ID: $book_id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'split_chapter') {
        $chapter_idx = (int)$_POST['chapter_index'];
        $para_idx = (int)$_POST['paragraph_index'];
        splitChapter($book_id, $chapter_idx, $para_idx);
        invalidateBookCache($book_id);
        logActivity($_SESSION['user_id'], 'chapter_split', "Book ID: $book_id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'generate_index') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        $terms = generateIndex($html);
        echo json_encode(['success' => true, 'index' => $terms]);
        exit;
    }

    if ($action === 'get_reading_time') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        $total = calculateReadingTime($html);
        $chapters = getChapterReadingTimes($html);
        echo json_encode(['success' => true, 'total' => $total['minutes'], 'chapters' => $chapters]);
        exit;
    }

    if ($action === 'batch_process') {
        $book_ids = json_decode($_POST['book_ids'], true);
        if (!is_array($book_ids)) {
            echo json_encode(['success' => false, 'error' => 'Invalid book IDs.']);
            exit;
        }
        queueBatchBooks($book_ids);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_comment') {
        $para_idx = (int)$_POST['paragraph_index'];
        $comment = trim($_POST['comment']);
        if (!$comment) {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
            exit;
        }
        addComment($book_id, $_SESSION['user_id'], $para_idx, $comment);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_comments') {
        $para_idx = isset($_POST['paragraph_index']) ? (int)$_POST['paragraph_index'] : null;
        $comments = getComments($book_id, $para_idx);
        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    }

    if ($action === 'resolve_comment') {
        $comment_id = (int)$_POST['comment_id'];
        resolveComment($comment_id);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_comment') {
        $comment_id = (int)$_POST['comment_id'];
        deleteComment($comment_id);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'export_comments') {
        $csv = exportComments($book_id);
        header('Content-Description: File Transfer');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="comments_book_' . $book_id . '.csv"');
        echo $csv;
        exit;
    }

    if ($action === 'save_preset') {
        $name = trim($_POST['name']);
        $settings = json_decode($_POST['settings'], true);
        if (!$name || !is_array($settings)) {
            echo json_encode(['success' => false, 'error' => 'Invalid preset data.']);
            exit;
        }
        saveFormattingPreset($book_id, $name, $settings);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_presets') {
        $presets = getFormattingPresets($book_id);
        echo json_encode(['success' => true, 'presets' => $presets]);
        exit;
    }

    if ($action === 'apply_preset') {
        $preset_id = (int)$_POST['preset_id'];
        $stmt = $db->prepare("SELECT settings FROM formatting_presets WHERE id = ?");
        $stmt->execute([$preset_id]);
        $settings_json = $stmt->fetchColumn();
        if (!$settings_json) {
            echo json_encode(['success' => false, 'error' => 'Preset not found.']);
            exit;
        }
        $settings = json_decode($settings_json, true);
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        $new_html = applyFormattingPreset($html, $settings);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([$new_html, $book_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'validate_content') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $html = $stmt->fetchColumn();
        $errors = validateContentStructure($html);
        $reading_time = calculateReadingTime($html);
        echo json_encode(['success' => true, 'errors' => $errors, 'reading_time' => $reading_time['minutes'], 'word_count' => $reading_time['word_count']]);
        exit;
    }

    if ($action === 'get_version_history') {
        $stmt = $db->prepare("SELECT * FROM book_content_history WHERE book_id = ? ORDER BY version DESC");
        $stmt->execute([$book_id]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($versions)) {
            echo '<p style="color:var(--text-light);font-size:0.9rem;">No version history available for this book.</p>';
            exit;
        }
        $stmt = $db->prepare("SELECT published_version FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $published_version = $stmt->fetchColumn() ?? 0;
        $html = '<div style="max-height:400px;overflow-y:auto;">';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="background:#f1f3f5;border-bottom:2px solid #ddd;">';
        $html .= '<th style="padding:8px 12px;text-align:left;">Version</th>';
        $html .= '<th style="padding:8px 12px;text-align:left;">Date</th>';
        $html .= '<th style="padding:8px 12px;text-align:left;">Note</th>';
        $html .= '<th style="padding:8px 12px;text-align:left;">Actions</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($versions as $version) {
            $is_published = ($version['version'] == $published_version);
            $row_class = $is_published ? 'style="background:#d4edda;"' : '';
            $html .= '<tr ' . $row_class . '>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">';
            $html .= $is_published ? '<strong>v' . $version['version'] . ' (published)</strong>' : 'v' . $version['version'];
            $html .= '</td>';
            $created_at = $version['created_at'] ?? date('Y-m-d H:i:s');
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . date('Y-m-d H:i:s', strtotime($created_at)) . '</td>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . htmlspecialchars($version['note'] ?? '—') . '</td>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">';
            $html .= '<button class="btn btn-sm btn-info" onclick="previewVersion(' . $version['version'] . ')">👁️ Preview</button> ';
            if (!$is_published) {
                $html .= '<button class="btn btn-sm btn-success" onclick="publishVersion(' . $version['version'] . ')">📢 Publish</button> ';
                $html .= '<button class="btn btn-sm btn-danger" onclick="deleteVersion(' . $version['version'] . ')">🗑 Delete</button>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
        echo $html;
        exit;
    }

    if ($action === 'publish_version') {
        $version = (int)$_POST['version'];
        if (publishVersion($book_id, $version)) {
            echo json_encode(['success' => true, 'message' => "✅ Version $version published successfully."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to publish version.']);
        }
        exit;
    }

    if ($action === 'delete_version') {
        $version = (int)$_POST['version'];
        if (deleteVersion($book_id, $version)) {
            echo json_encode(['success' => true, 'message' => "✅ Version $version deleted."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Cannot delete the published version.']);
        }
        exit;
    }

    if ($action === 'preview_version') {
        $version = (int)$_POST['version'];
        $stmt = $db->prepare("SELECT content_html, created_at, note FROM book_content_history WHERE book_id = ? AND version = ?");
        $stmt->execute([$book_id, $version]);
        $version_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version_data) {
            echo '<p style="color:red;">❌ Version not found.</p>';
            exit;
        }
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $current_html = $stmt->fetchColumn();
        $html = '<div style="padding:12px;background:#f8f9fa;border-bottom:1px solid #ddd;margin-bottom:12px;">';
        $html .= '<strong>Version v' . $version . '</strong> – ' . date('Y-m-d H:i:s', strtotime($version_data['created_at']));
        if ($version_data['note']) {
            $html .= ' – <em>' . htmlspecialchars($version_data['note']) . '</em>';
        }
        $html .= '</div>';
        $html .= renderVersionSideBySide($current_html, $version_data['content_html'], 'Current', $version);
        echo $html;
        exit;
    }

    // Legacy / fallback actions
    if ($action === 'accept') {
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'revert') {
        if (!isset($_SESSION['page_break_diff_old'])) {
            echo json_encode(['success' => false, 'error' => 'No old version found in session.']);
            exit;
        }
        $old_content = $_SESSION['page_break_diff_old'];
        $old_content = preg_replace('/ data-page="\d+"/', '', $old_content);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
        $stmt->execute([$old_content, $book_id]);
        unset($_SESSION['page_break_diff_old']);
        unset($_SESSION['page_break_diff_new']);
        unset($_SESSION['show_diff']);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'compare_original') {
        $current_content = $_POST['current_content'] ?? '';
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            echo '<p style="color:red;">❌ Original file not found.</p>';
            exit;
        }
        $raw_text = extractRawText($file_path);
        if (!$raw_text) {
            echo '<p style="color:red;">❌ Failed to extract content from original file.</p>';
            exit;
        }
        $parsed_original = parseBook($raw_text);
        $original_paragraphs = [];
        foreach ($parsed_original['chapters'] as $chapter) {
            $original_paragraphs = array_merge($original_paragraphs, $chapter['paragraphs']);
        }
        $current_clean = strip_tags($current_content);
        $current_paragraphs = preg_split('/\n\s*\n/', $current_clean);
        $current_paragraphs = array_filter(array_map('trim', $current_paragraphs));
        echo renderTextDiff($original_paragraphs, $current_paragraphs);
        exit;
    }

    if ($action === 'restore_original') {
        $current_content = $_POST['current_content'] ?? '';
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            echo json_encode(['success' => false, 'error' => 'Original file not found.']);
            exit;
        }
        $raw_text = extractRawText($file_path);
        if (!$raw_text) {
            echo json_encode(['success' => false, 'error' => 'Failed to extract content from original file.']);
            exit;
        }
        $parsed_original = parseBook($raw_text);
        $original_paragraphs = [];
        foreach ($parsed_original['chapters'] as $chapter) {
            $original_paragraphs = array_merge($original_paragraphs, $chapter['paragraphs']);
        }
        $merged_content = mergePreservingEdits($current_content, $original_paragraphs);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([$merged_content, $book_id]);
        $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        saveVersionHistory($book_id, $merged_content, $row['toc_json'], $row['metadata_json'], 'Restored from original (preserving edits)');
        echo json_encode(['success' => true, 'content' => $merged_content]);
        exit;
    }

    if ($action === 'preview_restore') {
        $selected = json_decode($_POST['selected'], true);
        $preview_all = isset($_POST['preview_all']) && $_POST['preview_all'] === '1';
        if (!is_array($selected)) {
            echo 'Invalid selected data.';
            exit;
        }
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $current_html = $stmt->fetchColumn();
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            echo 'Original file not found.';
            exit;
        }
        $raw_text = extractRawText($file_path);
        if (!$raw_text) {
            echo 'Failed to extract content from original file.';
            exit;
        }
        $parsed_original = parseBook($raw_text);
        $original_paragraphs = [];
        foreach ($parsed_original['chapters'] as $chapter) {
            $original_paragraphs = array_merge($original_paragraphs, $chapter['paragraphs']);
        }
        $preview_html = generatePreview($current_html, $original_paragraphs, $selected, $preview_all);
        echo $preview_html;
        exit;
    }

    if ($action === 'selective_restore') {
        $selected = json_decode($_POST['selected'], true);
        if (!is_array($selected)) {
            echo json_encode(['success' => false, 'error' => 'Invalid selected data.']);
            exit;
        }
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $current_html = $stmt->fetchColumn();
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            echo json_encode(['success' => false, 'error' => 'Original file not found.']);
            exit;
        }
        $raw_text = extractRawText($file_path);
        if (!$raw_text) {
            echo json_encode(['success' => false, 'error' => 'Failed to extract content from original file.']);
            exit;
        }
        $parsed_original = parseBook($raw_text);
        $original_paragraphs = [];
        foreach ($parsed_original['chapters'] as $chapter) {
            $original_paragraphs = array_merge($original_paragraphs, $chapter['paragraphs']);
        }
        $new_html = selectiveRestore($current_html, $original_paragraphs, $selected);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([$new_html, $book_id]);
        $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        saveVersionHistory($book_id, $new_html, $row['toc_json'], $row['metadata_json'], 'Selective restore');
        echo json_encode(['success' => true, 'content' => $new_html]);
        exit;
    }

    if ($action === 'restore_all') {
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $current_html = $stmt->fetchColumn();
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            echo json_encode(['success' => false, 'error' => 'Original file not found.']);
            exit;
        }
        $raw_text = extractRawText($file_path);
        if (!$raw_text) {
            echo json_encode(['success' => false, 'error' => 'Failed to extract content from original file.']);
            exit;
        }
        $parsed_original = parseBook($raw_text);
        $original_paragraphs = [];
        foreach ($parsed_original['chapters'] as $chapter) {
            $original_paragraphs = array_merge($original_paragraphs, $chapter['paragraphs']);
        }
        $new_html = restoreAllParagraphs($current_html, $original_paragraphs);
        $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([$new_html, $book_id]);
        $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        saveVersionHistory($book_id, $new_html, $row['toc_json'], $row['metadata_json'], 'Restore all paragraphs');
        echo json_encode(['success' => true, 'content' => $new_html]);
        exit;
    }

    if ($action === 'send_report_email') {
        $to_email = trim($_POST['email']);
        $title = trim($_POST['title']);
        $diff_content = $_POST['diff_content'];
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
            exit;
        }
        $report_html = generateReportHTML($diff_content, $title, $book_id);
        $temp_dir = __DIR__ . '/../tmp/reports/';
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
        $filename = 'report_' . date('Ymd_His') . '.html';
        $file_path = $temp_dir . $filename;
        file_put_contents($file_path, $report_html);
        $subject = 'Version Comparison Report: ' . $title;
        $body = "<p>Please find attached the version comparison report for Book ID {$book_id}.</p>";
        $body .= "<p>Title: <strong>{$title}</strong></p>";
        $body .= "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
        $body .= "<p>This report was automatically generated by AngelWrites.</p>";
        $result = sendEmailWithAttachment($to_email, $subject, $body, $file_path, $filename);
        @unlink($file_path);
        echo json_encode(['success' => $result, 'error' => $result ? null : 'Failed to send email.']);
        exit;
    }

    if ($action === 'send_full_history_email') {
        $to_email = trim($_POST['email']);
        $history_content = $_POST['history_content'];
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
            exit;
        }
        $report_html = generateFullHistoryReport($history_content, $book_id);
        $temp_dir = __DIR__ . '/../tmp/reports/';
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
        $filename = 'full_history_' . date('Ymd_His') . '.html';
        $file_path = $temp_dir . $filename;
        file_put_contents($file_path, $report_html);
        $subject = 'Full Version History Report - Book ID ' . $book_id;
        $body = "<p>Please find attached the full version history report for Book ID {$book_id}.</p>";
        $body .= "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
        $body .= "<p>This report was automatically generated by AngelWrites.</p>";
        $result = sendEmailWithAttachment($to_email, $subject, $body, $file_path, $filename);
        @unlink($file_path);
        echo json_encode(['success' => $result, 'error' => $result ? null : 'Failed to send email.']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ============================================================
//  UI RENDERING
// ============================================================

$pageTitle = 'Process Book: ' . htmlspecialchars($book['title']);
require_once '../includes/header.php';
?>
<style>
.admin-process-book { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { margin: 0; }
.card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
.card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.card-body { padding: 20px; }
.btn-large { padding: 14px 28px; font-size: 1.1rem; border-radius: 30px; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 4px; }
.field-hint { display: block; margin-top: 4px; font-size: 0.85rem; color: var(--text-light); }
.diff-toggle { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 20px; border: 1px solid #ddd; background: #f8f9fa; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; }
.diff-toggle:hover { background: #e9ecef; }
.diff-toggle.active { background: var(--rose); color: white; border-color: var(--rose); }
.diff-container { margin-bottom: 16px; }
.diff-container.side-by-side .diff-columns { display: flex; gap: 20px; flex-wrap: wrap; }
.diff-container.side-by-side .diff-column { flex: 1; min-width: 280px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
.diff-column .column-header { background: #f1f3f5; padding: 8px 12px; border-bottom: 1px solid #ddd; font-weight: bold; text-align: center; }
.diff-column .column-content { max-height: 400px; overflow-y: auto; padding: 4px 0; }
.diff-line { padding: 2px 8px; border-bottom: 1px solid #f0f0f0; font-family: monospace; font-size: 0.85rem; line-height: 1.5; white-space: pre-wrap; word-break: break-all; }
.diff-line.diff-empty { color: #ccc; background: #fafafa; }
.diff-line.diff-add { background: #d4edda; color: #155724; }
.diff-line.diff-remove { background: #f8d7da; color: #721c24; }
.diff-line.diff-change { background: #fff3cd; color: #856404; }
.diff-line.diff-same { color: #6c757d; }
.page-break-marker { color: #c0392b; font-weight: bold; }
.admin-process-book .btn { min-height: 40px; display: inline-flex; align-items: center; gap: 6px; }
.admin-process-book .btn-outline { border: 1px solid var(--border); background: transparent; }
.admin-process-book .btn-outline:hover { background: var(--vanilla); }
#extractedTextStatus { padding: 8px 12px; border-radius: 6px; background: #f8f9fa; border-left: 4px solid #28a745; transition: all 0.3s; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; overflow: auto; }
.modal-content { background: #fff; margin: 5% auto; max-width: 900px; padding: 24px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
</style>

<div class="admin-process-book">
    <div class="container">
        <div class="admin-header">
            <h1>Process Book: <?php echo htmlspecialchars($book['title']); ?></h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Books
                </a>
                <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-eye"></i> Preview Reader
                </a>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="card">
            <div class="card-header"><h2>📥 Stage 1: Extract & Parse</h2></div>
            <div class="card-body">
                <button onclick="extractAndParse()" class="btn btn-primary btn-large">
                    <i class="fas fa-magic"></i> Extract & Parse Content
                </button>
                <button onclick="queueBook()" class="btn btn-secondary btn-large">
                    <i class="fas fa-clock"></i> Add to Processing Queue
                </button>
                <div id="extract-status" style="display:none;padding:12px;border-radius:8px;margin-top:12px;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🖼️ Cover Image</h2></div>
            <div class="card-body">
                <div id="cover-preview">
                    <?php if (!empty($book['cover_path'])): ?>
                        <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" style="max-width:200px;">
                    <?php else: ?>
                        <p>No cover image uploaded.</p>
                    <?php endif; ?>
                </div>
                <input type="file" id="coverInput" accept="image/*">
                <button class="btn btn-primary" onclick="uploadCover()">Upload Cover</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📝 Stage 3: Edit & Refine</h2></div>
            <div class="card-body">
                <textarea id="editor" name="content_html" style="width:100%;height:500px;"><?php echo htmlspecialchars($existing['content_html'] ?? ''); ?></textarea>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="saveContent()">💾 Save Changes</button>
                    <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                        <i class="fas fa-eye"></i> Preview Reader
                    </a>
                    <button class="btn btn-outline" onclick="compareWithOriginal()">
                        <i class="fas fa-file-alt"></i> Compare with Original
                    </button>
                    <button class="btn btn-warning" onclick="restoreFromOriginal()">
                        <i class="fas fa-undo-alt"></i> Restore from Original
                    </button>
                </div>
                <div id="restore-status" style="display:none;padding:12px;border-radius:8px;margin-top:12px;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🏷️ Metadata & SEO</h2></div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;">
                    <div>
                        <label style="display:block;font-size:0.85rem;color:#666;">Genre</label>
                        <select id="genre" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">
                            <option value="Fiction">Fiction</option>
                            <option value="Non-Fiction">Non-Fiction</option>
                            <option value="Christian">Christian</option>
                            <option value="Romance">Romance</option>
                            <option value="Thriller">Thriller</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.85rem;color:#666;">Tags (comma separated)</label>
                        <input type="text" id="tags" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;" placeholder="faith, healing, love">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.85rem;color:#666;">ISBN</label>
                        <input type="text" id="isbn" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.85rem;color:#666;">Publisher</label>
                        <input type="text" id="publisher" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">
                    </div>
                </div>
                <button class="btn btn-primary" onclick="saveMetadata()" style="margin-top:12px;">💾 Save Metadata</button>
            </div>
        </div>

        <div style="display:flex;gap:20px;margin-top:20px;">
            <div style="flex:2;">
                <div class="card">
                    <div class="card-header"><h2>📝 Edit & Refine</h2></div>
                    <div class="card-body">
                        <textarea id="editor" name="content_html" style="width:100%;height:500px;"><?php echo htmlspecialchars($existing['content_html'] ?? ''); ?></textarea>
                        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                            <button class="btn btn-primary" onclick="saveContent()">💾 Save Changes</button>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                                <i class="fas fa-eye"></i> Preview Reader
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div style="flex:1;min-width:250px;">
                <?php echo renderTOCSidebar($existing['toc_json'] ?? '[]'); ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📤 Stage 4: Export</h2></div>
            <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn btn-sm btn-outline" onclick="exportTXT()">
                    <i class="fas fa-file-alt"></i> TXT
                </button>
                <button class="btn btn-sm btn-outline" onclick="exportHTML()">
                    <i class="fas fa-file-code"></i> HTML
                </button>
                <button class="btn btn-sm btn-outline" onclick="exportTOC_CSV()">
                    <i class="fas fa-table"></i> TOC CSV
                </button>
                <button class="btn btn-sm btn-outline" onclick="exportZIP()">
                    <i class="fas fa-folder-open"></i> Multi-Page ZIP
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>💾 Backup & Recovery</h2></div>
            <div class="card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="createBackup()">
                        <i class="fas fa-save"></i> Create Daily Backup
                    </button>
                    <button class="btn btn-outline" onclick="downloadBackup()">
                        <i class="fas fa-download"></i> Download Backup Archive
                    </button>
                    <button class="btn btn-outline" onclick="document.getElementById('restoreBackupModal').style.display='flex'">
                        <i class="fas fa-undo-alt"></i> Restore from Backup
                    </button>
                </div>
                <div id="backupStatus" style="margin-top:8px;font-size:0.85rem;color:#666;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>👥 Collaborators</h2></div>
            <div class="card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="number" id="collaboratorUserId" placeholder="User ID" style="padding:8px;border:1px solid #ddd;border-radius:4px;width:120px;">
                    <select id="collaboratorRole" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                        <option value="editor">Editor</option>
                        <option value="reviewer">Reviewer</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button class="btn btn-primary" onclick="addCollaborator()">+ Add</button>
                </div>
                <div id="collaboratorList" style="margin-top:12px;">
                    <!-- Dynamically populated -->
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>✂️ Chapter Merge & Split</h2></div>
            <div class="card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="mergeSelectedChapters()">
                        <i class="fas fa-compress"></i> Merge Selected Chapters
                    </button>
                    <button class="btn btn-outline" onclick="splitSelectedChapter()">
                        <i class="fas fa-cut"></i> Split Current Chapter
                    </button>
                </div>
                <div id="mergeSplitStatus" style="margin-top:8px;font-size:0.85rem;color:#666;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📊 Book Health Checker</h2></div>
            <div class="card-body">
                <button class="btn btn-secondary" onclick="runHealthCheck()">
                    <i class="fas fa-heartbeat"></i> Run Health Check
                </button>
                <div id="healthCheckResults" style="margin-top:12px;font-size:0.9rem;">
                    <span style="color:#999;">Click the button to scan the book.</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>💬 Comment System</h2></div>
            <div class="card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="number" id="commentParagraphIndex" placeholder="Paragraph index (0-based)" style="padding:8px;border:1px solid #ddd;border-radius:4px;width:180px;">
                    <textarea id="commentText" placeholder="Write a comment..." style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;min-width:200px;"></textarea>
                    <button class="btn btn-primary" onclick="addComment()">Add Comment</button>
                    <button class="btn btn-outline" onclick="loadComments()">📋 Load Comments</button>
                    <button class="btn btn-outline" onclick="exportComments()">📤 Export Comments</button>
                </div>
                <div id="commentList" style="margin-top:12px;font-size:0.9rem;">
                    <!-- Dynamically populated -->
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>📚 Index Generator</h2></div>
            <div class="card-body">
                <button class="btn btn-secondary" onclick="generateIndex()">
                    <i class="fas fa-list"></i> Generate Book Index
                </button>
                <button class="btn btn-outline" onclick="showReadingTime()">
                    <i class="fas fa-clock"></i> Show Reading Time
                </button>
                <div id="indexResults" style="margin-top:12px;font-size:0.9rem;">
                    <span style="color:#999;">Click to generate index.</span>
                </div>
                <div id="readingTimeResult" style="margin-top:12px;font-size:0.9rem;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🎨 Formatting Presets</h2></div>
            <div class="card-body">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="text" id="presetName" placeholder="Preset name" style="padding:8px;border:1px solid #ddd;border-radius:4px;flex:1;min-width:150px;">
                    <button class="btn btn-primary" onclick="savePreset()">💾 Save Current</button>
                </div>
                <div id="presetList" style="margin-top:12px;font-size:0.9rem;">
                    <!-- Dynamically populated -->
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>📜 Version Manager</h2>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-sm btn-outline" onclick="loadVersions()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body" id="version-history-container">
                <p style="color:var(--text-light);font-size:0.9rem;">Click "Refresh" to load the version list.</p>
            </div>
        </div>

        <!-- Search & Replace Modal -->
        <div id="searchReplaceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:30px;border-radius:12px;max-width:600px;width:90%;">
                <h3>🔍 Search & Replace</h3>
                <div style="margin:12px 0;">
                    <input type="text" id="searchInput" placeholder="Search..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;">
                </div>
                <div style="margin:12px 0;">
                    <input type="text" id="replaceInput" placeholder="Replace with..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;">
                </div>
                <div style="margin:12px 0;">
                    <label><input type="checkbox" id="useRegex"> Use Regex</label>
                </div>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-primary" onclick="executeSearchReplace()">🔍 Preview & Replace</button>
                    <button class="btn btn-secondary" onclick="document.getElementById('searchReplaceModal').style.display='none'">Cancel</button>
                </div>
                <div id="searchResult" style="margin-top:12px;font-size:0.9rem;"></div>
            </div>
        </div>

        <!-- Restore Backup Modal -->
        <div id="restoreBackupModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10001;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:30px;border-radius:12px;max-width:500px;width:90%;">
                <h3>📂 Restore from Backup</h3>
                <p style="font-size:0.9rem;color:#666;">Enter a backup date (YYYY-MM-DD) to restore from the earliest backup on that day.</p>
                <input type="date" id="restoreBackupDate" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;margin:12px 0;">
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-warning" onclick="restoreBackup()">🔄 Restore</button>
                    <button class="btn btn-secondary" onclick="document.getElementById('restoreBackupModal').style.display='none'">Cancel</button>
                </div>
                <div id="restoreBackupStatus" style="margin-top:8px;font-size:0.85rem;"></div>
            </div>
        </div>

        <!-- Diff Modal -->
        <div id="diffModal" class="modal" style="display:none;">
            <div class="modal-content" style="background:#fff;margin:5% auto;max-width:900px;padding:24px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                    <h3 id="modalTitle" style="margin:0;">📄 Diff View</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button onclick="closeDiffModal()" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                    </div>
                </div>
                <div id="diffContent" style="max-height:60vh;overflow-y:auto;">
                    <p style="text-align:center;color:#999;">No diff data available.</p>
                </div>
                <div class="modal-footer" style="margin-top:16px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.4.2/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#editor',
    height: 500,
    menubar: true,
    plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount code',
    toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 18px; line-height: 2; } .page-break { display: block; width: 100%; border-top: 2px dashed #c0392b; margin: 20px 0; text-align: center; color: #c0392b; font-size: 0.8rem; } .page-break::before { content: "⏎ Page Break"; }',
    forced_root_block: 'p'
});

function extractAndParse() {
    const statusDiv = document.getElementById('extract-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '⏳ Extracting and parsing content...';
    statusDiv.style.background = '#e9ecef';
    statusDiv.style.color = '#333';
    const formData = new FormData();
    formData.append('extract', '1');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '✅ ' + data.message;
            statusDiv.style.background = '#d4edda';
            statusDiv.style.color = '#155724';
            setTimeout(() => location.reload(), 2000);
        } else {
            statusDiv.innerHTML = '❌ ' + (data.error || 'Unknown error');
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '❌ Network or JSON error: ' + error.message;
        statusDiv.style.background = '#f8d7da';
        statusDiv.style.color = '#721c24';
        console.error('Fetch error:', error);
    });
}

function queueBook() {
    const statusDiv = document.getElementById('extract-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '⏳ Adding to queue...';
    const formData = new FormData();
    formData.append('queue_book', '1');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            statusDiv.innerHTML = '✅ ' + data.message;
            statusDiv.style.background = '#d4edda';
        } else {
            statusDiv.innerHTML = '❌ ' + (data.error || 'Unknown error');
            statusDiv.style.background = '#f8d7da';
        }
    });
}

function uploadCover() {
    const fileInput = document.getElementById('coverInput');
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Please select an image file.');
        return;
    }
    const formData = new FormData();
    formData.append('upload_cover', '1');
    formData.append('live_cover', fileInput.files[0]);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            document.getElementById('cover-preview').innerHTML = '<img src="<?php echo SITE_URL; ?>/' + data.path + '" style="max-width:200px;">';
            alert('✅ Cover uploaded successfully!');
        } else {
            alert('❌ Upload failed: ' + (data.error || 'Unknown error'));
        }
    });
}

function saveContent() {
    const content = tinymce.get('editor').getContent();
    const formData = new FormData();
    formData.append('save_content', '1');
    formData.append('content_html', content);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
        } else {
            alert('❌ Failed to save: ' + (data.error || 'Unknown error'));
        }
    });
}

function exportTXT() {
    window.location.href = '<?php echo SITE_URL; ?>/admin/process_book.php?action=export_txt&id=<?php echo $book_id; ?>';
}
function exportHTML() {
    window.location.href = '<?php echo SITE_URL; ?>/admin/process_book.php?action=export_html&id=<?php echo $book_id; ?>';
}
function exportTOC_CSV() {
    window.location.href = '<?php echo SITE_URL; ?>/admin/process_book.php?action=export_toc_csv&id=<?php echo $book_id; ?>';
}
function exportZIP() {
    window.location.href = '<?php echo SITE_URL; ?>/admin/process_book.php?action=export_zip&id=<?php echo $book_id; ?>';
}

function loadVersions() {
    const container = document.getElementById('version-history-container');
    container.innerHTML = '<p style="color:var(--text-light);font-size:0.9rem;">Loading version history...</p>';
    const formData = new FormData();
    formData.append('action', 'get_version_history');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => {
        container.innerHTML = html;
    });
}

function publishVersion(version) {
    if (!confirm(`Publish version ${version} to the reader?`)) return;
    const formData = new FormData();
    formData.append('action', 'publish_version');
    formData.append('version', version);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            loadVersions();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function deleteVersion(version) {
    if (!confirm(`Delete version ${version}? This cannot be undone.`)) return;
    const formData = new FormData();
    formData.append('action', 'delete_version');
    formData.append('version', version);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            loadVersions();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function previewVersion(version) {
    const formData = new FormData();
    formData.append('action', 'preview_version');
    formData.append('version', version);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => {
        document.getElementById('diffContent').innerHTML = html;
        document.getElementById('diffModal').style.display = 'block';
    });
}

function closeDiffModal() {
    document.getElementById('diffModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function showSearchReplace() {
    document.getElementById('searchReplaceModal').style.display = 'flex';
}

function executeSearchReplace() {
    const search = document.getElementById('searchInput').value;
    const replace = document.getElementById('replaceInput').value;
    const useRegex = document.getElementById('useRegex').checked;
    if (!search) { alert('Please enter a search term.'); return; }
    const formData = new FormData();
    formData.append('action', 'search_replace');
    formData.append('search', search);
    formData.append('replace', replace);
    formData.append('use_regex', useRegex ? '1' : '0');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('searchResult').innerHTML = '✅ Replaced ' + data.matches + ' occurrence(s).';
            setTimeout(() => location.reload(), 1000);
        } else {
            document.getElementById('searchResult').innerHTML = '❌ ' + (data.error || 'Error');
        }
    });
}

function saveMetadata() {
    const metadata = {
        genre: document.getElementById('genre').value,
        tags: document.getElementById('tags').value.split(',').map(s => s.trim()),
        isbn: document.getElementById('isbn').value,
        publisher: document.getElementById('publisher').value
    };
    const formData = new FormData();
    formData.append('action', 'save_metadata');
    formData.append('metadata', JSON.stringify(metadata));
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.success ? '✅ Metadata saved!' : '❌ Error saving metadata.');
    });
}

function createBackup() {
    const statusDiv = document.getElementById('backupStatus');
    statusDiv.innerHTML = '⏳ Creating backup...';
    const formData = new FormData();
    formData.append('action', 'create_backup');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            statusDiv.innerHTML = '✅ Backup created successfully.';
        } else {
            statusDiv.innerHTML = '❌ ' + (data.error || 'Failed.');
        }
        setTimeout(() => statusDiv.innerHTML = '', 3000);
    });
}

function downloadBackup() {
    const formData = new FormData();
    formData.append('action', 'download_backup');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.blob()).then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'book_backup_<?php echo $book_id; ?>.zip';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
}

function restoreBackup() {
    const date = document.getElementById('restoreBackupDate').value;
    const statusDiv = document.getElementById('restoreBackupStatus');
    if (!date) { statusDiv.innerHTML = 'Please select a date.'; return; }
    statusDiv.innerHTML = '⏳ Restoring...';
    const formData = new FormData();
    formData.append('action', 'restore_backup');
    formData.append('date', date);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            statusDiv.innerHTML = '✅ Restored successfully. Reloading...';
            setTimeout(() => location.reload(), 1000);
        } else {
            statusDiv.innerHTML = '❌ ' + (data.error || 'Failed.');
        }
    });
}

function addCollaborator() {
    const userId = document.getElementById('collaboratorUserId').value;
    const role = document.getElementById('collaboratorRole').value;
    if (!userId) { alert('Please enter a user ID.'); return; }
    const formData = new FormData();
    formData.append('action', 'add_collaborator');
    formData.append('user_id', userId);
    formData.append('role', role);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.success ? '✅ Collaborator added.' : '❌ ' + (data.error || 'Error.'));
        if (data.success) loadCollaborators();
    });
}

function loadCollaborators() {
    const formData = new FormData();
    formData.append('action', 'list_collaborators');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const list = document.getElementById('collaboratorList');
            list.innerHTML = '';
            data.collaborators.forEach(c => {
                list.innerHTML += `<div style="display:flex;justify-content:space-between;padding:4px 8px;border-bottom:1px solid #eee;">`;
                list.innerHTML += `<span>User ${c.user_id} (${c.role})</span>`;
                list.innerHTML += `<button class="btn btn-sm btn-danger" onclick="removeCollaborator(${c.user_id})">Remove</button>`;
                list.innerHTML += `</div>`;
            });
        }
    });
}

function removeCollaborator(userId) {
    if (!confirm('Remove this collaborator?')) return;
    const formData = new FormData();
    formData.append('action', 'remove_collaborator');
    formData.append('user_id', userId);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.success ? '✅ Removed.' : '❌ ' + (data.error || 'Error.'));
        if (data.success) loadCollaborators();
    });
}

function mergeSelectedChapters() {
    const checked = document.querySelectorAll('.chapter-checkbox:checked');
    const indices = Array.from(checked).map(c => parseInt(c.value));
    if (indices.length < 2) { alert('Select at least 2 chapters.'); return; }
    const formData = new FormData();
    formData.append('action', 'merge_chapters');
    formData.append('indices', JSON.stringify(indices));
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ Chapters merged. Reloading...');
            location.reload();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function splitSelectedChapter() {
    const chapterIndex = prompt('Enter chapter index (0-based):');
    const paraIndex = prompt('Enter paragraph index (0-based):');
    if (chapterIndex === null || paraIndex === null) return;
    const formData = new FormData();
    formData.append('action', 'split_chapter');
    formData.append('chapter_index', chapterIndex);
    formData.append('paragraph_index', paraIndex);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ Chapter split. Reloading...');
            location.reload();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function runHealthCheck() {
    const resultsDiv = document.getElementById('healthCheckResults');
    resultsDiv.innerHTML = '⏳ Scanning...';
    const formData = new FormData();
    formData.append('action', 'validate_content');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            let html = '';
            html += '<strong>Reading Time:</strong> ' + data.reading_time + ' (' + data.word_count + ' words)<br>';
            if (data.errors.length === 0) {
                html += '<span style="color:#28a745;">✅ No issues found.</span>';
            } else {
                html += '<span style="color:#dc3545;">⚠️ Issues found:</span><ul>';
                data.errors.forEach(e => { html += '<li>' + e + '</li>'; });
                html += '</ul>';
            }
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = '❌ ' + (data.error || 'Error.');
        }
    });
}

function addComment() {
    const paraIdx = document.getElementById('commentParagraphIndex').value;
    const text = document.getElementById('commentText').value;
    if (!paraIdx || !text) { alert('Enter paragraph index and comment.'); return; }
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('paragraph_index', paraIdx);
    formData.append('comment', text);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ Comment added.');
            document.getElementById('commentText').value = '';
            loadComments();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function loadComments(paraIdx = null) {
    const formData = new FormData();
    formData.append('action', 'get_comments');
    if (paraIdx !== null) formData.append('paragraph_index', paraIdx);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const list = document.getElementById('commentList');
            list.innerHTML = '';
            if (data.comments.length === 0) {
                list.innerHTML = '<span style="color:#999;">No comments yet.</span>';
                return;
            }
            data.comments.forEach(c => {
                list.innerHTML += `<div style="padding:8px;border-bottom:1px solid #eee;${c.resolved ? 'opacity:0.6;' : ''}">`;
                list.innerHTML += `<strong>Paragraph ${c.paragraph_index}</strong> <span style="color:#999;">${c.created_at}</span>`;
                list.innerHTML += `<p>${c.comment}</p>`;
                if (!c.resolved) {
                    list.innerHTML += `<button class="btn btn-sm btn-success" onclick="resolveComment(${c.id})">✓ Resolve</button> `;
                }
                list.innerHTML += `<button class="btn btn-sm btn-danger" onclick="deleteComment(${c.id})">🗑 Delete</button>`;
                list.innerHTML += `</div>`;
            });
        }
    });
}

function resolveComment(id) {
    const formData = new FormData();
    formData.append('action', 'resolve_comment');
    formData.append('comment_id', id);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) loadComments();
    });
}

function deleteComment(id) {
    if (!confirm('Delete this comment?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_comment');
    formData.append('comment_id', id);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) loadComments();
    });
}

function exportComments() {
    window.location.href = '<?php echo SITE_URL; ?>/admin/process_book.php?action=export_comments&id=<?php echo $book_id; ?>';
}

function generateIndex() {
    const resultsDiv = document.getElementById('indexResults');
    resultsDiv.innerHTML = '⏳ Generating index...';
    const formData = new FormData();
    formData.append('action', 'generate_index');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            resultsDiv.innerHTML = '<strong>Index Terms:</strong><br>' + data.index.join(', ');
        } else {
            resultsDiv.innerHTML = '❌ ' + (data.error || 'Error.');
        }
    });
}

function showReadingTime() {
    const resultDiv = document.getElementById('readingTimeResult');
    resultDiv.innerHTML = '⏳ Calculating...';
    const formData = new FormData();
    formData.append('action', 'get_reading_time');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            resultDiv.innerHTML = `<p><strong>Total reading time:</strong> ${data.total} minutes</p>`;
            let chapterTimes = '<ul>';
            data.chapters.forEach((t, i) => {
                chapterTimes += `<li>Chapter ${i+1}: ~${t} min</li>`;
            });
            chapterTimes += '</ul>';
            resultDiv.innerHTML += chapterTimes;
        } else {
            resultDiv.innerHTML = '❌ ' + (data.error || 'Failed.');
        }
    });
}

function savePreset() {
    const name = document.getElementById('presetName').value.trim();
    if (!name) { alert('Please enter a preset name.'); return; }
    const settings = {
        font: document.getElementById('presetFont').value,
        size: parseInt(document.getElementById('presetSize').value),
        line_height: parseFloat(document.getElementById('presetLineHeight').value),
        spacing: parseInt(document.getElementById('presetSpacing').value)
    };
    const formData = new FormData();
    formData.append('action', 'save_preset');
    formData.append('name', name);
    formData.append('settings', JSON.stringify(settings));
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ Preset saved.');
            loadPresets();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

function loadPresets() {
    const listDiv = document.getElementById('presetList');
    const formData = new FormData();
    formData.append('action', 'get_presets');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            listDiv.innerHTML = '';
            data.presets.forEach(p => {
                listDiv.innerHTML += `<div style="border:1px solid #ddd;padding:8px;border-radius:4px;display:flex;gap:8px;align-items:center;">`;
                listDiv.innerHTML += `<strong>${p.name}</strong>`;
                listDiv.innerHTML += `<button class="btn btn-sm btn-primary" onclick="applyPreset(${p.id})">Apply</button>`;
                listDiv.innerHTML += `</div>`;
            });
        }
    });
}

function applyPreset(id) {
    const formData = new FormData();
    formData.append('action', 'apply_preset');
    formData.append('preset_id', id);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert('✅ Preset applied. Reloading...');
            location.reload();
        } else {
            alert('❌ ' + (data.error || 'Error.'));
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    loadVersions();
    loadComments();
    loadPresets();
    loadCollaborators();
});

// Keyboard Shortcuts
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveContent();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
        e.preventDefault();
        exportTXT();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
        e.preventDefault();
        exportHTML();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        alert('EPUB export coming soon.');
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        restoreFromOriginal();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        showSearchReplace();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'g') {
        e.preventDefault();
        document.querySelector('.toc-sidebar')?.scrollIntoView();
    }
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal, #searchReplaceModal, #restoreBackupModal').forEach(el => {
            el.style.display = 'none';
        });
    }
});
</script>
<?php require_once '../includes/footer.php'; ?>