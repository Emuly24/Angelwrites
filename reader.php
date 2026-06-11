<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED: Zoho SMTP email

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch book
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// Check for processed HTML content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed_content = $stmt->fetch(PDO::FETCH_ASSOC);

$has_processed = !empty($processed_content) && $processed_content['is_processed'] == 1;

// User Progress
$user_progress = null;
$position_offset = 0;
$position_section = '';
$progress_percent = 0;
$current_page_index = 0;

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];

    // ===== SAFETY CHECK: Verify the user still exists =====
    $stmtCheck = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmtCheck->execute([$user_id]);
    $userExists = $stmtCheck->fetch();

    if (!$userExists) {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    // =============================================

    $stmt = $db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $user_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("INSERT OR REPLACE INTO reading_status (user_id, book_id, status) VALUES (?, ?, 'currently reading')");
    $stmt->execute([$user_id, $book_id]);
}

// ===== HANDLE AJAX PROGRESS SAVE (from JavaScript) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_position']) && isLoggedIn()) {
    $offset = (int)$_POST['offset'];
    $section = $_POST['section'] ?? '';
    $percent = (int)$_POST['percent'];
    
    // Update progress
    $stmt = $db->prepare("UPDATE reading_progress SET position_offset = ?, position_section = ?, progress_percent = ?, last_accessed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$offset, $section, $percent, $user_id, $book_id]);
    
    // ===== EMAIL NOTIFICATION WHEN BOOK IS COMPLETED =====
    if ($percent >= 100) {
        // Check if we have already sent a completion email for this user/book
        $stmt = $db->prepare("SELECT completion_email_sent FROM reading_progress WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$user_id, $book_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $already_sent = $row['completion_email_sent'] ?? 0;
        
        if (!$already_sent) {
            // Mark as sent
            $stmt = $db->prepare("UPDATE reading_progress SET completion_email_sent = 1 WHERE user_id = ? AND book_id = ?");
            $stmt->execute([$user_id, $book_id]);
            
            // Send admin email via Zoho SMTP
            $admin_email = 'angelwrites@zohomail.com';
            $subject = 'Reader Completed Book: ' . $book['title'];
            $body = "User ID: $user_id\nBook: " . $book['title'] . "\nProgress: 100%\n\nCongratulations to the reader!";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== HANDLE BOOKMARK TOGGLE (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bookmark']) && isLoggedIn()) {
    $section = $_POST['section'] ?? '';
    $action = $_POST['action'] ?? 'add'; // 'add' or 'remove'
    
    if ($action === 'add') {
        $stmt = $db->prepare("INSERT OR IGNORE INTO bookmarks (user_id, book_id, section) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $book_id, $section]);
    } else {
        $stmt = $db->prepare("DELETE FROM bookmarks WHERE user_id = ? AND book_id = ? AND section = ?");
        $stmt->execute([$user_id, $book_id, $section]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ===== FETCH USER BOOKMARKS =====
$bookmarks = [];
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT section FROM bookmarks WHERE user_id = ? AND book_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id, $book_id]);
    $bookmarks = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Parse TOC
$toc = [];
if ($has_processed) {
    $toc = json_decode($processed_content['toc_json'], true) ?? [];
}

// === PAGE PAGINATION LOGIC FOR HTML READER ===
$pages = [];
$total_pages = 0;
$current_page = 0;

if ($has_processed) {
    $content_html = $processed_content['content_html'];
    $pages = preg_split('/<h1[^>]*>/', $content_html);
    $total_pages = count($pages);
    
    if (!empty($position_section) && strpos($position_section, 'page_') === 0) {
        $page_num = (int)str_replace('page_', '', $position_section);
        if ($page_num > 0 && $page_num <= $total_pages) {
            $current_page = $page_num - 1;
        }
    }
}

// Get user preferences
$reader_theme = $_COOKIE['reader_theme'] ?? 'paper';
$reader_font = $_COOKIE['reader_font'] ?? 'serif';
$reader_font_size = $_COOKIE['reader_font_size'] ?? 'medium';
$reading_mode = $_COOKIE['reading_mode'] ?? 'scroll';

$pageTitle = 'Reading: ' . htmlspecialchars($book['title']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="reader-app" data-theme="<?php echo $reader_theme; ?>" data-font="<?php echo $reader_font; ?>" data-font-size="<?php echo $reader_font_size; ?>" data-mode="<?php echo $reading_mode; ?>">
    
    <!-- ===== READER HEADER (STICKY) ===== -->
    <div class="reader-header">
        <div class="reader-header-left">
            <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Book
            </a>
            <!-- ADDED: Download button for free books -->
            <?php if ($book['is_free'] && !$has_processed): ?>
                <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="download-link" style="margin-left:12px; color:var(--rose);">
                    <i class="fas fa-download"></i> Download
                </a>
            <?php endif; ?>
        </div>
        <div class="reader-header-center">
            <h1 class="reader-book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
            <p class="reader-author">by <?php echo htmlspecialchars($book['author']); ?></p>
        </div>
        <div class="reader-header-right">
            <span class="progress-display"><?php echo $progress_percent; ?>%</span>
            <!-- ADDED: Bookmark button -->
            <button id="bookmark-btn" class="bookmark-btn" aria-label="Bookmark this page" data-bookmarked="<?php echo in_array('page_' . ($current_page + 1), $bookmarks) ? 'true' : 'false'; ?>">
                <i class="fas fa-bookmark"></i>
            </button>
            <button id="settings-toggle" class="settings-btn" aria-label="Settings">
                <i class="fas fa-cog"></i>
            </button>
        </div>
    </div>

    <!-- ===== SETTINGS PANEL (STICKY) ===== -->
    <div class="reader-settings" id="reader-settings">
        <div class="settings-content">
            <div class="control-group">
                <label>Theme</label>
                <div class="theme-options">
                    <button class="theme-btn" data-theme="paper" aria-label="Paper theme">
                        <span class="color-preview paper"></span> Paper
                    </button>
                    <button class="theme-btn" data-theme="light" aria-label="Light theme">
                        <span class="color-preview light"></span> Light
                    </button>
                    <button class="theme-btn" data-theme="dark" aria-label="Dark theme">
                        <span class="color-preview dark"></span> Dark
                    </button>
                    <button class="theme-btn" data-theme="sepia" aria-label="Sepia theme">
                        <span class="color-preview sepia"></span> Sepia
                    </button>
                </div>
            </div>
            <div class="control-group">
                <label>Font</label>
                <div class="font-options">
                    <button class="font-btn" data-font="serif" aria-label="Serif font">Serif</button>
                    <button class="font-btn" data-font="sans-serif" aria-label="Sans font">Sans</button>
                    <button class="font-btn" data-font="monospace" aria-label="Monospace font">Mono</button>
                </div>
            </div>
            <div class="control-group">
                <label>Font Size</label>
                <div class="size-controls">
                    <button id="decrease-size" aria-label="Decrease font size"><i class="fas fa-font" style="font-size: 0.8rem;"></i></button>
                    <span id="size-label">Medium</span>
                    <button id="increase-size" aria-label="Increase font size"><i class="fas fa-font" style="font-size: 1.2rem;"></i></button>
                </div>
            </div>
            
            <?php if ($has_processed && $total_pages > 1): ?>
            <div class="control-group">
                <label>Reading Mode</label>
                <div class="mode-options">
                    <button class="mode-btn" data-mode="scroll" aria-label="Scroll mode">
                        <i class="fas fa-arrows-alt-v"></i> Scroll
                    </button>
                    <button class="mode-btn" data-mode="page" aria-label="Page flipping mode">
                        <i class="fas fa-book-open"></i> Page Flip
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ADDED: Find in page -->
            <?php if ($has_processed): ?>
            <div class="control-group">
                <label>Find in Page</label>
                <div class="find-controls">
                    <input type="text" id="find-input" placeholder="Search within book..." aria-label="Find in page">
                    <div class="find-nav">
                        <button id="find-prev" aria-label="Previous match"><i class="fas fa-chevron-up"></i></button>
                        <span id="find-counter">0 matches</span>
                        <button id="find-next" aria-label="Next match"><i class="fas fa-chevron-down"></i></button>
                    </div>
                    <button id="find-close" aria-label="Close search"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== MAIN CONTENT AREA (SCROLLABLE) ===== -->
    <div class="reader-content-area">
        <?php if ($has_processed): ?>
            <!-- HTML READER -->
            <div class="html-reader" id="reader-text" data-book-id="<?php echo $book_id; ?>" data-initial-offset="<?php echo $position_offset; ?>" data-total-pages="<?php echo $total_pages; ?>" data-current-page="<?php echo $current_page; ?>">
                <?php for ($i = 0; $i < $total_pages; $i++): ?>
                    <div class="page-content" data-page-index="<?php echo $i; ?>" data-page-id="page_<?php echo $i + 1; ?>" style="<?php echo ($i == $current_page) ? 'display:block;' : 'display:none;'; ?>">
                        <?php echo $pages[$i]; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <!-- PAGE NAVIGATION CONTROLS (Visible only in Page Mode) -->
            <div class="page-nav-controls" id="pageNavControls" style="display:none;">
                <button id="prev-page-btn" class="nav-btn"><i class="fas fa-chevron-left"></i> Previous</button>
                <span id="page-indicator">Page 1 of <?php echo $total_pages; ?></span>
                <button id="next-page-btn" class="nav-btn">Next <i class="fas fa-chevron-right"></i></button>
            </div>

            <!-- ADDED: Bookmarked pages list -->
            <?php if (count($bookmarks) > 0): ?>
            <div class="bookmarks-list" id="bookmarksList">
                <h4>📌 Bookmarked Pages</h4>
                <ul>
                    <?php foreach ($bookmarks as $bm): ?>
                        <li><a href="#" data-section="<?php echo $bm; ?>" class="bookmark-jump"><?php echo str_replace('page_', 'Page ', $bm); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- ===== FALLBACK READER (WITH FIXED SCROLLING) ===== -->
            <div class="fallback-reader">
                <div class="fallback-scroll-wrapper">
                    <?php if ($book['file_type'] === 'pdf'): ?>
                        <div class="pdf-container">
                            <iframe src="<?php echo SITE_URL . '/' . $book['file_path']; ?>" frameborder="0" allowfullscreen></iframe>
                        </div>
                    <?php elseif ($book['file_type'] === 'epub'): ?>
                        <div class="epub-container">
                            <div id="epub-viewer"></div>
                            <div class="epub-controls">
                                <button id="epub-prev" class="nav-btn"><i class="fas fa-chevron-left"></i></button>
                                <span id="epub-current">Page 1</span>
                                <button id="epub-next" class="nav-btn"><i class="fas fa-chevron-right"></i></button>
                            </div>
                        </div>
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            if (typeof ePub !== 'undefined') {
                                const book = ePub("<?php echo SITE_URL . '/' . $book['file_path']; ?>");
                                const viewer = document.getElementById('epub-viewer');
                                const prevBtn = document.getElementById('epub-prev');
                                const nextBtn = document.getElementById('epub-next');
                                const currentLabel = document.getElementById('epub-current');
                                let rendition = book.renderTo(viewer, { width: '100%', height: '100%', spread: 'none' });
                                rendition.display();
                                rendition.on('relocated', function(location) {
                                    currentLabel.textContent = 'Page ' + (location.start.displayedPage || 1);
                                });
                                prevBtn.addEventListener('click', function() { rendition.prev(); });
                                nextBtn.addEventListener('click', function() { rendition.next(); });
                            }
                        });
                        </script>
                    <?php else: ?>
                        <div class="unsupported-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>This file format is not supported for online reading.</p>
                            <a href="<?php echo SITE_URL . '/' . $book['file_path']; ?>" download class="btn btn-primary">Download to read</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ---- Settings Toggle ----
    const settingsToggle = document.getElementById('settings-toggle');
    const settingsPanel = document.getElementById('reader-settings');
    settingsToggle.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
    });

    // ---- Theme ----
    const themeBtns = document.querySelectorAll('.theme-btn');
    const readerApp = document.querySelector('.reader-app');
    themeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const theme = this.dataset.theme;
            readerApp.dataset.theme = theme;
            document.cookie = 'reader_theme=' + theme + '; path=/; max-age=' + (365 * 24 * 60 * 60);
            themeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
        if (btn.dataset.theme === readerApp.dataset.theme) {
            btn.classList.add('active');
        }
    });

    // ---- Font ----
    const fontBtns = document.querySelectorAll('.font-btn');
    fontBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const font = this.dataset.font;
            readerApp.dataset.font = font;
            document.cookie = 'reader_font=' + font + '; path=/; max-age=' + (365 * 24 * 60 * 60);
            fontBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
        if (btn.dataset.font === readerApp.dataset.font) {
            btn.classList.add('active');
        }
    });

    // ---- Font Size ----
    const decreaseBtn = document.getElementById('decrease-size');
    const increaseBtn = document.getElementById('increase-size');
    const sizeLabel = document.getElementById('size-label');
    let currentSizeIndex = ['small', 'medium', 'large', 'xlarge'].indexOf(readerApp.dataset.fontSize);
    if (currentSizeIndex === -1) currentSizeIndex = 1;
    const sizeMap = ['small', 'medium', 'large', 'xlarge'];
    const sizeLabels = ['Small', 'Medium', 'Large', 'Extra Large'];
    function applySize(index) {
        const size = sizeMap[index];
        readerApp.dataset.fontSize = size;
        sizeLabel.textContent = sizeLabels[index];
        document.cookie = 'reader_font_size=' + size + '; path=/; max-age=' + (365 * 24 * 60 * 60);
        decreaseBtn.disabled = (index === 0);
        increaseBtn.disabled = (index === sizeMap.length - 1);
    }
    decreaseBtn.addEventListener('click', function() {
        if (currentSizeIndex > 0) {
            currentSizeIndex--;
            applySize(currentSizeIndex);
        }
    });
    increaseBtn.addEventListener('click', function() {
        if (currentSizeIndex < sizeMap.length - 1) {
            currentSizeIndex++;
            applySize(currentSizeIndex);
        }
    });
    applySize(currentSizeIndex);

    // ---- Reading Mode ----
    const readerText = document.getElementById('reader-text');
    let bookId = readerText ? readerText.dataset.bookId : null;
    const totalPages = readerText ? parseInt(readerText.dataset.totalPages) : 0;
    let currentPage = readerText ? parseInt(readerText.dataset.currentPage) || 0 : 0;
    const modeBtns = document.querySelectorAll('.mode-btn');
    const pageNavControls = document.getElementById('pageNavControls');
    const prevBtn = document.getElementById('prev-page-btn');
    const nextBtn = document.getElementById('next-page-btn');
    const pageIndicator = document.getElementById('page-indicator');

    function setMode(mode) {
        readerApp.dataset.mode = mode;
        document.cookie = 'reading_mode=' + mode + '; path=/; max-age=' + (365 * 24 * 60 * 60);
        
        if (mode === 'page' && totalPages > 1) {
            pageNavControls.style.display = 'flex';
            updatePageDisplay();
        } else {
            pageNavControls.style.display = 'none';
            document.querySelectorAll('.page-content').forEach(el => {
                el.style.display = 'block';
            });
        }
        modeBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    }

    function updatePageDisplay() {
        document.querySelectorAll('.page-content').forEach(el => {
            el.style.display = 'none';
            if (el._scrollListener) {
                el.removeEventListener('scroll', el._scrollListener);
                delete el._scrollListener;
            }
        });
        const currentPageEl = document.querySelector(`.page-content[data-page-index="${currentPage}"]`);
        if (currentPageEl) {
            currentPageEl.style.display = 'block';
            // DYNAMIC SCROLL LISTENER for the current page (Page Flip mode)
            const listener = function() {
                const scrollTop = this.scrollTop;
                const scrollHeight = this.scrollHeight;
                const clientHeight = this.clientHeight;
                const scrollFraction = scrollHeight > clientHeight ? scrollTop / (scrollHeight - clientHeight) : 0;
                const percent = Math.min(100, Math.round(((currentPage + scrollFraction) / totalPages) * 100));
                const progressDisplay = document.querySelector('.progress-display');
                if (progressDisplay) {
                    progressDisplay.textContent = percent + '%';
                }
                savePagePosition(percent);
            };
            currentPageEl.addEventListener('scroll', listener);
            currentPageEl._scrollListener = listener;
        }
        pageIndicator.textContent = `Page ${currentPage + 1} of ${totalPages}`;
        prevBtn.disabled = (currentPage === 0);
        nextBtn.disabled = (currentPage === totalPages - 1);
        savePagePosition(Math.round(((currentPage) / totalPages) * 100));
    }

    function savePagePosition(percent) {
        <?php if (isLoggedIn()): ?>
        if (!bookId) return;
        const formData = new FormData();
        formData.append('save_position', '1');
        formData.append('offset', 0);
        formData.append('section', 'page_' + (currentPage + 1));
        formData.append('percent', percent);
        fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            // Silently handle response
        });
        <?php endif; ?>
    }

    modeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            setMode(this.dataset.mode);
        });
        if (btn.dataset.mode === readerApp.dataset.mode) {
            btn.classList.add('active');
        }
    });

    setMode(readerApp.dataset.mode);

    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 0) {
                currentPage--;
                updatePageDisplay();
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (currentPage < totalPages - 1) {
                currentPage++;
                updatePageDisplay();
            }
        });
    }

    // ---- SCROLL POSITION SAVING (SCROLL MODE) ----
    let saveTimeout;
    function saveScrollPosition() {
        <?php if (isLoggedIn()): ?>
        if (!bookId) return;
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight;
        const winHeight = window.innerHeight;
        let percent = 0;
        if (docHeight > winHeight) {
            percent = Math.min(100, Math.round((scrollTop / (docHeight - winHeight)) * 100));
        }
        const progressDisplay = document.querySelector('.progress-display');
        if (progressDisplay) {
            progressDisplay.textContent = percent + '%';
        }
        const formData = new FormData();
        formData.append('save_position', '1');
        formData.append('offset', scrollTop);
        formData.append('section', '');
        formData.append('percent', percent);
        fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            // Silently handle response
        });
        <?php endif; ?>
    }

    if (readerApp.dataset.mode === 'scroll') {
        window.addEventListener('scroll', function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(saveScrollPosition, 200);
        });
        window.addEventListener('beforeunload', function() {
            saveScrollPosition();
        });
    }

    // ---- KEYBOARD SHORTCUTS ----
    document.addEventListener('keydown', function(e) {
        // Left arrow = previous page (only in page mode)
        if (e.key === 'ArrowLeft' && readerApp.dataset.mode === 'page' && totalPages > 1) {
            e.preventDefault();
            if (currentPage > 0) {
                currentPage--;
                updatePageDisplay();
            }
        }
        // Right arrow = next page (only in page mode)
        if (e.key === 'ArrowRight' && readerApp.dataset.mode === 'page' && totalPages > 1) {
            e.preventDefault();
            if (currentPage < totalPages - 1) {
                currentPage++;
                updatePageDisplay();
            }
        }
        // Ctrl+F = focus find input
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const findInput = document.getElementById('find-input');
            if (findInput) {
                findInput.focus();
                findInput.select();
            }
        }
        // Escape = close find
        if (e.key === 'Escape') {
            const findInput = document.getElementById('find-input');
            if (findInput && findInput.value) {
                findInput.value = '';
                clearHighlights();
                document.getElementById('find-counter').textContent = '0 matches';
            }
        }
    });

    // ---- BOOKMARK TOGGLE ----
    const bookmarkBtn = document.getElementById('bookmark-btn');
    if (bookmarkBtn) {
        bookmarkBtn.addEventListener('click', function() {
            if (!bookId) return;
            const isBookmarked = this.dataset.bookmarked === 'true';
            const action = isBookmarked ? 'remove' : 'add';
            const section = 'page_' + (currentPage + 1);
            
            const formData = new FormData();
            formData.append('toggle_bookmark', '1');
            formData.append('section', section);
            formData.append('action', action);
            
            fetch('<?php echo SITE_URL; ?>/reader.php?id=' + bookId, {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    this.dataset.bookmarked = isBookmarked ? 'false' : 'true';
                    // Update icon
                    const icon = this.querySelector('i');
                    if (isBookmarked) {
                        icon.className = 'far fa-bookmark';
                    } else {
                        icon.className = 'fas fa-bookmark';
                    }
                    // Reload page to refresh bookmarks list (or dynamically update)
                    location.reload();
                }
            });
        });
    }

    // ---- BOOKMARK JUMP ----
    document.querySelectorAll('.bookmark-jump').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const section = this.dataset.section;
            if (section && section.startsWith('page_')) {
                const pageNum = parseInt(section.replace('page_', ''));
                if (pageNum >= 1 && pageNum <= totalPages) {
                    currentPage = pageNum - 1;
                    updatePageDisplay();
                }
            }
        });
    });

    // ---- FIND IN PAGE ----
    const findInput = document.getElementById('find-input');
    const findPrev = document.getElementById('find-prev');
    const findNext = document.getElementById('find-next');
    const findClose = document.getElementById('find-close');
    const findCounter = document.getElementById('find-counter');
    let currentMatchIndex = 0;
    let matchCount = 0;

    function clearHighlights() {
        document.querySelectorAll('.find-highlight').forEach(el => {
            el.outerHTML = el.textContent;
        });
    }

    function performFind(query) {
        clearHighlights();
        if (!query || query.length < 2) {
            findCounter.textContent = '0 matches';
            currentMatchIndex = 0;
            matchCount = 0;
            return;
        }

        // Get the current visible page content
        let container;
        if (readerApp.dataset.mode === 'page' && totalPages > 1) {
            const pageEl = document.querySelector(`.page-content[data-page-index="${currentPage}"]`);
            container = pageEl ? pageEl : document.querySelector('.html-reader');
        } else {
            container = document.querySelector('.html-reader');
        }

        if (!container) return;

        // Find text nodes containing the query (case-insensitive)
        const regex = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
        let matches = [];
        let node;
        while (node = walker.nextNode()) {
            if (node.parentElement && node.parentElement.closest('.find-highlight')) continue;
            if (regex.test(node.textContent)) {
                matches.push(node);
            }
        }

        matchCount = matches.length;
        findCounter.textContent = matchCount + ' matches';

        // Highlight matches
        matches.forEach((textNode, index) => {
            const text = textNode.textContent;
            const span = document.createElement('span');
            span.innerHTML = text.replace(regex, '<span class="find-highlight" data-match-index="' + index + '">$1</span>');
            textNode.parentNode.replaceChild(span, textNode);
        });

        // Scroll to first match
        if (matchCount > 0) {
            const firstHighlight = container.querySelector('.find-highlight');
            if (firstHighlight) {
                firstHighlight.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstHighlight.classList.add('active-highlight');
                currentMatchIndex = 0;
            }
        }
    }

    function navigateMatch(direction) {
        const highlights = document.querySelectorAll('.find-highlight');
        if (highlights.length === 0) return;
        
        highlights.forEach(el => el.classList.remove('active-highlight'));
        if (direction === 'next') {
            currentMatchIndex = (currentMatchIndex + 1) % highlights.length;
        } else {
            currentMatchIndex = (currentMatchIndex - 1 + highlights.length) % highlights.length;
        }
        const target = highlights[currentMatchIndex];
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.add('active-highlight');
    }

    if (findInput) {
        findInput.addEventListener('input', function() {
            performFind(this.value);
        });
        findInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                navigateMatch('next');
            }
        });
    }
    if (findPrev) {
        findPrev.addEventListener('click', function() { navigateMatch('prev'); });
    }
    if (findNext) {
        findNext.addEventListener('click', function() { navigateMatch('next'); });
    }
    if (findClose) {
        findClose.addEventListener('click', function() {
            findInput.value = '';
            clearHighlights();
            findCounter.textContent = '0 matches';
        });
    }
});
</script>
<style>
/* ===== RESET & VARIABLES ===== */
.reader-app {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    background: var(--vanilla);
    color: var(--text);
    transition: all 0.3s ease;
    padding: 0;
    position: relative;
}

/* ===== READER HEADER  ===== */
.reader-header {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 2px solid var(--rose-light);
    background: var(--vanilla);
    z-index: 10;
}
.back-link { color: var(--rose); font-weight: 500; text-decoration: none; }
.reader-header-center { text-align: center; }
.reader-book-title { font-size: 1.4rem; margin: 0; color: var(--text); }
.reader-author { color: var(--text-light); margin: 4px 0 0; }
.reader-header-right { display: flex; align-items: center; gap: 16px; }
.progress-display { font-weight: 600; font-size: 0.9rem; color: var(--rose); }
.settings-btn { background: transparent; border: none; font-size: 1.2rem; color: var(--text); cursor: pointer; transition: transform 0.2s, color 0.2s; }
.settings-btn:hover { transform: rotate(45deg); color: var(--rose); }

/* ADDED: Bookmark button */
.bookmark-btn { background: transparent; border: none; font-size: 1.2rem; color: var(--text); cursor: pointer; transition: color 0.2s; }
.bookmark-btn:hover { color: var(--rose); }
.bookmark-btn[data-bookmarked="true"] { color: var(--rose); }

/* ===== SETTINGS PANEL ===== */
.reader-settings {
    flex-shrink: 0;
    display: none;
    background: var(--card-bg);
    border: 1px solid var(--rose-light);
    border-radius: 12px;
    padding: 16px 20px;
    margin: 0 20px 12px 20px;
}
.reader-settings.open { display: block; }
.settings-content { display: flex; flex-wrap: wrap; gap: 24px; }
.control-group { flex: 1; min-width: 140px; }
.control-group label { display: block; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); margin-bottom: 8px; }
.theme-options, .font-options, .mode-options { display: flex; gap: 8px; flex-wrap: wrap; }
.theme-btn, .font-btn, .mode-btn { padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px; background: transparent; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; }
.theme-btn:hover, .font-btn:hover, .mode-btn:hover { border-color: var(--rose); }
.theme-btn.active, .font-btn.active, .mode-btn.active { border-color: var(--rose); background: var(--rose); color: white; }
.color-preview { display: inline-block; width: 14px; height: 14px; border-radius: 50%; vertical-align: middle; margin-right: 4px; border: 1px solid #ddd; }
.size-controls { display: flex; align-items: center; gap: 12px; }
.size-controls button { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 32px; height: 32px; cursor: pointer; color: var(--text); transition: all 0.2s; }
.size-controls button:hover { border-color: var(--rose); color: var(--rose); }
.size-controls button:disabled { opacity: 0.5; cursor: not-allowed; }

/* ADDED: Find controls */
.find-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.find-controls input { flex: 1; min-width: 120px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; }
.find-nav { display: flex; align-items: center; gap: 4px; }
.find-nav button { background: transparent; border: 1px solid var(--border); border-radius: 4px; width: 28px; height: 28px; cursor: pointer; color: var(--text); transition: all 0.2s; }
.find-nav button:hover { border-color: var(--rose); color: var(--rose); }
#find-counter { font-size: 0.8rem; color: var(--text-light); min-width: 60px; text-align: center; }
#find-close { background: transparent; border: none; font-size: 1rem; cursor: pointer; color: var(--text-light); transition: color 0.2s; }
#find-close:hover { color: var(--rose); }
.find-highlight { background: rgba(255, 255, 0, 0.4); padding: 0 2px; }
.find-highlight.active-highlight { background: rgba(255, 200, 0, 0.7); }

/* ===== CONTENT AREA (SCROLLABLE) ===== */
.reader-content-area {
    flex-grow: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0 20px 40px 20px;
    margin: 0 auto;
    width: 100%;
    max-width: 900px;
    height: 0; 
    min-height: 0;
}

/* ===== PAGE NAVIGATION CONTROLS ===== */
.page-nav-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    padding: 16px;
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--rose-light);
    margin-top: 20px;
    flex-shrink: 0;
}
.page-nav-controls .nav-btn {
    background: var(--rose);
    color: white;
    border: none;
    border-radius: 30px;
    padding: 8px 20px;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}
.page-nav-controls .nav-btn:hover { background: var(--rose-dark); transform: scale(1.02); }
.page-nav-controls .nav-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
#page-indicator { font-weight: 600; color: var(--text); }

/* ADDED: Bookmarks list */
.bookmarks-list {
    margin-top: 20px;
    padding: 16px;
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--rose-light);
}
.bookmarks-list h4 { margin: 0 0 8px 0; font-size: 0.9rem; }
.bookmarks-list ul { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.bookmarks-list li a { display: block; padding: 4px 12px; background: var(--vanilla); border-radius: 6px; color: var(--text); text-decoration: none; font-size: 0.8rem; transition: background 0.2s; }
.bookmarks-list li a:hover { background: var(--rose); color: white; }

/* ===== THEMES ===== */
.reader-app[data-theme="paper"] { background: var(--vanilla); color: var(--text); }
.reader-app[data-theme="light"] { background: #ffffff; color: #1a1a1a; }
.reader-app[data-theme="dark"] { background: #1a1a1a; color: #f0f0f0; }
.reader-app[data-theme="sepia"] { background: #f4ecd8; color: #5b4636; }

/* ===== FONTS ===== */
.reader-app[data-font="serif"] #reader-text { font-family: Georgia, 'Times New Roman', serif; }
.reader-app[data-font="sans-serif"] #reader-text { font-family: 'Inter', -apple-system, sans-serif; }
.reader-app[data-font="monospace"] #reader-text { font-family: 'Courier New', monospace; }

/* ===== FONT SIZES ===== */
.reader-app[data-font-size="small"] #reader-text { font-size: 0.9rem; line-height: 1.7; }
.reader-app[data-font-size="medium"] #reader-text { font-size: 1.1rem; line-height: 1.8; }
.reader-app[data-font-size="large"] #reader-text { font-size: 1.4rem; line-height: 1.9; }
.reader-app[data-font-size="xlarge"] #reader-text { font-size: 1.8rem; line-height: 2.0; }

/* ===== SCROLL MODE CONTENT ===== */
.reader-app[data-mode="scroll"] .html-reader {
    height: auto;
    overflow: visible;
}
.reader-app[data-mode="scroll"] .page-content {
    display: block;
    margin-bottom: 32px;
    background: transparent;
    box-shadow: none;
    border: none;
}

/* ===== PAGE FLIP MODE CONTENT ===== */
.reader-app[data-mode="page"] .html-reader {
    height: 100%;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.reader-app[data-mode="page"] .page-content {
    display: none;
    max-height: 100%;
    overflow-y: auto;
    padding: 30px;
    background: var(--card-bg);
    border-radius: 16px;
    box-shadow: var(--shadow-hover);
    border: 1px solid var(--rose-light);
    width: 100%;
}
.reader-app[data-mode="page"] .page-content:first-child { display: block; }
.reader-app[data-mode="page"] .page-content h1 { font-size: 1.8rem; margin-top: 0; }
.reader-app[data-mode="page"] .page-content p { margin-bottom: 16px; line-height: 1.8; }

/* ===== FALLBACK READER ===== */
.fallback-reader {
    height: 100%;
    padding: 0;
    margin: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
    display: flex;
    flex-direction: column;
}

.fallback-scroll-wrapper {
    flex-grow: 1;
    height: 100%;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.pdf-container {
    height: auto;
    width: 100%;
}
.pdf-container iframe {
    display: block;
    width: 100%;
    min-height: 100vh;
    border: 0;
    height: auto;
}

.epub-container {
    height: 100%;
    display: flex;
    flex-direction: column;
}
.epub-container #epub-viewer {
    flex-grow: 1;
    height: 100%;
}
.epub-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 12px;
    background: var(--vanilla);
    border-radius: 30px;
    flex-shrink: 0;
}
.epub-controls .nav-btn {
    background: var(--rose);
    color: white;
    border: none;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}
.epub-controls .nav-btn:hover {
    background: var(--rose-dark);
    transform: scale(1.05);
}
.epub-controls .nav-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}
#epub-current {
    font-weight: 600;
    color: var(--text);
    min-width: 80px;
    text-align: center;
}

.unsupported-message {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}
.unsupported-message i {
    font-size: 3.5rem;
    color: var(--rose);
    display: block;
    margin-bottom: 16px;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .reader-header { padding: 12px 16px; flex-wrap: wrap; gap: 8px; }
    .reader-header-left, .reader-header-right { width: auto; }
    .reader-header-center { order: 3; width: 100%; text-align: center; }
    .reader-book-title { font-size: 1.1rem; }
    .reader-settings { margin: 0 16px 12px 16px; }
    .settings-content { flex-direction: column; }
    .reader-content-area { padding: 0 16px 40px 16px; }
    .page-nav-controls { flex-wrap: wrap; }
}

</style>

<?php require_once 'includes/footer.php'; ?>