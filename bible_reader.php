<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== FETCH BIBLE DATA (if needed) =====
$pageTitle = 'Bible Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="bible-reader-page" id="bibleReader">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>📖 Bible Reader</h1>
            <p>Read, copy, compare, highlight, take notes, and track your reading journey.</p>
        </div>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- ===== READER CONTAINER ===== -->
        <div class="bible-reader-container" id="bibleReaderContainer">

            <!-- ===== HEADER ===== -->
            <header class="bible-reader-header" id="bibleReaderHeader">
                <div class="bible-reader-header-left">
                    <a href="<?php echo SITE_URL; ?>/index.php" class="bible-reader-back">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <h1 class="bible-reader-title">📖 Bible Reader</h1>
                </div>
                <div class="bible-reader-header-right">
                    <!-- Progress Ring -->
                    <svg class="bible-progress-ring" width="36" height="36" viewBox="0 0 36 36">
                        <circle class="bible-progress-ring-bg" cx="18" cy="18" r="16" stroke="var(--border)" stroke-width="2" fill="none"/>
                        <circle class="bible-progress-ring-fill" cx="18" cy="18" r="16" stroke="var(--rose)" stroke-width="2" fill="none"
                                stroke-dasharray="100" stroke-dashoffset="100"
                                transform="rotate(-90 18 18)" id="bibleProgressRing"/>
                        <text x="18" y="21" text-anchor="middle" font-size="8" fill="var(--text)" id="bibleProgressText">0%</text>
                    </svg>
                    <!-- Bookmarks -->
                    <button class="bible-reader-bookmark-btn" id="bibleBookmarkBtn" aria-label="Bookmark this verse">
                        <i class="far fa-bookmark"></i>
                    </button>
                    <!-- Settings -->
                    <button class="bible-reader-settings-btn" id="bibleSettingsToggle" aria-label="Settings">
                        <i class="fas fa-cog"></i>
                    </button>
                    <!-- TOC -->
                    <button class="bible-reader-toc-btn" id="bibleTocToggle" aria-label="Table of Contents">
                        <i class="fas fa-list-ul"></i>
                    </button>
                    <!-- Focus mode -->
                    <button class="bible-reader-focus-btn" id="bibleFocusToggle" aria-label="Focus mode">
                        <i class="fas fa-expand"></i>
                    </button>
                    <!-- Share -->
                    <button class="bible-reader-share-btn" id="bibleShareBtn" aria-label="Share">
                        <i class="fas fa-share-alt"></i>
                    </button>
                    <!-- Notes -->
                    <button class="bible-reader-notes-btn" id="bibleNotesToggle" aria-label="Notes">
                        <i class="fas fa-sticky-note"></i>
                    </button>
                </div>
            </header>

            <!-- ===== SETTINGS PANEL ===== -->
            <div class="bible-reader-settings" id="bibleSettingsPanel">
                <div class="bible-settings-grid">
                    <!-- Theme -->
                    <div class="bible-setting-group">
                        <label>Theme</label>
                        <div class="bible-theme-options">
                            <button class="bible-theme-btn" data-theme="paper"><span class="color-preview paper"></span> Paper</button>
                            <button class="bible-theme-btn active" data-theme="light"><span class="color-preview light"></span> Light</button>
                            <button class="bible-theme-btn" data-theme="dark"><span class="color-preview dark"></span> Dark</button>
                            <button class="bible-theme-btn" data-theme="sepia"><span class="color-preview sepia"></span> Sepia</button>
                        </div>
                        <div class="bible-theme-extra">
                            <label><input type="checkbox" id="bibleAutoTheme"> Auto‑theme (time of day)</label>
                        </div>
                    </div>
                    <!-- Font -->
                    <div class="bible-setting-group">
                        <label>Font</label>
                        <div class="bible-font-options">
                            <button class="bible-font-btn active" data-font="serif">Serif</button>
                            <button class="bible-font-btn" data-font="sans">Sans</button>
                            <button class="bible-font-btn" data-font="mono">Mono</button>
                        </div>
                    </div>
                    <!-- Font Size -->
                    <div class="bible-setting-group">
                        <label>Font Size</label>
                        <div class="bible-size-controls">
                            <button class="bible-size-btn" id="bibleDecreaseSize"><i class="fas fa-font" style="font-size:0.8rem;"></i></button>
                            <input type="range" id="bibleSizeSlider" min="80" max="160" value="100" step="5">
                            <button class="bible-size-btn" id="bibleIncreaseSize"><i class="fas fa-font" style="font-size:1.2rem;"></i></button>
                            <span id="bibleSizeLabel">100%</span>
                        </div>
                    </div>
                    <!-- Line Height -->
                    <div class="bible-setting-group">
                        <label>Line Height</label>
                        <div class="bible-size-controls">
                            <button class="bible-size-btn" id="bibleDecreaseLine"><i class="fas fa-arrows-alt-v" style="font-size:0.8rem;"></i></button>
                            <input type="range" id="bibleLineSlider" min="140" max="220" value="180" step="10">
                            <button class="bible-size-btn" id="bibleIncreaseLine"><i class="fas fa-arrows-alt-v" style="font-size:1.2rem;"></i></button>
                            <span id="bibleLineLabel">1.8</span>
                        </div>
                    </div>
                    <!-- Letter Spacing -->
                    <div class="bible-setting-group">
                        <label>Letter Spacing</label>
                        <div class="bible-size-controls">
                            <button class="bible-size-btn" id="bibleDecreaseSpacing"><i class="fas fa-text-width" style="font-size:0.8rem;"></i></button>
                            <input type="range" id="bibleSpacingSlider" min="-2" max="4" value="0" step="1">
                            <button class="bible-size-btn" id="bibleIncreaseSpacing"><i class="fas fa-text-width" style="font-size:1.2rem;"></i></button>
                            <span id="bibleSpacingLabel">0</span>
                        </div>
                    </div>
                    <!-- Reading Mode -->
                    <div class="bible-setting-group">
                        <label>Reading Mode</label>
                        <div class="bible-mode-options">
                            <button class="bible-mode-btn active" data-mode="scroll">Scroll</button>
                            <button class="bible-mode-btn" data-mode="flip">Page Flip</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== TOC DRAWER ===== -->
            <div class="bible-toc-drawer" id="bibleTocDrawer">
                <div class="bible-toc-header">
                    <h3>Books of the Bible</h3>
                    <button class="bible-toc-close" id="bibleTocClose">&times;</button>
                </div>
                <div class="bible-toc-body" id="bibleTocBody">
                    <ul class="bible-toc-list" id="bibleBookList"></ul>
                </div>
            </div>

            <!-- ===== NOTES PANEL ===== -->
            <div class="bible-notes-panel" id="bibleNotesPanel">
                <div class="bible-notes-header">
                    <div class="bible-notes-title">
                        <h3>📝 Notes</h3>
                        <span class="badge" id="bibleNoteBadge">0</span>
                    </div>
                    <div class="bible-notes-actions">
                        <button class="btn btn-sm btn-primary" id="bibleAddNoteBtn">+ Add Note</button>
                        <button class="btn btn-sm btn-outline" id="bibleNotesClose"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="bible-notes-body" id="bibleNotesBody">
                    <div class="notes-list" id="bibleNotesList">
                        <p class="empty-notes">No notes for this book.</p>
                    </div>
                    <div id="bibleAddNoteForm" style="display:none; padding:12px; border-top:1px solid var(--border);">
                        <form id="bibleNoteForm">
                            <div class="form-group">
                                <textarea id="bibleNoteText" rows="2" placeholder="Add a note about this verse..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="bibleNotePrivate"> Make this note private
                                </label>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button type="submit" class="btn btn-sm btn-primary">Post Note</button>
                                <button type="button" class="btn btn-sm btn-secondary" id="bibleNoteCancel">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ===== REACTION PICKER ===== -->
            <div id="bibleReactionPicker" style="display:none; position:fixed; background:var(--card-bg); border:1px solid var(--border); border-radius:8px; padding:6px 10px; box-shadow:var(--shadow-hover); z-index:50;">
                <button class="reaction-option" data-reaction="👍">👍</button>
                <button class="reaction-option" data-reaction="❤️">❤️</button>
                <button class="reaction-option" data-reaction="🙏">🙏</button>
                <button class="reaction-option" data-reaction="🤔">🤔</button>
                <button class="reaction-option" data-reaction="📖">📖</button>
            </div>

            <!-- ===== CHALLENGE WIDGET ===== -->
            <div id="bibleChallengeWidget" class="bible-challenge-widget-container" style="display:none;"></div>

            <!-- ===== CONTROLS ===== -->
            <div class="bible-controls">
                <div class="bible-control-group">
                    <label for="bibleVersion1">Version 1</label>
                    <select id="bibleVersion1">
                        <option value="KJV">King James Version (KJV)</option>
                        <option value="NIV">New International Version (NIV)</option>
                        <option value="ESV">English Standard Version (ESV)</option>
                        <option value="NASB">New American Standard Bible (NASB)</option>
                        <option value="NKJV">New King James Version (NKJV)</option>
                        <option value="AMP">Amplified Bible (AMP)</option>
                        <option value="ASV">American Standard Version (ASV)</option>
                        <option value="WEB">World English Bible (WEB)</option>
                        <option value="YLT">Young's Literal Translation (YLT)</option>
                    </select>
                </div>

                <div class="bible-control-group toggle-group">
                    <label for="bibleParallelMode">
                        <input type="checkbox" id="bibleParallelMode">
                        <span>🔀 Parallel</span>
                    </label>
                </div>

                <div class="bible-control-group" id="bibleVersion2Group" style="display:none;">
                    <label for="bibleVersion2">Version 2</label>
                    <select id="bibleVersion2">
                        <option value="NIV">New International Version (NIV)</option>
                        <option value="KJV">King James Version (KJV)</option>
                        <option value="ESV">English Standard Version (ESV)</option>
                        <option value="NASB">New American Standard Bible (NASB)</option>
                        <option value="NKJV">New King James Version (NKJV)</option>
                        <option value="AMP">Amplified Bible (AMP)</option>
                        <option value="ASV">American Standard Version (ASV)</option>
                        <option value="WEB">World English Bible (WEB)</option>
                        <option value="YLT">Young's Literal Translation (YLT)</option>
                    </select>
                </div>

                <div class="bible-control-group">
                    <label for="bibleBookSelect">Book</label>
                    <select id="bibleBookSelect"></select>
                </div>
                <div class="bible-control-group">
                    <label for="bibleChapterSelect">Chapter</label>
                    <select id="bibleChapterSelect"></select>
                </div>
                <div class="bible-control-group">
                    <label for="bibleVerseSelect">Verse</label>
                    <select id="bibleVerseSelect"></select>
                </div>

                <div class="bible-control-group action-group">
                    <button id="biblePrevChapterBtn" class="btn btn-secondary btn-sm">◀ Prev</button>
                    <button id="bibleNextChapterBtn" class="btn btn-secondary btn-sm">Next ▶</button>
                </div>
                <div class="bible-control-group action-group">
                    <button id="bibleGoToBtn" class="btn btn-primary btn-sm">Go</button>
                    <input type="text" id="bibleGoToInput" placeholder="e.g. John 3:16" value="John 3:16">
                </div>
                <div class="bible-control-group action-group">
                    <button id="bibleCopyBtn" class="btn btn-secondary btn-sm">📋 Copy</button>
                    <button id="bibleHighlightBtn" class="btn btn-secondary btn-sm">🖌️ Highlight</button>
                    <button id="bibleNotesBtn" class="btn btn-secondary btn-sm">📝 Notes</button>
                </div>
            </div>

            <!-- ===== READER DISPLAY ===== -->
            <div class="bible-display">
                <div id="bibleSingleView" class="verse-container">
                    <div class="verse-content" id="bibleVerseContent1"></div>
                </div>

                <div id="bibleParallelView" class="verse-container parallel" style="display:none;">
                    <div class="verse-column">
                        <h3 id="bibleParallelTitle1">KJV</h3>
                        <div class="verse-content" id="bibleVerseContent1p"></div>
                    </div>
                    <div class="verse-column">
                        <h3 id="bibleParallelTitle2">NIV</h3>
                        <div class="verse-content" id="bibleVerseContent2p"></div>
                    </div>
                </div>

                <div class="bible-chapter-nav-bottom">
                    <button id="biblePrevChapterBtn2" class="btn btn-secondary btn-sm">◀ Prev Chapter</button>
                    <span id="bibleChapterDisplay"></span>
                    <button id="bibleNextChapterBtn2" class="btn btn-secondary btn-sm">Next Chapter ▶</button>
                </div>
            </div>

            <!-- ===== HIGHLIGHT TOOLTIP ===== -->
            <div class="bible-highlight-tooltip" id="bibleHighlightTooltip">
                <button class="bible-highlight-color" data-color="yellow"></button>
                <button class="bible-highlight-color" data-color="green"></button>
                <button class="bible-highlight-color" data-color="blue"></button>
                <button class="bible-highlight-color" data-color="pink"></button>
                <button class="bible-highlight-btn" id="bibleHighlightAnnotate"><i class="fas fa-pen"></i></button>
            </div>

            <!-- ===== ANNOTATION POPUP ===== -->
            <div class="bible-annotation-popup" id="bibleAnnotationPopup">
                <textarea id="bibleAnnotationText" rows="3" placeholder="Add a note…"></textarea>
                <div class="bible-annotation-actions">
                    <button class="bible-annotation-save" id="bibleAnnotationSave">Save</button>
                    <button class="bible-annotation-cancel" id="bibleAnnotationCancel">Cancel</button>
                </div>
            </div>

            <!-- ===== SEARCH BAR ===== -->
            <div class="bible-search-bar" id="bibleSearchBar">
                <input type="text" id="bibleSearchInput" placeholder="Search in this chapter…">
                <button id="bibleSearchClose"><i class="fas fa-times"></i></button>
                <div id="bibleSearchResults"></div>
            </div>

            <!-- ===== SHARE MODAL ===== -->
            <div id="bibleShareModal" class="bible-share-modal">
                <div class="bible-share-modal-content">
                    <h3>Share this verse</h3>
                    <div class="bible-share-options">
                        <button class="bible-share-facebook"><i class="fab fa-facebook-f"></i> Facebook</button>
                        <button class="bible-share-twitter"><i class="fab fa-twitter"></i> Twitter/X</button>
                        <button class="bible-share-whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                        <button class="bible-share-copy"><i class="fas fa-link"></i> Copy Link</button>
                    </div>
                    <button class="bible-share-modal-close">Close</button>
                </div>
            </div>

            <!-- ===== OVERLAY ===== -->
            <div class="bible-overlay" id="bibleOverlay" onclick="closeAllBibleMenus()"></div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!--  JAVASCRIPT – FULL BIBLE READER ENGINE                        -->
<!-- ============================================================ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/epubjs/0.3.93/epub.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ================================================================
    // 1. CONFIGURATION
    // ================================================================
    const VERSION_MAP = {
        'KJV': 'bible_KJV.db',
        'NIV': 'bible_NIV.db',
        'ESV': 'bible_ESV.db',
        'NASB': 'bible_NASB.db',
        'NKJV': 'bible_NKJV.db',
        'AMP': 'bible_AMP.db',
        'ASV': 'bible_ASV.db',
        'WEB': 'bible_WEB.db',
        'YLT': 'bible_YLT.db'
    };

    const BOOKS = [
        "Genesis", "Exodus", "Leviticus", "Numbers", "Deuteronomy",
        "Joshua", "Judges", "Ruth", "1 Samuel", "2 Samuel",
        "1 Kings", "2 Kings", "1 Chronicles", "2 Chronicles",
        "Ezra", "Nehemiah", "Esther", "Job", "Psalms", "Proverbs",
        "Ecclesiastes", "Song of Solomon", "Isaiah", "Jeremiah",
        "Lamentations", "Ezekiel", "Daniel", "Hosea", "Joel", "Amos",
        "Obadiah", "Jonah", "Micah", "Nahum", "Habakkuk", "Zephaniah",
        "Haggai", "Zechariah", "Malachi",
        "Matthew", "Mark", "Luke", "John", "Acts", "Romans",
        "1 Corinthians", "2 Corinthians", "Galatians", "Ephesians",
        "Philippians", "Colossians", "1 Thessalonians", "2 Thessalonians",
        "1 Timothy", "2 Timothy", "Titus", "Philemon", "Hebrews",
        "James", "1 Peter", "2 Peter", "1 John", "2 John", "3 John",
        "Jude", "Revelation"
    ];

    const CHAPTER_COUNTS = {
        "Genesis": 50, "Exodus": 40, "Leviticus": 27, "Numbers": 36, "Deuteronomy": 34,
        "Joshua": 24, "Judges": 21, "Ruth": 4, "1 Samuel": 31, "2 Samuel": 24,
        "1 Kings": 22, "2 Kings": 25, "1 Chronicles": 29, "2 Chronicles": 36,
        "Ezra": 10, "Nehemiah": 13, "Esther": 10, "Job": 42, "Psalms": 150,
        "Proverbs": 31, "Ecclesiastes": 12, "Song of Solomon": 8,
        "Isaiah": 66, "Jeremiah": 52, "Lamentations": 5, "Ezekiel": 48,
        "Daniel": 12, "Hosea": 14, "Joel": 3, "Amos": 9, "Obadiah": 1,
        "Jonah": 4, "Micah": 7, "Nahum": 3, "Habakkuk": 3, "Zephaniah": 3,
        "Haggai": 2, "Zechariah": 14, "Malachi": 4,
        "Matthew": 28, "Mark": 16, "Luke": 24, "John": 21, "Acts": 28,
        "Romans": 16, "1 Corinthians": 16, "2 Corinthians": 13,
        "Galatians": 6, "Ephesians": 6, "Philippians": 4, "Colossians": 4,
        "1 Thessalonians": 5, "2 Thessalonians": 3,
        "1 Timothy": 6, "2 Timothy": 4, "Titus": 3, "Philemon": 1,
        "Hebrews": 13, "James": 5, "1 Peter": 5, "2 Peter": 3,
        "1 John": 5, "2 John": 1, "3 John": 1, "Jude": 1, "Revelation": 22
    };

    const VERSE_COUNTS = {
        "Psalms": 150, "Proverbs": 31, "Job": 42, "Isaiah": 66, "Jeremiah": 52,
        "Ezekiel": 48, "Genesis": 50, "Exodus": 40, "Leviticus": 27, "Numbers": 36,
        "Deuteronomy": 34, "Joshua": 24, "Judges": 21, "1 Samuel": 31, "2 Samuel": 24,
        "1 Kings": 22, "2 Kings": 25, "1 Chronicles": 29, "2 Chronicles": 36,
        "Ezra": 10, "Nehemiah": 13, "Esther": 10, "Ruth": 4, "Daniel": 12,
        "Hosea": 14, "Joel": 3, "Amos": 9, "Obadiah": 1, "Jonah": 4,
        "Micah": 7, "Nahum": 3, "Habakkuk": 3, "Zephaniah": 3, "Haggai": 2,
        "Zechariah": 14, "Malachi": 4, "Matthew": 28, "Mark": 16, "Luke": 24,
        "John": 21, "Acts": 28, "Romans": 16, "1 Corinthians": 16,
        "2 Corinthians": 13, "Galatians": 6, "Ephesians": 6, "Philippians": 4,
        "Colossians": 4, "1 Thessalonians": 5, "2 Thessalonians": 3,
        "1 Timothy": 6, "2 Timothy": 4, "Titus": 3, "Philemon": 1,
        "Hebrews": 13, "James": 5, "1 Peter": 5, "2 Peter": 3, "1 John": 5,
        "2 John": 1, "3 John": 1, "Jude": 1, "Revelation": 22,
        "Song of Solomon": 8, "Ecclesiastes": 12, "Lamentations": 5
    };

    // ================================================================
    // 2. DOM REFS
    // ================================================================
    const reader = document.getElementById('bibleReaderContainer');
    const content = document.querySelector('.bible-display');
    const textContainer = document.getElementById('bibleVerseContent1');
    const header = document.getElementById('bibleReaderHeader');
    const settingsPanel = document.getElementById('bibleSettingsPanel');
    const tocDrawer = document.getElementById('bibleTocDrawer');
    const notesPanel = document.getElementById('bibleNotesPanel');
    const notesList = document.getElementById('bibleNotesList');
    const noteBadge = document.getElementById('bibleNoteBadge');
    const addNoteBtn = document.getElementById('bibleAddNoteBtn');
    const noteForm = document.getElementById('bibleNoteForm');
    const noteText = document.getElementById('bibleNoteText');
    const notePrivate = document.getElementById('bibleNotePrivate');
    const noteCancel = document.getElementById('bibleNoteCancel');
    const notesClose = document.getElementById('bibleNotesClose');
    const overlay = document.getElementById('bibleOverlay');
    const progressRing = document.getElementById('bibleProgressRing');
    const progressText = document.getElementById('bibleProgressText');
    const bookmarkBtn = document.getElementById('bibleBookmarkBtn');
    const settingsToggle = document.getElementById('bibleSettingsToggle');
    const tocToggle = document.getElementById('bibleTocToggle');
    const tocClose = document.getElementById('bibleTocClose');
    const focusToggle = document.getElementById('bibleFocusToggle');
    const shareBtn = document.getElementById('bibleShareBtn');
    const shareModal = document.getElementById('bibleShareModal');
    const notesToggle = document.getElementById('bibleNotesToggle');
    const highlightTooltip = document.getElementById('bibleHighlightTooltip');
    const annotationPopup = document.getElementById('bibleAnnotationPopup');
    const annotationText = document.getElementById('bibleAnnotationText');
    const annotationSave = document.getElementById('bibleAnnotationSave');
    const annotationCancel = document.getElementById('bibleAnnotationCancel');
    const searchBar = document.getElementById('bibleSearchBar');
    const searchInput = document.getElementById('bibleSearchInput');
    const searchClose = document.getElementById('bibleSearchClose');
    const searchResults = document.getElementById('bibleSearchResults');
    const reactionPicker = document.getElementById('bibleReactionPicker');
    const challengeWidget = document.getElementById('bibleChallengeWidget');

    // ================================================================
    // 3. STATE
    // ================================================================
    let currentTheme = localStorage.getItem('bibleTheme') || 'light';
    let currentFont = localStorage.getItem('bibleFont') || 'serif';
    let fontSize = parseInt(localStorage.getItem('bibleFontSize')) || 100;
    let lineHeight = parseInt(localStorage.getItem('bibleLineHeight')) || 180;
    let letterSpacing = parseInt(localStorage.getItem('bibleLetterSpacing')) || 0;
    let readingMode = localStorage.getItem('bibleReadingMode') || 'scroll';
    let focusMode = false;
    let autoTheme = false;
    let scrollTimeout;
    let selectedText = '';
    let selectedRange = null;
    let isBookmarked = false;
    let currentChapter = 0;
    let currentPage = 1;
    let book = 'John';
    let chapter = 3;
    let verse = 16;
    let version1 = 'KJV';
    let version2 = 'NIV';
    let parallel = false;
    let progressPercent = 0;
    let saveTimer = null;
    let userId = <?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>;
    let currentNoteId = null;
    let currentReactionPicker = null;
    let findCount = 0;
    let findIndex = 0;

    // ================================================================
    // 4. PROGRESS UPDATE
    // ================================================================
    function updateProgress(scrollTop) {
        const scrollHeight = content.scrollHeight - content.clientHeight;
        if (scrollHeight <= 0) return;
        let percent = Math.min(100, Math.round((scrollTop / scrollHeight) * 100));
        progressPercent = percent;
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
                formData.append('book', book);
                formData.append('chapter', chapter);
                formData.append('verse', verse);
                formData.append('percent', percent);
                fetch('/bible_reader_ajax.php', {
                    method: 'POST',
                    body: formData
                }).catch(() => {});
            }, 2000);
        }
    }

    // ================================================================
    // 5. SCROLL EVENT
    // ================================================================
    content.addEventListener('scroll', function() {
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

    // ================================================================
    // 6. THEME ENGINE
    // ================================================================
    function applyTheme(theme) {
        currentTheme = theme;
        reader.setAttribute('data-theme', theme);
        localStorage.setItem('bibleTheme', theme);
        document.querySelectorAll('.bible-theme-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.bible-theme-btn[data-theme="${theme}"]`)?.classList.add('active');
    }

    function getAutoTheme() {
        const hour = new Date().getHours();
        if (hour >= 6 && hour < 12) return 'sepia';
        if (hour >= 12 && hour < 18) return 'paper';
        if (hour >= 18 && hour < 22) return 'dark';
        return 'dark';
    }

    document.querySelectorAll('.bible-theme-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const theme = this.dataset.theme;
            if (autoTheme) {
                document.getElementById('bibleAutoTheme').checked = false;
                autoTheme = false;
            }
            applyTheme(theme);
        });
    });

    document.getElementById('bibleAutoTheme').addEventListener('change', function() {
        autoTheme = this.checked;
        if (autoTheme) {
            applyTheme(getAutoTheme());
            setInterval(() => { if (autoTheme) applyTheme(getAutoTheme()); }, 3600000);
        }
    });

    applyTheme(currentTheme);

    // ================================================================
    // 7. FONT ENGINE
    // ================================================================
    function applyFont(font) {
        currentFont = font;
        reader.setAttribute('data-font', font);
        localStorage.setItem('bibleFont', font);
        document.querySelectorAll('.bible-font-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.bible-font-btn[data-font="${font}"]`)?.classList.add('active');
    }

    document.querySelectorAll('.bible-font-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            applyFont(this.dataset.font);
        });
    });
    applyFont(currentFont);

    // ================================================================
    // 8. FONT SIZE ENGINE
    // ================================================================
    function applySize(size) {
        fontSize = Math.min(160, Math.max(80, size));
        textContainer.style.fontSize = fontSize + '%';
        document.getElementById('bibleSizeLabel').textContent = fontSize + '%';
        document.getElementById('bibleSizeSlider').value = fontSize;
        localStorage.setItem('bibleFontSize', fontSize);
    }

    document.getElementById('bibleSizeSlider').addEventListener('input', function() {
        applySize(parseInt(this.value));
    });
    document.getElementById('bibleDecreaseSize').addEventListener('click', () => applySize(fontSize - 5));
    document.getElementById('bibleIncreaseSize').addEventListener('click', () => applySize(fontSize + 5));
    applySize(fontSize);

    // ================================================================
    // 9. LINE HEIGHT ENGINE
    // ================================================================
    function applyLine(height) {
        lineHeight = Math.min(220, Math.max(140, height));
        textContainer.style.lineHeight = lineHeight / 100;
        document.getElementById('bibleLineLabel').textContent = (lineHeight / 100).toFixed(1);
        document.getElementById('bibleLineSlider').value = lineHeight;
        localStorage.setItem('bibleLineHeight', lineHeight);
    }

    document.getElementById('bibleLineSlider').addEventListener('input', function() {
        applyLine(parseInt(this.value));
    });
    document.getElementById('bibleDecreaseLine').addEventListener('click', () => applyLine(lineHeight - 10));
    document.getElementById('bibleIncreaseLine').addEventListener('click', () => applyLine(lineHeight + 10));
    applyLine(lineHeight);

    // ================================================================
    // 10. LETTER SPACING ENGINE
    // ================================================================
    function applySpacing(spacing) {
        letterSpacing = Math.min(4, Math.max(-2, spacing));
        textContainer.style.letterSpacing = letterSpacing + 'px';
        document.getElementById('bibleSpacingLabel').textContent = letterSpacing;
        document.getElementById('bibleSpacingSlider').value = letterSpacing;
        localStorage.setItem('bibleLetterSpacing', letterSpacing);
    }

    document.getElementById('bibleSpacingSlider').addEventListener('input', function() {
        applySpacing(parseInt(this.value));
    });
    document.getElementById('bibleDecreaseSpacing').addEventListener('click', () => applySpacing(letterSpacing - 1));
    document.getElementById('bibleIncreaseSpacing').addEventListener('click', () => applySpacing(letterSpacing + 1));
    applySpacing(letterSpacing);

    // ================================================================
    // 11. READING MODE ENGINE
    // ================================================================
    document.querySelectorAll('.bible-mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            readingMode = this.dataset.mode;
            reader.setAttribute('data-mode', readingMode);
            localStorage.setItem('bibleReadingMode', readingMode);
            document.querySelectorAll('.bible-mode-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    document.querySelector(`.bible-mode-btn[data-mode="${readingMode}"]`)?.classList.add('active');
    reader.setAttribute('data-mode', readingMode);

    // ================================================================
    // 12. SETTINGS PANEL
    // ================================================================
    settingsToggle.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        if (settingsPanel.classList.contains('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    });

    // ================================================================
    // 13. TOC DRAWER
    // ================================================================
    tocToggle.addEventListener('click', function() {
        tocDrawer.classList.toggle('open');
        if (tocDrawer.classList.contains('open')) {
            overlay.classList.add SETTINGS PANEL
    // ================================================================
    settingsToggle.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        if (settingsPanel.classList.contains('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    });

    // ================================================================
    // 13. TOC DRAWER
    // ================================================================
    tocToggle.addEventListener('click', function() {
        tocDrawer.classList.toggle('open');
        if (tocDrawer.classList.contains('open')) {
            overlay.classList.add('active');
        }('active');
        } else {
            overlay.classList.remove('active');
        }
    });
    tocClose.addEventListener('click', function() {
        tocDrawer.classList.remove('open');
        overlay.classList.remove('active');
    });

    // ================================================================
    // 14. FOCUS MODE
    // ================================================================
    focusToggle.addEventListener('click', function else {
            overlay.classList.remove('active');
        }
    });
    tocClose.addEventListener('click', function() {
        tocDrawer.classList.remove('open');
        overlay.classList.remove('active');
    });

    // ================================================================
    // 14. FOCUS MODE
    // ================================================================
    focusToggle.addEventListener('click', function() {
        focusMode = !focusMode;
        reader.classList.toggle('focus-mode', focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            header.classList.add('hidden');
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
            searchBar.classList.remove('visible');
() {
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

    // ================================================================
    // 15. OVERLAY
    // ================================= } else {
            header.classList.remove('hidden');
        }
    });

    // ================================================================
    // 15. OVERLAY
    // ================================================================
    overlay.addEventListener('click', function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
    });

    // ================================================================
    // 16. CLOSE ALL
    // ================================================================
    window.closeAllBibleMenus = function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove===============================
    overlay.addEventListener('click', function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
    });

    // ================================================================
    // 16. CLOSE ALL
    // ================================================================
    window.closeAllBibleMenus = function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
        highlightTooltip.classList.remove('visible');
        annotationPopup.classList.remove('visible');
        searchBar.classList.remove('visible');
    };

    // ================================================================
    // 17. BOOKMARKS
    // ================================================================
    bookmarkBtn.addEventListener('click', function()('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
        highlightTooltip.classList.remove('visible');
        annotationPopup.classList.remove('visible');
        searchBar.classList.remove('visible');
    };

    // ================================================================
    // 17. BOOKMARKS
    // ================================================================
    bookmarkBtn.addEventListener('click', function() {
        if (!isBookmarked) {
            const formData = new FormData();
            formData.append('action', 'add_bookmark');
            formData.append('book', book);
            formData.append('chapter', chapter);
            formData.append('verse', verse);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    isBookmarked = true;
                    bookmarkBtn {
        if (!isBookmarked) {
            const formData = new FormData();
            formData.append('action', 'add_bookmark');
            formData.append('book', book);
            formData.append('chapter', chapter);
            formData.append('verse', verse);
            fetch('/bible_reader_ajax.php', {
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
           .querySelector('i').className = 'fas fa-bookmark';
                    bookmarkBtn.classList.add('active');
                }
            });
        } else {
            const formData = new FormData();
            formData.append('action', 'remove_bookmark');
            formData.append('book', book);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    formData.append('book', book);
            fetch('/bible_reader_ajax.php', {
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

    // ================================================================
    // 18. HIGHLIGHTS
    // ================================================================
    function getSelectedText() {
        const sel = window.getSelection();
        return sel.toString().trim isBookmarked = false;
                    bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
                    bookmarkBtn.classList.remove('active');
                }
            });
        }
    });

    // ================================================================
    // 18. HIGHLIGHTS
    // ================================================================
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
        if (!selectedText();
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
        highlightTooltip.style.top = ( || !selectedRange) {
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

    document.querySelectorAll('.bible-highlight-color').forEach(btn => {
        btn.addEventListener('click', functionrect.top - 50) + 'px';
        highlightTooltip.style.left = (rect.left + rect.width / 2 - 60) + 'px';
        highlightTooltip.classList.add('visible');
    }

    document.addEventListener('mouseup', showHighlightTooltip);
    document.addEventListener('touchend', function() {
        setTimeout(showHighlightTooltip, 300);
    });

    document.querySelectorAll('.bible-highlight-color').forEach(btn => {
        btn.addEventListener('click', function() {
            const color = this.dataset.color;
            if (selectedText && selectedRange) {
                const formData = new FormData();
                formData.append('action', 'add_highlight');
                formData.append('book', book);
                formData.append() {
            const color = this.dataset.color;
            if (selectedText && selectedRange) {
                const formData = new FormData();
                formData.append('action', 'add_highlight');
                formData.append('book', book);
                formData.append('chapter', chapter);
                formData.append('text', selectedText);
                formData.append('color', color);
                fetch('/bible_reader_ajax.php', {
                    method: 'POST',
                    body:('chapter', chapter);
                formData.append('text', selectedText);
                formData.append('color', color);
                fetch('/bible_reader_ajax.php', {
                    method: 'POST',
                    body: formData
                }).then(() => {
                    const span = formData
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

    // document.createElement('span');
                    span.className = 'highlight-' + color;
                    span.textContent = selectedText;
                    selectedRange.deleteContents();
                    selectedRange.insertNode(span);
                    highlightTooltip.classList.remove('visible');
                });
            }
        });
    });

    // ================================================================
    // 19. ANNOTATIONS
    // ================================================================
    document.getElementById('bibleHighlightAnnotate').addEventListener('click', function() {
        if (selectedText && selectedRange) {
            annotationPopup.classList.add('visible');
            annotationText.value = '';
            annotationText.focus();
            ================================================================
    // 19. ANNOTATIONS
    // ================================================================
    document.getElementById('bibleHighlightAnnotate').addEventListener('click', function() {
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
            formData.append('book', book);
            formData.append('chapter', chapter);
            formData.append('text highlightTooltip.classList.remove('visible');
        }
    });

    annotationSave.addEventListener('click', function() {
        const note = annotationText.value.trim();
        if (note && selectedText && selectedRange) {
            const formData = new FormData();
            formData.append('action', 'add_highlight');
            formData.append('book', book);
            formData.append('chapter', chapter);
            formData.append('text', selectedText);
            formData.append('color', 'yellow');
            formData.append('note', note);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then', selectedText);
            formData.append('color', 'yellow');
            formData.append('note', note);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                const span = document.createElement('span');
                span.className = 'highlight-yellow annotation';
                span.textContent = selectedText;
                selectedRange.deleteContents();
               (() => {
                const span = document.createElement('span');
                span.className = 'highlight-yellow annotation';
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
        if (!e.target.closest('.bible-highlight-tooltip') && !e.target.closest('.bible-annotation-popup selectedRange.insertNode(span);
                annotationPopup.classList.remove('visible');
            });
        }
    });

    annotationCancel.addEventListener('click', function() {
        annotationPopup.classList.remove('visible');
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.bible-highlight-tooltip') && !e.target.closest('.bible-annotation-popup')) {
            highlightTooltip.classList.remove('visible');
            annotationPopup.classList.remove('visible');
        }
    });

    // ================================================================
    // 20. SEARCH
    // ================================================================
    searchInput.addEventListener('input', function() {
        const query = this')) {
            highlightTooltip.classList.remove('visible');
            annotationPopup.classList.remove('visible');
        }
    });

    // ================================================================
    // 20. SEARCH
    // ================================================================
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
.value.toLowerCase().trim();
        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        const text = textContainer        const text = textContainer.innerText;
        const lines = text.split('\n');
        let resultsHtml = '';
        let count = 0;
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].toLowerCase().includes(query)) {
                resultsHtml += '<div class="bible-search-result">' + lines[i] + '</div>';
                count++;
.innerText;
        const lines = text.split('\n');
        let resultsHtml = '';
        let count = 0;
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].toLowerCase().includes(query)) {
                resultsHtml += '<div class="bible-search-result">' + lines[i] + '</div>';
                count++;
                if (count                if (count > 20) break;
            }
        }
        if (resultsHtml) {
            searchResults.innerHTML = resultsHtml;
            searchResults.style.display = 'block';
 > 20) break;
            }
        }
        if (resultsHtml) {
            searchResults.innerHTML = resultsHtml;
            searchResults.style.display = 'block';
        } else {
            searchResults.innerHTML = 'No matches found.';
            searchResults.style.display = '        } else {
            searchResults.innerHTML = 'No matches found.';
            searchResults.style.display = 'block';
        }
    });

    searchClose.addEventListener('click', function() {
        searchBar.classList.remove('visible');
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
    });

    // ================================================================
    // 21. KEYBOARD SHORTCUTS
    // =========================================================block';
        }
    });

    searchClose.addEventListener('click', function() {
        searchBar.classList.remove('visible');
        searchResults.innerHTML = '';
        searchResults.style.display = 'none';
    });

    // ================================================================
    // 21. KEYBOARD SHORTCUTS
    // ================================================================
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            searchBar.classList.toggle('visible');
            if (searchBar=======
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            searchBar.classList.toggle('visible');
            if (search.classList.contains('visible')) {
                searchInput.focus();
            }
        }
        if (e.key === 'Escape') {
            closeAllBibleMenus();
            searchBar.classList.remove('visibleBar.classList.contains('visible')) {
                searchInput.focus();
            }
        }
        if (e.key === 'Escape') {
            closeAllBibleMenus();
            searchBar.classList.remove('visible');
       ');
        }
    });

    // ================================================================
    // 22. SHARE
    // ================================================================
    shareBtn.addEventListener('click', function() {
        shareModal.classList.add('visible');
        overlay.classList.add('active }
    });

    // ================================================================
    // 22. SHARE
    // ================================================================
    shareBtn.addEventListener('click', function() {
        shareModal.classList.add('visible');
        overlay.classList.add('active');
    });

    document.querySelectorAll('.bible-share');
    });

    document.querySelectorAll('.bible-share-modal-close').forEach(btn => {
        btn.addEventListener('click-modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            shareModal.classList.remove('visible');
           ', function() {
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-facebook').forEach(btn => {
        btn overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-facebook').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-twitter').forEach(btn => {
        btn(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-twitter').forEach(btn => {
        btn.addEventListener('.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://twitter.com/intent/tweet?text=Reading&url=' + encodeURIComponent(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-whatsapp').click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://twitter.com/intent/tweet?text=Reading&url=' + encodeURIComponent(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-whatsapp').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?bookforEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book='=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-copy').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(url), '_blank');
        });
    });

    document.querySelectorAll('.bible-share-copy').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
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

    // ================================= chapter + '&verse=' + verse + '&share=1';
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

    // ================================================================
    // 23. NOTES
    // ================================================================
    function toggleNotesPanel() {
        const panel = document.getElementById('bibleNotesPanel');
===============================
    // 23. NOTES
    // ================================================================
    function toggleNotesPanel() {
        const panel = document.getElementById('bibleNotesPanel');
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
            loadBibleNotes();
            overlay.classList.add('active');
        } else {
            panel.style.display = 'none';
            overlay.classList.remove('active');
        }
    }

    notesToggle.addEventListener('click', toggleNotesPanel);
    notesClose.addEventListener('click', function() {
        notesPanel.style.display = 'none';
        overlay.classList.remove('active');
    });

    function load        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
            loadBibleNotes();
            overlay.classList.add('active');
        } else {
            panel.style.display = 'none';
            overlay.classList.remove('active');
        }
    }

    notesToggle.addEventListener('click', toggleNotesPanel);
    notesClose.addEventListener('click', function() {
        notesPanel.style.display = 'none';
        overlay.classList.remove('active');
    });

    function loadBibleNotes() {
        fetch('/bible_reader_ajax.php?action=get_notes&book=' + encodeBibleNotes() {
        fetch('/bible_reader_ajax.php?action=get_notes&book=' + encodeURIComponent(book) + '&chapter=' + chapter)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.notes.forEach(note => {
                        let reactionsHtml = '';
                        if (noteURIComponent(book) + '&chapter=' + chapter)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.notes.forEach(note => {
                        let reactionsHtml = '';
                        if (note.reactions && note.reactions.length > 0) {
                            note.reactions.forEach(reaction => {
                                reactionsHtml += `<span class="reaction" onclick=".reactions && note.reactions.length > 0) {
                            note.reactions.forEach(reaction => {
                                reactionsHtml += `<span class="reaction" onclick="reactBibleNote(${note.id}, '${reaction.reaction_type}')">
                                    ${reaction.reaction_type} ${reaction.count}
                                </span>`;
                            });
                        }
                        const isMyNote = note.user_id == userId;
                        const canReact = !note.is_private || isMyNote;
                        html += `<div class="note-card ${notereactBibleNote(${note.id}, '${reaction.reaction_type}')">
                                    ${reaction.reaction_type} ${reaction.count}
                                </span>`;
                            });
                        }
                        const isMyNote = note.user_id == userId;
                        const canReact = !note.is_private || isMyNote;
                        html += `<div class="note-card ${note.is_private ? 'private' : ''.is_private ? 'private' : ''}">
                            <div class="note-author">
                                ${note.avatar ? `<img src="${note.avatar}" class="note-avatar">` : `<div class="note-avatar-placeholder">${(note.display_name || note.username).charAt(0).toUpperCase()}</div>`}
                                <div class="note-author-info">
                                    <strong>${note.display_name || note.username}</strong>
                                   }">
                            <div class="note-author">
                                ${note.avatar ? `<img src="${note.avatar}" class="note-avatar">` : `<div class="note-avatar-placeholder">${(note.display_name || note.username).charAt(0).toUpperCase()}</div>`}
                                <div class="note-author-info">
                                    <strong>${note.display_name || note.username}</strong>
                                    <small>${time <small>${timeAgo(note.created_at)}</small>
                                    ${note.is_private ? '<span class="badge-private">🔒 Private</span>' : ''}
                                </div>
                            </div>
                            <p class="note-text">${note.text}</p>
                            <div class="note-footer">
                                <div class="note-reactions">
                                    ${reactionsHtml}
                                    ${canAgo(note.created_at)}</small>
                                    ${note.is_private ? '<span class="badge-private">🔒 Private</span>' : ''}
                                </div>
                            </div>
                            <p class="note-text">${note.text}</p>
                            <div class="note-footer">
                                <div class="note-reactions">
                                    ${reactionsHtml}
                                    ${canReact ? `<button classReact ? `<button class="btn btn-sm btn-outline" onclick="showBibleReactionPicker(${note.id}, event)">➕ React</button>` : ''}
                                </div>
                                ${isMyNote ? `<button class="btn btn-sm btn-danger" onclick="deleteBibleNote(${note.id})">🗑️</button>` : ''}
                            </="btn btn-sm btn-outline" onclick="showBibleReactionPicker(${note.id}, event)">➕ React</button>` : ''}
                                </div>
                                ${isMyNote ? `<button class="btn btn-sm btn-danger" onclick="deleteBibleNote(${note.id})">🗑️</button>` : ''div>
                        </div>`;
                    });
                    notesList.innerHTML = html || '<p class="empty-notes">No notes for this book.</p>';
                    noteBadge.textContent = data.notes.length;
                }
            });
    }

    function submitBibleNote(e) {
        e.preventDefault();
        const text = noteText.value.trim();
        const isPrivate = notePrivate.checked ? 1 : 0;
        if (!text) {
            alert('Please enter a note.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'add_n}
                            </div>
                        </div>`;
                    });
                    notesList.innerHTML = html || '<p class="empty-notes">No notes for this book.</p>';
                    noteBadge.textContent = data.notes.length;
                }
            });
    }

    function submitBibleNote(e) {
        e.preventDefault();
        const text = noteText.value.trim();
        const isPrivate = notePrivate.checked ? 1 : 0;
        if (!text) {
            alert('Please enter a note.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'add_note');
        formData.append('book', book);
        formData.append('chapter', chapter);
        formData.append('verse', verse);
        formData.append('text', text);
        formData.append('is_private',ote');
        formData.append('book', book);
        formData.append('chapter', chapter);
        formData.append('verse', verse);
        formData.append('text', text);
        formData.append('is_private', isPrivate);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                loadBibleNotes();
                noteText.value = '';
                notePrivate.checked = false;
                document.getElementById(' isPrivate);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                loadBibleNotes();
                noteText.value = '';
                notePrivate.checked = false;
                document.getElementById('bibleAddNoteForm').style.display = 'none';
            } else {
                alert('Error: ' + data.error);
            }
        });
    }

    addNoteBtn.addEventListener('click', function() {
        const form = document.getElementById('bibleAddNoteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            noteText.focus();
        }
   bibleAddNoteForm').style.display = 'none';
            } else {
                alert('Error: ' + data.error);
            }
        });
    }

    addNoteBtn.addEventListener('click', function() {
        const form = document.getElementById('bibleAddNoteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            noteText.focus();
        }
    });

    noteCancel.addEventListener('click', function() {
        document.getElementById('bibleAddNoteForm').style.display = 'none';
        noteText.value = '';
        notePrivate.checked = false;
    });

    noteForm.addEventListener('submit', submitBibleNote);

    function deleteBibleNote(noteId) {
        if });

    noteCancel.addEventListener('click', function() {
        document.getElementById('bibleAddNoteForm').style.display = 'none';
        noteText.value (!confirm('Delete this note?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_note');
        formData.append('note_id', noteId);
        fetch('/bible_reader_ajax.php', {
            = '';
        notePrivate.checked = false;
    });

    noteForm.addEventListener('submit', submitBibleNote);

    function deleteBibleNote(noteId) {
        if (!confirm('Delete this note?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_note');
        formData.append('note_id', noteId);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadBibleNotes();
        });
    }

    // ================================================================
    // 24. REACTIONS
    // ================================================================
    function showBibleReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const btn = event.target.closest('.btn-outline');
        const rect = btn.getBoundingClientRect();
        reactionPicker.style.top = (rect.bottom + 8) + 'px';
        reactionPicker.style.left method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadBibleNotes();
        });
    }

    // ================================================================
    // 24. REACTIONS
    // ================================================================
    function showBibleReactionPicker(noteId, event) {
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
            formData.append = (rect.left) + 'px';
        reactionPicker.style.display = 'flex';
        currentReactionPicker = reactionPicker;
    }

    document.querySelectorAll('.reaction-option').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!currentNoteId) return;
            const reaction = this.dataset.reaction;
            const formData = new FormData();
            formData('action', 'add_reaction');
            formData.append('note_id', currentNoteId);
            formData.append('reaction_type', reaction);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    loadBibleNotes();
                    reactionPicker.style.display = 'none';
                    currentNoteId = null;
                    currentReactionPicker = null;
                }
            });
        });
    });

    function reactBibleNote(noteId, reaction.append('action', 'add_reaction');
            formData.append('note_id', currentNoteId);
            formData.append('reaction_type', reaction);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    loadBibleNotes();
                    reactionPicker.style.display = 'none';
                    currentNoteId = null;
                    currentReactionPicker = null;
                }
            });
        });
    });

    function reactBibleNote(noteId, reactionType) {
        const formData = new FormData();
        formData.append('action', 'toggle_reaction');
        formData.append('note_id', noteId);
        formData.append('reType) {
        const formData = new FormData();
        formData.append('action', 'toggle_reaction');
        formData.append('note_id', noteId);
        formData.append('reaction_typeaction_type', reactionType);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadBibleNotes();
        });
    }

    document.addEventListener('click', function(e) {
        if (currentReactionPicker && !currentReactionPicker.contains(e.target) && !e.target.closest('.btn-outline') && !e.target.closest('.reaction')) {
            currentReactionPicker.style.display = 'none';
            currentReactionPicker = null;
', reactionType);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadBibleNotes();
        });
    }

    document.addEventListener('click', function(e) {
        if (currentReactionPicker && !currentReactionPicker.contains(e.target) && !e.target.closest('.btn-outline') && !e.target.closest('.reaction')) {
            currentReactionPicker.style.display = 'none';
            currentReactionPicker = null;
            currentNoteId = null;
        }
    });

    // ================================================================
    // 25. CHALLENGE WIDGET
    // ================================================================
    function loadBibleChallenge() {
        if (userId === 0) return;
        fetch('/b            currentNoteId = null;
        }
    });

    // ================================================================
    // 25. CHALLENGE WIDGET
    // ================================================================
    function loadBibleChallenge() {
        if (userId === 0) return;
        fetch('/bible_reader_ajax.php?action=get_monthly_challenge&user_id=' + userId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    const percent = Math.min(100, Math.round((data.progress / data.target) * 100));
                    challengeWidget.innerHTML = `
                        <div class="ible_reader_ajax.php?action=get_monthly_challenge&user_id=' + userId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    const percent = Math.min(100, Math.round((data.progress / data.target) * 100));
                    challengeWidget.innerHTML = `
                        <div class="bible-challenge-widget">
                            <h4>📖 Monthly Bible Challenge</h4>
                            <p>${data.goal}</p>
                            <div class="bible-challenge-progress">
                                <div class="bible-challenge-bar" style="width:${percent}%;"></div>
                                <span class="bible-challenge-percent">${percent}%</span>
                            </div>
                            <p class="bible-challenge-stats">${data.progress} / ${data.target} chapters read</p>
                            <buttonbible-challenge-widget">
                            <h4>📖 Monthly Bible Challenge</h4>
                            <p>${data.goal}</p>
                            <div class="bible-challenge-progress">
                                <div class="bible-challenge-bar" style="width:${percent}%;"></div>
                                <span class="bible-challenge-percent">${percent}%</span>
                            </div>
                            <p class="bible-challenge-stats">${data.progress} / ${data.target} chapters read</p>
                            <button class="btn btn-sm class="btn btn-sm btn-primary" onclick="updateBibleChallenge()">📈 Update Progress</button>
                        </div>
                    `;
                }
            });
    }

    window.updateBibleChallenge = function() {
        const chapters = prompt('How many chapters did you read today?');
        if (chapters && parseInt(chapters) > 0) {
            const formData = new FormData();
            formData.append('action', 'update_challenge_progress');
            formData.append('user_id', userId);
            btn-primary" onclick="updateBibleChallenge()">📈 Update Progress</button>
                        </div>
                    `;
                }
            });
    }

    window.updateBibleChallenge = function() {
        const chapters = prompt('How many chapters did you read today?');
        if (chapters && parseInt(chapters) > 0) {
            const formData = new FormData();
            formData.append('action', 'update_challenge_progress');
            formData.append('user_id', userId);
            formData.append('chapters_read', chapters);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                loadBibleChallenge();
                alert('✅ Progress updated!');
            });
        }
    };

    if (userId > 0) loadBibleChallenge();

    // ================================================================
    // 26. SESSION TRACKING
    // ================================================================
    if (userId > 0) {
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
 formData.append('chapters_read', chapters);
            fetch('/bible_reader_ajax.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                loadBibleChallenge();
                alert('✅ Progress updated!');
            });
        }
    };

    if (userId > 0) loadBibleChallenge();

    // ================================================================
    // 26. SESSION TRACKING
    // ================================================================
    if (userId > 0) {
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=start_session&book=' + encodeURIComponent(book) + '&chapter=' + chapter
        });
        window.addEventListener('beforeunload', function() {
            navigator.sendBeacon('/bible_reader_ajax.php', new            body: 'action=start_session&book=' + encodeURIComponent(book) + '&chapter=' + chapter
        });
        window.addEventListener('beforeunload', function() {
            navigator.sendBeacon('/bible_reader_ajax.php', new URLSearchParams({
                action: 'end_session',
                book: book,
                chapter: chapter
            }));
        });
    }

    // ================================================================
    // 27. TIME AGO HELPER
    // ================================================================
    function time URLSearchParams({
                action: 'end_session',
                book: book,
                chapter: chapter
            }));
        });
    }

    // ================================================================
    // 27. TIME AGO HELPER
    // ================================================================
    function timeAgo(timestamp) {
        constAgo(timestamp) {
        const time = new Date(timestamp).getTime();
        const now = Date.now();
        const diff = Math.floor((now - time) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    // ================================================================
    // 28. time = new Date(timestamp).getTime();
        const now = Date.now();
        const diff = Math.floor((now - time) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    // ================================================================
    // 28. INITIAL LOAD
    // ================================================================
    // Populate book selector
    const bookSelect = document.getElementById('bibleBookSelect');
    BOOKS.forEach(b => {
 INITIAL LOAD
    // ================================================================
    // Populate book selector
    const bookSelect = document.getElementById('bibleBookSelect');
    BOOKS        const opt = document.createElement('option');
        opt.value = b;
        opt.textContent = b;
        bookSelect.appendChild(opt);
    });
    bookSelect.value = book;

    // Populate chapter selector
    const chapterSelect = document.getElementById('bibleChapterSelect');
    const maxChapters = CHAPTER_COUNTS[book] || 21;
    for (let i = 1; i <= maxChapters; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.textContent = 'Chapter ' + i;
        chapterSelect.appendChild.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b;
        opt.textContent = b;
        bookSelect.appendChild(opt);
    });
    bookSelect.value = book;

    // Populate chapter selector
    const chapterSelect = document.getElementById('bibleChapterSelect');
    const maxChapters = CHAPTER_COUNTS[book] || 21;
    for (let i = 1; i <= maxChapters; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.text(opt);
    }
    chapterSelect.value = chapter;

    // Populate verse selector
    const verseSelect = document.getElementById('bibleVerseSelect');
    const maxVerses = VERSE_COUNTS[book] || 30;
    for (let i = 1; i <= maxVerses; i++) {
        constContent = 'Chapter ' + i;
        chapterSelect.appendChild(opt);
    }
    chapterSelect.value = chapter;

    // Populate verse selector
    const verseSelect = document.getElementById('bibleVerseSelect');
    const maxVerses = VERSE_COUNTS[book] || 30;
    for (let i = 1; i <= maxVerses; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.textContent = 'Verse ' + i;
        verseSelect.appendChild(opt);
    }
    verseSelect.value = verse;

    // Load initial verse
    loadBibleVerse();

    // Update progress ring
    setTimeout(() => {
        updateProgress(content.scrollTop);
    }, 200);
});
</script>

<!-- ============================================================ -->
<!--  STYLES – FULLY BRANDED & RESPONSIVE                         -->
<!-- ============================================================ -->
<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c039 opt = document.createElement('option');
        opt.value = i;
        opt.textContent = 'Verse ' + i;
        verseSelect.appendChild(opt);
    }
    verseSelect.value = verse;

    // Load initial verse
    loadBibleVerse();

    // Update progress ring
    setTimeout(() => {
        updateProgress(content.scrollTop);
    }, 200);
});
</script>

<!-- ============================================================ -->
<!--  STYLES – FULLY BRANDED & RESPONSIVE                         -->
<!-- ============================================================ -->
<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg2b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px -bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.bible-reader-page { padding: 32px 0 60px; }
.page-header { text-align: center; margin-bottom: 24px; }
.page-header h1 { font-size: 2.2rem; margin-bottom3s; }

.bible-reader-page { padding: 32px 0 60px; }
.page-header { text-align: center; margin-bottom: 24px; }
.page-header h1 { font-size: 2.2rem; margin-bottom: 4px; color: var(--dark); }
.page-header p { color: var(--text-light); }

.bible-reader-container { display: flex; flex-direction: column; min-height: 400px; }

.bible-reader-header { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; background: var(--card-bg); border-bottom: 1px solid var(--border); z-index: 10; }
.bible-reader-header-left {: 4px; color: var(--dark); }
.page-header p { color: var(--text-light); }

.bible-reader-container { display: flex; flex-direction: column; min-height: 400px; }

.bible-reader-header { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; background: var(--card-bg); border-bottom: 1px solid var(--border); z-index: 10; }
.bible-reader-header-left { display: flex; align-items: center; gap: 12px; }
.bible-reader-back { color: var(--rose); font-weight: 500; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 4px; }
.bible-reader-title { font-size: 1.1rem; margin: 0; color: var(--text); font-family: 'Playfair Display', serif; }
.bible-reader-header-right { display: flex; align-items: center display: flex; align-items: center; gap: 12px; }
.bible-reader-back { color: var(--rose); font-weight: 500; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 4px; }
.bible-reader-title { font-size: 1.1rem; margin: 0; color: var(--text); font-family: 'Playfair Display', serif; }
.bible-reader-header-right { display: flex; align-items: center; gap: 8px; }
.bible-reader-header-right button { background: none; border: none; font-size: 1.1rem; color: var(--text); cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: all 0.2s; }
.bible-reader-header; gap: 8px; }
.bible-reader-header-right button { background: none; border: none; font-size: 1.1rem; color: var(--text); cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: all 0.2s; }
.bible-reader-header-right button:hover { background: rgba(219,161,162,0.1); color: var(--rose); }

.bible-progress-ring { vertical-align: middle; }
.bible-progress-ring-bg { stroke: var(--border); }
.bible-progress-ring-fill { stroke: var(--rose); transition: stroke-dashoffset 0.3s; }

.bible-reader-settings { display: none; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 12px 16px; }
.bible-reader-settings.open { display: block; }
.bible-settings-grid { display: flex-right button:hover { background: rgba(219,161,162,0.1); color: var(--rose); }

.bible-progress-ring { vertical-align: middle; }
.bible-progress-ring-bg { stroke: var(--border); }
.bible-progress-ring-fill { stroke: var(--rose); transition: stroke-dashoffset 0.3s; }

.bible-reader-settings { display: none; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 12px 16px; }
.bible-reader-settings.open { display: block; }
.bible-settings-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.bible-setting-group { display: flex; flex-direction: column; gap: 4px; }
.bible-setting-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-light); letter-sp; flex-wrap: wrap; gap: 12px; align-items: center; }
.bible-setting-group { display: flex; flex-direction: column; gap: 4px; }
.bible-setting-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.5px; }
.bible-theme-options, .bible-font-options, .bible-mode-options { display: flex; gap: 4px; }
.bible-theme-btn, .bible-font-btn, .bible-mode-btn { padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: transparent; cursor: pointer; font-size: 0.75rem; transition: all 0.2s; }
.bible-theme-btn:hover, .bible-font-btn:hover, .bible-mode-btn:hover { border-color: var(--rose); }
.bibleacing: 0.5px; }
.bible-theme-options, .bible-font-options, .bible-mode-options { display: flex; gap: 4px; }
.bible-theme-btn, .bible-font-btn, .bible-mode-btn { padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: transparent; cursor: pointer; font-size: 0.75rem; transition: all 0.2s; }
.bible-theme-btn:hover, .bible-font-btn:hover, .bible-mode-btn:hover { border-color: var(--rose); }
.bible-theme-btn.active, .bible-font-btn.active, .bible-mode-btn.active { border-color: var(--rose); background: var(--rose); color: white; }
.color-preview { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; border: 1px solid var(--border); }
.bible-size-controls { display: flex; align-items: center; gap: 6px; }
.bible-size-btn { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: var(--text); transition: all-theme-btn.active, .bible-font-btn.active, .bible-mode-btn.active { border-color: var(--rose); background: var(--rose); color: white; }
.color-preview { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; border: 1px solid var(--border); }
.bible-size-controls { display: flex; align-items: center; gap: 6px; }
.bible-size-btn { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: var(--text); transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.bible-size-btn:hover { border-color: var(--rose); color: var(--rose); }
.bible-size-controls input[type="range"] { width: 80px; accent-color: var(--rose); }
.bible-theme-extra { margin-top: 4px; }

.bible-toc-drawer { position: fixed; top: 0; right: -320px; width: 320px; height: 100vh; background: var(--card-bg); box-shadow: -4px 0 20px rgba(0,0,0,0.1); z-index: 0.2s; display: flex; align-items: center; justify-content: center; }
.bible-size-btn:hover { border-color: var(--rose); color: var(--rose); }
.bible-size-controls input[type="range"] { width: 80px; accent-color: var(--rose); }
.bible-theme-extra { margin-top: 4px; }

.bible-toc-drawer { position: fixed; top: 0; right: -320px; width: 320px; height: 100vh; background: var(--card-bg); box-shadow: -4px 0 20px rgba(0,0,0,0.1); z-index: 20; transition: right 0.3s ease; display: flex; flex-direction: column; }
.bible-toc-drawer.open { right: 0; }
.bible-toc-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.bible-toc-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color 20; transition: right 0.3s ease; display: flex; flex-direction: column; }
.bible-toc-drawer.open { right: 0; }
.bible-toc-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.bible-toc-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text); padding: 0 4px; }
.bible-toc-body { flex: 1; overflow-y: auto; padding: 12px 20px; }

.bible-notes-panel { position: fixed; bottom: 0: var(--text); padding: 0 4px; }
.bible-toc-body { flex: 1; overflow-y: auto; padding: 12px 20px; }

.bible-notes-panel { position: fixed; bottom: 0; right: 0; width: 380px; max-height: 60vh; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px 12px 0 0; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); display: none; flex-direction: column; z-index: 25; }
.bible-notes-header { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--vanilla); border-radius: 12px 12px 0 0; }
.bible-notes-title h3 { margin: 0; font-size:; right: 0; width: 380px; max-height: 60vh; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px 12px 0 0; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); display: none; flex-direction: column; z-index: 25; }
.bible-notes-header { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--vanilla); border-radius: 12px 12px 0 0; }
.bible-notes-title h3 { margin: 0; font-size: 1rem; }
.bible-notes-title .badge { background: var(--rose); color: white; padding: 0 8px; border-radius: 12px; 1rem; }
.bible-notes-title .badge { background: var(--rose); color: white; padding: 0 8px; border-radius: 12px; font-size: 0.75rem; }
.bible-notes-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.note-card.private { border-left: 4px solid #6c757d; }
.note-author { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.note-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.note-avatar-placeholder { width: 32 font-size: 0.75rem; }
.bible-notes-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.note-card.private { border-left: 4px solid #6c757d; }
.note-author { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.note-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.note-avatar-placeholder { width: 32px; height: 32px; border-radius: 50%; background: var(--rose); color: white; display: flex; align-items: center; justify-content: center; fontpx; height: 32px; border-radius: 50%; background: var(--rose); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; }
.note-author-info { flex: 1; }
.note-author-info small { color: var(--text-light); }
.note-text { margin: 0 0 8px; font-size:-weight: bold; font-size: 0.85rem; }
.note-author-info { flex: 1; }
.note-author-info small { color: var(--text-light); }
.note-text { margin: 0 0 8px; font-size: 0.95rem; }
.note-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }
.note-reactions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.reaction { background: 0.95rem; }
.note-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }
.note-reactions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.reaction { background: var(--vanilla); padding: 0 8px; border-radius: 12px var(--vanilla); padding: 0 8px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; }
.reaction:hover; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; }
.reaction:hover { background: rgba(219,161,162,0.2); }
.badge-private { background: #6c757d; color: white; padding: 0 6px; border-radius: 4px; font-size: 0.7rem; }
.empty-notes { color: var(--text-light); text-align: center; padding: 24px 12px; }
#bibleAddNoteForm { padding: 12px 16px; border-top: 1px solid var { background: rgba(219,161,162,0.2); }
.badge-private { background: #6c757d; color: white; padding: 0 6px; border-radius: 4px; font-size: 0.7rem; }
.empty-notes { color: var(--text-light); text-align: center; padding: 24px 12px; }
#bibleAddNoteForm { padding: 12px 16px; border-top: 1px solid var(--border); }
#bibleAddNoteForm textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; resize: vertical; }
(--border); }
#bibleAddNoteForm textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; resize: vertical; }
#bibleAddNoteForm .btn { padding: 4px 12px; font-size: 0.8rem; }

.bible-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding: 16px; background: var(--card-bg); border-radius: 12px; border: 1px solid var#bibleAddNoteForm .btn { padding: 4px 12px; font-size: 0.8rem; }

.bible-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding: 16px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.bible-control-group(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.bible-control-group { display: flex; flex-direction: column; gap: 4px; min-width: 100px; flex: 1; }
.bible-control-group label { font-size: 0.8rem; font { display: flex; flex-direction: column; gap: 4px; min-width: 100px; flex: 1; }
.bible-control-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.bible-control-group select, .bible-control-group input { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
.bible-control-group select:focus, .bible-control-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.bible-control-group select, .bible-control-group input { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
.bible-control-group select:focus, .bible-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-control-group.action-group { display: flex; flex-wrap: wrap; align-items: center;-control-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-control-group.action-group { display: flex; flex-wrap: wrap; align-items: gap: 4px; flex-direction: row; min-width: auto; }
.bible-control-group.action-group .btn-sm { padding: 4px 10px; font-size: 0.75rem; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
.bible-control-group.toggle-group { flex: 0 0 auto; justify-content: center; }
.bible-control-group.toggle-group label { display: flex; align-items: center; gap: 6px; cursor: pointer; center; gap: 4px; flex-direction: row; min-width: auto; }
.bible-control-group.action-group .btn-sm { padding: 4px 10px; font-size: 0.75rem; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
.bible-control-group.toggle-group { flex: 0 0 auto; justify-content: center; }
.bible-control-group.toggle-group label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500; font-size: 0.9rem; color: var(--text); }
.bible-control-group font-weight: 500; font-size: 0.9rem; color: var(--text); }
.bible-control-group.toggle.toggle-group input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 40px; height: 22px; background: var(--border); border-radius: 11px; cursor: pointer; transition: background 0.3s; position: relative; flex-shrink: 0; }
.bible-control-group.toggle-group input[type="checkbox"]:checked { background: var(--rose); }
.bible-control-group.toggle-group input[type="checkbox"]::after { content: ''; position: absolute; top: 2px; left: 2px; width:-group input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 40px; height: 22px; background: var(--border); border-radius: 11px; cursor: pointer; transition: background 0.3s; position: relative; flex-shrink: 0; }
.bible-control-group.toggle-group input[type="checkbox"]:checked { background: var(--rose); }
.bible-control-group.toggle-group input[type="checkbox"]::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; background: white; border-radius: 50%; transition: transform 0.3s; }
.bible-control-group.toggle-group input[type="checkbox"]:checked::after { transform: translateX(18px); }

.bible-display { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 2px solid var(--rose 18px; height: 18px; background: white; border-radius: 50%; transition: transform 0.3s; }
.bible-control-group.toggle-group input[type="checkbox"]:checked::after { transform: translateX(18px); }

.bible-display { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 2px solid var(--rose); box-shadow: var(--shadow); min-height: 400px; }
.verse-container { position: relative; }
.verse-container.parallel { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.verse-column { padding: ); box-shadow: var(--shadow); min-height: 400px; }
.verse-container { position: relative; }
.verse-container.parallel { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.verse-column { padding: 12px; background: var(--fantasy); border-radius: 812px; background: var(--fantasy); border-radius: 8px; border-left: 3px solid var(--rose); }
.verse-column h3 { font-size: px; border-left: 3px solid var(--rose); }
.verse-column h3 { font-size: 1rem; margin-bottom: 8px; color: var(--text); }
.verse-content { font-family: 'Georgia', serif; font-size: 1.1rem; line-height: 1.9; color: var(--text); min-height: 200px; text-align: justify; }
.verse-content p { margin-bottom: 1rem; margin-bottom: 8px; color: var(--text); }
.verse-content { font-family: 'Georgia', serif; font-size: 1.1rem; line-height: 1.9; color: var(--text); min-height: 200px; text-align: justify; }
.verse-content p { margin-bottom: 12px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.2s; }
.verse-content p:hover { background: rgba(219,161,162,0.1); }
.verse-content p.highlighted { background: #fff3b0; }
.bible-chapter-nav-bottom { display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top12px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.2s; }
.verse-content p:hover { background: rgba(219,161,162,0.1); }
.verse-content p.highlighted { background: #fff3b0; }
.bible-chapter-nav-bottom { display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }
.bible-chapter-nav-bottom .btn { padding: 6px 16px; font-size: 0.85rem; }
#bibleChapterDisplay { font-weight: 600; color: var(--text); }

.bible-highlight-tooltip { position: 1px solid var(--border); }
.bible-chapter-nav-bottom .btn { padding: 6px 16px; font-size: 0.85rem; }
#bibleChapterDisplay { font-weight: 600; color: var(--text); }

.bible-highlight-tooltip { position: fixed; display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; box-shadow: var(--shadow-hover); z-index: 30;: fixed; display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; box-shadow: var(--shadow-hover); z-index: 30; gap: 4px; align-items: center; }
.bible-highlight-tooltip.visible { display: flex; }
.bible-highlight-color { width: 20px; height: 20px; border-radius: 50%; border: 1px solid var(--border); cursor: pointer; transition: transform 0.2s; }
.bible-highlight-color:hover { transform: scale(1.15); }
.bible-high gap: 4px; align-items: center; }
.bible-highlight-tooltip.visible { display: flex; }
.bible-highlight-color { width: 20px; height: 20px; border-radius: 50%; border: 1px solid var(--border); cursor: pointer; transition: transform 0.2s; }
.bible-highlight-color:hover { transform: scale(1.15); }
.bible-highlight-color[data-color="yellow"] { background: #ffeb3b; }
.bible-highlight-color[data-color="green"] { background: #a5d6a7; }
.bible-highlight-color[data-color="blue"] { background: #90caf9; }
.bible-highlight-color[data-color="pink"] { background: #f48fb1; }
.bible-highlight-btn { background: none; border: none; cursor: pointer; color: var(--text); font-size: 0.9rem; padding: 0 4px; transition: color 0.2s; }
.bible-highlight-btn:hover { color: var(--rose); }

.bible-annotation-popup { position:light-color[data-color="yellow"] { background: #ffeb3b; }
.bible-highlight-color[data-color="green"] { background: #a5d6a7; }
.bible-highlight-color[data-color="blue"] { background: #90caf9; }
.bible-highlight-color[data-color="pink"] { background: #f48fb1; }
.bible-highlight-btn { background: none; border: none; cursor: pointer; color: var(--text); font-size: 0.9rem; padding: 0 4px; transition: color 0.2s; }
.bible-highlight-btn:hover { color: var(--rose); }

.bible-annotation-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 20px; box-shadow: var(--shadow-hover); z-index: 30; display: none; }
.bible-annotation-popup.visible { display: block; }
.bible-annotation-popup textarea { width: 100%; padding: 8px; border: 1: 20px; box-shadow: var(--shadow-hover); z-index: 30; display: none; }
.bible-annotation-popup.visible { display: block; }
.bible-annotation-popup textarea { width: 100%; padding: 8px; border: 1px solidpx solid var(--border); border-radius: 6px; resize: vertical; min-height: 60px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-annotation-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
.bible-annotation-actions button { padding: 4px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 0.8rem var(--border); border-radius: 6px; resize: vertical; min-height: 60px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-annotation-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
.bible-annotation-actions button { padding: 4px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 0.8rem; }
.bible-annotation-save { background: var(--rose); color: white; }
.bible-annotation-cancel { background: var(--border); color: var(--text); }

.bible-search-bar { position: absolute; top: 56px; right: 16px; width: 320px; background: var(--card-bg; }
.bible-annotation-save { background: var(--rose); color: white; }
.bible-annotation-cancel { background: var(--border); color: var(--text); }

.bible-search-bar { position: absolute; top: 56px; right: 16px; width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 12px; box-shadow: var(--shadow-hover); z-index: 15; display: none; }
.bible-search-bar.visible { display: block; }
.bible-search-bar input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-search-bar input:focus { outline: none); border: 1px solid var(--border); border-radius: 12px; padding: 12px; box-shadow: var(--shadow-hover); z-index: 15; display: none; }
.bible-search-bar.visible { display: block; }
.bible-search-bar input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-search-bar input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-search-bar #bibleSearchClose { position: absolute; top: 6px; right: 8px; background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 1rem; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-search-bar #bibleSearchClose { position: absolute; top: 6px; right: 8px; background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 1rem; }
#bibleSearchResults { margin-top: 8px; max-height: 200px; overflow-y: auto; display: none; }
.bible-search-result { padding: 4px 8px; font-size: 0.85rem; border-bottom: 1px solid var; }
#bibleSearchResults { margin-top: 8px; max-height: 200px; overflow-y: auto; display: none; }
.bible-search-result { padding: 4px 8px; font-size: 0.85rem; border-bottom: 1px solid var(--border); cursor: pointer; }
.bible-search-result:hover { background: rgba(219,161,162,0.1); }

.bible-share-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 30; display: none; align-items: center; justify-content: center; }
.bible-share-modal.visible { display: flex; }
.bible-share-modal-content { background: var(--card-bg); border-radius(--border); cursor: pointer; }
.bible-search-result:hover { background: rgba(219,161,162,0.1); }

.bible-share-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 30; display: none; align-items: center; justify-content: center; }
.bible-share-modal.visible { display: flex; }
.bible-share-modal-content { background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 400px; width: 90%; text-align: center: 12px; padding: 24px; max-width: 400px; width: 90%; text-align: center; }
.bible-share-modal-content h3 { margin-top: 0; }
.bible-share-options { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
.bible-share-options button { padding: 8px 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); cursor; }
.bible-share-modal-content h3 { margin-top: 0; }
.bible-share-options { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
.bible-share-options button { padding: 8px 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); cursor: pointer; transition: all 0.2s; font-size: 0.9rem; }
.bible-share-options button:hover { border-color: pointer; transition: all 0.2s; font-size: 0.9rem; }
.bible-share-options button:hover { border-color: var(--rose); background: rgba(219,161,162,0.1); }
.bible-share-modal-close { background: var(--rose); color: white; border: none; padding: 8px 24px; border-radius: 30px; cursor: pointer; }

.bible-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 11; display: none; }
.bible-overlay.active { display: block; }

.bible-challenge-widget { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px: var(--rose); background: rgba(219,161,162,0.1); }
.bible-share-modal-close { background: var(--rose); color: white; border: none; padding: 8px 24px; border-radius: 30px; cursor: pointer; }

.bible-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 11; display: none; }
.bible-overlay.active { display: block; }

.bible-challenge-widget { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin: 8px 16px; box-shadow: var(--shadow); display: flex; flex-direction: column; gap: 6px; }
.bible-challenge-widget h4 { margin: 0; font-size: 1rem; }
.b 16px; margin: 8px 16px; box-shadow: var(--shadow); display: flex; flex-direction: column; gap: 6px; }
.bible-challenge-widget h4 { margin: 0; font-size: 1rem; }
.bible-challenge-widget p { margin: 0; font-size: 0.9rem; color: var(--text-light); }
.bible-challenge-progress { position: relative; height: 16px; background: var(--border); border-radius: 8px; overflow: hidden; }
.bible-challenge-bar { height: 100%; background: var(--rose); transition: width 0.3s; }
.bible-challenge-percent { position: absolute; top: 0; right: 8px; font-size: 0.7rem; font-weight: 600; color: var(--text); line-height: 16px; }
.bible-challenge-stats { font-weight: 600; font-size: 0.9rem; color: var(--text); }

.bible-reader.focus-modeible-challenge-widget p { margin: 0; font-size: 0.9rem; color: var(--text-light); }
.bible-challenge-progress { position: relative; height: 16px; background: var(--border); border-radius: 8px; overflow: hidden; }
.bible-challenge-bar { height: 100%; background: var(--rose); transition: width 0.3s; }
.bible-challenge-percent { position: absolute; top: 0; right: 8px; font-size: 0.7rem; font-weight: 600; color: var(--text); line-height: 16px; }
.bible-challenge-stats { font-weight: 600; font-size: 0.9rem; color: var(--text); }

.bible-reader.focus-mode .bible-reader-header { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.bible-reader.focus-mode .bible-reader-settings { display: none !important; }
.bible-reader.focus-mode .bible-search-bar { display: none !important; }

@media (max-width: 768px) {
    .bible-controls { flex-direction: column; align-items .bible-reader-header { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.bible-reader.focus-mode .bible-reader-settings { display: none !important; }
.bible-reader.focus-mode .bible-search-bar { display: none !important; }

@media (max-width: 768px) {
    .bible-controls { flex-direction: column; align-items: stretch; }
    .bible-control-group { min-width: auto; }
    .verse-container.parallel { grid-template-columns: 1fr; }
    .bible-control-group.toggle-group { align-items: center; }
    .bible-notes-panel { width: 100%; max-height: 50vh; border-radius: 0; }
    .bible-toc-drawer {: stretch; }
    .bible-control-group { min-width: auto; }
    .verse-container.parallel { grid-template-columns: 1fr; }
    .bible-control-group.toggle-group { align-items: center; }
    .bible-notes-panel { width: 100%; max-height: 50vh; border-radius: 0; }
    .bible-toc-drawer { width: 280px; right: -280px; }
    .bible-search-bar { width: 260px; right: 8px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>