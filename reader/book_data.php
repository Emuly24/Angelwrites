<?php
// ============================================================
//  BOOK_DATA.PHP – Fetch comprehensive book data for the reader
//  Includes book details, processed content, TOC, and user progress.
//  Returns an associative array for use in reader.php.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/reader_functions.php'; // for detectChapters()

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Validate book ID ---
if (!$book_id) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// --- Fetch book details ---
$stmt = $db->prepare("
    SELECT b.*, u.username as author_name, u.display_name as author_display
    FROM books b
    LEFT JOIN users u ON b.author_id = u.id
    WHERE b.id = ?
");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// --- Increment view count ---
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// --- Fetch processed content ---
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed = $stmt->fetch(PDO::FETCH_ASSOC);
$has_processed = !empty($processed) && $processed['is_processed'] == 1;

// --- Parse TOC if available ---
$toc = $has_processed ? (json_decode($processed['toc_json'], true) ?? []) : [];

// --- Parse pages from processed HTML ---
$pages = [];
if ($has_processed && !empty($processed['content_html'])) {
    preg_match_all('/<div class="page-content" data-page="(\d+)">(.*?)<\/div>/s', $processed['content_html'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $pages[] = $match[2];
    }
}
$total_pages = count($pages);

// --- Detect chapters ---
if ($has_processed && $total_pages > 0) {
    list($chapterMap, $chapterTitles, $pageToChapter) = detectChapters($pages);
} else {
    $chapterMap = [];
    $chapterTitles = [];
    $pageToChapter = [];
}

// --- User‑specific data (if logged in) ---
$user_progress = null;
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;

if ($user_id) {
    $stmt = $db->prepare("
        SELECT progress_percent, position_section, position_offset, last_accessed_at
        FROM reading_progress
        WHERE user_id = ? AND book_id = ?
    ");
    $stmt->execute([$user_id, $book_id]);
    $user_progress = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- Build final data array ---
return [
    // Book core
    'book' => $book,
    'book_id' => $book_id,
    'title' => $book['title'],
    'author' => $book['author_display'] ?: $book['author_name'] ?: 'Unknown Author',
    'cover_path' => $book['cover_path'] ?? '',
    'description' => $book['description'] ?? '',
    'genre' => $book['genre'] ?? '',
    'publication_year' => $book['publication_year'] ?? null,
    'file_type' => $book['file_type'] ?? 'unknown',
    'file_path' => $book['file_path'] ?? '',

    // Content processing
    'has_processed' => $has_processed,
    'processed' => $processed,
    'pages' => $pages,
    'total_pages' => $total_pages,
    'toc' => $toc,

    // Chapter mapping
    'chapter_map' => $chapterMap,
    'chapter_titles' => $chapterTitles,
    'page_to_chapter' => $pageToChapter,

    // User progress (if logged in)
    'user_progress' => $user_progress,
    'user_id' => $user_id,

    // Misc
    'view_count' => $book['view_count'] + 1,
];