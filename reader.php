<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

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
    $stmt = $db->prepare("SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $user_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_progress) {
        $position_offset = $user_progress['position_offset'] ?? 0;
        $position_section = $user_progress['position_section'] ?? '';
        $progress_percent = $user_progress['progress_percent'] ?? 0;
    } else {
        $stmt = $db->prepare("INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent) VALUES (?, ?, 0, '', 0)");
        $stmt->execute([$user_id, $book_id]);
    }
    
    $stmt = $db->prepare("INSERT OR REPLACE INTO reading_status (user_id, book_id, status) VALUES (?, ?, 'currently reading')");
    $stmt->execute([$user_id, $book_id]);
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
        </div>
        <div class="reader-header-center">
            <h1 class="reader-book-title"><?php echo htmlspecialchars($book['title']); ?></h1>
            <p class="reader-author">by <?php echo htmlspecialchars($book['author']); ?></p>
        </div>
        <div class="reader-header-right">
            <span class="progress-display"><?php echo $progress_percent; ?>%</span>
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
        </div>
    </div>

    <!-- ===== MAIN CONTENT AREA (SCROLLABLE) ===== -->
    <div class="reader-content-area">
        <?php if ($has_processed): ?>
            <!-- HTML READER -->
            <div class="html-reader" id="reader-text" data-book-id="<?php echo $book_id; ?>" data-initial-offset="<?php echo $position_offset; ?>" data-total-pages="<?php echo $total_pages; ?>" data-current-page="<?php echo $current_page; ?>">
                <?php for ($i = 0; $i < $total_pages; $i++): ?>
                    <div class="page-content" data-page-index="<?php echo $i; ?>" style="<?php echo ($i == $current_page) ? 'display:block;' : 'display:none;'; ?>">
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
});
</script>

<style>
/* ===== RESET & VARIABLES ===== */
.reader-app {
    display: flex;
    flex-direction: column;
    height: 100vh; /* Fallback for older browsers */
    height: 100dvh;
    background: var(--vanilla);
    color: var(--text);
    transition: all 0.3s ease;
    padding: 0;
    position: relative;
}

/* ===== READER HEADER (STICKY) ===== */
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

/* ===== CONTENT AREA (SCROLLABLE) ===== */
.reader-content-area {
    flex-grow: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0 20px 40px 20px;
    margin: 0 auto;
    width: 100%;
    max-width: 900px;
    /* THE FIX: Ensure the content area takes up remaining space */
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

/* ===== FALLBACK READER (ENSURE PARENT SCROLLS) ===== */
.fallback-reader {
    height: 100%;
    padding: 0;
    margin: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
    /* THE FIX: Allow the parent container to scroll */
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
    min-height: 100vh; /* Force a huge height so the parent will scroll */
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