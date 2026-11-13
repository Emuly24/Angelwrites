<?php
// ============================================================
//  READER.PHP – Final Fully Integrated Reader
//  Includes all enhancements: StPageFlip, highlights, TTS,
//  sharing, and more. (C1: gamification and challenges are retired.)
// ============================================================

// S7 (F211): errors are logged, never printed to visitors (they leaked server paths).
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
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

// --- Table of contents entries for the TOC drawer ---
$tocEntries = buildTOC($pages, $chapterMap, $chapterTitles);

// --- User progress (already extracted, but we need some variables for the UI) ---
$last_offset = $user_progress['position_offset'] ?? 0;
$last_chapter = $user_progress['position_section'] ?? 0;

// Q6: a book whose file has not been processed for the reader opened an empty reader showing "1 / 0"
// with no explanation. Show a normal site page that says so and leads to the book page instead.
if (empty($pages)) {
    $pageTitle = $book['title'];
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="max-width:640px;margin:40px auto;padding:0 16px;text-align:center;">'
       . '<h1 style="font-size:1.6rem;">' . htmlspecialchars($book['title']) . '</h1>'
       . '<p>This book is not ready to read online yet. Please check back soon.</p>'
       . '<p><a class="btn btn-primary" href="' . SITE_URL . '/book.php?id=' . (int)$book_id . '">Go to the book page</a></p>'
       . '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$progress_percent = $user_progress['progress_percent'] ?? 0;

// --- Get additional user data ---
$group_id = null;
$reading_status = 'not_started';
$reading_speed_wpm = 250;

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];

    // Group
    $stmt = $db->prepare("SELECT g.id FROM reading_groups g JOIN group_members m ON g.id = m.group_id WHERE g.content_type = 'book' AND g.content_id = ? AND m.user_id = ? LIMIT 1"); // F17: groups store the book as content_type/content_id (book_id is unset)
    $stmt->execute([$book_id, $user_id]);
    $group_id = $stmt->fetchColumn();

    // Reading status
    $stmt = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $reading_status = str_replace(' ', '_', (string)($stmt->fetchColumn() ?: 'not_started')); // F112: stored as 'currently reading'

    // Reading speed
    $stmt = $db->prepare("SELECT reading_speed_wpm FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $speed = $stmt->fetchColumn();
    if ($speed) $reading_speed_wpm = (int)$speed;
}
// C1: the reader no longer shows a reading streak, a level, achievements or a monthly challenge
// (PROGRAM_PLAN.md, wish C).

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
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet" /><!-- R13: easy-to-read font -->
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/book-reader.css?v=1" /><!-- the reader's look: a quiet, page-first design -->
<?php if (!empty($processed['reader_css'])): // F90: the per-book CSS saved in admin/process_book.php was never applied ?>
<style id="book-reader-css"><?php echo str_ireplace('</style', '', (string)$processed['reader_css']); ?></style>
<?php endif; ?>
</head>
<body>
<div id="reader-app">
    <div id="awPageAnnounce" class="aw-sr-only" aria-live="polite" aria-atomic="true"></div><!-- R12 -->
<?php if (!empty($preview_version)): ?>
    <!-- R5: draft preview banner (admin only) -->
    <div id="previewBanner" role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:60;background:#f7ecdc;color:#5c4400;border-top:2px solid #e0b100;padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:0.9rem;">
        <strong>Preview of draft version <?php echo (int)$preview_version; ?></strong>
        <span><?php echo $live_version ? 'Members currently read version ' . (int)$live_version . '.' : 'Members cannot read this book yet.'; ?> Nothing is saved while previewing.</span>
        <span style="flex:1"></span>
        <a href="<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo (int)$book_id; ?>" style="color:#5c4400;min-height:40px;display:inline-flex;align-items:center;">Back to processing</a>
        <button type="button" id="previewPublish" style="background:#2e7d32;color:#fff;border:0;border-radius:8px;padding:8px 14px;min-height:40px;cursor:pointer;">Publish this version</button>
    </div>
    <script>
    document.getElementById('previewPublish').addEventListener('click', function () {
        if (!confirm('Publish version <?php echo (int)$preview_version; ?>? Members will read it from now on.')) return;
        const fd = new FormData(); fd.append('action', 'publish_version'); fd.append('version', '<?php echo (int)$preview_version; ?>');
        fetch('<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo (int)$book_id; ?>', { method: 'POST', body: fd })
            .then(r => r.json()).then(d => { alert(d.success ? d.message : (d.error || 'Could not publish.')); if (d.success) location.href = '<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$book_id; ?>'; })
            .catch(() => alert('Could not publish. Please try again from the processing page.'));
    });
    </script>
<?php endif; ?>
    <!-- Top bar: back, contents, title, then Aa (themes & settings), search, bookmark and the "…" menu.
         It fades away while you read; tap the page to bring it back. -->
    <div id="toolbar">
        <div class="toolbar-left">
            <a id="backBtn" href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$book_id; ?>" aria-label="Close the book"><i class="fas fa-chevron-left" aria-hidden="true"></i></a><!-- Q3: a real link (was a JS button) -->
            <button type="button" class="rb-icon" id="tocBtn" title="Contents" aria-label="Contents" aria-controls="toc-drawer" aria-expanded="false"><i class="fas fa-list-ul" aria-hidden="true"></i></button>
        </div>
        <div class="toolbar-center">
            <span class="title"><?php echo htmlspecialchars($book['title']); ?></span>
            <span class="subtitle" id="rbChapter" aria-hidden="true"></span>
        </div>
        <div class="toolbar-right">
            <button type="button" class="rb-icon rb-aa" id="settingsBtn" title="Themes &amp; settings" aria-label="Themes and settings" aria-controls="settings-panel" aria-expanded="false"><small>A</small>A</button>
            <button type="button" class="rb-icon" id="searchBtn" title="Search this book" aria-label="Search this book"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></button>
            <button type="button" class="rb-icon rb-hide-sm" id="bookmarkBtn" title="Bookmark this page" aria-label="Bookmark this page"><i class="far fa-bookmark" aria-hidden="true"></i></button>
            <button type="button" id="sidebarToggle" title="More" aria-label="More" aria-haspopup="true" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-ellipsis" aria-hidden="true"></i></button>
        </div>
    </div>

    <!-- The "…" menu: every other reader tool, with its name. -->
    <div id="sidebar" role="dialog" aria-label="More" tabindex="-1">
        <div class="rb-menu-group">
            <div class="rb-status"><label for="readingStatus">Reading status</label>
                <select id="readingStatus">
                    <option value="not_started" <?php echo $reading_status == 'not_started' ? 'selected' : ''; ?>>Not started</option>
                    <option value="currently_reading" <?php echo $reading_status == 'currently_reading' ? 'selected' : ''; ?>>Reading</option>
                    <option value="finished" <?php echo $reading_status == 'finished' ? 'selected' : ''; ?>>Finished</option>
                    <option value="want_to_read" <?php echo $reading_status == 'want_to_read' ? 'selected' : ''; ?>>Want to read</option>
                    <option value="dropped" <?php echo $reading_status == 'dropped' ? 'selected' : ''; ?>>Stopped</option>
                </select>
            </div>
            <button type="button" class="sidebar-btn" id="rbBookmarkMenu" aria-label="Bookmark this page"><i class="far fa-bookmark" aria-hidden="true"></i><span>Bookmark this page</span></button>
            <button type="button" class="sidebar-btn" id="resumeBtn" aria-label="Go to my last place"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Go to my last place</span></button>
            <button type="button" class="sidebar-btn" id="focusBtn" aria-label="Focus mode"><i class="fas fa-expand" aria-hidden="true"></i><span>Focus mode</span></button>
        </div>
        <div class="rb-menu-group">
            <button type="button" class="sidebar-btn" id="myNotesBtn" aria-label="My notes and highlights"><i class="fas fa-pen" aria-hidden="true"></i><span>My notes &amp; highlights</span></button>
            <button type="button" class="sidebar-btn" id="notesBtn" aria-label="Group notes"><i class="fas fa-user-group" aria-hidden="true"></i><span>Group notes</span></button>
            <button type="button" class="sidebar-btn" id="commentsBtn" aria-label="Comments"><i class="far fa-comments" aria-hidden="true"></i><span>Comments</span></button>
            <button type="button" class="sidebar-btn" id="exportHighlightsBtn" aria-label="Export highlights"><i class="fas fa-file-export" aria-hidden="true"></i><span>Export highlights</span></button>
        </div>
        <div class="rb-menu-group">
            <button type="button" class="sidebar-btn" id="ttsBtn" aria-label="Read aloud"><i class="fas fa-volume-high" aria-hidden="true"></i><span>Read aloud</span></button>
            <button type="button" class="sidebar-btn" id="shareBtn" aria-label="Share"><i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i><span>Share</span></button>
            <button type="button" class="sidebar-btn" id="prayerBtn" aria-label="Prayer request"><i class="fas fa-hands-praying" aria-hidden="true"></i><span>Prayer request</span></button>
            <button type="button" class="sidebar-btn" id="errorReportBtn" aria-label="Report a mistake"><i class="far fa-flag" aria-hidden="true"></i><span>Report a mistake</span></button>
        </div>
        <div class="rb-menu-group">
<?php if (isAdmin()): // the reading report is an admin page; members were sent to a page they could not open ?>
            <button type="button" class="sidebar-btn" id="analyticsBtn" aria-label="Reading report (admin)"><i class="fas fa-chart-simple" aria-hidden="true"></i><span>Reading report (admin)</span></button>
<?php endif; ?>
            <button type="button" class="sidebar-btn rb-danger" id="resetProgressBtn" aria-label="Start this book again"><i class="fas fa-rotate-left" aria-hidden="true"></i><span>Start this book again</span></button>
        </div>
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
                <button class="aw-nav-btn" id="flipPrevBtn" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            </div>
            <div class="flip-nav-btn-wrapper" id="flipNextBtnWrapper">
                <button class="aw-nav-btn" id="flipNextBtn" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>

    <!-- Bottom bar: drag to move through the book. -->
    <div id="rbFoot">
        <div class="rb-foot-inner">
            <input type="range" id="rbScrub" min="1" max="<?php echo max(1, (int)$total_pages); ?>" value="1" step="1" aria-label="Page" aria-valuetext="">
            <div class="rb-foot-meta">
                <span id="chapterInfo"></span>
                <span class="rb-pages">Page <span id="pageNum">1</span> of <span id="totalPages"><?php echo $total_pages; ?></span></span>
                <span id="remainingInfo"></span>
            </div>
        </div>
        <div class="progress-ring" aria-hidden="true"><svg viewBox="0 0 36 36"><circle class="fill" id="progressFill" cx="18" cy="18" r="16" stroke-dasharray="100.53" stroke-dashoffset="100.53"/></svg><span id="progressPercent">0%</span></div>
    </div>

    <!-- Themes & Settings (the Aa button) -->
    <div id="settings-panel" role="dialog" aria-labelledby="rsTitle" tabindex="-1">
        <div class="rs-title"><span id="rsTitle">Themes &amp; Settings</span><button type="button" class="rs-close" onclick="closeAll()" aria-label="Close">&times;</button></div>
        <div class="rs-size">
            <button type="button" class="rs-small" onclick="adjustFontSize(-5)" aria-label="Smaller text">A</button>
            <input type="range" id="fontSizeSlider" min="70" max="160" value="100" step="5" aria-label="Text size">
            <button type="button" class="rs-big" onclick="adjustFontSize(5)" aria-label="Larger text">A</button>
        </div>
        <p class="rs-caption">Text size <span id="fontSizeLabel">100%</span></p>
        <div class="rs-label" id="rsThemes">Themes</div>
        <div class="rs-themes" id="themeGroup" role="group" aria-labelledby="rsThemes">
            <button type="button" data-theme="light" class="active"><span class="sw">Aa</span>Original</button>
            <button type="button" data-theme="paper"><span class="sw">Aa</span>Paper</button>
            <button type="button" data-theme="sepia"><span class="sw">Aa</span>Calm</button>
            <button type="button" data-theme="dark"><span class="sw">Aa</span>Night</button>
            <button type="button" data-theme="contrast" title="High contrast: black on white"><span class="sw">Aa</span>Contrast</button>
        </div>
        <div class="rs-row"><label for="fontTypeSelect">Font</label>
            <div class="rs-ctl"><select id="fontTypeSelect">
                <option value="Georgia, serif">Georgia</option>
                <option value="'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif">Palatino</option>
                <option value="'Playfair Display', Georgia, serif">Playfair Display</option>
                <option value="Inter, sans-serif">Inter</option>
                <option value="'Atkinson Hyperlegible', Verdana, sans-serif">Atkinson Hyperlegible (easy to read)</option>
            </select></div>
        </div>
        <div class="rs-row"><span>Line spacing</span>
            <div class="rs-ctl"><button type="button" onclick="adjustLineHeight(-10)" aria-label="Less line spacing">&minus;</button><input type="range" id="lineHeightSlider" min="140" max="220" value="180" step="10" aria-label="Line spacing"><button type="button" onclick="adjustLineHeight(10)" aria-label="More line spacing">+</button><span class="rs-val" id="lineHeightLabel">1.8</span></div>
        </div>
        <div class="rs-row"><span id="rsLayout">Layout</span>
            <div class="rs-seg" id="modeGroup" role="group" aria-labelledby="rsLayout"><button type="button" data-mode="scroll" class="active">Scroll</button><button type="button" data-mode="flip">Pages</button></div>
        </div>
        <div class="rs-row"><span>Reading pace</span>
            <div class="rs-ctl"><input type="range" id="readingSpeedSlider" min="100" max="500" value="<?php echo $reading_speed_wpm; ?>" step="10" aria-label="Reading pace in words per minute"><span class="rs-val" id="readingSpeedLabel"><?php echo $reading_speed_wpm; ?> wpm</span></div>
        </div>
        <p class="rs-note">Your pace is only used to estimate the time left in a chapter.</p>
    </div>

    <!-- TOC Drawer -->
    <div id="toc-drawer">
        <div class="toc-header">
            <h3>Contents</h3>
            <button class="toc-close" id="tocClose" aria-label="Close contents">&times;</button>
        </div>
        <div class="toc-book">
            <?php if (!empty($cover_path)): ?><img src="<?php echo $cover_path; ?>" alt="" onerror="this.remove()"><?php endif; ?>
            <div><strong><?php echo htmlspecialchars($book['title']); ?></strong><?php if (!empty($author)): ?><span><?php echo htmlspecialchars($author); ?></span><?php endif; ?></div>
        </div>
        <div class="toc-body" id="tocBody">
            <?php if (count($tocEntries) > 0): ?>
            <p class="toc-summary" id="tocSummary"></p>
            <div class="toc-group" id="tocGroup" hidden></div>
            <p class="toc-tools"><button type="button" id="tocPrint"><i class="fas fa-print" aria-hidden="true"></i> Print or save this chapter</button></p><!-- R14 -->
            <ul class="toc-list">
                <?php foreach ($tocEntries as $entry): ?>
                <li><a href="#" class="toc-link" data-chapter="<?php echo (int)($entry['page'] ?? 1); ?>"><span class="toc-mark" aria-hidden="true"></span><span class="toc-title"><?php echo htmlspecialchars($entry['title']); ?></span><span class="toc-page">p. <?php echo (int)($entry['page'] ?? 1); ?></span></a></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="toc-empty">No table of contents available.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Group Notes Panel -->
    <div id="notes-panel" class="modal-wrapper">
        <button class="modal-close" id="notesClose" aria-label="Close">&times;</button>
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
        <button class="modal-close" onclick="closeModal(\'share-modal\')" aria-label="Close">&times;</button>
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
        <!-- Q36: the same three reactions as the rest of the site (like / love / pray) -->
        <button onclick="reactNote(currentNoteId, '👍')" aria-label="Like" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">👍</button>
        <button onclick="reactNote(currentNoteId, '❤️')" aria-label="Love" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">❤️</button>
        <button onclick="reactNote(currentNoteId, '🙏')" aria-label="Pray" style="font-size:1.4rem;background:transparent;border:none;cursor:pointer;">🙏</button>
        <button onclick="document.getElementById('reaction-picker').style.display='none'" style="font-size:0.9rem;background:transparent;border:none;cursor:pointer;color:var(--text-light);">✕</button>
    </div>


    <!-- Comments Modal -->
    <div id="commentsModal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal(\'commentsModal\')" aria-label="Close">&times;</button>
        <h3><i class="fas fa-comments" style="color:var(--rose);"></i> Comments</h3>
        <div id="commentList" style="margin:12px 0;max-height:200px;overflow-y:auto;"></div>
        <textarea id="commentInput" rows="3" placeholder="Write a comment..." style="width:100%;padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--input-bg);color:var(--text);margin-bottom:8px;"></textarea>
        <button onclick="submitComment()" style="background:var(--rose);color:var(--white);border:none;padding:10px 24px;border-radius:30px;cursor:pointer;font-weight:600;width:100%;">Post Comment</button>
    </div>

    <!-- Error Report Modal -->
    <div id="errorModal" class="modal-wrapper">
        <button class="modal-close" onclick="closeModal(\'errorModal\')" aria-label="Close">&times;</button>
        <h3><i class="far fa-flag"></i> Report a mistake</h3>
        <div style="margin:12px 0;">
            <label for="errorPageInput" style="font-weight:600;font-size:0.9rem;">Page number <span id="errorPageNum"></span>:</label>
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
        <button class="modal-close" onclick="closeModal(\'prayerModal\')" aria-label="Close">&times;</button>
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
            <button onclick="closeSearch()" aria-label="Close search">×</button>
        </div>
        <div id="searchResults"></div>
    </div>

    <!-- Exit Focus Button -->
    <button type="button" id="exitFocusBtn" onclick="toggleFocus()"><i class="fas fa-compress" aria-hidden="true"></i> Exit focus</button>

    <!-- Overlay -->
    <!-- Selection toolbar for highlights; filled in by initSelectionTooltip() -->
    <div id="highlight-tooltip"></div>
    <div id="overlay"></div>
    <?php if (!isLoggedIn()): $aw_back = urlencode('/reader/reader.php?id=' . (int)$book_id); ?>
    <!-- Q7: guests could read but were never told that their place, highlights and notes are not saved -->
    <div id="guestNotice" role="note" style="position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:35;max-width:94vw;width:520px;background:var(--card-bg);border:1px solid var(--rose-light);border-radius:12px;box-shadow:var(--shadow-hover);padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:0.9rem;color:var(--text);">
        <span style="flex:1 1 220px;">You're reading as a guest: your place, highlights and notes won't be saved.</span>
        <a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo $aw_back; ?>" class="note-submit" style="text-decoration:none;min-height:40px;display:inline-flex;align-items:center;">Log in</a>
        <a href="<?php echo SITE_URL; ?>/register.php" style="color:var(--rose-dark);min-height:40px;display:inline-flex;align-items:center;">Create account</a>
        <button type="button" aria-label="Dismiss" onclick="this.parentNode.remove();try{sessionStorage.setItem('awGuestNotice','1')}catch(e){}" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-light);min-width:40px;min-height:40px;">&times;</button>
    </div>
    <script>try { if (sessionStorage.getItem('awGuestNotice')) document.getElementById('guestNotice').remove(); } catch (e) {}</script>
    <?php endif; ?>
</div>

<!-- ====== Include all enhanced modules ====== -->
<script src="https://unpkg.com/page-flip@2.0.7/dist/js/page-flip.browser.js"></script>
<?php
// Include TTS and sharing. (C1: the challenge module and the achievement toast are retired.)
require_once __DIR__ . '/reader_tts.php';
require_once __DIR__ . '/reader_share.php';
?>

<script>
(function() {
    const pages = <?php echo json_encode($pages); ?>;
    const totalPages = pages.length;
    const bookId = <?php echo $book_id; ?>;
    // R5: while the admin previews a draft, nothing is saved (position, session, bookmarks...).
    const userId = <?php echo (isLoggedIn() && empty($preview_version)) ? $_SESSION['user_id'] : 0; ?>;
    // Q7: guest-only actions showed a bare alert; offer to log in and come back to this book.
    function awNeedLogin(what) {
        if (confirm('Please log in to ' + what.replace(/\.$/, '') + '.\n\nGo to the login page now? You will come back to this book.')) {
            window.location.href = '<?php echo SITE_URL; ?>/login.php?redirect=' + encodeURIComponent('/reader/reader.php?id=<?php echo (int)$book_id; ?>');
        }
    }
    const groupId = <?php echo $group_id ? (int)$group_id : 0; ?>;
    // R11: the member's reading group schedule for this book (chapter + due date), shown in the contents.
    const groupPlan = <?php
        $aw_plan = null;
        if ($group_id) {
            try {
                $gs = $db->prepare("SELECT name FROM reading_groups WHERE id = ?");
                $gs->execute([$group_id]);
                $aw_plan = ['group' => (string)$gs->fetchColumn(), 'url' => SITE_URL . '/group.php?id=' . (int)$group_id, 'items' => []];
                $gs = $db->prepare("SELECT chapter_index, due_date FROM group_schedules WHERE group_id = ? ORDER BY due_date, chapter_index");
                $gs->execute([$group_id]);
                foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $ch = (int)$it['chapter_index'] + 1; // stored from 0, shown from 1 (as in group.php)
                    $aw_plan['items'][] = ['chapter' => $ch, 'title' => $chapterTitles[$ch] ?? ('Chapter ' . $ch), 'page' => $chapterMap[$ch][0] ?? null, 'due' => $it['due_date']];
                }
            } catch (PDOException $e) { $aw_plan = null; }
        }
        echo json_encode($aw_plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    const toc = <?php echo json_encode($toc); ?>;
    const lastPage = <?php echo $last_page; ?>;
    // R9: settings saved with the account are applied before the reader reads its settings, so they
    // follow the member to every device; later changes are saved back (only when they differ).
    const awServerPrefs = <?php
        $aw_prefs = null;
        if (isLoggedIn() && empty($preview_version)) {
            try {
                $ps = $db->prepare("SELECT prefs FROM reader_preferences WHERE user_id = ?");
                $ps->execute([$_SESSION['user_id']]);
                $aw_prefs = json_decode((string)$ps->fetchColumn(), true);
            } catch (PDOException $e) { $aw_prefs = null; }
        }
        echo json_encode(is_array($aw_prefs) ? $aw_prefs : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    // R10: keep this book readable offline (see /sw.js) and say when the reader is offline.
    if ('serviceWorker' in navigator && userId) { navigator.serviceWorker.register('/sw.js').catch(() => {}); }
    function awOfflineNotice() {
        let n = document.getElementById('awOffline');
        if (!navigator.onLine) {
            if (!n) { n = document.createElement('div'); n.id = 'awOffline'; n.setAttribute('role', 'status'); n.style.cssText = 'position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:100002;background:#3d2e2e;color:#fff;padding:6px 14px;border-radius:16px;font-size:0.85rem;'; document.body.appendChild(n); }
            n.textContent = 'You are offline. Reading the saved copy; your place will be saved when you reconnect.';
        } else if (n) { n.remove(); savePosition && savePosition(); }
    }
    window.addEventListener('online', awOfflineNotice); window.addEventListener('offline', awOfflineNotice);
    document.addEventListener('DOMContentLoaded', awOfflineNotice);
    const awPrefKeys = ['reader_theme', 'reader_mode', 'reader_font_size', 'reader_line_height', 'reader_font_family', 'reader_font_readable'];
    function awPrefsSnapshot() { const o = {}; awPrefKeys.forEach(k => { try { const v = localStorage.getItem(k); if (v !== null) o[k] = v; } catch (e) {} }); return o; }
    if (awServerPrefs) { try { Object.keys(awServerPrefs).forEach(k => { if (awPrefKeys.includes(k)) localStorage.setItem(k, awServerPrefs[k]); }); } catch (e) {} }
    let awPrefsSent = JSON.stringify(awServerPrefs ? awPrefsSnapshot() : null);
    let awPrefsTimer = null;
    function awPrefsChanged() {
        if (!userId) return;
        clearTimeout(awPrefsTimer);
        awPrefsTimer = setTimeout(() => {
            const now = JSON.stringify(awPrefsSnapshot());
            if (now === awPrefsSent) return;
            const fd = new FormData(); fd.append('action', 'save_prefs'); fd.append('prefs', now);
            fetch('reader_ajax.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d && d.success) awPrefsSent = now; }).catch(() => {});
        }, 800);
    }
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
    // Scroll is the default: in flip mode a mouse drag turns the page, so text can't be selected for highlighting.
    let readingMode = localStorage.getItem('reader_mode') || 'scroll';
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
        app.classList.remove('theme-paper','theme-light','theme-dark','theme-sepia','theme-contrast');
        app.classList.add('theme-'+theme);
        localStorage.setItem('reader_theme',theme); awPrefsChanged();
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

        pageFlip = new St.PageFlip(flipWrapper, {
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
            chapterInfoEl.textContent = 'Cover';
            remainingInfoEl.textContent = '';
            rbSync(0, '');
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
        chapterInfoEl.textContent = getChapterTitle(ch);
        // Calm wording, as in a printed book's running footer: no counters to beat.
        const inCh = getPagesInChapter(ch).indexOf(page) !== -1, left = getRemainingPagesInChapter(page), mins = estimateTimeRemaining(page);
        remainingInfoEl.textContent = !inCh ? '' : left > 0 ? `${left} ${left === 1 ? 'page' : 'pages'} left in chapter${mins > 0 ? ' · about ' + mins + ' min' : ''}` : 'Last page of chapter';
        rbSync(page, getChapterTitle(ch));
        markToc(page);
        // R12: tell screen-reader users where they are after a page turn (not on start-up).
        const ann = document.getElementById('awPageAnnounce');
        if (ann && awAnnounceReady && page !== awAnnounced) { awAnnounced = page; ann.textContent = `Page ${page} of ${totalPages}. ${getChapterTitle(ch)}.`; }
    }
    var awAnnounceReady = false, awAnnounced = 0; // announce a page once, when it changes
    window.addEventListener('load', () => setTimeout(() => { awAnnounceReady = true; }, 1000));

    // R11: "Reading together" – the group's next chapter, its due date and a link to discuss it.
    function awFmtDate(d) { const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
    function renderGroupPlan() {
        const box = document.getElementById('tocGroup');
        if (!box || !groupPlan) return;
        const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const today = new Date().toISOString().slice(0, 10);
        const next = groupPlan.items.find(i => i.due >= today);
        let html = '<strong>👥 ' + esc(groupPlan.group) + '</strong><br>';
        if (next) {
            html += 'Read ' + (next.page ? '<a href="#" data-goto="' + next.page + '">' + esc(next.title) + '</a>' : esc(next.title)) + ' by <strong>' + esc(awFmtDate(next.due)) + '</strong>';
        } else if (groupPlan.items.length) {
            html += 'All scheduled chapters are past their dates.';
        } else {
            html += 'Your group has no reading schedule yet.';
        }
        html += '<br><a href="' + esc(groupPlan.url) + '#tab-discussions">💬 Discuss this chapter with your group</a> · <a href="' + esc(groupPlan.url) + '#tab-schedule">📅 Schedule</a>';
        box.innerHTML = html; box.hidden = false;
        box.querySelectorAll('[data-goto]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); goToPage(parseInt(a.dataset.goto)); tocDrawer.classList.remove('open'); overlay.classList.remove('active'); }));
        groupPlan.items.forEach(i => {
            if (!i.page) return;
            const link = Array.from(document.querySelectorAll('.toc-link[data-chapter="' + i.page + '"]')).pop();
            if (link && !link.querySelector('.toc-due')) { const d = document.createElement('span'); d.className = 'toc-due'; d.textContent = 'due ' + awFmtDate(i.due); link.insertBefore(d, link.querySelector('.toc-page')); }
        });
    }
    document.addEventListener('DOMContentLoaded', renderGroupPlan);

    // R14: print (or "Save as PDF") the chapter being read, as clean pages without the reader controls.
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('tocPrint');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const ch = getChapterForPage(currentPage || 1);
            const nums = (chapterMap[ch] && chapterMap[ch].length) ? chapterMap[ch] : [currentPage || 1];
            const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const body = nums.map(n => { const el = document.querySelector('.page-content-inner[data-page="' + n + '"]'); return el ? '<section>' + el.innerHTML + '</section>' : ''; }).join('');
            const w = window.open('', '_blank');
            if (!w) { alert('Please allow pop-ups for this site to print the chapter.'); return; }
            const bookTitle = <?php echo json_encode((string)$book['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            w.document.write('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' + esc(bookTitle + ' – ' + getChapterTitle(ch)) + '</title>'
                + '<style>body{font-family:Georgia,"Times New Roman",serif;font-size:12pt;line-height:1.6;color:#000;max-width:42em;margin:2em auto;padding:0 1em}'
                + 'header{border-bottom:1px solid #a08a8a;margin-bottom:1.5em;font-size:10pt;color:#4a3a3a}section{margin-bottom:1em}'
                + 'img{max-width:100%}[class^="highlight-"],mark{background:none!important;text-decoration:underline}'
                + '@media print{body{margin:0}a{color:#000;text-decoration:none}}</style></head><body>'
                + '<header>' + esc(bookTitle) + ' · ' + esc(getChapterTitle(ch)) + ' · pages ' + nums[0] + '–' + nums[nums.length - 1] + '</header>'
                + body + '</body></html>');
            w.document.close();
            w.focus();
            setTimeout(() => w.print(), 300);
        });
    });

    // R7: mark the current chapter and the chapters already read in the contents.
    var furthestPage = 0; // var: markToc can run before this line during start-up
    function markToc(page) {
        furthestPage = Math.max(furthestPage || 0, parseInt(lastPage) || 0, page);
        const links = Array.from(document.querySelectorAll('.toc-link'));
        let current = null;
        links.forEach(l => { if (parseInt(l.dataset.chapter) <= page) current = l; });
        links.forEach((l, i) => {
            const start = parseInt(l.dataset.chapter);
            const next = links[i + 1] ? parseInt(links[i + 1].dataset.chapter) : totalPages + 1;
            l.classList.toggle('toc-current', l === current);
            l.classList.toggle('toc-done', l !== current && next - 1 <= furthestPage);
            if (l === current) l.setAttribute('aria-current', 'true'); else l.removeAttribute('aria-current');
        });
        const sum = document.getElementById('tocSummary');
        if (sum) sum.textContent = `Page ${page} of ${totalPages} · ${Math.round(page / totalPages * 100)}% read`;
    }

    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        const near = Math.abs(pageNum - currentPage) <= 2; // long jumps land at once instead of scrolling past every page
        currentPage = pageNum;
        if (readingMode === 'flip' && pageFlip) {
            pageFlip.turnToPage(pageNum);
        } else {
            const target = document.querySelector(`.page-content-inner[data-page="${pageNum}"]`);
            if (target) { rbLockScroll(); target.scrollIntoView({ behavior: near ? 'smooth' : 'auto', block:'start' }); }
            updateUI(pageNum);
            applyHighlights(scrollContainer);
        }
        savePosition();
        loadNotes();
    }

    // Q5: every page turn (and every scroll update) sent its own save_position request. The save now
    // waits until the reader has stayed on a page for 3 s; a pending save is sent when the page is
    // hidden or closed, so the position is never lost.
    let savePositionTimer = null;
    function savePosition() {
        if (userId === 0 || currentPage === 0) return;
        clearTimeout(savePositionTimer);
        savePositionTimer = setTimeout(sendPosition, 3000);
    }
    function flushPosition() {
        if (savePositionTimer) { clearTimeout(savePositionTimer); sendPosition(); }
    }
    document.addEventListener('visibilitychange', function () { if (document.hidden) flushPosition(); });
    window.addEventListener('pagehide', flushPosition);
    function sendPosition() {
        savePositionTimer = null;
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
        localStorage.setItem('reader_mode',mode); awPrefsChanged();
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
        // Arrow keys belong to the control in use (sliders, menus, text boxes), not to page turning.
        if (e.key !== 'Escape' && e.target.closest && e.target.closest('input, select, textarea, [contenteditable], .modal-wrapper, #sidebar, #settings-panel, #toc-drawer')) return;
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
    // A tap in the middle of the page shows or hides the bars. In page-turn mode a tap near the
    // left or right edge turns the page.
    document.getElementById('page-viewport').addEventListener('click',function(e) {
        if (e.target.closest('button, a, input, select, textarea, #highlight-tooltip, [class^="highlight-"], [class*=" highlight-"]')) return;
        if (String(window.getSelection ? window.getSelection() : '').trim() !== '') return; // finishing a text selection
        const rect = this.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width;
        if (readingMode === 'flip' && pageFlip && (x < 0.3 || x > 0.7)) {
            if (x > 0.5) pageFlip.turnToNextPage(); else pageFlip.turnToPrevPage();
            rbChrome(false);
            return;
        }
        rbChrome(document.getElementById('reader-app').classList.contains('rb-quiet'));
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
        if (userId === 0) { awNeedLogin('bookmark.'); return; }
        if (isBookmarked) {
            const xhr = new XMLHttpRequest(); xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData(); fd.append('action','remove_bookmark'); fd.append('book_id',bookId); fd.append('chapter',currentPage); xhr.send(fd); // F235
            isBookmarked = false; rbBookmarkShow();
        } else {
            const xhr = new XMLHttpRequest(); xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData(); fd.append('action','add_bookmark'); fd.append('book_id',bookId); fd.append('chapter',currentPage); fd.append('offset',0); xhr.send(fd);
            isBookmarked = true; rbBookmarkShow();
        }
    });

    function rbBookmarkShow() {
        const label = isBookmarked ? 'Remove bookmark' : 'Bookmark this page';
        [bookmarkBtn, document.getElementById('rbBookmarkMenu')].forEach(b => {
            if (!b) return;
            b.querySelector('i').className = (isBookmarked ? 'fas' : 'far') + ' fa-bookmark';
            b.classList.toggle('is-on', isBookmarked);
            b.setAttribute('aria-label', label); b.setAttribute('aria-pressed', String(isBookmarked));
            const t = b.querySelector('span'); if (t) t.textContent = label;
        });
    }
    function loadBookmarkStatus() {
        if (userId === 0) return;
        // Q4: this was a synchronous XMLHttpRequest, which froze the whole page (scrolling, taps) until
        // the server answered. It is now asynchronous; the icon updates when the answer arrives.
        const fd = new FormData(); fd.append('action','list_bookmarks'); fd.append('book_id',bookId);
        fetch('/reader/reader_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                let exists = false;
                data.bookmarks.forEach(b => { if (b.chapter_index == currentPage) exists = true; });
                isBookmarked = exists;
                rbBookmarkShow();
            })
            .catch(() => {});
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
            document.querySelectorAll('#themeGroup button').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
            this.classList.add('active'); this.setAttribute('aria-pressed', 'true');
            applyTheme(theme);
        });
    });

    const savedTheme = localStorage.getItem('reader_theme') || 'light';
    applyTheme(savedTheme);
    document.querySelectorAll('#themeGroup button').forEach(b => { const on = b.dataset.theme === savedTheme; b.classList.toggle('active', on); b.setAttribute('aria-pressed', String(on)); });

    // ----- Font size -----
    document.getElementById('fontSizeSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.fontSize = val+'%');
        document.getElementById('fontSizeLabel').textContent = val+'%';
        localStorage.setItem('reader_font_size',val); awPrefsChanged();
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
        localStorage.setItem('reader_line_height',val); awPrefsChanged();
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
    const savedFont = localStorage.getItem('reader_font_family') || 'Georgia, serif'; // a book face by default
    if (savedFont) { fontTypeSelect.value = savedFont; if (!fontTypeSelect.value) fontTypeSelect.selectedIndex = 0; applyFontType(savedFont); }
    fontTypeSelect.addEventListener('change',function() { const font = this.value; applyFontType(font); localStorage.setItem('reader_font_family',font); awPrefsChanged(); });
    function applyFontType(font) { document.querySelectorAll('.page-content-inner,.flip-page-custom').forEach(el => el.style.fontFamily = font); }

    // ----- Reading speed -----
    document.getElementById('readingSpeedSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.getElementById('readingSpeedLabel').textContent = val + ' wpm';
        updateUI(currentPage);
    });
    // Q5: saved once when the slider is released ('change'), not on every step of the drag ('input').
    document.getElementById('readingSpeedSlider').addEventListener('change',function() {
        if (userId > 0) {
            const data = new FormData();
            data.append('action','update_reading_speed');
            data.append('speed',parseInt(this.value));
            navigator.sendBeacon('/reader/reader_ajax.php',data);
        }
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

    // ----- The "…" menu, Aa and Contents -----
    // The old side rail is now a menu that opens from the "…" button; choosing a tool closes it.
    function rbPopover(panel, btn, cls) {
        const open = !panel.classList.contains(cls);
        closeAll();
        if (!open) return;
        panel.classList.add(cls);
        btn.setAttribute('aria-expanded', 'true');
        overlay.classList.add('active', 'rb-clear'); // a tap anywhere closes it; the page stays visible
        panel.focus({ preventScroll: true }); // Tab moves on to the first control
    }
    sidebarToggle.addEventListener('click', function () { rbPopover(sidebar, sidebarToggle, 'open'); });
    sidebar.addEventListener('click', function (e) {
        if (e.target.closest('.sidebar-btn')) { sidebar.classList.remove('open'); sidebarToggle.setAttribute('aria-expanded', 'false'); overlay.classList.remove('rb-clear'); }
    });
    document.getElementById('rbBookmarkMenu').addEventListener('click', function () { bookmarkBtn.click(); });
    settingsBtn.addEventListener('click', function () { rbPopover(settingsPanel, settingsBtn, 'open'); });
    tocBtn.addEventListener('click', function() {
        const open = !tocDrawer.classList.contains('open');
        closeAll();
        if (!open) return;
        markToc(currentPage || 1);
        tocDrawer.classList.add('open'); tocBtn.setAttribute('aria-expanded', 'true'); overlay.classList.add('active');
        const cur = document.querySelector('.toc-link.toc-current');
        if (cur) cur.scrollIntoView({ block: 'center' });
        tocClose.focus({ preventScroll: true });
    });
    tocClose.addEventListener('click', function() { closeAll(); tocBtn.focus(); });
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
    commentsBtn.addEventListener('click', function() { if (userId === 0) { awNeedLogin('view comments.'); return; } loadComments(); openModal('commentsModal'); });
    errorReportBtn.addEventListener('click', function() { if (userId === 0) { awNeedLogin('report errors.'); return; } errorPageInput.value = currentPage; if (errorPageNumSpan) errorPageNumSpan.textContent = '(you are on ' + currentPage + ')'; openModal('errorModal'); });
    prayerBtn.addEventListener('click', function() { if (userId === 0) { awNeedLogin('submit prayer requests.'); return; } prayerText.value = ''; openModal('prayerModal'); });

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
        if (userId === 0) { awNeedLogin('reset progress.'); return; }
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
                    <button class="tooltip-action" data-action="copy" aria-label="Copy" title="Copy"><i class="fas fa-copy" aria-hidden="true"></i></button>
                    <button class="tooltip-action" data-action="underline" aria-label="Highlight" title="Highlight"><i class="fas fa-underline" aria-hidden="true"></i></button>
                    <button class="tooltip-action" data-action="note" aria-label="Add note" title="Add note"><i class="fas fa-pen" aria-hidden="true"></i></button>
                    <button class="tooltip-action" data-action="share" aria-label="Share" title="Share"><i class="fas fa-share-alt" aria-hidden="true"></i></button>
                    <button class="tooltip-action" data-action="question" aria-label="Ask a question" title="Ask a question"><i class="fas fa-question-circle" aria-hidden="true"></i></button>
                    <button class="tooltip-action" data-action="react" aria-label="React" title="React"><i class="fas fa-smile" aria-hidden="true"></i></button>
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
                                n.reactions.forEach(r => { rh += `<button type="button" class="reaction" onclick="reactNote(${n.id}, '${r.reaction_type}')">${r.reaction_type} ${r.count}</button>`; });
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
        sidebar.classList.remove('open');
        [sidebarToggle, settingsBtn, tocBtn].forEach(b => b.setAttribute('aria-expanded', 'false'));
        overlay.classList.remove('rb-clear');
        closeModal('share-modal'); closeModal('commentsModal'); closeModal('errorModal'); closeModal('prayerModal');
        document.getElementById('notes-panel').classList.remove('visible');
        document.getElementById('search-bar').classList.remove('visible');
        document.getElementById('reaction-picker').style.display = 'none';
        overlay.classList.remove('active');
        if (focusMode) { toggleFocus(); }
    }


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
        if (userId === 0) { awNeedLogin('export your highlights.'); return; }
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
            results.forEach(r => { html += `<button type="button" class="search-result" onclick="goToPage(${r.page}); closeSearch();">Page ${r.page} … ${r.snippet}</button>`; });
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

    // ----- Quiet reading: bars that step aside, a page scrubber, and the page you are on -----
    var rbScrollLockUntil = 0, rbLastTop = 0, rbTicking = false, rbPagesCache = null;
    function rbLockScroll() { rbScrollLockUntil = Date.now() + 1500; }
    function rbChrome(show) {
        const app = document.getElementById('reader-app');
        if (!show && (sidebar.classList.contains('open') || settingsPanel.classList.contains('open') || tocDrawer.classList.contains('open') || searchBar.classList.contains('visible'))) return;
        if (!show && document.activeElement && document.activeElement.closest && document.activeElement.closest('#toolbar, #rbFoot')) return;
        app.classList.toggle('rb-quiet', !show);
    }
    // Called by updateUI: the footer, the scrubber and the chapter under the title.
    function rbSync(page, chapterTitle) {
        const scrub = document.getElementById('rbScrub'), sub = document.getElementById('rbChapter');
        if (sub) sub.textContent = chapterTitle || '';
        if (!scrub || scrub.dataset.dragging === '1') return;
        scrub.value = Math.max(1, page);
        const pct = totalPages > 1 ? ((Math.max(1, page) - 1) / (totalPages - 1)) * 100 : 100;
        scrub.style.setProperty('--fill', pct + '%');
        scrub.setAttribute('aria-valuetext', page ? `Page ${page} of ${totalPages}${chapterTitle ? ', ' + chapterTitle : ''}` : 'Cover');
    }
    (function () {
        const scrub = document.getElementById('rbScrub');
        scrub.addEventListener('input', function () {
            const p = parseInt(this.value, 10);
            this.dataset.dragging = '1';
            pageNumEl.textContent = p;
            chapterInfoEl.textContent = getChapterTitle(getChapterForPage(p));
            this.style.setProperty('--fill', (totalPages > 1 ? (p - 1) / (totalPages - 1) * 100 : 100) + '%');
            this.setAttribute('aria-valuetext', `Page ${p} of ${totalPages}`);
        });
        scrub.addEventListener('change', function () { this.dataset.dragging = ''; goToPage(parseInt(this.value, 10)); });

        // Scroll mode: follow the page in view, so the page number, progress and saved place keep up.
        function pageInView() {
            if (!rbPagesCache) rbPagesCache = Array.from(document.querySelectorAll('#scroll-container .page-content-inner'));
            const line = scrollContainer.getBoundingClientRect().top + scrollContainer.clientHeight * 0.35;
            let lo = 0, hi = rbPagesCache.length - 1, found = 0;
            while (lo <= hi) { // pages are in order, so a binary search finds the one under the reading line
                const mid = (lo + hi) >> 1;
                if (rbPagesCache[mid].getBoundingClientRect().top <= line) { found = mid; lo = mid + 1; } else hi = mid - 1;
            }
            return rbPagesCache[found] ? parseInt(rbPagesCache[found].dataset.page, 10) : 1;
        }
        scrollContainer.addEventListener('scroll', function () {
            const top = scrollContainer.scrollTop, delta = top - rbLastTop;
            if (Math.abs(delta) > 24) { if (Date.now() > rbScrollLockUntil && top > 40) rbChrome(false); rbLastTop = top; }
            if (top <= 4) rbChrome(true);
            if (rbTicking || readingMode !== 'scroll') return;
            rbTicking = true;
            requestAnimationFrame(function () {
                rbTicking = false;
                const p = pageInView();
                if (p && p !== currentPage) {
                    currentPage = p; updateUI(p); applyHighlights(scrollContainer); savePosition();
                    clearTimeout(rbBookmarkTimer); rbBookmarkTimer = setTimeout(loadBookmarkStatus, 1200);
                }
            });
        }, { passive: true });
        var rbBookmarkTimer = null;
        // Desktop: moving the pointer to the top or bottom edge brings the bars back.
        document.addEventListener('mousemove', function (e) {
            if (e.clientY < 64 || e.clientY > window.innerHeight - 88) rbChrome(true);
        }, { passive: true });
        document.getElementById('toolbar').addEventListener('focusin', function () { rbChrome(true); });
        document.getElementById('rbFoot').addEventListener('focusin', function () { rbChrome(true); });
        // Fonts and sizes change the page heights; the next scroll measures again.
        ['fontSizeSlider', 'lineHeightSlider', 'fontTypeSelect'].forEach(id => document.getElementById(id).addEventListener('input', function () { rbPagesCache = null; }));
        // The bars step aside a moment after the book opens; a tap brings them back.
        setTimeout(function () { rbChrome(false); }, 2500);
    })();

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
        // First visit: start in scroll mode so text can be selected and highlighted
        readingMode = 'scroll';
        localStorage.setItem('reader_mode', 'scroll');
        document.querySelector('#modeGroup [data-mode="flip"]').classList.remove('active');
        document.querySelector('#modeGroup [data-mode="scroll"]').classList.add('active');
        switchMode('scroll');
    }
    goToPage(currentPage);
    loadBookmarkStatus();
    if (userId > 0) {
        const data = new FormData();
        data.append('action','start_session');
        data.append('book_id',bookId);
        navigator.sendBeacon('/reader/reader_ajax.php',data);
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