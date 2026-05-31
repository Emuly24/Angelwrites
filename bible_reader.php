<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$pageTitle = 'Bible Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="bible-reader-page" id="bibleReader">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>📖 Bible Reader</h1>
            <p>Read, copy, compare, highlight, and take notes — all offline and completely free.</p>
        </div>

        <!-- Reader Controls -->
        <div class="reader-controls">
            <!-- Translation 1 -->
            <div class="control-group">
                <label for="version1">Version 1</label>
                <select id="version1">
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

            <!-- Parallel Toggle -->
            <div class="control-group toggle-group">
                <label for="parallelMode">
                    <input type="checkbox" id="parallelMode">
                    <span>🔀 Parallel Mode</span>
                </label>
            </div>

            <!-- Translation 2 (parallel) -->
            <div class="control-group" id="version2Group" style="display:none;">
                <label for="version2">Version 2</label>
                <select id="version2">
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

            <!-- Navigation -->
            <div class="control-group">
                <label for="bookSelect">Book</label>
                <select id="bookSelect"></select>
            </div>
            <div class="control-group">
                <label for="chapterSelect">Chapter</label>
                <select id="chapterSelect"></select>
            </div>
            <div class="control-group">
                <label for="verseSelect">Verse</label>
                <select id="verseSelect"></select>
            </div>

            <!-- Action Buttons with descriptions -->
            <div class="control-group action-group">
                <button id="prevChapterBtn" class="btn btn-secondary btn-sm" title="Previous Chapter">◀ Prev</button>
                <button id="nextChapterBtn" class="btn btn-secondary btn-sm" title="Next Chapter">Next ▶</button>
            </div>
            <div class="control-group action-group">
                <button id="goToBtn" class="btn btn-primary btn-sm">Go</button>
                <input type="text" id="goToInput" placeholder="e.g. John 3:16" value="John 3:16">
            </div>
            <div class="control-group action-group">
                <button id="copyBtn" class="btn btn-secondary btn-sm" title="Copy this verse">📋 Copy</button>
                <button id="highlightBtn" class="btn btn-secondary btn-sm" title="Toggle highlight for this verse">🖌️ Highlight</button>
                <button id="notesBtn" class="btn btn-secondary btn-sm" title="Add a note to this verse">📝 Notes</button>
                <button id="readerThemeToggle" class="btn btn-secondary btn-sm" title="Toggle light/dark theme">🌓 Theme</button>
            </div>
        </div>

        <!-- Reader Display -->
        <div class="reader-display">
            <!-- Single version display -->
            <div id="singleView" class="verse-container">
                <div class="verse-content" id="verseContent1"></div>
            </div>

            <!-- Parallel display (two columns) -->
            <div id="parallelView" class="verse-container parallel" style="display:none;">
                <div class="verse-column">
                    <h3 id="parallelTitle1">KJV</h3>
                    <div class="verse-content" id="verseContent1p"></div>
                </div>
                <div class="verse-column">
                    <h3 id="parallelTitle2">NIV</h3>
                    <div class="verse-content" id="verseContent2p"></div>
                </div>
            </div>

            <!-- Chapter navigation buttons (bottom) -->
            <div class="chapter-nav-bottom">
                <button id="prevChapterBtn2" class="btn btn-secondary btn-sm">◀ Prev Chapter</button>
                <span id="chapterDisplay"></span>
                <button id="nextChapterBtn2" class="btn btn-secondary btn-sm">Next Chapter ▶</button>
            </div>

            <!-- Notes Modal -->
            <div id="notesModal" class="modal" style="display:none;">
                <div class="modal-content">
                    <h3>📝 Notes for <span id="notesVerseRef"></span></h3>
                    <textarea id="notesTextarea" rows="6" placeholder="Write your notes here..."></textarea>
                    <div class="modal-actions">
                        <button id="saveNoteBtn" class="btn btn-primary">Save Note</button>
                        <button id="closeNotesBtn" class="btn btn-secondary">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
    .bible-reader-page {
        padding: 32px 0 60px;
        background: var(--bg);
        color: var(--text);
        transition: background var(--transition), color var(--transition);
    }

    .page-header {
        text-align: center;
        margin-bottom: 24px;
    }
    .page-header h1 {
        font-size: 2.2rem;
        margin-bottom: 4px;
        color: var(--dark);
    }
    .page-header p {
        color: var(--text-light);
    }

    .reader-controls {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: flex-end;
        padding: 16px;
        background: var(--card-bg);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        margin-bottom: 24px;
    }

    .control-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 100px;
        flex: 1;
    }
    .control-group label {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .control-group select,
    .control-group input {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.9rem;
    }
    .control-group select:focus,
    .control-group input:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }

    /* Action buttons group */
    .action-group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        flex-direction: row;
        min-width: auto;
    }
    .action-group .btn-sm {
        padding: 4px 10px;
        font-size: 0.75rem;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .toggle-group {
        flex: 0 0 auto;
        justify-content: center;
    }
    .toggle-group label {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        font-weight: 500;
        font-size: 0.9rem;
        color: var(--text);
    }
    .toggle-group input[type="checkbox"] {
        appearance: none;
        -webkit-appearance: none;
        width: 40px;
        height: 22px;
        background: var(--border);
        border-radius: 11px;
        cursor: pointer;
        transition: background 0.3s;
        position: relative;
        flex-shrink: 0;
    }
    .toggle-group input[type="checkbox"]:checked {
        background: var(--rose);
    }
    .toggle-group input[type="checkbox"]::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 18px;
        height: 18px;
        background: white;
        border-radius: 50%;
        transition: transform 0.3s;
    }
    .toggle-group input[type="checkbox"]:checked::after {
        transform: translateX(18px);
    }

    .reader-display {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 24px;
        border: 2px solid var(--rose); /* Brand color border */
        box-shadow: var(--shadow);
        min-height: 400px;
    }

    .verse-container {
        position: relative;
    }
    .verse-container.parallel {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .verse-column {
        padding: 12px;
        background: var(--fantasy);
        border-radius: 8px;
        border-left: 3px solid var(--rose);
    }
    .verse-column h3 {
        font-size: 1rem;
        margin-bottom: 8px;
        color: var(--text);
    }

    .verse-content {
    font-family: 'Georgia', serif;
    font-size: 1.1rem;
    line-height: 1.9;
    color: var(--text);
    min-height: 200px;
    text-align: justify; /* Justified text */
}

    .verse-content p {
        margin-bottom: 12px;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 4px;
        transition: background 0.2s;
    }
    .verse-content p:hover {
        background: rgba(219, 161, 162, 0.1);
    }
    .verse-content p.highlighted {
        background: #fff3b0;
    }

    .chapter-nav-bottom {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
        padding-top: 16px;
        border-top: 1px solid var(--border);
    }
    .chapter-nav-bottom .btn {
        padding: 6px 16px;
        font-size: 0.85rem;
    }
    #chapterDisplay {
        font-weight: 600;
        color: var(--text);
    }

    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }
    .modal-content {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 32px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .modal-content h3 {
        margin-bottom: 12px;
    }
    .modal-content textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        resize: vertical;
        min-height: 80px;
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.95rem;
    }
    .modal-content textarea:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 12px;
    }
    .modal-actions .btn {
        flex: 1;
        justify-content: center;
        padding: 10px;
    }

    @media (max-width: 768px) {
        .reader-controls {
            flex-direction: column;
            align-items: stretch;
        }
        .control-group {
            min-width: auto;
        }
        .verse-container.parallel {
            grid-template-columns: 1fr;
        }
        .toggle-group {
            align-items: center;
        }
    }
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
    (function() {
        'use strict';

        // ===== CONFIGURATION =====
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

        // ===== STATE =====
        let state = {
            book: 'John',
            chapter: 3,
            verse: 16,
            version1: 'KJV',
            version2: 'NIV',
            parallel: false,
            readerTheme: localStorage.getItem('readerTheme') || 'light'
        };

        // ===== DOM REFS =====
        const bookSelect = document.getElementById('bookSelect');
        const chapterSelect = document.getElementById('chapterSelect');
        const verseSelect = document.getElementById('verseSelect');
        const version1Select = document.getElementById('version1');
        const version2Select = document.getElementById('version2');
        const version2Group = document.getElementById('version2Group');
        const parallelToggle = document.getElementById('parallelMode');
        const singleView = document.getElementById('singleView');
        const parallelView = document.getElementById('parallelView');
        const verseContent1 = document.getElementById('verseContent1');
        const verseContent1p = document.getElementById('verseContent1p');
        const verseContent2p = document.getElementById('verseContent2p');
        const parallelTitle1 = document.getElementById('parallelTitle1');
        const parallelTitle2 = document.getElementById('parallelTitle2');
        const chapterDisplay = document.getElementById('chapterDisplay');
        const goToInput = document.getElementById('goToInput');
        const goToBtn = document.getElementById('goToBtn');
        const prevBtn1 = document.getElementById('prevChapterBtn');
        const nextBtn1 = document.getElementById('nextChapterBtn');
        const prevBtn2 = document.getElementById('prevChapterBtn2');
        const nextBtn2 = document.getElementById('nextChapterBtn2');
        const copyBtn = document.getElementById('copyBtn');
        const highlightBtn = document.getElementById('highlightBtn');
        const notesBtn = document.getElementById('notesBtn');
        const notesModal = document.getElementById('notesModal');
        const notesTextarea = document.getElementById('notesTextarea');
        const notesVerseRef = document.getElementById('notesVerseRef');
        const saveNoteBtn = document.getElementById('saveNoteBtn');
        const closeNotesBtn = document.getElementById('closeNotesBtn');
        const readerThemeToggle = document.getElementById('readerThemeToggle');

        // ===== HELPER FUNCTIONS =====

        function populateBooks() {
            bookSelect.innerHTML = '';
            BOOKS.forEach(book => {
                const opt = document.createElement('option');
                opt.value = book;
                opt.textContent = book;
                bookSelect.appendChild(opt);
            });
            bookSelect.value = state.book;
        }

        function populateChapters() {
            chapterSelect.innerHTML = '';
            const count = CHAPTER_COUNTS[state.book] || 21;
            for (let i = 1; i <= count; i++) {
                const opt = document.createElement('option');
                opt.value = i;
                opt.textContent = `Chapter ${i}`;
                chapterSelect.appendChild(opt);
            }
            chapterSelect.value = state.chapter;
        }

        function populateVerses() {
            verseSelect.innerHTML = '';
            const count = VERSE_COUNTS[state.book] || 30;
            for (let i = 1; i <= count; i++) {
                const opt = document.createElement('option');
                opt.value = i;
                opt.textContent = `Verse ${i}`;
                verseSelect.appendChild(opt);
            }
            verseSelect.value = state.verse;
        }

        function getVerseText(version, book, chapter, verse) {
    return fetch(`/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&verse=${verse}&version=${version}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                return data.data[0].text;
            } else {
                return `[${version} ${book} ${chapter}:${verse} not found]`;
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            return `[Error loading verse]`;
        });
}
        function renderVerse() {
            const book = state.book;
            const chapter = state.chapter;
            const verse = state.verse;

            if (state.parallel) {
                parallelView.style.display = 'grid';
                singleView.style.display = 'none';
                parallelTitle1.textContent = state.version1;
                parallelTitle2.textContent = state.version2;

                verseContent1p.innerHTML = '<p>Loading...</p>';
                verseContent2p.innerHTML = '<p>Loading...</p>';

                Promise.all([
                    getVerseText(state.version1, book, chapter, verse),
                    getVerseText(state.version2, book, chapter, verse)
                ]).then(([text1, text2]) => {
                    verseContent1p.innerHTML = `<p>${text1}</p>`;
                    verseContent2p.innerHTML = `<p>${text2}</p>`;
                    applyHighlights(verseContent1p);
                    applyHighlights(verseContent2p);
                });
            } else {
                parallelView.style.display = 'none';
                singleView.style.display = 'block';
                verseContent1.innerHTML = '<p>Loading...</p>';

                getVerseText(state.version1, book, chapter, verse).then(text => {
                    verseContent1.innerHTML = `<p>${text}</p>`;
                    applyHighlights(verseContent1);
                });
            }

            chapterDisplay.textContent = `${book} ${chapter}:${verse}`;
            notesVerseRef.textContent = `${book} ${chapter}:${verse}`;
        }

        function loadVerse() {
            bookSelect.value = state.book;
            chapterSelect.value = state.chapter;
            verseSelect.value = state.verse;
            version1Select.value = state.version1;
            version2Select.value = state.version2;
            renderVerse();
        }

        function applyHighlights(container) {
            const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
            const p = container.querySelector('p');
            if (p) {
                const key = `${state.book}-${state.chapter}-${state.verse}`;
                if (saved[key]) {
                    p.classList.add('highlighted');
                } else {
                    p.classList.remove('highlighted');
                }
            }
        }

        function toggleHighlight() {
            const key = `${state.book}-${state.chapter}-${state.verse}`;
            const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
            if (saved[key]) {
                delete saved[key];
            } else {
                saved[key] = true;
            }
            localStorage.setItem('bibleHighlights', JSON.stringify(saved));
            renderVerse();
        }

        function copyVerse() {
            const book = state.book;
            const chapter = state.chapter;
            const verse = state.verse;
            const version = state.version1;
            getVerseText(version, book, chapter, verse).then(text => {
                const content = `${version} ${book} ${chapter}:${verse}\n${text}`;
                navigator.clipboard.writeText(content).then(() => {
                    alert('Verse copied to clipboard!');
                }).catch(() => {
                    prompt('Copy manually:', content);
                });
            });
        }

        function openNotes() {
            const key = `${state.book}-${state.chapter}-${state.verse}`;
            const saved = JSON.parse(localStorage.getItem('bibleNotes') || '{}');
            notesTextarea.value = saved[key] || '';
            notesModal.style.display = 'flex';
        }

        function saveNote() {
            const key = `${state.book}-${state.chapter}-${state.verse}`;
            const saved = JSON.parse(localStorage.getItem('bibleNotes') || '{}');
            saved[key] = notesTextarea.value;
            localStorage.setItem('bibleNotes', JSON.stringify(saved));
            notesModal.style.display = 'none';
            alert('Note saved!');
        }

        function toggleReaderTheme() {
            state.readerTheme = state.readerTheme === 'light' ? 'dark' : 'light';
            localStorage.setItem('readerTheme', state.readerTheme);
            document.getElementById('bibleReader').setAttribute('data-theme', state.readerTheme);
            readerThemeToggle.textContent = state.readerTheme === 'light' ? '🌓 Theme' : '☀️ Theme';
        }

        function goToVerse(input) {
            const match = input.match(/^([\d\s\w]+)\s+(\d+):(\d+)$/i);
            if (match) {
                const book = match[1].trim();
                const chapter = parseInt(match[2], 10);
                const verse = parseInt(match[3], 10);
                let found = BOOKS.find(b => b.toLowerCase() === book.toLowerCase());
                if (!found) {
                    found = BOOKS.find(b => b.toLowerCase().includes(book.toLowerCase()));
                }
                if (found) {
                    state.book = found;
                    state.chapter = chapter;
                    state.verse = verse;
                    populateChapters();
                    populateVerses();
                    loadVerse();
                    return;
                }
            }
            alert('Invalid verse format. Use: Book Chapter:Verse (e.g. John 3:16)');
        }

        // ===== EVENT LISTENERS =====

        populateBooks();
        populateChapters();
        populateVerses();
        loadVerse();

        document.getElementById('bibleReader').setAttribute('data-theme', state.readerTheme);
        readerThemeToggle.textContent = state.readerTheme === 'light' ? '🌓 Theme' : '☀️ Theme';

        bookSelect.addEventListener('change', function() {
            state.book = this.value;
            state.chapter = 1;
            state.verse = 1;
            populateChapters();
            populateVerses();
            loadVerse();
        });

        chapterSelect.addEventListener('change', function() {
            state.chapter = parseInt(this.value, 10);
            state.verse = 1;
            populateVerses();
            loadVerse();
        });

        verseSelect.addEventListener('change', function() {
            state.verse = parseInt(this.value, 10);
            loadVerse();
        });

        version1Select.addEventListener('change', function() {
            state.version1 = this.value;
            loadVerse();
        });

        version2Select.addEventListener('change', function() {
            state.version2 = this.value;
            if (state.parallel) loadVerse();
        });

        parallelToggle.addEventListener('change', function() {
            state.parallel = this.checked;
            version2Group.style.display = state.parallel ? 'block' : 'none';
            loadVerse();
        });

        function prevChapter() {
            const book = state.book;
            const chapter = state.chapter;
            if (chapter > 1) {
                state.chapter = chapter - 1;
                state.verse = 1;
                populateVerses();
                loadVerse();
            } else {
                const idx = BOOKS.indexOf(book);
                if (idx > 0) {
                    state.book = BOOKS[idx - 1];
                    state.chapter = CHAPTER_COUNTS[state.book] || 21;
                    state.verse = 1;
                    populateChapters();
                    populateVerses();
                    loadVerse();
                }
            }
        }

        function nextChapter() {
            const book = state.book;
            const chapter = state.chapter;
            const max = CHAPTER_COUNTS[book] || 21;
            if (chapter < max) {
                state.chapter = chapter + 1;
                state.verse = 1;
                populateVerses();
                loadVerse();
            } else {
                const idx = BOOKS.indexOf(book);
                if (idx < BOOKS.length - 1) {
                    state.book = BOOKS[idx + 1];
                    state.chapter = 1;
                    state.verse = 1;
                    populateChapters();
                    populateVerses();
                    loadVerse();
                }
            }
        }

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
        notesBtn.addEventListener('click', openNotes);
        saveNoteBtn.addEventListener('click', saveNote);
        closeNotesBtn.addEventListener('click', function() {
            notesModal.style.display = 'none';
        });
        window.addEventListener('click', function(e) {
            if (e.target === notesModal) notesModal.style.display = 'none';
        });
        readerThemeToggle.addEventListener('click', toggleReaderTheme);
        document.addEventListener('click', function(e) {
            if (e.target.closest('.verse-content p')) {
                toggleHighlight();
            }
        });

        function syncGoToInput() {
            goToInput.value = `${state.book} ${state.chapter}:${state.verse}`;
        }
        const origLoad = loadVerse;
        loadVerse = function() {
            origLoad();
            syncGoToInput();
        };

    })();
</script>

<?php require_once 'includes/footer.php'; ?>