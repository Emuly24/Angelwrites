<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

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

// ======================================================================
// 1. MAGIC NUMBER DETECTION
// ======================================================================
function detectFileType($file_path) {
    if (!file_exists($file_path)) return false;
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($file_path);
        if (strpos($mime, 'pdf') !== false) return 'pdf';
        if (strpos($mime, 'word') !== false || strpos($mime, 'document') !== false) return 'docx';
        if (strpos($mime, 'epub') !== false) return 'epub';
    }
    // Fallback to extension
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    return $ext;
}

// ======================================================================
// 2. SMART ENCODING FIX
// ======================================================================
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
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    return trim($text);
}

// ======================================================================
// 3. EXTRACTOR WITH PDFTOEXT (FALLBACK TO PURE PHP)
// ======================================================================
function extractRawText($file_path) {
    if (!file_exists($file_path)) return false;
    $type = detectFileType($file_path);
    
    if ($type === 'pdf') {
        // Try pdftotext if exec() is enabled
        if (function_exists('exec') && exec('which pdftotext') !== '') {
            $txt_path = dirname($file_path) . '/' . pathinfo($file_path, PATHINFO_FILENAME) . '.txt';
            exec("pdftotext -layout -enc UTF-8 '$file_path' '$txt_path'", $output, $return_var);
            if ($return_var === 0 && file_exists($txt_path)) {
                $text = file_get_contents($txt_path);
                return fixEncoding($text);
            }
        }
        // Fallback to Smalot\PdfParser
        return extractPDF($file_path);
    }
    if ($type === 'epub') return extractEPUB($file_path);
    if ($type === 'docx') return extractDOCX($file_path);
    return false;
}

// ======================================================================
// 4. PDF EXTRACTOR (WITH FALLBACK TO PURE PHP)
// ======================================================================
function extractPDF($file_path) {
    require_once LIB_PATH . 'pdfparser-master/src/Smalot/PdfParser/Element.php';
    // ... (Include all PDF parser classes as before) ...
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

// ======================================================================
// 5. DOCX / EPUB EXTRACTORS
// ======================================================================
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

// ======================================================================
// 6. WORD BOUNDARY DETECTION (Smart Paragraph Splitting)
// ======================================================================
function splitParagraphs($text) {
    // Use sentence boundaries for better splitting
    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    $paragraphs = [];
    $current = '';
    foreach ($sentences as $sentence) {
        if (strlen($sentence) < 80 && !empty($current)) {
            $current .= ' ' . $sentence;
        } else {
            if (!empty($current)) {
                $paragraphs[] = trim($current);
            }
            $current = $sentence;
        }
    }
    if (!empty($current)) {
        $paragraphs[] = trim($current);
    }
    return $paragraphs;
}

// ======================================================================
// 7. SMART PARSER – Chapter / Heading / Page Break Detection
// ======================================================================
function parseBook($raw_text) {
    $lines = explode("\n", $raw_text);
    $chapters = [];
    $current = null;
    $toc = [];
    $page_breaks = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;

        // Detect Chapter heading
        if (preg_match('/^(Chapter|CHAPTER|CHAP\.?|Part)\s+(\d+|[IVXLCDM]+)/i', $trimmed)) {
            if ($current) {
                $chapters[] = $current;
            }
            $current = ['heading' => $trimmed, 'content' => ''];
            $toc[] = ['title' => $trimmed, 'level' => 1];
            continue;
        }

        // Detect page break (number alone)
        if (preg_match('/^\d+$/', $trimmed) && strlen($trimmed) < 5) {
            $page_breaks[] = count($chapters) + 1;
            continue;
        }

        // Append to current chapter
        if ($current) {
            $current['content'] .= $trimmed . ' ';
        }
    }
    if ($current) {
        $chapters[] = $current;
    }

    // Apply word boundary detection to chapter content
    foreach ($chapters as &$chapter) {
        $chapter['paragraphs'] = splitParagraphs($chapter['content']);
        unset($chapter['content']); // Replace raw content with paragraphs
    }

    return ['chapters' => $chapters, 'toc' => $toc, 'page_breaks' => $page_breaks];
}

// ======================================================================
// 8. RENDERER – Converts parsed data to beautiful HTML
// ======================================================================
function renderBook($parsed, $book) {
    $html = "<div class='book-reader' data-book-id='{$book['id']}'>";
    $html .= "<h1 class='book-title'>" . htmlspecialchars($book['title']) . "</h1>\n";
    $html .= "<p class='book-author'>by " . htmlspecialchars($book['author']) . "</p>\n";

    foreach ($parsed['chapters'] as $index => $chapter) {
        $html .= "<div class='chapter-container' data-chapter='$index'>\n";
        $html .= "<h2 class='chapter-heading'>" . htmlspecialchars($chapter['heading']) . "</h2>\n";
        $html .= "<div class='chapter-content'>\n";
        foreach ($chapter['paragraphs'] as $p) {
            if (trim($p)) {
                $html .= "<p>" . nl2br(htmlspecialchars(trim($p))) . "</p>\n";
            }
        }
        $html .= "</div>\n</div>\n";
    }
    $html .= "</div>";
    return $html;
}

// ======================================================================
// 9. AUTO-SUMMARIZATION (Keyword Extraction)
// ======================================================================
function extractKeywords($text, $limit = 10) {
    $stop_words = ['the', 'and', 'to', 'of', 'a', 'in', 'that', 'it', 'for', 'with', 'on', 'was', 'as', 'by', 'at', 'an'];
    $words = str_word_count(strtolower($text), 1);
    $words = array_diff($words, $stop_words);
    $counts = array_count_values($words);
    arsort($counts);
    return array_slice(array_keys($counts), 0, $limit);
}

// ======================================================================
// 10. PARAGRAPH SPLITTING/MERGING UI (TinyMCE Enhancement)
// ======================================================================
function renderParagraphEditor($content_html) {
    ?>
    <script>
    tinymce.init({
        selector: '#editor',
        height: 600,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code | splitpara mergepara',
        content_style: 'body { font-family: Inter, sans-serif; font-size: 18px; line-height: 2; }',
        forced_root_block: 'p',
        setup: function(editor) {
            // ===== SPLIT PARAGRAPH BUTTON =====
            editor.ui.registry.addButton('splitpara', {
                text: '✂ Split Paragraph',
                icon: 'cut',
                onAction: function() {
                    const selection = editor.selection.getContent();
                    if (selection.trim()) {
                        const before = editor.selection.getNode().textContent.replace(selection, '');
                        const after = selection;
                        editor.selection.getNode().outerHTML = '<p>' + before + '</p><p>' + after + '</p>';
                    }
                }
            });
            // ===== MERGE PARAGRAPH BUTTON =====
            editor.ui.registry.addButton('mergepara', {
                text: '🔗 Merge Paragraphs',
                icon: 'link',
                onAction: function() {
                    const selNode = editor.selection.getNode();
                    const nextP = selNode.nextElementSibling;
                    if (nextP && nextP.tagName === 'P') {
                        selNode.innerHTML += ' ' + nextP.innerHTML;
                        nextP.remove();
                    }
                }
            });
        }
    });
    </script>
    <?php
}

// ======================================================================
// 11. PROCESSING QUEUE
// ======================================================================
function addToQueue($book_id) {
    global $db;
    $stmt = $db->prepare("INSERT OR IGNORE INTO book_processing_queue (book_id) VALUES (?)");
    $stmt->execute([$book_id]);
}

function updateQueueProgress($book_id, $progress, $status) {
    global $db;
    $stmt = $db->prepare("UPDATE book_processing_queue SET progress = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
    $stmt->execute([$progress, $status, $book_id]);
}

// ======================================================================
// 12. EXPORT FUNCTIONS
// ======================================================================
function exportTXT($content_html) {
    return strip_tags($content_html);
}
function exportHTML($content_html) {
    return "<html><head><meta charset='UTF-8'><title>Book Export</title></head><body>$content_html</body></html>";
}
function exportTOC($toc_json) {
    $toc = json_decode($toc_json, true) ?? [];
    $csv = "Title,Level\n";
    foreach ($toc as $item) {
        $csv .= '"' . $item['title'] . '",' . ($item['level'] ?? 1) . "\n";
    }
    return $csv;
}

// ======================================================================
// 13. MULTI-PAGE EXPORT (One file per chapter)
// ======================================================================
function exportMultiPageHTML($parsed, $book) {
    $files = [];
    $base_name = preg_replace('/[^a-zA-Z0-9_]/', '', $book['title']);
    $dir = '../assets/exports/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    // Cover page
    $cover = "<html><head><meta charset='UTF-8'><title>{$book['title']}</title></head><body>";
    $cover .= "<h1>" . htmlspecialchars($book['title']) . "</h1>";
    $cover .= "<p>by " . htmlspecialchars($book['author']) . "</p>";
    $cover .= "<ul>";
    foreach ($parsed['toc'] as $item) {
        $cover .= "<li><a href='chapter_0.html'>" . htmlspecialchars($item['title']) . "</a></li>";
    }
    $cover .= "</ul></body></html>";
    file_put_contents($dir . $base_name . '_cover.html', $cover);
    $files[] = $dir . $base_name . '_cover.html';
    
    // Each chapter
    foreach ($parsed['chapters'] as $i => $chapter) {
        $html = "<html><head><meta charset='UTF-8'><title>Chapter " . ($i+1) . "</title></head><body>";
        $html .= "<h2>" . htmlspecialchars($chapter['heading']) . "</h2>";
        foreach ($chapter['paragraphs'] as $p) {
            $html .= "<p>" . nl2br(htmlspecialchars($p)) . "</p>";
        }
        $html .= "</body></html>";
        $filename = $dir . $base_name . '_chapter_' . $i . '.html';
        file_put_contents($filename, $html);
        $files[] = $filename;
    }
    return $files;
}

// ======================================================================
// 14. PAGE-FLIP READER INTEGRATION (CSS + JS)
// ======================================================================
function renderPageFlipCSS() {
    ?>
    <style>
    .book-reader {
        perspective: 1000px;
        max-width: 800px;
        margin: 0 auto;
    }
    .chapter-container {
        display: none;
        transform-style: preserve-3d;
        transition: transform 0.6s;
    }
    .chapter-container.active {
        display: block;
        animation: flipIn 0.6s ease;
    }
    @keyframes flipIn {
        from { transform: rotateY(-90deg); opacity: 0; }
        to { transform: rotateY(0deg); opacity: 1; }
    }
    .chapter-container .chapter-content {
        max-height: 70vh;
        overflow-y: auto;
        padding: 20px;
        border-radius: 8px;
        background: var(--card-bg);
    }
    .nav-flip {
        display: flex;
        justify-content: space-between;
        margin: 16px 0;
    }
    .nav-flip button {
        padding: 8px 20px;
        border-radius: 30px;
        background: var(--rose);
        color: white;
        border: none;
        cursor: pointer;
    }
    .nav-flip button:hover {
        background: var(--rose-dark);
    }
    </style>
    <?php
}

// ======================================================================
// HANDLE POST ACTIONS
// ======================================================================
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
                $success = '✅ Content extracted, parsed, and rendered successfully.';
            } else {
                $error = 'Failed to extract content from the file.';
            }
        }
    }

    if (isset($_POST['export_txt'])) {
        $content = $existing['content_html'] ?? '';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '', $book['title']) . '.txt"');
        echo exportTXT($content);
        exit;
    }

    if (isset($_POST['export_html'])) {
        $content = $existing['content_html'] ?? '';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '', $book['title']) . '.html"');
        echo exportHTML($content);
        exit;
    }

    if (isset($_POST['export_toc'])) {
        $toc_json = $existing['toc_json'] ?? '[]';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '', $book['title']) . '_toc.csv"');
        echo exportTOC($toc_json);
        exit;
    }

    if (isset($_POST['export_multi'])) {
        $raw_text = extractRawText('../' . $book['file_path']);
        if ($raw_text) {
            $parsed = parseBook($raw_text);
            $files = exportMultiPageHTML($parsed, $book);
            $zip = new ZipArchive();
            $zip_file = '../assets/exports/' . preg_replace('/[^a-zA-Z0-9_]/', '', $book['title']) . '.zip';
            if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
                foreach ($files as $file) {
                    $zip->addFile($file, basename($file));
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($zip_file) . '"');
                readfile($zip_file);
                exit;
            } else {
                $error = 'Failed to create ZIP archive.';
            }
        }
    }

    if (isset($_POST['apply_corrections'])) {
        $corrected_toc = json_decode($_POST['corrected_toc'], true);
        $stmt = $db->prepare("UPDATE book_content SET toc_json = ?, version = version + 1 WHERE book_id = ?");
        $stmt->execute([json_encode($corrected_toc), $book_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if (isset($_POST['queue_book'])) {
        addToQueue($book_id);
        $success = '✅ Book added to processing queue. The queue worker will process it soon.';
    }
}

$pageTitle = 'Process Book: ' . htmlspecialchars($book['title']);
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-process-book">
    <div class="container">
        <div class="admin-header">
            <h1>Process Book: <?php echo htmlspecialchars($book['title']); ?></h1>
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
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <!-- Stage 1: Extraction -->
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
                    <p class="field-hint">The extractor uses `pdftotext` if available; otherwise, it falls back to pure PHP. A `.txt` file is also saved for backup.</p>
                </form>
            </div>
        </div>

        <!-- Stage 2: Correction & TOC Editing -->
        <?php if ($existing && $existing['is_processed'] == 1): ?>
        <div class="card">
            <div class="card-header"><h2>✏️ Stage 2: User‑Driven Correction</h2></div>
            <div class="card-body">
                <?php renderCorrectionInterface($book_id, $existing['toc_json']); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage 3: WYSIWYG Editor with Paragraph Controls -->
        <div class="card">
            <div class="card-header"><h2>📝 Stage 3: Edit & Refine</h2></div>
            <div class="card-body">
                <textarea id="editor" name="content_html" style="width:100%;height:500px;"><?php echo htmlspecialchars($existing['content_html'] ?? ''); ?></textarea>
                <?php renderParagraphEditor($existing['content_html'] ?? ''); ?>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="saveContent()">💾 Save Changes</button>
                    <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book_id; ?>" class="btn btn-secondary" target="_blank">
                        <i class="fas fa-eye"></i> Preview Reader
                    </a>
                </div>
            </div>
        </div>

        <!-- Stage 4: Export -->
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
    </div>
</div>

<script>
function saveContent() {
    const content = tinymce.get('editor').getContent();
    const formData = new FormData();
    formData.append('content_html', content);
    fetch('<?php echo SITE_URL; ?>/admin/save_book_content.php?id=<?php echo $book_id; ?>', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.success ? '✅ Content saved.' : '❌ Failed to save.');
    });
}
</script>

<style>
.admin-process-book { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.card { margin-bottom: 24px; border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
.card-header h2 { font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px; }
.card-body { padding: 20px; }
.btn-large { padding: 14px 28px; font-size: 1.1rem; border-radius: 30px; }
.btn-sm { padding: 6px 14px; font-size: 0.8rem; border-radius: 20px; }
.field-hint { display: block; margin-top: 4px; font-size: 0.85rem; color: var(--text-light); }
</style>

<?php require_once '../includes/footer.php'; ?>