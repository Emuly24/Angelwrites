<script>
(function() {
    // ===== DATA =====
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

    // ===== DOM REFS =====
    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container');
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
    const errorCorrection = document.getElementById('errorCorrection');
    const prayerBtn = document.getElementById('prayerBtn');
    const prayerModal = document.getElementById('prayerModal');
    const prayerText = document.getElementById('prayerText');
    const backBtn = document.getElementById('backBtn');
    const prevFlipBtn = document.getElementById('flipPrevBtn');
    const nextFlipBtn = document.getElementById('flipNextBtn');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const searchBtn = document.getElementById('searchBtn');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    let currentPage = Math.min(lastPage, totalPages) || 1;
    let readingMode = localStorage.getItem('reader_mode') || 'scroll';
    let focusMode = false;
    let isBookmarked = false;
    let touchStartX = 0;
    let currentNoteId = null;
    let flipData = { chunks: [], currentChunk: 0, totalChunks: 0, originalPage: 1 };

    // ===== UTILITY =====
    function getChapterForPage(page) {
        return pageToChapter[page] || 1;
    }
    function getChapterTitle(chapter) {
        return chapterTitles[chapter] || 'Chapter ' + chapter;
    }
    function getPagesInChapter(chapter) {
        return chapterMap[chapter] || [];
    }
    function getRemainingPagesInChapter(page) {
        const ch = getChapterForPage(page);
        const pagesInCh = getPagesInChapter(ch);
        const idx = pagesInCh.indexOf(page);
        if (idx === -1) return 0;
        return pagesInCh.length - idx - 1;
    }
    function getChapterTotalPages(chapter) {
        return getPagesInChapter(chapter).length;
    }
    function estimateTimeRemaining(page) {
        const remaining = getRemainingPagesInChapter(page);
        if (remaining <= 0) return 0;
        const wordsPerPage = 300;
        const totalWords = remaining * wordsPerPage;
        const minutes = Math.ceil(totalWords / readingSpeedWPM);
        return minutes;
    }

    // ===== THEMES =====
    function applyTheme(theme) {
        const app = document.getElementById('reader-app');
        app.classList.remove('theme-paper','theme-light','theme-dark','theme-sepia');
        app.classList.add('theme-'+theme);
        localStorage.setItem('reader_theme',theme);
    }

    // ===== SPLITTER =====
    function splitByFit(originalPageNum, html) {
        // (same as before)
        if (originalPageNum === 1 && html.trim() === 'COVER') {
            let coverHTML = '';
            if (cover_path && cover_path.length > 0) {
                coverHTML = `<div class="cover-image-wrapper-flip"><img src="${cover_path}" alt="Cover" /></div>`;
            } else {
                coverHTML = `<div class="cover-image-wrapper-flip"><div class="cover-placeholder-flip"><i class="fas fa-book-open"></i><p>Cover</p></div></div>`;
            }
            return { chunks: [coverHTML], mapping: [1] };
        }
        const temp = document.createElement('div');
        temp.innerHTML = html;
        const children = Array.from(temp.children);
        const measureContainer = document.createElement('div');
        measureContainer.style.cssText = `visibility:hidden;position:absolute;width:100%;padding:30px 40px;font-size:1.05rem;line-height:1.8;font-family:'Inter',sans-serif;color:var(--text);box-sizing:border-box;`;
        document.body.appendChild(measureContainer);
        const flipContainerEl = document.getElementById('flip-container');
        const maxHeight = flipContainerEl.clientHeight * 0.92 - 60;
        const chunks = [];
        const mapping = [];
        let currentChunk = document.createElement('div');

        function pushChunk() {
            if (currentChunk.children.length > 0) {
                chunks.push(currentChunk.innerHTML);
                mapping.push(originalPageNum);
                currentChunk = document.createElement('div');
            }
        }
        function wouldFit(child) {
            measureContainer.innerHTML = currentChunk.innerHTML;
            measureContainer.appendChild(child.cloneNode(true));
            const h = measureContainer.scrollHeight;
            measureContainer.innerHTML = '';
            return h <= maxHeight;
        }

        children.forEach(child => {
            const tag = child.tagName.toLowerCase();
            const text = child.textContent.trim().toLowerCase();
            if (tag === 'h2' || tag === 'h3') {
                pushChunk();
                currentChunk.appendChild(child.cloneNode(true));
                pushChunk();
                return;
            }
            const specialKeywords = ['acknowledgements','author\'s note','about the author','dedication','copyright'];
            if (specialKeywords.includes(text)) {
                pushChunk();
                const clone = child.cloneNode(true);
                currentChunk.appendChild(clone);
                pushChunk();
                return;
            }
            if (wouldFit(child)) {
                currentChunk.appendChild(child.cloneNode(true));
            } else {
                pushChunk();
                currentChunk.appendChild(child.cloneNode(true));
            }
        });
        pushChunk();
        document.body.removeChild(measureContainer);
        if (chunks.length === 0) {
            chunks.push('<p style="color:var(--text-light);text-align:center;">(empty page)</p>');
            mapping.push(originalPageNum);
        }
        return { chunks, mapping };
    }

    // ===== FLIP RENDERING =====
    function loadFlipPages(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        const pageHTML = (pageNum === 1) ? 'COVER' : pages[pageNum-1];
        const result = splitByFit(pageNum, pageHTML);
        flipData.chunks = result.chunks;
        flipData.totalChunks = result.chunks.length;
        flipData.currentChunk = 0;
        flipData.originalPage = pageNum;
        renderFlipChunk(0);
        updateFlipUI(pageNum, 0);
    }

    function renderFlipChunk(index) {
        const html = flipData.chunks[index] || '<p>...</p>';
        const leftContent = document.getElementById('flipLeftContent');
        leftContent.className = 'flip-page-inner';
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const text = tempDiv.textContent.trim().toLowerCase();
        const specialKeywords = ['acknowledgements','author\'s note','about the author','dedication','copyright','cover'];
        if (specialKeywords.some(kw => text.includes(kw)) || (flipData.originalPage === 1 && index === 0)) {
            leftContent.classList.add('special-page');
        }
        leftContent.innerHTML = html;
        const flipBook = document.getElementById('flipBook');
        flipBook.classList.remove('flipped-right','flipped-left','flipping');
        flipBook.style.transform = 'rotateY(0deg)';
    }

    function flipToNext() {
        if (flipData.currentChunk < flipData.totalChunks - 1) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-right');
            setTimeout(() => {
                flipData.currentChunk++;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-right','flipping');
                updateFlipUI(flipData.originalPage, flipData.currentChunk);
                savePosition();
            }, 800);
        } else if (flipData.originalPage < totalPages) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-right');
            setTimeout(() => {
                currentPage = flipData.originalPage + 1;
                loadFlipPages(currentPage);
                flipBook.classList.remove('flipped-right','flipping');
                updateFlipUI(currentPage, 0);
                savePosition();
            }, 800);
        }
    }

    function flipToPrev() {
        if (flipData.currentChunk > 0) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-left');
            setTimeout(() => {
                flipData.currentChunk--;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-left','flipping');
                updateFlipUI(flipData.originalPage, flipData.currentChunk);
                savePosition();
            }, 800);
        } else if (flipData.originalPage > 1) {
            const flipBook = document.getElementById('flipBook');
            flipBook.classList.add('flipping','flipped-left');
            setTimeout(() => {
                currentPage = flipData.originalPage - 1;
                loadFlipPages(currentPage);
                flipData.currentChunk = flipData.totalChunks - 1;
                renderFlipChunk(flipData.currentChunk);
                flipBook.classList.remove('flipped-left','flipping');
                updateFlipUI(currentPage, flipData.currentChunk);
                savePosition();
            }, 800);
        }
    }

    function updateFlipUI(pageNum, chunkIndex) {
        const totalChunks = flipData.totalChunks;
        if (totalChunks > 0) {
            pageNumEl.textContent = `${chunkIndex+1} / ${totalChunks}`;
        } else {
            pageNumEl.textContent = '1 / 1';
        }
        const approxPercent = Math.round(((pageNum-1)/totalPages + (chunkIndex+1)/totalPages/Math.max(1,totalChunks))*100);
        const circumference = 2 * Math.PI * 16;
        const offset = circumference - (approxPercent/100)*circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = approxPercent + '%';
        const ch = getChapterForPage(pageNum);
        const chapTitle = getChapterTitle(ch);
        const remaining = getRemainingPagesInChapter(pageNum);
        const totalInChapter = getChapterTotalPages(ch);
        chapterInfoEl.textContent = `📖 ${chapTitle}`;
        remainingInfoEl.textContent = `⏳ ${remaining} pages remaining • ${estimateTimeRemaining(pageNum)} min left`;
    }

    // ===== SCROLL UPDATE =====
    function updateUI(page) {
        if (readingMode === 'flip') return;
        pageNumEl.textContent = page;
        const percent = Math.round((page/totalPages)*100);
        const circumference = 2 * Math.PI * 16;
        const offset = circumference - (percent/100)*circumference;
        progressFill.setAttribute('stroke-dashoffset', offset);
        progressPercent.textContent = percent + '%';
        const ch = getChapterForPage(page);
        const chapTitle = getChapterTitle(ch);
        const remaining = getRemainingPagesInChapter(page);
        const totalInChapter = getChapterTotalPages(ch);
        chapterInfoEl.textContent = `📖 ${chapTitle}`;
        remainingInfoEl.textContent = `⏳ ${remaining} pages remaining • ${estimateTimeRemaining(page)} min left`;
    }

    // ===== NAVIGATION =====
    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip') {
            loadFlipPages(pageNum);
        } else {
            const target = document.querySelector(`.page-content-inner[data-page="${pageNum}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(pageNum);
        }
        savePosition();
        loadNotes();
    }

    function savePosition() {
        if (userId === 0) return;
        const data = new FormData();
        data.append('action','save_position');
        data.append('book_id',bookId);
        data.append('chapter',currentPage);
        data.append('percent',Math.round((currentPage/totalPages)*100));
        navigator.sendBeacon('/reader/reader_ajax.php',data);
    }

    // ===== SWITCH MODE =====
    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode',mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            flipContainer.style.display = 'flex';
            loadFlipPages(currentPage);
        } else {
            flipContainer.style.display = 'none';
            scrollContainer.style.display = 'block';
            const target = document.querySelector(`.page-content-inner[data-page="${currentPage}"]`);
            if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
            updateUI(currentPage);
        }
    }

    // ===== EVENTS =====
    prevFlipBtn.addEventListener('click',flipToPrev);
    nextFlipBtn.addEventListener('click',flipToNext);

    document.addEventListener('keydown',function(e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            if (readingMode === 'flip') flipToNext(); else scrollContainer.scrollBy({ top: scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (readingMode === 'flip') flipToPrev(); else scrollContainer.scrollBy({ top: -scrollContainer.clientHeight*0.8, behavior:'smooth' });
        } else if (e.key === 'Escape') {
            closeAll();
        }
    });

    document.getElementById('page-viewport').addEventListener('click',function(e) {
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('#highlight-tooltip')) return;
        if (readingMode === 'flip') {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            if (x > rect.width/2) flipToNext(); else flipToPrev();
        }
    });

    document.addEventListener('touchstart',function(e) { touchStartX = e.changedTouches[0].screenX; });
    document.addEventListener('touchend',function(e) {
        if (readingMode === 'flip') {
            const diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) flipToNext(); else flipToPrev();
            }
        }
    });

    // ===== BOOKMARK =====
    bookmarkBtn.addEventListener('click',function() {
        if (userId === 0) { alert('Please log in to bookmark.'); return; }
        if (isBookmarked) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData();
            fd.append('action','remove_bookmark');
            fd.append('book_id',bookId);
            xhr.send(fd);
            isBookmarked = false;
            bookmarkBtn.querySelector('i').className = 'far fa-bookmark';
            bookmarkBtn.style.color = '#555';
        } else {
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            const fd = new FormData();
            fd.append('action','add_bookmark');
            fd.append('book_id',bookId);
            fd.append('chapter',currentPage);
            fd.append('offset',0);
            xhr.send(fd);
            isBookmarked = true;
            bookmarkBtn.querySelector('i').className = 'fas fa-bookmark';
            bookmarkBtn.style.color = 'var(--rose)';
        }
    });

    function loadBookmarkStatus() {
        if (userId === 0) return;
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',false);
        const fd = new FormData();
        fd.append('action','list_bookmarks');
        fd.append('book_id',bookId);
        xhr.send(fd);
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                let exists = false;
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

    // ===== TOC =====
    document.querySelectorAll('.toc-link').forEach(function(link) {
        link.addEventListener('click',function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.chapter);
            if (page >= 1 && page <= totalPages) {
                goToPage(page);
                tocDrawer.style.display = 'none';
                overlay.classList.remove('active');
            }
        });
    });

    // ===== SETTINGS =====
    document.querySelectorAll('#modeGroup button').forEach(function(btn) {
        btn.addEventListener('click',function() {
            const mode = this.dataset.mode;
            document.querySelectorAll('#modeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            switchMode(mode);
        });
    });

    document.querySelectorAll('#themeGroup button').forEach(function(btn) {
        btn.addEventListener('click',function() {
            const theme = this.dataset.theme;
            document.querySelectorAll('#themeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            applyTheme(theme);
        });
    });

    const savedTheme = localStorage.getItem('reader_theme') || 'light';
    applyTheme(savedTheme);
    const themeBtn = document.querySelector('#themeGroup [data-theme="'+savedTheme+'"]');
    if (themeBtn) themeBtn.classList.add('active');

    document.getElementById('fontSizeSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.fontSize = val+'%'; });
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
    document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.fontSize = savedSize+'%'; });
    document.getElementById('fontSizeLabel').textContent = savedSize+'%';

    document.getElementById('lineHeightSlider').addEventListener('input',function() {
        const val = parseInt(this.value);
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.lineHeight = (val/100).toFixed(1); });
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
    document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) { el.style.lineHeight = (savedLine/100).toFixed(1); });
    document.getElementById('lineHeightLabel').textContent = (savedLine/100).toFixed(1);

    const fontTypeSelect = document.getElementById('fontTypeSelect');
    const savedFont = localStorage.getItem('reader_font_family') || 'Inter,sans-serif';
    if (savedFont) { fontTypeSelect.value = savedFont; applyFontType(savedFont); }
    fontTypeSelect.addEventListener('change',function() {
        const font = this.value;
        applyFontType(font);
        localStorage.setItem('reader_font_family',font);
    });
    function applyFontType(font) {
        document.querySelectorAll('.page-content-inner,.flip-page-inner').forEach(function(el) {
            el.style.fontFamily = font;
        });
    }

    // Reading speed slider
    const speedSlider = document.getElementById('readingSpeedSlider');
    speedSlider.addEventListener('input',function() {
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

    // ===== SIDEBAR TOGGLES =====
    sidebarToggle.addEventListener('click',function() { sidebar.classList.toggle('closed'); });
    settingsBtn.addEventListener('click',function() {
        settingsPanel.classList.toggle('open');
        overlay.classList.toggle('active',settingsPanel.classList.contains('open'));
    });
    tocBtn.addEventListener('click', function() {
        const drawer = document.getElementById('toc-drawer');
        if (drawer.style.display === 'none' || drawer.style.display === '') {
            drawer.style.display = 'block';
            overlay.classList.add('active');
        } else {
            drawer.style.display = 'none';
            overlay.classList.remove('active');
        }
    });
    commentsBtn.addEventListener('click',function() {
        if (userId === 0) { alert('Please log in to view comments.'); return; }
        loadComments();
        commentsModal.style.display = 'block';
        overlay.classList.add('active');
    });
    tocClose.addEventListener('click',function() {
        tocDrawer.style.display = 'none';
        overlay.classList.remove('active');
    });
    focusBtn.addEventListener('click',function() {
        focusMode = !focusMode;
        document.getElementById('reader-app').classList.toggle('focus-mode',focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
        }
    });

    // ===== CHALLENGE =====
    challengeBtn.addEventListener('click',function() { loadChallenge(); });
    function loadChallenge() {
        if (userId === 0) { alert('Please log in to view challenges.'); return; }
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_monthly_challenge&user_id='+userId,true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success) {
                    challengeWidget.style.display = 'block';
                    const percent = Math.min(100,Math.round((data.progress/data.target)*100));
                    challengeWidget.innerHTML = `
                        <h4>📖 Monthly Challenge</h4>
                        <p>${data.goal}</p>
                        <div class="challenge-progress"><div class="bar" style="width:${percent}%;"></div></div>
                        <p style="font-size:0.9rem;">${data.progress} / ${data.target} pages</p>
                        <button style="padding:4px 12px;border:1px solid var(--border);border-radius:4px;background:var(--rose);color:white;cursor:pointer;" onclick="updateChallenge()">📈 Update</button>
                    `;
                }
            } catch(e) { console.error('Challenge error:',e); alert('Could not load challenge.'); }
        };
        xhr.send();
    }
    function updateChallenge() {
        const pagesRead = prompt('How many pages did you read today?');
        if (pagesRead && parseInt(pagesRead) > 0) {
            const data = new FormData();
            data.append('action','update_challenge_progress');
            data.append('user_id',userId);
            data.append('pages_read',pagesRead);
            const xhr = new XMLHttpRequest();
            xhr.open('POST','/reader/reader_ajax.php',true);
            xhr.onload = function() { loadChallenge(); alert('✅ Updated!'); };
            xhr.send(data);
        }
    }

    // ===== HIGHLIGHT =====
    function getSelectedText() {
        const sel = window.getSelection();
        return sel.toString().trim();
    }
    function getSelectionRange() {
        const sel = window.getSelection();
        return sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    }
    function showSelectionTooltip(e) {
        e.stopPropagation();
        const text = getSelectedText();
        const range = getSelectionRange();
        const tooltip = document.getElementById('highlight-tooltip');
        if (!text || !range || text.length < 1) {
            tooltip.classList.remove('visible');
            document.getElementById('notes-panel').style.display = 'none';
            overlay.classList.remove('active');
            return;
        }
        const rect = range.getBoundingClientRect();
        const tooltipWidth = 320;
        const leftPos = rect.left + rect.width/2 - tooltipWidth/2;
        const topPos = rect.top - 50;
        tooltip.style.left = Math.max(10,leftPos) + 'px';
        tooltip.style.top = Math.max(10,topPos) + 'px';
        tooltip.classList.add('visible');
        tooltip.dataset.text = text;
    }
    document.addEventListener('click',function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        if (tooltip && !tooltip.contains(e.target)) {
            tooltip.classList.remove('visible');
        }
    });
    document.addEventListener('click', function(e) {
        const tooltip = document.getElementById('highlight-tooltip');
        const notesPanel = document.getElementById('notes-panel');
        if (tooltip && !tooltip.contains(e.target) && notesPanel && !notesPanel.contains(e.target)) {
            tooltip.classList.remove('visible');
            notesPanel.style.display = 'none';
            overlay.classList.remove('active');
        }
    });
    document.addEventListener('mouseup',function(e) {
        if (getSelectedText().length > 0) {
            setTimeout(function() { showSelectionTooltip(e); }, 50);
        }
    });
    document.addEventListener('touchend',function(e) {
        setTimeout(function() { showSelectionTooltip(e); }, 100);
    });

    function initSelectionTooltip() {
        const tooltip = document.getElementById('highlight-tooltip');
        if (!tooltip) return;
        tooltip.innerHTML = `
            <div>
                <div style="display:flex;gap:4px;">
                    <button class="highlight-color" data-color="yellow" style="background:#fff9c4;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="green" style="background:#c8e6c9;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="blue" style="background:#bbdefb;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                    <button class="highlight-color" data-color="pink" style="background:#f8bbd0;border-radius:50%;width:24px;height:24px;border:2px solid var(--border);cursor:pointer;"></button>
                </div>
                <div style="display:flex;gap:4px;margin-top:4px;">
                    <button class="tooltip-action" data-action="copy"><i class="fas fa-copy"></i></button>
                    <button class="tooltip-action" data-action="note"><i class="fas fa-pen"></i></button>
                    <button class="tooltip-action" data-action="share"><i class="fas fa-share-alt"></i></button>
                    <button class="tooltip-action" data-action="question"><i class="fas fa-question-circle"></i></button>
                    <button class="tooltip-action" data-action="react"><i class="fas fa-smile"></i></button>
                </div>
            </div>
        `;
        tooltip.querySelectorAll('.highlight-color').forEach(function(btn) {
            btn.addEventListener('click',function() {
                const color = this.dataset.color;
                const text = tooltip.dataset.text;
                const range = getSelectionRange();
                if (!range) return;
                const span = document.createElement('span');
                span.className = 'highlight-'+color;
                span.textContent = text;
                range.deleteContents();
                range.insertNode(span);
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
            });
        });
        tooltip.querySelectorAll('.tooltip-action').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                const text = tooltip.dataset.text;
                switch(action) {
                    case 'copy': navigator.clipboard.writeText(text).then(()=>{alert('✅ Copied!');}).catch(()=>{document.execCommand('copy');}); break;
                    case 'note':
                        if (groupId > 0) {
                            const panel = document.getElementById('notes-panel');
                            panel.style.display = 'flex';
                            overlay.classList.add('active');
                            loadNotes();
                            const noteTextarea = document.getElementById('noteText');
                            if (noteTextarea) {
                                noteTextarea.value = '"' + text + '"\n\n';
                                noteTextarea.focus();
                            }
                        } else {
                            alert('You need to be in a reading group to add notes.');
                        }
                        break;
                    case 'share':
                        document.getElementById('share-modal').style.display = 'block';
                        overlay.classList.add('active');
                        break;
                    case 'question':
                        if (groupId === 0) { alert('You need to be in a reading group.'); return; }
                        const question = prompt('Ask a question about this text:\n\n"' + text + '"');
                        if (question) { /* TODO: send question via AJAX */ }
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

    // ===== NOTES =====
    function loadNotes() {
        if (groupId === 0) return;
        const xhr = new XMLHttpRequest();
        xhr.open('GET','/reader/reader_ajax.php?action=get_notes&group_id='+groupId+'&book_id='+bookId+'&chapter='+currentPage,true);
        xhr.onload = function() {
            try {
                const data = JSON.parse(this.responseText);
                if (data.success) {
                    let html = '';
                    if (data.notes.length === 0) {
                        html = '<p class="empty-notes">No notes for this chapter.</p>';
                    } else {
                        data.notes.forEach(function(n) {
                            let reactionsHtml = '';
                            if (n.reactions && n.reactions.length > 0) {
                                n.reactions.forEach(function(r) {
                                    reactionsHtml += '<span class="reaction" onclick="reactNote('+n.id+',\''+r.reaction_type+'\')">'+r.reaction_type+' '+r.count+'</span>';
                                });
                            }
                            const canReact = !n.is_private || n.user_id == userId;
                            const isMyNote = n.user_id == userId;
                            html += '<div class="note-card'+(n.is_private?' private':'')+'">';
                            html += '<div class="note-author">';
                            html += '<div class="note-avatar-placeholder">'+(n.display_name||n.username).charAt(0).toUpperCase()+'</div>';
                            html += '<div class="note-author-info"><strong>'+(n.display_name||n.username)+'</strong> <small>'+timeAgo(n.created_at)+'</small>';
                            if (n.is_private) html += ' <span class="badge-private">🔒 Private</span>';
                            html += '</div></div>';
                            html += '<p class="note-text">'+n.text+'</p>';
                            html += '<div class="note-footer">';
                            html += '<div class="note-reactions">'+reactionsHtml;
                            if (canReact) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="showReactionPicker('+n.id+',event)">➕</button>';
                            html += '</div>';
                            if (isMyNote) html += ' <button style="padding:2px 8px;border:1px solid var(--border);border-radius:4px;background:transparent;cursor:pointer;" onclick="deleteNote('+n.id+')">🗑️</button>';
                            html += '</div></div>';
                        });
                    }
                    notesList.innerHTML = html;
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

    // ===== RESUME =====
    resumeBtn.addEventListener('click',function() { resumePosition(); });
    function resumePosition() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(function() {
                    const target = document.querySelector('.page-content-inner[data-page="'+lastPage+'"]');
                    if (target) target.scrollIntoView({ block:'start' });
                }, 100);
            }
        }
    }

    // ===== SHARE =====
    function share(platform) {
        const url = window.location.origin+'/reader/reader.php?id='+bookId+'&chapter='+currentPage;
        const text = '📖 I\'m reading on AngelWrites!';
        switch(platform) {
            case 'facebook': window.open('https://www.facebook.com/sharer/sharer.php?u='+encodeURIComponent(url),'_blank'); break;
            case 'twitter': window.open('https://twitter.com/intent/tweet?text='+encodeURIComponent(text)+'&url='+encodeURIComponent(url),'_blank'); break;
            case 'whatsapp': window.open('https://api.whatsapp.com/send?text='+encodeURIComponent(text+' '+url),'_blank'); break;
            case 'copy': navigator.clipboard.writeText(url).then(function(){alert('✅ Copied!');}).catch(function(){ const ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); alert('✅ Copied!'); }); break;
        }
        closeShare();
    }
    function closeShare() {
        document.getElementById('share-modal').style.display = 'none';
        overlay.classList.remove('active');
    }

    // ===== NOTES PANEL TOGGLE =====
    notesBtn.addEventListener('click', function() {
        if (groupId === 0) {
            alert('You are not in a reading group for this book.');
            return;
        }
        const panel = document.getElementById('notes-panel');
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
            overlay.classList.add('active');
            loadNotes();
        } else {
            panel.style.display = 'none';
            overlay.classList.remove('active');
        }
    });
    notesClose.addEventListener('click', function() {
        document.getElementById('notes-panel').style.display = 'none';
        overlay.classList.remove('active');
    });

    // ===== NOTE FORM FUNCTIONS =====
    function toggleNoteForm() {
        const form = document.getElementById('noteForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') {
            document.getElementById('noteText').focus();
        }
    }
    function submitNote() {
        const text = document.getElementById('noteText').value.trim();
        const isPrivate = document.getElementById('notePrivate').checked ? 1 : 0;
        if (!text) return alert('Please enter a note.');
        const data = new FormData();
        data.append('action','add_reader_note');
        data.append('group_id',groupId);
        data.append('book_id',bookId);
        data.append('chapter_index',currentPage);
        data.append('text',text);
        data.append('is_private',isPrivate);
        const xhr = new XMLHttpRequest();
        xhr.open('POST','/reader/reader_ajax.php',true);
        xhr.onload = function() {
            try {
                const d = JSON.parse(this.responseText);
                if (d.success) {
                    loadNotes();
                    document.getElementById('noteText').value = '';
                    document.getElementById('notePrivate').checked = false;
                    document.getElementById('noteForm').style.display = 'none';
                } else {
                    alert('Error: ' + d.error);
                }
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
        xhr.onload = function() { loadNotes(); };
        xhr.send(data);
    }
    function showReactionPicker(noteId, event) {
        currentNoteId = noteId;
        const btn = event.target.closest('button');
        const rect = btn.getBoundingClientRect();
        const picker = document.getElementById('reaction-picker');
        picker.style.top = (rect.bottom + 8) + 'px';
        picker.style.left = (rect.left) + 'px';
        picker.style.display = 'flex';
    }

    // ===== COMMENT FUNCTIONS =====
    function loadComments() {
        if (userId === 0) return;
        document.getElementById('currentCommentPage').textContent = currentPage;
        const formData = new FormData();
        formData.append('action','get_book_comments');
        formData.append('book_id',bookId);
        formData.append('page_num',currentPage);
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData})
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                const list = document.getElementById('commentList');
                list.innerHTML = '';
                if (data.comments.length === 0) {
                    list.innerHTML = '<p style="color:var(--text-light);text-align:center;padding:20px;">No comments on this page yet.</p>';
                } else {
                    data.comments.forEach(com=>{
                        const isAdmin = com.is_admin_reply == 1;
                        const authorName = isAdmin ? 'Angella (Admin)' : com.author_name;
                        const badge = isAdmin ? '<span class="admin-badge">🛡️ Admin</span>' : '';
                        list.innerHTML += `
                            <div class="comment-item ${isAdmin?'admin':''}">
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
    function submitComment() {
        const text = document.getElementById('commentInput').value.trim();
        if (!text) return alert('Please write a comment.');
        const formData = new FormData();
        formData.append('action','add_book_comment');
        formData.append('book_id',bookId);
        formData.append('page_num',currentPage);
        formData.append('comment',text);
        fetch('/reader/reader_ajax.php',{method:'POST',body:formData})
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                document.getElementById('commentInput').value = '';
                loadComments();
            } else {
                alert('Error: ' + (data.error || 'Failed to post comment.'));
            }
        });
    }
    function closeCommentsModal() {
        document.getElementById('commentsModal').style.display = 'none';
        overlay.classList.remove('active');
    }

    // ===== CLOSE ALL =====
    function closeAll() {
        settingsPanel.classList.remove('open');
        tocDrawer.style.display = 'none';
        notesPanel.style.display = 'none';
        document.getElementById('share-modal').style.display = 'none';
        overlay.classList.remove('active');
        if (focusMode) {
            focusMode = false;
            document.getElementById('reader-app').classList.remove('focus-mode');
            document.getElementById('focusBtn').querySelector('i').className = 'fas fa-expand';
        }
        if (commentsModal.style.display === 'block') { commentsModal.style.display = 'none'; }
        if (errorModal.style.display === 'block') { errorModal.style.display = 'none'; }
        if (prayerModal.style.display === 'block') { prayerModal.style.display = 'none'; }
    }
    overlay.addEventListener('click', closeAll);

    // ===== BACK BUTTON =====
    backBtn.addEventListener('click',function() { window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>'; });

    // ===== EXPOSE ALL FUNCTIONS TO GLOBAL SCOPE =====
    window.adjustFontSize = adjustFontSize;
    window.adjustLineHeight = adjustLineHeight;
    window.goToPage = goToPage;
    window.resumePosition = resumePosition;
    window.closeAll = closeAll;
    window.share = share;
    window.closeShare = closeShare;
    window.loadChallenge = loadChallenge;
    window.updateChallenge = updateChallenge;
    window.toggleNoteForm = toggleNoteForm;
    window.submitNote = submitNote;
    window.deleteNote = deleteNote;
    window.reactNote = reactNote;
    window.showReactionPicker = showReactionPicker;
    window.loadComments = loadComments;
    window.submitComment = submitComment;
    window.closeCommentsModal = closeCommentsModal;

    // ===== INIT =====
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