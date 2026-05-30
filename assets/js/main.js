/**
 * AngelWrites – Main JavaScript
 * Handles: Hamburger menu, Theme toggle, Bible modal (API + Table of Contents + Translations)
 */

(function() {
    'use strict';

    // ============================================================
    // 1. DOM References
    // ============================================================
    const hamburger = document.getElementById('hamburger');
    const navLinks = document.getElementById('navLinks');
    const menuOverlay = document.getElementById('menuOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = themeToggle ? themeToggle.querySelector('i') : null;
    const bibleToggle = document.getElementById('bibleToggle');
    const bibleModal = document.getElementById('bibleModal');
    const bibleClose = document.getElementById('bibleClose');
    const bibleBookSelect = document.getElementById('bibleBook');
    const bibleChapterSelect = document.getElementById('bibleChapter');
    const bibleVerseSelect = document.getElementById('bibleVerse');
    const bibleVersionSelect = document.getElementById('bibleVersion');
    const bibleResult = document.getElementById('bibleResult');

    // ============================================================
    // 2. Hamburger Menu & Overlay
    // ============================================================
    function toggleMenu() {
        hamburger.classList.toggle('active');
        navLinks.classList.toggle('open');
        menuOverlay.classList.toggle('active');
        document.body.style.overflow = navLinks.classList.contains('open') ? 'hidden' : '';
    }

    function closeMenu() {
        hamburger.classList.remove('active');
        navLinks.classList.remove('open');
        menuOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (hamburger) {
        hamburger.addEventListener('click', toggleMenu);
    }

    if (menuOverlay) {
        menuOverlay.addEventListener('click', closeMenu);
    }

    // Close menu when any nav link is clicked (mobile)
    document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', closeMenu);
    });

    // Close menu on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && navLinks.classList.contains('open')) {
            closeMenu();
        }
    });

    // ============================================================
    // 3. Theme Toggle (Light → Dark → System)
    // ============================================================
    function setTheme(theme) {
        const html = document.documentElement;
        html.setAttribute('data-theme', theme);
        document.cookie = `theme=${theme}; path=/; max-age=${60*60*24*365}`;

        if (themeIcon) {
            if (theme === 'dark') {
                themeIcon.className = 'fas fa-sun';
            } else if (theme === 'light') {
                themeIcon.className = 'fas fa-moon';
            } else {
                themeIcon.className = 'fas fa-circle-half-stroke';
            }
        }
        localStorage.setItem('theme', theme);
    }

    function getNextTheme(current) {
        const themes = ['light', 'dark', 'system'];
        const idx = themes.indexOf(current);
        return themes[(idx + 1) % themes.length];
    }

    function initializeTheme() {
        let theme = document.cookie.replace(/(?:(?:^|.*;\s*)theme\s*=\s*([^;]+).*$)|^.*$/, '$1');
        if (!theme) theme = localStorage.getItem('theme');
        if (!theme) theme = 'system';
        setTheme(theme);
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = getNextTheme(current);
            setTheme(next);
        });
    }

    initializeTheme();

    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', function() {
            const current = document.documentElement.getAttribute('data-theme');
            if (current === 'system') setTheme('system');
        });
    }

    // ============================================================
    // 4. Bible Modal (API + Table of Contents + Multiple Versions)
    // ============================================================
    function openBibleModal() {
        if (bibleModal) {
            bibleModal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeBibleModal() {
        if (bibleModal) {
            bibleModal.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    if (bibleToggle) bibleToggle.addEventListener('click', openBibleModal);
    if (bibleClose) bibleClose.addEventListener('click', closeBibleModal);

    if (bibleModal) {
        bibleModal.addEventListener('click', function(e) {
            if (e.target === bibleModal) closeBibleModal();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && bibleModal && bibleModal.classList.contains('open')) {
            closeBibleModal();
        }
    });

    // ============================================================
    // 5. Bible Navigation (Book → Chapter → Verse)
    // ============================================================
    async function loadBibleBooks() {
        try {
            const response = await fetch('https://bible-api.com/books');
            const data = await response.json();
            data.forEach(book => {
                const option = document.createElement('option');
                option.value = book.id;
                option.textContent = book.name;
                bibleBookSelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load Bible books:', error);
            bibleResult.innerHTML = '<p style="color:red;">⚠️ Failed to load Bible books. Try again later.</p>';
        }
    }

    function getCurrentVersion() {
        return bibleVersionSelect ? bibleVersionSelect.value : 'kjv';
    }

    // Book → Chapters
    bibleBookSelect.addEventListener('change', async function() {
        const bookId = this.value;
        bibleChapterSelect.innerHTML = '<option value="">Select a chapter</option>';
        bibleVerseSelect.innerHTML = '<option value="">Select a verse</option>';
        bibleResult.innerHTML = '<p><em>Select a chapter and verse.</em></p>';
        if (!bookId) return;
        try {
            const response = await fetch(`https://bible-api.com/${bookId}?verse_numbers=true`);
            const data = await response.json();
            const chapters = data.chapters || [];
            for (let i = 1; i <= chapters.length; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = `Chapter ${i}`;
                bibleChapterSelect.appendChild(option);
            }
        } catch (error) {
            console.error('Failed to load chapters:', error);
            bibleResult.innerHTML = '<p style="color:red;">Failed to load chapters.</p>';
        }
    });

    // Chapter → Verses
    bibleChapterSelect.addEventListener('change', async function() {
        const bookId = bibleBookSelect.value;
        const chapter = this.value;
        bibleVerseSelect.innerHTML = '<option value="">Select a verse</option>';
        bibleResult.innerHTML = '<p><em>Select a verse to see it here.</em></p>';
        if (!bookId || !chapter) return;
        try {
            const response = await fetch(`https://bible-api.com/${bookId}+${chapter}?verse_numbers=true`);
            const data = await response.json();
            const verses = data.verses || [];
            for (let i = 1; i <= verses.length; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = `Verse ${i}`;
                bibleVerseSelect.appendChild(option);
            }
        } catch (error) {
            console.error('Failed to load verses:', error);
            bibleResult.innerHTML = '<p style="color:red;">Failed to load verses.</p>';
        }
    });

    // Verse → Display with selected translation
    bibleVerseSelect.addEventListener('change', async function() {
        const bookId = bibleBookSelect.value;
        const chapter = bibleChapterSelect.value;
        const verse = this.value;
        const version = getCurrentVersion();
        if (!bookId || !chapter || !verse) {
            bibleResult.innerHTML = '<p><em>Select a book, chapter, and verse.</em></p>';
            return;
        }
        try {
            const response = await fetch(`https://bible-api.com/${bookId}+${chapter}:${verse}?translation=${version}&verse_numbers=true`);
            const data = await response.json();
            const versionName = bibleVersionSelect.options[bibleVersionSelect.selectedIndex].text.split('(')[0].trim();
            bibleResult.innerHTML = `
                <div class="verse-display">
                    <strong>${data.reference} (${versionName})</strong>
                    <p>${data.text}</p>
                </div>
            `;
        } catch (error) {
            console.error('Failed to load verse:', error);
            bibleResult.innerHTML = '<p style="color:red;">Failed to load verse.</p>';
        }
    });

    // Translation change refreshes current verse
    if (bibleVersionSelect) {
        bibleVersionSelect.addEventListener('change', function() {
            const bookId = bibleBookSelect.value;
            const chapter = bibleChapterSelect.value;
            const verse = bibleVerseSelect.value;
            if (bookId && chapter && verse) {
                bibleVerseSelect.dispatchEvent(new Event('change'));
            }
        });
    }

    // Load books on page load
    loadBibleBooks();

    // ============================================================
    // 6. Navigation Active State (Highlight current page)
    // ============================================================
    function setActiveNavLink() {
        const currentPath = window.location.pathname;
        const currentFile = currentPath.split('/').pop() || 'index.php';
        document.querySelectorAll('.nav-links a').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentFile || href === currentPath) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }
    setActiveNavLink();

})();