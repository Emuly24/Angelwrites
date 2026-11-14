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

// --- S1 (F18): paid books are readable only by the admin or a member with a completed purchase.
// Before the DB repair the purchases table may not exist; that counts as "not purchased".
if (empty($book['is_free'])) {
    $reader_allowed = isAdmin();
    if (!$reader_allowed && isLoggedIn()) {
        try {
            $pstmt = $db->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND book_id = ? AND payment_status = 'completed' LIMIT 1");
            $pstmt->execute([$_SESSION['user_id'], $book_id]);
            $reader_allowed = (bool)$pstmt->fetchColumn();
        } catch (PDOException $e) {
            $reader_allowed = false;
        }
    }
    if (!$reader_allowed) {
        header('Location: ' . SITE_URL . '/book.php?id=' . $book_id);
        exit;
    }
}

// R5: the admin can open a draft version in the real reader before publishing it
// (reader.php?id=N&preview=V). Nothing is counted or saved while previewing.
$preview_version = 0;
if (isAdmin() && !empty($_GET['preview'])) {
    $pv = $db->prepare("SELECT version FROM book_content_history WHERE book_id = ? AND version = ?");
    $pv->execute([$book_id, (int)$_GET['preview']]);
    $preview_version = (int)$pv->fetchColumn();
}

// Opening the reader is a reading session (reading_sessions), not another view of the book page;
// it used to add to the book's view count a second time.

// --- Fetch processed content ---
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed = $stmt->fetch(PDO::FETCH_ASSOC);
$has_processed = !empty($processed) && $processed['is_processed'] == 1;
$live_version = $has_processed ? (int)($processed['published_version'] ?? 0) : 0;
if ($preview_version) {
    $pv = $db->prepare("SELECT content_html, toc_json FROM book_content_history WHERE book_id = ? AND version = ?");
    $pv->execute([$book_id, $preview_version]);
    $draft = $pv->fetch(PDO::FETCH_ASSOC);
    $processed = array_merge($processed ?: [], ['content_html' => $draft['content_html'], 'toc_json' => $draft['toc_json'], 'is_processed' => 1]);
    $has_processed = true;
}

// --- Parse TOC if available ---
$toc = $has_processed ? (json_decode($processed['toc_json'], true) ?? []) : [];

// --- Parse pages from processed HTML ---
$pages = [];
if ($has_processed && !empty($processed['content_html'])) {
    // F20: the old regex ended each page at the first </div>, so a page containing a div was
    // silently truncated. Each page now runs to its matching (balanced) closing </div>.
    // Same opening tags as before; on the live book both methods give the same 141 pages.
    $html = $processed['content_html'];
    if (preg_match_all('/<div class="page-content" data-page="(\d+)">/', $html, $open, PREG_OFFSET_CAPTURE)) {
        foreach ($open[0] as $o) {
            $pos = $o[1] + strlen($o[0]);
            $start = $pos;
            $depth = 1;
            while ($depth > 0 && preg_match('/<div\b|<\/div>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
                $depth += (stripos($t[0][0], '</') === 0) ? -1 : 1;
                $pos = $t[0][1] + strlen($t[0][0]);
                if ($depth === 0) {
                    $pages[] = substr($html, $start, $t[0][1] - $start);
                }
            }
        }
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
    'preview_version' => $preview_version, // R5
    'live_version' => $live_version,
];