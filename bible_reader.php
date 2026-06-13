<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

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
                    <?php if (isLoggedIn()): ?>
                        <a href="<?php echo SITE_URL; ?>/<?php echo isAdmin() ? 'admin/dashboard.php' : 'dashboard.php'; ?>" class="bible-reader-back">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>/index.php" class="bible-reader-back">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    <?php endif; ?>
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
                    <!-- ===== DARK MODE TOGGLE (Added) ===== -->
                    <button class="bible-reader-theme-btn" id="bibleThemeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon"></i>
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
<!--  JAVASCRIPT – FULL BIBLE READER ENGINE (ALL FEATURES KEPT)   -->
<!-- ============================================================ -->
<script>
(function() {
    'use strict';

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
    const textContainer1p = document.getElementById('bibleVerseContent1p');
    const textContainer2p = document.getElementById('bibleVerseContent2p');
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
    const bookSelect = document.getElementById('bibleBookSelect');
    const chapterSelect = document.getElementById('bibleChapterSelect');
    const verseSelect = document.getElementById('bibleVerseSelect');
    const version1Select = document.getElementById('bibleVersion1');
    const version2Select = document.getElementById('bibleVersion2');
    const version2Group = document.getElementById('bibleVersion2Group');
    const parallelToggle = document.getElementById('bibleParallelMode');
    const singleView = document.getElementById('bibleSingleView');
    const parallelView = document.getElementById('bibleParallelView');
    const parallelTitle1 = document.getElementById('bibleParallelTitle1');
    const parallelTitle2 = document.getElementById('bibleParallelTitle2');
    const chapterDisplay = document.getElementById('bibleChapterDisplay');
    const goToInput = document.getElementById('bibleGoToInput');
    const goToBtn = document.getElementById('bibleGoToBtn');
    const prevBtn1 = document.getElementById('biblePrevChapterBtn');
    const nextBtn1 = document.getElementById('bibleNextChapterBtn');
    const prevBtn2 = document.getElementById('biblePrevChapterBtn2');
    const nextBtn2 = document.getElementById('bibleNextChapterBtn2');
    const copyBtn = document.getElementById('bibleCopyBtn');
    const highlightBtn = document.getElementById('bibleHighlightBtn');
    const notesBtn = document.getElementById('bibleNotesBtn');
    const annotateBtn = document.getElementById('bibleHighlightAnnotate');
    const themeToggleBtn = document.getElementById('bibleThemeToggle');

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
    // 4. HELPER FUNCTIONS
    // ================================================================
    function getVerseText(version, book, chapter, verse) {
        let url = `/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&version=${version}`;
        if (verse > 0) {
            url += `&verse_start=${verse}&verse_end=${verse}`;
        }
        return fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data && data.data.length > 0) {
                    return data.data;
                } else {
                    return `[${version} ${book} ${chapter} not found]`;
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                return `[Error loading chapter]`;
            });
    }

    function timeAgo(timestamp) {
        const time = new Date(timestamp).getTime();
        const now = Date.now();
        const diff = Math.floor((now - time) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
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

    // ================================================================
    // 5. POPULATE SELECTORS
    // ================================================================
    function populateBooks() {
        bookSelect.innerHTML = '';
        BOOKS.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b;
            opt.textContent = b;
            bookSelect.appendChild(opt);
        });
        bookSelect.value = book;
    }

    function populateChapters() {
        chapterSelect.innerHTML = '';
        const count = CHAPTER_COUNTS[book] || 21;
        for (let i = 1; i <= count; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = 'Chapter ' + i;
            chapterSelect.appendChild(opt);
        }
        chapterSelect.value = chapter;
    }

    function populateVerses() {
        verseSelect.innerHTML = '';
        const count = VERSE_COUNTS[book] || 30;
        for (let i = 1; i <= count; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = 'Verse ' + i;
            verseSelect.appendChild(opt);
        }
        verseSelect.value = verse;
    }

    // ================================================================
    // 6. RENDER VERSE (CORE)
    // ================================================================
    function renderVerse() {
        const b = book;
        const c = chapter;
        const v = verse;

        if (parallel) {
            parallelView.style.display = 'grid';
            singleView.style.display = 'none';
            parallelTitle1.textContent = version1;
            parallelTitle2.textContent = version2;

            textContainer1p.innerHTML = '<p>Loading...</p>';
            textContainer2p.innerHTML = '<p>Loading...</p>';

            Promise.all([
                getVerseText(version1, b, c, 0),
                getVerseText(version2, b, c, 0)
            ]).then(([data1, data2]) => {
                if (Array.isArray(data1)) {
                    textContainer1p.innerHTML = data1.map(v => `<p>${v.verse}. ${v.text}</p>`).join('');
                    applyHighlights(textContainer1p);
                } else {
                    textContainer1p.innerHTML = `<p>${data1}</p>`;
                }
                
                if (Array.isArray(data2)) {
                    textContainer2p.innerHTML = data2.map(v => `<p>${v.verse}. ${v.text}</p>`).join('');
                    applyHighlights(textContainer2p);
                } else {
                    textContainer2p.innerHTML = `<p>${data2}</p>`;
                }
            });
        } else {
            parallelView.style.display = 'none';
            singleView.style.display = 'block';
            textContainer.innerHTML = '<p>Loading...</p>';

            getVerseText(version1, b, c, 0).then(data => {
                if (Array.isArray(data)) {
                    textContainer.innerHTML = data.map(v => {
                        const cleanText = v.text.replace(/\\/g, '');
                        return `<p>${v.verse}. ${cleanText}</p>`;
                    }).join('');
                    applyHighlights(textContainer);
                } else {
                    textContainer.innerHTML = `<p>${data}</p>`;
                }
            });
        }

        chapterDisplay.textContent = `${b} ${c}`;
        document.getElementById('notesVerseRef').textContent = `${b} ${c}`;
        goToInput.value = `${b} ${c}:${v}`;
    }

    function loadVerse() {
        bookSelect.value = book;
        chapterSelect.value = chapter;
        verseSelect.value = verse;
        version1Select.value = version1;
        version2Select.value = version2;
        renderVerse();
    }

    // ===== FIX: loadBibleVerse (missing from original) =====
    function loadBibleVerse() {
        loadVerse();
    }

    // ================================================================
    // 7. HIGHLIGHTS
    // ================================================================
    function applyHighlights(container) {
        const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
        const paragraphs = container.querySelectorAll('p');
        paragraphs.forEach(p => {
            const text = p.textContent.trim();
            const key = `${book}-${chapter}-${text.split('.')[0]}`;
            if (saved[key]) {
                p.classList.add('highlighted');
            } else {
                p.classList.remove('highlighted');
            }
        });
    }

    function toggleHighlight() {
        const selText = getSelectedText();
        const range = getSelectionRange();
        if (!selText || !range) {
            alert('Please select a verse to highlight.');
            return;
        }
        const key = `${book}-${chapter}-${verse}`;
        const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
        if (saved[key]) {
            delete saved[key];
        } else {
            saved[key] = true;
        }
        localStorage.setItem('bibleHighlights', JSON.stringify(saved));
        renderVerse();
    }

    // ================================================================
    // 8. COPY
    // ================================================================
    function copyVerse() {
        getVerseText(version1, book, chapter, verse).then(text => {
            const content = `${version1} ${book} ${chapter}:${verse}\n${text}`;
            navigator.clipboard.writeText(content).then(() => {
                alert('Verse copied to clipboard!');
            }).catch(() => {
                prompt('Copy manually:', content);
            });
        });
    }

    // ================================================================
    // 9. NOTES
    // ================================================================
    function toggleNotesPanel() {
        if (notesPanel.style.display === 'none' || notesPanel.style.display === '') {
            notesPanel.style.display = 'flex';
            loadBibleNotes();
            overlay.classList.add('active');
        } else {
            notesPanel.style.display = 'none';
            overlay.classList.remove('active');
        }
    }

    function loadBibleNotes() {
        fetch(`/bible_reader_ajax.php?action=get_notes&book=${encodeURIComponent(book)}&chapter=${chapter}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.notes.forEach(note => {
                        let reactionsHtml = '';
                        if (note.reactions && note.reactions.length > 0) {
                            note.reactions.forEach(reaction => {
                                reactionsHtml += `<span class="reaction" onclick="reactBibleNote(${note.id}, '${reaction.reaction_type}')">
                                    ${reaction.reaction_type} ${reaction.count}
                                </span>`;
                            });
                        }
                        const isMyNote = note.user_id == userId;
                        const canReact = !note.is_private || isMyNote;
                        html += `<div class="note-card ${note.is_private ? 'private' : ''}">
                            <div class="note-author">
                                ${note.avatar ? `<img src="${note.avatar}" class="note-avatar">` : `<div class="note-avatar-placeholder">${(note.display_name || note.username).charAt(0).toUpperCase()}</div>`}
                                <div class="note-author-info">
                                    <strong>${note.display_name || note.username}</strong>
                                    <small>${timeAgo(note.created_at)}</small>
                                    ${note.is_private ? '<span class="badge-private">🔒 Private</span>' : ''}
                                </div>
                            </div>
                            <p class="note-text">${note.text}</p>
                            <div class="note-footer">
                                <div class="note-reactions">
                                    ${reactionsHtml}
                                    ${canReact ? `<button class="btn btn-sm btn-outline" onclick="showBibleReactionPicker(${note.id}, event)">➕ React</button>` : ''}
                                </div>
                                ${isMyNote ? `<button class="btn btn-sm btn-danger" onclick="deleteBibleNote(${note.id})">🗑️</button>` : ''}
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
        formData.append('is_private', isPrivate);
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

    function reactBibleNote(noteId, reactionType) {
        const formData = new FormData();
        formData.append('action', 'toggle_reaction');
        formData.append('note_id', noteId);
        formData.append('reaction_type', reactionType);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) loadBibleNotes();
        });
    }

    function showBibleReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const btn = event.target.closest('.btn-outline');
        const rect = btn.getBoundingClientRect();
        reactionPicker.style.top = (rect.bottom + 8) + 'px';
        reactionPicker.style.left = (rect.left) + 'px';
        reactionPicker.style.display = 'flex';
        currentReactionPicker = reactionPicker;
    }

    // ================================================================
    // 10. BOOKMARKS
    // ================================================================
    function checkBookmark() {
        if (userId === 0) return;
        fetch(`/bible_reader_ajax.php?action=check_bookmark&book=${encodeURIComponent(book)}&chapter=${chapter}&verse=${verse}`)
            .then(r => r.json())
            .then(data => {
                isBookmarked = data.bookmarked;
                bookmarkBtn.querySelector('i').className = data.bookmarked ? 'fas fa-bookmark' : 'far fa-bookmark';
                bookmarkBtn.classList.toggle('active', data.bookmarked);
            });
    }

    function toggleBookmark() {
        if (userId === 0) {
            alert('Please log in to bookmark verses.');
            return;
        }
        const formData = new FormData();
        formData.append('action', isBookmarked ? 'remove_bookmark' : 'add_bookmark');
        formData.append('book', book);
        formData.append('chapter', chapter);
        formData.append('verse', verse);
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                isBookmarked = !isBookmarked;
                bookmarkBtn.querySelector('i').className = isBookmarked ? 'fas fa-bookmark' : 'far fa-bookmark';
                bookmarkBtn.classList.toggle('active', isBookmarked);
            }
        });
    }


    // ================================================================
    // 12. FONT ENGINE
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
    // 13. FONT SIZE ENGINE
    // ================================================================
    function applySize(size) {
        fontSize = Math.min(160, Math.max(80, size));
        textContainer.style.fontSize = fontSize + '%';
        if (textContainer1p) textContainer1p.style.fontSize = fontSize + '%';
        if (textContainer2p) textContainer2p.style.fontSize = fontSize + '%';
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
    // 14. LINE HEIGHT ENGINE
    // ================================================================
    function applyLine(height) {
        lineHeight = Math.min(220, Math.max(140, height));
        textContainer.style.lineHeight = lineHeight / 100;
        if (textContainer1p) textContainer1p.style.lineHeight = lineHeight / 100;
        if (textContainer2p) textContainer2p.style.lineHeight = lineHeight / 100;
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
    // 15. LETTER SPACING ENGINE
    // ================================================================
    function applySpacing(spacing) {
        letterSpacing = Math.min(4, Math.max(-2, spacing));
        textContainer.style.letterSpacing = letterSpacing + 'px';
        if (textContainer1p) textContainer1p.style.letterSpacing = letterSpacing + 'px';
        if (textContainer2p) textContainer2p.style.letterSpacing = letterSpacing + 'px';
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
    // 16. READING MODE ENGINE
    // ================================================================
    function applyReadingMode(mode) {
        readingMode = mode;
        reader.setAttribute('data-mode', mode);
        localStorage.setItem('bibleReadingMode', mode);
        document.querySelectorAll('.bible-mode-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.bible-mode-btn[data-mode="${mode}"]`)?.classList.add('active');
    }

    document.querySelectorAll('.bible-mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            applyReadingMode(this.dataset.mode);
        });
    });
    reader.setAttribute('data-mode', readingMode);
    document.querySelector(`.bible-mode-btn[data-mode="${readingMode}"]`)?.classList.add('active');

    // ================================================================
    // 17. PROGRESS UPDATE
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
    // 18. SCROLL EVENT
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
    // 19. NAVIGATION
    // ================================================================
    function prevChapter() {
        if (chapter > 1) {
            chapter = chapter - 1;
            verse = 1;
            populateVerses();
            loadVerse();
        } else {
            const idx = BOOKS.indexOf(book);
            if (idx > 0) {
                book = BOOKS[idx - 1];
                chapter = CHAPTER_COUNTS[book] || 21;
                verse = 1;
                populateChapters();
                populateVerses();
                loadVerse();
            }
        }
    }

    function nextChapter() {
        const max = CHAPTER_COUNTS[book] || 21;
        if (chapter < max) {
            chapter = chapter + 1;
            verse = 1;
            populateVerses();
            loadVerse();
        } else {
            const idx = BOOKS.indexOf(book);
            if (idx < BOOKS.length - 1) {
                book = BOOKS[idx + 1];
                chapter = 1;
                verse = 1;
                populateChapters();
                populateVerses();
                loadVerse();
            }
        }
    }

    function goToVerse(input) {
        const match = input.match(/^([\d\s\w]+)\s+(\d+):(\d+)$/i);
        if (match) {
            const b = match[1].trim();
            const c = parseInt(match[2], 10);
            const v = parseInt(match[3], 10);
            let found = BOOKS.find(bk => bk.toLowerCase() === b.toLowerCase());
            if (!found) {
                found = BOOKS.find(bk => bk.toLowerCase().includes(b.toLowerCase()));
            }
            if (found) {
                book = found;
                chapter = c;
                verse = v;
                populateChapters();
                populateVerses();
                loadVerse();
                return;
            }
        }
        alert('Invalid verse format. Use: Book Chapter:Verse (e.g. John 3:16)');
    }

    // ================================================================
    // 20. SETTINGS PANEL
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
    // 21. TOC DRAWER
    // ================================================================
    tocToggle.addEventListener('click', function() {
        tocDrawer.classList.toggle('open');
        if (tocDrawer.classList.contains('open')) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    });
    tocClose.addEventListener('click', function() {
        tocDrawer.classList.remove('open');
        overlay.classList.remove('active');
    });

    // ================================================================
    // 22. FOCUS MODE
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
        } else {
            header.classList.remove('hidden');
        }
    });

    // ================================================================
    // 23. OVERLAY
    // ================================================================
    overlay.addEventListener('click', function() {
        settingsPanel.classList.remove('open');
        tocDrawer.classList.remove('open');
        notesPanel.style.display = 'none';
        shareModal.classList.remove('visible');
        overlay.classList.remove('active');
        highlightTooltip.classList.remove('visible');
        annotationPopup.classList.remove('visible');
        searchBar.classList.remove('visible');
    });

    // ================================================================
    // 24. CLOSE ALL
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
    // 25. BOOKMARKS (Event)
    // ================================================================
    bookmarkBtn.addEventListener('click', toggleBookmark);

    // ================================================================
    // 26. HIGHLIGHTS (Event)
    // ================================================================
    function showHighlightTooltip() {
        const text = getSelectedText();
        const range = getSelectionRange();
        if (!text || !range) {
            highlightTooltip.classList.remove('visible');
            return;
        }
        const rect = range.getBoundingClientRect();
        highlightTooltip.style.top = (rect.top - 50) + 'px';
        highlightTooltip.style.left = (rect.left + rect.width / 2 - 60) + 'px';
        highlightTooltip.classList.add('visible');
        selectedText = text;
        selectedRange = range;
    }

    document.addEventListener('mouseup', showHighlightTooltip);
    document.addEventListener('touchend', function() {
        setTimeout(showHighlightTooltip, 300);
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.bible-highlight-tooltip') && !e.target.closest('.bible-annotation-popup')) {
            highlightTooltip.classList.remove('visible');
            annotationPopup.classList.remove('visible');
        }
    });

    // Highlight colors
    document.querySelectorAll('.bible-highlight-color').forEach(btn => {
        btn.addEventListener('click', function() {
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
                    body: formData
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

    // Annotation
    annotateBtn.addEventListener('click', function() {
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
            formData.append('text', selectedText);
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
                selectedRange.insertNode(span);
                annotationPopup.classList.remove('visible');
            });
        }
    });

    annotationCancel.addEventListener('click', function() {
        annotationPopup.classList.remove('visible');
    });

    // ================================================================
    // 27. SEARCH
    // ================================================================
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        const container = parallel ? parallelView : textContainer;
        const text = container.innerText;
        const lines = text.split('\n');
        let resultsHtml = '';
        let count = 0;
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].toLowerCase().includes(query)) {
                resultsHtml += '<div class="bible-search-result">' + lines[i] + '</div>';
                count++;
                if (count > 20) break;
            }
        }
        if (resultsHtml) {
            searchResults.innerHTML = resultsHtml;
            searchResults.style.display = 'block';
        } else {
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
    // 28. KEYBOARD SHORTCUTS
    // ================================================================
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            searchBar.classList.toggle('visible');
            if (searchBar.classList.contains('visible')) {
                searchInput.focus();
            }
        }
        if (e.key === 'Escape') {
            closeAllBibleMenus();
            searchBar.classList.remove('visible');
        }
    });

    // ================================================================
    // 29. SHARE
    // ================================================================
    shareBtn.addEventListener('click', function() {
        shareModal.classList.add('visible');
        overlay.classList.add('active');
    });

    document.querySelectorAll('.bible-share-modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-facebook').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank');
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-twitter').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://twitter.com/intent/tweet?text=Reading&url=' + encodeURIComponent(url), '_blank');
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-whatsapp').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(url), '_blank');
            shareModal.classList.remove('visible');
            overlay.classList.remove('active');
        });
    });

    document.querySelectorAll('.bible-share-copy').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = window.location.origin + '/bible_reader.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&share=1';
            navigator.clipboard.writeText(url).then(() => {
                alert('✅ Link copied!');
                shareModal.classList.remove('visible');
                overlay.classList.remove('active');
            }).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('✅ Link copied!');
                shareModal.classList.remove('visible');
                overlay.classList.remove('active');
            });
        });
    });

    // ================================================================
    // 30. REACTIONS (Event)
    // ================================================================
    document.querySelectorAll('.reaction-option').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!currentNoteId) return;
            const reaction = this.dataset.reaction;
            const formData = new FormData();
            formData.append('action', 'add_reaction');
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

    document.addEventListener('click', function(e) {
        if (currentReactionPicker && !currentReactionPicker.contains(e.target) && !e.target.closest('.btn-outline') && !e.target.closest('.reaction')) {
            currentReactionPicker.style.display = 'none';
            currentReactionPicker = null;
            currentNoteId = null;
        }
    });

    // ================================================================
    // 31. CHALLENGE WIDGET
    // ================================================================
    function loadBibleChallenge() {
        if (userId === 0) return;
        fetch(`/bible_reader_ajax.php?action=get_monthly_challenge&user_id=${userId}`)
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
                            <button class="btn btn-sm btn-primary" onclick="updateBibleChallenge()">📈 Update Progress</button>
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
    // 32. SESSION TRACKING
    // ================================================================
    if (userId > 0) {
        fetch('/bible_reader_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=start_session&book=' + encodeURIComponent(book) + '&chapter=' + chapter
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
    // 33. EVENT LISTENERS (Controls)
    // ================================================================
    bookSelect.addEventListener('change', function() {
        book = this.value;
        chapter = 1;
        verse = 1;
        populateChapters();
        populateVerses();
        loadVerse();
        checkBookmark();
    });

    chapterSelect.addEventListener('change', function() {
        chapter = parseInt(this.value, 10);
        verse = 1;
        populateVerses();
        loadVerse();
        checkBookmark();
    });

    verseSelect.addEventListener('change', function() {
        verse = parseInt(this.value, 10);
        loadVerse();
        checkBookmark();
    });

    version1Select.addEventListener('change', function() {
        version1 = this.value;
        loadVerse();
    });

    version2Select.addEventListener('change', function() {
        version2 = this.value;
        if (parallel) loadVerse();
    });

    parallelToggle.addEventListener('change', function() {
        parallel = this.checked;
        version2Group.style.display = parallel ? 'block' : 'none';
        loadVerse();
    });

    prevBtn1.addEventListener('click', prevChapter);
    nextBtn1.addEventListener('click', nextChapter);
    prevBtn2.addEventListener('click', prevChapter);
    nextBtn2.addEventListener('click', nextChapter);

    goToBtn.addEventListener('click', function() {
        goToVerse(goToInput.value);
    });
    goToInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') goToVerse(goToInput.value);
    });

    copyBtn.addEventListener('click', copyVerse);
    highlightBtn.addEventListener('click', toggleHighlight);

    notesBtn.addEventListener('click', function() {
        toggleNotesPanel();
    });
    notesToggle.addEventListener('click', toggleNotesPanel);
    notesClose.addEventListener('click', function() {
        notesPanel.style.display = 'none';
        overlay.classList.remove('active');
    });

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

    // ================================================================
    // 34. INITIAL LOAD
    // ================================================================
    populateBooks();
    populateChapters();
    populateVerses();
    loadVerse();
    checkBookmark();

    // Update progress ring
    setTimeout(() => {
        updateProgress(content.scrollTop);
    }, 200);

})();
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
    --card-bg: #ffffff;
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
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.bible-reader-page { padding: 32px 0 60px; }
.page-header { text-align: center; margin-bottom: 24px; }
.page-header h1 { font-size: 2.2rem; margin-bottom: 4px; color: var(--dark); }
.page-header p { color: var(--text-light); }

.bible-reader-container { display: flex; flex-direction: column; min-height: 400px; }

.bible-reader-header { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; background: var(--card-bg); border-bottom: 1px solid var(--border); z-index: 10; }
.bible-reader-header-left { display: flex; align-items: center; gap: 12px; }
.bible-reader-back { color: var(--rose); font-weight: 500; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 4px; }
.bible-reader-title { font-size: 1.1rem; margin: 0; color: var(--text); font-family: 'Playfair Display', serif; }
.bible-reader-header-right { display: flex; align-items: center; gap: 8px; }
.bible-reader-header-right button { background: none; border: none; font-size: 1.1rem; color: var(--text); cursor: pointer; padding: 4px 8px; border-radius: 6px; transition: all 0.2s; }
.bible-reader-header-right button:hover { background: rgba(219,161,162,0.1); color: var(--rose); }

.bible-progress-ring { vertical-align: middle; }
.bible-progress-ring-bg { stroke: var(--border); }
.bible-progress-ring-fill { stroke: var(--rose); transition: stroke-dashoffset 0.3s; }

.bible-reader-settings { display: none; background: var(--card-bg); border-bottom: 1px solid var(--border); padding: 12px 16px; }
.bible-reader-settings.open { display: block; }
.bible-settings-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.bible-setting-group { display: flex; flex-direction: column; gap: 4px; }
.bible-setting-group label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.5px; }
.bible-theme-options, .bible-font-options, .bible-mode-options { display: flex; gap: 4px; }
.bible-theme-btn, .bible-font-btn, .bible-mode-btn { padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px; background: transparent; cursor: pointer; font-size: 0.75rem; transition: all 0.2s; }
.bible-theme-btn:hover, .bible-font-btn:hover, .bible-mode-btn:hover { border-color: var(--rose); }
.bible-theme-btn.active, .bible-font-btn.active, .bible-mode-btn.active { border-color: var(--rose); background: var(--rose); color: white; }
.color-preview { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; border: 1px solid var(--border); }
.bible-size-controls { display: flex; align-items: center; gap: 6px; }
.bible-size-btn { background: transparent; border: 1px solid var(--border); border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: var(--text); transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.bible-size-btn:hover { border-color: var(--rose); color: var(--rose); }
.bible-size-controls input[type="range"] { width: 80px; accent-color: var(--rose); }
.bible-theme-extra { margin-top: 4px; }

.bible-toc-drawer { position: fixed; top: 0; right: -320px; width: 320px; height: 100vh; background: var(--card-bg); box-shadow: -4px 0 20px rgba(0,0,0,0.1); z-index: 20; transition: right 0.3s ease; display: flex; flex-direction: column; }
.bible-toc-drawer.open { right: 0; }
.bible-toc-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.bible-toc-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text); padding: 0 4px; }
.bible-toc-body { flex: 1; overflow-y: auto; padding: 12px 20px; }
.bible-toc-list { list-style: none; padding: 0; margin: 0; }
.bible-toc-list li { padding: 4px 8px; cursor: pointer; border-radius: 4px; transition: background 0.2s; }
.bible-toc-list li:hover { background: rgba(219,161,162,0.1); }

.bible-notes-panel { position: fixed; bottom: 0; right: 0; width: 380px; max-height: 60vh; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px 12px 0 0; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); display: none; flex-direction: column; z-index: 25; }
.bible-notes-header { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--vanilla); border-radius: 12px 12px 0 0; }
.bible-notes-title h3 { margin: 0; font-size: 1rem; }
.bible-notes-title .badge { background: var(--rose); color: white; padding: 0 8px; border-radius: 12px; font-size: 0.75rem; }
.bible-notes-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.note-card.private { border-left: 4px solid #6c757d; }
.note-author { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.note-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.note-avatar-placeholder { width: 32px; height: 32px; border-radius: 50%; background: var(--rose); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; }
.note-author-info { flex: 1; }
.note-author-info small { color: var(--text-light); }
.note-text { margin: 0 0 8px; font-size: 0.95rem; }
.note-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }
.note-reactions { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.reaction { background: var(--vanilla); padding: 0 8px; border-radius: 12px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; }
.reaction:hover { background: rgba(219,161,162,0.2); }
.badge-private { background: #6c757d; color: white; padding: 0 6px; border-radius: 4px; font-size: 0.7rem; }
.empty-notes { color: var(--text-light); text-align: center; padding: 24px 12px; }
#bibleAddNoteForm { padding: 12px 16px; border-top: 1px solid var(--border); }
#bibleAddNoteForm textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; resize: vertical; }
#bibleAddNoteForm .btn { padding: 4px 12px; font-size: 0.8rem; }

.bible-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding: 16px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.bible-control-group { display: flex; flex-direction: column; gap: 4px; min-width: 100px; flex: 1; }
.bible-control-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.bible-control-group select, .bible-control-group input { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
.bible-control-group select:focus, .bible-control-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-control-group.action-group { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; flex-direction: row; min-width: auto; }
.bible-control-group.action-group .btn-sm { padding: 4px 10px; font-size: 0.75rem; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
.bible-control-group.toggle-group { flex: 0 0 auto; justify-content: center; }
.bible-control-group.toggle-group label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 500; font-size: 0.9rem; color: var(--text); }
.bible-control-group.toggle-group input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 40px; height: 22px; background: var(--border); border-radius: 11px; cursor: pointer; transition: background 0.3s; position: relative; flex-shrink: 0; }
.bible-control-group.toggle-group input[type="checkbox"]:checked { background: var(--rose); }
.bible-control-group.toggle-group input[type="checkbox"]::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; background: white; border-radius: 50%; transition: transform 0.3s; }
.bible-control-group.toggle-group input[type="checkbox"]:checked::after { transform: translateX(18px); }

.bible-display { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 2px solid var(--rose); box-shadow: var(--shadow); min-height: 400px; position: relative; }
.verse-container { position: relative; }
.verse-container.parallel { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.verse-column { padding: 12px; background: var(--vanilla); border-radius: 8px; border-left: 3px solid var(--rose); }
.verse-column h3 { font-size: 1rem; margin-bottom: 8px; color: var(--text); }
.verse-content { font-family: 'Georgia', serif; font-size: 1.1rem; line-height: 1.9; color: var(--text); min-height: 200px; text-align: justify; }
.verse-content p { margin-bottom: 12px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.2s; }
.verse-content p:hover { background: rgba(219,161,162,0.1); }
.verse-content p.highlighted { background: #fff3b0; }
.bible-chapter-nav-bottom { display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }
.bible-chapter-nav-bottom .btn { padding: 6px 16px; font-size: 0.85rem; }
#bibleChapterDisplay { font-weight: 600; color: var(--text); }

.bible-highlight-tooltip { position: fixed; display: none; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; box-shadow: var(--shadow-hover); z-index: 30; gap: 4px; align-items: center; }
.bible-highlight-tooltip.visible { display: flex; }
.bible-highlight-color { width: 20px; height: 20px; border-radius: 50%; border: 1px solid var(--border); cursor: pointer; transition: transform 0.2s; }
.bible-highlight-color:hover { transform: scale(1.15); }
.bible-highlight-color[data-color="yellow"] { background: #ffeb3b; }
.bible-highlight-color[data-color="green"] { background: #a5d6a7; }
.bible-highlight-color[data-color="blue"] { background: #90caf9; }
.bible-highlight-color[data-color="pink"] { background: #f48fb1; }
.bible-highlight-btn { background: none; border: none; cursor: pointer; color: var(--text); font-size: 0.9rem; padding: 0 4px; transition: color 0.2s; }
.bible-highlight-btn:hover { color: var(--rose); }

.bible-annotation-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 20px; box-shadow: var(--shadow-hover); z-index: 30; display: none; }
.bible-annotation-popup.visible { display: block; }
.bible-annotation-popup textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; resize: vertical; min-height: 60px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-annotation-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
.bible-annotation-actions button { padding: 4px 12px; border-radius: 6px; border: none; cursor: pointer; font-size: 0.8rem; }
.bible-annotation-save { background: var(--rose); color: white; }
.bible-annotation-cancel { background: var(--border); color: var(--text); }

.bible-search-bar { position: absolute; top: 56px; right: 16px; width: 320px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 12px; box-shadow: var(--shadow-hover); z-index: 15; display: none; }
.bible-search-bar.visible { display: block; }
.bible-search-bar input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.bible-search-bar input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.bible-search-bar #bibleSearchClose { position: absolute; top: 6px; right: 8px; background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 1rem; }
#bibleSearchResults { margin-top: 8px; max-height: 200px; overflow-y: auto; display: none; }
.bible-search-result { padding: 4px 8px; font-size: 0.85rem; border-bottom: 1px solid var(--border); cursor: pointer; }
.bible-search-result:hover { background: rgba(219,161,162,0.1); }

.bible-share-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 30; display: none; align-items: center; justify-content: center; }
.bible-share-modal.visible { display: flex; }
.bible-share-modal-content { background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 400px; width: 90%; text-align: center; }
.bible-share-modal-content h3 { margin-top: 0; }
.bible-share-options { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
.bible-share-options button { padding: 8px 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); cursor: pointer; transition: all 0.2s; font-size: 0.9rem; }
.bible-share-options button:hover { border-color: var(--rose); background: rgba(219,161,162,0.1); }
.bible-share-modal-close { background: var(--rose); color: white; border: none; padding: 8px 24px; border-radius: 30px; cursor: pointer; }

.bible-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 11; display: none; }
.bible-overlay.active { display: block; }

.bible-challenge-widget { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin: 8px 16px; box-shadow: var(--shadow); display: flex; flex-direction: column; gap: 6px; }
.bible-challenge-widget h4 { margin: 0; font-size: 1rem; }
.bible-challenge-widget p { margin: 0; font-size: 0.9rem; color: var(--text-light); }
.bible-challenge-progress { position: relative; height: 16px; background: var(--border); border-radius: 8px; overflow: hidden; }
.bible-challenge-bar { height: 100%; background: var(--rose); transition: width 0.3s; }
.bible-challenge-percent { position: absolute; top: 0; right: 8px; font-size: 0.7rem; font-weight: 600; color: var(--text); line-height: 16px; }
.bible-challenge-stats { font-weight: 600; font-size: 0.9rem; color: var(--text); }

.bible-reader.focus-mode .bible-reader-header { transform: translateY(-100%); opacity: 0; pointer-events: none; }
.bible-reader.focus-mode .bible-reader-settings { display: none !important; }
.bible-reader.focus-mode .bible-search-bar { display: none !important; }

/* ===== THEME VARIANTS ===== */
.bible-reader-container[data-theme="paper"] { --bg: #f5f0e1; --card-bg: #faf6ed; --text: #3d2b1f; --text-light: #6b4f3a; --border: #d4c5a9; --vanilla: #faf6ed; --input-bg: #f5f0e1; }
.bible-reader-container[data-theme="light"] { --bg: #fdfdfd; --card-bg: #ffffff; --text: #1a1a1a; --text-light: #666; --border: #e0e0e0; --vanilla: #fdf5e6; --input-bg: #f9f9f9; }
.bible-reader-container[data-theme="dark"] { --bg: #1a1a1a; --card-bg: #2a2a2a; --text: #e0e0e0; --text-light: #aaa; --border: #444; --vanilla: #2a2a2a; --input-bg: #333; }
.bible-reader-container[data-theme="sepia"] { --bg: #f4e8d1; --card-bg: #fdf5e6; --text: #4a3728; --text-light: #7a5a3a; --border: #d4c5a9; --vanilla: #fdf5e6; --input-bg: #f4e8d1; }

/* ===== FONT VARIANTS ===== */
.bible-reader-container[data-font="serif"] .verse-content { font-family: 'Georgia', serif; }
.bible-reader-container[data-font="sans"] .verse-content { font-family: 'Helvetica', 'Arial', sans-serif; }
.bible-reader-container[data-font="mono"] .verse-content { font-family: 'Courier New', monospace; }

/* ===== READING MODE ===== */
.bible-reader-container[data-mode="flip"] .bible-display { overflow: hidden; }
.bible-reader-container[data-mode="flip"] .verse-content { column-count: 1; column-gap: 2em; }
.bible-reader-container[data-mode="scroll"] .bible-display { overflow-y: auto; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .bible-controls { flex-direction: column; align-items: stretch; }
    .bible-control-group { min-width: auto; }
    .verse-container.parallel { grid-template-columns: 1fr; }
    .bible-control-group.toggle-group { align-items: center; }
    .bible-notes-panel { width: 100%; max-height: 50vh; border-radius: 0; }
    .bible-toc-drawer { width: 280px; right: -280px; }
    .bible-search-bar { width: 260px; right: 8px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>