<?php
ini_set('display_errors', 1); // <- Fixed typo here
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_helper.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$book_id) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed = $stmt->fetch(PDO::FETCH_ASSOC);
$has_processed = !empty($processed) && $processed['is_processed'] == 1;

$toc = $has_processed ? (json_decode($processed['toc_json'], true) ?? []) : [];

$pages = [];
if ($has_processed && !empty($processed['content_html'])) {
    preg_match_all('/<div class="page-content" data-page="(\d+)">(.*?)<\/div>/s', $processed['content_html'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $pages[] = $match[0];
    }
}
$total_pages = count($pages);

$user_progress = null;
$last_offset = 0;
$last_chapter = 0;
$progress_percent = 0;
$streak_days = 0;
$group_id = null;
$reading_status = 'not_started';

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
    
    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak = $stmt->fetchColumn();
    $streak_days = $streak ? (int)$streak : 0;

    $stmt = $db->prepare("
        SELECT g.id FROM reading_groups g
        JOIN group_members m ON g.id = m.group_id
        WHERE g.book_id = ? AND m.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$book_id, $user_id]);
    $group_id = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $reading_status = $stmt->fetchColumn() ?: 'not_started';
}

$bookmarks = [];
$highlights = [];
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id, chapter_index, note, created_at FROM bookmarks WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, created_at DESC");
    $stmt->execute([$user_id, $book_id]);
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT id, chapter_index, paragraph_index, text, color, note FROM highlights WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, paragraph_index");
    $stmt->execute([$user_id, $book_id]);
    $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$file_type = strtolower($book['file_type'] ?? 'unknown');
$file_path = __DIR__ . '/../' . $book['file_path'];
$file_exists = file_exists($file_path);

$last_page = $last_chapter > 0 && $last_chapter <= $total_pages ? $last_chapter : 1;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($book['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
    /* ===== FULL SCREEN RESET ===== */
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; width: 100%; overflow: hidden; }

/* ===== ANGELWRITES BRAND COLORS ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #EFD8D6; /* Matches vanilla */
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44, 30, 30, 0.08);
    --shadow-hover: 0 8px 30px rgba(44, 30, 30, 0.15);
    --input-bg: #ffffff;
    --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ===== READER APP CONTAINER ===== */
#reader-app {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: var(--bg); /* The pink/rose color from your screenshot */
    font-family: 'Inter', sans-serif;
    color: var(--text);
    z-index: 10000;
    display: flex;
    flex-direction: column;
}

/* ===== TOOLBAR (Clean, minimal) ===== */
#toolbar {
    height: 56px;
    min-height: 56px;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 16px;
    z-index: 10;
    flex-shrink: 0;
}
.toolbar-left .title {
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--dark);
    font-family: 'Playfair Display', Georgia, serif;
}
.toolbar-right button {
    background: none;
    border: none;
    font-size: 1.1rem;
    color: var(--text-light);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
}
.toolbar-right button:hover {
    color: var(--rose);
    background: rgba(219, 161, 162, 0.1);
}

/* ===== CONTENT AREA (Pink background from screenshot) ===== */
#page-viewport {
    flex: 1;
    background: var(--bg);
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}

/* ===== SCROLL MODE CONTAINER ===== */
#scroll-container {
    flex: 1;
    width: 100%;
    min-width: 100%;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
}

#scroll-container .page-content {
    width: 100%;
    max-width: 750px;
    margin: 0 auto 40px auto;
    flex-shrink: 0;
}

#highlight-tooltip, 
#reaction-picker, 
#annotation-popup, 
#search-bar {
    position: fixed !important;
    bottom: 80px !important;
    left: 20px !important;
    top: auto !important;
    right: auto !important;
    z-index: 100 !important;
    pointer-events: auto;
}

#search-bar.visible {
    bottom: 20px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
}

/* ===== FLIP MODE CONTAINER ===== */
#flip-container {
    display: none;
    width: 90%;
    max-width: 750px;
    height: 85%;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-hover);
    overflow: hidden;
}
#flip-container .reader-page {
    padding: 40px;
    height: 100%;
    overflow-y: auto;
    color: var(--text);
}

/* ===== TOC DRAWER ===== */
#toc-drawer {
    background: var(--white);
    border-left: 1px solid var(--border);
}
.toc-header {
    background: var(--vanilla);
    border-bottom: 1px solid var(--border);
}
.toc-list a {
    color: var(--text);
}
.toc-list a:hover {
    color: var(--rose);
    background: rgba(219, 161, 162, 0.1);
}

/* ===== SETTINGS PANEL ===== */
#settings-panel {
    background: var(--white);
    border-top: 1px solid var(--border);
}
.settings-group .btn-group button.active {
    background: var(--rose);
    border-color: var(--rose);
    color: white;
}
.settings-group .btn-group button:hover {
    border-color: var(--rose);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    #toolbar { height: 48px; padding: 0 8px; }
    .toolbar-left .title { font-size: 0.9rem; max-width: 160px; }
    #scroll-container .page-content { padding: 20px; }
}
/* ===== TOC LIST ===== */
.aw-toc-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.aw-toc-list li {
    padding: 4px 0;
}
.aw-toc-list a {
    color: var(--text);
    text-decoration: none;
    display: block;
    padding: 6px 8px;
    border-radius: 6px;
    transition: all 0.2s;
}
.aw-toc-list a:hover {
    background: rgba(219, 161, 162, 0.1);
    color: var(--rose);
}

/* ===== GROUP NOTES ===== */
.aw-notes-panel {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px 12px 0 0;
    box-shadow: 0 -4px 20px rgba(44, 30, 30, 0.1);
}
.aw-notes-header {
    background: var(--vanilla);
    border-bottom: 1px solid var(--border);
    border-radius: 12px 12px 0 0;
}
.aw-notes-header h3 {
    color: var(--dark);
    font-family: 'Playfair Display', Georgia, serif;
}
.aw-notes-header .badge {
    background: var(--rose);
    color: var(--white);
}
.aw-notes-header button {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 4px 12px;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--text);
    transition: all 0.2s;
}
.aw-notes-header button:hover {
    border-color: var(--rose);
    color: var(--rose);
}
.note-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}
.note-card.private {
    border-left: 4px solid #6c757d;
}
.note-author-info strong {
    color: var(--dark);
}
.note-author-info small {
    color: var(--text-light);
}
.note-text {
    color: var(--text);
}
.note-footer {
    border-top: 1px solid var(--border);
    padding-top: 8px;
    margin-top: 8px;
}
.note-reactions .reaction {
    background: var(--vanilla);
    padding: 0 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}
.note-reactions .reaction:hover {
    background: rgba(219, 161, 162, 0.2);
}
.note-footer button {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 2px 8px;
    cursor: pointer;
    font-size: 0.75rem;
    color: var(--text);
    transition: all 0.2s;
}
.note-footer button:hover {
    border-color: var(--rose);
    color: var(--rose);
}
#noteForm textarea {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 8px;
    background: var(--input-bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    resize: vertical;
    width: 100%;
}
#noteForm textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
#noteForm label {
    color: var(--text);
    font-size: 0.9rem;
}
#noteForm .btn {
    background: var(--rose);
    color: var(--white);
    border: none;
    padding: 4px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.8rem;
    transition: background 0.2s;
}
#noteForm .btn:hover {
    background: var(--rose-dark);
}
#noteForm .btn-secondary {
    background: var(--border);
    color: var(--text);
}
#noteForm .btn-secondary:hover {
    background: var(--text-light);
    color: var(--white);
}

/* ===== HIGHLIGHT TOOLTIP ===== */
.aw-highlight-tooltip {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-hover);
    padding: 6px 10px;
}
.aw-highlight-color {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: transform 0.2s;
}
.aw-highlight-color:hover {
    transform: scale(1.15);
}
.aw-highlight-color[data-color="yellow"] { background: #ffeb3b; }
.aw-highlight-color[data-color="green"] { background: #a5d6a7; }
.aw-highlight-color[data-color="blue"] { background: #90caf9; }
.aw-highlight-color[data-color="pink"] { background: #f48fb1; }
.aw-highlight-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text);
    font-size: 0.9rem;
    padding: 0 4px;
    transition: color 0.2s;
}
.aw-highlight-btn:hover {
    color: var(--rose);
}

/* ===== ANNOTATION POPUP ===== */
.aw-annotation-popup {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-hover);
    padding: 20px;
}
.aw-annotation-popup textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    resize: vertical;
    min-height: 60px;
    font-size: 0.9rem;
    background: var(--input-bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
}
.aw-annotation-popup textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.aw-annotation-actions button {
    padding: 4px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    transition: background 0.2s;
}
.aw-annotation-save {
    background: var(--rose);
    color: var(--white);
}
.aw-annotation-save:hover {
    background: var(--rose-dark);
}
.aw-annotation-cancel {
    background: var(--border);
    color: var(--text);
}
.aw-annotation-cancel:hover {
    background: var(--text-light);
    color: var(--white);
}

/* ===== SEARCH BAR ===== */
.aw-search-bar {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-hover);
    padding: 12px;
}
.aw-search-bar input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.9rem;
    background: var(--input-bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
}
.aw-search-bar input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.aw-search-bar button {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-light);
    font-size: 0.9rem;
    transition: color 0.2s;
}
.aw-search-bar button:hover {
    color: var(--rose);
}
#awSearchResults {
    margin-top: 8px;
    max-height: 200px;
    overflow-y: auto;
}
.aw-search-result {
    padding: 4px 8px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background 0.2s;
    font-size: 0.85rem;
}
.aw-search-result:hover {
    background: rgba(219, 161, 162, 0.1);
}
.aw-search-result strong {
    color: var(--rose);
}

/* ===== SHARE MODAL ===== */
.aw-share-modal {
    background: rgba(44, 30, 30, 0.5);
    backdrop-filter: blur(4px);
}
.aw-share-modal-content {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 24px;
    max-width: 400px;
    box-shadow: var(--shadow-hover);
}
.aw-share-modal-content h3 {
    color: var(--dark);
    font-family: 'Playfair Display', Georgia, serif;
    margin-top: 0;
}
.aw-share-options button {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--text);
    transition: all 0.2s;
    width: 100%;
    text-align: left;
}
.aw-share-options button:hover {
    border-color: var(--rose);
    background: rgba(219, 161, 162, 0.05);
}
.aw-share-options button i {
    margin-right: 8px;
    color: var(--rose);
}
.aw-share-modal-close {
    background: var(--rose);
    color: var(--white);
    border: none;
    padding: 8px 24px;
    border-radius: 30px;
    cursor: pointer;
    transition: background 0.2s;
    width: 100%;
    margin-top: 12px;
    font-weight: 600;
}
.aw-share-modal-close:hover {
    background: var(--rose-dark);
}
</style>    
</head>
<body>

<div id="reader-app">
    <div id="toolbar">
        <div class="toolbar-left">
            <button id="backBtn" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#555;"><i class="fas fa-arrow-left"></i></button>
            <span class="title"><?php echo htmlspecialchars($book['title']); ?></span>
            <?php if (isLoggedIn() && $streak_days > 0): ?>
            <span class="streak-badge">🔥 <?php echo $streak_days; ?>d</span>
            <?php endif; ?>
            <select id="readingStatus" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:0.8rem;background:#ffffff;color:var(--text);">
                <option value="not_started" <?php echo $reading_status == 'not_started' ? 'selected' : ''; ?>>📌 Not Started</option>
                <option value="currently_reading" <?php echo $reading_status == 'currently_reading' ? 'selected' : ''; ?>>📖 Currently Reading</option>
                <option value="finished" <?php echo $reading_status == 'finished' ? 'selected' : ''; ?>>✅ Finished</option>
                <option value="want_to_read" <?php echo $reading_status == 'want_to_read' ? 'selected' : ''; ?>>📚 Want to Read</option>
                <option value="dropped" <?php echo $reading_status == 'dropped' ? 'selected' : ''; ?>>❌ Dropped</option>
            </select>
        </div>
        <div class="toolbar-center">
            <div class="progress-ring">
                <svg viewBox="0 0 32 32">
                    <circle class="bg" cx="16" cy="16" r="14"/>
                    <circle class="fill" id="progressFill" cx="16" cy="16" r="14" stroke-dasharray="87.96" stroke-dashoffset="87.96"/>
                </svg>
                <span class="percent" id="progressPercent">0%</span>
            </div>
            <span id="pageNum">1</span> / <span id="totalPages"><?php echo $total_pages; ?></span>
        </div>
        <div class="toolbar-right">
            <button id="bookmarkBtn"><i class="far fa-bookmark"></i></button>
            <button id="tocBtn"><i class="fas fa-list-ul"></i></button>
            <button id="settingsBtn"><i class="fas fa-cog"></i></button>
            <button id="notesBtn"><i class="fas fa-sticky-note"></i></button>
            <button id="focusBtn"><i class="fas fa-expand"></i></button>
        </div>
    </div>

    <div id="page-viewport">
        <div id="scroll-container">
            <?php foreach ($pages as $page_html) { echo $page_html; } ?>
        </div>
        <div id="flip-container">
            <button class="aw-nav-btn prev" id="prevFlipBtn"><i class="fas fa-chevron-left"></i></button>
            <button class="aw-nav-btn next" id="nextFlipBtn"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <div id="settings-panel">
        <div class="settings-grid">
            <div class="settings-group">
                <label>Mode</label>
                <div class="btn-group" id="modeGroup">
                    <button data-mode="scroll" class="active">Scroll</button>
                    <button data-mode="flip">Page Flip</button>
                </div>
            </div>
            <div class="settings-group">
                <label>Theme</label>
                <div class="btn-group" id="themeGroup">
                    <button data-theme="paper">Paper</button>
                    <button data-theme="light" class="active">Light</button>
                    <button data-theme="dark">Dark</button>
                    <button data-theme="sepia">Sepia</button>
                </div>
            </div>
            <div class="settings-group">
                <label>Font Size</label>
                <div class="slider-group">
                    <button onclick="adjustFontSize(-5)">A-</button>
                    <input type="range" id="fontSizeSlider" min="70" max="160" value="100" step="5">
                    <button onclick="adjustFontSize(5)">A+</button>
                    <span id="fontSizeLabel">100%</span>
                </div>
            </div>
            <div class="settings-group">
                <label>Line Height</label>
                <div class="slider-group">
                    <button onclick="adjustLineHeight(-10)">-</button>
                    <input type="range" id="lineHeightSlider" min="140" max="220" value="180" step="10">
                    <button onclick="adjustLineHeight(10)">+</button>
                    <span id="lineHeightLabel">1.8</span>
                </div>
            </div>
            <div class="settings-group">
                <label>Letter Spacing</label>
                <div class="slider-group">
                    <button onclick="adjustLetterSpacing(-1)">-</button>
                    <input type="range" id="letterSpacingSlider" min="-2" max="4" value="0" step="1">
                    <button onclick="adjustLetterSpacing(1)">+</button>
                    <span id="letterSpacingLabel">0</span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
            <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="resumePosition()">↩️ Resume</button>
            <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" id="resetProgressBtn">↺ Reset Progress</button>
            <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" id="exportHighlightsBtn">📤 Export Highlights</button>
        </div>
    </div>

    <div id="toc-drawer">
        <div class="toc-header">
            <h3>Table of Contents</h3>
            <button class="toc-close" id="tocClose">&times;</button>
        </div>
        <div class="toc-body" id="tocBody">
            <?php if (count($toc) > 0): ?>
                <ul class="toc-list">
                <?php foreach ($toc as $entry): ?>
                    <li><a href="#" class="toc-link" data-chapter="<?php echo $entry['page']; ?>"><?php echo htmlspecialchars($entry['title']); ?></a></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="toc-empty">No table of contents available.</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="notes-panel">
        <div class="notes-header">
            <h3>📝 Group Notes</h3>
            <div>
                <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" id="addNoteBtn">+ Add</button>
                <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" id="notesClose">&times;</button>
            </div>
        </div>
        <div class="notes-body" id="notesBody">
            <div id="notesList"><p class="empty-notes">No notes for this chapter.</p></div>
            <div id="noteForm">
                <textarea id="noteText" rows="2" placeholder="Write a note..."></textarea>
                <div style="margin:6px 0;"><label><input type="checkbox" id="notePrivate"> Private</label></div>
                <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:var(--rose);color:white;cursor:pointer;" onclick="submitNote()">Post</button>
                <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="toggleNoteForm()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="reaction-picker">
        <button data-reaction="👍">👍</button>
        <button data-reaction="❤️">❤️</button>
        <button data-reaction="🙏">🙏</button>
        <button data-reaction="🤔">🤔</button>
        <button data-reaction="📖">📖</button>
    </div>

    <div id="highlight-tooltip">
        <button class="highlight-color" data-color="yellow"></button>
        <button class="highlight-color" data-color="green"></button>
        <button class="highlight-color" data-color="blue"></button>
        <button class="highlight-color" data-color="pink"></button>
        <button class="highlight-btn" id="highlightAnnotate"><i class="fas fa-pen"></i></button>
    </div>

    <div id="annotation-popup">
        <textarea id="annotationText" rows="3" placeholder="Add a note…"></textarea>
        <div class="annotation-actions">
            <button class="annotation-save" id="annotationSave">Save</button>
            <button class="annotation-cancel" id="annotationCancel">Cancel</button>
        </div>
    </div>

    <div id="search-bar">
        <input type="text" id="searchInput" placeholder="Search in this book…">
        <button onclick="closeSearch()" style="background:none;border:none;cursor:pointer;font-size:0.9rem;float:right;">✕</button>
        <div id="searchResults"></div>
    </div>

    <div id="share-modal">
        <div class="share-content">
            <h3>Share this page</h3>
            <div class="share-options">
                <button onclick="share('facebook')"><i class="fab fa-facebook-f"></i> Facebook</button>
                <button onclick="share('twitter')"><i class="fab fa-twitter"></i> Twitter</button>
                <button onclick="share('whatsapp')"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                <button onclick="share('copy')"><i class="fas fa-link"></i> Copy Link</button>
            </div>
            <button class="share-close" onclick="closeShare()">Close</button>
        </div>
    </div>

    <div id="challenge-widget"></div>

    <div id="overlay" onclick="closeAll()"></div>
</div>

<script>
(function() {
    'use strict';

    const pages = <?php echo json_encode($pages); ?>;
    const totalPages = pages.length;
    const bookId = <?php echo $book_id; ?>;
    const userId = <?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>;
    const groupId = <?php echo $group_id ? (int)$group_id : 0; ?>;
    const toc = <?php echo json_encode($toc); ?>;
    const lastPage = <?php echo $last_page; ?>;

    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container');
    const pageNumEl = document.getElementById('pageNum');
    const totalPagesEl = document.getElementById('totalPages');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
    const bookmarkBtn = document.getElementById('bookmarkBtn');
    const settingsPanel = document.getElementById('settings-panel');
    const tocDrawer = document.getElementById('toc-drawer');
    const tocClose = document.getElementById('tocClose');
    const notesPanel = document.getElementById('notes-panel');
    const notesList = document.getElementById('notesList');
    const notesBody = document.getElementById('notesBody');
    const addNoteBtn = document.getElementById('addNoteBtn');
    const notesClose = document.getElementById('notesClose');
    const noteForm = document.getElementById('noteForm');
    const noteText = document.getElementById('noteText');
    const notePrivate = document.getElementById('notePrivate');
    const overlay = document.getElementById('overlay');
    const focusBtn = document.getElementById('focusBtn');
    const readingStatus = document.getElementById('readingStatus');
    const resetProgressBtn = document.getElementById('resetProgressBtn');
    const exportHighlightsBtn = document.getElementById('exportHighlightsBtn');
    const highlightTooltip = document.getElementById('highlight-tooltip');
    const annotationPopup = document.getElementById('annotation-popup');
    const annotationText = document.getElementById('annotationText');
    const annotationSave = document.getElementById('annotationSave');
    const annotationCancel = document.getElementById('annotationCancel');
    const searchBar = document.getElementById('search-bar');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const reactionPicker = document.getElementById('reaction-picker');
    const challengeWidget = document.getElementById('challenge-widget');
    const backBtn = document.getElementById('backBtn');
    const prevFlipBtn = document.getElementById('prevFlipBtn');
    const nextFlipBtn = document.getElementById('nextFlipBtn');

    let currentPage = Math.min(lastPage, totalPages) || 1;
    let readingMode = localStorage.getItem('reader_mode') || 'scroll';
    let focusMode = false;
    let isBookmarked = false;
    let touchStartX = 0;
    let currentNoteId = null;
    let selectedText = '';
    let selectedRange = null;

    // Flip mode specific state
    let flipCurrentChunkIndex = 0;
    let flipChunks = [];

    totalPagesEl.textContent = totalPages;

    if (localStorage.getItem('reader_mode') === 'flip') {
        readingMode = 'flip';
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="flip"]').classList.add('active');
        switchMode('flip');
    } else {
        switchMode('scroll');
    }
    goToPage(currentPage);
    loadBookmarkStatus();
    if (userId > 0) startSession();
    if (userId > 0) loadChallenge();

    readingStatus.addEventListener('change', function() {
        if (userId === 0) { alert('Please log in to set reading status.'); return; }
        var data = new FormData();
        data.append('action', 'set_reading_status');
        data.append('book_id', bookId);
        data.append('status', this.value);
        navigator.sendBeacon('/reader/reader_ajax.php', data);
    });

    backBtn.addEventListener('click', function() {
        window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>';
    });

    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode', mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            flipContainer.style.display = 'flex';
            prepareFlipChunks(currentPage);
            renderFlipChunk(0);
            prevFlipBtn.style.display = 'flex';
            nextFlipBtn.style.display = 'flex';
        } else {
            scrollContainer.style.display = 'block';
            flipContainer.style.display = 'none';
            prevFlipBtn.style.display = 'none';
            nextFlipBtn.style.display = 'none';
            var target = document.querySelector('.page-content[data-page="' + currentPage + '"]');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        updateUI(currentPage);
    }

    function prepareFlipChunks(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        var html = pages[pageNum - 1];
        // Split the content of the page into smaller chunks
        var paragraphs = html.split('</p>');
        var chunks = [];
        var currentChunk = '';
        var chunkSize = 6; // Number of paragraphs per chunk
        var count = 0;
        for (var i = 0; i < paragraphs.length; i++) {
            var para = paragraphs[i].trim();
            if (para.length === 0) continue;
            currentChunk += para + '</p>';
            count++;
            if (count >= chunkSize) {
                chunks.push(currentChunk);
                currentChunk = '';
                count = 0;
            }
        }
        if (currentChunk.length > 0) {
            chunks.push(currentChunk);
        }
        flipChunks = chunks;
        flipCurrentChunkIndex = 0;
    }

    function renderFlipChunk(index) {
        if (index < 0) index = 0;
        if (index >= flipChunks.length) {
            // Move to the next page
            if (currentPage < totalPages) {
                currentPage++;
                prepareFlipChunks(currentPage);
                renderFlipChunk(0);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
            return;
        }
        flipCurrentChunkIndex = index;
        var html = flipChunks[index];
        // Apply highlights if logged in
        if (userId > 0) {
            var saved = getHighlightsForPage(currentPage);
            saved.forEach(function(h) {
                html = html.replaceAll(h.text, '<span class="highlight-' + h.color + '">' + h.text + '</span>');
            });
        }
        flipContainer.innerHTML = `
            <button class="aw-nav-btn prev" id="prevFlipBtn"><i class="fas fa-chevron-left"></i></button>
            <button class="aw-nav-btn next" id="nextFlipBtn"><i class="fas fa-chevron-right"></i></button>
            <div class="reader-page">${html}</div>
        `;
        // Re-attach event listeners to the newly created buttons
        document.getElementById('prevFlipBtn').addEventListener('click', prevFlipPage);
        document.getElementById('nextFlipBtn').addEventListener('click', nextFlipPage);
        updateUI(currentPage);
        // Calculate progress based on chunks
        var totalChunks = 0;
        for (var i = currentPage - 1; i < totalPages; i++) {
            var tempHtml = pages[i];
            var tempParas = tempHtml.split('</p>');
            totalChunks += Math.ceil((tempParas.length - 1) / 6);
        }
        // For simplicity, updateUI already handles the page percentage.
    }

    function nextFlipPage() {
        if (flipCurrentChunkIndex < flipChunks.length - 1) {
            renderFlipChunk(flipCurrentChunkIndex + 1);
        } else {
            if (currentPage < totalPages) {
                currentPage++;
                prepareFlipChunks(currentPage);
                renderFlipChunk(0);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
        }
    }

    function prevFlipPage() {
        if (flipCurrentChunkIndex > 0) {
            renderFlipChunk(flipCurrentChunkIndex - 1);
        } else {
            if (currentPage > 1) {
                currentPage--;
                prepareFlipChunks(currentPage);
                var lastChunkIndex = flipChunks.length - 1;
                renderFlipChunk(lastChunkIndex);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
        }
    }

    function nextPage() {
        if (readingMode === 'flip') {
            nextFlipPage();
        } else if (currentPage < totalPages) {
            goToPage(currentPage + 1);
        }
    }

    function prevPage() {
        if (readingMode === 'flip') {
            prevFlipPage();
        } else if (currentPage > 1) {
            goToPage(currentPage - 1);
        }
    }

    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip') {
            prepareFlipChunks(pageNum);
            renderFlipChunk(0);
        } else {
            var target = document.querySelector('.page-content[data-page="' + pageNum + '"]');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            updateUI(pageNum);
        }
        savePosition();
        loadNotes();
    }

    function updateUI(page) {
        pageNumEl.textContent = page;
        var percent = Math.round((page / totalPages) * 100);
        var circumference = 2 * Math.PI * 14;
        var offset = circumference - (percent / 100) * circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = percent + '%';
    }

    function savePosition() {
        if (userId === 0) return;
        var data = new FormData();
        data.append('action', 'save_position');
        data.append('book_id', bookId);
        data.append('chapter', currentPage);
        data.append('percent', Math.round((currentPage / totalPages) * 100));
        navigator.sendBeacon('/reader/reader_ajax.php', data);
    }

    function getHighlightsForPage(page) {
        var result = [];
        if (userId === 0) return result;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', false);
        var fd = new FormData();
        fd.append('action', 'list_highlights');
        fd.append('book_id', bookId);
        xhr.send(fd);
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.success) {
                data.highlights.forEach(function(h) {
                    if (h.chapter_index == page) result.push(h);
                });
            }
        } catch(e) {}
        return result;
    }

    function loadBookmarkStatus() {
        if (userId === 0) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', false);
        var fd = new FormData();
        fd.append('action', 'list_bookmarks');
        fd.append('book_id', bookId);
        xhr.send(fd);
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.success) {
                var exists = false;
                data.bookmarks.forEach(function(b) {
                    if (b.chapter_index == currentPage) exists = true;
                });
                isBookmarked = exists;
                if (exists) {
                    bookmarkBtn.querySelector('i').className = 'fas fa-bookmark';
                    bookmarkBtn.style.color = 'var(--rose)';
                } else {
                    bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
                    bookmarkBtn.style.color = '#555';
                }
            }
        } catch(e) {}
    }

    bookmarkBtn.addEventListener('click', function() {
        if (userId === 0) { alert('Please log in to bookmark.'); return; }
        if (isBookmarked) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            var fd = new FormData();
            fd.append('action', 'remove_bookmark');
            fd.append('book_id', bookId);
            xhr.send(fd);
            isBookmarked = false;
            bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
            bookmarkBtn.style.color = '#555';
        } else {
            var xhr2 = new XMLHttpRequest();
            xhr2.open('POST', '/reader/reader_ajax.php', true);
            var fd2 = new FormData();
            fd2.append('action', 'add_bookmark');
            fd2.append('book_id', bookId);
            fd2.append('chapter', currentPage);
            fd2.append('offset', 0);
            xhr2.send(fd2);
            isBookmarked = true;
            bookmarkBtn.querySelector('i').className = 'fas fa-bookmark';
            bookmarkBtn.style.color = 'var(--rose)';
        }
    });

    document.querySelectorAll('#modeGroup button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mode = this.dataset.mode;
            document.querySelectorAll('#modeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            switchMode(mode);
        });
    });

    document.querySelectorAll('#themeGroup button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var theme = this.dataset.theme;
            document.getElementById('reader-app').className = 'theme-' + theme;
            document.querySelectorAll('#themeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            localStorage.setItem('reader_theme', theme);
        });
    });

    var savedTheme = localStorage.getItem('reader_theme') || 'light';
    document.getElementById('reader-app').className = 'theme-' + savedTheme;
    var themeBtn = document.querySelector('#themeGroup [data-theme="' + savedTheme + '"]');
    if (themeBtn) themeBtn.classList.add('active');

    document.getElementById('fontSizeSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.fontSize = val + '%'; });
        document.getElementById('fontSizeLabel').textContent = val + '%';
        localStorage.setItem('reader_font_size', val);
    });

    window.adjustFontSize = function(amount) {
        var slider = document.getElementById('fontSizeSlider');
        var val = parseInt(slider.value) + amount;
        val = Math.min(160, Math.max(70, val));
        slider.value = val;
        slider.dispatchEvent(new Event('input'));
    };

    var savedSize = localStorage.getItem('reader_font_size') || 100;
    document.getElementById('fontSizeSlider').value = savedSize;
    document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.fontSize = savedSize + '%'; });
    document.getElementById('fontSizeLabel').textContent = savedSize + '%';

    document.getElementById('lineHeightSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.lineHeight = (val / 100).toFixed(1); });
        document.getElementById('lineHeightLabel').textContent = (val / 100).toFixed(1);
        localStorage.setItem('reader_line_height', val);
    });

    window.adjustLineHeight = function(amount) {
        var slider = document.getElementById('lineHeightSlider');
        var val = parseInt(slider.value) + amount;
        val = Math.min(220, Math.max(140, val));
        slider.value = val;
        slider.dispatchEvent(new Event('input'));
    };

    var savedLine = localStorage.getItem('reader_line_height') || 180;
    document.getElementById('lineHeightSlider').value = savedLine;
    document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.lineHeight = (savedLine / 100).toFixed(1); });
    document.getElementById('lineHeightLabel').textContent = (savedLine / 100).toFixed(1);

    document.getElementById('letterSpacingSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.letterSpacing = val + 'px'; });
        document.getElementById('letterSpacingLabel').textContent = val;
        localStorage.setItem('reader_letter_spacing', val);
    });

    window.adjustLetterSpacing = function(amount) {
        var slider = document.getElementById('letterSpacingSlider');
        var val = parseInt(slider.value) + amount;
        val = Math.min(4, Math.max(-2, val));
        slider.value = val;
        slider.dispatchEvent(new Event('input'));
    };

    var savedSpacing = localStorage.getItem('reader_letter_spacing') || 0;
    document.getElementById('letterSpacingSlider').value = savedSpacing;
    document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.letterSpacing = savedSpacing + 'px'; });
    document.getElementById('letterSpacingLabel').textContent = savedSpacing;

    document.getElementById('page-viewport').addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('a')) return;
        if (readingMode === 'flip') {
            var rect = this.getBoundingClientRect();
            var x = e.clientX - rect.left;
            if (x > rect.width / 2) nextPage();
            else prevPage();
        }
    });

    document.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    });

    document.addEventListener('touchend', function(e) {
        if (readingMode === 'flip') {
            var diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) nextPage();
                else prevPage();
            }
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight') nextPage();
        else if (e.key === 'ArrowLeft') prevPage();
        else if (e.key === 'Escape') {
            window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>';
        }
        else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            toggleSearch();
        }
    });

    document.getElementById('settingsBtn').addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        overlay.classList.toggle('active', settingsPanel.classList.contains('open'));
    });

    document.getElementById('tocBtn').addEventListener('click', function() {
        tocDrawer.classList.toggle('open');
        overlay.classList.toggle('active', tocDrawer.classList.contains('open'));
    });

    tocClose.addEventListener('click', function() {
        tocDrawer.classList.remove('open');
        overlay.classList.remove('active');
    });

    document.querySelectorAll('.toc-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var page = parseInt(this.dataset.chapter);
            if (page >= 1 && page <= totalPages) {
                goToPage(page);
                tocDrawer.classList.remove('open');
                overlay.classList.remove('active');
            }
        });
    });

    document.getElementById('notesBtn').addEventListener('click', function() {
        if (groupId === 0) { alert('You are not in a reading group for this book.'); return; }
        notesPanel.classList.toggle('open');
        overlay.classList.toggle('active', notesPanel.classList.contains('open'));
        if (notesPanel.classList.contains('open')) loadNotes();
    });

    notesClose.addEventListener('click', function() {
        notesPanel.classList.remove('open');
        overlay.classList.remove('active');
    });

    function loadNotes() {
        if (groupId === 0) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/reader/reader_ajax.php?action=get_notes&group_id=' + groupId + '&book_id=' + bookId + '&chapter=' + currentPage, true);
        xhr.onload = function() {
            try {
                var data = JSON.parse(this.responseText);
                if (data.success) {
                    var html = '';
                    if (data.notes.length === 0) {
                        html = '<p class="empty-notes">No notes for this chapter.</p>';
                    } else {
                        data.notes.forEach(function(n) {
                            var reactionsHtml = '';
                            if (n.reactions && n.reactions.length > 0) {
                                n.reactions.forEach(function(r) {
                                    reactionsHtml += '<span class="reaction" onclick="reactNote(' + n.id + ', \'' + r.reaction_type + '\')">' + r.reaction_type + ' ' + r.count + '</span>';
                                });
                            }
                            var canReact = !n.is_private || n.user_id == userId;
                            var isMyNote = n.user_id == userId;
                            html += '<div class="note-card' + (n.is_private ? ' private' : '') + '">';
                            html += '<div class="note-author">';
                            html += '<div class="note-avatar-placeholder">' + (n.display_name || n.username).charAt(0).toUpperCase() + '</div>';
                            html += '<div class="note-author-info"><strong>' + (n.display_name || n.username) + '</strong> <small>' + timeAgo(n.created_at) + '</small>';
                            if (n.is_private) html += ' <span class="badge-private">🔒 Private</span>';
                            html += '</div></div>';
                            html += '<p class="note-text">' + n.text + '</p>';
                            html += '<div class="note-footer">';
                            html += '<div class="note-reactions">' + reactionsHtml;
                            if (canReact) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="showReactionPicker(' + n.id + ', event)">➕</button>';
                            html += '</div>';
                            if (isMyNote) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="deleteNote(' + n.id + ')">🗑️</button>';
                            html += '</div></div>';
                        });
                    }
                    notesList.innerHTML = html;
                }
            } catch(e) {}
        };
        xhr.send();
    }

    window.toggleNoteForm = function() {
        noteForm.style.display = noteForm.style.display === 'none' ? 'block' : 'none';
    };

    window.submitNote = function() {
        var text = noteText.value.trim();
        var isPrivate = notePrivate.checked ? 1 : 0;
        if (!text) return alert('Please enter a note.');
        var data = new FormData();
        data.append('action', 'add_reader_note');
        data.append('group_id', groupId);
        data.append('book_id', bookId);
        data.append('chapter_index', currentPage);
        data.append('text', text);
        data.append('is_private', isPrivate);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', true);
        xhr.onload = function() {
            try {
                var d = JSON.parse(this.responseText);
                if (d.success) {
                    loadNotes();
                    noteText.value = '';
                    notePrivate.checked = false;
                    noteForm.style.display = 'none';
                } else {
                    alert('Error: ' + d.error);
                }
            } catch(e) { alert('Error submitting note.'); }
        };
        xhr.send(data);
    };

    addNoteBtn.addEventListener('click', function() {
        noteForm.style.display = noteForm.style.display === 'none' ? 'block' : 'none';
        if (noteForm.style.display === 'block') noteText.focus();
    });

    window.deleteNote = function(noteId) {
        if (!confirm('Delete this note?')) return;
        var data = new FormData();
        data.append('action', 'delete_reader_note');
        data.append('note_id', noteId);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', true);
        xhr.onload = function() { loadNotes(); };
        xhr.send(data);
    };

    window.reactNote = function(noteId, reaction) {
        var data = new FormData();
        data.append('action', 'toggle_note_reaction');
        data.append('note_id', noteId);
        data.append('reaction_type', reaction);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', true);
        xhr.onload = function() { loadNotes(); };
        xhr.send(data);
    };

    window.showReactionPicker = function(noteId, event) {
        currentNoteId = noteId;
        var btn = event.target.closest('button');
        var rect = btn.getBoundingClientRect();
        reactionPicker.style.top = (rect.bottom + 8) + 'px';
        reactionPicker.style.left = (rect.left) + 'px';
        reactionPicker.style.display = 'flex';
    };

    reactionPicker.querySelectorAll('button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!currentNoteId) return;
            var reaction = this.dataset.reaction;
            var data = new FormData();
            data.append('action', 'add_reader_reaction');
            data.append('note_id', currentNoteId);
            data.append('reaction_type', reaction);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.onload = function() {
                loadNotes();
                reactionPicker.style.display = 'none';
                currentNoteId = null;
            };
            xhr.send(data);
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#reaction-picker') && !e.target.closest('button')) {
            reactionPicker.style.display = 'none';
            currentNoteId = null;
        }
    });

    focusBtn.addEventListener('click', function() {
        focusMode = !focusMode;
        document.getElementById('reader-app').classList.toggle('focus-mode', focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
        }
    });

    window.closeAll = function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.classList.remove('open');
        overlay.classList.remove('active');
    };

    overlay.addEventListener('click', closeAll);

    window.resumePosition = function() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(function() {
                    var target = document.querySelector('.page-content[data-page="' + lastPage + '"]');
                    if (target) target.scrollIntoView({ block: 'start' });
                }, 100);
            }
        }
    };

    resetProgressBtn.addEventListener('click', function() {
        if (userId === 0) return;
        if (confirm('Reset reading progress for this book?')) {
            var data = new FormData();
            data.append('action', 'reset_progress');
            data.append('book_id', bookId);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.send(data);
            goToPage(1);
            alert('✅ Progress reset.');
        }
    });

    exportHighlightsBtn.addEventListener('click', function() {
        if (userId === 0) return;
        var data = new FormData();
        data.append('action', 'export_highlights');
        data.append('book_id', bookId);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/reader/reader_ajax.php', true);
        xhr.responseType = 'blob';
        xhr.onload = function() {
            var url = URL.createObjectURL(this.response);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'highlights.txt';
            a.click();
            URL.revokeObjectURL(url);
        };
        xhr.send(data);
    });

    window.toggleSearch = function() {
        searchBar.classList.toggle('visible');
        if (searchBar.classList.contains('visible')) searchInput.focus();
    };

    window.closeSearch = function() {
        searchBar.classList.remove('visible');
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
    };

    searchInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        if (q.length < 2) { searchResults.innerHTML = ''; searchResults.style.display = 'none'; return; }
        if (readingMode === 'flip') {
            var found = [];
            pages.forEach(function(html, idx) {
                var text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
                if (text.toLowerCase().includes(q)) {
                    found.push({ page: idx + 1, snippet: text.substring(0, 80) + '…' });
                }
            });
            if (found.length === 0) {
                searchResults.innerHTML = '<div style="color:#999;">No matches</div>';
                searchResults.style.display = 'block';
            } else {
                var html = '';
                found.slice(0, 10).forEach(function(f) {
                    html += '<div class="search-result" onclick="goToPage(' + f.page + ')"><strong>Page ' + f.page + '</strong> – ' + f.snippet + '</div>';
                });
                searchResults.innerHTML = html;
                searchResults.style.display = 'block';
            }
        } else {
            var text = document.querySelector('#scroll-container').innerText;
            var lines = text.split('\n');
            var html = '';
            for (var i = 0; i < lines.length; i++) {
                if (lines[i].toLowerCase().includes(q)) {
                    html += '<div class="search-result">' + lines[i] + '</div>';
                    if (html.split('</div>').length > 20) break;
                }
            }
            if (html) {
                searchResults.innerHTML = html;
                searchResults.style.display = 'block';
            } else {
                searchResults.innerHTML = '<div style="color:#999;">No matches</div>';
                searchResults.style.display = 'block';
            }
        }
    });

    function share(platform) {
        var url = window.location.origin + '/reader/reader.php?id=' + bookId + '&chapter=' + currentPage;
        var text = '📖 I\'m reading on AngelWrites!';
        switch(platform) {
            case 'facebook': window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank'); break;
            case 'twitter': window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(url), '_blank'); break;
            case 'whatsapp': window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(text + ' ' + url), '_blank'); break;
            case 'copy': navigator.clipboard.writeText(url).then(function() { alert('✅ Copied!'); }).catch(function() {
                var ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                alert('✅ Copied!');
            }); break;
        }
        closeShare();
    }

    window.closeShare = function() { document.getElementById('share-modal').classList.remove('visible'); overlay.classList.remove('active'); };

    document.getElementById('share-modal').querySelector('.share-close').addEventListener('click', closeShare);

    function loadChallenge() {
        if (userId === 0) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/reader/reader_ajax.php?action=get_monthly_challenge&user_id=' + userId, true);
        xhr.onload = function() {
            try {
                var data = JSON.parse(this.responseText);
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    var percent = Math.min(100, Math.round((data.progress / data.target) * 100));
                    challengeWidget.innerHTML = '<h4>📖 Monthly Challenge</h4><p>' + data.goal + '</p><div class="challenge-progress"><div class="bar" style="width:' + percent + '%;"></div></div><p style="font-size:0.9rem;">' + data.progress + ' / ' + data.target + ' pages</p><button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:var(--rose);color:white;cursor:pointer;" onclick="updateChallenge()">📈 Update</button>';
                }
            } catch(e) {}
        };
        xhr.send();
    }

    window.updateChallenge = function() {
        var pagesRead = prompt('How many pages did you read today?');
        if (pagesRead && parseInt(pagesRead) > 0) {
            var data = new FormData();
            data.append('action', 'update_challenge_progress');
            data.append('user_id', userId);
            data.append('pages_read', pagesRead);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.onload = function() { loadChallenge(); alert('✅ Updated!'); };
            xhr.send(data);
        }
    };

    function timeAgo(timestamp) {
        var diff = Date.now() - new Date(timestamp).getTime();
        var secs = Math.floor(diff / 1000);
        if (secs < 60) return 'just now';
        if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
        if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
        if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    function startSession() {
        var data = new FormData();
        data.append('action', 'start_session');
        data.append('book_id', bookId);
        navigator.sendBeacon('/reader/reader_ajax.php', data);
    }

    window.addEventListener('beforeunload', function() {
        if (userId > 0) {
            var data = new FormData();
            data.append('action', 'end_session');
            data.append('book_id', bookId);
            navigator.sendBeacon('/reader/reader_ajax.php', data);
        }
    });

    function getSelectedText() { return window.getSelection().toString().trim(); }
    function getSelectionRange() { var sel = window.getSelection(); return sel.rangeCount > 0 ? sel.getRangeAt(0) : null; }
    function showHighlightTooltip() {
        selectedText = getSelectedText();
        selectedRange = getSelectionRange();
        if (!selectedText || !selectedRange) { highlightTooltip.classList.remove('visible'); return; }
        var rect = selectedRange.getBoundingClientRect();
        highlightTooltip.style.top = (rect.top - 50) + 'px';
        highlightTooltip.style.left = (rect.left + rect.width / 2 - 60) + 'px';
        highlightTooltip.classList.add('visible');
    }
    document.addEventListener('mouseup', showHighlightTooltip);
    document.addEventListener('touchend', function() { setTimeout(showHighlightTooltip, 300); });

    document.querySelectorAll('.highlight-color').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var color = this.dataset.color;
            if (selectedText && selectedRange) {
                var data = new FormData();
                data.append('action', 'add_highlight');
                data.append('book_id', bookId);
                data.append('chapter', currentPage);
                data.append('text', selectedText);
                data.append('color', color);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/reader/reader_ajax.php', true);
                xhr.onload = function() {
                    var span = document.createElement('span');
                    span.className = 'highlight-' + color;
                    span.textContent = selectedText;
                    selectedRange.deleteContents();
                    selectedRange.insertNode(span);
                    highlightTooltip.classList.remove('visible');
                };
                xhr.send(data);
            }
        });
    });

    document.getElementById('highlightAnnotate').addEventListener('click', function() {
        if (selectedText && selectedRange) {
            annotationPopup.classList.add('visible');
            annotationText.value = '';
            annotationText.focus();
            highlightTooltip.classList.remove('visible');
        }
    });

    annotationSave.addEventListener('click', function() {
        var note = annotationText.value.trim();
        if (note && selectedText && selectedRange) {
            var data = new FormData();
            data.append('action', 'add_highlight');
            data.append('book_id', bookId);
            data.append('chapter', currentPage);
            data.append('text', selectedText);
            data.append('color', 'yellow');
            data.append('note', note);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.onload = function() {
                var span = document.createElement('span');
                span.className = 'highlight-yellow.annotation';
                span.textContent = selectedText;
                selectedRange.deleteContents();
                selectedRange.insertNode(span);
                annotationPopup.classList.remove('visible');
            };
            xhr.send(data);
        }
    });

    annotationCancel.addEventListener('click', function() {
        annotationPopup.classList.remove('visible');
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#highlight-tooltip') && !e.target.closest('#annotation-popup')) {
            highlightTooltip.classList.remove('visible');
            annotationPopup.classList.remove('visible');
        }
    });

    window.goToPage = goToPage;

})();
</script>
</body>
</html>