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
$cover_path = isset($book['cover_path']) && !empty($book['cover_path']) ? SITE_URL . '/' . $book['cover_path'] : '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($book['title']); ?></title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;width:100%;overflow:hidden}
        :root{--rose:#DBA1A2;--rose-dark:#c08a8b;--rose-light:#e8c0c0;--vanilla:#EFD8D6;--fantasy:#F7F3ED;--white:#ffffff;--dark:#2c1e1e;--text:#3d2e2e;--text-light:#6b5a5a;--bg:#F7F3ED;--card-bg:#ffffff;--border:#e5d5d5;--shadow:0 4px 16px rgba(44,30,30,0.08);--shadow-hover:0 8px 30px rgba(44,30,30,0.15);--input-bg:#ffffff;--transition:0.3s cubic-bezier(0.4,0,0.2,1)}
        [data-theme="dark"]{--rose:#dba1a2;--rose-dark:#c08a8b;--rose-light:#e8c0c0;--vanilla:#3d2e2e;--fantasy:#2c1e1e;--white:#1a1212;--dark:#f0e8e8;--text:#e8dddd;--text-light:#b8a8a8;--bg:#1a1212;--card-bg:#2c1e1e;--border:#4a3a3a;--shadow:0 4px 16px rgba(0,0,0,0.4);--shadow-hover:0 8px 30px rgba(0,0,0,0.6);--input-bg:#2c1e1e}

        #reader-app{position:fixed;top:0;left:0;width:100%;height:100%;display:flex;flex-direction:column;background:var(--bg);z-index:10000;font-family:'Inter',sans-serif;color:var(--text);transition:background var(--transition),color var(--transition)}

        #toolbar{flex-shrink:0;height:60px;min-height:60px;display:flex;justify-content:space-between;align-items:center;padding:0 20px;background:var(--card-bg);border-bottom:1px solid var(--border);z-index:20;box-shadow:var(--shadow)}
        .toolbar-left{display:flex;align-items:center;gap:16px}
        .toolbar-left .title{font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:1.15rem;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--dark)}
        .toolbar-left button{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-light);transition:color var(--transition);width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center}
        .toolbar-left button:hover{color:var(--rose);background:rgba(219,161,162,0.1)}
        .toolbar-center{display:flex;align-items:center;gap:12px;font-size:0.95rem;color:var(--text-light)}
        .toolbar-center .progress-ring{position:relative;width:36px;height:36px}
        .toolbar-center .progress-ring svg{width:100%;height:100%;transform:rotate(-90deg)}
        .toolbar-center .progress-ring .bg{stroke:var(--border);stroke-width:2;fill:none}
        .toolbar-center .progress-ring .fill{stroke:var(--rose);stroke-width:2;fill:none;transition:stroke-dashoffset var(--transition)}
        .toolbar-center .progress-ring .percent{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:0.7rem;font-weight:600;color:var(--text-light)}
        .toolbar-right{display:flex;align-items:center;gap:8px}
        .toolbar-right button{background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--text-light);padding:6px 10px;border-radius:6px;transition:all var(--transition);display:flex;align-items:center;justify-content:center}
        .toolbar-right button:hover{background:rgba(219,161,162,0.1);color:var(--rose);transform:scale(1.05)}
        .streak-badge{background:var(--rose);color:var(--white);padding:2px 12px;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap}

        #sidebar{position:fixed;top:60px;left:0;width:48px;height:calc(100% - 60px);background:var(--card-bg);border-right:1px solid var(--border);z-index:15;display:flex;flex-direction:column;align-items:center;padding:8px 0;gap:4px;overflow-y:auto;transition:transform 0.25s ease}
        #sidebar.closed{transform:translateX(-100%)}
        #sidebar.open{transform:translateX(0)}
        #page-viewport{margin-left:48px;flex:1;position:relative;overflow:hidden;background:var(--bg);display:flex;justify-content:center;align-items:center}
        .focus-mode #sidebar{transform:translateX(-100%)}
        .sidebar-btn{width:36px;height:36px;border:none;background:transparent;color:var(--text-light);font-size:1rem;cursor:pointer;border-radius:8px;transition:all var(--transition);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sidebar-btn:hover{background:rgba(219,161,162,0.1);color:var(--rose);transform:scale(1.05)}
        .sidebar-btn.active{color:var(--rose);background:rgba(219,161,162,0.15)}
        .sidebar-separator{width:28px;border:none;border-top:1px solid var(--border);margin:4px 0}
        @media (max-width:768px){#sidebar{display:none}#page-viewport{margin-left:0}}

        #scroll-container{height:100%;width:100%;overflow-y:auto;padding:20px 20px 120px 20px;display:flex;flex-direction:column;align-items:center}
        .page-content-wrapper{width:100%;max-width:900px;margin:0 auto 40px auto;padding:10px;background:linear-gradient(145deg,var(--rose-light),var(--vanilla));border-radius:20px;box-shadow:var(--shadow-hover);border:1px solid var(--rose);transition:transform 0.3s ease}
        .page-content-wrapper:hover{transform:translateY(-2px)}
        .page-content-inner{width:100%;padding:30px 40px;background:var(--card-bg);border-radius:12px;box-shadow:inset 0 0 20px rgba(0,0,0,0.03);font-size:1.05rem;line-height:1.8;color:var(--text);min-height:400px}
        .page-content-inner h1,.page-content-inner h2,.page-content-inner h3{font-family:'Playfair Display',Georgia,serif;color:var(--dark)}
        .page-content-inner p{margin-bottom:16px}
        .page-content-inner p:last-child{margin-bottom:0}

        #flip-container{width:100%;height:100%;position:relative;perspective:2500px;justify-content:center;align-items:center;background:var(--bg);display:none}
        .flip-book{position:relative;width:95%;max-width:900px;height:92%;max-height:900px;transform-style:preserve-3d;transition:transform 0.9s cubic-bezier(0.645,0.045,0.355,1)}
        .flip-page{position:absolute;top:0;left:0;width:100%;height:100%;backface-visibility:hidden;border-radius:20px;box-shadow:var(--shadow-hover);border:1px solid var(--rose);background:linear-gradient(145deg,var(--rose-light),var(--vanilla));padding:10px;overflow:hidden}
        .flip-page-front{z-index:2;transform:rotateY(0deg);transform-origin:left center}
        .flip-page-back{transform:rotateY(180deg);transform-origin:right center}
        .flip-page-inner{width:100%;height:100%;padding:30px 40px;background:var(--card-bg);border-radius:12px;box-shadow:inset 0 0 20px rgba(0,0,0,0.03);overflow-y:auto;font-size:1.05rem;line-height:1.8;color:var(--text);font-family:'Inter',sans-serif}
        .flip-page-inner h1,.flip-page-inner h2,.flip-page-inner h3{font-family:'Playfair Display',Georgia,serif;color:var(--dark)}
        .flip-page-inner p{margin-bottom:16px}
        .flip-page-inner p:last-child{margin-bottom:0}
        .flip-page::before{content:'';position:absolute;top:0;bottom:0;width:40px;pointer-events:none;background:linear-gradient(to right,rgba(0,0,0,0.08) 0%,transparent 100%);z-index:1}
        .flip-page-front::before{left:0}
        .flip-page-back::before{right:0}
        .flip-book.page-right-flipped{transform:rotateY(-180deg)}
        .flip-book.page-left-flipped{transform:rotateY(180deg)}
        .flip-nav-btn-wrapper{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,0.8);backdrop-filter:blur(4px);box-shadow:0 4px 16px rgba(0,0,0,0.1);display:flex;align-items:center;justify-content:center;z-index:10;transition:background .3s;border:1px solid var(--rose-light)}
        .flip-nav-btn-wrapper:hover{background:rgba(255,255,255,1);box-shadow:0 4px 24px rgba(0,0,0,0.15)}
        .flip-nav-btn-wrapper .aw-nav-btn{position:static !important;transform:none !important;background:transparent !important;border:none !important;box-shadow:none !important;color:var(--text) !important;width:44px;height:44px;margin:0;padding:0;display:flex;align-items:center;justify-content:center}
        .flip-nav-btn-wrapper .aw-nav-btn i{font-size:1.2rem}
        .flip-nav-btn-wrapper .aw-nav-btn:hover{color:var(--rose) !important;transform:scale(1.1) !important}
        #flipPrevBtnWrapper{left:16px}
        #flipNextBtnWrapper{right:16px}


        .cover-image-wrapper{width:100%;max-width:900px;margin:0 auto 40px auto;padding:10px;background:linear-gradient(145deg,var(--rose-light),var(--vanilla));border-radius:20px;box-shadow:var(--shadow-hover);border:1px solid var(--rose)}
        .cover-image-container{width:100%;border-radius:12px;overflow:hidden;background:var(--card-bg);box-shadow:inset 0 0 20px rgba(0,0,0,0.05)}
        .cover-image-container img{width:100%;height:auto;display:block;object-fit:contain;max-height:80vh;transition:transform 0.3s ease}
        .cover-image-container img:hover{transform:scale(1.01)}
        .cover-placeholder{width:100%;min-height:400px;display:flex;flex-direction:column;justify-content:center;align-items:center;background:linear-gradient(135deg,var(--vanilla),var(--fantasy));color:var(--text-light);text-align:center;padding:40px}
        .cover-placeholder i{font-size:4rem;color:var(--rose);margin-bottom:16px}
        .cover-placeholder p{font-family:'Playfair Display',Georgia,serif;font-size:1.5rem;font-weight:600;color:var(--dark)}

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

        #notes-panel{bottom:0;right:0;width:400px;max-height:60vh;background:var(--card-bg);border:1px solid var(--border);border-radius:12px 12px 0 0;box-shadow:0 -4px 20px rgba(44,30,30,0.1);display:none;flex-direction:column;pointer-events:auto}
        #notes-panel.open{display:flex}
        .notes-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:var(--vanilla);border-radius:12px 12px 0 0}
        .notes-header h3{margin:0;font-size:1rem;font-family:'Playfair Display',Georgia,serif}
        .notes-body{flex:1;overflow-y:auto;padding:12px 16px}
        .note-card{border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:12px;background:var(--card-bg)}
        .note-card.private{border-left:4px solid var(--text-light)}
        .note-author{display:flex;gap:8px;align-items:center;margin-bottom:8px}
        .note-avatar-placeholder{width:32px;height:32px;border-radius:50%;background:var(--rose);color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:0.85rem}
        .note-author-info{flex:1}
        .note-author-info strong{color:var(--dark)}
        .note-author-info small{color:var(--text-light)}
        .note-text{margin:0 0 8px;font-size:0.95rem;color:var(--text)}
        .note-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border)}
        .note-reactions{display:flex;flex-wrap:wrap;gap:4px;align-items:center}
        .reaction{background:var(--vanilla);padding:0 8px;border-radius:12px;font-size:0.8rem;cursor:pointer;transition:all var(--transition)}
        .reaction:hover{background:rgba(219,161,162,0.2)}
        .badge-private{background:var(--text-light);color:var(--white);padding:0 6px;border-radius:4px;font-size:0.7rem}
        .empty-notes{color:var(--text-light);text-align:center;padding:24px 12px}
        #noteForm{display:none;padding:12px 16px;border-top:1px solid var(--border)}
        #noteForm textarea{width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;resize:vertical;font-size:0.9rem;background:var(--input-bg);color:var(--text);font-family:'Inter',sans-serif}
        #noteForm textarea:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}
        #noteForm label{color:var(--text);font-size:0.9rem}
        #noteForm button{padding:4px 12px;border-radius:6px;border:none;cursor:pointer;font-size:0.8rem}
        .note-submit{background:var(--rose);color:var(--white)}
        .note-submit:hover{background:var(--rose-dark)}
        .note-cancel{background:var(--border);color:var(--text)}
        .note-cancel:hover{background:var(--text-light);color:var(--white)}

        #share-modal{top:0;left:0;width:100%;height:100%;background:rgba(44,30,30,0.6);display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
        #share-modal.visible{display:flex}
        .share-content{background:var(--card-bg);padding:24px 32px;border-radius:16px;max-width:400px;width:90%;text-align:center;box-shadow:var(--shadow-hover)}
        .share-content h3{font-family:'Playfair Display',Georgia,serif;color:var(--dark);margin-top:0}
        .share-options{display:flex;flex-direction:column;gap:8px;margin:16px 0}
        .share-options button{padding:8px 16px;border:1px solid var(--border);border-radius:8px;background:var(--card-bg);cursor:pointer;transition:all var(--transition);font-size:0.9rem;color:var(--text);width:100%;text-align:left}
        .share-options button:hover{border-color:var(--rose);background:rgba(219,161,162,0.05)}
        .share-options button i{margin-right:8px;color:var(--rose)}
        .share-close{background:var(--rose);color:var(--white);border:none;padding:8px 24px;border-radius:30px;cursor:pointer;transition:background var(--transition);width:100%;margin-top:12px;font-weight:600}
        .share-close:hover{background:var(--rose-dark)}

        #overlay{top:0;left:0;width:100%;height:100%;background:rgba(44,30,30,0.4);display:none;z-index:9998 !important}
        #overlay.active{display:block}
        #challenge-widget{display:none;margin:8px 16px;padding:12px 16px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow)}
        #challenge-widget h4{margin:0 0 4px;font-size:1rem}
        .challenge-progress{position:relative;height:12px;background:var(--border);border-radius:6px;overflow:hidden}
        .challenge-progress .bar{height:100%;background:var(--rose);transition:width 0.3s}

        #readingStatus{appearance:none;background-color:var(--card-bg);border:1px solid var(--border);border-radius:30px;padding:6px 36px 6px 16px;font-size:0.85rem;font-weight:500;color:var(--text);cursor:pointer;transition:all var(--transition);background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b5a5a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 12px center;background-size:16px}
        #readingStatus:hover{border-color:var(--rose)}
        #readingStatus:focus{outline:none;border-color:var(--rose);box-shadow:0 0 0 3px rgba(219,161,162,0.15)}

        .focus-mode #toolbar{transform:translateY(-100%);opacity:0;pointer-events:none;transition:all var(--transition)}
        .focus-mode #settings-panel.open{display:none !important}

        #commentsModal .modal-content{max-width:600px;max-height:80vh}
        #commentsModal .modal-body{max-height:60vh;overflow-y:auto;padding:10px}
        #commentsModal .modal-body textarea{width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;resize:vertical;min-height:60px}
        #commentsModal .modal-body .form-actions{display:flex;gap:8px;margin-top:8px}
        .comment-list{margin-bottom:16px}
        .comment-item{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px}
        .comment-item.admin{border-left:4px solid var(--rose)}
        .comment-author{font-weight:600;display:flex;align-items:center;gap:6px}
        .comment-author .admin-badge{background:var(--rose);color:white;font-size:0.65rem;padding:2px 8px;border-radius:12px}
        .modal{display:none;position:fixed;z-index:20000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)}
        .modal-content{background:var(--card-bg);margin:10% auto;padding:20px;border-radius:16px;width:90%;max-width:500px;box-shadow:var(--shadow-hover)}
        .modal-close{float:right;font-size:1.4rem;cursor:pointer;color:var(--text-light);transition:color 0.2s}
        .modal-close:hover{color:var(--rose)}
        .modal h3{margin-top:0}
        .modal .form-group{margin-bottom:12px}
        .modal .form-group label{display:block;margin-bottom:4px;font-weight:600}
        .modal .form-group input,.modal .form-group textarea,.modal .form-group select{width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--input-bg);color:var(--text)}
        .modal .form-group textarea{resize:vertical;min-height:60px}
        .modal .btn{margin-top:4px}
        @media (max-width:768px){#toolbar{height:48px;padding:0 8px}.toolbar-left .title{font-size:0.9rem;max-width:160px}.page-content-inner{padding:20px}.flip-page-content{padding:20px}#toc-drawer{width:280px;right:-280px}#notes-panel{width:100%;max-height:50vh;border-radius:0}.settings-grid{grid-template-columns:1fr 1fr}}
        @media (max-width:480px){.toolbar-left .title{font-size:0.8rem;max-width:120px}.page-content-inner{padding:16px}.flip-page-content{padding:16px}}
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
        </div>
        <div class="toolbar-right">
            <button id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>
    </div>

    <div id="sidebar">
        <button class="sidebar-btn" id="searchBtn" title="Search"><i class="fas fa-search"></i></button>
        <button class="sidebar-btn" id="bookmarkBtn" title="Bookmark"><i class="far fa-bookmark"></i></button>
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
        <button class="sidebar-btn" id="resumeBtn" title="Resume Position"><i class="fas fa-bookmark"></i></button>
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
            <?php foreach ($pages as $page_html): ?>
            <div class="page-content-wrapper"><div class="page-content-inner"><?php echo $page_html; ?></div></div>
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

    <div id="settings-panel">
        <div class="settings-grid">
            <div class="settings-group"><label>Mode</label><div class="btn-group" id="modeGroup"><button data-mode="scroll" class="active">Scroll</button><button data-mode="flip">Page Flip</button></div></div>
            <div class="settings-group"><label>Theme</label><div class="btn-group" id="themeGroup"><button data-theme="paper">Paper</button><button data-theme="light" class="active">Light</button><button data-theme="dark">Dark</button><button data-theme="sepia">Sepia</button></div></div>
            <div class="settings-group"><label>Font Size</label><div class="slider-group"><button onclick="adjustFontSize(-5)">A-</button><input type="range" id="fontSizeSlider" min="70" max="160" value="100" step="5"><button onclick="adjustFontSize(5)">A+</button><span id="fontSizeLabel">100%</span></div></div>
            <div class="settings-group"><label>Font Type</label><div class="font-select-wrapper"><select id="fontTypeSelect"><option value="Inter, sans-serif">Inter</option><option value="Georgia, serif">Georgia</option><option value="'Playfair Display', Georgia, serif">Playfair Display</option></select></div></div>
            <div class="settings-group"><label>Line Height</label><div class="slider-group"><button onclick="adjustLineHeight(-10)">-</button><input type="range" id="lineHeightSlider" min="140" max="220" value="180" step="10"><button onclick="adjustLineHeight(10)">+</button><span id="lineHeightLabel">1.8</span></div></div>
        </div>
    </div>

    <div id="toc-drawer"><div class="toc-header"><h3>Table of Contents</h3><button class="toc-close" id="tocClose">&times;</button></div><div class="toc-body" id="tocBody"><?php if (is_array($toc) && count($toc) > 0): ?><ul class="toc-list"><?php foreach ($toc as $entry): ?><li><a href="#" class="toc-link" data-chapter="<?php echo (int)($entry['page'] ?? 1); ?>"><?php echo htmlspecialchars($entry['title']); ?></a></li><?php endforeach; ?></ul><?php else: ?><p class="toc-empty">No table of contents available.</p><?php endif; ?></div></div>

    <div id="notes-panel"><div class="notes-header"><h3>📝 Group Notes</h3><div><button class="note-submit" id="addNoteBtn">+ Add</button><button class="note-cancel" id="notesClose">&times;</button></div></div><div class="notes-body" id="notesBody"><div id="notesList"><p class="empty-notes">No notes for this chapter.</p></div><div id="noteForm"><textarea id="noteText" rows="2" placeholder="Write a note..."></textarea><div><label><input type="checkbox" id="notePrivate"> Private</label></div><button class="note-submit" onclick="submitNote()">Post</button><button class="note-cancel" onclick="toggleNoteForm()">Cancel</button></div></div></div>

    <div id="commentsModal" class="modal"><div class="modal-content"><span class="modal-close" onclick="closeCommentsModal()">&times;</span><h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments (Page <span id="currentCommentPage">1</span>)</h3><div class="modal-body"><div id="commentList" class="comment-list"></div><?php if (isLoggedIn()): ?><div><h4>Add a Comment</h4><textarea id="commentInput" rows="2" placeholder="Share your thoughts on this page..."></textarea><button class="btn btn-primary" onclick="submitComment()">Post</button></div><?php else: ?><p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to comment.</p><?php endif; ?></div></div></div>

    <div id="errorModal" class="modal"><div class="modal-content"><span class="modal-close" onclick="closeErrorModal()">&times;</span><h3><i class="fas fa-exclamation-triangle" style="color: var(--rose);"></i> Report an Error</h3><p>Help us improve by reporting typos or errors on <strong>Page <span id="errorPageNum">1</span></strong>.</p><form id="errorForm"><input type="hidden" id="errorBookId" value="<?php echo $book_id; ?>"><input type="hidden" id="errorPageInput" value="1"><label>What is wrong?</label><textarea id="errorText" rows="2" placeholder="e.g. Typo on line 3..." required></textarea><label>Suggested Correction (optional)</label><input type="text" id="errorCorrection" placeholder="e.g. 'their' instead of 'there'"><button type="button" class="btn btn-primary" onclick="submitError()">Submit Report</button></form></div></div>

    <div id="prayerModal" class="modal"><div class="modal-content"><span class="modal-close" onclick="closePrayerModal()">&times;</span><h3><i class="fas fa-hands-praying" style="color: var(--rose);"></i> Send a Prayer Request</h3><p>Share your prayer request with Angella.</p><form id="prayerForm"><input type="hidden" id="prayerBookId" value="<?php echo $book_id; ?>"><label>Your Prayer Request</label><textarea id="prayerText" rows="4" placeholder="Write your prayer request here..." required></textarea><button type="button" class="btn btn-primary" onclick="submitPrayer()">Send Prayer Request</button></form></div></div>

    <div id="reaction-picker"><button data-reaction="👍">👍</button><button data-reaction="❤️">❤️</button><button data-reaction="🙏">🙏</button><button data-reaction="🤔">🤔</button><button data-reaction="📖">📖</button></div>
    <div id="highlight-tooltip"></div>
    <div id="annotation-popup"><textarea id="annotationText" rows="3" placeholder="Add a note…"></textarea><div><button class="annotation-save" id="annotationSave">Save</button><button class="annotation-cancel" id="annotationCancel">Cancel</button></div></div>
    <div id="search-bar"><div><input type="text" id="searchInput" placeholder="Search in this book…"><button onclick="closeSearch()"><i class="fas fa-times"></i></button></div><div id="searchResults"></div></div>
    <div id="share-modal"><div class="share-content"><h3>Share this page</h3><div><button onclick="share('facebook')"><i class="fab fa-facebook-f"></i> Facebook</button><button onclick="share('twitter')"><i class="fab fa-twitter"></i> Twitter</button><button onclick="share('whatsapp')"><i class="fab fa-whatsapp"></i> WhatsApp</button><button onclick="share('copy')"><i class="fas fa-link"></i> Copy Link</button></div><button class="share-close" onclick="closeShare()">Close</button></div></div>
    <div id="challenge-widget"></div>
    <div id="overlay" onclick="closeAll()"></div>
</div>

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

    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container');
    const pageNumEl = document.getElementById('pageNum');
    const totalPagesEl = document.getElementById('totalPages');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
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
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const searchBtn = document.getElementById('searchBtn');
    const bookmarkBtn = document.getElementById('bookmarkBtn');
    const tocBtn = document.getElementById('tocBtn');
    const notesBtn = document.getElementById('notesBtn');
    const settingsBtn = document.getElementById('settingsBtn');
    const shareBtn = document.getElementById('shareBtn');
    const resetProgressBtn = document.getElementById('resetProgressBtn');
    const exportHighlightsBtn = document.getElementById('exportHighlightsBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const challengeBtn = document.getElementById('challengeBtn');
    const commentsBtn = document.getElementById('commentsBtn');
    const commentsModal = document.getElementById('commentsModal');
    const currentCommentPageSpan = document.getElementById('currentCommentPage');
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

    let currentPage = Math.min(lastPage, totalPages) || 1;
    let readingMode = localStorage.getItem('reader_mode') || 'scroll';
    let focusMode = false;
    let isBookmarked = false;
    let touchStartX = 0;
    let currentNoteId = null;
    let selectedText = '';
    let selectedRange = null;
    let flipCurrentChunkIndex = 0;
    let flipChunks = [];

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
    if (userId > 0) { startSession(); loadChallenge(); }

    sidebarToggle.addEventListener('click', function() { sidebar.classList.toggle('closed'); });

    readingStatus.addEventListener('change', function() {
        if (userId === 0) { alert('Please log in to set reading status.'); return; }
        var data = new FormData();
        data.append('action', 'set_reading_status');
        data.append('book_id', bookId);
        data.append('status', this.value);
        navigator.sendBeacon('/reader/reader_ajax.php', data);
    });

    backBtn.addEventListener('click', function() { window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>'; });

    // ===== HELPER: Escape HTML =====
    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

        // ===== SWITCH MODE =====
    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode', mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            flipContainer.style.display = 'flex';
            loadFlipPages(currentPage);
        } else {
            flipContainer.style.display = 'none';
            scrollContainer.style.display = 'block';
            const target = document.querySelector(`.page-content-inner[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        updateUI(currentPage);
    }

    // ===== FLIP MODE – CHUNKED CONTENT + NO INNER WRAPPER =====

    function loadFlipPages(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;

        function formatPageHTML(rawHtml) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = rawHtml;
            const paragraphs = tempDiv.querySelectorAll('p');
            let result = '';
            paragraphs.forEach(p => {
                const text = p.textContent.trim();
                if (!text) return;

                if (/^(Chapter|CHAPTER|CHAP\.?)\s+(\d+|[IVXLCDM]+)/i.test(text)) {
                    result += `<h2 class="chapter-heading">${escapeHtml(text)}</h2>`;
                }
                else if (text.startsWith('ACKNOWLEDGEMENT')) {
                    const rest = text.substring('ACKNOWLEDGEMENT'.length).trim();
                    result += `<h3>Acknowledgements</h3><p>${escapeHtml(rest)}</p>`;
                }
                else if (text.startsWith("AUTHOR'S NOTE")) {
                    const rest = text.substring("AUTHOR'S NOTE".length).trim();
                    result += `<h3>Author's Note</h3><p>${escapeHtml(rest)}</p>`;
                }
                else if (text.startsWith('ABOUT THE AUTHOR')) {
                    const rest = text.substring('ABOUT THE AUTHOR'.length).trim();
                    result += `<h3>About the Author</h3><p>${escapeHtml(rest)}</p>`;
                }
                else if (/^Psalm\s+(\d+)/i.test(text)) {
                    result += `<h3>${escapeHtml(text)}</h3>`;
                }
                else if (/^To\s+[A-Za-z]/.test(text)) {
                    result += `<p class="dedication">${escapeHtml(text)}</p>`;
                }
                else {
                    result += `<p>${escapeHtml(text)}</p>`;
                }
            });
            return result;
        }

        function getCoverHTML() {
            if (cover_path && cover_path.length > 0) {
                return `<div class="cover-image-wrapper"><div class="cover-image-container"><img src="${cover_path}" alt="Cover" /></div></div>`;
            }
            return `<div class="cover-image-wrapper"><div class="cover-image-container"><div class="cover-placeholder"><i class="fas fa-book-open"></i><p>Cover Image</p></div></div></div>`;
        }

        let contentToSplit;
        if (pageNum === 1) {
            contentToSplit = getCoverHTML();
        } else {
            // Format the page content but do NOT wrap it in .page-content-wrapper
            contentToSplit = formatPageHTML(pages[pageNum - 1] || '');
        }

        // Split the content into smaller chunks (approx. 1500 characters)
        const chunks = splitContent(contentToSplit, 1500);

        // Store chunks and current index
        flipBook.dataset.chunks = JSON.stringify(chunks);
        flipBook.dataset.currentChunk = 0;
        flipBook.dataset.pageNum = pageNum;

        // Render the first chunk
        renderChunk(chunks[0] || '');
    }

    function splitContent(html, charLimit) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const text = tempDiv.textContent || tempDiv.innerText || '';
        const words = text.split(/\s+/);
        const chunks = [];
        let currentChunk = '';
        let currentChunkHTML = '';

        // Helper to extract valid HTML for a given text chunk
        function getHTMLForText(sourceDiv, textChunk) {
            // Simple approach: rebuild from paragraphs
            const paragraphs = sourceDiv.querySelectorAll('p, h2, h3');
            let result = '';
            let charCount = 0;
            for (let p of paragraphs) {
                const pText = p.textContent.trim();
                if (pText.length === 0) continue;
                if (charCount + pText.length > charLimit && charCount > 0) break;
                result += p.outerHTML;
                charCount += pText.length;
            }
            if (result.length === 0) {
                // Fallback: return the first paragraph
                const firstP = sourceDiv.querySelector('p');
                return firstP ? firstP.outerHTML : textChunk;
            }
            return result;
        }

        // Iterate through words to build chunks
        for (let i = 0; i < words.length; i++) {
            if ((currentChunk.length + words[i].length + 1) > charLimit) {
                // Wrap the chunk in a way that preserves HTML structure
                const chunkHTML = getHTMLForText(tempDiv, currentChunk);
                if (chunkHTML) chunks.push(chunkHTML);
                currentChunk = words[i];
            } else {
                if (currentChunk.length > 0) currentChunk += ' ';
                currentChunk += words[i];
            }
        }
        if (currentChunk.length > 0) {
            const chunkHTML = getHTMLForText(tempDiv, currentChunk);
            if (chunkHTML) chunks.push(chunkHTML);
        }

        // If no chunks were created, return the original HTML
        if (chunks.length === 0) {
            return [html];
        }
        return chunks;
    }

    function renderChunk(html) {
        // Update the left page content (front)
        flipLeftContent.innerHTML = html;
        // Clear the right page (back)
        flipRightContent.innerHTML = '';

        // Reset book rotation
        flipBook.classList.remove('page-right-flipped', 'page-left-flipped');
        flipBook.style.transform = 'rotateY(0deg)';
    }

    function flipToNext() {
        const chunks = JSON.parse(flipBook.dataset.chunks || '[]');
        const currentChunk = parseInt(flipBook.dataset.currentChunk);
        const pageNum = parseInt(flipBook.dataset.pageNum);

        if (currentChunk < chunks.length - 1) {
            // Move to the next chunk within the same page
            flipBook.classList.add('page-right-flipped');
            setTimeout(() => {
                renderChunk(chunks[currentChunk + 1]);
                flipBook.dataset.currentChunk = currentChunk + 1;
                updateUI(pageNum);
            }, 800);
        } else {
            // Move to the next actual page
            if (pageNum < totalPages) {
                flipBook.classList.add('page-right-flipped');
                setTimeout(() => {
                    loadFlipPages(pageNum + 1);
                    updateUI(pageNum + 1);
                    savePosition();
                }, 800);
            }
        }
    }

    function flipToPrev() {
        const currentChunk = parseInt(flipBook.dataset.currentChunk);
        const pageNum = parseInt(flipBook.dataset.pageNum);

        if (currentChunk > 0) {
            // Move to the previous chunk within the same page
            const chunks = JSON.parse(flipBook.dataset.chunks || '[]');
            flipBook.classList.add('page-left-flipped');
            setTimeout(() => {
                renderChunk(chunks[currentChunk - 1]);
                flipBook.dataset.currentChunk = currentChunk - 1;
                updateUI(pageNum);
            }, 800);
        } else {
            // Move to the previous actual page
            if (pageNum > 1) {
                flipBook.classList.add('page-left-flipped');
                setTimeout(() => {
                    loadFlipPages(pageNum - 1);
                    updateUI(pageNum - 1);
                    savePosition();
                }, 800);
            }
        }
    }

    // ===== NAVIGATION FUNCTIONS =====
    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip') {
            loadFlipPages(pageNum);
        } else {
            const target = document.querySelector(`.page-content-inner[data-page="${pageNum}"]`);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            updateUI(pageNum);
        }
        savePosition();
        loadNotes();
    }

    // ===== ATTACH EVENT LISTENERS =====
    document.getElementById('flipPrevBtn').addEventListener('click', flipToPrev);
    document.getElementById('flipNextBtn').addEventListener('click', flipToNext);

    function updateUI(page) {
        pageNumEl.textContent = page;
        var percent = Math.round((page / totalPages) * 100);
        var circumference = 2 * Math.PI * 16;
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

    function applyTheme(theme) {
        var app = document.getElementById('reader-app');
        app.classList.remove('theme-paper', 'theme-light', 'theme-dark', 'theme-sepia');
        app.classList.add('theme-' + theme);
        localStorage.setItem('reader_theme', theme);
    }

    document.querySelectorAll('#themeGroup button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var theme = this.dataset.theme;
            document.querySelectorAll('#themeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            applyTheme(theme);
        });
    });

    var savedTheme = localStorage.getItem('reader_theme') || 'light';
    applyTheme(savedTheme);
    var themeBtn = document.querySelector('#themeGroup [data-theme="' + savedTheme + '"]');
    if (themeBtn) themeBtn.classList.add('active');

    document.getElementById('fontSizeSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner, .flip-page-content').forEach(function(el) { el.style.fontSize = val + '%'; });
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
    document.querySelectorAll('.page-content-inner, .flip-page-content').forEach(function(el) { el.style.fontSize = savedSize + '%'; });
    document.getElementById('fontSizeLabel').textContent = savedSize + '%';

    document.getElementById('lineHeightSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner, .flip-page-content').forEach(function(el) { el.style.lineHeight = (val / 100).toFixed(1); });
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
    document.querySelectorAll('.page-content-inner, .flip-page-content').forEach(function(el) { el.style.lineHeight = (savedLine / 100).toFixed(1); });
    document.getElementById('lineHeightLabel').textContent = (savedLine / 100).toFixed(1);

    const fontTypeSelect = document.getElementById('fontTypeSelect');
    const savedFont = localStorage.getItem('reader_font_family') || 'Inter, sans-serif';
    if (savedFont) { fontTypeSelect.value = savedFont; applyFontType(savedFont); }
    fontTypeSelect.addEventListener('change', function() {
        const font = this.value;
        applyFontType(font);
        localStorage.setItem('reader_font_family', font);
    });
    function applyFontType(font) {
        document.querySelectorAll('.page-content-inner, .flip-page-content').forEach(function(el) {
            el.style.fontFamily = font;
        });
    }

    document.getElementById('page-viewport').addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('a')) return;
        if (readingMode === 'flip') {
            var rect = this.getBoundingClientRect();
            var x = e.clientX - rect.left;
            if (x > rect.width / 2) nextPage(); else prevPage();
        }
    });

    document.addEventListener('touchstart', function(e) { touchStartX = e.changedTouches[0].screenX; });
    document.addEventListener('touchend', function(e) {
        if (readingMode === 'flip') {
            var diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) nextPage(); else prevPage();
            }
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight') nextPage();
        else if (e.key === 'ArrowLeft') prevPage();
        else if (e.key === 'Escape') { closeAll(); }
        else if (e.ctrlKey && e.key === 'f') { e.preventDefault(); toggleSearch(); }
    });

    settingsBtn.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        overlay.classList.toggle('active', settingsPanel.classList.contains('open'));
    });

    tocBtn.addEventListener('click', function() {
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

    notesBtn.addEventListener('click', function() {
        if (groupId === 0) { alert('You are not in a reading group for this book.'); return; }
        notesPanel.classList.toggle('open');
        overlay.classList.toggle('active', notesPanel.classList.contains('open'));
        if (notesPanel.classList.contains('open')) loadNotes();
    });

    notesClose.addEventListener('click', function() {
        notesPanel.classList.remove('open');
        overlay.classList.remove('active');
    });

    window.toggleNoteForm = function() { noteForm.style.display = noteForm.style.display === 'none' ? 'block' : 'none'; };
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

    searchBtn.addEventListener('click', function() { toggleSearch(); });
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

    shareBtn.addEventListener('click', function() {
        document.getElementById('share-modal').classList.add('visible');
        document.getElementById('overlay').classList.add('active');
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

    challengeBtn.addEventListener('click', function() { loadChallenge(); });
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

    resumeBtn.addEventListener('click', function() { resumePosition(); });
    window.resumePosition = function() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(function() {
                    var target = document.querySelector('.page-content-inner[data-page="' + lastPage + '"]');
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

    function timeAgo(timestamp) {
        var diff = Date.now() - new Date(timestamp).getTime();
        var secs = Math.floor(diff / 1000);
        if (secs < 60) return 'just now';
        if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
        if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
        if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    function getSelectedText() {
        const sel = window.getSelection();
        return sel.toString().trim();
    }
    function getSelectionRange() {
        const sel = window.getSelection();
        return sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    }
    function showSelectionTooltip() {
        const text = getSelectedText();
        const range = getSelectionRange();
        const tooltip = document.getElementById('highlight-tooltip');
        if (!text || !range || text.length < 1) {
            tooltip.classList.remove('visible');
            return;
        }
        const rect = range.getBoundingClientRect();
        const tooltipWidth = 320;
        const leftPos = rect.left + rect.width / 2 - tooltipWidth / 2;
        const topPos = rect.top - 50;
        tooltip.style.left = Math.max(10, leftPos) + 'px';
        tooltip.style.top = Math.max(10, topPos) + 'px';
        tooltip.classList.add('visible');
        tooltip.dataset.text = text;
        tooltip.dataset.rangeStart = range.startOffset;
        tooltip.dataset.rangeEnd = range.endOffset;
        tooltip.dataset.node = range.commonAncestorContainer.parentElement;
    }
    document.addEventListener('click', function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        if (tooltip && !tooltip.contains(e.target)) {
            tooltip.classList.remove('visible');
        }
    });

    function initSelectionTooltip() {
        const tooltip = document.getElementById('highlight-tooltip');
        if (!tooltip) return;
        tooltip.innerHTML = `
            <div>
                <div><button class="highlight-color" data-color="yellow"></button><button class="highlight-color" data-color="green"></button><button class="highlight-color" data-color="blue"></button><button class="highlight-color" data-color="pink"></button></div>
                <div><button class="tooltip-action" data-action="copy"><i class="fas fa-copy"></i></button><button class="tooltip-action" data-action="note"><i class="fas fa-pen"></i></button><button class="tooltip-action" data-action="share"><i class="fas fa-share-alt"></i></button><button class="tooltip-action" data-action="question"><i class="fas fa-question-circle"></i></button><button class="tooltip-action" data-action="react"><i class="fas fa-smile"></i></button></div>
            </div>
        `;
        tooltip.querySelectorAll('.highlight-color').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const color = this.dataset.color;
                const text = tooltip.dataset.text;
                const range = getSelectionRange();
                if (!range) return;
                const span = document.createElement('span');
                span.className = 'highlight-' + color;
                span.textContent = text;
                range.deleteContents();
                range.insertNode(span);
                tooltip.classList.remove('visible');
                if (userId > 0) {
                    const data = new FormData();
                    data.append('action', 'add_highlight');
                    data.append('book_id', bookId);
                    data.append('chapter', currentPage);
                    data.append('text', text);
                    data.append('color', color);
                    fetch('/reader/reader_ajax.php', { method: 'POST', body: data });
                }
            });
        });
        tooltip.querySelectorAll('.tooltip-action').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const text = tooltip.dataset.text;
                const range = getSelectionRange();
                switch(action) {
                    case 'copy': navigator.clipboard.writeText(text).then(() => { alert('✅ Copied!'); }).catch(() => { document.execCommand('copy'); }); break;
                    case 'note': annotationPopup.classList.add('visible'); annotationText.value = '"' + text + '"\n\n'; annotationText.focus(); break;
                    case 'share': document.getElementById('share-modal').classList.add('visible'); overlay.classList.add('active'); break;
                    case 'question': if (groupId === 0) { alert('You need to be in a reading group.'); return; } 
                        const question = prompt('Ask a question about this text:\n\n"' + text + '"');
                        if (question) { /* ... */ }
                        break;
                    case 'react': const picker = document.getElementById('reaction-picker'); if (picker) { picker.style.display = 'flex'; picker.dataset.text = text; } break;
                }
                tooltip.classList.remove('visible');
            });
        });
    }
    initSelectionTooltip();

    document.addEventListener('mouseup', function(e) { setTimeout(showSelectionTooltip, 50); });
    document.addEventListener('touchend', function(e) { setTimeout(showSelectionTooltip, 100); });

    reactionPicker.querySelectorAll('button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const reaction = this.dataset.reaction;
            const picker = document.getElementById('reaction-picker');
            const text = picker.dataset.text;
            picker.style.display = 'none';
            if (userId > 0) {
                const data = new FormData();
                data.append('action', 'add_reaction');
                data.append('book_id', bookId);
                data.append('chapter', currentPage);
                data.append('text', text);
                data.append('reaction', reaction);
                fetch('/reader/reader_ajax.php', { method: 'POST', body: data });
            }
        });
    });

    annotationSave.addEventListener('click', function() {
        var note = annotationText.value.trim();
        if (note && getSelectedText()) {
            var data = new FormData();
            data.append('action', 'add_highlight');
            data.append('book_id', bookId);
            data.append('chapter', currentPage);
            data.append('text', getSelectedText());
            data.append('color', 'yellow');
            data.append('note', note);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/reader/reader_ajax.php', true);
            xhr.onload = function() {
                annotationPopup.classList.remove('visible');
                alert('✅ Annotation saved!');
            };
            xhr.send(data);
        }
    });
    annotationCancel.addEventListener('click', function() { annotationPopup.classList.remove('visible'); });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#highlight-tooltip') && !e.target.closest('#annotation-popup')) {
            annotationPopup.classList.remove('visible');
        }
    });

    window.goToPage = goToPage;

    window.closeAll = function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.classList.remove('open');
        searchBar.classList.remove('visible');
        document.getElementById('share-modal').classList.remove('visible');
        overlay.classList.remove('active');
        if (focusMode) {
            focusMode = false;
            document.getElementById('reader-app').classList.remove('focus-mode');
            document.getElementById('focusBtn').querySelector('i').className = 'fas fa-expand';
        }
    };
    overlay.addEventListener('click', closeAll);

    // ===== COMMENTS =====
    function loadComments() {
        if (userId === 0) return;
        currentCommentPageSpan.textContent = currentPage;
        const formData = new FormData();
        formData.append('action', 'get_book_comments');
        formData.append('book_id', bookId);
        formData.append('page_num', currentPage);
        fetch('/reader/reader_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                commentList.innerHTML = '';
                if (data.comments.length === 0) {
                    commentList.innerHTML = '<p style="color:var(--text-light);text-align:center;padding:20px;">No comments on this page yet.</p>';
                } else {
                    data.comments.forEach(com => {
                        const isAdmin = com.is_admin_reply == 1;
                        const authorName = isAdmin ? 'Angella (Admin)' : com.author_name;
                        const badge = isAdmin ? '<span class="admin-badge">🛡️ Admin</span>' : '';
                        commentList.innerHTML += `
                            <div class="comment-item ${isAdmin ? 'admin' : ''}">
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
    window.submitComment = function() {
        const text = commentInput.value.trim();
        if (!text) return alert('Please write a comment.');
        const formData = new FormData();
        formData.append('action', 'add_book_comment');
        formData.append('book_id', bookId);
        formData.append('page_num', currentPage);
        formData.append('comment', text);
        fetch('/reader/reader_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                commentInput.value = '';
                loadComments();
            } else {
                alert('Error: ' + (data.error || 'Failed to post comment.'));
            }
        });
    };
    window.openCommentsModal = function() {
        commentsModal.style.display = 'block';
        loadComments();
        overlay.classList.add('active');
    };
    window.closeCommentsModal = function() {
        commentsModal.style.display = 'none';
        overlay.classList.remove('active');
    };
    commentsBtn.addEventListener('click', openCommentsModal);

    // ===== ERROR REPORT =====
    window.openErrorModal = function() {
        errorPageNumSpan.textContent = currentPage;
        errorPageInput.value = currentPage;
        errorText.value = '';
        errorCorrection.value = '';
        errorModal.style.display = 'block';
        overlay.classList.add('active');
    };
    window.closeErrorModal = function() {
        errorModal.style.display = 'none';
        overlay.classList.remove('active');
    };
    window.submitError = function() {
        if (userId === 0) { alert('Please login to report an error.'); return; }
        const text = errorText.value.trim();
        if (!text) return alert('Please describe the error.');
        const formData = new FormData();
        formData.append('action', 'report_book_error');
        formData.append('book_id', bookId);
        formData.append('page_num', errorPageInput.value);
        formData.append('error_text', text);
        formData.append('correction', errorCorrection.value);
        fetch('/reader/reader_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Error report submitted. Thank you for helping improve the book!');
                closeErrorModal();
            } else {
                alert('Error: ' + (data.error || 'Failed to submit report.'));
            }
        });
    };
    errorReportBtn.addEventListener('click', openErrorModal);

    // ===== PRAYER REQUESTS =====
    window.openPrayerModal = function() {
        prayerText.value = '';
        prayerModal.style.display = 'block';
        overlay.classList.add('active');
    };
    window.closePrayerModal = function() {
        prayerModal.style.display = 'none';
        overlay.classList.remove('active');
    };
    window.submitPrayer = function() {
        if (userId === 0) { alert('Please login to submit a prayer request.'); return; }
        const text = prayerText.value.trim();
        if (!text) return alert('Please write your prayer request.');
        const formData = new FormData();
        formData.append('action', 'submit_prayer_request');
        formData.append('book_id', bookId);
        formData.append('request_text', text);
        fetch('/reader/reader_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Prayer request sent. Angella will pray for you.');
                closePrayerModal();
            } else {
                alert('Error: ' + (data.error || 'Failed to send request.'));
            }
        });
    };
    prayerBtn.addEventListener('click', openPrayerModal);

    overlay.addEventListener('click', function() {
        if (commentsModal.style.display === 'block') closeCommentsModal();
        if (errorModal.style.display === 'block') closeErrorModal();
        if (prayerModal.style.display === 'block') closePrayerModal();
    });

})();
</script>
</body>
</html>