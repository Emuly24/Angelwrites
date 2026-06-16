<script>
(function() {
    'use strict';

    const pages = <?php echo json_encode($pages); ?>;
    const totalPages = pages.length;
    const bookId = <?php echo $book_id; ?>;
    const userId = <?php echo isLoggedIn() ? $_SESSION['user_id'] : 0; ?>;
    const groupId = <?php echo $group_id ? (int)$group_id : 0; ?>;
    const toc = <?php echo json_encode($toc); ?>;
    const lastPage = <?php echo $last_page; ?>;

    const scrollContainer = document.getElementById('scroll-container');
    const flipContainer = document.getElementById('flip-container'); // This now correctly targets the 3D container
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

    // ===== NEW FEATURE REFERENCES =====
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
    if (userId > 0) startSession();
    if (userId > 0) loadChallenge();

    // ===== SIDEBAR TOGGLE =====
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('closed');
    });

    // ===== READING STATUS =====
    readingStatus.addEventListener('change', function() {
        if (userId === 0) { alert('Please log in to set reading status.'); return; }
        var data = new FormData();
        data.append('action', 'set_reading_status');
        data.append('book_id', bookId);
        data.append('status', this.value);
        navigator.sendBeacon('/reader/reader_ajax.php', data);
    });

    backBtn.addEventListener('click', function() {
        window.location.href = '<?php echo SITE_URL; ?>/book.php?id=<?php echo $book_id; ?>';
    });

    // ===== MODE SWITCHING =====
    function switchMode(mode) {
        readingMode = mode;
        localStorage.setItem('reader_mode', mode);
        if (mode === 'flip') {
            scrollContainer.style.display = 'none';
            if (flipContainer) {
                flipContainer.style.display = 'flex';
            }
            prepareFlipChunks(currentPage);
            renderFlipChunk(0);
        } else {
            if (flipContainer) {
                flipContainer.style.display = 'none';
            }
            scrollContainer.style.display = 'block';
            var target = document.querySelector('.page-content[data-page="' + currentPage + '"]');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        updateUI(currentPage);
    }

    // ===== FLIP MODE LOGIC =====
    function prepareFlipChunks(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        var html = pages[pageNum - 1];
        var paragraphs = html.split('</p>');
        var chunks = [];
        var currentChunk = '';
        var chunkSize = 6;
        var count = 0;
        for (var i = 0; i < paragraphs.length; i++) {
            var para = paragraphs[i].trim();
            if (para.length === 0) continue;
            currentChunk += para + '</p>';
            count++;
            if (count >= chunkSize) {
                chunks.push(currentChunk);
                currentChunk = '';
                count = 0;
            }
        }
        if (currentChunk.length > 0) {
            chunks.push(currentChunk);
        }
        flipChunks = chunks;
        flipCurrentChunkIndex = 0;
    }

    function renderFlipChunk(index) {
        if (index < 0) index = 0;
        if (index >= flipChunks.length) {
            if (currentPage < totalPages) {
                currentPage++;
                prepareFlipChunks(currentPage);
                renderFlipChunk(0);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
            return;
        }
        flipCurrentChunkIndex = index;
        var html = flipChunks[index];
        if (userId > 0) {
            var saved = getHighlightsForPage(currentPage);
            saved.forEach(function(h) {
                html = html.replaceAll(h.text, '<span class="highlight-' + h.color + '">' + h.text + '</span>');
            });
        }
        flipContainer.innerHTML = `
            <button class="aw-nav-btn prev" id="prevFlipBtn"><i class="fas fa-chevron-left"></i></button>
            <button class="aw-nav-btn next" id="nextFlipBtn"><i class="fas fa-chevron-right"></i></button>
            <div class="reader-page">${html}</div>
        `;
        document.getElementById('prevFlipBtn').addEventListener('click', prevFlipPage);
        document.getElementById('nextFlipBtn').addEventListener('click', nextFlipPage);
        updateUI(currentPage);
    }

    function nextFlipPage() {
        if (flipCurrentChunkIndex < flipChunks.length - 1) {
            renderFlipChunk(flipCurrentChunkIndex + 1);
        } else {
            if (currentPage < totalPages) {
                currentPage++;
                prepareFlipChunks(currentPage);
                renderFlipChunk(0);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
        }
    }

    function prevFlipPage() {
        if (flipCurrentChunkIndex > 0) {
            renderFlipChunk(flipCurrentChunkIndex - 1);
        } else {
            if (currentPage > 1) {
                currentPage--;
                prepareFlipChunks(currentPage);
                var lastChunkIndex = flipChunks.length - 1;
                renderFlipChunk(lastChunkIndex);
                updateUI(currentPage);
                savePosition();
                loadNotes();
            }
        }
    }

    // ===== NAVIGATION =====
    function nextPage() {
        if (readingMode === 'flip') { nextFlipPage(); }
        else if (currentPage < totalPages) { goToPage(currentPage + 1); }
    }

    function prevPage() {
        if (readingMode === 'flip') { prevFlipPage(); }
        else if (currentPage > 1) { goToPage(currentPage - 1); }
    }

    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        if (readingMode === 'flip') {
            prepareFlipChunks(pageNum);
            renderFlipChunk(0);
        } else {
            var target = document.querySelector('.page-content[data-page="' + pageNum + '"]');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            updateUI(pageNum);
        }
        savePosition();
        loadNotes();
    }

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

    // ===== BOOKMARKS =====
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

    // ===== SETTINGS: MODE =====
    document.querySelectorAll('#modeGroup button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mode = this.dataset.mode;
            document.querySelectorAll('#modeGroup button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            switchMode(mode);
        });
    });

    // ===== SETTINGS: THEME =====
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

    // ===== SETTINGS: FONT SIZE =====
    document.getElementById('fontSizeSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.fontSize = val + '%'; });
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
    document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.fontSize = savedSize + '%'; });
    document.getElementById('fontSizeLabel').textContent = savedSize + '%';

    // ===== SETTINGS: LINE HEIGHT =====
    document.getElementById('lineHeightSlider').addEventListener('input', function() {
        var val = parseInt(this.value);
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.lineHeight = (val / 100).toFixed(1); });
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
    document.querySelectorAll('.page-content, .reader-page').forEach(function(el) { el.style.lineHeight = (savedLine / 100).toFixed(1); });
    document.getElementById('lineHeightLabel').textContent = (savedLine / 100).toFixed(1);

    // ===== SETTINGS: FONT TYPE =====
    const fontTypeSelect = document.getElementById('fontTypeSelect');
    const savedFont = localStorage.getItem('reader_font_family') || 'Inter, sans-serif';
    if (savedFont) {
        fontTypeSelect.value = savedFont;
        applyFontType(savedFont);
    }

    fontTypeSelect.addEventListener('change', function() {
        const font = this.value;
        applyFontType(font);
        localStorage.setItem('reader_font_family', font);
    });

    function applyFontType(font) {
        document.querySelectorAll('.page-content, .reader-page').forEach(function(el) {
            el.style.fontFamily = font;
        });
    }

    // ===== TOUCH / CLICK EVENTS =====
    document.getElementById('page-viewport').addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('a')) return;
        if (readingMode === 'flip') {
            var rect = this.getBoundingClientRect();
            var x = e.clientX - rect.left;
            if (x > rect.width / 2) nextPage();
            else prevPage();
        }
    });

    document.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    });

    document.addEventListener('touchend', function(e) {
        if (readingMode === 'flip') {
            var diff = touchStartX - e.changedTouches[0].screenX;
            if (Math.abs(diff) > 30) {
                if (diff > 0) nextPage();
                else prevPage();
            }
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight') nextPage();
        else if (e.key === 'ArrowLeft') prevPage();
        else if (e.key === 'Escape') {
            closeAll();
        }
        else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            toggleSearch();
        }
    });

    // ===== SETTINGS PANEL TOGGLE =====
    settingsBtn.addEventListener('click', function() {
        settingsPanel.classList.toggle('open');
        overlay.classList.toggle('active', settingsPanel.classList.contains('open'));
    });

    // ===== TOC TOGGLE =====
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

    // ===== NOTES PANEL =====
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

    window.toggleNoteForm = function() {
        noteForm.style.display = noteForm.style.display === 'none' ? 'block' : 'none';
    };

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

    // ===== FOCUS MODE =====
    focusBtn.addEventListener('click', function() {
        focusMode = !focusMode;
        document.getElementById('reader-app').classList.toggle('focus-mode', focusMode);
        this.querySelector('i').className = focusMode ? 'fas fa-compress' : 'fas fa-expand';
        if (focusMode) {
            settingsPanel.classList.remove('open');
            overlay.classList.remove('active');
        }
    });

    // ===== SEARCH =====
    searchBtn.addEventListener('click', function() {
        toggleSearch();
    });

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

    // ===== SHARE =====
    shareBtn.addEventListener('click', function() {
        document.getElementById('share-modal').classList.add('visible');
        document.getElementById('overlay').classList.add('active');
    });

    function share(platform) {
        var url = window.location.origin + '/reader/reader.phpid=' + bookId + '&chapter=' + currentPage;
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

    // ===== CHALLENGE WIDGET =====
    challengeBtn.addEventListener('click', function() {
        loadChallenge();
    });

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

    // ===== RESUME POSITION =====
    resumeBtn.addEventListener('click', function() {
        resumePosition();
    });

    window.resumePosition = function() {
        if (lastPage >= 1 && lastPage <= totalPages) {
            goToPage(lastPage);
            if (readingMode === 'scroll') {
                setTimeout(function() {
                    var target = document.querySelector('.page-content[data-page="' + lastPage + '"]');
                    if (target) target.scrollIntoView({ block: 'start' });
                }, 100);
            }
        }
    };

    // ===== RESET PROGRESS =====
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

    // ===== EXPORT HIGHLIGHTS =====
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

    // ===== SESSION TRACKING =====
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

    // ===== TIME AGO HELPER =====
    function timeAgo(timestamp) {
        var diff = Date.now() - new Date(timestamp).getTime();
        var secs = Math.floor(diff / 1000);
        if (secs < 60) return 'just now';
        if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
        if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
        if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';
        return new Date(timestamp).toLocaleDateString();
    }

    // ================================================================
    //  SELECTION TOOLTIP (New unified implementation)
    // ================================================================
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
            <div style="display:flex;flex-direction:column;gap:4px;width:100%;">
                <div style="display:flex;gap:4px;justify-content:center;padding:2px 0;">
                    <button class="highlight-color" data-color="yellow" title="Highlight Yellow"></button>
                    <button class="highlight-color" data-color="green" title="Highlight Green"></button>
                    <button class="highlight-color" data-color="blue" title="Highlight Blue"></button>
                    <button class="highlight-color" data-color="pink" title="Highlight Pink"></button>
                </div>
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;padding:2px 0;">
                    <button class="tooltip-action" data-action="copy" title="Copy">
                        <i class="fas fa-copy"></i>
                    </button>
                    <button class="tooltip-action" data-action="note" title="Add Note">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="tooltip-action" data-action="share" title="Share">
                        <i class="fas fa-share-alt"></i>
                    </button>
                    <button class="tooltip-action" data-action="question" title="Ask Group">
                        <i class="fas fa-question-circle"></i>
                    </button>
                    <button class="tooltip-action" data-action="react" title="React">
                        <i class="fas fa-smile"></i>
                    </button>
                </div>
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
                    case 'copy':
                        navigator.clipboard.writeText(text).then(() => {
                            alert('✅ Copied to clipboard!');
                        }).catch(() => {
                            const ta = document.createElement('textarea');
                            ta.value = text;
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand('copy');
                            document.body.removeChild(ta);
                            alert('✅ Copied!');
                        });
                        break;

                    case 'note':
                        annotationPopup.classList.add('visible');
                        annotationText.value = '"' + text + '"\n\n';
                        annotationText.focus();
                        break;

                    case 'share':
                        document.getElementById('share-modal').classList.add('visible');
                        document.getElementById('overlay').classList.add('active');
                        break;

                    case 'question':
                        if (groupId === 0) {
                            alert('You need to be in a reading group to ask questions.');
                            return;
                        }
                        const question = prompt('Ask a question about this text:\n\n"' + text + '"');
                        if (question && question.trim().length > 0) {
                            const data = new FormData();
                            data.append('action', 'ask_question');
                            data.append('book_id', bookId);
                            data.append('chapter', currentPage);
                            data.append('text', text);
                            data.append('question', question);
                            fetch('/reader/reader_ajax.php', { method: 'POST', body: data })
                                .then(() => { alert('✅ Question sent to group!'); });
                        }
                        break;

                    case 'react':
                        const picker = document.getElementById('reaction-picker');
                        if (picker) {
                            const rect = this.getBoundingClientRect();
                            picker.style.top = (rect.bottom + 8) + 'px';
                            picker.style.left = (rect.left) + 'px';
                            picker.style.display = 'flex';
                            picker.dataset.text = text;
                        }
                        break;
                }
                tooltip.classList.remove('visible');
            });
        });
    }

    document.addEventListener('mouseup', function(e) {
        setTimeout(showSelectionTooltip, 50);
    });

    document.addEventListener('touchend', function(e) {
        setTimeout(showSelectionTooltip, 100);
    });

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
                fetch('/reader/reader_ajax.php', { method: 'POST', body: data })
                    .then(() => { alert('✅ Reaction added!'); });
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

    annotationCancel.addEventListener('click', function() {
        annotationPopup.classList.remove('visible');
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#highlight-tooltip') && !e.target.closest('#annotation-popup')) {
            annotationPopup.classList.remove('visible');
        }
    });

    initSelectionTooltip();

    window.goToPage = goToPage;

    // ===== CLOSE ALL (Optimized Overlay Handling) =====
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

    // ============================================================
    //   NEW FEATURES: COMMENTS, PROOFREADING, PRAYER REQUESTS
    // ============================================================

    // ===== 1. COMMENTS =====
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

    // ===== 2. PROOFREADING (ERROR REPORT) =====
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

    // ===== 3. PRAYER REQUESTS =====
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

    // Clicking on overlay closes specific modals if open
    overlay.addEventListener('click', function() {
        if (commentsModal.style.display === 'block') closeCommentsModal();
        if (errorModal.style.display === 'block') closeErrorModal();
        if (prayerModal.style.display === 'block') closePrayerModal();
    });

})();
</script>