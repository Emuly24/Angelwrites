<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$pageTitle = 'Bible Reader';
?>
<?php require_once 'includes/header.php'; ?>

<div class="bible-reader-wrapper">
    <div class="container">
        <div class="bible-header">
            <h1>📖 Bible Reader</h1>
            <p>Read entire chapters, compare translations, highlight, and take notes — all offline and free.</p>
        </div>

        <!-- Controls -->
        <div class="bible-controls">
            <div class="control-row">
                <div class="control-group">
                    <label>Book</label>
                    <select id="bookSelect"></select>
                </div>
                <div class="control-group">
                    <label>Chapter</label>
                    <select id="chapterSelect"></select>
                </div>
                <div class="control-group">
                    <label>Version 1</label>
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
                <div class="control-group">
                    <label>Version 2 (Parallel)</label>
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
            </div>

            <div class="control-row action-row">
                <div class="control-group">
                    <label>Go to</label>
                    <div class="input-group">
                        <input type="text" id="goToInput" placeholder="e.g. John 3" value="John 3">
                        <button id="goToBtn" class="btn btn-primary">Go</button>
                    </div>
                </div>
                <div class="control-group toggle-group">
                    <label class="toggle-label">
                        <input type="checkbox" id="parallelToggle">
                        <span class="toggle-track"></span>
                        <span class="toggle-text">Parallel Mode</span>
                    </label>
                </div>
                <div class="control-group">
                    <button id="prevChapterBtn" class="btn btn-secondary">◀ Prev</button>
                    <button id="nextChapterBtn" class="btn btn-secondary">Next ▶</button>
                </div>
                <div class="control-group">
                    <button id="copyBtn" class="btn btn-outline">📋 Copy</button>
                    <button id="highlightBtn" class="btn btn-outline">✨ Highlight</button>
                    <button id="notesBtn" class="btn btn-outline">📝 Notes</button>
                    <button id="themeToggle" class="btn btn-outline">🌓 Theme</button>
                </div>
            </div>
        </div>

        <!-- Reader Display -->
        <div class="bible-display">
            <div id="singleView" class="chapter-view">
                <h2 id="singleHeader">John 3</h2>
                <div id="singleContent" class="bible-text"></div>
            </div>

            <div id="parallelView" class="chapter-view parallel" style="display:none;">
                <div class="parallel-column">
                    <h3 id="parallelHeader1">KJV</h3>
                    <div id="parallelContent1" class="bible-text"></div>
                </div>
                <div class="parallel-column">
                    <h3 id="parallelHeader2">NIV</h3>
                    <div id="parallelContent2" class="bible-text"></div>
                </div>
            </div>
        </div>

        <!-- Notes Modal -->
        <div id="notesModal" class="modal" style="display:none;">
            <div class="modal-content">
                <h3>📝 Notes</h3>
                <p id="notesVerseRef">John 3</p>
                <textarea id="notesTextarea" rows="6" placeholder="Write your notes here..."></textarea>
                <div class="modal-actions">
                    <button id="saveNoteBtn" class="btn btn-primary">Save</button>
                    <button id="closeNotesBtn" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
    .bible-reader-wrapper {
        padding: 32px 0 60px;
        background: var(--bg);
        color: var(--text);
    }

    .bible-header {
        text-align: center;
        margin-bottom: 24px;
    }
    .bible-header h1 {
        font-size: 2.4rem;
        margin-bottom: 4px;
        color: var(--dark);
    }
    .bible-header p {
        color: var(--text-light);
        font-size: 1.05rem;
    }

    .bible-controls {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        margin-bottom: 24px;
    }

    .control-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
        margin-bottom: 12px;
    }
    .control-row:last-child {
        margin-bottom: 0;
    }

    .control-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 1;
        min-width: 120px;
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
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }
    .control-group select:focus,
    .control-group input:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }

    .input-group {
        display: flex;
        gap: 8px;
    }
    .input-group input {
        flex: 1;
        min-width: 100px;
    }

    .action-row {
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
    }

    .toggle-group {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
    }
    .toggle-label {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-weight: 500;
        font-size: 0.9rem;
        color: var(--text);
    }
    .toggle-label input[type="checkbox"] {
        display: none;
    }
    .toggle-track {
        display: inline-block;
        width: 44px;
        height: 24px;
        background: var(--border);
        border-radius: 12px;
        position: relative;
        transition: background 0.3s;
    }
    .toggle-track::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 20px;
        height: 20px;
        background: white;
        border-radius: 50%;
        transition: transform 0.3s;
    }
    .toggle-label input:checked + .toggle-track {
        background: var(--rose);
    }
    .toggle-label input:checked + .toggle-track::after {
        transform: translateX(20px);
    }

    .bible-display {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        min-height: 400px;
    }

    .chapter-view h2 {
        font-family: 'Playfair Display', serif;
        font-size: 1.8rem;
        margin-bottom: 16px;
        color: var(--dark);
        text-align: center;
    }

    .chapter-view.parallel {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .parallel-column {
        padding: 16px;
        background: var(--fantasy);
        border-radius: 12px;
        border-top: 4px solid var(--rose);
    }
    .parallel-column h3 {
        font-family: 'Playfair Display', serif;
        font-size: 1.2rem;
        margin-bottom: 12px;
        color: var(--dark);
    }

    .bible-text {
        font-family: 'Georgia', serif;
        font-size: 1.05rem;
        line-height: 1.9;
        color: var(--text);
    }
    .bible-text p {
        margin-bottom: 8px;
        padding: 4px 8px;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .bible-text p:hover {
        background: rgba(219, 161, 162, 0.1);
    }
    .bible-text p.highlighted {
        background: #fff3b0;
        border-left: 4px solid var(--rose);
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
        font-size: 1.4rem;
        margin-bottom: 8px;
        color: var(--dark);
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

    .btn-primary {
        background: var(--rose);
        color: white;
    }
    .btn-primary:hover {
        background: var(--rose-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(219, 161, 162, 0.3);
    }

    .btn-secondary {
        background: var(--dark);
        color: white;
    }
    .btn-secondary:hover {
        background: #1e1414;
        transform: translateY(-2px);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text);
    }
    .btn-outline:hover {
        border-color: var(--rose);
        color: var(--rose);
        transform: translateY(-2px);
    }

    @media (max-width: 768px) {
        .control-row {
            flex-direction: column;
        }
        .control-group {
            min-width: auto;
        }
        .chapter-view.parallel {
            grid-template-columns: 1fr;
        }
        .action-row {
            flex-direction: column;
            align-items: stretch;
        }
        .input-group {
            flex-direction: column;
        }
        .toggle-group {
            align-self: center;
        }
    }
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
    (function() {
        'use strict';

        // ===== BOOK & CHAPTER DATA =====
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

        // ===== STATE =====
        let state = {
            book: 'John',
            chapter: 3,
            version1: 'KJV',
            version2: 'NIV',
            parallel: false,
            theme: localStorage.getItem('readerTheme') || 'light'
        };

        // ===== DOM REFS =====
        const bookSelect = document.getElementById('bookSelect');
        const chapterSelect = document.getElementById('chapterSelect');
        const version1Select = document.getElementById('version1');
        const version2Select = document.getElementById('version2');
        const parallelToggle = document.getElementById('parallelToggle');
        const goToInput = document.getElementById('goToInput');
        const goToBtn = document.getElementById('goToBtn');
        const prevBtn = document.getElementById('prevChapterBtn');
        const nextBtn = document.getElementById('nextChapterBtn');
        const copyBtn = document.getElementById('copyBtn');
        const highlightBtn = document.getElementById('highlightBtn');
        const notesBtn = document.getElementById('notesBtn');
        const themeToggle = document.getElementById('themeToggle');
        const singleView = document.getElementById('singleView');
        const singleContent = document.getElementById('singleContent');
        const singleHeader = document.getElementById('singleHeader');
        const parallelView = document.getElementById('parallelView');
        const parallelContent1 = document.getElementById('parallelContent1');
        const parallelContent2 = document.getElementById('parallelContent2');
        const parallelHeader1 = document.getElementById('parallelHeader1');
        const parallelHeader2 = document.getElementById('parallelHeader2');
        const notesModal = document.getElementById('notesModal');
        const notesTextarea = document.getElementById('notesTextarea');
        const notesVerseRef = document.getElementById('notesVerseRef');
        const saveNoteBtn = document.getElementById('saveNoteBtn');
        const closeNotesBtn = document.getElementById('closeNotesBtn');

        // ===== INIT =====
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

        function loadChapter() {
            const book = state.book;
            const chapter = state.chapter;
            const version1 = state.version1;
            const version2 = state.version2;
            const isParallel = state.parallel;

            // Update UI
            bookSelect.value = book;
            chapterSelect.value = chapter;
            version1Select.value = version1;
            version2Select.value = version2;
            goToInput.value = `${book} ${chapter}`;
            singleHeader.textContent = `${book} ${chapter}`;
            parallelHeader1.textContent = version1;
            parallelHeader2.textContent = version2;

            // Show loading
            if (isParallel) {
                parallelView.style.display = 'grid';
                singleView.style.display = 'none';
                parallelContent1.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
                parallelContent2.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
            } else {
                parallelView.style.display = 'none';
                singleView.style.display = 'block';
                singleContent.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
            }

            // Fetch data
            if (isParallel) {
                Promise.all([
                    fetch(`/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&version=${version1}`),
                    fetch(`/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&version=${version2}`)
                ])
                .then(([res1, res2]) => Promise.all([res1.json(), res2.json()]))
                .then(([data1, data2]) => {
                    if (data1.success && data2.success) {
                        parallelContent1.innerHTML = data1.data.map(v => `<p data-verse="${v.verse}">${v.verse}. ${v.text}</p>`).join('');
                        parallelContent2.innerHTML = data2.data.map(v => `<p data-verse="${v.verse}">${v.verse}. ${v.text}</p>`).join('');
                        applyHighlights(parallelContent1);
                        applyHighlights(parallelContent2);
                    } else {
                        parallelContent1.innerHTML = '<p style="color:red;">Error loading chapter.</p>';
                        parallelContent2.innerHTML = '<p style="color:red;">Error loading chapter.</p>';
                    }
                });
            } else {
                fetch(`/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&version=${version1}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        singleContent.innerHTML = data.data.map(v => `<p data-verse="${v.verse}">${v.verse}. ${v.text}</p>`).join('');
                        applyHighlights(singleContent);
                    } else {
                        singleContent.innerHTML = '<p style="color:red;">Error loading chapter.</p>';
                    }
                });
            }
        }

        function applyHighlights(container) {
            const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
            container.querySelectorAll('p[data-verse]').forEach(p => {
                const key = `${state.book}-${state.chapter}-${p.dataset.verse}`;
                if (saved[key]) {
                    p.classList.add('highlighted');
                } else {
                    p.classList.remove('highlighted');
                }
            });
        }

        function toggleHighlight() {
            const selection = window.getSelection();
            if (!selection.rangeCount) return;
            const p = selection.anchorNode?.parentElement?.closest('p[data-verse]');
            if (!p) return;
            const key = `${state.book}-${state.chapter}-${p.dataset.verse}`;
            const saved = JSON.parse(localStorage.getItem('bibleHighlights') || '{}');
            if (saved[key]) {
                delete saved[key];
                p.classList.remove('highlighted');
            } else {
                saved[key] = true;
                p.classList.add('highlighted');
            }
            localStorage.setItem('bibleHighlights', JSON.stringify(saved));
        }

        function copyChapter() {
            const container = state.parallel ? parallelContent1 : singleContent;
            const text = container.textContent.trim();
            const header = state.parallel ? `[${state.version1}] ${state.book} ${state.chapter}` : `${state.book} ${state.chapter}`;
            navigator.clipboard.writeText(`${header}\n\n${text}`).then(() => {
                alert('Chapter copied to clipboard!');
            });
        }

        function openNotes() {
            const key = `${state.book}-${state.chapter}`;
            const saved = JSON.parse(localStorage.getItem('bibleNotes') || '{}');
            notesTextarea.value = saved[key] || '';
            notesVerseRef.textContent = `${state.book} ${state.chapter}`;
            notesModal.style.display = 'flex';
        }

        function saveNote() {
            const key = `${state.book}-${state.chapter}`;
            const saved = JSON.parse(localStorage.getItem('bibleNotes') || '{}');
            saved[key] = notesTextarea.value;
            localStorage.setItem('bibleNotes', JSON.stringify(saved));
            notesModal.style.display = 'none';
            alert('Note saved!');
        }

        function toggleTheme() {
            state.theme = state.theme === 'light' ? 'dark' : 'light';
            localStorage.setItem('readerTheme', state.theme);
            document.querySelector('.bible-reader-wrapper').setAttribute('data-theme', state.theme);
        }

        function goTo(input) {
            const match = input.match(/^([\d\s\w]+)\s+(\d+)$/i);
            if (match) {
                const book = match[1].trim();
                const chapter = parseInt(match[2], 10);
                let found = BOOKS.find(b => b.toLowerCase() === book.toLowerCase());
                if (!found) {
                    found = BOOKS.find(b => b.toLowerCase().includes(book.toLowerCase()));
                }
                if (found && chapter >= 1 && chapter <= (CHAPTER_COUNTS[found] || 21)) {
                    state.book = found;
                    state.chapter = chapter;
                    populateChapters();
                    loadChapter();
                    return;
                }
            }
            alert('Invalid format. Use: Book Chapter (e.g., John 3)');
        }

        // ===== EVENTS =====
        populateBooks();
        populateChapters();
        loadChapter();

        bookSelect.addEventListener('change', function() {
            state.book = this.value;
            state.chapter = 1;
            populateChapters();
            loadChapter();
        });

        chapterSelect.addEventListener('change', function() {
            state.chapter = parseInt(this.value, 10);
            loadChapter();
        });

        version1Select.addEventListener('change', function() {
            state.version1 = this.value;
            loadChapter();
        });

        version2Select.addEventListener('change', function() {
            state.version2 = this.value;
            if (state.parallel) loadChapter();
        });

        parallelToggle.addEventListener('change', function() {
            state.parallel = this.checked;
            loadChapter();
        });

        goToBtn.addEventListener('click', function() {
            goTo(goToInput.value);
        });
        goToInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') goTo(goToInput.value);
        });

        prevBtn.addEventListener('click', function() {
            const idx = BOOKS.indexOf(state.book);
            if (state.chapter > 1) {
                state.chapter--;
            } else if (idx > 0) {
                state.book = BOOKS[idx - 1];
                state.chapter = CHAPTER_COUNTS[state.book] || 21;
                populateChapters();
            }
            loadChapter();
        });

        nextBtn.addEventListener('click', function() {
            const idx = BOOKS.indexOf(state.book);
            const max = CHAPTER_COUNTS[state.book] || 21;
            if (state.chapter < max) {
                state.chapter++;
            } else if (idx < BOOKS.length - 1) {
                state.book = BOOKS[idx + 1];
                state.chapter = 1;
                populateChapters();
            }
            loadChapter();
        });

        copyBtn.addEventListener('click', copyChapter);
        highlightBtn.addEventListener('click', toggleHighlight);
        notesBtn.addEventListener('click', openNotes);
        saveNoteBtn.addEventListener('click', saveNote);
        closeNotesBtn.addEventListener('click', function() {
            notesModal.style.display = 'none';
        });
        themeToggle.addEventListener('click', toggleTheme);

        // Click on verse to highlight
        document.addEventListener('click', function(e) {
            if (e.target.closest('.bible-text p[data-verse]')) {
                toggleHighlight();
            }
        });

        // Close modal on outside click
        window.addEventListener('click', function(e) {
            if (e.target === notesModal) notesModal.style.display = 'none';
        });

        // Init theme
        document.querySelector('.bible-reader-wrapper').setAttribute('data-theme', state.theme);
    })();
</script>

<?php require_once 'includes/footer.php'; ?>