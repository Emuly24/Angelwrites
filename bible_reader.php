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
            <p>Read entire chapters, compare translations, highlight, and take notes.</p>
        </div>

        <!-- Unified Control Bar -->
        <div class="control-bar">
            <!-- Navigation -->
            <div class="nav-group">
                <button id="prevChapterBtn" class="btn btn-secondary btn-icon" title="Previous Chapter">◀</button>
                <div class="select-group">
                    <select id="bookSelect"></select>
                    <select id="chapterSelect"></select>
                </div>
                <button id="nextChapterBtn" class="btn btn-secondary btn-icon" title="Next Chapter">▶</button>
            </div>

            <!-- Go to -->
            <div class="go-group">
                <input type="text" id="goToInput" placeholder="John 3" value="John 3">
                <button id="goToBtn" class="btn btn-primary">Go</button>
            </div>

            <!-- Versions & Tools -->
            <div class="tools-group">
                <select id="version1">
                    <option value="KJV">KJV</option>
                    <option value="NIV">NIV</option>
                    <option value="ESV">ESV</option>
                    <option value="NASB">NASB</option>
                    <option value="NKJV">NKJV</option>
                    <option value="AMP">AMP</option>
                    <option value="ASV">ASV</option>
                    <option value="WEB">WEB</option>
                    <option value="YLT">YLT</option>
                </select>
                
                <div class="toggle-container">
                    <input type="checkbox" id="parallelToggle">
                    <label for="parallelToggle">Parallel</label>
                </div>

                <div id="version2Container" style="display:none;">
                    <select id="version2">
                        <option value="NIV">NIV</option>
                        <option value="KJV">KJV</option>
                        <option value="ESV">ESV</option>
                        <option value="NASB">NASB</option>
                        <option value="NKJV">NKJV</option>
                        <option value="AMP">AMP</option>
                        <option value="ASV">ASV</option>
                        <option value="WEB">WEB</option>
                        <option value="YLT">YLT</option>
                    </select>
                </div>

                <button id="copyBtn" class="btn btn-outline btn-icon" title="Copy Chapter">📋</button>
                <button id="highlightBtn" class="btn btn-outline btn-icon" title="Highlight Verse">✨</button>
                <button id="notesBtn" class="btn btn-outline btn-icon" title="Chapter Notes">📝</button>
                <button id="themeToggle" class="btn btn-outline btn-icon" title="Toggle Theme">🌓</button>
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
                <h3>📝 Chapter Notes</h3>
                <p id="notesChapterRef">John 3</p>
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
        font-size: 2.2rem;
        margin-bottom: 4px;
        color: var(--dark);
    }
    .bible-header p {
        color: var(--text-light);
    }

    /* ===== CONTROL BAR ===== */
    .control-bar {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 16px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        margin-bottom: 24px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .nav-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .nav-group .select-group {
        display: flex;
        gap: 6px;
    }
    .nav-group select {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.9rem;
    }

    .go-group {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .go-group input {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.9rem;
        width: 120px;
    }

    .tools-group {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .tools-group select {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid var(--border);
        background: var(--input-bg);
        color: var(--text);
        font-size: 0.9rem;
    }

    .toggle-container {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .toggle-container input[type="checkbox"] {
        accent-color: var(--rose);
    }

    .btn-icon {
        width: 34px;
        height: 34px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
    }
    .btn-secondary {
        background: var(--dark);
        color: white;
    }
    .btn-secondary:hover {
        background: #1e1414;
        transform: translateY(-2px);
    }
    .btn-primary {
        background: var(--rose);
        color: white;
    }
    .btn-primary:hover {
        background: var(--rose-dark);
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
    }

    /* ===== DISPLAY ===== */
    .bible-display {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        min-height: 300px;
    }

    .chapter-view h2 {
        font-family: 'Playfair Display', serif;
        font-size: 1.6rem;
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
        border-radius: 8px;
        border-top: 4px solid var(--rose);
    }
    .parallel-column h3 {
        font-size: 1rem;
        margin-bottom: 8px;
        color: var(--dark);
    }

    .bible-text {
        font-family: 'Georgia', serif;
        font-size: 1rem;
        line-height: 1.8;
        color: var(--text);
    }
    .bible-text p {
        margin-bottom: 6px;
        padding: 2px 6px;
        border-radius: 3px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .bible-text p:hover {
        background: rgba(219, 161, 162, 0.1);
    }
    .bible-text p.highlighted {
        background: #fff3b0;
        border-left: 3px solid var(--rose);
    }

    /* ===== MODAL ===== */
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
        max-width: 480px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .modal-content h3 {
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
    }

    @media (max-width: 768px) {
        .control-bar {
            flex-direction: column;
            align-items: stretch;
        }
        .nav-group {
            justify-content: center;
        }
        .nav-group .select-group {
            flex-direction: column;
        }
        .go-group {
            justify-content: center;
        }
        .tools-group {
            justify-content: center;
        }
        .chapter-view.parallel {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ===== DATA =====
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
            parallel: false
        };

        // ===== DOM REFS =====
        const bookSelect = document.getElementById('bookSelect');
        const chapterSelect = document.getElementById('chapterSelect');
        const version1Select = document.getElementById('version1');
        const version2Select = document.getElementById('version2');
        const version2Container = document.getElementById('version2Container');
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
        const notesChapterRef = document.getElementById('notesChapterRef');
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

            bookSelect.value = book;
            chapterSelect.value = chapter;
            version1Select.value = version1;
            version2Select.value = version2;
            goToInput.value = `${book} ${chapter}`;

            if (isParallel) {
                parallelView.style.display = 'grid';
                singleView.style.display = 'none';
                parallelHeader1.textContent = version1;
                parallelHeader2.textContent = version2;
                parallelContent1.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
                parallelContent2.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
                fetchChapter(book, chapter, version1, parallelContent1);
                fetchChapter(book, chapter, version2, parallelContent2);
            } else {
                parallelView.style.display = 'none';
                singleView.style.display = 'block';
                singleHeader.textContent = `${book} ${chapter}`;
                singleContent.innerHTML = '<p style="text-align:center;color:var(--text-light);">Loading...</p>';
                fetchChapter(book, chapter, version1, singleContent);
            }
        }

        function fetchChapter(book, chapter, version, container) {
            fetch(`/includes/bible_lookup.php?book=${encodeURIComponent(book)}&chapter=${chapter}&version=${version}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    container.innerHTML = data.data.map(v => `<p data-verse="${v.verse}">${v.verse}. ${v.text}</p>`).join('');
                    applyHighlights(container);
                } else {
                    container.innerHTML = `<p style="color:red;">${data.error}</p>`;
                }
            })
            .catch(err => {
                container.innerHTML = `<p style="color:red;">Error loading chapter.</p>`;
            });
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
            notesChapterRef.textContent = `${state.book} ${state.chapter}`;
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
            version2Container.style.display = state.parallel ? 'inline-block' : 'none';
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

        // Click verse to highlight
        document.addEventListener('click', function(e) {
            if (e.target.closest('.bible-text p[data-verse]')) {
                toggleHighlight();
            }
        });

        // Close modal on outside click
        window.addEventListener('click', function(e) {
            if (e.target === notesModal) notesModal.style.display = 'none';
        });

        // Theme toggle (light/dark for reader)
        themeToggle.addEventListener('click', function() {
            const wrapper = document.querySelector('.bible-reader-wrapper');
            const current = wrapper.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            wrapper.setAttribute('data-theme', next);
        });
    });
</script>

<?php require_once 'includes/footer.php'; ?>