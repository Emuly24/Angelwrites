<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch book
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header('Location: ' . SITE_URL . '/admin/manage_books.php');
    exit;
}

// Check if book already has processed content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$existing_content = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== REAL EXTRACTION ENGINE =====

function extract_pdf($file_path) {
    // Use smalot/pdfparser
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($file_path);
    $text = $pdf->getText();
    // Convert plain text to simple HTML (headings, paragraphs)
    $lines = explode("\n", $text);
    $html = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;
        // Guess heading (all caps or short line)
        if (strtoupper($trimmed) === $trimmed && strlen($trimmed) < 100) {
            $html .= "<h2>$trimmed</h2>";
        } else {
            $html .= "<p>$trimmed</p>";
        }
    }
    return $html;
}

function extract_docx($file_path) {
    // Use PHPSpreadsheet for DOCX parsing
    $phpWord = \PhpOffice\PhpWord\IOFactory::load($file_path);
    $html = '';
    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                $text = '';
                foreach ($element->getElements() as $textElement) {
                    if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                        $text .= $textElement->getText();
                    }
                }
                $html .= "<p>$text</p>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Title) {
                $level = $element->getDepth();
                $text = $element->getText();
                $tag = $level == 1 ? 'h1' : ($level == 2 ? 'h2' : 'h3');
                $html .= "<$tag>$text</$tag>";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $html .= "<p>" . $element->getText() . "</p>";
            }
        }
    }
    return $html;
}

function extract_epub($file_path) {
    // EPUB is a ZIP file with HTML/XHTML inside
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== TRUE) {
        return false;
    }
    $html_content = '';
    // Find the OPF file (package document)
    $opf_path = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, '.opf') !== false) {
            $opf_path = $name;
            break;
        }
    }
    if (!$opf_path) {
        $zip->close();
        return false;
    }
    // Read the OPF to find the spine (reading order)
    $opf_xml = $zip->getFromName($opf_path);
    $opf = simplexml_load_string($opf_xml);
    $ns = $opf->getNamespaces(true);
    $opf->registerXPathNamespace('opf', $ns[''] ?? 'http://www.idpf.org/2007/opf');
    // Find all manifest items with media-type="application/xhtml+xml"
    $items = $opf->xpath('//opf:manifest/opf:item[@media-type="application/xhtml+xml"]');
    $base_dir = dirname($opf_path) . '/';
    foreach ($items as $item) {
        $href = (string)$item['href'];
        $full_path = $base_dir . $href;
        $content = $zip->getFromName($full_path);
        if ($content) {
            $dom = new DOMDocument();
            @$dom->loadHTML($content);
            $xpath = new DOMXPath($dom);
            // Extract headings and paragraphs
            $nodes = $xpath->query('//h1 | //h2 | //h3 | //p');
            foreach ($nodes as $node) {
                $html_content .= $dom->saveHTML($node);
            }
        }
    }
    $zip->close();
    return $html_content;
}

// ===== HANDLE EXTRACT CONTENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extract_content'])) {
    $file_path = '../' . $book['file_path'];
    if (!file_exists($file_path)) {
        $error = 'Book file not found. Please upload a file first.';
    } else {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $extracted_html = '';
        try {
            switch ($ext) {
                case 'pdf':
                    $extracted_html = extract_pdf($file_path);
                    break;
                case 'docx':
                    $extracted_html = extract_docx($file_path);
                    break;
                case 'epub':
                    $extracted_html = extract_epub($file_path);
                    break;
                default:
                    $error = "File type '$ext' is not supported for extraction.";
                    break;
            }
        } catch (Exception $e) {
            $error = 'Extraction error: ' . $e->getMessage();
        }

        if (empty($error) && !empty($extracted_html)) {
            // Generate a simple TOC from h1, h2, h3 headings in the extracted HTML
            $dom = new DOMDocument();
            @$dom->loadHTML($extracted_html);
            $xpath = new DOMXPath($dom);
            $headings = $xpath->query('//h1 | //h2 | //h3');
            $toc = [];
            $counter = 0;
            foreach ($headings as $h) {
                $counter++;
                $id = 'heading-' . $counter;
                $h->setAttribute('id', $id);
                $level = (int)substr($h->tagName, 1);
                $toc[] = ['id' => $id, 'title' => $h->textContent, 'level' => $level];
            }
            $updated_html = $dom->saveHTML();
            $toc_json = json_encode($toc);

            if ($existing_content) {
                $stmt = $db->prepare("UPDATE book_content SET content_html = ?, toc_json = ?, is_processed = 0 WHERE book_id = ?");
                $stmt->execute([$updated_html, $toc_json, $book_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO book_content (book_id, title, content_html, toc_json, is_processed) VALUES (?, ?, ?, ?, 0)");
                $stmt->execute([$book_id, $book['title'], $updated_html, $toc_json]);
            }
            $success = 'Content extracted successfully. Please review and edit below.';
            header('Location: ' . SITE_URL . '/admin/process_book.php?id=' . $book_id);
            exit;
        } elseif (empty($error)) {
            $error = 'No content could be extracted from the file.';
        }
    }
}

// ===== HANDLE SAVE & PUBLISH =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_processed'])) {
    $content_html = trim($_POST['content_html']);
    $toc_json = trim($_POST['toc_json']);
    $is_angella_book = isset($_POST['is_angella_book']) ? 1 : 0;

    if (empty($content_html)) {
        $error = 'Content cannot be empty.';
    } else {
        if ($existing_content) {
            $stmt = $db->prepare("UPDATE book_content SET content_html = ?, toc_json = ?, is_angella_book = ?, is_processed = 1, updated_at = CURRENT_TIMESTAMP WHERE book_id = ?");
            $stmt->execute([$content_html, $toc_json, $is_angella_book, $book_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO book_content (book_id, title, content_html, toc_json, is_angella_book, is_processed) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$book_id, $book['title'], $content_html, $toc_json, $is_angella_book]);
        }
        $success = 'Book processed and published successfully!';
        header('Location: ' . SITE_URL . '/admin/manage_books.php');
        exit;
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

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="process-actions">
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="extract_content" class="btn btn-primary btn-large">
                            <i class="fas fa-magic"></i> Extract Content from File
                        </button>
                    </form>
                    <button onclick="window.open('/reader.php?id=<?php echo $book_id; ?>', '_blank')" class="btn btn-secondary btn-large">
                        <i class="fas fa-eye"></i> Preview in Reader
                    </button>
                </div>
                <p class="field-hint">Extract the content from the uploaded book file (PDF/EPUB/DOCX). The system will attempt to detect headings and structure.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Advanced Editor</h2>
            </div>
            <div class="card-body">
                <form method="POST" id="processForm">
                    <input type="hidden" name="save_processed" value="1">
                    <div class="form-group">
                        <label for="advancedEditor">Book Content (HTML)</label>
                        <textarea id="advancedEditor" name="content_html" rows="20"><?php echo htmlspecialchars($existing_content['content_html'] ?? ''); ?></textarea>
                        <small>Use the editor below to format headings, insert images, and create the table of contents.</small>
                    </div>
                    <div class="form-group">
                        <label for="toc_json">Table of Contents (JSON)</label>
                        <textarea name="toc_json" id="toc_json" rows="4"><?php echo htmlspecialchars($existing_content['toc_json'] ?? '[]'); ?></textarea>
                        <small>JSON array: [{"id":"ch1","title":"Chapter 1","level":1}]</small>
                    </div>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_angella_book" <?php echo ($existing_content['is_angella_book'] ?? 1) ? 'checked' : ''; ?>>
                            <span>This is Angella's original work (public)</span>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-large">
                            <i class="fas fa-save"></i> Save & Publish
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js"></script>
<script>
    tinymce.init({
        selector: '#advancedEditor',
        height: 600,
        menubar: true,
        plugins: 'anchor autolink charmap codesample emoticons image imagetools link lists media searchreplace table visualblocks wordcount code',
        toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image media | table | code',
        content_style: 'body { font-family: Inter, sans-serif; font-size: 16px; line-height: 1.8; }',
        forced_root_block: 'p',
        init_instance_callback: function(editor) {
            const existingContent = <?php echo json_encode($existing_content['content_html'] ?? ''); ?>;
            if (existingContent) {
                editor.setContent(existingContent);
            }
        }
    });
</script>

<style>
    .admin-process-book { padding: 32px 0 60px; }
    .admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
    .admin-header h1 { font-size: 2rem; margin: 0; }
    .admin-actions { display: flex; gap: 12px; }
    
    .process-actions { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
    .btn-large { padding: 14px 28px; font-size: 1.1rem; }
    
    .admin-form .form-group { margin-bottom: 16px; }
    .admin-form label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
    .admin-form textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
    .checkbox-group { margin: 16px 0; }
    .form-actions { display: flex; gap: 12px; margin-top: 16px; }
    .field-hint { display: block; margin-top: 4px; font-size: 0.85rem; color: var(--text-light); }
</style>

<?php require_once '../includes/footer.php'; ?>