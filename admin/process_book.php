<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'process';
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
//  HELPER FUNCTIONS
// ============================================================

function detectFileType($file_path) {
    if (!file_exists($file_path)) return false;
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($file_path);
        if (strpos($mime, 'pdf') !== false) return 'pdf';
        if (strpos($mime, 'word') !== false || strpos($mime, 'document') !== false) return 'docx';
        if (strpos($mime, 'epub') !== false) return 'epub';
    }
    return strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
}

function fixEncoding($text) {
    $detected = mb_detect_encoding($text, 'UTF-8, Windows-1252, ISO-8859-1, ASCII', true);
    if ($detected !== 'UTF-8' && $detected !== 'ASCII') {
        $text = mb_convert_encoding($text, 'UTF-8', $detected);
    }
    $replacements = [
        'â€œ' => '“', 'â€' => '”', 'â€™' => '’',
        'â€˜' => '‘', 'â€"’' => '—', 'â€”' => '—',
        'â€“' => '–', 'â€¦' => '…', 'â€¢' => '•',
        'â€¹' => '‹', 'â€º' => '›', 'â‚¬' => '€',
        'â„¢' => '™', 'â€¡' => '‡', 'â€°' => '‰',
        'â€š' => '‚', 'â€ž' => '„'
    ];
    return str_replace(array_keys($replacements), array_values($replacements), trim($text));
}

function extractRawText($file_path) {
    if (!file_exists($file_path)) return false;
    $type = detectFileType($file_path);
    if ($type === 'pdf') {
        if (function_exists('exec') && exec('which pdftotext') !== '') {
            $txt_path = dirname($file_path) . '/' . pathinfo($file_path, PATHINFO_FILENAME) . '.txt';
            exec("pdftotext -layout -enc UTF-8 '$file_path' '$txt_path'", $output, $return_var);
            if ($return_var === 0 && file_exists($txt_path)) {
                $text = file_get_contents($txt_path);
                return fixEncoding($text);
            }
        }
        return extractPDF($file_path);
    }
    if ($type === 'epub') return extractEPUB($file_path);
    if ($type === 'docx') return extractDOCX($file_path);
    return false;
}

function extractPDF($file_path) {
    require_once LIB_PATH . 'pdfparser-master/src/Smalot/PdfParser/Element.php';
    // ... include all PDF parser classes as before ...
    $parser = new \Smalot\PdfParser\Parser();
    try {
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        if (empty(trim($text))) {
            return '⚠️ This PDF appears to be a scan (no extractable text). For InfinityFree, we cannot run OCR. If you upgrade to a VPS, we can use Tesseract.';
        }
        return fixEncoding($text);
    } catch (Exception $e) {
        return false;
    }
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
//  PARAGRAPH & PAGE BREAK DETECTION
// ============================================================

function splitParagraphs($text) {
    $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($paragraphs) > 1) {
        return array_map('trim', $paragraphs);
    }
    $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $text);
    $paragraphs = [];
    $current = '';
    foreach ($sentences as $s) {
        if (strlen($current) + strlen($s) < 200 && !empty($current)) {
            $current .= ' ' . $s;
        } else {
            if (!empty($current)) $paragraphs[] = trim($current);
            $current = $s;
        }
    }
    if (!empty($current)) $paragraphs[] = trim($current);
    return $paragraphs;
}

function detectPageBreaks($lines) {
    $page_breaks = [];
    $line_count = count($lines);
    for ($i = 0; $i < $line_count; $i++) {
        $line = trim($lines[$i]);
        if (preg_match('/^\d{1,5}$/', $line) && strlen($line) < 6) {
            $prev = $i > 0 ? trim($lines[$i-1]) : '';
            $next = $i < $line_count-1 ? trim($lines[$i+1]) : '';
            if (preg_match('/[.!?]$/', $prev) || preg_match('/^[A-Z]/', $next)) {
                $page_breaks[] = $i;
            }
        }
        if (strpos($lines[$i], "\f") !== false) {
            $page_breaks[] = $i;
        }
    }
    return $page_breaks;
}

function parseBook($raw_text) {
    $lines = explode("\n", $raw_text);
    $chapters = [];
    $toc = [];
    $current_chapter = null;
    $page_break_lines = detectPageBreaks($lines);

    foreach ($lines as $line_number => $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;

        if (preg_match('/^(Chapter|CHAPTER|CHAP\.?|Part|PART|Book|BOOK|Section|SECTION)\s+(\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten)/i', $trimmed)) {
            if ($current_chapter) {
                $chapters[] = $current_chapter;
            }
            $current_chapter = [
                'heading' => $trimmed,
                'paragraphs' => [],
                'page_breaks' => []
            ];
            $toc[] = ['title' => $trimmed, 'level' => 1];
            continue;
        }

        if ($current_chapter) {
            if (in_array($line_number, $page_break_lines)) {
                $current_chapter['page_breaks'][] = count($current_chapter['paragraphs']);
                continue;
            }
            if (empty($current_chapter['paragraphs']) || strlen($trimmed) < 50) {
                if (!empty($current_chapter['paragraphs'])) {
                    $last_idx = count($current_chapter['paragraphs']) - 1;
                    $current_chapter['paragraphs'][$last_idx] .= ' ' . $trimmed;
                } else {
                    $current_chapter['paragraphs'][] = $trimmed;
                }
            } else {
                $current_chapter['paragraphs'][] = $trimmed;
            }
        }
    }
    if ($current_chapter) {
        $chapters[] = $current_chapter;
    }

    foreach ($chapters as &$chapter) {
        $final_paragraphs = [];
        foreach ($chapter['paragraphs'] as $p) {
            if (strlen($p) > 500) {
                $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $p);
                foreach ($sentences as $s) {
                    if (trim($s)) $final_paragraphs[] = trim($s);
                }
            } else {
                $final_paragraphs[] = $p;
            }
        }
        $chapter['paragraphs'] = $final_paragraphs;
        unset($chapter['content']);
    }

    return ['chapters' => $chapters, 'toc' => $toc, 'page_breaks' => $page_break_lines];
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

        $break_positions = $chapter['page_breaks'] ?? [];
        $p_idx = 0;
        foreach ($chapter['paragraphs'] as $p) {
            if (in_array($p_idx, $break_positions)) {
                $global_page++;
                $html .= "<div class='page-break' data-page='$global_page'></div>\n";
            }
            $html .= "<p data-page='$global_page'>" . nl2br(htmlspecialchars(trim($p))) . "</p>\n";
            $p_idx++;
        }
        $html .= "</div>\n</div>\n";
        $global_page++;
    }
    $html .= "</div>";
    return $html;
}

function renderBook($parsed, $book) {
    // Use stored content_html if available
    global $db, $book_id;
    $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $content_html = $row ? $row['content_html'] : '';

    if (empty($content_html)) {
        return renderBookFromParsed($parsed, $book);
    }

    // Inject data-page attributes based on <div class="page-break">
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($content_html, 'HTML-ENTITIES', 'UTF-8'));
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

function extractKeywords($text, $limit = 10) {
    $stop_words = ['the', 'and', 'to', 'of', 'a', 'in', 'that', 'it', 'for', 'with', 'on', 'was', 'as', 'by', 'at', 'an'];
    $words = str_word_count(strtolower($text), 1);
    $words = array_diff($words, $stop_words);
    $counts = array_count_values($words);
    arsort($counts);
    return array_slice(array_keys($counts), 0, $limit);
}

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

function addToQueue($book_id) {
    global $db;
    $stmt = $db->prepare("INSERT OR IGNORE INTO book_processing_queue (book_id) VALUES (?)");
    $stmt->execute([$book_id]);
}
// ============================================================
//  DIFF, PREVIEW & RESTORE FUNCTIONS
// ============================================================

function renderUnifiedDiff($old_html, $new_html, $usePatterns = false) {
    if (empty($old_html)) $old_html = '';
    if (empty($new_html)) $new_html = '';

    $old_clean = preg_replace('/ data-page="\d+"/', '', $old_html);
    $new_clean = preg_replace('/ data-page="\d+"/', '', $new_html);

    $old_lines = preg_split('/\r\n|\r|\n/', $old_clean);
    $new_lines = preg_split('/\r\n|\r|\n/', $new_clean);

    foreach ($old_lines as $i => $line) {
        if (strpos($line, 'class="page-break"') !== false) {
            $old_lines[$i] = '[PAGE_BREAK] ' . $line;
        }
    }
    foreach ($new_lines as $i => $line) {
        if (strpos($line, 'class="page-break"') !== false) {
            $new_lines[$i] = '[PAGE_BREAK] ' . $line;
        }
    }

    $i = 0; $j = 0;
    $diff = [];

    while ($i < count($old_lines) || $j < count($new_lines)) {
        $old_line = $i < count($old_lines) ? $old_lines[$i] : null;
        $new_line = $j < count($new_lines) ? $new_lines[$j] : null;

        if ($old_line === null) {
            $diff[] = ['type' => 'add', 'line' => $new_line];
            $j++;
        } elseif ($new_line === null) {
            $diff[] = ['type' => 'remove', 'line' => $old_line];
            $i++;
        } elseif ($old_line === $new_line) {
            $diff[] = ['type' => 'same', 'line' => $old_line];
            $i++; $j++;
        } else {
            $found = false;
            for ($k = 1; $k <= 5 && $i+$k < count($old_lines); $k++) {
                if ($old_lines[$i+$k] === $new_line) {
                    for ($m = 0; $m < $k; $m++) {
                        $diff[] = ['type' => 'remove', 'line' => $old_lines[$i+$m]];
                    }
                    $i += $k;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                for ($k = 1; $k <= 5 && $j+$k < count($new_lines); $k++) {
                    if ($new_lines[$j+$k] === $old_line) {
                        for ($m = 0; $m < $k; $m++) {
                            $diff[] = ['type' => 'add', 'line' => $new_lines[$j+$m]];
                        }
                        $j += $k;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $diff[] = ['type' => 'change', 'old' => $old_line, 'new' => $new_line];
                $i++; $j++;
            }
        }
    }

    $html = '<div class="diff-container unified">';
    $patternClass = $usePatterns ? ' pattern' : '';
    foreach ($diff as $item) {
        $class = '';
        $content = '';
        switch ($item['type']) {
            case 'same':
                $class = 'diff-same' . $patternClass;
                $content = htmlspecialchars($item['line']);
                break;
            case 'add':
                $class = 'diff-add' . $patternClass;
                $content = '+ ' . htmlspecialchars($item['line']);
                break;
            case 'remove':
                $class = 'diff-remove' . $patternClass;
                $content = '- ' . htmlspecialchars($item['line']);
                break;
            case 'change':
                $class = 'diff-change' . $patternClass;
                $content = '- ' . htmlspecialchars($item['old']) . "\n+ " . htmlspecialchars($item['new']);
                break;
        }
        $html .= '<div class="diff-line ' . $class . '">' . $content . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function renderSideBySideDiff($old_html, $new_html, $usePatterns = false) {
    if (empty($old_html)) $old_html = '';
    if (empty($new_html)) $new_html = '';

    $old_clean = preg_replace('/ data-page="\d+"/', '', $old_html);
    $new_clean = preg_replace('/ data-page="\d+"/', '', $new_html);

    $old_lines = preg_split('/\r\n|\r|\n/', $old_clean);
    $new_lines = preg_split('/\r\n|\r|\n/', $new_clean);

    foreach ($old_lines as $i => $line) {
        if (strpos($line, 'class="page-break"') !== false) {
            $old_lines[$i] = '<span class="page-break-marker">📄</span> ' . $line;
        }
    }
    foreach ($new_lines as $i => $line) {
        if (strpos($line, 'class="page-break"') !== false) {
            $new_lines[$i] = '<span class="page-break-marker">📄</span> ' . $line;
        }
    }

    $max_lines = max(count($old_lines), count($new_lines));
    $aligned_old = array_pad($old_lines, $max_lines, '');
    $aligned_new = array_pad($new_lines, $max_lines, '');

    $html = '<div class="diff-container side-by-side">';
    $html .= '<div class="diff-columns">';
    $html .= '<div class="diff-column old-column"><div class="column-header">Before</div><div class="column-content">';
    $patternClass = $usePatterns ? ' pattern' : '';
    foreach ($aligned_old as $line) {
        $class = empty($line) ? 'diff-empty' . $patternClass : 'diff-same' . $patternClass;
        if (!empty($line) && strpos($line, '📄') !== false) {
            $class = 'diff-remove' . $patternClass;
        }
        $html .= '<div class="diff-line ' . $class . '">' . htmlspecialchars($line) . '</div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="diff-column new-column"><div class="column-header">After</div><div class="column-content">';
    foreach ($aligned_new as $line) {
        $class = empty($line) ? 'diff-empty' . $patternClass : 'diff-same' . $patternClass;
        if (!empty($line) && strpos($line, '📄') !== false) {
            $class = 'diff-add' . $patternClass;
        }
        $html .= '<div class="diff-line ' . $class . '">' . htmlspecialchars($line) . '</div>';
    }
    $html .= '</div></div>';

    $html .= '</div></div>';
    return $html;
}

function renderTextDiff($original_paragraphs, $current_paragraphs) {
    $added = 0; $removed = 0; $changed = 0; $same = 0;
    $max = max(count($original_paragraphs), count($current_paragraphs));

    for ($i = 0; $i < $max; $i++) {
        $orig = isset($original_paragraphs[$i]) ? $original_paragraphs[$i] : null;
        $curr = isset($current_paragraphs[$i]) ? $current_paragraphs[$i] : null;

        if ($orig === null && $curr !== null) $added++;
        elseif ($orig !== null && $curr === null) $removed++;
        elseif ($orig !== null && $curr !== null && $orig !== $curr) $changed++;
        elseif ($orig !== null && $curr !== null && $orig === $curr) $same++;
    }

    $html = '<div class="diff-container unified">';
    $html .= '<div style="padding:8px 12px;background:#f1f3f5;border-bottom:1px solid #ddd;font-weight:bold;display:flex;gap:20px;flex-wrap:wrap;">';
    $html .= '<span>📊 <strong>Comparison Stats:</strong></span>';
    $html .= '<span style="color:#28a745;">+ ' . $added . ' added</span>';
    $html .= '<span style="color:#dc3545;">− ' . $removed . ' removed</span>';
    $html .= '<span style="color:#ffc107;">✏️ ' . $changed . ' changed</span>';
    $html .= '<span style="color:#6c757d;">✓ ' . $same . ' unchanged</span>';
    $html .= '<span style="color:#6c757d;">Total: ' . ($added+$removed+$changed+$same) . ' paragraphs</span>';
    $html .= '</div>';

    $html .= '<div style="padding:8px 12px;background:#e9ecef;border-bottom:1px solid #ddd;">';
    $html .= '<label><input type="checkbox" id="selectAllChanged" onchange="toggleAllChanged()"> Select all changed paragraphs</label>';
    $html .= ' <button class="btn btn-sm btn-info" onclick="previewSelected()">👁️ Preview Selected</button>';
    $html .= ' <button class="btn btn-sm btn-primary" onclick="previewAll()">📖 Preview All</button>';
    $html .= ' <button class="btn btn-sm btn-warning" onclick="restoreSelected()">🔄 Restore Selected</button>';
    $html .= ' <button class="btn btn-sm btn-danger" onclick="restoreAll()">⚡ Restore All</button>';
    $html .= ' <span style="font-size:0.8rem;color:#666;">(Check the ones you want to restore from original)</span>';
    $html .= '</div>';

    for ($i = 0; $i < $max; $i++) {
        $orig = isset($original_paragraphs[$i]) ? $original_paragraphs[$i] : null;
        $curr = isset($current_paragraphs[$i]) ? $current_paragraphs[$i] : null;

        $paraId = 'para_' . $i;
        $checkbox = '';

        if ($orig !== null && $curr !== null && $orig !== $curr) {
            $checkbox = '<input type="checkbox" class="changed-para" data-index="' . $i . '" data-original="' . htmlspecialchars($orig) . '">';
            $html .= '<div class="diff-line diff-change" style="padding:8px;">';
            $html .= $checkbox . ' <strong>Paragraph ' . ($i+1) . '</strong><br>';
            $html .= '  <span style="background:#f8d7da;padding:0 4px;">' . htmlspecialchars(substr($orig, 0, 200)) . (strlen($orig) > 200 ? '…' : '') . '</span><br>';
            $html .= '  → <span style="background:#d4edda;padding:0 4px;">' . htmlspecialchars(substr($curr, 0, 200)) . (strlen($curr) > 200 ? '…' : '') . '</span>';
            $html .= '</div>';
        } elseif ($orig === null && $curr !== null) {
            $checkbox = '<input type="checkbox" class="added-para" data-index="' . $i . '">';
            $html .= '<div class="diff-line diff-add" style="padding:8px;">';
            $html .= $checkbox . ' + <strong>Added:</strong> ' . htmlspecialchars(substr($curr, 0, 200)) . (strlen($curr) > 200 ? '…' : '') . '</div>';
        } elseif ($orig !== null && $curr === null) {
            $checkbox = '<input type="checkbox" class="removed-para" data-index="' . $i . '" data-original="' . htmlspecialchars($orig) . '">';
            $html .= '<div class="diff-line diff-remove" style="padding:8px;">';
            $html .= $checkbox . ' − <strong>Removed:</strong> ' . htmlspecialchars(substr($orig, 0, 200)) . (strlen($orig) > 200 ? '…' : '') . '</div>';
        } else {
            $html .= '<div class="diff-line diff-same" style="padding:2px 8px;color:#aaa;font-size:0.8rem;">✓ Paragraph ' . ($i+1) . ' unchanged</div>';
        }
    }
    $html .= '</div>';
    return $html;
}

function renderVersionSideBySide($html_a, $html_b, $version_a, $version_b) {
    $clean_a = preg_replace('/ data-page="\d+"/', '', $html_a);
    $clean_b = preg_replace('/ data-page="\d+"/', '', $html_b);

    $dom_a = new DOMDocument();
    $dom_a->loadHTML(mb_convert_encoding($clean_a, 'HTML-ENTITIES', 'UTF-8'));
    $xpath_a = new DOMXPath($dom_a);
    $paras_a = $xpath_a->query('//p');

    $dom_b = new DOMDocument();
    $dom_b->loadHTML(mb_convert_encoding($clean_b, 'HTML-ENTITIES', 'UTF-8'));
    $xpath_b = new DOMXPath($dom_b);
    $paras_b = $xpath_b->query('//p');

    $max = max($paras_a->length, $paras_b->length);
    $html = '<div class="diff-columns" style="display:flex;gap:16px;flex-wrap:wrap;padding:12px;">';

    $html .= '<div class="diff-column" style="flex:1;min-width:280px;border:1px solid #ddd;border-radius:8px;overflow:hidden;">';
    $html .= '<div class="column-header" style="background:#f1f3f5;padding:8px 12px;border-bottom:1px solid #ddd;font-weight:bold;text-align:center;">v' . $version_a . '</div>';
    $html .= '<div class="column-content" style="max-height:500px;overflow-y:auto;padding:8px;">';
    for ($i = 0; $i < $max; $i++) {
        $para_a = isset($paras_a[$i]) ? $paras_a[$i]->textContent : '';
        $para_b = isset($paras_b[$i]) ? $paras_b[$i]->textContent : '';
        $class = 'diff-same';
        if ($para_a !== $para_b) {
            if ($para_a === '') $class = 'diff-add';
            elseif ($para_b === '') $class = 'diff-remove';
            else $class = 'diff-change';
        }
        $html .= '<div class="diff-line ' . $class . '" style="padding:4px 8px;border-bottom:1px solid #f0f0f0;font-size:0.9rem;">';
        $html .= '<strong>#' . ($i+1) . '</strong> ';
        if ($para_a === '') {
            $html .= '<span style="color:#999;">(missing)</span>';
        } else {
            $html .= htmlspecialchars(substr($para_a, 0, 200)) . (strlen($para_a) > 200 ? '…' : '');
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="diff-column" style="flex:1;min-width:280px;border:1px solid #ddd;border-radius:8px;overflow:hidden;">';
    $html .= '<div class="column-header" style="background:#f1f3f5;padding:8px 12px;border-bottom:1px solid #ddd;font-weight:bold;text-align:center;">v' . $version_b . '</div>';
    $html .= '<div class="column-content" style="max-height:500px;overflow-y:auto;padding:8px;">';
    for ($i = 0; $i < $max; $i++) {
        $para_a = isset($paras_a[$i]) ? $paras_a[$i]->textContent : '';
        $para_b = isset($paras_b[$i]) ? $paras_b[$i]->textContent : '';
        $class = 'diff-same';
        if ($para_a !== $para_b) {
            if ($para_a === '') $class = 'diff-add';
            elseif ($para_b === '') $class = 'diff-remove';
            else $class = 'diff-change';
        }
        $html .= '<div class="diff-line ' . $class . '" style="padding:4px 8px;border-bottom:1px solid #f0f0f0;font-size:0.9rem;">';
        $html .= '<strong>#' . ($i+1) . '</strong> ';
        if ($para_b === '') {
            $html .= '<span style="color:#999;">(missing)</span>';
        } else {
            $html .= htmlspecialchars(substr($para_b, 0, 200)) . (strlen($para_b) > 200 ? '…' : '');
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';

    $html .= '</div>';

    $html .= '<div style="padding:8px 12px;background:#f8f9fa;border-top:1px solid #ddd;display:flex;gap:20px;flex-wrap:wrap;font-size:0.85rem;">';
    $html .= '<span><span style="background:#d4edda;padding:0 4px;">Green</span> = Added in v' . $version_b . '</span>';
    $html .= '<span><span style="background:#f8d7da;padding:0 4px;">Red</span> = Removed from v' . $version_b . '</span>';
    $html .= '<span><span style="background:#fff3cd;padding:0 4px;">Yellow</span> = Changed</span>';
    $html .= '<span><span style="background:#f8f9fa;padding:0 4px;">Gray</span> = Unchanged</span>';
    $html .= '</div>';

    return $html;
}

function mergePreservingEdits($current_html, $original_paragraphs) {
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($current_html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $paragraphs = $xpath->query('//p');
    $para_index = 0;
    $total_original = count($original_paragraphs);

    foreach ($paragraphs as $p) {
        $current_text = $p->textContent;
        if ($para_index < $total_original) {
            $children = $p->childNodes;
            $has_inline_tags = false;
            foreach ($children as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $has_inline_tags = true;
                    break;
                }
            }

            if ($has_inline_tags) {
                $text_nodes = $xpath->query('.//text()', $p);
                foreach ($text_nodes as $text_node) {
                    if (trim($text_node->nodeValue) !== '') {
                        $text_node->nodeValue = $original_paragraphs[$para_index];
                        break;
                    }
                }
            } else {
                $p->nodeValue = $original_paragraphs[$para_index];
            }
            $para_index++;
        }
    }

    while ($para_index < $total_original) {
        $new_p = $dom->createElement('p');
        $new_p->nodeValue = $original_paragraphs[$para_index];
        $body = $dom->getElementsByTagName('body')->item(0);
        $body->appendChild($new_p);
        $para_index++;
    }

    $merged = $dom->saveHTML();
    $merged = preg_replace('/^<!DOCTYPE.*?<html>.*?<body>/s', '', $merged);
    $merged = preg_replace('/<\/body><\/html>$/s', '', $merged);
    return $merged;
}

function selectiveRestore($current_html, $original_paragraphs, $selected_indices) {
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($current_html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $paragraphs = $xpath->query('//p');
    $para_index = 0;

    $restore_map = [];
    foreach ($selected_indices as $item) {
        $restore_map[$item['index']] = $item['original'];
    }

    foreach ($paragraphs as $p) {
        if (isset($restore_map[$para_index])) {
            $original_text = $restore_map[$para_index];
            $children = $p->childNodes;
            $has_inline_tags = false;
            foreach ($children as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $has_inline_tags = true;
                    break;
                }
            }

            if ($has_inline_tags) {
                $text_nodes = $xpath->query('.//text()', $p);
                foreach ($text_nodes as $text_node) {
                    if (trim($text_node->nodeValue) !== '') {
                        $text_node->nodeValue = $original_text;
                        break;
                    }
                }
            } else {
                $p->nodeValue = $original_text;
            }
        }
        $para_index++;
    }

    $new_html = $dom->saveHTML();
    $new_html = preg_replace('/^<!DOCTYPE.*?<html>.*?<body>/s', '', $new_html);
    $new_html = preg_replace('/<\/body><\/html>$/s', '', $new_html);
    return $new_html;
}

function restoreAllParagraphs($current_html, $original_paragraphs) {
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($current_html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $paragraphs = $xpath->query('//p');
    $para_index = 0;
    $total_original = count($original_paragraphs);

    foreach ($paragraphs as $p) {
        if ($para_index < $total_original) {
            $original_text = $original_paragraphs[$para_index];
            $children = $p->childNodes;
            $has_inline_tags = false;
            foreach ($children as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $has_inline_tags = true;
                    break;
                }
            }

            if ($has_inline_tags) {
                $text_nodes = $xpath->query('.//text()', $p);
                foreach ($text_nodes as $text_node) {
                    if (trim($text_node->nodeValue) !== '') {
                        $text_node->nodeValue = $original_text;
                        break;
                    }
                }
            } else {
                $p->nodeValue = $original_text;
            }
        }
        $para_index++;
    }

    while ($para_index < $total_original) {
        $new_p = $dom->createElement('p');
        $new_p->nodeValue = $original_paragraphs[$para_index];
        $body = $dom->getElementsByTagName('body')->item(0);
        $body->appendChild($new_p);
        $para_index++;
    }

    $new_html = $dom->saveHTML();
    $new_html = preg_replace('/^<!DOCTYPE.*?<html>.*?<body>/s', '', $new_html);
    $new_html = preg_replace('/<\/body><\/html>$/s', '', $new_html);
    return $new_html;
}

function generatePreview($current_html, $original_paragraphs, $selected_indices, $preview_all = false) {
    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($current_html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $summary = $dom->createElement('div');
    $summary->setAttribute('style', 'background:#f8f9fa;padding:12px;border-radius:8px;margin-bottom:16px;border:1px solid #ddd;');
    if ($preview_all) {
        $summary->nodeValue = '📖 Preview All: ' . count($selected_indices) . ' paragraphs will be restored to original text.';
    } else {
        $summary->nodeValue = '👁️ Preview: ' . count($selected_indices) . ' paragraphs will be restored to original text.';
    }
    $body = $dom->getElementsByTagName('body')->item(0);
    $body->insertBefore($summary, $body->firstChild);

    $paragraphs = $xpath->query('//p');
    $para_index = 0;

    $restore_map = [];
    foreach ($selected_indices as $item) {
        $restore_map[$item['index']] = $item['original'];
    }

    foreach ($paragraphs as $p) {
        if (isset($restore_map[$para_index])) {
            $original_text = $restore_map[$para_index];
            $wrapper = $dom->createElement('div');
            $wrapper->setAttribute('class', 'preview-change');
            $wrapper->setAttribute('style', 'border:2px solid #ffc107;padding:8px;margin:4px 0;border-radius:4px;background:#fffef0;');

            $original_label = $dom->createElement('div');
            $original_label->setAttribute('style', 'font-weight:bold;color:#28a745;');
            $original_label->nodeValue = '✅ Restored from original:';
            $wrapper->appendChild($original_label);

            $original_content = $dom->createElement('div');
            $original_content->nodeValue = $original_text;
            $wrapper->appendChild($original_content);

            $current_label = $dom->createElement('div');
            $current_label->setAttribute('style', 'font-weight:bold;color:#dc3545;');
            $current_label->nodeValue = '❌ Current (will be replaced):';
            $wrapper->appendChild($current_label);

            $current_content = $dom->createElement('div');
            $current_content->setAttribute('style', 'text-decoration:line-through;color:#dc3545;');
            $current_content->nodeValue = $p->textContent;
            $wrapper->appendChild($current_content);

            $p->parentNode->replaceChild($wrapper, $p);
        }
        $para_index++;
    }

    if ($preview_all) {
        $unchanged_note = $dom->createElement('div');
        $unchanged_note->setAttribute('style', 'margin-top:16px;padding:8px;background:#e9ecef;border-radius:4px;text-align:center;color:#6c757d;');
        $unchanged_note->nodeValue = '✓ ' . ($para_index - count($selected_indices)) . ' paragraphs unchanged (not shown in preview).';
        $body->appendChild($unchanged_note);
    }

    $preview_html = $dom->saveHTML();
    $preview_html = preg_replace('/^<!DOCTYPE.*?<html>.*?<body>/s', '', $preview_html);
    $preview_html = preg_replace('/<\/body><\/html>$/s', '', $preview_html);
    return $preview_html;
}

function generateReportHTML($diffContent, $title, $book_id) {
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' - AngelWrites</title>
    <style>
        body { font-family: Inter, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; color: #333; line-height: 1.6; }
        h1 { color: #c0392b; border-bottom: 2px solid #c0392b; padding-bottom: 10px; }
        .version-info { background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #c0392b; }
        .diff-container { margin-top: 20px; }
        .diff-columns { display: flex; gap: 16px; flex-wrap: wrap; }
        .diff-column { flex: 1; min-width: 280px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .column-header { background: #f1f3f5; padding: 8px 12px; border-bottom: 1px solid #ddd; font-weight: bold; text-align: center; }
        .column-content { max-height: 600px; overflow-y: auto; padding: 8px; }
        .diff-line { padding: 4px 8px; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; font-family: monospace; white-space: pre-wrap; word-break: break-word; }
        .diff-add { background: #d4edda; color: #155724; }
        .diff-remove { background: #f8d7da; color: #721c24; }
        .diff-change { background: #fff3cd; color: #856404; }
        .diff-same { color: #6c757d; }
        .legend { margin-top: 16px; padding: 8px 12px; background: #f8f9fa; border-radius: 8px; border: 1px solid #ddd; display: flex; gap: 20px; flex-wrap: wrap; font-size: 0.85rem; }
        .footer { margin-top: 30px; padding-top: 16px; border-top: 1px solid #ddd; text-align: center; color: #999; font-size: 0.85rem; }
        @media print { body { margin: 20px; } .column-content { max-height: none; } .diff-column { break-inside: avoid; } }
    </style>
</head>
<body>
    <h1>📄 ' . htmlspecialchars($title) . '</h1>
    <div class="version-info">
        <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><strong>Book ID:</strong> ' . $book_id . '</p>
    </div>
    ' . $diffContent . '
    <div class="footer">AngelWrites – Version Comparison Report<br>To print to PDF, use Ctrl+P (or Cmd+P) and select "Save as PDF".</div>
</body>
</html>';
}

function generateFullHistoryReport($historyContent, $book_id) {
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Version History - AngelWrites</title>
    <style>
        body { font-family: Inter, sans-serif; max-width: 1000px; margin: 40px auto; padding: 20px; color: #333; line-height: 1.6; }
        h1 { color: #c0392b; border-bottom: 2px solid #c0392b; padding-bottom: 10px; }
        .version-info { background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #c0392b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f1f3f5; }
        .current-version { background: #d4edda; }
        .footer { margin-top: 30px; padding-top: 16px; border-top: 1px solid #ddd; text-align: center; color: #999; font-size: 0.85rem; }
        @media print { body { margin: 20px; } }
    </style>
</head>
<body>
    <h1>📜 Full Version History</h1>
    <div class="version-info">
        <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
        <p><strong>Book ID:</strong> ' . $book_id . '</p>
    </div>
    ' . $historyContent . '
    <div class="footer">AngelWrites – Version History Report<br>To print to PDF, use Ctrl+P (or Cmd+P) and select "Save as PDF".</div>
</body>
</html>';
}
// ============================================================
//  POST HANDLERS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['extract'])) {
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            $error = 'Book file not found.';
        } else {
            $raw_text = extractRawText($file_path);
            if ($raw_text) {
                $parsed = parseBook($raw_text);
                $html_content = renderBook($parsed, $book);
                $toc_json = json_encode($parsed['toc']);
                $metadata = [
                    'keywords' => extractKeywords($raw_text),
                    'page_breaks' => $parsed['page_breaks']
                ];
                $metadata_json = json_encode($metadata);

                $stmt = $db->prepare("INSERT OR REPLACE INTO book_content (book_id, title, content_html, toc_json, metadata_json, is_processed, processing_status) VALUES (?, ?, ?, ?, ?, 1, 'complete')");
                $stmt->execute([$book_id, $book['title'], $html_content, $toc_json, $metadata_json]);

                saveVersionHistory($book_id, $html_content, $toc_json, $metadata_json, 'Initial extraction');
                $success = '✅ Content extracted, parsed, and rendered successfully.';
            } else {
                $error = 'Failed to extract content from the file.';
            }
        }
    }
    if (!empty($_FILES['live_cover']['name'])) {
    $upload_dir = '../assets/uploads/books/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $cover_filename = 'live_cover_' . time() . '.jpg';
    if (move_uploaded_file($_FILES['live_cover']['tmp_name'], $upload_dir . $cover_filename)) {
        $cover_path = 'assets/uploads/books/' . $cover_filename;
        // Update book record with new cover
        $stmt = $db->prepare("UPDATE books SET cover_path = ? WHERE id = ?");
        $stmt->execute([$cover_path, $book_id]);
    }
}

    if (isset($_POST['queue_book'])) {
        addToQueue($book_id);
        $success = '✅ Book added to processing queue.';
    }

    if (isset($_POST['save_content'])) {
        $content_html = trim($_POST['content_html']);
        if (!empty($content_html)) {
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$content_html, $book_id]);

            $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            saveVersionHistory($book_id, $content_html, $row['toc_json'], $row['metadata_json'], 'Manual edit');

            $success = '✅ Content saved successfully.';
        } else {
            $error = 'Content cannot be empty.';
        }
    }

    if (isset($_POST['apply_corrections'])) {
        $corrected_toc = json_decode($_POST['corrected_toc'], true);
        $stmt = $db->prepare("UPDATE book_content SET toc_json = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([json_encode($corrected_toc), $book_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if (isset($_POST['reset_page_breaks'])) {
        $file_path = '../' . $book['file_path'];
        if (!file_exists($file_path)) {
            $error = 'Book file not found. Cannot reset page breaks.';
        } else {
            $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $old_content = $stmt->fetchColumn();

            $raw_text = extractRawText($file_path);
            if (!$raw_text) {
                $error = 'Failed to extract content from the original file.';
            } else {
                $parsed = parseBook($raw_text);
                $new_content = renderBookFromParsed($parsed, $book);
                $metadata = [
                    'keywords' => extractKeywords($raw_text),
                    'page_breaks' => $parsed['page_breaks']
                ];
                $metadata_json = json_encode($metadata);
                $toc_json = json_encode($parsed['toc']);

                $stmt = $db->prepare("UPDATE book_content SET content_html = ?, toc_json = ?, metadata_json = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
                $stmt->execute([$new_content, $toc_json, $metadata_json, $book_id]);

                saveVersionHistory($book_id, $new_content, $toc_json, $metadata_json, 'Reset page breaks');

                $_SESSION['page_break_diff_old'] = $old_content;
                $_SESSION['page_break_diff_new'] = $new_content;
                $_SESSION['show_diff'] = true;

                $success = '✅ Page breaks reset successfully. <a href="#" onclick="showDiffModal()">View changes</a>';
            }
        }
    }

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

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
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$merged_content, $book_id]);

            $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            saveVersionHistory($book_id, $merged_content, $row['toc_json'], $row['metadata_json'], 'Restored from original (preserving edits)');

            echo json_encode(['success' => true, 'content' => $merged_content]);
            exit;
        }

        if ($action === 'preview_restore') {
            $book_id = (int)$_POST['book_id'];
            $selected = json_decode($_POST['selected'], true);
            $preview_all = isset($_POST['preview_all']) && $_POST['preview_all'] === '1';

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
            $book_id = (int)$_POST['book_id'];
            $selected = json_decode($_POST['selected'], true);
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
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$new_html, $book_id]);

            $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            saveVersionHistory($book_id, $new_html, $row['toc_json'], $row['metadata_json'], 'Selective restore');

            echo json_encode(['success' => true, 'content' => $new_html]);
            exit;
        }

        if ($action === 'restore_all') {
            $book_id = (int)$_POST['book_id'];
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
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$new_html, $book_id]);

            $stmt = $db->prepare("SELECT toc_json, metadata_json FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            saveVersionHistory($book_id, $new_html, $row['toc_json'], $row['metadata_json'], 'Restore all paragraphs');

            echo json_encode(['success' => true, 'content' => $new_html]);
            exit;
        }

        if ($action === 'get_version_history') {
            $book_id = (int)$_POST['book_id'];
            $stmt = $db->prepare("SELECT * FROM book_content_history WHERE book_id = ? ORDER BY version DESC");
            $stmt->execute([$book_id]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($versions)) {
                echo '<p style="color:var(--text-light);font-size:0.9rem;">No version history available for this book.</p>';
                exit;
            }

            $stmt = $db->prepare("SELECT version FROM book_content WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $current_version = $stmt->fetchColumn() ?? 0;

            $html = '<div style="max-height:400px;overflow-y:auto;">';
            $html .= '<table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr style="background:#f1f3f5;border-bottom:2px solid #ddd;">';
            $html .= '<th style="padding:8px 12px;text-align:left;">Version</th>';
            $html .= '<th style="padding:8px 12px;text-align:left;">Date</th>';
            $html .= '<th style="padding:8px 12px;text-align:left;">Note</th>';
            $html .= '<th style="padding:8px 12px;text-align:left;">Actions</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($versions as $version) {
                $is_current = ($version['version'] == $current_version);
                $row_class = $is_current ? 'style="background:#d4edda;"' : '';
                $html .= '<tr ' . $row_class . '>';
                $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">';
                $html .= $is_current ? '<strong>v' . $version['version'] . ' (current)</strong>' : 'v' . $version['version'];
                $html .= '</td>';
                $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . date('Y-m-d H:i:s', strtotime($version['created_at'])) . '</td>';
                $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . htmlspecialchars($version['note'] ?? '—') . '</td>';
                $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">';
                $html .= '<button class="btn btn-sm btn-info" onclick="previewVersion(' . $version['version'] . ')">👁️ Preview</button> ';
                if (!$is_current) {
                    $html .= '<button class="btn btn-sm btn-danger" onclick="restoreVersion(' . $version['version'] . ')">↩️ Restore</button>';
                }
                $html .= '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
            echo $html;
            exit;
        }

        if ($action === 'preview_version') {
            $book_id = (int)$_POST['book_id'];
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

        if ($action === 'restore_version') {
            $book_id = (int)$_POST['book_id'];
            $version = (int)$_POST['version'];
            $stmt = $db->prepare("SELECT content_html, toc_json, metadata_json FROM book_content_history WHERE book_id = ? AND version = ?");
            $stmt->execute([$book_id, $version]);
            $version_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$version_data) {
                echo json_encode(['success' => false, 'error' => 'Version not found.']);
                exit;
            }

            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$version_data['content_html'], $book_id]);

            $stmt = $db->prepare("SELECT MAX(version) FROM book_content_history WHERE book_id = ?");
            $stmt->execute([$book_id]);
            $next_version = $stmt->fetchColumn() + 1;

            $stmt = $db->prepare("INSERT INTO book_content_history (book_id, content_html, toc_json, metadata_json, version, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $book_id,
                $version_data['content_html'],
                $version_data['toc_json'],
                $version_data['metadata_json'],
                $next_version,
                'Restored from version v' . $version
            ]);

            echo json_encode(['success' => true, 'content' => $version_data['content_html']]);
            exit;
        }

        if ($action === 'send_report_email') {
            $book_id = (int)$_POST['book_id'];
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
            $book_id = (int)$_POST['book_id'];
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
    }
}

// ============================================================
//  UI – ADMIN PAGE
// ============================================================

$pageTitle = 'Process Book: ' . htmlspecialchars($book['title']);
require_once '../includes/header.php';
?>

<div class="admin-process-book">
    <div class="container">
        <div class="admin-header">
            <h1>Process Book: <?php echo htmlspecialchars($book['title']); ?></h1>
            <div class="form-group">
                <label>Live Book Cover</label>
                <div class="camera-section">...</div>
                <input type="file" name="live_cover" id="liveCoverInput" accept="image/*" style="display:none;">
            </div>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Books
                </a>
                <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-eye"></i> Preview Reader
                </a>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Stage 1 -->
        <div class="card">
            <div class="card-header"><h2>📥 Stage 1: Extract & Parse</h2></div>
            <div class="card-body">
                <form method="POST">
                    <button type="submit" name="extract" class="btn btn-primary btn-large">
                        <i class="fas fa-magic"></i> Extract & Parse Content
                    </button>
                    <button type="submit" name="queue_book" class="btn btn-secondary btn-large">
                        <i class="fas fa-clock"></i> Add to Processing Queue
                    </button>
                    <p class="field-hint">The extractor uses `pdftotext` if available; otherwise, it falls back to pure PHP.</p>
                </form>
            </div>
        </div>

        <!-- Stage 2 -->
        <?php if ($existing && $existing['is_processed'] == 1): ?>
        <div class="card">
            <div class="card-header"><h2>✏️ Stage 2: Correction Interface</h2></div>
            <div class="card-body">
                <?php
                // Render TOC correction
                $toc = json_decode($existing['toc_json'] ?? '[]', true);
                ?>
                <div style="background:var(--vanilla);padding:16px;border-radius:12px;border:1px solid var(--border);margin:16px 0;">
                    <h4 style="margin-top:0;">📑 Table of Contents</h4>
                    <div class="toc-editor" style="display:flex;flex-direction:column;gap:4px;">
                        <?php foreach ($toc as $i => $item): ?>
                            <div style="display:flex;gap:8px;align-items:center;padding:4px 8px;background:var(--card-bg);border-radius:6px;border:1px solid var(--border);">
                                <input type="text" value="<?php echo htmlspecialchars($item['title']); ?>" 
                                       data-index="<?php echo $i; ?>" class="toc-edit-field" 
                                       style="flex:1;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;">
                                <button class="btn btn-sm btn-danger remove-toc" data-index="<?php echo $i; ?>" 
                                        style="padding:2px 8px;font-size:0.7rem;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button class="btn btn-sm btn-secondary" onclick="addTocEntry()">+ Add Entry</button>
                        <button class="btn btn-sm btn-primary" onclick="applyTocCorrections()">✅ Apply Corrections</button>
                    </div>
                </div>
                <script>
                function addTocEntry() {
                    const container = document.querySelector('.toc-editor');
                    const div = document.createElement('div');
                    div.style.cssText = 'display:flex;gap:8px;align-items:center;padding:4px 8px;background:var(--card-bg);border-radius:6px;border:1px solid var(--border);';
                    div.innerHTML = `
                        <input type="text" class="toc-edit-field" style="flex:1;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.85rem;" value="New Chapter">
                        <button class="btn btn-sm btn-danger remove-toc" style="padding:2px 8px;font-size:0.7rem;" onclick="this.parentElement.remove()">✕</button>
                    `;
                    container.appendChild(div);
                }
                function applyTocCorrections() {
                    const toc = [];
                    document.querySelectorAll('.toc-edit-field').forEach(input => {
                        toc.push({ title: input.value, level: 1 });
                    });
                    const formData = new FormData();
                    formData.append('corrected_toc', JSON.stringify(toc));
                    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
                        method: 'POST',
                        body: formData
                    }).then(r => r.json()).then(data => {
                        if (data.success) location.reload();
                        else alert('Failed to apply corrections.');
                    });
                }
                </script>

                <!-- Page break correction -->
                <?php
                $content = $existing['content_html'] ?? '';
                $break_count = substr_count($content, 'class="page-break"');
                ?>
                <div style="background:var(--vanilla);padding:16px;border-radius:12px;border:1px solid var(--border);margin:16px 0;">
                    <h4 style="margin-top:0;">📄 Page Breaks</h4>
                    <p style="color:var(--text-light);font-size:0.9rem;">
                        Current page breaks: <strong><?php echo $break_count; ?></strong>.
                        Use the editor to insert or remove breaks. Click "Save Changes" below to apply.
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn btn-sm btn-primary" onclick="insertPageBreakInEditor()">➕ Insert Page Break</button>
                        <button class="btn btn-sm btn-danger" onclick="removePageBreakInEditor()">➖ Remove Nearest Break</button>
                    </div>
                </div>
                <script>
                function insertPageBreakInEditor() {
                    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                        tinymce.activeEditor.execCommand('insertpagebreak');
                    }
                }
                function removePageBreakInEditor() {
                    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                        tinymce.activeEditor.execCommand('removepagebreak');
                    }
                }
                </script>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage 3 -->
        <div class="card">
            <div class="card-header"><h2>📝 Stage 3: Edit & Refine</h2></div>
            <div class="card-body">
                <textarea id="editor" name="content_html" style="width:100%;height:500px;"><?php echo htmlspecialchars($existing['content_html'] ?? ''); ?></textarea>
                <?php
                // TinyMCE with page break support
                ?>
                <script>
                tinymce.init({
                    selector: '#editor',
                    height: 500,
                    menubar: true,
                    plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
                    toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code | insertpagebreak removepagebreak',
                    content_style: 'body { font-family: Inter, sans-serif; font-size: 18px; line-height: 2; } .page-break { display: block; width: 100%; border-top: 2px dashed #c0392b; margin: 20px 0; text-align: center; color: #c0392b; font-size: 0.8rem; } .page-break::before { content: "⏎ Page Break"; }',
                    forced_root_block: 'p',
                    setup: function(editor) {
                        editor.ui.registry.addButton('insertpagebreak', {
                            text: '📄 Insert Page Break',
                            icon: 'newpage',
                            onAction: function() {
                                editor.insertContent('<div class="page-break" data-page-break="true"></div>');
                            }
                        });
                        editor.ui.registry.addButton('removepagebreak', {
                            text: '🗑️ Remove Page Break',
                            icon: 'remove',
                            onAction: function() {
                                const node = editor.selection.getNode();
                                if (node.classList && node.classList.contains('page-break')) {
                                    node.remove();
                                    return;
                                }
                                const parent = node.closest('.page-break');
                                if (parent) {
                                    parent.remove();
                                    return;
                                }
                                alert('No page break found at cursor position.');
                            }
                        });
                    }
                });
                </script>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="saveContent()">💾 Save Changes</button>
                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                        <i class="fas fa-eye"></i> Preview Reader
                    </a>
                    <button class="btn btn-outline" onclick="compareWithOriginal()">
                        <i class="fas fa-file-alt"></i> Compare with Original
                    </button>
                    <button class="btn btn-warning" onclick="restoreFromOriginal()">
                        <i class="fas fa-undo-alt"></i> Restore from Original
                    </button>
                    <button class="btn btn-danger" onclick="restoreToOriginalFull()">
                        <i class="fas fa-undo-alt"></i> ⚡ Restore to Original
                    </button>
                </div>
                <div id="restore-status" style="display:none;padding:12px;border-radius:8px;margin-top:12px;"></div>
            </div>
        </div>

        <!-- Stage 4 -->
        <?php if ($existing): ?>
        <div class="card">
            <div class="card-header"><h2>📤 Stage 4: Export</h2></div>
            <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="POST" style="display:inline;">
                    <button type="submit" name="export_txt" class="btn btn-sm btn-outline">
                        <i class="fas fa-file-alt"></i> TXT
                    </button>
                </form>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="export_html" class="btn btn-sm btn-outline">
                        <i class="fas fa-file-code"></i> HTML
                    </button>
                </form>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="export_toc" class="btn btn-sm btn-outline">
                        <i class="fas fa-table"></i> TOC CSV
                    </button>
                </form>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="export_multi" class="btn btn-sm btn-outline">
                        <i class="fas fa-folder-open"></i> Multi-Page ZIP
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage 5: Version History -->
        <div class="card">
            <div class="card-header">
                <h2>📜 Version History</h2>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-sm btn-outline" onclick="loadVersionHistory()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-sm btn-success" onclick="sendFullHistoryViaEmail()">
                        <i class="fas fa-envelope"></i> Email Full History
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="exportFullHistory()">
                        <i class="fas fa-file-export"></i> Export Full History
                    </button>
                </div>
            </div>
            <div class="card-body" id="version-history-container">
                <p style="color:var(--text-light);font-size:0.9rem;">Loading version history...</p>
            </div>
        </div>

        <!-- Reset card -->
        <?php if ($existing && $existing['is_processed'] == 1): ?>
        <div class="card">
            <div class="card-header"><h2>🔄 Reset Page Breaks</h2></div>
            <div class="card-body">
                <p style="color:var(--text-light);font-size:0.9rem;">
                    This will <strong>discard all manual page breaks</strong> and re‑parse the original file.
                    Any custom edits to the content (text changes, paragraph splits) will be lost.
                </p>
                <form method="POST" onsubmit="return confirm('Are you sure? This will overwrite all content with a fresh extraction.');">
                    <input type="hidden" name="reset_page_breaks" value="1">
                    <button type="submit" class="btn btn-danger btn-large">
                        <i class="fas fa-redo-alt"></i> Reset Page Breaks
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Revert restore card (hidden by default) -->
        <div class="card" id="revert-restore-card" style="display:none;">
            <div class="card-header"><h2>↩️ Revert Restore</h2></div>
            <div class="card-body">
                <p style="color:var(--text-light);font-size:0.9rem;">
                    If you accidentally restored to original and lost your edits, you can revert to the previous version.
                </p>
                <button class="btn btn-warning" onclick="revertRestoreToOriginal()">
                    <i class="fas fa-undo-alt"></i> Revert to Previous Version
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Diff Modal -->
<div id="diffModal" class="modal" style="display:none;">
    <div class="modal-content" style="background:#fff;margin:5% auto;max-width:900px;padding:24px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
            <h3 id="modalTitle" style="margin:0;">📄 Diff View</h3>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button id="diffToggleBtn" class="diff-toggle" onclick="toggleDiffMode()">
                    <span id="diffToggleIcon">📄</span>
                    <span id="diffToggleText">Unified</span>
                </button>
                <button id="colorBlindBtn" class="diff-toggle" onclick="toggleColorBlindMode()">
                    <span id="colorBlindIcon">👁️</span>
                    <span id="colorBlindText">Color mode</span>
                </button>
                <button onclick="downloadDiff()" class="diff-toggle" style="background:#e9ecef;">
                    <span>📥</span> Download
                </button>
                <button onclick="closeDiffModal()" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
            </div>
        </div>
        <div id="diffContent" style="max-height:60vh;overflow-y:auto;">
            <p style="text-align:center;color:#999;">No diff data available.</p>
        </div>
        <div class="modal-footer" style="margin-top:16px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div style="display:flex;gap:8px;">
                <button class="btn btn-success" onclick="acceptChanges()">✅ Accept Changes</button>
                <button class="btn btn-danger" onclick="revertChanges()">↩️ Revert to Old Version</button>
            </div>
            <button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
        </div>
    </div>
</div>

<script>
// ============================================================
//  GLOBAL JAVASCRIPT
// ============================================================

let diffMode = 'unified';
let colorBlindMode = false;

function showDiffModal() {
    document.getElementById('diffModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    diffMode = 'unified';
    colorBlindMode = false;
    updateDiffView();
    updateToggleUI();
    updateColorBlindUI();
}

function closeDiffModal() {
    document.getElementById('diffModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function toggleDiffMode() {
    diffMode = diffMode === 'unified' ? 'sidebyside' : 'unified';
    updateDiffView();
    updateToggleUI();
}

function toggleColorBlindMode() {
    colorBlindMode = !colorBlindMode;
    updateDiffView();
    updateColorBlindUI();
}

function updateDiffView() {
    const container = document.getElementById('diffContent');
    container.innerHTML = '<p style="text-align:center;color:#999;">Loading diff view...</p>';
    const formData = new FormData();
    formData.append('mode', diffMode);
    formData.append('color_blind', colorBlindMode ? '1' : '0');
    fetch('<?php echo SITE_URL; ?>/admin/diff_ajax.php', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => { container.innerHTML = html; });
}

function updateToggleUI() {
    const btn = document.getElementById('diffToggleBtn');
    const text = document.getElementById('diffToggleText');
    const icon = document.getElementById('diffToggleIcon');
    if (diffMode === 'unified') {
        text.textContent = 'Unified';
        icon.textContent = '📄';
        btn.classList.remove('active');
    } else {
        text.textContent = 'Side-by-Side';
        icon.textContent = '📑';
        btn.classList.add('active');
    }
}

function updateColorBlindUI() {
    const btn = document.getElementById('colorBlindBtn');
    const text = document.getElementById('colorBlindText');
    const icon = document.getElementById('colorBlindIcon');
    if (colorBlindMode) {
        text.textContent = 'Pattern mode';
        icon.textContent = '🔲';
        btn.classList.add('active');
    } else {
        text.textContent = 'Color mode';
        icon.textContent = '👁️';
        btn.classList.remove('active');
    }
}

function downloadDiff() {
    const formData = new FormData();
    formData.append('mode', diffMode);
    formData.append('color_blind', colorBlindMode ? '1' : '0');
    formData.append('download', '1');
    fetch('<?php echo SITE_URL; ?>/admin/diff_ajax.php', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(text => {
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'page_break_diff.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
}

function acceptChanges() {
    if (confirm('✅ The new version has already been saved. Do you want to keep it?')) {
        const formData = new FormData();
        formData.append('action', 'accept');
        fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
            method: 'POST',
            body: formData
        }).then(response => response.json()).then(data => {
            if (data.success) {
                alert('✅ Changes accepted. New version is now the active content.');
                closeDiffModal();
            } else {
                alert('❌ Failed to accept changes: ' + data.error);
            }
        });
    }
}

function revertChanges() {
    if (!confirm('⚠️ This will undo the reset and restore the old version. All manual edits will be lost. Continue?')) return;
    const formData = new FormData();
    formData.append('action', 'revert');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            alert('✅ Reverted to old version successfully. Page breaks restored.');
            location.reload();
        } else {
            alert('❌ Failed to revert: ' + (data.error || 'Unknown error'));
            location.reload();
        }
    });
}

function compareWithOriginal() {
    const content = tinymce.get('editor').getContent();
    const formData = new FormData();
    formData.append('action', 'compare_original');
    formData.append('current_content', content);
    document.getElementById('diffContent').innerHTML = '<p style="text-align:center;color:#999;">⏳ Comparing with original file...</p>';
    document.getElementById('diffModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.querySelector('#modalTitle').textContent = '📄 Comparing with Original File';
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => {
        document.getElementById('diffContent').innerHTML = html;
        const footer = document.querySelector('.modal-footer');
        footer.innerHTML = `
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
                <button class="btn btn-sm btn-outline" onclick="loadVersionHistory()">Refresh History</button>
            </div>
        `;
    });
}

function restoreFromOriginal() {
    if (!confirm('⚠️ This will restore the original text from the source file while keeping your manual edits. Continue?')) return;
    const editor = tinymce.get('editor');
    const currentContent = editor.getContent();
    const formData = new FormData();
    formData.append('action', 'restore_original');
    formData.append('current_content', currentContent);
    document.getElementById('diffContent').innerHTML = '<p style="text-align:center;color:#999;">⏳ Restoring original text while preserving edits...</p>';
    document.getElementById('diffModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.querySelector('#modalTitle').textContent = '🔄 Restoring from Original';
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            editor.setContent(data.content);
            document.getElementById('diffContent').innerHTML = '<div style="padding:20px;text-align:center;color:#28a745;">✅ Restored successfully! Your manual edits have been preserved.</div>';
            document.querySelector('#modalTitle').textContent = '✅ Restore Complete';
            const footer = document.querySelector('.modal-footer');
            footer.innerHTML = '<button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>';
        } else {
            document.getElementById('diffContent').innerHTML = '<p style="color:red;">❌ Failed to restore: ' + (data.error || 'Unknown error') + '</p>';
        }
    });
}

function restoreToOriginalFull() {
    if (!confirm('⚠️ This will IMMEDIATELY reset the entire book content to the original file. ALL manual edits will be permanently LOST. Continue?')) return;
    if (!confirm('Are you absolutely sure? This action cannot be undone through the editor (only via version history).')) return;
    const editor = tinymce.get('editor');
    const formData = new FormData();
    formData.append('action', 'restore_to_original_full');
    formData.append('book_id', <?php echo $book_id; ?>);
    const statusDiv = document.getElementById('restore-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '⏳ Restoring to original file...';
    statusDiv.style.background = '#e9ecef';
    statusDiv.style.color = '#333';
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            editor.setContent(data.content);
            statusDiv.innerHTML = '✅ Successfully restored to original file. All manual edits removed.';
            statusDiv.style.background = '#d4edda';
            statusDiv.style.color = '#155724';
            document.getElementById('revert-restore-card').style.display = 'block';
            setTimeout(() => { statusDiv.style.display = 'none'; }, 5000);
        } else {
            statusDiv.innerHTML = '❌ Failed to restore: ' + (data.error || 'Unknown error');
            statusDiv.style.background = '#f8d7da';
            statusDiv.style.color = '#721c24';
        }
    });
}

function revertRestoreToOriginal() {
    if (!confirm('Revert to the version before "Restore to Original"? This will restore your manual edits.')) return;
    const formData = new FormData();
    formData.append('action', 'revert_restore_original');
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            tinymce.get('editor').setContent(data.content);
            document.getElementById('revert-restore-card').style.display = 'none';
            alert('✅ Reverted to previous version.');
        } else {
            alert('❌ Failed to revert: ' + (data.error || 'Unknown error'));
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
    }).then(response => response.text()).then(() => {
        alert('✅ Content saved.');
    }).catch(() => {
        alert('❌ Failed to save.');
    });
}

function loadVersionHistory() {
    const container = document.getElementById('version-history-container');
    container.innerHTML = '<p style="color:var(--text-light);font-size:0.9rem;">Loading version history...</p>';
    const formData = new FormData();
    formData.append('action', 'get_version_history');
    formData.append('book_id', <?php echo $book_id; ?>);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => {
        container.innerHTML = html;
    });
}

function previewVersion(version) {
    const formData = new FormData();
    formData.append('action', 'preview_version');
    formData.append('book_id', <?php echo $book_id; ?>);
    formData.append('version', version);
    document.getElementById('diffContent').innerHTML = '<p style="text-align:center;color:#999;">⏳ Loading version preview...</p>';
    document.getElementById('diffModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.querySelector('#modalTitle').textContent = '👁️ Preview Version v' + version;
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.text()).then(html => {
        document.getElementById('diffContent').innerHTML = html;
        const footer = document.querySelector('.modal-footer');
        footer.innerHTML = `
            <div style="display:flex;gap:8px;">
                <button class="btn btn-danger" onclick="restoreVersion(${version})">↩️ Restore This Version</button>
                <button class="btn btn-secondary" onclick="closeDiffModal()">Close</button>
            </div>
        `;
    });
}

function restoreVersion(version) {
    if (!confirm('⚠️ This will restore the book content to version v' + version + '. Current edits will be overwritten. Continue?')) return;
    const formData = new FormData();
    formData.append('action', 'restore_version');
    formData.append('book_id', <?php echo $book_id; ?>);
    formData.append('version', version);
    document.getElementById('diffContent').innerHTML = '<p style="text-align:center;color:#999;">⏳ Restoring to version v' + version + '...</p>';
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            tinymce.get('editor').setContent(data.content);
            document.getElementById('diffContent').innerHTML = '<div style="padding:20px;text-align:center;color:#28a745;">✅ Restored to version v' + version + ' successfully!</div>';
            setTimeout(() => {
                closeDiffModal();
                loadVersionHistory();
            }, 1500);
        } else {
            document.getElementById('diffContent').innerHTML = '<p style="color:red;">❌ Failed to restore: ' + (data.error || 'Unknown error') + '</p>';
        }
    });
}

function sendFullHistoryViaEmail() {
    const historyContent = document.getElementById('version-history-container').innerHTML;
    const email = prompt('Enter email address to send full history to:');
    if (!email || !email.includes('@')) {
        alert('Please enter a valid email address.');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'send_full_history_email');
    formData.append('book_id', <?php echo $book_id; ?>);
    formData.append('email', email);
    formData.append('history_content', historyContent);
    fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data.success) {
            alert('✅ Full history sent to ' + email + '!');
        } else {
            alert('❌ Failed to send: ' + (data.error || 'Unknown error'));
        }
    });
}

function exportFullHistory() {
    const container = document.getElementById('version-history-container');
    const historyContent = container.innerHTML;
    const blob = new Blob([generateFullHistoryReport(historyContent, <?php echo $book_id; ?>)], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'full_version_history.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function generateFullHistoryReport(content, bookId) {
    return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Full Version History</title><style>
        body { font-family: Inter, sans-serif; max-width: 1000px; margin: 40px auto; padding: 20px; }
        h1 { color: #c0392b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 12px; border-bottom: 1px solid #ddd; }
        th { background: #f1f3f5; }
    </style></head><body>
        <h1>📜 Full Version History</h1>
        <p>Generated: ${new Date().toLocaleString()}</p>
        <p>Book ID: ${bookId}</p>
        ${content}
        <p style="margin-top:30px;color:#999;text-align:center;">AngelWrites</p>
    </body></html>`;
}

// Load history on page load
document.addEventListener('DOMContentLoaded', loadVersionHistory);

// Close modal on outside click
document.getElementById('diffModal').addEventListener('click', function(e) {
    if (e.target === this) closeDiffModal();
});
</script>

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

/* Diff CSS */
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
.diff-line.diff-add.pattern { background: repeating-linear-gradient(45deg, #d4edda, #d4edda 10px, #b8dfc8 10px, #b8dfc8 20px); border-left: 4px solid #28a745; }
.diff-line.diff-remove.pattern { background: repeating-linear-gradient(45deg, #f8d7da, #f8d7da 10px, #e8b4b8 10px, #e8b4b8 20px); border-left: 4px solid #dc3545; }
.diff-line.diff-change.pattern { background: repeating-linear-gradient(45deg, #fff3cd, #fff3cd 10px, #f0e4b0 10px, #f0e4b0 20px); border-left: 4px solid #ffc107; }
.diff-line.diff-same.pattern { background: #f8f9fa; border-left: 4px solid #6c757d; }
.page-break-marker { color: #c0392b; font-weight: bold; }

.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; overflow: auto; }
.modal-content { background: #fff; margin: 5% auto; max-width: 900px; padding: 24px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
</style>

<?php require_once '../includes/footer.php'; ?>