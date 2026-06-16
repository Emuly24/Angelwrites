<?php
ini_set('display_errors', 1);
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
        $pages[] = $match[2];
    }
}
$total_pages = count($pages);

// ------- CHAPTER DETECTION & PAGE MAPPING -------
$chapterMap = []; // $chapterMap[chapter_index] = array of page numbers
$currentChapter = 0;
$chapterTitles = [];
$pageToChapter = [];

if ($has_processed) {
    foreach ($pages as $idx => $html) {
        $pageNum = $idx + 1;
        // Look for chapter headings
        if (preg_match('/<h[2-3][^>]*>(.*?Chapter\s+(\d+|[IVXLCDM]+).*?)<\/h[2-3]>/i', $html, $matches)) {
            $currentChapter++;
            $chapterTitles[$currentChapter] = trim(strip_tags($matches[1]));
            $chapterMap[$currentChapter] = [];
        }
        $pageToChapter[$pageNum] = $currentChapter ?: 1;
        if ($currentChapter > 0) {
            $chapterMap[$currentChapter][] = $pageNum;
        }
    }
}
// If no chapters detected, treat whole book as one chapter
if (empty($chapterMap)) {
    $chapterMap[1] = range(1, $total_pages);
    $chapterTitles[1] = 'Chapter 1';
    foreach (range(1, $total_pages) as $p) {
        $pageToChapter[$p] = 1;
    }
}

// ------- USER PROGRESS -------
$user_progress = null;
$last_offset = 0;
$last_chapter = 0;
$progress_percent = 0;
$streak_days = 0;
$group_id = null;
$reading_status = 'not_started';
$reading_speed_wpm = 250; // default: words per minute

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

    // Load user reading speed preference
    $stmt = $db->prepare("SELECT reading_speed_wpm FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $speed = $stmt->fetchColumn();
    if ($speed) $reading_speed_wpm = (int)$speed;
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

$last_page = $last_chapter > 0 && $last_chapter <= $total_pages ? $last_chapter : 1;
$cover_path = isset($book['cover_path']) && !empty($book['cover_path']) ? SITE_URL . '/' . $book['cover_path'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<title><?php echo htmlspecialchars($book['title']); ?></title>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet" />
<style>
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
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --input-bg: #ffffff;
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}
.theme-paper { --bg: #EFD8D6; --card-bg: #fffdf9; --text: #3d2e2e; --border: #e5d5d5; --input-bg: #ffffff; }
.theme-light { --bg: #F7F3ED; --card-bg: #ffffff; --text: #3d2e2e; --border: #e5d5d5; --input-bg: #ffffff; }
.theme-dark { --bg: #1a1212; --card-bg: #2c1e1e; --text: #e8dddd; --border: #4a3a3a; --input-bg: #2c1e1e; }
.theme-sepia { --bg: #fbf3e9; --card-bg: #fdf5ec; --text: #4a3d36; --border: #d9c9b8; --input-bg: #fdf5ec; }

* { margin:0; padding:0; box-sizing:border-box; }
html, body { height:100%; width:100%; overflow:hidden; }
#reader-app {
    position:fixed; top:0; left:0; width:100%; height:100%;
    display:flex; flex-direction:column;
    background:var(--bg); color:var(--text);
    font-family:'Inter',sans-serif;
    transition:background var(--transition), color var(--transition);
}

#toolbar {
    flex-shrink:0; height:60px; min-height:60px;
    display:flex; justify-content:space-between; align-items:center;
    padding:0 20px;
    background:var(--card-bg);
    border-bottom:1px solid var(--border);
    box-shadow:var(--shadow); z-index:20;
}
.toolbar-left { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.toolbar-left .title {
    font-family:'Playfair Display',Georgia,serif;
    font-weight:700; font-size:1.15rem;
    max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    color:var(--dark);
}
.toolbar-left button {
    background:none; border:none; font-size:1.2rem;
    cursor:pointer; color:var(--text-light);
    width:40px; height:40px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    transition:color var(--transition);
}
.toolbar-left button:hover { color:var(--rose); background:rgba(219,161,162,0.1); }
.toolbar-center {
    display:flex; align-items:center; gap:16px;
    font-size:0.95rem; color:var(--text-light); flex-wrap:wrap; justify-content:center;
}
.progress-ring {
    position:relative; width:36px; height:36px;
}
.progress-ring svg { width:100%; height:100%; transform:rotate(-90deg); }
.progress-ring .bg { stroke:var(--border); stroke-width:2; fill:none; }
.progress-ring .fill { stroke:var(--rose); stroke-width:2; fill:none; transition:stroke-dashoffset var(--transition); }
.progress-ring .percent {
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%);
    font-size:0.7rem; font-weight:600; color:var(--text-light);
}
#chapterInfo {
    font-size:0.9rem; color:var(--text-light); white-space:nowrap;
}
#remainingInfo {
    font-size:0.85rem; color:var(--text-light); white-space:nowrap;
}
.toolbar-right { display:flex; align-items:center; gap:8px; }
.toolbar-right button {
    background:none; border:none; font-size:1.1rem; cursor:pointer;
    color:var(--text-light); padding:6px 10px; border-radius:6px;
    transition:all var(--transition);
    display:flex; align-items:center; justify-content:center;
}
.toolbar-right button:hover { background:rgba(219,161,162,0.1); color:var(--rose); transform:scale(1.05); }
.streak-badge {
    background:var(--rose); color:var(--white);
    padding:2px 12px; border-radius:20px;
    font-size:0.75rem; font-weight:600; white-space:nowrap;
}

#sidebar {
    position:fixed; top:60px; left:0; width:48px;
    height:calc(100% - 60px);
    background:var(--card-bg);
    border-right:1px solid var(--border);
    z-index:15;
    display:flex; flex-direction:column; align-items:center;
    padding:8px 0; gap:4px; overflow-y:auto;
    transition:transform 0.25s ease;
}
#sidebar.closed { transform:translateX(-100%); }
#sidebar.open { transform:translateX(0); }
.sidebar-btn {
    width:36px; height:36px; border:none; background:transparent;
    color:var(--text-light); font-size:1rem; cursor:pointer;
    border-radius:8px; transition:all var(--transition);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.sidebar-btn:hover { background:rgba(219,161,162,0.1); color:var(--rose); transform:scale(1.05); }
.sidebar-btn.active { color:var(--rose); background:rgba(219,161,162,0.15); }
.sidebar-separator { width:28px; border:none; border-top:1px solid var(--border); margin:4px 0; }

#page-viewport {
    margin-left:48px; flex:1;
    position:relative; overflow:hidden;
    background:var(--bg);
    display:flex; justify-content:center; align-items:center;
}
.focus-mode #sidebar { transform:translateX(-100%); }

#scroll-container {
    height:100%; width:100%; overflow-y:auto;
    padding:20px 20px 120px 20px;
    display:flex; flex-direction:column; align-items:center;
}
.page-content-wrapper {
    width:100%; max-width:900px;
    margin:0 auto 40px auto; padding:10px;
    background:linear-gradient(145deg,var(--rose-light),var(--vanilla));
    border-radius:20px; box-shadow:var(--shadow-hover);
    border:1px solid var(--rose);
    transition:transform 0.3s ease;
}
.page-content-wrapper:hover { transform:translateY(-2px); }
.page-content-inner {
    width:100%; padding:30px 40px;
    background:var(--card-bg);
    border-radius:12px;
    box-shadow:inset 0 0 20px rgba(0,0,0,0.03);
    font-size:1.05rem; line-height:1.8;
    color:var(--text); min-height:400px;
}
.page-content-inner h1, .page-content-inner h2, .page-content-inner h3 {
    font-family:'Playfair Display',Georgia,serif;
    color:var(--dark);
}
.page-content-inner h1, .page-content-inner h2 { text-align:center; margin-bottom:1.2rem; }
.page-content-inner p { margin-bottom:16px; }
.page-content-inner p:last-child { margin-bottom:0; }

#flip-container {
    width:100%; height:100%;
    position:relative; perspective:2500px;
    justify-content:center; align-items:center;
    background:var(--bg); display:none;
}
.flip-book {
    position:relative; width:95%; max-width:900px;
    height:92%; max-height:900px;
    transform-style:preserve-3d;
    transition:transform 1.2s cubic-bezier(0.645,0.045,0.355,1);
}
.flip-page {
    position:absolute; top:0; left:0; width:100%; height:100%;
    backface-visibility:hidden;
    border-radius:20px; box-shadow:var(--shadow-hover);
    border:1px solid var(--rose);
    background:linear-gradient(145deg,var(--rose-light),var(--vanilla));
    padding:10px; overflow:hidden;
}
.flip-page-front { z-index:2; transform-origin:left center; transform:rotateY(0deg); }
.flip-page-back { transform-origin:right center; transform:rotateY(180deg); }
.flip-page-inner {
    width:100%; height:100%; padding:30px 40px;
    background:var(--card-bg);
    border-radius:12px;
    box-shadow:inset 0 0 20px rgba(0,0,0,0.03);
    font-size:1.05rem; line-height:1.8;
    color:var(--text);
    font-family:'Inter',sans-serif;
    overflow:hidden; display:flex; flex-direction:column;
}
.flip-page-inner h1, .flip-page-inner h2, .flip-page-inner h3 {
    font-family:'Playfair Display',Georgia,serif;
    color:var(--dark);
}
.flip-page-inner h1, .flip-page-inner h2 { text-align:center; margin-bottom:1.2rem; }
.flip-page-inner p { margin-bottom:16px; }
.flip-page-inner p:last-child { margin-bottom:0; }
.flip-page-inner.special-page {
    display:flex; flex-direction:column;
    justify-content:center; align-items:center;
    text-align:center;
}
.flip-page-inner.special-page h1, .flip-page-inner.special-page h2, .flip-page-inner.special-page h3, .flip-page-inner.special-page p { text-align:center; }

.flip-page-front::before {
    content:''; position:absolute; top:0; left:0;
    width:40px; height:100%;
    background:linear-gradient(to right,rgba(0,0,0,0.1) 0%,rgba(0,0,0,0.02) 80%,transparent 100%);
    pointer-events:none; z-index:3;
}
.flip-page-back::before {
    content:''; position:absolute; top:0; right:0;
    width:40px; height:100%;
    background:linear-gradient(to left,rgba(0,0,0,0.1) 0%,rgba(0,0,0,0.02) 80%,transparent 100%);
    pointer-events:none; z-index:3;
}
.flip-book.flipped-right { transform:rotateY(-180deg); }
.flip-book.flipped-left { transform:rotateY(180deg); }
.flip-book.flipping { transition:transform 1.2s cubic-bezier(0.645,0.045,0.355,1); }

.cover-image-wrapper-flip {
    width:100%; height:100%; border-radius:12px;
    overflow:hidden; background:var(--card-bg);
    display:flex; align-items:center; justify-content:center;
}
.cover-image-wrapper-flip img {
    width:100%; height:100%; object-fit:contain; display:block;
}
.cover-placeholder-flip {
    width:100%; height:100%;
    display:flex; flex-direction:column;
    justify-content:center; align-items:center;
    background:linear-gradient(135deg,var(--vanilla),var(--fantasy));
    color:var(--text-light); text-align:center; padding:40px;
}
.cover-placeholder-flip i { font-size:4rem; color:var(--rose); margin-bottom:16px; }
.cover-placeholder-flip p { font-family:'Playfair Display',Georgia,serif; font-size:1.5rem; font-weight:600; color:var(--dark); }

.flip-nav-btn-wrapper {
    position:absolute; top:50%; transform:translateY(-50%);
    width:44px; height:44px; border-radius:50%;
    background:rgba(255,255,255,0.85); backdrop-filter:blur(4px);
    box-shadow:0 4px 16px rgba(0,0,0,0.1);
    display:flex; align-items:center; justify-content:center;
    z-index:10; transition:background .3s;
    border:1px solid var(--rose-light);
}
.flip-nav-btn-wrapper:hover { background:rgba(255,255,255,1); box-shadow:0 4px 24px rgba(0,0,0,0.15); }
.flip-nav-btn-wrapper .aw-nav-btn {
    position:static !important; transform:none !important;
    background:transparent !important; border:none !important;
    box-shadow:none !important; color:var(--text) !important;
    width:44px; height:44px; margin:0; padding:0;
    display:flex; align-items:center; justify-content:center;
}
.flip-nav-btn-wrapper .aw-nav-btn i { font-size:1.2rem; }
.flip-nav-btn-wrapper .aw-nav-btn:hover { color:var(--rose) !important; transform:scale(1.1) !important; }
#flipPrevBtnWrapper { left:16px; }
#flipNextBtnWrapper { right:16px; }

.highlight-yellow { background:#fff9c4; padding:0 4px; border-radius:3px; }
.highlight-green { background:#c8e6c9; padding:0 4px; border-radius:3px; }
.highlight-blue { background:#bbdefb; padding:0 4px; border-radius:3px; }
.highlight-pink { background:#f8bbd0; padding:0 4px; border-radius:3px; }

#highlight-tooltip,#reaction-picker,#annotation-popup,#search-bar,#share-modal,#overlay,#notes-panel,#toc-drawer,#settings-panel{position:fixed !important;z-index:9999 !important}
#highlight-tooltip{display:none;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:12px 16px;box-shadow:var(--shadow-hover);min-width:280px;pointer-events:auto}
#highlight-tooltip.visible{display:block}
#highlight-tooltip .highlight-color{width:24px;height:24px;border-radius:50%;border:2px solid var(--border);cursor:pointer;transition:all 0.2s}
#highlight-tooltip .highlight-color:hover{transform:scale(1.15);border-color:var(--rose)}
#highlight-tooltip .tooltip-action{background:transparent;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;color:var(--text);transition:all 0.2s;font-size:0.9rem;display:flex;align-items:center;gap:4px}
#highlight-tooltip .tooltip-action:hover{border-color:var(--rose);color:var(--rose);background:rgba(219,161,162,0.05)}
#reaction-picker{display:none;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:8px 12px;box-shadow:var(--shadow-hover);gap:6px;pointer-events:auto;bottom:80px !important;right:20px !important;left:auto !important}
#reaction-picker button{background:none;border:none;font-size:1.5rem;cursor:pointer;padding:4px;transition:transform var(--transition)}
#reaction-picker button:hover{transform:scale(1.2)}
#annotation-popup{display:none;width:320px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px;box-shadow:var(--shadow-hover);pointer-events:auto;bottom:140px !important;right:20px !important;left:auto !important}
#annotation-popup.visible{display:block}
#annotation-popup textarea{width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;resize:vertical;min-height:60px;font-size:0.9rem;background:var(--input-bg);color:var(--text);font-family:'Inter',sans-serif}
#annotation-popup textarea:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}
.annotation-actions{display:flex;gap:8px;margin-top:8px;justify-content:flex-end}
.annotation-actions button{padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:0.8rem;transition:background var(--transition)}
.annotation-save{background:var(--rose);color:var(--white)}
.annotation-save:hover{background:var(--rose-dark)}
.annotation-cancel{background:var(--border);color:var(--text)}
.annotation-cancel:hover{background:var(--text-light);color:var(--white)}
#search-bar{display:none;width:300px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:12px;box-shadow:var(--shadow-hover);pointer-events:auto;top:70px !important;left:50px !important}
#search-bar.visible{display:block}
#search-bar input{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.9rem;background:var(--input-bg);color:var(--text);font-family:'Inter',sans-serif}
#search-bar input:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}
#search-bar .search-header{display:flex;gap:8px;align-items:center;margin-bottom:8px}
#search-bar .search-header button{background:none;border:none;cursor:pointer;color:var(--text-light);font-size:0.9rem;transition:color var(--transition)}
#search-bar .search-header button:hover{color:var(--rose)}
#searchResults{margin-top:8px;max-height:200px;overflow-y:auto;font-size:0.85rem}
.search-result{padding:6px 8px;border-bottom:1px solid var(--border);cursor:pointer;transition:background var(--transition)}
.search-result:hover{background:rgba(219,161,162,0.1)}
.search-result strong{color:var(--rose)}
#settings-panel{bottom:0;left:0;right:0;background:var(--card-bg);border-top:1px solid var(--border);padding:16px 20px;transform:translateY(100%);transition:transform 0.25s ease;max-height:50vh;overflow-y:auto;pointer-events:auto}
#settings-panel.open{transform:translateY(0)}
.settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.settings-group label{font-size:0.7rem;font-weight:600;text-transform:uppercase;color:var(--text-light);display:block;margin-bottom:4px}
.settings-group .btn-group{display:flex;gap:4px;flex-wrap:wrap}
.settings-group .btn-group button{padding:4px 10px;border:1px solid var(--border);border-radius:6px;background:transparent;cursor:pointer;font-size:0.75rem;transition:var(--transition)}
.settings-group .btn-group button.active{border-color:var(--rose);background:var(--rose);color:var(--white)}
.settings-group .btn-group button:hover{border-color:var(--rose)}
.slider-group{display:flex;align-items:center;gap:6px}
.slider-group input[type="range"]{width:80px;accent-color:var(--rose)}
.font-select-wrapper select{width:100%;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--input-bg);color:var(--text);font-size:0.85rem;appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b5a5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 10px center;background-size:14px}
.font-select-wrapper select:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}
#overlay{top:0;left:0;width:100%;height:100%;background:rgba(44,30,30,0.4);display:none;z-index:9998 !important}
#overlay.active{display:block}
#toc-drawer{top:0;right:-340px;width:340px;height:100vh;background:var(--card-bg);box-shadow:-4px 0 20px rgba(44,30,30,0.1);transition:right 0.25s ease;display:flex;flex-direction:column;pointer-events:auto}
#toc-drawer.open{right:0}
.toc-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:var(--vanilla)}
.toc-header h3{margin:0;font-size:1.1rem;font-family:'Playfair Display',Georgia,serif;color:var(--dark)}
.toc-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text);width:36px;height:36px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.toc-close:hover{background:rgba(219,161,162,0.1)}
.toc-body{flex:1;overflow-y:auto;padding:12px 20px}
.toc-list{list-style:none;padding:0;margin:0}
.toc-list li{padding:2px 0}
.toc-list a{color:var(--text);text-decoration:none;display:block;padding:6px 8px;border-radius:6px;transition:all var(--transition)}
.toc-list a:hover{background:rgba(219,161,162,0.1);color:var(--rose)}
.toc-empty{text-align:center;color:var(--text-light);padding:40px 0}
#challenge-widget{display:none;margin:8px 16px;padding:12px 16px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow)}
#challenge-widget h4{margin:0 0 4px;font-size:1rem}
.challenge-progress{position:relative;height:12px;background:var(--border);border-radius:6px;overflow:hidden}
.challenge-progress .bar{height:100%;background:var(--rose);transition:width 0.3s}
#readingStatus{appearance:none;background-color:var(--card-bg);border:1px solid var(--border);border-radius:30px;padding:6px 36px 6px 16px;font-size:0.85rem;font-weight:500;color:var(--text);cursor:pointer;transition:all var(--transition);background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b5a5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:16px}
#readingStatus:hover{border-color:var(--rose)}
#readingStatus:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}
.focus-mode #toolbar{transform:translateY(-100%);opacity:0;pointer-events:none;transition:all var(--transition)}
.focus-mode #settings-panel.open{display:none !important}
@media (max-width:768px){#toolbar{height:48px;padding:0 8px}.toolbar-left .title{font-size:0.9rem;max-width:160px}.page-content-inner{padding:20px}.flip-page-inner{padding:20px}#toc-drawer{width:280px;right:-280px}.settings-grid{grid-template-columns:1fr 1fr}}
@media (max-width:480px){.toolbar-left .title{font-size:0.8rem;max-width:120px}.page-content-inner{padding:16px}.flip-page-inner{padding:16px}}
</style>
</head>
<body>

<div id="reader-app">
    <div id="toolbar">
        <div class="toolbar-left">
            <button id="backBtn"><i class="fas fa-arrow-left"></i></button>
            <span class="title"><?php echo htmlspecialchars($book['title']); ?></span>
            <?php if (isLoggedIn() && $streak_days > 0): ?><span class="streak-badge">🔥 <?php echo $streak_days; ?>d</span><?php endif; ?>
            <select id="readingStatus">
                <option value="not_started" <?php echo $reading_status == 'not_started' ? 'selected' : ''; ?>>📌 Not Started</option>
                <option value="currently_reading" <?php echo $reading_status == 'currently_reading' ? 'selected' : ''; ?>>📖 Currently Reading</option>
                <option value="finished" <?php echo $reading_status == 'finished' ? 'selected' : ''; ?>>✅ Finished</option>
                <option value="want_to_read" <?php echo $reading_status == 'want_to_read' ? 'selected' : ''; ?>>📚 Want to Read</option>
                <option value="dropped" <?php echo $reading_status == 'dropped' ? 'selected' : ''; ?>>❌ Dropped</option>
            </select>
        </div>
        <div class="toolbar-center">
            <div class="progress-ring">
                <svg viewBox="0 0 36 36"><circle class="bg" cx="18" cy="18" r="16"/><circle class="fill" id="progressFill" cx="18" cy="18" r="16" stroke-dasharray="100.53" stroke-dashoffset="100.53"/></svg>
                <span class="percent" id="progressPercent">0%</span>
            </div>
            <span id="pageNum">1</span> / <span id="totalPages"><?php echo $total_pages; ?></span>
            <span id="chapterInfo"></span>
            <span id="remainingInfo"></span>
        </div>
        <div class="toolbar-right">
            <button id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>
    </div>

    <div id="sidebar">
        <button class="sidebar-btn" id="searchBtn" title="Search"><i class="fas fa-search"></i></button>
        <button class="sidebar-btn" id="bookmarkBtn" title="Bookmark"><i class="fas fa-bookmark"></i></button>
        <button class="sidebar-btn" id="tocBtn" title="Table of Contents"><i class="fas fa-list-ul"></i></button>
        <button class="sidebar-btn" id="notesBtn" title="Group Notes"><i class="fas fa-sticky-note"></i></button>
        <button class="sidebar-btn" id="settingsBtn" title="Settings"><i class="fas fa-cog"></i></button>
        <button class="sidebar-btn" id="focusBtn" title="Focus Mode"><i class="fas fa-expand"></i></button>
        <hr class="sidebar-separator">
        <button class="sidebar-btn" id="commentsBtn" title="Comments"><i class="fas fa-comments"></i></button>
        <button class="sidebar-btn" id="errorReportBtn" title="Report Error"><i class="fas fa-exclamation-triangle"></i></button>
        <button class="sidebar-btn" id="prayerBtn" title="Prayer Request"><i class="fas fa-hands-praying"></i></button>
        <hr class="sidebar-separator">
        <button class="sidebar-btn" id="exportHighlightsBtn" title="Export Highlights"><i class="fas fa-file-export"></i></button>
        <button class="sidebar-btn" id="resetProgressBtn" title="Reset Progress"><i class="fas fa-undo-alt"></i></button>
        <button class="sidebar-btn" id="resumeBtn" title="Resume Position"><i class="fas fa-history"></i></button>
        <button class="sidebar-btn" id="challengeBtn" title="Challenge"><i class="fas fa-trophy"></i></button>
        <button class="sidebar-btn" id="shareBtn" title="Share"><i class="fas fa-share-alt"></i></button>
    </div>

    <div id="page-viewport">
        <div id="scroll-container">
            <?php if (!empty($cover_path)): ?>
            <div class="cover-image-wrapper"><div class="cover-image-container"><img src="<?php echo $cover_path; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>"></div></div>
            <?php else: ?>
            <div class="cover-image-wrapper"><div class="cover-image-container"><div class="cover-placeholder"><i class="fas fa-book-open"></i><p><?php echo htmlspecialchars($book['title']); ?></p></div></div></div>
            <?php endif; ?>
            <?php foreach ($pages as $index => $page_html): ?>
            <div class="page-content-wrapper"><div class="page-content-inner" data-page="<?php echo $index+1; ?>"><?php echo $page_html; ?></div></div>
            <?php endforeach; ?>
        </div>

        <div id="flip-container" style="display:none;">
            <div class="flip-book" id="flipBook">
                <div class="flip-page flip-page-front" id="flipLeftPage">
                    <div class="flip-page-inner" id="flipLeftContent"></div>
                </div>
                <div class="flip-page flip-page-back" id="flipRightPage">
                    <div class="flip-page-inner" id="flipRightContent"></div>
                </div>
            </div>
            <div class="flip-nav-btn-wrapper" id="flipPrevBtnWrapper">
                <button class="aw-nav-btn" id="flipPrevBtn"><i class="fas fa-chevron-left"></i></button>
            </div>
            <div class="flip-nav-btn-wrapper" id="flipNextBtnWrapper">
                <button class="aw-nav-btn" id="flipNextBtn"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <div id="settings-panel">
        <div class="settings-grid">
            <div class="settings-group"><label>Mode</label><div class="btn-group" id="modeGroup"><button data-mode="scroll" class="active">Scroll</button><button data-mode="flip">Page Flip</button></div></div>
            <div class="settings-group"><label>Theme</label><div class="btn-group" id="themeGroup"><button data-theme="paper">Paper</button><button data-theme="light" class="active">Light</button><button data-theme="dark">Dark</button><button data-theme="sepia">Sepia</button></div></div>
            <div class="settings-group"><label>Font Size</label><div class="slider-group"><button onclick="adjustFontSize(-5)">A-</button><input type="range" id="fontSizeSlider" min="70" max="160" value="100" step="5"><button onclick="adjustFontSize(5)">A+</button><span id="fontSizeLabel">100%</span></div></div>
            <div class="settings-group"><label>Font Type</label><div class="font-select-wrapper"><select id="fontTypeSelect"><option value="Inter, sans-serif">Inter</option><option value="Georgia, serif">Georgia</option><option value="'Playfair Display', Georgia, serif">Playfair Display</option></select></div></div>
            <div class="settings-group"><label>Line Height</label><div class="slider-group"><button onclick="adjustLineHeight(-10)">-</button><input type="range" id="lineHeightSlider" min="140" max="220" value="180" step="10"><button onclick="adjustLineHeight(10)">+</button><span id="lineHeightLabel">1.8</span></div></div>
            <div class="settings-group"><label>Reading Speed</label><div class="slider-group"><input type="range" id="readingSpeedSlider" min="100" max="500" value="<?php echo $reading_speed_wpm; ?>" step="10"><span id="readingSpeedLabel"><?php echo $reading_speed_wpm; ?> wpm</span></div></div>
        </div>
    </div>

   <div id="toc-drawer" style="display: none;">
    <div class="toc-header">
        <h3>Table of Contents</h3>
        <button class="toc-close" id="tocClose">&times;</button>
    </div>
    <div class="toc-body" id="tocBody">
        <?php if (is_array($toc) && count($toc) > 0): ?>
        <ul class="toc-list">
            <?php foreach ($toc as $entry): ?>
            <li><a href="#" class="toc-link" data-chapter="<?php echo (int)($entry['page'] ?? 1); ?>"><?php echo htmlspecialchars($entry['title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="toc-empty">No table of contents available.</p>
        <?php endif; ?>
    </div>
</div>

    <div id="notes-panel" style="display: none;">
    <div class="notes-header">
        <h3>📝 Group Notes</h3>
        <div>
            <button class="note-submit" id="addNoteBtn">+ Add</button>
            <button class="note-cancel" id="notesClose">&times;</button>
        </div>
    </div>
    <div class="notes-body" id="notesBody">
        <div id="notesList">
            <p class="empty-notes">No notes for this chapter.</p>
        </div>
        <div id="noteForm">
            <textarea id="noteText" rows="2" placeholder="Write a note..."></textarea>
            <div>
                <label><input type="checkbox" id="notePrivate"> Private</label>
            </div>
            <button class="note-submit" onclick="submitNote()">Post</button>
            <button class="note-cancel" onclick="toggleNoteForm()">Cancel</button>
        </div>
    </div>
</div>
   <div id="share-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="modal-close" onclick="closeShare()">&times;</span>
        <h3><i class="fas fa-share-alt" style="color:var(--rose);"></i> Share this page</h3>
        <div style="margin:16px 0;display:flex;flex-direction:column;gap:8px;">
            <button onclick="share('facebook')" style="padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);cursor:pointer;color:var(--text);"><i class="fab fa-facebook-f" style="color:var(--rose);"></i> Facebook</button>
            <button onclick="share('twitter')" style="padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);cursor:pointer;color:var(--text);"><i class="fab fa-twitter" style="color:var(--rose);"></i> Twitter</button>
            <button onclick="share('whatsapp')" style="padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);cursor:pointer;color:var(--text);"><i class="fab fa-whatsapp" style="color:var(--rose);"></i> WhatsApp</button>
            <button onclick="share('copy')" style="padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);cursor:pointer;color:var(--text);"><i class="fas fa-link" style="color:var(--rose);"></i> Copy Link</button>
        </div>
        <button class="share-close" onclick="closeShare()" style="background:var(--rose);color:var(--white);border:none;padding:8px 24px;border-radius:30px;cursor:pointer;width:100%;font-weight:600;">Close</button>
    </div>
</div>

    <div id="challenge-widget"></div>
    <div id="overlay" onclick="closeAll()"></div>
</div>
<script>
(function() {
    // ===== DATA =====
    const pages = <?php echo json_encode($pages); ?>;
    const totalPages = pages.length;
    const bookId = <?php echo $book_id; ?>;
    const userId = <?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>;
    const groupId = <?php echo $group_id ? (int)$group_id : 0; ?>;
    const toc = <?php echo json_encode($toc); ?>;
    const lastPage = <?php echo $last_page; ?>;
    const cover_path = <?php echo json_encode($cover_path); ?>;
    const chapterMap = <?php echo json_encode($chapterMap); ?>;
    const pageToChapter = <?php echo json_encode($pageToChapter); ?>;
    const chapterTitles = <?php echo json_encode($chapterTitles); ?>;
    const readingSpeedWPM = <?php echo $reading_speed_wpm; ?>;

    // ===== DOM REFS =====
    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container');
    const pageNumEl = document.getElementById('pageNum');
    const totalPagesEl = document.getElementById('totalPages');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
    const chapterInfoEl = document.getElementById('chapterInfo');
    const remainingInfoEl = document.getElementById('remainingInfo');
    const settingsPanel = document.getElementById('settings-panel');
    const tocDrawer = document.getElementById('toc-drawer');
    const tocClose = document.getElementById('tocClose');
    const notesPanel = document.getElementById('notes-panel');
    const notesList = document.getElementById('notesList');
    const addNoteBtn = document.getElementById('addNoteBtn');
    const notesClose = document.getElementById('notesClose');
    const noteForm = document.getElementById('noteForm');
    const noteText = document.getElementById('noteText');
    const notePrivate = document.getElementById('notePrivate');
    const overlay = document.getElementById('overlay');
    const focusBtn = document.getElementById('focusBtn');
    const readingStatus = document.getElementById('readingStatus');
    const bookmarkBtn = document.getElementById('bookmarkBtn');
    const tocBtn = document.getElementById('tocBtn');
    const settingsBtn = document.getElementById('settingsBtn');
    const shareBtn = document.getElementById('shareBtn');
    const resetProgressBtn = document.getElementById('resetProgressBtn');
    const exportHighlightsBtn = document.getElementById('exportHighlightsBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const challengeBtn = document.getElementById('challengeBtn');
    const commentsBtn = document.getElementById('commentsBtn');
    const commentsModal = document.getElementById('commentsModal');
    const commentList = document.getElementById('commentList');
    const commentInput = document.getElementById('commentInput');
    const errorReportBtn = document.getElementById('errorReportBtn');
    const errorModal = document.getElementById('errorModal');
    const errorPageNumSpan = document.getElementById('errorPageNum');
    const errorPageInput = document.getElementById('errorPageInput');
    const errorText = document.getElementById('errorText');
    const errorCorrection = document.getElementById('errorCorrection');
    const prayerBtn = document.getElementById('prayerBtn');
    const prayerModal = document.getElementById('prayerModal');
    const prayerText = document.getElementById('prayerText');
    const backBtn = document.getElementById('backBtn');
    const prevFlipBtn = document.getElementById('flipPrevBtn');
    const nextFlipBtn = document.getElementById('flipNextBtn');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const searchBtn = document.getElementById('searchBtn');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    let currentPage = Math.min(lastPage, totalPages) || 1;
    let readingMode = localStorage.getItem('reader_mode') || 'scroll';
    let focusMode = false;
    let isBookmarked = false;
    let touchStartX = 0;
    let currentNoteId = null;
    let flipData = { chunks: [], currentChunk: 0, totalChunks: 0, originalPage: 1 };

    // ===== UTILITY =====
    function getChapterForPage(page) {
        return pageToChapter[page] || 1;
    }
    function getChapterTitle(chapter) {
        return chapterTitles[chapter] || 'Chapter ' + chapter;
    }
    function getPagesInChapter(chapter) {
        return chapterMap[chapter] || [];
    }
    function getRemainingPagesInChapter(page) {
        const ch = getChapterForPage(page);
        const pagesInCh = getPagesInChapter(ch);
        const idx = pagesInCh.indexOf(page);
        if (idx === -1) return 0;
        return pagesInCh.length - idx - 1;
    }
    function getChapterTotalPages(chapter) {
        return getPagesInChapter(chapter).length;
    }
    function estimateTimeRemaining(page) {
        const remaining = getRemainingPagesInChapter(page);
        if (remaining <= 0) return 0;
        const wordsPerPage = 300;
        const totalWords = remaining * wordsPerPage;
        const minutes = Math.ceil(totalWords / readingSpeedWPM);
        return minutes;
    }

    // ===== THEMES =====
    function applyTheme(theme) {
        const app = document.getElementById('reader-app');
        app.classList.remove('theme-paper','theme-light','theme-dark','theme-sepia');
        app.classList.add('theme-'+theme);
        localStorage.setItem('reader_theme',theme);
    }

    // ===== SPLITTER =====
    function splitByFit(originalPageNum, html) {
        // (same as before)
        if (originalPageNum === 1 && html.trim() === 'COVER') {
            let coverHTML = '';
            if (cover_path && cover_path.length > 0) {
                coverHTML = `<div class="cover-image-wrapper-flip"><img src="${cover_path}" alt="Cover" /></div>`;
            } else {
                coverHTML = `<div class="cover-image-wrapper-flip"><div class="cover-placeholder-flip"><i class="fas fa-book-open"></i><p>Cover</p></div></div>`;
            }
            return { chunks: [coverHTML], mapping: [1] };
        }
        const temp = document.createElement('div');
        temp.innerHTML = html;
        const children = Array.from(temp.children);
        const measureContainer = document.createElement('div');
        measureContainer.style.cssText = `visibility:hidden;position:absolute;width:100%;padding:30px 40px;font-size:1.05rem;line-height:1.8;font-family:'Inter',sans-serif;color:var(--text);box-sizing:border-box;`;
        document.body.appendChild(measureContainer);
        const flipContainerEl = document.getElementById('flip-container');
        const maxHeight = flipContainerEl.clientHeight * 0.92 - 60;
        const chunks = [];
        const mapping = [];
        let currentChunk = document.createElement('div');

        function pushChunk() {
            if (currentChunk.children.length > 0) {
                chunks.push(currentChunk.innerHTML);
                mapping.push(originalPageNum);
                currentChunk = document.createElement('div');
            }
        }
        function wouldFit(child) {
            measureContainer.innerHTML = currentChunk.innerHTML;
            measureContainer.appendChild(child.cloneNode(true));
            const h = measureContainer.scrollHeight;
            measureContainer.innerHTML = '';
            return h <= maxHeight;
        }

        children.forEach(child => {
            const tag = child.tagName.toLowerCase();
            const text = child.textContent.trim().toLowerCase();
            if (tag === 'h2' || tag === 'h3') {
                pushChunk();
                currentChunk.appendChild(child.cloneNode(true));
                pushChunk();
                return;
            }
            const specialKeywords = ['acknowledgements','author\'s note','about the author','dedication','copyright'];
            if (specialKeywords.includes(text)) {
                pushChunk();
                const clone = child.cloneNode(true);
                currentChunk.appendChild(clone);
                pushChunk();
                return;
            }
            if (wouldFit(child)) {
                currentChunk.appendChild(child.cloneNode(true));
            } else {
                pushChunk();
                currentChunk.appendChild(child.cloneNode(true));
            }
        });
        pushChunk();
        document.body.removeChild(measureContainer);
        if (chunks.length === 0) {
            chunks.push('<p style="color:var(--text-light);text-align:center;">(empty page)</p>');
            mapping.push(originalPageNum);
        }
        return { chunks, mapping };
    }

    // ===== FLIP RENDERING =====
    function loadFlipPages(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        const pageHTML = (pageNum === 1) ? 'COVER' : pages[pageNum-1];
        const result = splitByFit(pageNum, pageHTML);
        flipData.chunks = result.chunks;
        flipData.totalChunks = result.chunks.length;
        flipData.currentChunk = 0;
        flipData.originalPage = pageNum;
        renderFlipChunk(0);
        updateFlipUI(pageNum, 0);
    }

    function renderFlipChunk(index) {
        const html = flipData.chunks[index] || '<p>...</p>';
        const leftContent = document.getElementById('flipLeftContent');
        leftContent.className = 'flip-page-inner';
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const text = tempDiv.textContent.trim().toLowerCase();
        const specialKeywords = ['acknowledgements','author\'s note','about the author','dedication','copyright','cover'];
        if (specialKeywords.some(kw => text.includes(kw)) || (flipData.originalPage === 1 && index === 0)) {
            leftContent.classList.add('special-page');
        }
        leftContent.innerHTML = html;
        const flipBook = document.getElementById('flipBook');
        flipBook.classList.remove('flipped-right','flipped-left','flipping');
        flipBook.style.transform = 'rotateY(0deg)';
    }

    function flipToNext() {
        if (flipData.currentChunk < flipData.totalChunks - 1) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-right');
            setTimeout(() => {
                flipData.currentChunk++;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-right','flipping');
                updateFlipUI(flipData.originalPage, flipData.currentChunk);
                savePosition();
            }, 800);
        } else if (flipData.originalPage < totalPages) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-right');
            setTimeout(() => {
                currentPage = flipData.originalPage + 1;
                loadFlipPages(currentPage);
                flipBook.classList.remove('flipped-right','flipping');
                updateFlipUI(currentPage, 0);
                savePosition();
            }, 800);
        }
    }

    function flipToPrev() {
        if (flipData.currentChunk > 0) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-left');
            setTimeout(() => {
                flipData.currentChunk--;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-left','flipping');
                updateFlipUI(flipData.originalPage, flipData.currentChunk);
                savePosition();
            }, 800);
        } else if (flipData.originalPage > 1) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-left');
            setTimeout(() => {
                currentPage = flipData.originalPage - 1;
                loadFlipPages(currentPage);
                flipData.currentChunk = flipData.totalChunks - 1;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-left','flipping');
                updateFlipUI(currentPage, flipData.currentChunk);
                savePosition();
            }, 800);
        }
    }

    function updateFlipUI(pageNum, chunkIndex) {
        const totalChunks = flipData.totalChunks;
        if (totalChunks > 0) {
            pageNumEl.textContent = `${chunkIndex+1} / ${totalChunks}`;
        } else {
            pageNumEl.textContent = '1 / 1';
        }
        const approxPercent = Math.round(((pageNum-1)/totalPages + (chunkIndex+1)/totalPages/Math.max(1,totalChunks))*100);
        const circumference = 2 * Math.PI * 16;
        const offset = circumference - (approxPercent/100)*circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = approxPercent + '%';
        const ch = getChapterForPage(pageNum);
        const chapTitle = getChapterTitle(ch);
        const remaining = getRemainingPagesInChapter(pageNum);
        const totalInChapter = getChapterTotalPages(ch);
        chapterInfoEl.textContent = `📖 ${chapTitle}`;
        remainingInfoEl.textContent = `⏳ ${remaining} pages remaining • ${estimateTimeRemaining(pageNum)} min left`;
    }

    // ===== SCROLL UPDATE =====
    function updateUI(page) {
        if (readingMode === 'flip') return;
        pageNumEl.textContent = page;
        const percent = Math.round((page/totalPages)*100);
        const circumference = 2 * Math.PI * 16;
        const offset = circumference - (percent/100)*circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = percent + '%';
        const ch = getChapterForPage(page);
        const chapTitle = getChapterTitle(ch);
        const remaining = getRemainingPagesInChapter(page);
        const totalInChapter = getChapterTotalPages(ch);
        chapterInfoEl.textContent = `📖 ${chapTitle}`;
        remainingInfoEl.textContent = `⏳ ${remaining} pages remaining • ${estimateTimeRemaining(page)} min left`;
    }

    // ===== NAVIGATION =====
    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip') {
            loadFlipPages(pageNum);
        } else {
            const target = document.querySelector(`.page-content-inner[data-page="${pageNum}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(pageNum);
        }
        savePosition();
        loadNotes();
    }

    function savePosition() {
        if (userId === 0) return;
        const data = new FormData();
        data.append('action','save_position');
        data.append('book_id',bookId);
        data.append('chapter',currentPage);
        data.append('percent',Math.round((currentPage/totalPages)*100));
        navigator.sendBeacon('/reader/reader_ajax.php',data);
    }

    // ===== SWITCH MODE =====
    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode',mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            flipContainer.style.display = 'flex';
            loadFlipPages(currentPage);
        } else {
            flipContainer.style.display = 'none';
            scrollContainer.style.display = 'block';
            const target = document.querySelector(`.page-content-inner[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(currentPage);
        }
    }

    // ===== EVENTS =====
    prevFlipBtn.addEventListener('click',flipToPrev);
    nextFlipBtn.addEventListener('click',flipToNext);

    document.addEventListener('keydown',function(e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            if (readingMode === 'flip') flipToNext(); else scrollContainer.scrollBy({ top: scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (readingMode === 'flip') flipToPrev(); else scrollContainer.scrollBy({ top: -scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'Escape') {
            closeAll();
        }
    });

    document.getElementById('page-viewport').addEventListener('click',function(e) {
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('#highlight-tooltip')) return;
        if (readingMode === 'flip') {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            if (x > rect.width/2) flipToNext(); else flipToPrev();
        }
    });

    document.addEventListener('touchstart',function(e) { touchStartX = e.changedTouches[0].screenX; });
    document.addEventListener('touchend',function(e) {
        if (readingMode === 'flip') {
            const diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) flipToNext(); else flipToPrev();
            }
        }
    });

    // ===== BOOKMARK =====
    bookmarkBtn.addEventListener('click',function() {
        if (userId === 0) { alert('Please log in to bookmark.'); return; }
        if (isBookmarked) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData();
            fd.append('action','remove_bookmark');
            fd.append('book_id',bookId);
            xhr.send(fd);
            isBookmarked = false;
            bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
            bookmarkBtn.style.color = '#555';
        } else {
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData();
            fd.append('action','add_bookmark');
            fd.append('book_id',bookId);
            fd.append('chapter',currentPage);
            fd.append('offset',0);
            xhr.send(fd);
            isBookmarked = true;
            bookmarkBtn.querySelector('i').className = 'fas fa-bookmark';
            bookmarkBtn.style.color = 'var(--rose)';
        }
    });

    function loadBookmarkStatus() {
        if (userId === 0) return;
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',false);
        const fd = new FormData();
        fd.append('action','list_bookmarks');
        fd.append('book_id',bookId);
        xhr.send(fd);
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                let exists = false;
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

    // ===== TOC =====
    document.querySelectorAll('.toc-link').forEach(function(link) {
        link.addEventListener('click',function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.chapter);
            if (page >= 1 && page <= totalPages) {
                goToPage(page);
                tocDrawer.style.display = 'none';
                overlay.classList.remove('active');
            }
        });
    });

    // ===== SETTINGS =====
    document.querySelectorAll('#modeGroup button').forEach(function(btn) {
        btn.addEventListener('click',function() {
            const mode = this.dataset.mode;
            document.querySelectorAll('#modeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            switchMode(mode);
        });
    });

    document.querySelectorAll('#themeGroup button').forEach(function(btn) {
        btn.addEventListener('click',function() {
            const theme = this.dataset.theme;
            document.querySelectorAll('#themeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            applyTheme(theme);
        });
    });

    const savedTheme = localStorage.getItem('reader_theme') || 'light';
    applyTheme(savedTheme);
    const themeBtn = document.querySelector('#themeGroup [data-theme="'+savedTheme+'"]');
    if (themeBtn) themeBtn.classList.add('active');

    document.getElementById('fontSizeSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.fontSize = val+'%'; });
        document.getElementById('fontSizeLabel').textContent = val+'%';
        localStorage.setItem('reader_font_size',val);
    });
    window.adjustFontSize = function(amount) {
        const slider = document.getElementById('fontSizeSlider');
        let val = parseInt(slider.value)+amount;
        val = Math.min(160,Math.max(70,val));
        slider.value = val;
        slider.dispatchEvent(new Event('input'));
    };
    const savedSize = localStorage.getItem('reader_font_size') || 100;
    document.getElementById('fontSizeSlider').value = savedSize;
    document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.fontSize = savedSize+'%'; });
    document.getElementById('fontSizeLabel').textContent = savedSize+'%';

    document.getElementById('lineHeightSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.lineHeight = (val/100).toFixed(1); });
        document.getElementById('lineHeightLabel').textContent = (val/100).toFixed(1);
        localStorage.setItem('reader_line_height',val);
    });
    window.adjustLineHeight = function(amount) {
        const slider = document.getElementById('lineHeightSlider');
        let val = parseInt(slider.value)+amount;
        val = Math.min(220,Math.max(140,val));
        slider.value = val;
        slider.dispatchEvent(new Event('input'));
    };
    const savedLine = localStorage.getItem('reader_line_height') || 180;
    document.getElementById('lineHeightSlider').value = savedLine;
    document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.lineHeight = (savedLine/100).toFixed(1); });
    document.getElementById('lineHeightLabel').textContent = (savedLine/100).toFixed(1);

    const fontTypeSelect = document.getElementById('fontTypeSelect');
    const savedFont = localStorage.getItem('reader_font_family') || 'Inter,sans-serif';
    if (savedFont) { fontTypeSelect.value = savedFont; applyFontType(savedFont); }
    fontTypeSelect.addEventListener('change',function() {
        const font = this.value;
        applyFontType(font);
        localStorage.setItem('reader_font_family',font);
    });
    function applyFontType(font) {
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) {
            el.style.fontFamily = font;
        });
    }

    // Reading speed slider
    const speedSlider = document.getElementById('readingSpeedSlider');
    speedSlider.addEventListener('input',function() {
        const val = parseInt(this.value);
        document.getElementById('readingSpeedLabel').textContent = val + ' wpm';
        if (userId > 0) {
            const data = new FormData();
            data.append('action','update_reading_speed');
            data.append('speed',val);
            navigator.sendBeacon('/reader/reader_ajax.php',data);
        }
        updateUI(currentPage);
    });

    // ===== SIDEBAR TOGGLES =====
    sidebarToggle.addEventListener('click',function() { sidebar.classList.toggle('closed'); });
    settingsBtn.addEventListener('click',function() {
        settingsPanel.classList.toggle('open');
        overlay.classList.toggle('active',settingsPanel.classList.contains('open'));
    });
     tocBtn.addEventListener('click', function() {
    const drawer = document.getElementById('toc-drawer');
        drawer.classList.toggle('open');
        overlay.classList.toggle('active', drawer.classList.contains('open'));
    });
        tocClose.addEventListener('click', function() {
        document.getElementById('toc-drawer').classList.remove('open');
        overlay.classList.remove('active');
    });
        commentsBtn.addEventListener('click',function() {
        if (userId === 0) { alert('Please log in to view comments.'); return; }
        loadComments();
        commentsModal.style.display = 'block';
        overlay.classList.add('active');
    });
    tocClose.addEventListener('click',function() {
        tocDrawer.style.display = 'none';
        overlay.classList.remove('active');
    });
    focusBtn.addEventListener('click',function() {
        focusMode = !focusMode;
        document.getElementById('reader-app').classList.toggle('focus-mode',focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
        }
    });

    // ===== CHALLENGE =====
    challengeBtn.addEventListener('click',function() { loadChallenge(); });
    function loadChallenge() {
        if (userId === 0) { alert('Please log in to view challenges.'); return; }
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_monthly_challenge&user_id='+userId,true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    const percent = Math.min(100,Math.round((data.progress/data.target)*100));
                    challengeWidget.innerHTML = `
                        <h4>📖 Monthly Challenge</h4>
                        <p>${data.goal}</p>
                        <div class="challenge-progress"><div class="bar" style="width:${percent}%;"></div></div>
                        <p style="font-size:0.9rem;">${data.progress} / ${data.target} pages</p>
                        <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:var(--rose);color:white;cursor:pointer;" onclick="updateChallenge()">📈 Update</button>
                    `;
                }
            } catch(e) { console.error('Challenge error:',e); alert('Could not load challenge.'); }
        };
        xhr.send();
    }
    function updateChallenge() {
        const pagesRead = prompt('How many pages did you read today?');
        if (pagesRead && parseInt(pagesRead) > 0) {
            const data = new FormData();
            data.append('action','update_challenge_progress');
            data.append('user_id',userId);
            data.append('pages_read',pagesRead);
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            xhr.onload = function() { loadChallenge(); alert('✅ Updated!'); };
            xhr.send(data);
        }
    }

    // ===== HIGHLIGHT =====
    function getSelectedText() {
        const sel = window.getSelection();
        return sel.toString().trim();
    }
    function getSelectionRange() {
        const sel = window.getSelection();
        return sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    }
    function showSelectionTooltip(e) {
        e.stopPropagation();
        const text = getSelectedText();
        const range = getSelectionRange();
        const tooltip = document.getElementById('highlight-tooltip');
        if (!text || !range || text.length < 1) {
            tooltip.classList.remove('visible');
            document.getElementById('notes-panel').style.display = 'none';
            overlay.classList.remove('active');
            return;
        }
        const rect = range.getBoundingClientRect();
        const tooltipWidth = 320;
        const leftPos = rect.left + rect.width/2 - tooltipWidth/2;
        const topPos = rect.top - 50;
        tooltip.style.left = Math.max(10,leftPos) + 'px';
        tooltip.style.top = Math.max(10,topPos) + 'px';
        tooltip.classList.add('visible');
        tooltip.dataset.text = text;
    }
    document.addEventListener('click',function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        if (tooltip && !tooltip.contains(e.target)) {
            tooltip.classList.remove('visible');
        }
    });
    document.addEventListener('click', function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        const notesPanel = document.getElementById('notes-panel');
        if (tooltip && !tooltip.contains(e.target) && notesPanel && !notesPanel.contains(e.target)) {
            tooltip.classList.remove('visible');
            notesPanel.style.display = 'none';
            overlay.classList.remove('active');
        }
    });
    document.addEventListener('mouseup',function(e) {
        if (getSelectedText().length > 0) {
            setTimeout(function() { showSelectionTooltip(e); }, 50);
        }
    });
    document.addEventListener('touchend',function(e) {
        setTimeout(function() { showSelectionTooltip(e); }, 100);
    });

    function initSelectionTooltip() {
        const tooltip = document.getElementById('highlight-tooltip');
        if (!tooltip) return;
        tooltip.innerHTML = `
            <div>
                <div style="display:flex;gap:4px;">
                    <button class="highlight-color" data-color="yellow" style="background:#fff9c4;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="green" style="background:#c8e6c9;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="blue" style="background:#bbdefb;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="pink" style="background:#f8bbd0;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                </div>
                <div style="display:flex;gap:4px;margin-top:4px;">
                    <button class="tooltip-action" data-action="copy"><i class="fas fa-copy"></i></button>
                    <button class="tooltip-action" data-action="note"><i class="fas fa-pen"></i></button>
                    <button class="tooltip-action" data-action="share"><i class="fas fa-share-alt"></i></button>
                    <button class="tooltip-action" data-action="question"><i class="fas fa-question-circle"></i></button>
                    <button class="tooltip-action" data-action="react"><i class="fas fa-smile"></i></button>
                </div>
            </div>
        `;
        tooltip.querySelectorAll('.highlight-color').forEach(function(btn) {
            btn.addEventListener('click',function() {
                const color = this.dataset.color;
                const text = tooltip.dataset.text;
                const range = getSelectionRange();
                if (!range) return;
                const span = document.createElement('span');
                span.className = 'highlight-'+color;
                span.textContent = text;
                range.deleteContents();
                range.insertNode(span);
                tooltip.classList.remove('visible');
                if (userId > 0) {
                    const data = new FormData();
                    data.append('action','add_highlight');
                    data.append('book_id',bookId);
                    data.append('chapter',currentPage);
                    data.append('text',text);
                    data.append('color',color);
                    fetch('/reader/reader_ajax.php',{method:'POST',body:data});
                }
            });
        });
        tooltip.querySelectorAll('.tooltip-action').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const text = tooltip.dataset.text;
                switch(action) {
                    case 'copy': navigator.clipboard.writeText(text).then(()=>{alert('✅ Copied!');}).catch(()=>{document.execCommand('copy');}); break;
                    case 'note':
                        if (groupId > 0) {
                            const panel = document.getElementById('notes-panel');
                            panel.style.display = 'flex';
                            overlay.classList.add('active');
                            loadNotes();
                            const noteTextarea = document.getElementById('noteText');
                            if (noteTextarea) {
                                noteTextarea.value = '"' + text + '"\n\n';
                                noteTextarea.focus();
                            }
                        } else {
                            alert('You need to be in a reading group to add notes.');
                        }
                        break;
                    case 'share':
                        document.getElementById('share-modal').style.display = 'block';
                        overlay.classList.add('active');
                        break;
                    case 'question':
                        if (groupId === 0) { alert('You need to be in a reading group.'); return; }
                        const question = prompt('Ask a question about this text:\n\n"' + text + '"');
                        if (question) { /* TODO: send question via AJAX */ }
                        break;
                    case 'react':
                        const picker = document.getElementById('reaction-picker');
                        if (picker) { picker.style.display = 'flex'; picker.dataset.text = text; }
                        break;
                }
                tooltip.classList.remove('visible');
            });
        });
    }
    initSelectionTooltip();

    // ===== NOTES =====
    function loadNotes() {
        if (groupId === 0) return;
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_notes&group_id='+groupId+'&book_id='+bookId+'&chapter='+currentPage,true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success) {
                    let html = '';
                    if (data.notes.length === 0) {
                        html = '<p class="empty-notes">No notes for this chapter.</p>';
                    } else {
                        data.notes.forEach(function(n) {
                            let reactionsHtml = '';
                            if (n.reactions && n.reactions.length > 0) {
                                n.reactions.forEach(function(r) {
                                    reactionsHtml += '<span class="reaction" onclick="reactNote('+n.id+',\''+r.reaction_type+'\')">'+r.reaction_type+' '+r.count+'</span>';
                                });
                            }
                            const canReact = !n.is_private || n.user_id == userId;
                            const isMyNote = n.user_id == userId;
                            html += '<div class="note-card'+(n.is_private?' private':'')+'">';
                            html += '<div class="note-author">';
                            html += '<div class="note-avatar-placeholder">'+(n.display_name||n.username).charAt(0).toUpperCase()+'</div>';
                            html += '<div class="note-author-info"><strong>'+(n.display_name||n.username)+'</strong> <small>'+timeAgo(n.created_at)+'</small>';
                            if (n.is_private) html += ' <span class="badge-private">🔒 Private</span>';
                            html += '</div></div>';
                            html += '<p class="note-text">'+n.text+'</p>';
                            html += '<div class="note-footer">';
                            html += '<div class="note-reactions">'+reactionsHtml;
                            if (canReact) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="showReactionPicker('+n.id+',event)">➕</button>';
                            html += '</div>';
                            if (isMyNote) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="deleteNote('+n.id+')">🗑️</button>';
                            html += '</div></div>';
                        });
                    }
                    notesList.innerHTML = html;
                }
            } catch(e) {}
        };
        xhr.send();
    }

    function timeAgo(timestamp) {
        const diff = Date.now() - new Date(timestamp).getTime();
        const secs = Math.floor(diff/1000);
        if (secs<60) return 'just now';
        if (secs<3600) return Math.floor(secs/60)+'m ago';
        if (secs<86400) return Math.floor(secs/3600)+'h ago';
        if (secs<604800) return Math.floor(secs/86400)+'d ago';
        return new Date(timestamp).toLocaleDateString();
    }

    // ===== RESUME =====
    resumeBtn.addEventListener('click',function() { resumePosition(); });
    function resumePosition() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(function() {
                    const target = document.querySelector('.page-content-inner[data-page="'+lastPage+'"]');
                    if (target) target.scrollIntoView({ block:'start' });
                }, 100);
            }
        }
    }

    // ===== SHARE =====
    function share(platform) {
        const url = window.location.origin+'/reader/reader.php?id='+bookId+'&chapter='+currentPage;
        const text = '📖 I\'m reading on AngelWrites!';
        switch(platform) {
            case 'facebook': window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(url),'_blank'); break;
            case 'twitter': window.open('https://twitter.com/intent/tweet?text='+encodeURIComponent(text)+'&url='+encodeURIComponent(url),'_blank'); break;
            case 'whatsapp': window.open('https://api.whatsapp.com/send?text='+encodeURIComponent(text+' '+url),'_blank'); break;
            case 'copy': navigator.clipboard.writeText(url).then(function(){alert('✅ Copied!');}).catch(function(){ const ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); alert('✅ Copied!'); }); break;
        }
        closeShare();
    }
    function closeShare() {
        document.getElementById('share-modal').style.display = 'none';
        overlay.classList.remove('active');
    }

    // ===== NOTES PANEL TOGGLE =====
    notesBtn.addEventListener('click', function() {
        if (groupId === 0) {
            alert('You are not in a reading group for this book.');
            return;
        }
        const panel = document.getElementById('notes-panel');
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
            overlay.classList.add('active');
            loadNotes();
        } else {
            panel.style.display = 'none';
            overlay.classList.remove('active');
        }
    });
    notesClose.addEventListener('click', function() {
        document.getElementById('notes-panel').style.display = 'none';
        overlay.classList.remove('active');
    });
    // ===== SEARCH FUNCTIONALITY =====
    function toggleSearch() {
        const bar = document.getElementById('search-bar');
        if (bar.style.display === 'none' || bar.style.display === '') {
            bar.style.display = 'block';
            document.getElementById('searchInput').focus();
        } else {
            bar.style.display = 'none';
            document.getElementById('searchResults').innerHTML = '';
        }
    }
    function closeSearch() {
        document.getElementById('search-bar').style.display = 'none';
        document.getElementById('searchResults').innerHTML = '';
    }
    searchBtn.addEventListener('click', toggleSearch);
    // ===== ERROR REPORT MODAL =====
    function openErrorModal() {
        document.getElementById('errorPageNum').textContent = currentPage;
        document.getElementById('errorPageInput').value = currentPage;
        document.getElementById('errorText').value = '';
        document.getElementById('errorCorrection').value = '';
        document.getElementById('errorModal').style.display = 'block';
        overlay.classList.add('active');
    }
    function closeErrorModal() {
        document.getElementById('errorModal').style.display = 'none';
        overlay.classList.remove('active');
    }
    errorReportBtn.addEventListener('click', openErrorModal);
    
    // ===== NOTE FORM FUNCTIONS =====
    function toggleNoteForm() {
        const form = document.getElementById('noteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            document.getElementById('noteText').focus();
        }
    }
    function submitNote() {
        const text = document.getElementById('noteText').value.trim();
        const isPrivate = document.getElementById('notePrivate').checked ? 1 : 0;
        if (!text) return alert('Please enter a note.');
        const data = new FormData();
        data.append('action','add_reader_note');
        data.append('group_id',groupId);
        data.append('book_id',bookId);
        data.append('chapter_index',currentPage);
        data.append('text',text);
        data.append('is_private',isPrivate);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() {
            try {
                const d = JSON.parse(this.responseText);
                if (d.success) {
                    loadNotes();
                    document.getElementById('noteText').value = '';
                    document.getElementById('notePrivate').checked = false;
                    document.getElementById('noteForm').style.display = 'none';
                } else {
                    alert('Error: ' + d.error);
                }
            } catch(e) { alert('Error submitting note.'); }
        };
        xhr.send(data);
    }
    function deleteNote(noteId) {
        if (!confirm('Delete this note?')) return;
        const data = new FormData();
        data.append('action','delete_reader_note');
        data.append('note_id',noteId);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() { loadNotes(); };
        xhr.send(data);
    }
    function reactNote(noteId, reaction) {
        const data = new FormData();
        data.append('action','toggle_note_reaction');
        data.append('note_id',noteId);
        data.append('reaction_type',reaction);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() { loadNotes(); };
        xhr.send(data);
    }
    function showReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const btn = event.target.closest('button');
        const rect = btn.getBoundingClientRect();
        const picker = document.getElementById('reaction-picker');
        picker.style.top = (rect.bottom + 8) + 'px';
        picker.style.left = (rect.left) + 'px';
        picker.style.display = 'flex';
    }
    // ===== PRAYER REQUEST MODAL =====
    function openPrayerModal() {
        document.getElementById('prayerText').value = '';
        document.getElementById('prayerModal').style.display = 'block';
        overlay.classList.add('active');
    }
    function closePrayerModal() {
        document.getElementById('prayerModal').style.display = 'none';
        overlay.classList.remove('active');
    }
    prayerBtn.addEventListener('click', openPrayerModal);

exportHighlightsBtn.addEventListener('click', function() {
        if (userId === 0) { alert('Please log in to export highlights.'); return; }
        const formData = new FormData();
        formData.append('action', 'export_highlights');
        formData.append('book_id', bookId);
        fetch('/reader/reader_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.blob())
        .then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'highlights.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        })
        .catch(() => alert('Export failed.'));
    });
    // ===== COMMENT FUNCTIONS =====
    function loadComments() {
        if (userId === 0) return;
        document.getElementById('currentCommentPage').textContent = currentPage;
        const formData = new FormData();
        formData.append('action','get_book_comments');
        formData.append('book_id',bookId);
        formData.append('page_num',currentPage);
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData})
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                const list = document.getElementById('commentList');
                list.innerHTML = '';
                if (data.comments.length === 0) {
                    list.innerHTML = '<p style="color:var(--text-light);text-align:center;padding:20px;">No comments on this page yet.</p>';
                } else {
                    data.comments.forEach(com=>{
                        const isAdmin = com.is_admin_reply == 1;
                        const authorName = isAdmin ? 'Angella (Admin)' : com.author_name;
                        const badge = isAdmin ? '<span class="admin-badge">🛡️ Admin</span>' : '';
                        list.innerHTML += `
                            <div class="comment-item ${isAdmin?'admin':''}">
                                <div class="comment-author"><i class="fas fa-user-circle"></i> ${authorName} ${badge}</div>
                                <div style="font-size:0.85rem;color:var(--text-light);">${timeAgo(com.created_at)}</div>
                                <div style="margin-top:4px;">${com.comment}</div>
                            </div>
                        `;
                    });
                }
            }
        });
    }
    function submitComment() {
        const text = document.getElementById('commentInput').value.trim();
        if (!text) return alert('Please write a comment.');
        const formData = new FormData();
        formData.append('action','add_book_comment');
        formData.append('book_id',bookId);
        formData.append('page_num',currentPage);
        formData.append('comment',text);
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData})
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                document.getElementById('commentInput').value = '';
                loadComments();
            } else {
                alert('Error: ' + (data.error || 'Failed to post comment.'));
            }
        });
    }
    function closeCommentsModal() {
        document.getElementById('commentsModal').style.display = 'none';
        overlay.classList.remove('active');
    }

    // ===== CLOSE ALL =====
    function closeAll() {
       settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open'); // Changed from style.display = 'none'
        notesPanel.style.display = 'none';
        document.getElementById('share-modal').style.display = 'none';
        overlay.classList.remove('active');
        if (focusMode) {
            focusMode = false;
            document.getElementById('reader-app').classList.remove('focus-mode');
            document.getElementById('focusBtn').querySelector('i').className = 'fas fa-expand';
        }
        if (commentsModal.style.display === 'block') { commentsModal.style.display = 'none'; }
        if (errorModal.style.display === 'block') { errorModal.style.display = 'none'; }
        if (prayerModal.style.display === 'block') { prayerModal.style.display = 'none'; }
    }
    overlay.addEventListener('click', closeAll);

    // ===== BACK BUTTON =====
    backBtn.addEventListener('click',function() { window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>'; });

    // ===== EXPOSE ALL FUNCTIONS TO GLOBAL SCOPE =====
    window.adjustFontSize = adjustFontSize;
    window.adjustLineHeight = adjustLineHeight;
    window.goToPage = goToPage;
    window.resumePosition = resumePosition;
    window.closeAll = closeAll;
    window.share = share;
    window.closeShare = closeShare;
    window.loadChallenge = loadChallenge;
    window.updateChallenge = updateChallenge;
    window.toggleNoteForm = toggleNoteForm;
    window.submitNote = submitNote;
    window.deleteNote = deleteNote;
    window.reactNote = reactNote;
    window.showReactionPicker = showReactionPicker;
    window.loadComments = loadComments;
    window.submitComment = submitComment;
    window.closeCommentsModal = closeCommentsModal;
    window.toggleSearch = toggleSearch;
    window.closeSearch = closeSearch;
    window.openErrorModal = openErrorModal;
    window.closeErrorModal = closeErrorModal;
    window.openPrayerModal = openPrayerModal;
    window.closePrayerModal = closePrayerModal;

    // ===== INIT =====
    totalPagesEl.textContent = totalPages;
    const savedMode = localStorage.getItem('reader_mode');
    if (savedMode === 'flip') {
        readingMode = 'flip';
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="flip"]').classList.add('active');
        switchMode('flip');
    } else {
        switchMode('scroll');
    }
    goToPage(currentPage);
    loadBookmarkStatus();
    if (userId > 0) {
        const data = new FormData();
        data.append('action','start_session');
        data.append('book_id',bookId);
        navigator.sendBeacon('/reader/reader_ajax.php',data);
        loadChallenge();
    }
    window.addEventListener('beforeunload',function() {
        if (userId > 0) {
            const data = new FormData();
            data.append('action','end_session');
            data.append('book_id',bookId);
            navigator.sendBeacon('/reader/reader_ajax.php',data);
        }
    });
})();
</script>
</body>
</html>