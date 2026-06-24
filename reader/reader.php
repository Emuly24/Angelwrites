<?php
// ============================================================
//  READER.PHP – Final Fully Integrated Reader
//  Includes all enhancements: StPageFlip, highlights, TTS,
//  gamification, challenges, sharing, and more.
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_helper.php';

// --- Load book data using the enhanced book_data.php ---
$bookData = require_once __DIR__ . '/book_data.php';
extract($bookData); // gives $book, $book_id, $pages, $total_pages, $chapterMap, $chapterTitles, $pageToChapter, $toc, $user_progress, etc.

// --- Load helper functions ---
require_once __DIR__ . '/reader_functions.php';

// --- User progress (already extracted, but we need some variables for the UI) ---
$last_offset = $user_progress['position_offset'] ?? 0;
$last_chapter = $user_progress['position_section'] ?? 0;
$progress_percent = $user_progress['progress_percent'] ?? 0;

// --- Get additional user data ---
$streak_days = 0;
$group_id = null;
$reading_status = 'not_started';
$reading_speed_wpm = 250;
$user_level = 1;

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];

    // Streak
    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak_days = (int)$stmt->fetchColumn();

    // Group
    $stmt = $db->prepare("SELECT g.id FROM reading_groups g JOIN group_members m ON g.id = m.group_id WHERE g.book_id = ? AND m.user_id = ? LIMIT 1");
    $stmt->execute([$book_id, $user_id]);
    $group_id = $stmt->fetchColumn();

    // Reading status
    $stmt = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $reading_status = $stmt->fetchColumn() ?: 'not_started';

    // Reading speed
    $stmt = $db->prepare("SELECT reading_speed_wpm FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $speed = $stmt->fetchColumn();
    if ($speed) $reading_speed_wpm = (int)$speed;

    // User level (from gamification)
    $level_data = getReaderLevel($user_id);
    $user_level = $level_data['level'];
}

// --- Bookmarks and highlights ---
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
$cover_path = $book['cover_path'] ? SITE_URL . '/' . $book['cover_path'] : '';
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/page-flip/dist/css/page-flip.browser.min.css">
<style>
/* ----- All styles remain the same as before (with the flip nav buttons restored) ----- */
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0; --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #ffffff; --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a; --bg: #F7F3ED; --card-bg: #ffffff; --border: #e5d5d5; --shadow: 0 4px 16px rgba(44,30,30,0.08); --shadow-hover: 0 8px 30px rgba(44,30,30,0.15); --input-bg: #ffffff; --transition: 0.3s cubic-bezier(0.4,0,0.2,1); --toolbar-height: 60px; --sidebar-width: 48px; --sidebar-btn-size: 36px; --flip-nav-btn-size: 44px;
}
.theme-paper { --bg: #EFD8D6; --card-bg: #fffdf9; --text: #3d2e2e; --border: #e5d5d5; --input-bg: #ffffff; }
.theme-light { --bg: #F7F3ED; --card-bg: #ffffff; --text: #3d2e2e; --border: #e5d5d5; --input-bg: #ffffff; }
.theme-dark { --bg: #1a1212; --card-bg: #2c1e1e; --text: #e8dddd; --border: #4a3a3a; --input-bg: #2c1e1e; }
.theme-sepia { --bg: #fbf3e9; --card-bg: #fdf5ec; --text: #4a3d36; --border: #d9c9b8; --input-bg: #fdf5ec; }
* { margin:0; padding:0; box-sizing:border-box; }
html,body { height:100%; width:100%; overflow:hidden; }
#reader-app { position:fixed; top:0; left:0; width:100%; height:100%; display:flex; flex-direction:column; background:var(--bg); color:var(--text); font-family:'Inter',sans-serif; transition:background var(--transition), color var(--transition); }
#overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(30,20,20,0.65); z-index:99999 !important; display:none; pointer-events:auto; }
#overlay.active { display:block; }
#toolbar { flex-shrink:0; height:var(--toolbar-height); min-height:var(--toolbar-height); display:flex; justify-content:space-between; align-items:center; padding:0 20px; background:var(--card-bg); border-bottom:1px solid var(--border); box-shadow:var(--shadow); z-index:20; }
.toolbar-left { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.toolbar-left .title { font-family:'Playfair Display',Georgia,serif; font-weight:700; font-size:1.15rem; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--dark); }
.toolbar-left button { background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--text-light); width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; transition:color var(--transition); }
.toolbar-left button:hover { color:var(--rose); background:rgba(219,161,162,0.1); }
.toolbar-center { display:flex; align-items:center; gap:16px; font-size:0.95rem; color:var(--text-light); flex-wrap:wrap; justify-content:center; }
.progress-ring { position:relative; width:36px; height:36px; }
.progress-ring svg { width:100%; height:100%; transform:rotate(-90deg); }
.progress-ring .bg { stroke:var(--border); stroke-width:2; fill:none; }
.progress-ring .fill { stroke:var(--rose); stroke-width:2; fill:none; transition:stroke-dashoffset var(--transition); }
.progress-ring .percent { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:0.7rem; font-weight:600; color:var(--text-light); }
#chapterInfo { font-size:0.9rem; color:var(--text-light); white-space:nowrap; }
#remainingInfo { font-size:0.85rem; color:var(--text-light); white-space:nowrap; }
.toolbar-right { display:flex; align-items:center; gap:8px; }
.toolbar-right button { background:none; border:none; font-size:1.1rem; cursor:pointer; color:var(--text-light); padding:6px 10px; border-radius:6px; transition:all var(--transition); display:flex; align-items:center; justify-content:center; }
.toolbar-right button:hover { background:rgba(219,161,162,0.1); color:var(--rose); transform:scale(1.05); }
.streak-badge { background:var(--rose); color:var(--white); padding:2px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; white-space:nowrap; }
.level-badge { background:var(--vanilla); color:var(--rose-dark); padding:2px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; white-space:nowrap; border:1px solid var(--rose-light); margin-left:4px;}
#sidebar { position:fixed; top:var(--toolbar-height); left:0; width:var(--sidebar-width); height:calc(100vh - var(--toolbar-height)); background:var(--card-bg); border-right:1px solid var(--border); z-index:15; display:flex; flex-direction:column; align-items:center; padding:8px 0; gap:4px; overflow-y:auto; transition:transform 0.25s ease; }
#sidebar.closed { transform:translateX(-100%); }
#sidebar.open { transform:translateX(0); }
.sidebar-btn { width:var(--sidebar-btn-size); height:var(--sidebar-btn-size); border:none; background:transparent; color:var(--text-light); font-size:1rem; cursor:pointer; border-radius:8px; transition:all var(--transition); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.sidebar-btn:hover { background:rgba(219,161,162,0.1); color:var(--rose); transform:scale(1.05); }
.sidebar-btn.active { color:var(--rose); background:rgba(219,161,162,0.15); }
.sidebar-separator { width:28px; border:none; border-top:1px solid var(--border); margin:4px 0; }
#page-viewport { margin-left:var(--sidebar-width); height:calc(100vh - var(--toolbar-height)); position:relative; overflow:hidden; background:var(--bg); display:flex; justify-content:center; align-items:center; transition:margin 0.3s ease, height 0.3s ease, width 0.3s ease; }
#scroll-container { height:100%; width:100%; overflow-y:auto; overflow-x:hidden; padding:20px 20px 120px 20px; display:flex; flex-direction:column; align-items:center; }
#scroll-container .cover-image-wrapper { width:100%; max-width:900px; margin:0 auto 40px auto; padding:10px; background:linear-gradient(145deg,var(--rose-light),var(--vanilla)); border-radius:20px; box-shadow:var(--shadow-hover); border:1px solid var(--rose); display:flex; justify-content:center; align-items:center; }
#scroll-container .cover-image-wrapper img { width:100%; height:auto; max-height:70vh; object-fit:contain; border-radius:12px; }
#scroll-container .cover-image-container { width:100%; display:flex; justify-content:center; }
.page-content-wrapper { width:100%; max-width:900px; margin:0 auto 40px auto; padding:10px; background:linear-gradient(145deg,var(--rose-light),var(--vanilla)); border-radius:20px; box-shadow:var(--shadow-hover); border:1px solid var(--rose); transition:transform 0.3s ease; }
.page-content-wrapper:hover { transform:translateY(-2px); }
.page-content-inner { width:100%; padding:30px 40px; background:var(--card-bg); border-radius:12px; box-shadow:inset 0 0 20px rgba(0,0,0,0.03); font-size:1.05rem; line-height:1.8; color:var(--text); min-height:400px; }
.page-content-inner h1, .page-content-inner h2, .page-content-inner h3 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.page-content-inner h1, .page-content-inner h2 { text-align:center; margin-bottom:1.2rem; }
.page-content-inner p { margin-bottom:16px; }
.page-content-inner p:last-child { margin-bottom:0; }

/* Flip container for StPageFlip */
#flip-container { width:100%; height:100%; position:relative; display:none; justify-content:center; align-items:center; background:var(--bg); padding:0 20px; }
#flip-book-wrapper { width:100%; max-width:900px; height:100%; max-height:92%; position:relative; }
.flip-page-custom { width:100%; height:100%; padding:30px 40px; background:var(--card-bg); border-radius:12px; box-shadow:inset 0 0 20px rgba(0,0,0,0.03); font-size:1.05rem; line-height:1.8; color:var(--text); overflow:hidden; display:flex; flex-direction:column; }
.flip-page-custom.special-page { justify-content:center; align-items:center; text-align:center; }
.cover-image-wrapper-flip { width:100%; height:100%; border-radius:12px; overflow:hidden; background:var(--card-bg); display:flex; align-items:center; justify-content:center; }
.cover-image-wrapper-flip img { width:100%; height:100%; object-fit:contain; display:block; }
.cover-placeholder-flip { width:100%; height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center; background:linear-gradient(135deg,var(--vanilla),var(--fantasy)); color:var(--text-light); text-align:center; padding:40px; }
.cover-placeholder-flip i { font-size:4rem; color:var(--rose); margin-bottom:16px; }
.cover-placeholder-flip p { font-family:'Playfair Display',Georgia,serif; font-size:1.5rem; font-weight:600; color:var(--dark); }

/* Restyled flip navigation buttons */
.flip-nav-btn-wrapper { position:absolute; top:50%; transform:translateY(-50%); width:var(--flip-nav-btn-size); height:var(--flip-nav-btn-size); border-radius:50%; background:rgba(255,255,255,0.85); backdrop-filter:blur(4px); box-shadow:0 4px 16px rgba(0,0,0,0.1); display:flex; align-items:center; justify-content:center; z-index:10; transition:background .3s; border:1px solid var(--rose-light); }
.flip-nav-btn-wrapper:hover { background:rgba(255,255,255,1); box-shadow:0 4px 24px rgba(0,0,0,0.15); }
.flip-nav-btn-wrapper .aw-nav-btn { position:static!important; transform:none!important; background:transparent!important; border:none!important; box-shadow:none!important; color:var(--text)!important; width:var(--flip-nav-btn-size); height:var(--flip-nav-btn-size); margin:0; padding:0; display:flex; align-items:center; justify-content:center; }
.flip-nav-btn-wrapper .aw-nav-btn i { font-size:1.2rem; }
.flip-nav-btn-wrapper .aw-nav-btn:hover { color:var(--rose)!important; transform:scale(1.1)!important; }
#flipPrevBtnWrapper { left:16px; }
#flipNextBtnWrapper { right:16px; }

.highlight-yellow { background:#fff9c4; padding:0 4px; border-radius:3px; }
.highlight-green { background:#c8e6c9; padding:0 4px; border-radius:3px; }
.highlight-blue { background:#bbdefb; padding:0 4px; border-radius:3px; }
.highlight-pink { background:#f8bbd0; padding:0 4px; border-radius:3px; }
.highlight-purple { background:#e8d5f5; padding:0 4px; border-radius:3px; }
.highlight-underline { text-decoration: underline; text-decoration-color: var(--rose); text-decoration-thickness: 2px; text-underline-offset: 3px; padding:0 4px; }

#highlight-tooltip, #reaction-picker, #annotation-popup, #search-bar, #share-modal, #notes-panel, #toc-drawer, #settings-panel { position:fixed!important; z-index:9999!important; }
#highlight-tooltip { display:none; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; padding:12px 16px; box-shadow:var(--shadow-hover); min-width:280px; pointer-events:auto; }
#highlight-tooltip.visible { display:block; }
#highlight-tooltip .highlight-color { width:24px; height:24px; border-radius:50%; border:2px solid var(--border); cursor:pointer; transition:all 0.2s; }
#highlight-tooltip .highlight-color:hover { transform:scale(1.15); border-color:var(--rose); }
#highlight-tooltip .tooltip-action { background:transparent; border:1px solid var(--border); border-radius:6px; padding:4px 8px; cursor:pointer; color:var(--text); transition:all 0.2s; font-size:0.9rem; display:flex; align-items:center; gap:4px; }
#highlight-tooltip .tooltip-action:hover { border-color:var(--rose); color:var(--rose); background:rgba(219,161,162,0.05); }
#reaction-picker { display:none; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; padding:8px 12px; box-shadow:var(--shadow-hover); gap:6px; pointer-events:auto; bottom:80px!important; right:20px!important; left:auto!important; }
#reaction-picker button { background:none; border:none; font-size:1.5rem; cursor:pointer; padding:4px; transition:transform var(--transition); }
#reaction-picker button:hover { transform:scale(1.2); }
#annotation-popup { display:none; width:320px; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow:var(--shadow-hover); pointer-events:auto; bottom:140px!important; right:20px!important; left:auto!important; }
#annotation-popup.visible { display:block; }
#annotation-popup textarea { width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; resize:vertical; min-height:60px; font-size:0.9rem; background:var(--input-bg); color:var(--text); font-family:'Inter',sans-serif; }
#annotation-popup textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
#search-bar { display:none; width:300px; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; padding:12px; box-shadow:var(--shadow-hover); pointer-events:auto; top:70px!important; left:50px!important; z-index:100001!important; }
#search-bar.visible { display:block; }
#search-bar input { width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:6px; font-size:0.9rem; background:var(--input-bg); color:var(--text); font-family:'Inter',sans-serif; }
#search-bar input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
#search-bar .search-header { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
#search-bar .search-header button { background:none; border:none; cursor:pointer; color:var(--text-light); font-size:0.9rem; transition:color var(--transition); }
#search-bar .search-header button:hover { color:var(--rose); }
#searchResults { margin-top:8px; max-height:200px; overflow-y:auto; font-size:0.85rem; }
.search-result { padding:6px 8px; border-bottom:1px solid var(--border); cursor:pointer; transition:background var(--transition); }
.search-result:hover { background:rgba(219,161,162,0.1); }
.search-result strong { color:var(--rose); }
#settings-panel { bottom:0; left:0; right:0; background:var(--card-bg); border-top:1px solid var(--border); padding:16px 20px; transform:translateY(100%); transition:transform 0.25s ease; max-height:50vh; overflow-y:auto; pointer-events:auto; z-index:100001!important; }
#settings-panel.open { transform:translateY(0); }
.settings-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
.settings-group label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:var(--text-light); display:block; margin-bottom:4px; }
.settings-group .btn-group { display:flex; gap:4px; flex-wrap:wrap; }
.settings-group .btn-group button { padding:4px 10px; border:1px solid var(--border); border-radius:6px; background:transparent; cursor:pointer; font-size:0.75rem; transition:var(--transition); }
.settings-group .btn-group button.active { border-color:var(--rose); background:var(--rose); color:var(--white); }
.settings-group .btn-group button:hover { border-color:var(--rose); }
.slider-group { display:flex; align-items:center; gap:6px; }
.slider-group input[type="range"] { width:80px; accent-color:var(--rose); }
.font-select-wrapper select { width:100%; padding:6px 10px; border:1px solid var(--border); border-radius:6px; background:var(--input-bg); color:var(--text); font-size:0.85rem; appearance:none; background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b5a5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 10px center;background-size:14px; }
.font-select-wrapper select:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
#toc-drawer { top:0; right:-340px; width:340px; height:100vh; background:var(--card-bg); box-shadow:-4px 0 20px rgba(44,30,30,0.1); transition:right 0.25s ease; display:flex; flex-direction:column; pointer-events:auto; z-index:100001!important; }
#toc-drawer.open { right:0; }
.toc-header { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--vanilla); }
.toc-header h3 { margin:0; font-size:1.1rem; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.toc-close { background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--text); width:36px; height:36px; border-radius:6px; display:flex; align-items:center; justify-content:center; transition:background var(--transition); }
.toc-close:hover { background:rgba(219,161,162,0.1); }
.toc-body { flex:1; overflow-y:auto; padding:12px 20px; }
.toc-list { list-style:none; padding:0; margin:0; }
.toc-list li { padding:2px 0; }
.toc-list a { color:var(--text); text-decoration:none; display:block; padding:6px 8px; border-radius:6px; transition:all var(--transition); }
.toc-list a:hover { background:rgba(219,161,162,0.1); color:var(--rose); }
.toc-empty { text-align:center; color:var(--text-light); padding:40px 0; }
#challenge-widget { display:none; margin:8px 16px; padding:12px 16px; background:var(--card-bg); border:1px solid var(--border); border-radius:8px; box-shadow:var(--shadow); z-index:100001!important; }
#challenge-widget h4 { margin:0 0 4px; font-size:1rem; }
.challenge-progress { position:relative; height:12px; background:var(--border); border-radius:6px; overflow:hidden; }
.challenge-progress .bar { height:100%; background:var(--rose); transition:width 0.3s; }
#readingStatus { appearance:none; background-color:var(--card-bg); border:1px solid var(--border); border-radius:30px; padding:6px 36px 6px 16px; font-size:0.85rem; font-weight:500; color:var(--text); cursor:pointer; transition:all var(--transition); background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b5a5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:16px; }
#readingStatus:hover { border-color:var(--rose); }
#readingStatus:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.focus-mode #toolbar { transform:translateY(-100%); opacity:0; pointer-events:none; transition:all var(--transition); }
.focus-mode #sidebar { transform:translateX(-100%)!important; }
.focus-mode #page-viewport { margin-left:0!important; height:100vh!important; width:100%!important; max-width:100%!important; }
.focus-mode #settings-panel.open { display:none!important; }
#exitFocusBtn { position:fixed; bottom:24px; right:24px; z-index:100001!important; display:none; align-items:center; gap:8px; background:var(--rose); color:white; border:none; padding:10px 16px; border-radius:30px; font-weight:600; box-shadow:var(--shadow-hover); cursor:pointer; transition:all 0.3s; }
#exitFocusBtn:hover { transform:scale(1.05); background:var(--rose-dark); }
.focus-mode #exitFocusBtn { display:flex!important; }
.modal-wrapper { position:fixed!important; top:50%!important; left:50%!important; transform:translate(-50%,-50%)!important; z-index:999999 !important; width:90%; max-width:520px; max-height:85vh; overflow-y:auto; background:var(--card-bg); border-radius:24px; padding:30px 28px 28px; box-shadow:0 24px 80px rgba(0,0,0,0.35); border:1px solid var(--rose-light); display:none!important; flex-direction:column; pointer-events:auto; transition:opacity 0.25s ease,transform 0.25s ease; backdrop-filter:blur(4px); }
.modal-wrapper.visible { display:flex!important; z-index:999999 !important; }
#share-modal.modal-wrapper { z-index:999999 !important; }
.modal-close { position:absolute!important; top:14px!important; right:18px!important; background:transparent!important; border:none!important; font-size:1.5rem!important; cursor:pointer!important; color:var(--text-light)!important; transition:transform 0.3s ease,color 0.3s ease!important; padding:4px 8px!important; border-radius:8px!important; }
.modal-close:hover { color:var(--rose)!important; transform:rotate(90deg) scale(1.1)!important; background:rgba(219,161,162,0.1)!important; }
.modal-wrapper h3 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); margin-top:0; margin-bottom:16px; font-size:1.3rem; }
#share-modal .share-btn { display:flex!important; align-items:center!important; gap:12px!important; width:100%!important; padding:12px 16px!important; margin-bottom:8px!important; border-radius:14px!important; border:1px solid var(--border)!important; background:var(--input-bg)!important; color:var(--text)!important; cursor:pointer!important; font-size:0.95rem!important; transition:all 0.2s ease!important; }
#share-modal .share-btn:last-child { margin-bottom:0!important; }
#share-modal .share-btn:hover { border-color:var(--rose)!important; background:rgba(219,161,162,0.08)!important; transform:translateX(4px)!important; }
#share-modal .share-btn i { width:24px!important; text-align:center!important; font-size:1.2rem!important; }
#shareQuotePreview { margin:8px 0; padding:12px; background:var(--bg); border-radius:8px; font-style:italic; display:none; }
#shareQuotePreview span { color:var(--text); }
#notes-panel { max-width:550px!important; }
.notes-header { border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
#noteForm { margin-top:16px; }
#noteText { width:100%; min-height:80px; padding:12px; border-radius:12px; border:1px solid var(--border); background:var(--input-bg); color:var(--text); font-family:'Inter',sans-serif; font-size:0.9rem; }
#noteHighlightId { display:none; }
.note-submit { background:var(--rose); color:white; border:none; padding:8px 20px; border-radius:30px; font-weight:600; cursor:pointer; transition:background 0.2s; }
.note-submit:hover { background:var(--rose-dark); }
.note-cancel { background:var(--border); color:var(--text); border:none; padding:8px 20px; border-radius:30px; font-weight:600; cursor:pointer; transition:0.2s; }
@media (max-width:768px) {
    :root { --toolbar-height:50px; --sidebar-width:40px; --sidebar-btn-size:32px; --flip-nav-btn-size:32px; }
    #toolbar { height:var(--toolbar-height)!important; min-height:var(--toolbar-height)!important; padding:0 8px!important; }
    .toolbar-left { gap:4px!important; flex-wrap:nowrap!important; }
    .toolbar-left .title { max-width:none!important; min-width:0!important; flex:0 1 auto!important; font-size:0.75rem!important; }
    #backBtn { flex-shrink:0!important; font-size:1rem!important; width:32px!important; height:32px!important; padding:0!important; }
    #readingStatus { font-size:0.65rem!important; padding:2px 10px 2px 6px!important; max-width:60px!important; background-size:10px!important; background-position:right 4px center!important; }
    .toolbar-center { font-size:0.7rem!important; gap:6px!important; }
    #chapterInfo, #remainingInfo { display:none!important; }
    .progress-ring { width:28px!important; height:28px!important; }
    #pageNum, #totalPages { font-size:0.7rem!important; }
    #sidebar { width:var(--sidebar-width)!important; padding:4px 0!important; }
    .sidebar-btn { width:var(--sidebar-btn-size)!important; height:var(--sidebar-btn-size)!important; font-size:0.8rem!important; }
    #page-viewport { margin-left:var(--sidebar-width)!important; }
    .page-content-wrapper { padding:6px!important; margin-bottom:20px!important; }
    .page-content-inner { padding:16px!important; min-height:300px!important; font-size:95%!important; }
    #flip-container { padding:0 5px!important; }
    .flip-page-custom { padding:12px!important; font-size:90%!important; line-height:1.6!important; }
    .flip-nav-btn-wrapper { width:var(--flip-nav-btn-size)!important; height:var(--flip-nav-btn-size)!important; top:50%!important; }
    #flipPrevBtnWrapper { left:2px!important; }
    #flipNextBtnWrapper { right:2px!important; }
    .flip-nav-btn-wrapper .aw-nav-btn { width:var(--flip-nav-btn-size)!important; height:var(--flip-nav-btn-size)!important; }
    .flip-nav-btn-wrapper .aw-nav-btn i { font-size:1rem!important; }
    .modal-wrapper, #search-bar, #challenge-widget, #notes-panel { width:95%!important; max-width:95%!important; padding:16px!important; max-height:80vh!important; }
    #toc-drawer { width:85%!important; right:-85%!important; }
    #toc-drawer.open { right:0!important; }
}
@media (max-width:480px) {
    .toolbar-left .title { font-size:0.7rem!important; max-width:50px!important; flex:0 1 auto!important; }
    .page-content-inner { padding:12px!important; }
    .flip-page-custom { padding:10px!important; }
}
</style>
</head>
<body>
<div id="reader-app">
    <div id="toolbar">
        <div class="toolbar-left">
            <button id="backBtn"><i class="fas fa-arrow-left"></i></button>
            <span class="title"><?php echo htmlspecialchars($book['title']); ?></span>
            <?php echo renderStreakBadge($streak_days); ?>
            <?php echo renderLevelBadge($user_id ?? 0); ?>
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
        <button class="sidebar-btn" id="analyticsBtn" title="Analytics"><i class="fas fa-chart-pie"></i></button>
        <button class="sidebar-btn" id="myNotesBtn" title="My Personal Notes"><i class="fas fa-pen-fancy"></i></button>
        <button class="sidebar-btn" id="ttsBtn" title="Text to Speech"><i class="fas fa-volume-up"></i></button>
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
            <div id="flip-book-wrapper"></div>
            <!-- Flip navigation buttons (restored) -->
            <div class="flip-nav-btn-wrapper" id="flipPrevBtnWrapper">
                <button class="aw-nav-btn" id="flipPrevBtn"><i class="fas fa-chevron-left"></i></button>
            </div>
            <div class="flip-nav-btn-wrapper" id="flipNextBtnWrapper">
                <button class="aw-nav-btn" id="flipNextBtn"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- Settings Panel -->
    <div id="settings-panel">
        <div class="settings-grid">
            <div class="settings-group"><label>Mode</label><div class="btn-group" id="modeGroup"><button data-mode="scroll">Scroll</button><button data-mode="flip" class="active">Page Flip</button></div></div>
            <div class="settings-group"><label>Theme</label><div class="btn-group" id="themeGroup"><button data-theme="paper">Paper</button><button data-theme="light" class="active">Light</button><button data-theme="dark">Dark</button><button data-theme="sepia">Sepia</button></div></div>
            <div class="settings-group"><label>Font Size</label><div class="slider-group"><button onclick="adjustFontSize(-5)">A-</button><input type="range" id="fontSizeSlider" min="70" max="160" value="100" step="5"><button onclick="adjustFontSize(5)">A+</button><span id="fontSizeLabel">100%</span></div></div>
            <div class="settings-group"><label>Font Type</label><div class="font-select-wrapper"><select id="fontTypeSelect"><option value="Inter, sans-serif">Inter</option><option value="Georgia, serif">Georgia</option><option value="'Playfair Display', Georgia, serif">Playfair Display</option></select></div></div>
            <div class="settings-group"><label>Line Height</label><div class="slider-group"><button onclick="adjustLineHeight(-10)">-</button><input type="range" id="lineHeightSlider" min="140" max="220" value="180" step="10"><button onclick="adjustLineHeight(10)">+</button><span id="lineHeightLabel">1.8</span></div></div>
            <div class="settings-group"><label>Reading Speed</label><div class="slider-group"><input type="range" id="readingSpeedSlider" min="100" max="500" value="<?php echo $reading_speed_wpm; ?>" step="10"><span id="readingSpeedLabel"><?php echo $reading_speed_wpm; ?> wpm</span></div></div>
        </div>
    </div>

    <!-- TOC Drawer -->
    <div id="toc-drawer">
        <div class="toc-header">
            <h3>Table of Contents</h3>
            <button class="toc-close" id="tocClose">&times;</button>
        </div>
        <div class="toc-body" id="tocBody">
            <?php if (count($tocEntries) > 0): ?>
            <ul class="toc-list">
                <?php foreach ($tocEntries as $entry): ?>
                <li><a href="#" class="toc-link" data-chapter="<?php echo (int)($entry['page'] ?? 1); ?>"><?php echo htmlspecialchars($entry['title']); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="toc-empty">No table of contents available.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Group Notes Panel -->
    <div id="notes-panel" class="modal-wrapper">
        <button class="modal-close" id="notesClose">&times;</button>
        <div class="notes-header">
            <h3 style="margin:0;font-size:1.2rem;">📝 Group Notes</h3>
            <button class="note-submit" id="addNoteBtn" style="padding:6px 16px;font-size:0.85rem;">+ Add Note</button>
        </div>
        <div id="notesBody" style="flex:1;overflow-y:auto;">
            <div id="notesList" style="max-height:200px;overflow-y:auto;"></div>
            <div id="noteForm" style="display:none;margin-top:12px;">
                <textarea id="noteText" rows="3" placeholder="Write a note..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);font-family:'Inter',sans-serif;"></textarea>
                <input type="hidden" id="noteHighlightId" value="0">
                <div style="margin:6px 0;"><label><input type="checkbox" id="notePrivate"> Private note</label></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="note-submit" onclick="submitNote()">Post</button>
                    <button class="note-cancel" onclick="toggleNoteForm()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="share-modal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal('share-modal')">&times;</button>
        <h3><i class="fas fa-share-alt" style="color:var(--rose);"></i> Share</h3>
        <div id="shareQuotePreview">“<span id="shareQuoteText"></span>”</div>
        <div style="display:flex;flex-direction:column;margin-top:8px;">
            <button class="share-btn" onclick="share('facebook')"><i class="fab fa-facebook-f"></i> Facebook</button>
            <button class="share-btn" onclick="share('twitter')"><i class="fab fa-twitter"></i> Twitter</button>
            <button class="share-btn" onclick="share('whatsapp')"><i class="fab fa-whatsapp"></i> WhatsApp</button>
            <button class="share-btn" onclick="share('copy')"><i class="fas fa-link"></i> Copy Link</button>
        </div>
    </div>

    <!-- Reaction Picker -->
    <div id="reaction-picker" style="position:fixed;bottom:80px;right:20px;z-index:100002;display:none;background:var(--card-bg);border-radius:16px;padding:8px 12px;box-shadow:0 8px 30px rgba(0,0,0,0.15);border:1px solid var(--rose-light);gap:6px;align-items:center;pointer-events:auto;">
        <button onclick="reactNote(currentNoteId, '❤️')" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">❤️</button>
        <button onclick="reactNote(currentNoteId, '🙏')" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">🙏</button>
        <button onclick="reactNote(currentNoteId, '🔥')" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">🔥</button>
        <button onclick="reactNote(currentNoteId, '📖')" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">📖</button>
        <button onclick="reactNote(currentNoteId, '💔')" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">💔</button>
        <button onclick="document.getElementById('reaction-picker').style.display='none'" style="font-size:0.9rem;background:transparent;border:none;cursor:pointer;color:var(--text-light);">✕</button>
    </div>

    <!-- Challenge Widget (empty – will be filled by reader_challenges.php) -->
    <div id="challenge-widget"></div>

    <!-- Comments Modal -->
    <div id="commentsModal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal('commentsModal')">&times;</button>
        <h3><i class="fas fa-comments" style="color:var(--rose);"></i> Comments</h3>
        <div id="commentList" style="margin:12px 0;max-height:200px;overflow-y:auto;"></div>
        <textarea id="commentInput" rows="3" placeholder="Write a comment..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);margin-bottom:8px;"></textarea>
        <button onclick="submitComment()" style="background:var(--rose);color:var(--white);border:none;padding:10px 24px;border-radius:30px;cursor:pointer;font-weight:600;width:100%;">Post Comment</button>
    </div>

    <!-- Error Report Modal -->
    <div id="errorModal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal('errorModal')">&times;</button>
        <h3><i class="fas fa-exclamation-triangle" style="color:var(--rose);"></i> Report Error</h3>
        <div style="margin:12px 0;">
            <label style="font-weight:600;font-size:0.9rem;">Page number:</label>
            <input type="number" id="errorPageInput" min="1" max="<?php echo $total_pages; ?>" value="<?php echo $last_page; ?>" style="width:80px;padding:6px;border-radius:8px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);">
        </div>
        <div style="margin:12px 0;">
            <label style="font-weight:600;font-size:0.9rem;">Error description:</label>
            <textarea id="errorText" rows="3" placeholder="Describe the error..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);"></textarea>
        </div>
        <button onclick="submitError()" style="background:var(--rose);color:var(--white);border:none;padding:10px 24px;border-radius:30px;cursor:pointer;font-weight:600;width:100%;">Submit Error Report</button>
    </div>

    <!-- Prayer Request Modal -->
    <div id="prayerModal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal('prayerModal')">&times;</button>
        <h3><i class="fas fa-hands-praying" style="color:var(--rose);"></i> Prayer Request</h3>
        <div style="margin:12px 0;">
            <label style="font-weight:600;font-size:0.9rem;">Your prayer request:</label>
            <textarea id="prayerText" rows="4" placeholder="Write your prayer request here..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);"></textarea>
        </div>
        <button onclick="submitPrayer()" style="background:var(--rose);color:var(--white);border:none;padding:10px 24px;border-radius:30px;cursor:pointer;font-weight:600;width:100%;">Submit Prayer Request</button>
    </div>

    <!-- Search Bar -->
    <div id="search-bar">
        <div class="search-header">
            <input type="text" id="searchInput" placeholder="Search in book...">
            <button onclick="closeSearch()">×</button>
        </div>
        <div id="searchResults"></div>
    </div>

    <!-- Exit Focus Button -->
    <button id="exitFocusBtn" onclick="toggleFocus()"><i class="fas fa-compress"></i> Exit Focus</button>

    <!-- Overlay -->
    <div id="overlay"></div>
</div>

<!-- ====== Include all enhanced modules ====== -->
<script src="https://unpkg.com/page-flip/dist/js/page-flip.browser.min.js"></script>
<?php
// Include TTS, sharing, challenges, and gamification toast
require_once __DIR__ . '/reader_tts.php';
require_once __DIR__ . '/reader_share.php';
require_once __DIR__ . '/reader_challenges.php';

// Render the achievement toast (from gamification)
if (function_exists('renderAchievementToastJS')) {
    renderAchievementToastJS();
}
?>

<script>
(function() {
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
    const highlights = <?php echo json_encode($highlights); ?>;

    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container');
    const flipWrapper = document.getElementById('flip-book-wrapper');
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
    const noteHighlightId = document.getElementById('noteHighlightId');
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
    const prayerBtn = document.getElementById('prayerBtn');
    const prayerModal = document.getElementById('prayerModal');
    const prayerText = document.getElementById('prayerText');
    const backBtn = document.getElementById('backBtn');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const searchBtn = document.getElementById('searchBtn');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const searchBar = document.getElementById('search-bar');
    const exitFocusBtn = document.getElementById('exitFocusBtn');
    const analyticsBtn = document.getElementById('analyticsBtn');
    const myNotesBtn = document.getElementById('myNotesBtn');
    const ttsBtn = document.getElementById('ttsBtn');

    let currentPage = Math.min(lastPage, totalPages) || 1;
    let readingMode = localStorage.getItem('reader_mode') || 'flip';
    let focusMode = false;
    let isBookmarked = false;
    let touchStartX = 0;
    let currentNoteId = null;
    let savedRange = null;
    let pageFlip = null;

    // ----- Chapter helpers -----
    function getChapterForPage(page) { return pageToChapter[page] || 1; }
    function getChapterTitle(chapter) { return chapterTitles[chapter] || 'Chapter ' + chapter; }
    function getPagesInChapter(chapter) { return chapterMap[chapter] || []; }
    function getRemainingPagesInChapter(page) {
        const ch = getChapterForPage(page);
        const pagesInCh = getPagesInChapter(ch);
        const idx = pagesInCh.indexOf(page);
        return idx === -1 ? 0 : pagesInCh.length - idx - 1;
    }
    function estimateTimeRemaining(page) {
        const remaining = getRemainingPagesInChapter(page);
        if (remaining <= 0) return 0;
        return Math.ceil(remaining * 300 / readingSpeedWPM);
    }

    // ----- Theme -----
    function applyTheme(theme) {
        const app = document.getElementById('reader-app');
        app.classList.remove('theme-paper','theme-light','theme-dark','theme-sepia');
        app.classList.add('theme-'+theme);
        localStorage.setItem('reader_theme',theme);
    }

    // ----- Highlight application -----
    function applyHighlights(container) {
        if (!highlights || !container) return;
        const pageWrapper = container.querySelector(`.page-content-inner[data-page="${currentPage}"]`) || container;
        if (!pageWrapper) return;
        const walker = document.createTreeWalker(pageWrapper, NodeFilter.SHOW_TEXT, null, false);
        let node;
        while (node = walker.nextNode()) {
            const text = node.textContent;
            for (let h of highlights) {
                if (h.chapter_index != currentPage) continue;
                const idx = text.indexOf(h.text);
                if (idx !== -1 && node.parentElement && !node.parentElement.closest('.highlight-yellow, .highlight-green, .highlight-blue, .highlight-pink, .highlight-purple, .highlight-underline')) {
                    const range = document.createRange();
                    range.setStart(node, idx);
                    range.setEnd(node, idx + h.text.length);
                    const span = document.createElement('span');
                    if (h.color === 'underline') {
                        span.className = 'highlight-underline';
                    } else {
                        span.className = 'highlight-' + h.color;
                    }
                    span.textContent = h.text;
                    range.deleteContents();
                    range.insertNode(span);
                    applyHighlights(container);
                    return;
                }
            }
        }
    }

    // ----- StPageFlip initialization -----
    function initFlip() {
        if (pageFlip) {
            pageFlip.destroy();
            pageFlip = null;
        }
        let flipPagesHTML = [];
        if (cover_path) {
            flipPagesHTML.push(`<div class="flip-page-custom special-page"><div class="cover-image-wrapper-flip"><img src="${cover_path}" alt="Cover" /></div></div>`);
        } else {
            flipPagesHTML.push(`<div class="flip-page-custom special-page"><div class="cover-placeholder-flip"><i class="fas fa-book-open"></i><p>Cover</p></div></div>`);
        }
        pages.forEach((html, idx) => {
            flipPagesHTML.push(`<div class="flip-page-custom" data-page="${idx+1}">${html}</div>`);
        });

        flipWrapper.innerHTML = flipPagesHTML.join('');

        pageFlip = new StPageFlip(flipWrapper, {
            width: 450,
            height: 600,
            size: 'stretch',
            minWidth: 300,
            maxWidth: 900,
            minHeight: 400,
            maxHeight: 1200,
            showCover: true,
            maxShadowOpacity: 0.5,
            usePortrait: true,
            mobileScrollSupport: true,
        });
        pageFlip.loadFromHTML(document.querySelectorAll('.flip-page-custom'));
        pageFlip.on('turn', function(e) {
            const pageIndex = e.data; // 0 = cover, 1 = first content page
            if (pageIndex === 0) {
                currentPage = 0;
                updateUI(0);
            } else {
                currentPage = pageIndex;
                updateUI(currentPage);
                applyHighlights(flipWrapper);
                savePosition();
            }
        });
        // Initial page
        if (currentPage > 0) {
            pageFlip.turnToPage(currentPage);
        } else {
            pageFlip.turnToPage(0);
        }
    }

    // ----- UI updates -----
    function updateUI(page) {
        if (page === 0) {
            pageNumEl.textContent = 'Cover';
            progressFill.setAttribute('stroke-dashoffset', '100.53');
            progressPercent.textContent = '0%';
            chapterInfoEl.textContent = '📖 Cover';
            remainingInfoEl.textContent = '';
            return;
        }
        if (readingMode === 'flip') {
            pageNumEl.textContent = page;
        } else {
            pageNumEl.textContent = page;
        }
        const percent = Math.round((page/totalPages)*100);
        const circumference = 2 * Math.PI * 16;
        const offset = circumference - (percent/100)*circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = percent + '%';
        const ch = getChapterForPage(page);
        chapterInfoEl.textContent = `📖 ${getChapterTitle(ch)}`;
        remainingInfoEl.textContent = `⏳ ${getRemainingPagesInChapter(page)} pages remaining • ${estimateTimeRemaining(page)} min left`;
    }

    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip' && pageFlip) {
            pageFlip.turnToPage(pageNum);
        } else {
            const target = document.querySelector(`.page-content-inner[data-page="${pageNum}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(pageNum);
            applyHighlights(scrollContainer);
        }
        savePosition();
        loadNotes();
    }

    function savePosition() {
        if (userId === 0 || currentPage === 0) return;
        const data = new FormData();
        data.append('action','save_position');
        data.append('book_id',bookId);
        data.append('chapter',currentPage);
        data.append('percent',Math.round((currentPage/totalPages)*100));
        navigator.sendBeacon('/reader/reader_ajax.php',data);
    }

    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode',mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            flipContainer.style.display = 'flex';
            if (!pageFlip) initFlip();
            if (currentPage > 0) pageFlip.turnToPage(currentPage);
            else pageFlip.turnToPage(0);
            updateUI(currentPage);
            setTimeout(() => applyHighlights(flipWrapper), 100);
        } else {
            flipContainer.style.display = 'none';
            scrollContainer.style.display = 'block';
            const target = document.querySelector(`.page-content-inner[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(currentPage);
            applyHighlights(scrollContainer);
        }
    }

    // ----- Flip navigation buttons -----
    document.getElementById('flipPrevBtn').addEventListener('click', function() {
        if (pageFlip) pageFlip.turnToPrevPage();
    });
    document.getElementById('flipNextBtn').addEventListener('click', function() {
        if (pageFlip) pageFlip.turnToNextPage();
    });

    // ----- Keyboard shortcuts -----
    document.addEventListener('keydown',function(e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            if (readingMode === 'flip' && pageFlip) pageFlip.turnToNextPage();
            else scrollContainer.scrollBy({ top: scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (readingMode === 'flip' && pageFlip) pageFlip.turnToPrevPage();
            else scrollContainer.scrollBy({ top: -scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'Escape') { closeAll(); }
    });

    // ----- Click/touch navigation -----
    document.getElementById('page-viewport').addEventListener('click',function(e) {
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('#highlight-tooltip')) return;
        if (readingMode === 'flip' && pageFlip) {
            const rect = this.getBoundingClientRect();
            if (e.clientX - rect.left > rect.width/2) pageFlip.turnToNextPage();
            else pageFlip.turnToPrevPage();
        }
    });

    document.addEventListener('touchstart',function(e) { touchStartX = e.changedTouches[0].screenX; });
    document.addEventListener('touchend',function(e) {
        if (readingMode === 'flip' && pageFlip) {
            const diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) pageFlip.turnToNextPage();
                else pageFlip.turnToPrevPage();
            }
        }
    });

    // ----- Bookmarks -----
    bookmarkBtn.addEventListener('click',function() {
        if (userId === 0) { alert('Please log in to bookmark.'); return; }
        if (isBookmarked) {
            const xhr = new XMLHttpRequest(); xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData(); fd.append('action','remove_bookmark'); fd.append('book_id',bookId); xhr.send(fd);
            isBookmarked = false; bookmarkBtn.querySelector('i').className = 'far fa-bookmark'; bookmarkBtn.style.color = '#555';
        } else {
            const xhr = new XMLHttpRequest(); xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData(); fd.append('action','add_bookmark'); fd.append('book_id',bookId); fd.append('chapter',currentPage); fd.append('offset',0); xhr.send(fd);
            isBookmarked = true; bookmarkBtn.querySelector('i').className = 'fas fa-bookmark'; bookmarkBtn.style.color = 'var(--rose)';
        }
    });

    function loadBookmarkStatus() {
        if (userId === 0) return;
        const xhr = new XMLHttpRequest(); xhr.open('POST','/reader/reader_ajax.php',false);
        const fd = new FormData(); fd.append('action','list_bookmarks'); fd.append('book_id',bookId); xhr.send(fd);
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                let exists = false;
                data.bookmarks.forEach(b => { if (b.chapter_index == currentPage) exists = true; });
                isBookmarked = exists;
                bookmarkBtn.querySelector('i').className = exists ? 'fas fa-bookmark' : 'far fa-bookmark';
                bookmarkBtn.style.color = exists ? 'var(--rose)' : '#555';
            }
        } catch(e) {}
    }

    // ----- TOC -----
    document.querySelectorAll('.toc-link').forEach(link => {
        link.addEventListener('click',function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.chapter);
            if (page >= 1 && page <= totalPages) {
                goToPage(page);
                tocDrawer.classList.remove('open');
                overlay.classList.remove('active');
            }
        });
    });

    // ----- Settings -----
    document.querySelectorAll('#modeGroup button').forEach(btn => {
        btn.addEventListener('click',function() {
            const mode = this.dataset.mode;
            document.querySelectorAll('#modeGroup button').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            switchMode(mode);
        });
    });

    document.querySelectorAll('#themeGroup button').forEach(btn => {
        btn.addEventListener('click',function() {
            const theme = this.dataset.theme;
            document.querySelectorAll('#themeGroup button').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            applyTheme(theme);
        });
    });

    const savedTheme = localStorage.getItem('reader_theme') || 'light';
    applyTheme(savedTheme);
    document.querySelector('#themeGroup [data-theme="'+savedTheme+'"]')?.classList.add('active');

    // ----- Font size -----
    document.getElementById('fontSizeSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.fontSize = val+'%');
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
    document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.fontSize = savedSize+'%');
    document.getElementById('fontSizeLabel').textContent = savedSize+'%';

    // ----- Line height -----
    document.getElementById('lineHeightSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.lineHeight = (val/100).toFixed(1));
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
    document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.lineHeight = (savedLine/100).toFixed(1));
    document.getElementById('lineHeightLabel').textContent = (savedLine/100).toFixed(1);

    // ----- Font type -----
    const fontTypeSelect = document.getElementById('fontTypeSelect');
    const savedFont = localStorage.getItem('reader_font_family') || 'Inter,sans-serif';
    if (savedFont) { fontTypeSelect.value = savedFont; applyFontType(savedFont); }
    fontTypeSelect.addEventListener('change',function() { const font = this.value; applyFontType(font); localStorage.setItem('reader_font_family',font); });
    function applyFontType(font) { document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.fontFamily = font); }

    // ----- Reading speed -----
    document.getElementById('readingSpeedSlider').addEventListener('input',function() {
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

    // ----- Reading status auto-update -----
    readingStatus.addEventListener('change', function() {
        if (userId === 0) return;
        const status = this.value;
        const data = new FormData();
        data.append('action','update_reading_status');
        data.append('book_id',bookId);
        data.append('status',status);
        fetch('/reader/reader_ajax.php', { method:'POST', body:data });
    });

    // ----- Sidebar toggles -----
    sidebarToggle.addEventListener('click',function() { sidebar.classList.toggle('closed'); });
    settingsBtn.addEventListener('click',function() { settingsPanel.classList.toggle('open'); overlay.classList.toggle('active',settingsPanel.classList.contains('open')); });
    tocBtn.addEventListener('click', function() { tocDrawer.classList.toggle('open'); overlay.classList.toggle('active', tocDrawer.classList.contains('open')); });
    tocClose.addEventListener('click', function() { tocDrawer.classList.remove('open'); overlay.classList.remove('active'); });
    shareBtn.addEventListener('click', function() {
        const preview = document.getElementById('shareQuotePreview');
        const quoteSpan = document.getElementById('shareQuoteText');
        if (window.currentQuote && window.currentQuote.trim() !== '') {
            quoteSpan.textContent = window.currentQuote;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
        }
        openModal('share-modal');
    });
    commentsBtn.addEventListener('click', function() { if (userId === 0) { alert('Please log in to view comments.'); return; } loadComments(); openModal('commentsModal'); });
    errorReportBtn.addEventListener('click', function() { if (userId === 0) { alert('Please log in to report errors.'); return; } errorPageInput.value = currentPage; errorPageNumSpan.textContent = '(current: ' + currentPage + ')'; openModal('errorModal'); });
    prayerBtn.addEventListener('click', function() { if (userId === 0) { alert('Please log in to submit prayer requests.'); return; } prayerText.value = ''; openModal('prayerModal'); });
    challengeBtn.addEventListener('click', loadChallenge);

    // ----- Analytics / notes / TTS buttons (redirect) -----
    if(analyticsBtn) {
        analyticsBtn.addEventListener('click', function() { window.location.href = '/reader/reader_analytics.php?book_id=' + bookId; });
    }
    if(myNotesBtn) {
        myNotesBtn.addEventListener('click', function() { window.location.href = '/reader/reader_notes.php?book_id=' + bookId; });
    }
    if(ttsBtn) {
        // TTS is handled by reader_tts.php – we just need to trigger it
        // The TTS toggle button is already bound in reader_tts.php.
    }

    // ----- Focus mode -----
    function toggleFocus() {
        focusMode = !focusMode;
        document.getElementById('reader-app').classList.toggle('focus-mode', focusMode);
        exitFocusBtn.style.display = focusMode ? 'flex' : 'none';
        focusBtn.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) { settingsPanel.classList.remove('open'); overlay.classList.remove('active'); }
    }
    focusBtn.addEventListener('click', toggleFocus);

    // ----- Reset progress -----
    resetProgressBtn.addEventListener('click', function() {
        if (userId === 0) { alert('Please log in to reset progress.'); return; }
        if (confirm('Are you sure you want to reset your reading progress for this book? This cannot be undone.')) {
            const data = new FormData(); data.append('action', 'reset_progress'); data.append('book_id', bookId);
            const xhr = new XMLHttpRequest(); xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.onload = function() {
                try {
                    const res = JSON.parse(this.responseText);
                    if (res.success) { alert('✅ Progress has been reset.'); location.reload(); }
                    else { alert('Error: ' + (res.error || 'Could not reset progress.')); }
                } catch(e) { alert('Server error. Please try again.'); }
            };
            xhr.send(data);
        }
    });

    // ----- Challenge (handled by reader_challenges.php) -----
    function loadChallenge() {
        if (window.readerChallenges) {
            window.readerChallenges.openModal();
        } else {
            alert('Challenge system not loaded.');
        }
    }

    // ----- Selection tooltip -----
    function getSelectedText() { const sel = window.getSelection(); return sel.toString().trim(); }
    function getSelectionRange() { const sel = window.getSelection(); return sel.rangeCount > 0 ? sel.getRangeAt(0) : null; }

    function showSelectionTooltip(e) {
        e.stopPropagation();
        const text = getSelectedText(); const range = getSelectionRange();
        const tooltip = document.getElementById('highlight-tooltip');
        if (!text || !range || range.collapsed || text.length < 2) { tooltip.classList.remove('visible'); return; }
        const rect = range.getBoundingClientRect(); const tooltipWidth = 260;
        let leftPos = rect.left + rect.width/2 - tooltipWidth/2; let topPos = rect.top - 60;
        if (leftPos < 10) leftPos = 10; if (leftPos + tooltipWidth > window.innerWidth - 10) leftPos = window.innerWidth - tooltipWidth - 10; if (topPos < 10) topPos = rect.bottom + 10;
        tooltip.style.left = leftPos + 'px'; tooltip.style.top = topPos + 'px'; tooltip.dataset.text = text; savedRange = range; tooltip.classList.add('visible');
    }

    document.addEventListener('click', function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        if (tooltip && !tooltip.contains(e.target)) { tooltip.classList.remove('visible'); }
        if (tooltip && !tooltip.contains(e.target) && notesPanel && !notesPanel.contains(e.target)) { tooltip.classList.remove('visible'); notesPanel.classList.remove('visible'); overlay.classList.remove('active'); }
    });

    document.addEventListener('mouseup', function(e) {
        if (getSelectedText().length > 0) {
            setTimeout(function() { showSelectionTooltip(e); }, 50);
        }
    });
    document.addEventListener('touchend', function(e) {
        setTimeout(function() { showSelectionTooltip(e); }, 100);
    });

    function initSelectionTooltip() {
        const tooltip = document.getElementById('highlight-tooltip');
        if (!tooltip) return;
        tooltip.innerHTML = `
            <div>
                <div style="display:flex;gap:4px; flex-wrap:wrap;">
                    <button class="highlight-color" data-color="yellow" style="background:#fff9c4;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="green" style="background:#c8e6c9;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="blue" style="background:#bbdefb;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="pink" style="background:#f8bbd0;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="purple" style="background:#e8d5f5;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                </div>
                <div style="display:flex;gap:4px;margin-top:4px; flex-wrap:wrap;">
                    <button class="tooltip-action" data-action="copy"><i class="fas fa-copy"></i></button>
                    <button class="tooltip-action" data-action="underline"><i class="fas fa-underline"></i></button>
                    <button class="tooltip-action" data-action="note"><i class="fas fa-pen"></i></button>
                    <button class="tooltip-action" data-action="share"><i class="fas fa-share-alt"></i></button>
                    <button class="tooltip-action" data-action="question"><i class="fas fa-question-circle"></i></button>
                    <button class="tooltip-action" data-action="react"><i class="fas fa-smile"></i></button>
                </div>
            </div>
        `;
        tooltip.querySelectorAll('.highlight-color').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const color = this.dataset.color;
                const text = document.getElementById('highlight-tooltip').dataset.text;
                const range = savedRange;
                if (!range) return;
                const span = document.createElement('span');
                span.className = 'highlight-'+color;
                span.textContent = text;
                range.deleteContents(); range.insertNode(span);
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
                if (readingMode === 'flip') applyHighlights(flipWrapper);
                else applyHighlights(scrollContainer);
            });
        });
        tooltip.querySelectorAll('.tooltip-action').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const text = document.getElementById('highlight-tooltip').dataset.text;
                switch(action) {
                    case 'copy':
                        navigator.clipboard.writeText(text).then(()=>{alert('✅ Copied!');}).catch(()=>{document.execCommand('copy');});
                        break;
                    case 'underline':
                        const range = savedRange; if (!range) return;
                        const span = document.createElement('span');
                        span.className = 'highlight-underline';
                        span.textContent = text;
                        range.deleteContents(); range.insertNode(span);
                        tooltip.classList.remove('visible');
                        if(userId > 0) {
                            const data = new FormData();
                            data.append('action','add_highlight');
                            data.append('book_id',bookId);
                            data.append('chapter',currentPage);
                            data.append('text',text);
                            data.append('color','underline');
                            fetch('/reader/reader_ajax.php',{method:'POST',body:data});
                        }
                        break;
                    case 'note':
                        if (groupId > 0) {
                            const highlightData = new FormData();
                            highlightData.append('action','add_highlight');
                            highlightData.append('book_id',bookId);
                            highlightData.append('chapter',currentPage);
                            highlightData.append('text',text);
                            highlightData.append('color','yellow');
                            fetch('/reader/reader_ajax.php',{method:'POST',body:highlightData})
                            .then(r => r.json())
                            .then(res => {
                                if (res.success && res.id) {
                                    noteHighlightId.value = res.id;
                                } else {
                                    noteHighlightId.value = 0;
                                }
                                notesPanel.classList.add('visible');
                                overlay.classList.add('active');
                                loadNotes();
                                noteText.value = '"' + text + '"\n\n';
                                noteText.focus();
                            });
                        } else { alert('You need to be in a reading group to add notes.'); }
                        break;
                    case 'share':
                        window.currentQuote = text;
                        const preview = document.getElementById('shareQuotePreview');
                        const quoteSpan = document.getElementById('shareQuoteText');
                        quoteSpan.textContent = text;
                        preview.style.display = 'block';
                        document.getElementById('share-modal').classList.add('visible');
                        overlay.classList.add('active');
                        break;
                    case 'question':
                        if (groupId === 0) { alert('You need to be in a reading group.'); return; }
                        const q = prompt('Ask a question about this text:\n\n"' + text + '"');
                        if (q) {
                            const qData = new FormData();
                            qData.append('action','add_reader_note');
                            qData.append('group_id',groupId);
                            qData.append('book_id',bookId);
                            qData.append('chapter_index',currentPage);
                            qData.append('text','❓ Question: ' + q);
                            qData.append('is_private',0);
                            fetch('/reader/reader_ajax.php',{method:'POST',body:qData});
                            loadNotes();
                        }
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

    // ----- Group Notes -----
    function loadNotes() {
        if (groupId === 0) return;
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_notes&group_id='+groupId+'&book_id='+bookId+'&chapter='+currentPage,true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success) {
                    let html = '';
                    if (data.notes.length === 0) html = '<p class="empty-notes">No notes for this chapter.</p>';
                    else {
                        data.notes.forEach(n => {
                            let rh = '';
                            if (n.reactions && n.reactions.length > 0) {
                                n.reactions.forEach(r => { rh += `<span class="reaction" onclick="reactNote(${n.id}, '${r.reaction_type}')">${r.reaction_type} ${r.count}</span>`; });
                            }
                            const canReact = !n.is_private || n.user_id == userId;
                            const isMyNote = n.user_id == userId;
                            html += `<div class="note-card${n.is_private?' private':''}"><div class="note-author"><div class="note-avatar-placeholder">${(n.display_name||n.username).charAt(0).toUpperCase()}</div><div class="note-author-info"><strong>${n.display_name||n.username}</strong> <small>${timeAgo(n.created_at)}</small>${n.is_private ? '<span class="badge-private">🔒 Private</span>' : ''}</div></div><p class="note-text">${n.text}</p><div class="note-footer"><div class="note-reactions">${rh}${canReact ? `<button style="background:transparent;border:none;cursor:pointer;font-size:1.1rem;" onclick="showReactionPicker(${n.id}, event)">➕</button>` : ''}</div>${isMyNote ? `<button style="background:transparent;border:none;cursor:pointer;color:var(--text-light);" onclick="deleteNote(${n.id})">🗑️</button>` : ''}</div></div>`;
                        });
                    }
                    document.getElementById('notesList').innerHTML = html;
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

    resumeBtn.addEventListener('click',function() { resumePosition(); });
    function resumePosition() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(() => {
                    const target = document.querySelector(`.page-content-inner[data-page="${lastPage}"]`);
                    if (target) target.scrollIntoView({ block:'start' });
                }, 100);
            }
        }
    }

    function share(platform) {
        const url = window.location.origin+'/reader/reader.php?id='+bookId+'&chapter='+currentPage;
        let text = '📖 I\'m reading on AngelWrites!';
        if (window.currentQuote && window.currentQuote.trim() !== '') {
            text = '"' + window.currentQuote + '" - Read on AngelWrites!';
        }
        switch(platform) {
            case 'facebook': window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(url)+'&quote='+encodeURIComponent(text),'_blank'); break;
            case 'twitter': window.open('https://twitter.com/intent/tweet?text='+encodeURIComponent(text)+'&url='+encodeURIComponent(url),'_blank'); break;
            case 'whatsapp': window.open('https://api.whatsapp.com/send?text='+encodeURIComponent(text+' '+url),'_blank'); break;
            case 'copy': navigator.clipboard.writeText(text + ' ' + url).then(()=>{alert('✅ Copied!');}).catch(()=>{ const ta=document.createElement('textarea'); ta.value=text+' '+url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); alert('✅ Copied!'); }); break;
        }
        window.currentQuote = null;
        closeShare();
    }
    function closeShare() { closeModal('share-modal'); }

    notesBtn.addEventListener('click', function() {
        if (groupId === 0) { alert('You are not in a reading group for this book.'); return; }
        const panel = document.getElementById('notes-panel');
        if (!panel.classList.contains('visible')) {
            panel.classList.add('visible');
            overlay.classList.add('active');
            loadNotes();
        } else {
            panel.classList.remove('visible');
            overlay.classList.remove('active');
        }
    });
    document.getElementById('notesClose').addEventListener('click', function() {
        document.getElementById('notes-panel').classList.remove('visible');
        overlay.classList.remove('active');
    });

    function toggleNoteForm() {
        const form = document.getElementById('noteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') { document.getElementById('noteText').focus(); }
    }
    function submitNote() {
        const text = document.getElementById('noteText').value.trim();
        const isPrivate = document.getElementById('notePrivate').checked ? 1 : 0;
        const highlightId = document.getElementById('noteHighlightId').value;
        if (!text) return alert('Please enter a note.');
        const data = new FormData();
        data.append('action','add_reader_note');
        data.append('group_id',groupId);
        data.append('book_id',bookId);
        data.append('chapter_index',currentPage);
        data.append('text',text);
        data.append('is_private',isPrivate);
        if (highlightId > 0) data.append('highlight_id', highlightId);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() {
            try {
                const d = JSON.parse(this.responseText);
                if (d.success) {
                    loadNotes();
                    document.getElementById('noteText').value = '';
                    document.getElementById('notePrivate').checked = false;
                    document.getElementById('noteHighlightId').value = 0;
                    document.getElementById('noteForm').style.display = 'none';
                } else { alert('Error: ' + d.error); }
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
        xhr.onload = function() { loadNotes(); document.getElementById('reaction-picker').style.display = 'none'; };
        xhr.send(data);
    }
    function showReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const picker = document.getElementById('reaction-picker');
        const btn = event.target.closest('button');
        const rect = btn.getBoundingClientRect();
        picker.style.top = (rect.top - 50) + 'px';
        picker.style.left = (rect.left) + 'px';
        picker.style.display = 'flex';
    }

    // ----- Comments -----
    function loadComments() {
        if (userId === 0) return;
        const formData = new FormData();
        formData.append('action','get_book_comments');
        formData.append('book_id',bookId);
        formData.append('page_num',currentPage);
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData}).then(r=>r.json()).then(data=>{
            if (data.success) {
                const list = document.getElementById('commentList');
                list.innerHTML = '';
                if (data.comments.length === 0) list.innerHTML = '<p style="color:var(--text-light);text-align:center;padding:20px;">No comments on this page yet.</p>';
                else {
                    data.comments.forEach(com=>{
                        const isAdmin = com.is_admin_reply == 1;
                        const authorName = isAdmin ? 'Angella (Admin)' : com.author_name;
                        const badge = isAdmin ? '<span class="admin-badge">🛡️ Admin</span>' : '';
                        list.innerHTML += `<div class="comment-item ${isAdmin?'admin':''}"><div class="comment-author"><i class="fas fa-user-circle"></i> ${authorName} ${badge}</div><div style="font-size:0.85rem;color:var(--text-light);">${timeAgo(com.created_at)}</div><div style="margin-top:4px;">${com.comment}</div></div>`;
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
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData}).then(r=>r.json()).then(data=>{
            if (data.success) { document.getElementById('commentInput').value = ''; loadComments(); } else { alert('Error: ' + (data.error || 'Failed to post comment.')); }
        });
    }

    // ----- Modal utilities -----
    function openModal(id) {
        const modal = document.getElementById(id);
        if (modal) { modal.style.display = ''; modal.classList.add('visible'); overlay.classList.add('active'); }
    }
    function closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) { modal.classList.remove('visible'); overlay.classList.remove('active'); }
    }
    function closeComments() { closeModal('commentsModal'); }
    function closePrayerModal() { closeModal('prayerModal'); }
    function closeSearch() { searchBar.classList.remove('visible'); overlay.classList.remove('active'); searchInput.value = ''; searchResults.innerHTML = ''; }
    function closeShare() { closeModal('share-modal'); }
    function closeErrorModal() { closeModal('errorModal'); }
    function closeSettings() { settingsPanel.classList.remove('open'); overlay.classList.remove('active'); }

    function closeAll() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        closeModal('share-modal'); closeModal('commentsModal'); closeModal('errorModal'); closeModal('prayerModal');
        document.getElementById('notes-panel').classList.remove('visible');
        document.getElementById('challenge-widget').classList.remove('visible');
        document.getElementById('search-bar').classList.remove('visible');
        document.getElementById('reaction-picker').style.display = 'none';
        overlay.classList.remove('active');
        if (focusMode) { toggleFocus(); }
    }

    backBtn.addEventListener('click',function() { window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>'; });

    function submitError() {
        const page = parseInt(errorPageInput.value) || currentPage;
        const desc = errorText.value.trim();
        if (!desc) { alert('Please describe the error.'); return; }
        const data = new FormData();
        data.append('action','submit_error_report');
        data.append('book_id',bookId);
        data.append('page_num',page);
        data.append('description',desc);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() {
            try {
                const res = JSON.parse(this.responseText);
                if (res.success) { alert('✅ Error report submitted. Thank you!'); closeErrorModal(); } else { alert('Error: ' + (res.error || 'Could not submit report.')); }
            } catch(e) { alert('Server error. Please try again.'); }
        };
        xhr.send(data);
    }

    function submitPrayer() {
        const text = prayerText.value.trim();
        if (!text) { alert('Please write your prayer request.'); return; }
        const data = new FormData();
        data.append('action','submit_prayer_request');
        data.append('book_id',bookId);
        data.append('text',text);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() {
            try {
                const res = JSON.parse(this.responseText);
                if (res.success) { alert('✅ Prayer request submitted. We will pray for you.'); closePrayerModal(); } else { alert('Error: ' + (res.error || 'Could not submit prayer request.')); }
            } catch(e) { alert('Server error. Please try again.'); }
        };
        xhr.send(data);
    }

    // ----- Export highlights -----
    exportHighlightsBtn.addEventListener('click', function() {
        if (userId === 0) { alert('Please log in to export your highlights.'); return; }
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_highlights&book_id=' + bookId, true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success && data.highlights.length > 0) {
                    const exportData = {
                        book: '<?php echo addslashes($book['title']); ?>',
                        highlights: data.highlights.map(h => ({
                            chapter: h.chapter_index,
                            text: h.text,
                            color: h.color,
                            note: h.note || '',
                            created_at: h.created_at
                        }))
                    };
                    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'highlights_' + bookId + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    alert('✅ Highlights exported!');
                } else { alert('No highlights to export.'); }
            } catch(e) { alert('Error exporting highlights: ' + e.message); }
        };
        xhr.send();
    });

    // ----- Search -----
    searchBtn.addEventListener('click', function() {
        searchBar.classList.toggle('visible');
        if (searchBar.classList.contains('visible')) {
            searchInput.focus(); searchInput.value = '';
            searchResults.innerHTML = '';
            overlay.classList.add('active');
        } else { overlay.classList.remove('active'); }
    });

    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        if (query.length < 2) { searchResults.innerHTML = ''; return; }
        let results = [];
        pages.forEach((html, idx) => {
            const stripped = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
            if (stripped.includes(query)) {
                const pageNum = idx + 1;
                const snippet = stripped.substring(Math.max(0, stripped.indexOf(query) - 60), Math.min(stripped.length, stripped.indexOf(query) + 120));
                results.push({ page: pageNum, snippet: snippet.replace(query, '<strong>' + query + '</strong>') });
            }
        });
        if (results.length === 0) searchResults.innerHTML = '<p style="padding:8px;color:var(--text-light);">No results found.</p>';
        else {
            let html = '<div style="font-weight:600;font-size:0.8rem;padding:4px 8px;">' + results.length + ' result(s)</div>';
            results.forEach(r => { html += `<div class="search-result" onclick="goToPage(${r.page}); closeSearch();">Page ${r.page} … ${r.snippet}</div>`; });
            searchResults.innerHTML = html;
        }
    });

    // ----- Overlay closes all -----
    overlay.addEventListener('click', closeAll);

    // ----- Expose globals -----
    window.adjustFontSize = adjustFontSize;
    window.adjustLineHeight = adjustLineHeight;
    window.goToPage = goToPage;
    window.resumePosition = resumePosition;
    window.closeAll = closeAll;
    window.share = share;
    window.closeShare = closeShare;
    window.loadChallenge = loadChallenge;
    window.toggleNoteForm = toggleNoteForm;
    window.submitNote = submitNote;
    window.deleteNote = deleteNote;
    window.reactNote = reactNote;
    window.showReactionPicker = showReactionPicker;
    window.loadComments = loadComments;
    window.submitComment = submitComment;
    window.closeComments = closeComments;
    window.submitError = submitError;
    window.submitPrayer = submitPrayer;
    window.closeSearch = closeSearch;
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.toggleFocus = toggleFocus;

    // ----- Initialization -----
    totalPagesEl.textContent = totalPages;
    const savedMode = localStorage.getItem('reader_mode');
    if (savedMode === 'flip') {
        readingMode = 'flip';
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="flip"]').classList.add('active');
        switchMode('flip');
    } else if (savedMode === 'scroll') {
        readingMode = 'scroll';
        document.querySelector('#modeGroup [data-mode="flip"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.add('active');
        switchMode('scroll');
    } else {
        readingMode = 'flip';
        localStorage.setItem('reader_mode', 'flip');
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="flip"]').classList.add('active');
        switchMode('flip');
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