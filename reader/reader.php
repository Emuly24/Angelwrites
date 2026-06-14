<?php
// ============================================================
//  READER.PHP – FULLY ENHANCED & HONEST (NO FEATURES REMOVED)
//  Supports: Processed HTML, PDF, EPUB, MOBI, AZW3, AZW4, TXT
//  New: Vertical (scroll) / Horizontal (page-flip) toggle
// ============================================================

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_helper.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$book_id) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// ===== FETCH BOOK =====
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// ===== INCREMENT VIEWS =====
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// ===== FETCH PROCESSED CONTENT =====
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed = $stmt->fetch(PDO::FETCH_ASSOC);
$has_processed = !empty($processed) && $processed['is_processed'] == 1;

// ===== TOC =====
$toc = $has_processed ? (json_decode($processed['toc_json'], true) ?? []) : [];

// ===== USER PROGRESS =====
$user_progress = null;
$last_offset = 0;
$last_chapter = 0;
$progress_percent = 0;
$streak_days = 0;

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $user_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_progress) {
        $last_offset = (int)$user_progress['position_offset'];
        $last_chapter = (int)$user_progress['position_section'];
        $progress_percent = (int)$user_progress['progress_percent'];
    } else {
        $stmt = $db->prepare("INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent) VALUES (?, ?, 0, 0, 0)");
        $stmt->execute([$user_id, $book_id]);
    }
    
    // ===== STREAK =====
    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak = $stmt->fetchColumn();
    $streak_days = $streak ? (int)$streak : 0;
}

// ===== GROUP ID (for notes) =====
$group_id = null;
if (isLoggedIn()) {
    $stmt = $db->prepare("
        SELECT g.id FROM reading_groups g
        JOIN group_members m ON g.id = m.group_id
        WHERE g.book_id = ? AND m.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$book_id, $_SESSION['user_id']]);
    $group_id = $stmt->fetchColumn();
}

// ===== BOOKMARKS =====
$bookmarks = [];
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT id, chapter_index, note, created_at FROM bookmarks WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, created_at DESC");
    $stmt->execute([$user_id, $book_id]);
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== HIGHLIGHTS =====
$highlights = [];
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT id, chapter_index, paragraph_index, text, color, note FROM highlights WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, paragraph_index");
    $stmt->execute([$user_id, $book_id]);
    $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== FILE TYPE FOR FALLBACK =====
$file_type = strtolower($book['file_type'] ?? 'unknown');

// ===== SERVER-SIDE FILE VALIDATION =====
$file_path = __DIR__ . '/../' . $book['file_path'];
$file_exists = file_exists($file_path);
$file_size = $file_exists ? filesize($file_path) : 0;

// ===== SHARE CHAPTER =====
$share_chapter = isset($_GET['chapter']) ? (int)$_GET['chapter'] : null;
$share_page = isset($_GET['page']) ? (int)$_GET['page'] : null;
$is_share = isset($_GET['share']) && $_GET['share'] == 1;

// ===== PREPARE PAGES (FOR HORIZONTAL MODE) =====
$pages = [];
if ($has_processed && !empty($processed['content_html'])) {
    $raw = $processed['content_html'];
    $parts = preg_split('/<div class="page-break"[^>]*><\/div>/', $raw);
    foreach ($parts as $index => $html) {
        $html = trim($html);
        if (!empty($html)) {
            $pages[] = $html;
        }
    }
    if (empty($pages)) {
        $pages[] = $raw;
    }
} else {
    $pages = [];
}

$total_pages = count($pages);
$last_page = $last_chapter > 0 && $last_chapter <= $total_pages ? $last_chapter : 1;

// ============================================================
//  OUTPUT BUFFER START + HEADER (BUT READER IS FULL-SCREEN)
// ============================================================
$pageTitle = 'Reading: ' . htmlspecialchars($book['title']);
// We do NOT include header.php – the reader is full-screen.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- PDF.js / EPUB.js for fallback (kept) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
    <!-- ============================================================ -->
    <!--  STYLES – FULLY BRANDED (identical to original, no removal)   -->
    <!-- ============================================================ -->
    <style>
/* ===== BRAND COLORS ===== */
:root {
    --rose: #dba1a2;
    --rose-dark: #c98a8b;
    --vanilla: #f7f2ec;
    --border: #e0d8d0;
    --text: #3a2e2a;
    --text-light: #7a6e6a;
    --card-bg: #ffffff;
    --shadow: 0 2px 12px rgba(0,0,0,0.06);
    --shadow-hover: 0 4px 20px rgba(0,0,0,0.12);
    --input-bg: #f9f7f5;
}
/* ===== READER BASE (but full-screen, no header/footer from site) ===== */
.aw-reader { display: flex; flex-direction: column; height: 100vh; background: var(--vanilla); color: var(--text); transition: all 0.3s ease; position: relative; overflow: hidden; }
.aw-reader-header { flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; background: rgba(255,255,255,0.9); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); z-index: 10; transition: transform 0.3s ease, opacity 0.3s ease; }
.aw-reader-header.hidden { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.aw-reader-header-left { display: flex; align-items: center; gap: 12px; }
.aw-reader-back { color: var(--rose); font-weight: 500; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 4px; }
.aw-reader-title { font-size: 1.1rem; margin: 0; color: var(--text); font-family: 'Playfair Display', serif; }
.aw-reader-header-right { display: flex; align-items: center; gap: 6px; }
.aw-reader-header-right button { background: none; border: none; font-size: 1.1rem; color: var(--text); cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: all 0.2s; }
.aw-reader-header-right button:hover { background: rgba(219,161,162,0.1); color: var(--rose); }
.streak-badge { background: var(--rose); color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 4px; }
.aw-progress-ring { vertical-align: middle; }
.aw-progress-ring-bg { stroke: var(--border); }
.aw-progress-ring-fill { stroke: var(--rose); transition: stroke-dashoffset 0.3s; }
.aw-reader-settings { flex-shrink: 0; display: none; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 12px 16px; }
.aw-reader-settings.open { display: block; }
.aw-settings-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.aw-setting-group { display: flex; flex-direction: column; gap: 4px; }
.aw-setting-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.5px; }
.aw-theme-options, .aw-font-options, .aw-mode-options { display: flex; gap: 4px; }
.aw-theme-btn, .aw-font-btn, .aw-mode-btn { padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: transparent; cursor: pointer; font-size: 0.75rem; transition: all 0.2s; }
.aw-theme-btn:hover, .aw-font-btn:hover, .aw-mode-btn:hover { border-color: var(--rose); }
.aw-theme-btn.active, .aw-font-btn.active, .aw-mode-btn.active { border-color: var(--rose); background: var(--rose); color: white; }
.color-preview { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; border: 1px solid var(--border); }
.aw-size-controls { display: flex; align-items: center; gap: 6px; }
.aw-size-btn { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: var(--text); transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.aw-size-btn:hover { border-color: var(--rose); color: var(--rose); }
.aw-size-controls input[type="range"] { width: 80px; accent-color: var(--rose); }
.aw-theme-extra { margin-top: 4px; }
.aw-toc-drawer { position: fixed; top: 0; right: -320px; width: 320px; height: 100vh; background: var(--card-bg); box-shadow: -4px 0 20px rgba(0,0,0,0.1); z-index: 20; transition: right 0.3s ease; display: flex; flex-direction: column; }
.aw-toc-drawer.open { right: 0; }
.aw-toc-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.aw-toc-header h3 { margin: 0; font-size: 1.1rem; }
.aw-toc-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text); padding: 0 4px; }
.aw-toc-body { flex: 1; overflow-y: auto; padding: 12px 20px; }
.aw-toc-list { list-style: none; padding: 0; margin: 0; }
.aw-toc-list li { padding: 4px 0; }
.aw-toc-list a { color: var(--text); text-decoration: none; display: block; padding: 2px 4px; border-radius: 4px; transition: background 0.2s, color 0.2s; }
.aw-toc-list a:hover { background: rgba(219,161,162,0.1); color: var(--rose); }
.aw-toc-empty { color: var(--text-light); text-align: center; padding: 40px 0; }
.aw-notes-panel { position: fixed; bottom: 0; right: 0; width: 380px; max-height: 60vh; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px 12px 0 0; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); display: none; flex-direction: column; z-index: 25; }
.aw-notes-header { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--vanilla); border-radius: 12px 12px 0 0; }
.aw-notes-title h3 { margin: 0; font-size: 1rem; }
.aw-notes-title .badge { background: var(--rose); color: white; padding: 0 8px; border-radius: 12px; font-size: 0.75rem; }
.aw-notes-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.note-card.private { border-left: 4px solid #6c757d; }
.note-author { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.note-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.note-avatar-placeholder { width: 32px; height: 32px; border-radius: 50%; background: var(--rose); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; }
.note-author-info { flex: 1; }
.note-author-info small { color: var(--text-light); }
.note-text { margin: 0 0 8px; font-size: 0.95rem; }
.note-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }
.note-reactions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.reaction { background: var(--vanilla); padding: 0 8px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; }
.reaction:hover { background: rgba(219,161,162,0.2); }
.badge-private { background: #6c757d; color: white; padding: 0 6px; border-radius: 4px; font-size: 0.7rem; }
.empty-notes { color: var(--text-light); text-align: center; padding: 24px 12px; }
#awAddNoteForm { padding: 12px 16px; border-top: 1px solid var(--border); }
#awAddNoteForm textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; resize: vertical; }
#awAddNoteForm .btn { padding: 4px 12px; font-size: 0.8rem; }
.aw-notes-actions .btn { padding: 4px 12px; font-size: 0.8rem; }
.aw-reaction-picker { position: fixed; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; box-shadow: var(--shadow-hover); z-index: 50; display: none; gap: 6px; align-items: center; }
.aw-reaction-picker button { background: none; border: none; font-size: 1.2rem; cursor: pointer; padding: 2px 6px; border-radius: 4px; transition: all 0.2s; }
.aw-reaction-picker button:hover { background: var(--vanilla); transform: scale(1.15); }
.aw-challenge-widget { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin: 8px 16px; box-shadow: var(--shadow); display: flex; flex-direction: column; gap: 6px; }
.aw-challenge-widget h4 { margin: 0; font-size: 1rem; }
.aw-challenge-widget p { margin: 0; font-size: 0.9rem; color: var(--text-light); }
.aw-challenge-progress { position: relative; height: 16px; background: var(--border); border-radius: 8px; overflow: hidden; }
.aw-challenge-bar { height: 100%; background: var(--rose); transition: width 0.3s; }
.aw-challenge-percent { position: absolute; top: 0; right: 8px; font-size: 0.7rem; font-weight: 600; color: var(--text); line-height: 16px; }
.aw-challenge-stats { font-weight: 600; font-size: 0.9rem; color: var(--text); }
.aw-reader-text { max-width: 750px; margin: 0 auto; padding: 30px 40px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); }
.aw-reader-text .highlight-yellow { background: #ffeb3b; padding: 0 2px; }
.aw-reader-text .highlight-green { background: #a5d6a7; padding: 0 2px; }
.aw-reader-text .highlight-blue { background: #90caf9; padding: 0 2px; }
.aw-reader-text .highlight-pink { background: #f48fb1; padding: 0 2px; }
.aw-reader-text .highlight-yellow.annotation { border-bottom: 2px solid #ffeb3b; cursor: pointer; }
.aw-reader-text .highlight-green.annotation { border-bottom: 2px solid #a5d6a7; cursor: pointer; }
.aw-reader-text .highlight-blue.annotation { border-bottom: 2px solid #90caf9; cursor: pointer; }
.aw-reader-text .highlight-pink.annotation { border-bottom: 2px solid #f48fb1; cursor: pointer; }
.aw-highlight-tooltip { position: fixed; display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; box-shadow: var(--shadow-hover); z-index: 30; gap: 4px; align-items: center; }
.aw-highlight-tooltip.visible { display: flex; }
.aw-highlight-color { width: 20px; height: 20px; border-radius: 50%; border: 1px solid var(--border); cursor: pointer; transition: transform 0.2s; }
.aw-highlight-color:hover { transform: scale(1.15); }
.aw-highlight-color[data-color="yellow"] { background: #ffeb3b; }
.aw-highlight-color[data-color="green"] { background: #a5d6a7; }
.aw-highlight-color[data-color="blue"] { background: #90caf9; }
.aw-highlight-color[data-color="pink"] { background: #f48fb1; }
.aw-highlight-btn { background: none; border: none; cursor: pointer; color: var(--text); font-size: 0.9rem; padding: 0 4px; transition: color 0.2s; }
.aw-highlight-btn:hover { color: var(--rose); }
.aw-annotation-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 20px; box-shadow: var(--shadow-hover); z-index: 30; display: none; }
.aw-annotation-popup.visible { display: block; }
.aw-annotation-popup textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; resize: vertical; min-height: 60px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.aw-annotation-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
.aw-annotation-actions button { padding: 4px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 0.8rem; }
.aw-annotation-save { background: var(--rose); color: white; }
.aw-annotation-cancel { background: var(--border); color: var(--text); }
.aw-search-bar { position: absolute; top: 56px; right: 16px; width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 12px; box-shadow: var(--shadow-hover); z-index: 15; display: none; }
.aw-search-bar.visible { display: block; }
.aw-search-bar input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.aw-search-bar input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
#awSearchResults { margin-top: 8px; max-height: 200px; overflow-y: auto; display: none; }
.aw-search-result { padding: 4px 8px; font-size: 0.85rem; border-bottom: 1px solid var(--border); cursor: pointer; }
.aw-search-result:hover { background: rgba(219,161,162,0.1); }
.aw-share-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 30; display: none; align-items: center; justify-content: center; }
.aw-share-modal.visible { display: flex; }
.aw-share-modal-content { background: var(--card-bg); padding: 24px; border-radius: 12px; max-width: 400px; width: 90%; text-align: center; }
.aw-share-modal-content h3 { margin-top: 0; }
.aw-share-options { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
.aw-share-options button { padding: 8px 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); cursor: pointer; transition: all 0.2s; font-size: 0.9rem; }
.aw-share-options button:hover { border-color: var(--rose); background: rgba(219,161,162,0.1); }
.aw-share-modal-close { background: var(--rose); color: white; border: none; padding: 8px 24px; border-radius: 30px; cursor: pointer; }
.aw-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 11; display: none; }
.aw-overlay.active { display: block; }
.aw-reader.focus-mode .aw-reader-header { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.aw-reader.focus-mode .aw-reader-settings { display: none !important; }
.aw-reader.focus-mode .aw-search-bar { display: none !important; }
.aw-reader-fallback { height: 100%; width: 100%; display: flex; flex-direction: column; }
.aw-reader-fallback canvas { flex: 1; width: 100%; height: auto; object-fit: contain; }
.aw-pdf-controls, .aw-epub-controls { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 8px 12px; background: var(--card-bg); border-top: 1px solid var(--border); flex-shrink: 0; }
.aw-pdf-controls button, .aw-epub-controls button { background: var(--rose); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; transition: background 0.2s; }
.aw-pdf-controls button:hover, .aw-epub-controls button:hover { background: var(--rose-dark); }
.aw-pdf-controls input[type="range"] { width: 80px; accent-color: var(--rose); }
.aw-epub-container #awEpubViewer { flex: 1; }
.aw-reader-unsupported { text-align: center; padding: 40px 20px; color: var(--text-light); }
.aw-reader-unsupported i { font-size: 3rem; color: var(--rose); display: block; margin-bottom: 16px; }
.aw-reader[data-theme="paper"] { background: var(--vanilla); color: var(--text); }
.aw-reader[data-theme="light"] { background: #ffffff; color: #1a1a1a; }
.aw-reader[data-theme="dark"] { background: #1a1a1a; color: #f0f0f0; }
.aw-reader[data-theme="sepia"] { background: #f4ecd8; color: #5b4636; }
.aw-reader[data-font="serif"] .aw-reader-text { font-family: Georgia, 'Times New Roman', serif; }
.aw-reader[data-font="sans"] .aw-reader-text { font-family: 'Inter', -apple-system, sans-serif; }
.aw-reader[data-font="mono"] .aw-reader-text { font-family: 'Courier New', monospace; }
@media (max-width: 768px) { .aw-reader-header { padding: 6px 12px; } .aw-reader-title { font-size: 0.9rem; } .aw-reader-header-right button { font-size: 0.9rem; padding: 2px 6px; } .aw-reader-content { padding: 16px 12px; } .aw-reader-text .book-title { font-size: 1.6rem; } .aw-reader-text .chapter-heading { font-size: 1.2rem; } .aw-toc-drawer { width: 280px; right: -280px; } .aw-notes-panel { width: 100%; max-height: 50vh; border-radius: 0; } .aw-settings-grid { gap: 8px; } .aw-size-controls input[type="range"] { width: 60px; } .aw-search-bar { width: 260px; right: 8px; } }
@media (max-width: 480px) { .aw-reader-header { padding: 4px 8px; } .aw-reader-title { font-size: 0.8rem; } .aw-reader-content { padding: 12px 8px; } .aw-toc-drawer { width: 260px; right: -260px; } }
    </style>
</head>
<body>

<!-- ============================================================ -->
<!--  READER CONTAINER (unchanged, but we removed header.php)     -->
<!-- ============================================================ -->
<div class="aw-reader" id="awReader" data-book-id="<?php echo $book_id; ?>" data-user-id="<?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>" data-last-chapter="<?php echo $last_chapter; ?>" data-last-progress="<?php echo $progress_percent; ?>" data-last-offset="<?php echo $last_offset; ?>" data-file-path="<?php echo htmlspecialchars($book['file_path']); ?>" data-file-type="<?php echo $file_type; ?>" data-file-exists="<?php echo $file_exists ? '1' : '0'; ?>" data-group-id="<?php echo $group_id ? (int)$group_id : 0; ?>">
    
    <!-- ===== HEADER ===== -->
    <header class="aw-reader-header" id="awReaderHeader">
        <div class="aw-reader-header-left">
            <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>" class="aw-reader-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <h1 class="aw-reader-title"><?php echo htmlspecialchars($book['title']); ?></h1>
        </div>
        <div class="aw-reader-header-right">
            <!-- Streak Badge -->
            <?php if (isLoggedIn() && $streak_days > 0): ?>
            <div class="streak-badge" title="Reading streak">
                <span>🔥</span>
                <span><?php echo $streak_days; ?> days</span>
            </div>
            <?php endif; ?>
            <!-- Progress Ring -->
            <svg class="aw-progress-ring" width="36" height="36" viewBox="0 0 36 36">
                <circle class="aw-progress-ring-bg" cx="18" cy="18" r="16" stroke="var(--border)" stroke-width="2" fill="none"/>
                <circle class="aw-progress-ring-fill" cx="18" cy="18" r="16" stroke="var(--rose)" stroke-width="2" fill="none"
                        stroke-dasharray="100" stroke-dashoffset="100"
                        transform="rotate(-90 18 18)" id="awProgressRing"/>
                <text x="18" y="21" text-anchor="middle" font-size="8" fill="var(--text)" id="awProgressText">0%</text>
            </svg>
            <button class="aw-reader-bookmark-btn" id="awBookmarkBtn" aria-label="Bookmark this page">
                <i class="far fa-bookmark"></i>
            </button>
            <button class="aw-reader-settings-btn" id="awSettingsToggle" aria-label="Settings">
                <i class="fas fa-cog"></i>
            </button>
            <button class="aw-reader-toc-btn" id="awTocToggle" aria-label="Table of Contents">
                <i class="fas fa-list-ul"></i>
            </button>
            <button class="aw-reader-focus-btn" id="awFocusToggle" aria-label="Focus mode">
                <i class="fas fa-expand"></i>
            </button>
            <button class="aw-reader-share-btn" id="awShareBtn" aria-label="Share">
                <i class="fas fa-share-alt"></i>
            </button>
            <button class="aw-reader-notes-btn" id="awNotesToggle" aria-label="Group notes">
                <i class="fas fa-sticky-note"></i>
            </button>
        </div>
    </header>

    <!-- ===== SETTINGS PANEL ===== -->
    <div class="aw-reader-settings" id="awSettingsPanel">
        <div class="aw-settings-grid">
            <div class="aw-setting-group">
                <label>Theme</label>
                <div class="aw-theme-options">
                    <button class="aw-theme-btn" data-theme="paper"><span class="color-preview paper"></span> Paper</button>
                    <button class="aw-theme-btn active" data-theme="light"><span class="color-preview light"></span> Light</button>
                    <button class="aw-theme-btn" data-theme="dark"><span class="color-preview dark"></span> Dark</button>
                    <button class="aw-theme-btn" data-theme="sepia"><span class="color-preview sepia"></span> Sepia</button>
                </div>
                <div class="aw-theme-extra">
                    <label><input type="checkbox" id="awAutoTheme"> Auto‑theme (time of day)</label>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Font</label>
                <div class="aw-font-options">
                    <button class="aw-font-btn active" data-font="serif">Serif</button>
                    <button class="aw-font-btn" data-font="sans">Sans</button>
                    <button class="aw-font-btn" data-font="mono">Mono</button>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Font Size</label>
                <div class="aw-size-controls">
                    <button class="aw-size-btn" id="awDecreaseSize"><i class="fas fa-font" style="font-size:0.8rem;"></i></button>
                    <input type="range" id="awSizeSlider" min="80" max="160" value="100" step="5">
                    <button class="aw-size-btn" id="awIncreaseSize"><i class="fas fa-font" style="font-size:1.2rem;"></i></button>
                    <span id="awSizeLabel">100%</span>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Line Height</label>
                <div class="aw-size-controls">
                    <button class="aw-size-btn" id="awDecreaseLine"><i class="fas fa-arrows-alt-v" style="font-size:0.8rem;"></i></button>
                    <input type="range" id="awLineSlider" min="140" max="220" value="180" step="10">
                    <button class="aw-size-btn" id="awIncreaseLine"><i class="fas fa-arrows-alt-v" style="font-size:1.2rem;"></i></button>
                    <span id="awLineLabel">1.8</span>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Letter Spacing</label>
                <div class="aw-size-controls">
                    <button class="aw-size-btn" id="awDecreaseSpacing"><i class="fas fa-text-width" style="font-size:0.8rem;"></i></button>
                    <input type="range" id="awSpacingSlider" min="-2" max="4" value="0" step="1">
                    <button class="aw-size-btn" id="awIncreaseSpacing"><i class="fas fa-text-width" style="font-size:1.2rem;"></i></button>
                    <span id="awSpacingLabel">0</span>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Reading Mode</label>
                <div class="aw-mode-options">
                    <button class="aw-mode-btn active" data-mode="scroll">Vertical (Scroll)</button>
                    <button class="aw-mode-btn" data-mode="flip">Horizontal (Page Flip)</button>
                </div>
            </div>
            <div class="aw-setting-group">
                <label>Word Count</label>
                <div id="awWordCount" style="font-size:0.8rem;color:var(--text-light);">Loading...</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
            <button class="btn btn-sm btn-outline" id="awExportHighlights">📤 Export Highlights</button>
            <button class="btn btn-sm btn-outline" id="awResumeBtn">↩️ Resume Last Position</button>
        </div>
    </div>

    <!-- ===== TOC DRAWER ===== -->
    <div class="aw-toc-drawer" id="awTocDrawer">
        <div class="aw-toc-header">
            <h3>Table of Contents</h3>
            <button class="aw-toc-close" id="awTocClose">&times;</button>
        </div>
        <div class="aw-toc-body" id="awTocBody">
            <?php if (count($toc) > 0): ?>
                <ul class="aw-toc-list">
                <?php foreach ($toc as $entry): ?>
                    <li><a href="#" class="aw-toc-link" data-chapter="<?php echo $entry['page']; ?>"><?php echo htmlspecialchars($entry['title']); ?></a></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="aw-toc-empty">No table of contents available.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== NOTES PANEL ===== -->
    <div class="aw-notes-panel" id="awNotesPanel">
        <div class="aw-notes-header">
            <div class="aw-notes-title">
                <h3>📝 Group Notes</h3>
                <span class="badge" id="awNoteBadge">0</span>
            </div>
            <div class="aw-notes-actions">
                <button class="btn btn-sm btn-primary" id="awAddNoteBtn">+ Add Note</button>
                <button class="btn btn-sm btn-outline" id="awNotesClose"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="aw-notes-body" id="awNotesBody">
            <div class="notes-list" id="awNotesList">
                <p class="empty-notes">No notes for this chapter. Be the first to add one!</p>
            </div>
            <div id="awAddNoteForm" style="display:none; padding:12px; border-top:1px solid var(--border);">
                <form id="awNoteForm">
                    <div class="form-group">
                        <textarea id="awNoteText" rows="2" placeholder="Share your thoughts on this chapter..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="awNotePrivate"> Make this note private
                        </label>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-sm btn-primary">Post Note</button>
                        <button type="button" class="btn btn-sm btn-secondary" id="awNoteCancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== REACTION PICKER ===== -->
    <div id="awReactionPicker" style="display:none; position:fixed; background:var(--card-bg); border:1px solid var(--border); border-radius:8px; padding:6px 10px; box-shadow:var(--shadow-hover); z-index:50;">
        <button class="reaction-option" data-reaction="👍">👍</button>
        <button class="reaction-option" data-reaction="❤️">❤️</button>
        <button class="reaction-option" data-reaction="🙏">🙏</button>
        <button class="reaction-option" data-reaction="🤔">🤔</button>
        <button class="reaction-option" data-reaction="📖">📖</button>
    </div>

    <!-- ===== CHALLENGE WIDGET ===== -->
    <div id="awChallengeWidget" class="aw-challenge-widget-container" style="display:none;"></div>

    <!-- ===== CONTENT AREA ===== -->
    <div class="aw-reader-content" id="awContent">
        <?php if ($has_processed && !empty($processed['content_html'])): ?>
            <div id="awReaderText" class="aw-reader-text">
                <?php echo $processed['content_html']; ?>
            </div>
        <?php else: ?>
            <!-- ===== ENHANCED FALLBACK ===== -->
            <div class="aw-reader-fallback" id="awFallbackContainer">
                
                <?php if ($file_type === 'pdf'): ?>
                    <canvas id="awPdfCanvas"></canvas>
                    <div class="aw-pdf-controls">
                        <button id="awPdfPrev"><i class="fas fa-chevron-left"></i></button>
                        <span id="awPdfPageInfo">1 / 1</span>
                        <button id="awPdfNext"><i class="fas fa-chevron-right"></i></button>
                        <input type="range" id="awPdfZoom" min="0.5" max="2" step="0.1" value="1">
                    </div>
                
                <?php elseif ($file_type === 'epub'): ?>
                    <div id="awEpubViewer"></div>
                    <div class="aw-epub-controls">
                        <button id="awEpubPrev"><i class="fas fa-chevron-left"></i></button>
                        <span id="awEpubPageInfo">1 / 1</span>
                        <button id="awEpubNext"><i class="fas fa-chevron-right"></i></button>
                    </div>

                <?php elseif (in_array($file_type, ['mobi', 'azw3', 'azw4'])): ?>
                    <div class="aw-reader-unsupported" style="text-align:center;padding:40px 20px;">
                        <i class="fas fa-book" style="font-size:4rem;color:var(--rose);display:block;margin-bottom:16px;"></i>
                        <h3><?php echo strtoupper($file_type); ?> Format Not Supported Online</h3>
                        <p>This format is not supported for web-based reading. Please download the file to read it on your device.</p>
                        <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary" style="display:inline-block;margin-top:8px;">
                            <i class="fas fa-download"></i> Download <?php echo strtoupper($file_type); ?>
                        </a>
                    </div>

                <?php elseif ($file_type === 'txt'): ?>
                    <div class="aw-reader-unsupported" style="text-align:center;padding:40px 20px;">
                        <i class="fas fa-file-alt" style="font-size:4rem;color:var(--rose);display:block;margin-bottom:16px;"></i>
                        <h3>TXT Format Not Supported</h3>
                        <p>TXT files cannot be processed for online reading. Please convert to EPUB or PDF.</p>
                    </div>

                <?php elseif (!$file_exists): ?>
                    <div class="aw-reader-unsupported" style="text-align:center;padding:40px 20px;">
                        <i class="fas fa-file-excel" style="font-size:4rem;color:var(--rose);display:block;margin-bottom:16px;"></i>
                        <h3>File Not Found</h3>
                        <p>The book file could not be located on the server.</p>
                        <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">Back to Books</a>
                    </div>

                <?php else: ?>
                    <div class="aw-reader-unsupported" style="text-align:center;padding:40px 20px;">
                        <i class="fas fa-exclamation-triangle" style="font-size:4rem;color:var(--rose);display:block;margin-bottom:16px;"></i>
                        <h3>Unsupported File Type</h3>
                        <p>File type "<?php echo htmlspecialchars($file_type); ?>" cannot be read online.</p>
                        <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">Back to Books</a>
                    </div>
                <?php endif; ?>
                
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== HIGHLIGHT TOOLTIP ===== -->
    <div class="aw-highlight-tooltip" id="awHighlightTooltip">
        <button class="aw-highlight-color" data-color="yellow"></button>
        <button class="aw-highlight-color" data-color="green"></button>
        <button class="aw-highlight-color" data-color="blue"></button>
        <button class="aw-highlight-color" data-color="pink"></button>
        <button class="aw-highlight-btn" id="awHighlightAnnotate"><i class="fas fa-pen"></i></button>
    </div>

    <!-- ===== ANNOTATION POPUP ===== -->
    <div class="aw-annotation-popup" id="awAnnotationPopup">
        <textarea id="awAnnotationText" rows="3" placeholder="Add a note…"></textarea>
        <div class="aw-annotation-actions">
            <button class="aw-annotation-save" id="awAnnotationSave">Save</button>
            <button class="aw-annotation-cancel" id="awAnnotationCancel">Cancel</button>
        </div>
    </div>

    <!-- ===== SEARCH BAR ===== -->
    <div class="aw-search-bar" id="awSearchBar">
        <input type="text" id="awSearchInput" placeholder="Search in this book…">
        <button id="awSearchClose"><i class="fas fa-times"></i></button>
        <div id="awSearchResults"></div>
    </div>

    <!-- ===== SHARE MODAL ===== -->
    <div id="awShareModal" class="aw-share-modal">
        <div class="aw-share-modal-content">
            <h3>Share this page</h3>
            <div class="aw-share-options">
                <button class="aw-share-facebook"><i class="fab fa-facebook-f"></i> Facebook</button>
                <button class="aw-share-twitter"><i class="fab fa-twitter"></i> Twitter/X</button>
                <button class="aw-share-whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                <button class="aw-share-copy"><i class="fas fa-link"></i> Copy Link</button>
            </div>
            <button class="aw-share-modal-close">Close</button>
        </div>
    </div>

    <!-- ===== OVERLAY ===== -->
    <div class="aw-overlay" id="awOverlay" onclick="closeAllMenus()"></div>
</div>

<!-- ============================================================ -->
<!--  JAVASCRIPT – FULLY ENHANCED (no original function removed)  -->
<!-- ============================================================ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== GET DATA FROM PHP =====
    const reader = document.getElementById('awReader');
    const content = document.getElementById('awContent');
    const textContainer = document.getElementById('awReaderText');
    const header = document.getElementById('awReaderHeader');
    const settingsPanel = document.getElementById('awSettingsPanel');
    const tocDrawer = document.getElementById('awTocDrawer');
    const notesPanel = document.getElementById('awNotesPanel');
    const notesList = document.getElementById('awNotesList');
    const noteBadge = document.getElementById('awNoteBadge');
    const addNoteBtn = document.getElementById('awAddNoteBtn');
    const noteForm = document.getElementById('awNoteForm');
    const noteText = document.getElementById('awNoteText');
    const notePrivate = document.getElementById('awNotePrivate');
    const noteCancel = document.getElementById('awNoteCancel');
    const notesClose = document.getElementById('awNotesClose');
    const overlay = document.getElementById('awOverlay');
    const progressRing = document.getElementById('awProgressRing');
    const progressText = document.getElementById('awProgressText');
    const bookmarkBtn = document.getElementById('awBookmarkBtn');
    const settingsToggle = document.getElementById('awSettingsToggle');
    const tocToggle = document.getElementById('awTocToggle');
    const tocClose = document.getElementById('awTocClose');
    const focusToggle = document.getElementById('awFocusToggle');
    const shareBtn = document.getElementById('awShareBtn');
    const shareModal = document.getElementById('awShareModal');
    const notesToggle = document.getElementById('awNotesToggle');
    const highlightTooltip = document.getElementById('awHighlightTooltip');
    const annotationPopup = document.getElementById('awAnnotationPopup');
    const annotationText = document.getElementById('awAnnotationText');
    const annotationSave = document.getElementById('awAnnotationSave');
    const annotationCancel = document.getElementById('awAnnotationCancel');
    const searchBar = document.getElementById('awSearchBar');
    const searchInput = document.getElementById('awSearchInput');
    const searchClose = document.getElementById('awSearchClose');
    const searchResults = document.getElementById('awSearchResults');
    const reactionPicker = document.getElementById('awReactionPicker');
    const challengeWidget = document.getElementById('awChallengeWidget');
    const exportBtn = document.getElementById('awExportHighlights');
    const resumeBtn = document.getElementById('awResumeBtn');
    const wordCountEl = document.getElementById('awWordCount');

    const bookId = parseInt(reader.dataset.bookId);
    const filePath = reader.dataset.filePath;
    const fileType = reader.dataset.fileType;
    const fileExists = reader.dataset.fileExists === '1';
    const userId = parseInt(reader.dataset.userId);
    const groupId = parseInt(reader.dataset.groupId || 0);
    const lastOffset = parseInt(reader.dataset.lastOffset || 0);
    const lastChapter = parseInt(reader.dataset.lastChapter || 0);
    const progressPercent = parseInt(reader.dataset.lastProgress || 0);

    let saveTimer = null;
    let scrollTimeout = null;
    let currentNoteId = null;
    let currentReactionPicker = null;
    let readingMode = localStorage.getItem('reading_mode') || 'scroll';
    let focusMode = false;
    let flipPages = [];
    let currentFlipPage = 0;
    let currentChapter = 0; // We'll update this on page changes

    // ===== FILE EXISTENCE CHECK =====
    if (!fileExists) {
        document.getElementById('awContent').innerHTML = `
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-file-excel" style="font-size:3rem;color:var(--rose);display:block;margin-bottom:16px;"></i>
                <h3>File Not Found</h3>
                <p>The book file could not be located on the server.</p>
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">Back to Books</a>
            </div>
        `;
        return;
    }

    // ===== PREPARE PAGES FOR HORIZONTAL MODE =====
    const pagesData = <?php echo json_encode($pages); ?>;
    const totalPages = pagesData.length;

    // ===== PROGRESS =====
    function updateProgress(scrollTop) {
        const scrollHeight = content.scrollHeight - content.clientHeight;
        if (scrollHeight <= 0) return;
        let percent = Math.min(100, Math.round((scrollTop / scrollHeight) * 100));
        const radius = 16;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (percent / 100) * circumference;
        progressRing.setAttribute('stroke-dashoffset', offset);
        progressText.textContent = percent + '%';
        if (userId > 0) {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                const formData = new FormData();
                formData.append('action', 'save_position');
                formData.append('book_id', bookId);
                formData.append('offset', scrollTop);
                formData.append('chapter', currentChapter);
                formData.append('percent', percent);
                fetch('/reader/reader_ajax.php', {
                    method: 'POST',
                    body: formData
                }).catch(() => {});
            }, 2000);
        }
    }

    // ===== SCROLL (Vertical Mode) =====
    content.addEventListener('scroll', function() {
        if (readingMode !== 'scroll') return;
        const scrollTop = content.scrollTop;
        updateProgress(scrollTop);
        clearTimeout(scrollTimeout);
        header.classList.remove('hidden');
        scrollTimeout = setTimeout(() => {
            if (!focusMode && !settingsPanel.classList.contains('open')) {
                header.classList.add('hidden');
            }
        }, 3000);
    });

    // ===== RESTORE POSITION (with Resume button) =====
    function restorePosition() {
        if (readingMode === 'scroll') {
            if (lastOffset > 0 && textContainer) {
                content.scrollTop = lastOffset;
                updateProgress(lastOffset);
            }
        } else {
            // Horizontal mode – go to the saved chapter/page
            if (lastChapter >= 1 && lastChapter <= totalPages) {
                goToPage(lastChapter);
            }
        }
    }
    resumeBtn.addEventListener('click', restorePosition);
    // Restore on load
    setTimeout(restorePosition, 100);

    // ===== THEME =====
    let currentTheme = localStorage.getItem('reader_theme') || 'light';
    let autoTheme = false;
    function applyTheme(theme) {
        currentTheme = theme;
        reader.setAttribute('data-theme', theme);
        localStorage.setItem('reader_theme', theme);
        document.querySelectorAll('.aw-theme-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.aw-theme-btn[data-theme="${theme}"]`)?.classList.add('active');
    }
    // Auto-theme based on OS preference (first load)
    if (!localStorage.getItem('reader_theme')) {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }
    document.querySelectorAll('.aw-theme-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const theme = this.dataset.theme;
            if (autoTheme) {
                document.getElementById('awAutoTheme').checked = false;
                autoTheme = false;
            }
            applyTheme(theme);
        });
    });
    document.getElementById('awAutoTheme').addEventListener('change', function() {
        autoTheme = this.checked;
        if (autoTheme) {
            const hour = new Date().getHours();
            let theme = 'light';
            if (hour >= 6 && hour < 12) theme = 'sepia';
            else if (hour >= 12 && hour < 18) theme = 'paper';
            else if (hour >= 18 && hour < 22) theme = 'dark';
            else theme = 'dark';
            applyTheme(theme);
            setInterval(() => { if (autoTheme) {
                const h = new Date().getHours();
                let t = 'light';
                if (h >= 6 && h < 12) t = 'sepia';
                else if (h >= 12 && h < 18) t = 'paper';
                else if (h >= 18 && h < 22) t = 'dark';
                else t = 'dark';
                applyTheme(t);
            } }, 3600000);
        }
    });
    applyTheme(currentTheme);

    // ===== FONT =====
    let currentFont = localStorage.getItem('reader_font') || 'serif';
    function applyFont(font) {
        currentFont = font;
        reader.setAttribute('data-font', font);
        localStorage.setItem('reader_font', font);
        document.querySelectorAll('.aw-font-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.aw-font-btn[data-font="${font}"]`)?.classList.add('active');
    }
    document.querySelectorAll('.aw-font-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            applyFont(this.dataset.font);
        });
    });
    applyFont(currentFont);

    // ===== FONT SIZE =====
    let fontSize = parseInt(localStorage.getItem('reader_font_size')) || 100;
    function applySize(size) {
        fontSize = Math.min(160, Math.max(80, size));
        textContainer.style.fontSize = fontSize + '%';
        document.getElementById('awSizeLabel').textContent = fontSize + '%';
        document.getElementById('awSizeSlider').value = fontSize;
        localStorage.setItem('reader_font_size', fontSize);
    }
    document.getElementById('awSizeSlider').addEventListener('input', function() {
        applySize(parseInt(this.value));
    });
    document.getElementById('awDecreaseSize').addEventListener('click', () => applySize(fontSize - 5));
    document.getElementById('awIncreaseSize').addEventListener('click', () => applySize(fontSize + 5));
    applySize(fontSize);

    // ===== LINE HEIGHT =====
    let lineHeight = parseInt(localStorage.getItem('reader_line_height')) || 180;
    function applyLine(height) {
        lineHeight = Math.min(220, Math.max(140, height));
        textContainer.style.lineHeight = lineHeight / 100;
        document.getElementById('awLineLabel').textContent = (lineHeight / 100).toFixed(1);
        document.getElementById('awLineSlider').value = lineHeight;
        localStorage.setItem('reader_line_height', lineHeight);
    }
    document.getElementById('awLineSlider').addEventListener('input', function() {
        applyLine(parseInt(this.value));
    });
    document.getElementById('awDecreaseLine').addEventListener('click', () => applyLine(lineHeight - 10));
    document.getElementById('awIncreaseLine').addEventListener('click', () => applyLine(lineHeight + 10));
    applyLine(lineHeight);

    // ===== LETTER SPACING =====
    let letterSpacing = parseInt(localStorage.getItem('reader_letter_spacing')) || 0;
    function applySpacing(spacing) {
        letterSpacing = Math.min(4, Math.max(-2, spacing));
        textContainer.style.letterSpacing = letterSpacing + 'px';
        document.getElementById('awSpacingLabel').textContent = letterSpacing;
        document.getElementById('awSpacingSlider').value = letterSpacing;
        localStorage.setItem('reader_letter_spacing', letterSpacing);
    }
    document.getElementById('awSpacingSlider').addEventListener('input', function() {
        applySpacing(parseInt(this.value));
    });
    document.getElementById('awDecreaseSpacing').addEventListener('click', () => applySpacing(letterSpacing - 1));
    document.getElementById('awIncreaseSpacing').addEventListener('click', () => applySpacing(letterSpacing + 1));
    applySpacing(letterSpacing);

    // ===== WORD COUNT & READING TIME =====
    function updateWordCount() {
        if (!textContainer) return;
        const words = textContainer.innerText.split(/\s+/).length;
        const minutes = Math.ceil(words / 200);
        const hours = Math.floor(minutes / 60);
        const remaining = minutes % 60;
        const readingTime = hours > 0 ? `${hours}h ${remaining}m` : `${minutes}m`;
        wordCountEl.textContent = `Words: ${words.toLocaleString()} — Reading time: ~${readingTime}`;
    }
    setTimeout(updateWordCount, 500);
    document.addEventListener('DOMContentLoaded', updateWordCount);

    // ===== READING MODE (Vertical vs Horizontal) =====
    function initFlipMode() {
        // Instead of destroying the DOM, we create a virtual page container
        if (typeof pagesData === 'undefined' || pagesData.length === 0) {
            alert('No pages available for horizontal mode. Please run extraction first.');
            return;
        }
        // Hide the original textContainer (scroll view)
        textContainer.style.display = 'none';
        // Remove any existing flip container
        const existing = document.getElementById('awFlipContainer');
        if (existing) existing.remove();
        // Create a new flip container
        const flipContainer = document.createElement('div');
        flipContainer.id = 'awFlipContainer';
        flipContainer.style.cssText = 'flex:1;overflow:hidden;padding:20px;display:flex;flex-direction:column;justify-content:center;align-items:center;';
        textContainer.parentNode.insertBefore(flipContainer, textContainer);
        // Show the first page
        currentFlipPage = lastChapter >= 1 && lastChapter <= totalPages ? lastChapter - 1 : 0;
        showFlipPage(currentFlipPage);
    }

    function showFlipPage(index) {
        const container = document.getElementById('awFlipContainer');
        if (!container) return;
        if (index < 0 || index >= totalPages) return;
        currentFlipPage = index;
        // Instead of setting innerHTML (which destroys highlights), we create a fresh page
        container.innerHTML = `<div class="virtual-page active">${pagesData[index]}</div>`;
        // Re-apply highlights for this page (if user is logged in)
        if (userId > 0) {
            const pageNum = index + 1;
            fetchHighlightsForPage(pageNum).then(highlights => {
                highlights.forEach(h => {
                    const span = `<span class="highlight-${h.color}">${h.text}</span>`;
                    container.innerHTML = container.innerHTML.replaceAll(h.text, span);
                });
            });
        }
        // Update progress
        const percent = Math.round(((index + 1) / totalPages) * 100);
        const radius = 16;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (percent / 100) * circumference;
        progressRing.setAttribute('stroke-dashoffset', offset);
        progressText.textContent = percent + '%';
        // Update page number display
        document.getElementById('pageNum').textContent = index + 1;
        document.getElementById('totalPages').textContent = totalPages;
        // Save position
        currentChapter = index + 1;
        savePosition();
    }

    function nextFlipPage() {
        if (currentFlipPage < totalPages - 1) showFlipPage(currentFlipPage + 1);
    }
    function prevFlipPage() {
        if (currentFlipPage > 0) showFlipPage(currentFlipPage - 1);
    }

    // Mode toggle
    document.querySelectorAll('.aw-mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            readingMode = this.dataset.mode;
            reader.setAttribute('data-mode', readingMode);
            localStorage.setItem('reading_mode', readingMode);
            document.querySelectorAll('.aw-mode-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            if (readingMode === 'flip') {
                initFlipMode();
                document.addEventListener('keydown', flipKeyHandler);
                // Disable scroll event for content
                content.removeEventListener('scroll', updateProgress);
            } else {
                // Remove flip container and show original scroll
                const flipContainer = document.getElementById('awFlipContainer');
                if (flipContainer) flipContainer.remove();
                textContainer.style.display = 'block';
                document.removeEventListener('keydown', flipKeyHandler);
                // Restore scroll listener
                content.addEventListener('scroll', updateProgress);
                // Restore scroll position
                if (lastOffset > 0) {
                    content.scrollTop = lastOffset;
                    updateProgress(lastOffset);
                }
            }
        });
    });
    // Set initial mode from localStorage
    if (readingMode === 'flip') {
        document.querySelector('.aw-mode-btn[data-mode="flip"]')?.classList.add('active');
        document.querySelector('.aw-mode-btn[data-mode="scroll"]')?.classList.remove('active');
        initFlipMode();
        document.addEventListener('keydown', flipKeyHandler);
    } else {
        document.querySelector('.aw-mode-btn[data-mode="scroll"]')?.classList.add('active');
        document.querySelector('.aw-mode-btn[data-mode="flip"]')?.classList.remove('active');
        content.addEventListener('scroll', updateProgress);
    }

    function flipKeyHandler(e) {
        if (e.key === 'ArrowRight') nextFlipPage();
        if (e.key === 'ArrowLeft') prevFlipPage();
    }

    // ===== TOUCH GESTURES (Swipe for mobile) =====
    let touchStartX = 0;
    let touchStartY = 0;
    document.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    });
    document.addEventListener('touchmove', function(e) {
        if (!touchStartX) return;
        const diffX = e.touches[0].clientX - touchStartX;
        const diffY = e.touches[0].clientY - touchStartY;
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 30 && readingMode === 'flip') {
            e.preventDefault();
            if (diffX < 0) nextFlipPage();
            else prevFlipPage();
            touchStartX = 0;
        }
    });

    // ===== BIBLE VERSE POP-UP (Simple implementation) =====
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('bible-ref')) {
            const ref = e.target.textContent.trim();
            alert('📖 Bible reference: ' + ref + '\n(Integrate a Bible API to show the verse text.)');
        }
    });

    // ===== SETTINGS PANEL =====
    settingsToggle.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        if (settingsPanel.classList.contains('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    });

    // ===== TOC DRAWER =====
    tocToggle.addEventListener('click', function() {
        tocDrawer.classList.toggle('open');
        if (tocDrawer.classList.contains('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    });
    tocClose.addEventListener('click', function() {
        tocDrawer.classList.remove('open');
        overlay.classList.remove('active');
    });
    document.querySelectorAll('.aw-toc-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.chapter);
            goToPage(page);
            tocDrawer.classList.remove('open');
            overlay.classList.remove('active');
        });
    });

    // ===== FOCUS MODE =====
    focusToggle.addEventListener('click', function() {
        focusMode = !focusMode;
        reader.classList.toggle('focus-mode', focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            header.classList.add('hidden');
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
            searchBar.classList.remove('visible');
        } else {
            header.classList.remove('hidden');
        }
    });

    // ===== OVERLAY =====
    overlay.addEventListener('click', function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
    });

    // ===== BOOKMARKS =====
    let isBookmarked = false;
    bookmarkBtn.addEventListener('click', function() {
        const chapter = currentChapter;
        const offset = content.scrollTop;
        if (!isBookmarked) {
            const formData = new FormData();
            formData.append('action', 'add_bookmark');
            formData.append('book_id', bookId);
            formData.append('chapter', chapter);
            formData.append('offset', offset);
            fetch('/reader/reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    isBookmarked = true;
                    bookmarkBtn.querySelector('i').className = 'fas fa-bookmark';
                    bookmarkBtn.classList.add('active');
                }
            });
        } else {
            const formData = new FormData();
            formData.append('action', 'remove_bookmark');
            formData.append('book_id', bookId);
            fetch('/reader/reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    isBookmarked = false;
                    bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
                    bookmarkBtn.classList.remove('active');
                }
            });
        }
    });

    // ===== HIGHLIGHTS =====
    let selectedText = '';
    let selectedRange = null;
    function getSelectedText() {
        const sel = window.getSelection();
        return sel.toString().trim();
    }
    function getSelectionRange() {
        const sel = window.getSelection();
        return sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    }
    function showHighlightTooltip() {
        selectedText = getSelectedText();
        selectedRange = getSelectionRange();
        if (!selectedText || !selectedRange) {
            highlightTooltip.classList.remove('visible');
            return;
        }
        const rect = selectedRange.getBoundingClientRect();
        highlightTooltip.style.top = (rect.top - 50) + 'px';
        highlightTooltip.style.left = (rect.left + rect.width / 2 - 60) + 'px';
        highlightTooltip.classList.add('visible');
    }
    document.addEventListener('mouseup', showHighlightTooltip);
    document.addEventListener('touchend', function() {
        setTimeout(showHighlightTooltip, 300);
    });
    document.querySelectorAll('.aw-highlight-color').forEach(btn => {
        btn.addEventListener('click', function() {
            const color = this.dataset.color;
            if (selectedText && selectedRange) {
                const formData = new FormData();
                formData.append('action', 'add_highlight');
                formData.append('book_id', bookId);
                formData.append('chapter', currentChapter);
                formData.append('text', selectedText);
                formData.append('color', color);
                fetch('/reader/reader_ajax.php', {
                    method: 'POST',
                    body: formData
                }).then(() => {
                    const span = document.createElement('span');
                    span.className = 'highlight-' + color;
                    span.textContent = selectedText;
                    selectedRange.deleteContents();
                    selectedRange.insertNode(span);
                    highlightTooltip.classList.remove('visible');
                });
            }
        });
    });

    // ===== ANNOTATIONS =====
    document.getElementById('awHighlightAnnotate').addEventListener('click', function() {
        if (selectedText && selectedRange) {
            annotationPopup.classList.add('visible');
            annotationText.value = '';
            annotationText.focus();
            highlightTooltip.classList.remove('visible');
        }
    });
    annotationSave.addEventListener('click', function() {
        const note = annotationText.value.trim();
        if (note && selectedText && selectedRange) {
            const formData = new FormData();
            formData.append('action', 'add_highlight');
            formData.append('book_id', bookId);
            formData.append('chapter', currentChapter);
            formData.append('text', selectedText);
            formData.append('color', 'yellow');
            formData.append('note', note);
            fetch('/reader/reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                const span = document.createElement('span');
                span.className = 'highlight-yellow.annotation';
                span.textContent = selectedText;
                selectedRange.deleteContents();
                selectedRange.insertNode(span);
                annotationPopup.classList.remove('visible');
            });
        }
    });
    annotationCancel.addEventListener('click', function() {
        annotationPopup.classList.remove('visible');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.aw-highlight-tooltip') && !e.target.closest('.aw-annotation-popup')) {
            highlightTooltip.classList.remove('visible');
            annotationPopup.classList.remove('visible');
        }
    });

    // ===== EXPORT HIGHLIGHTS =====
    exportBtn.addEventListener('click', function() {
        const formData = new FormData();
        formData.append('action', 'export_highlights');
        formData.append('book_id', bookId);
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.blob()).then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'highlights.txt';
            a.click();
            URL.revokeObjectURL(url);
        });
    });

    // ===== SEARCH =====
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        // Search in all pages (if horizontal mode) or in the full text (if scroll)
        if (readingMode === 'flip') {
            let found = [];
            pagesData.forEach((html, idx) => {
                const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
                if (text.toLowerCase().includes(query)) {
                    found.push({ page: idx + 1, snippet: text.substring(0, 80) + '...' });
                }
            });
            if (found.length === 0) {
                searchResults.innerHTML = 'No matches found.';
                searchResults.style.display = 'block';
            } else {
                let resultsHtml = '';
                found.slice(0, 10).forEach(f => {
                    resultsHtml += `<div class="aw-search-result" onclick="goToPage(${f.page})"><strong>Page ${f.page}</strong> – ${f.snippet}</div>`;
                });
                searchResults.innerHTML = resultsHtml;
                searchResults.style.display = 'block';
            }
        } else {
            // Scroll mode: search in the visible text container (original behavior)
            const text = textContainer.innerText;
            const lines = text.split('\n');
            let resultsHtml = '';
            for (let i = 0; i < lines.length; i++) {
                if (lines[i].toLowerCase().includes(query)) {
                    resultsHtml += '<div class="aw-search-result">' + lines[i] + '</div>';
                    if (resultsHtml.split('</div>').length > 20) break;
                }
            }
            if (resultsHtml) {
                searchResults.innerHTML = resultsHtml;
                searchResults.style.display = 'block';
            } else {
                searchResults.innerHTML = 'No matches found.';
                searchResults.style.display = 'block';
            }
        }
    });
    searchClose.addEventListener('click', function() {
        searchBar.classList.remove('visible');
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
    });

    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            searchBar.classList.toggle('visible');
            if (searchBar.classList.contains('visible')) {
                searchInput.focus();
            }
        }
        if (e.key === 'Escape') {
            closeAllMenus();
            searchBar.classList.remove('visible');
        }
    });

    // ===== CLOSE ALL =====
    window.closeAllMenus = function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
        highlightTooltip.classList.remove('visible');
        annotationPopup.classList.remove('visible');
        searchBar.classList.remove('visible');
    };

    // ===== SHARE =====
    shareBtn.addEventListener('click', function() {
        shareModal.classList.add('visible');
        overlay.classList.add('active');
    });
    document.querySelectorAll('.aw-share-modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });
    document.querySelectorAll('.aw-share-facebook').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/reader/reader.php?id=' + bookId + '&chapter=' + currentChapter + '&share=1';
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank');
        });
    });
    document.querySelectorAll('.aw-share-twitter').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/reader/reader.php?id=' + bookId + '&chapter=' + currentChapter + '&share=1';
            window.open('https://twitter.com/intent/tweet?text=Reading&url=' + encodeURIComponent(url), '_blank');
        });
    });
    document.querySelectorAll('.aw-share-whatsapp').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/reader/reader.php?id=' + bookId + '&chapter=' + currentChapter + '&share=1';
            window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(url), '_blank');
        });
    });
    document.querySelectorAll('.aw-share-copy').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/reader/reader.php?id=' + bookId + '&chapter=' + currentChapter + '&share=1';
            navigator.clipboard.writeText(url).then(() => alert('✅ Link copied!')).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('✅ Link copied!');
            });
        });
    });

    // ===== NOTES =====
    function toggleNotesPanel() {
        if (groupId === 0) {
            alert('You are not a member of a reading group for this book.');
            return;
        }
        if (notesPanel.style.display === 'none' || notesPanel.style.display === '') {
            notesPanel.style.display = 'flex';
            loadNotes();
            overlay.classList.add('active');
        } else {
            notesPanel.style.display = 'none';
            overlay.classList.remove('active');
        }
    }
    notesToggle.addEventListener('click', toggleNotesPanel);
    notesClose.addEventListener('click', function() {
        notesPanel.style.display = 'none';
        overlay.classList.remove('active');
    });

    function loadNotes() {
        if (groupId === 0) return;
        fetch('/reader/reader_ajax.php?action=get_notes&group_id=' + groupId + '&book_id=' + bookId + '&chapter=' + currentChapter)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.notes.forEach(note => {
                        let reactionsHtml = '';
                        if (note.reactions && note.reactions.length > 0) {
                            note.reactions.forEach(reaction => {
                                reactionsHtml += `<span class="reaction" onclick="reactNote(${note.id}, '${reaction.reaction_type}')">
                                    ${reaction.reaction_type} ${reaction.count}
                                </span>`;
                            });
                        }
                        const isMyNote = note.user_id == userId;
                        const canReact = !note.is_private || isMyNote;
                        html += `<div class="note-card ${note.is_private ? 'private' : ''}">
                            <div class="note-author">
                                ${note.avatar ? `<img src="${note.avatar}" class="note-avatar">` : `<div class="note-avatar-placeholder">${(note.display_name || note.username).charAt(0).toUpperCase()}</div>`}
                                <div class="note-author-info">
                                    <strong>${note.display_name || note.username}</strong>
                                    <small>${timeAgo(note.created_at)}</small>
                                    ${note.is_private ? '<span class="badge-private">🔒 Private</span>' : ''}
                                </div>
                            </div>
                            <p class="note-text">${note.text}</p>
                            <div class="note-footer">
                                <div class="note-reactions">
                                    ${reactionsHtml}
                                    ${canReact ? `<button class="btn btn-sm btn-outline" onclick="showReactionPicker(${note.id}, event)">➕ React</button>` : ''}
                                </div>
                                ${isMyNote ? `<button class="btn btn-sm btn-danger" onclick="deleteNote(${note.id})">🗑️</button>` : ''}
                            </div>
                        </div>`;
                    });
                    notesList.innerHTML = html || '<p class="empty-notes">No notes for this chapter.</p>';
                    noteBadge.textContent = data.notes.length;
                }
            });
    }

    function submitNote(e) {
        e.preventDefault();
        const text = noteText.value.trim();
        const isPrivate = notePrivate.checked ? 1 : 0;
        if (!text) {
            alert('Please enter a note.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'add_reader_note');
        formData.append('group_id', groupId);
        formData.append('book_id', bookId);
        formData.append('chapter_index', currentChapter);
        formData.append('text', text);
        formData.append('is_private', isPrivate);
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                loadNotes();
                noteText.value = '';
                notePrivate.checked = false;
                document.getElementById('awAddNoteForm').style.display = 'none';
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
    addNoteBtn.addEventListener('click', function() {
        const form = document.getElementById('awAddNoteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            noteText.focus();
        }
    });
    noteCancel.addEventListener('click', function() {
        document.getElementById('awAddNoteForm').style.display = 'none';
        noteText.value = '';
        notePrivate.checked = false;
    });
    noteForm.addEventListener('submit', submitNote);

    function deleteNote(noteId) {
        if (!confirm('Delete this note?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_reader_note');
        formData.append('note_id', noteId);
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadNotes();
        });
    }

    // ===== REACTIONS =====
    function showReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const btn = event.target.closest('.btn-outline');
        const rect = btn.getBoundingClientRect();
        reactionPicker.style.top = (rect.bottom + 8) + 'px';
        reactionPicker.style.left = (rect.left) + 'px';
        reactionPicker.style.display = 'flex';
        currentReactionPicker = reactionPicker;
    }
    document.querySelectorAll('.reaction-option').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!currentNoteId) return;
            const reaction = this.dataset.reaction;
            const formData = new FormData();
            formData.append('action', 'add_reader_reaction');
            formData.append('note_id', currentNoteId);
            formData.append('reaction_type', reaction);
            fetch('/reader/reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    loadNotes();
                    reactionPicker.style.display = 'none';
                    currentNoteId = null;
                    currentReactionPicker = null;
                }
            });
        });
    });
    function reactNote(noteId, reactionType) {
        const formData = new FormData();
        formData.append('action', 'toggle_note_reaction');
        formData.append('note_id', noteId);
        formData.append('reaction_type', reactionType);
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadNotes();
        });
    }
    document.addEventListener('click', function(e) {
        if (currentReactionPicker && !currentReactionPicker.contains(e.target) && !e.target.closest('.btn-outline') && !e.target.closest('.reaction')) {
            currentReactionPicker.style.display = 'none';
            currentReactionPicker = null;
            currentNoteId = null;
        }
    });

    // ===== TIME AGO HELPER =====
    function timeAgo(timestamp) {
        const time = new Date(timestamp).getTime();
        const now = Date.now();
        const diff = Math.floor((now - time) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    // ===== CHALLENGE WIDGET =====
    function loadChallenge() {
        if (userId === 0) return;
        fetch('/reader/reader_ajax.php?action=get_monthly_challenge&user_id=' + userId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    const percent = Math.min(100, Math.round((data.progress / data.target) * 100));
                    challengeWidget.innerHTML = `
                        <div class="aw-challenge-widget">
                            <h4>📖 Monthly Challenge</h4>
                            <p>${data.goal}</p>
                            <div class="aw-challenge-progress">
                                <div class="aw-challenge-bar" style="width:${percent}%;"></div>
                                <span class="aw-challenge-percent">${percent}%</span>
                            </div>
                            <p class="aw-challenge-stats">${data.progress} / ${data.target} pages read</p>
                            <button class="btn btn-sm btn-primary" onclick="updateChallenge()">📈 Update Progress</button>
                        </div>
                    `;
                }
            });
    }
    window.updateChallenge = function() {
        const pages = prompt('How many pages did you read today?');
        if (pages && parseInt(pages) > 0) {
            const formData = new FormData();
            formData.append('action', 'update_challenge_progress');
            formData.append('user_id', userId);
            formData.append('pages_read', pages);
            fetch('/reader/reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                loadChallenge();
                alert('✅ Progress updated!');
            });
        }
    };
    if (userId > 0) loadChallenge();

    // ===== SESSION TRACKING =====
    if (userId > 0) {
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=start_session&book_id=' + bookId
        });
        window.addEventListener('beforeunload', function() {
            navigator.sendBeacon('/reader/reader_ajax.php', new URLSearchParams({
                action: 'end_session',
                book_id: bookId
            }));
        });
    }

    // ===== PROGRESS SYNC (visibility change) =====
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && userId > 0) {
            const formData = new FormData();
            formData.append('action', 'save_position');
            formData.append('book_id', bookId);
            formData.append('offset', readingMode === 'scroll' ? content.scrollTop : 0);
            formData.append('chapter', currentChapter);
            formData.append('percent', Math.round((readingMode === 'scroll' ? (content.scrollTop / (content.scrollHeight - content.clientHeight)) * 100 : (currentPage / totalPages) * 100)));
            navigator.sendBeacon('/reader/reader_ajax.php', formData);
        }
    });

    // ===== PDF FALLBACK =====
    <?php if (!$has_processed && $file_type === 'pdf'): ?>
    const pdfCanvas = document.getElementById('awPdfCanvas');
    const pdfPrev = document.getElementById('awPdfPrev');
    const pdfNext = document.getElementById('awPdfNext');
    const pdfPageInfo = document.getElementById('awPdfPageInfo');
    const pdfZoom = document.getElementById('awPdfZoom');
    let pdfDoc = null;
    let pageNum = 1;
    let pageRendering = false;
    let pageNumPending = null;
    let scale = 1;
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    function renderPage(num) {
        pageRendering = true;
        pdfDoc.getPage(num).then(function(page) {
            const viewport = page.getViewport({ scale: scale });
            pdfCanvas.height = viewport.height;
            pdfCanvas.width = viewport.width;
            const renderContext = {
                canvasContext: pdfCanvas.getContext('2d'),
                viewport: viewport
            };
            page.render(renderContext).promise.then(function() {
                pageRendering = false;
                if (pageNumPending !== null) {
                    renderPage(pageNumPending);
                    pageNumPending = null;
                }
            });
            pdfPageInfo.textContent = num + ' / ' + pdfDoc.numPages;
        });
    }
    function queueRenderPage(num) {
        if (pageRendering) {
            pageNumPending = num;
        } else {
            renderPage(num);
        }
    }
    function onPrevPage() {
        if (pageNum <= 1) return;
        pageNum--;
        queueRenderPage(pageNum);
    }
    function onNextPage() {
        if (pageNum >= pdfDoc.numPages) return;
        pageNum++;
        queueRenderPage(pageNum);
    }
    pdfjsLib.getDocument('<?php echo SITE_URL . '/' . $book['file_path']; ?>').promise.then(function(doc) {
        pdfDoc = doc;
        renderPage(pageNum);
    });
    pdfPrev.addEventListener('click', onPrevPage);
    pdfNext.addEventListener('click', onNextPage);
    pdfZoom.addEventListener('input', function() {
        scale = parseFloat(this.value);
        renderPage(pageNum);
    });
    <?php endif; ?>

    // ===== EPUB FALLBACK =====
    <?php if (!$has_processed && $file_type === 'epub'): ?>
    const epubViewer = document.getElementById('awEpubViewer');
    const epubPrev = document.getElementById('awEpubPrev');
    const epubNext = document.getElementById('awEpubNext');
    const epubPageInfo = document.getElementById('awEpubPageInfo');
    let book = ePub('<?php echo SITE_URL . '/' . $book['file_path']; ?>');
    let rendition = book.renderTo(epubViewer, { width: '100%', height: '100%', spread: 'none' });
    rendition.display();
    rendition.on('relocated', function(location) {
        epubPageInfo.textContent = 'Page ' + (location.start.displayedPage || 1);
    });
    epubPrev.addEventListener('click', function() { rendition.prev(); });
    epubNext.addEventListener('click', function() { rendition.next(); });
    <?php endif; ?>

    // ===== INITIAL PROGRESS =====
    setTimeout(() => {
        if (readingMode === 'scroll') {
            updateProgress(content.scrollTop);
        } else {
            // Horizontal mode already updates via showFlipPage
        }
    }, 200);

    // ===== GO TO PAGE FUNCTION (Enhanced) =====
    function goToPage(pageNum) {
        if (readingMode === 'scroll') {
            // Scroll mode: search for the page in the text container using data-page attributes
            const headings = textContainer.querySelectorAll('.chapter-heading, h2, h3');
            let target = null;
            headings.forEach(h => {
                const page = h.closest('[data-page]')?.dataset.page;
                if (page && parseInt(page) === pageNum) {
                    target = h;
                }
            });
            if (!target) {
                const paras = textContainer.querySelectorAll(`[data-page="${pageNum}"]`);
                if (paras.length > 0) target = paras[0];
            }
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                updateProgress(content.scrollTop);
            }
        } else if (readingMode === 'flip') {
            if (pageNum >= 1 && pageNum <= totalPages) {
                showFlipPage(pageNum - 1);
            }
        }
    }

    // ===== FETCH HIGHLIGHTS FOR PAGE (reusable) =====
    function fetchHighlightsForPage(page) {
        return new Promise((resolve) => {
            if (!userId) return resolve([]);
            const formData = new FormData();
            formData.append('action', 'list_highlights');
            formData.append('book_id', bookId);
            fetch('/reader/reader_ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        resolve(data.highlights.filter(h => h.chapter_index == page));
                    } else {
                        resolve([]);
                    }
                })
                .catch(() => resolve([]));
        });
    }

    // ===== SAVE POSITION (enhanced for both modes) =====
    function savePosition() {
        if (!userId) return;
        const formData = new FormData();
        formData.append('action', 'save_position');
        formData.append('book_id', bookId);
        formData.append('offset', readingMode === 'scroll' ? content.scrollTop : 0);
        formData.append('chapter', currentChapter);
        formData.append('percent', readingMode === 'scroll' ? Math.round((content.scrollTop / (content.scrollHeight - content.clientHeight)) * 100) : Math.round((currentChapter / totalPages) * 100));
        fetch('/reader/reader_ajax.php', { method: 'POST', body: formData });
    }

    // ===== READ THEME / MODE FROM LOCAL STORAGE =====
    document.querySelector(`.aw-mode-btn[data-mode="${readingMode}"]`)?.classList.add('active');
    if (readingMode === 'flip') {
        document.querySelector('.aw-mode-btn[data-mode="scroll"]')?.classList.remove('active');
        initFlipMode();
    }
});
</script>

</body>
</html>