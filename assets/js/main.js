/**
 * AngelWrites – Main JavaScript
 * Handles: Hamburger menu, Theme toggle, Bible modal (Local Database)
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
    // 4. Bible Modal (Local Database)
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

    // ===== LOCAL BIBLE NAVIGATION =====
    // List of Bible books
    const books = [
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

    // Populate book dropdown
    books.forEach(book => {
        const option = document.createElement('option');
        option.value = book;
        option.textContent = book;
        bibleBookSelect.appendChild(option);
    });

    // Populate version dropdown with all 9 versions
    const versions = ["KJV", "NIV", "ESV", "NASB", "NKJV", "AMP", "ASV", "WEB", "YLT"];
    versions.forEach(version => {
        const option = document.createElement('option');
        option.value = version;
        option.textContent = version;
        bibleVersionSelect.appendChild(option);
    });

    // Book → Chapters
    bibleBookSelect.addEventListener('change', function() {
        const book = this.value;
        bibleChapterSelect.innerHTML = '<option value="">Select a chapter</option>';
        bibleVerseSelect.innerHTML = '<option value="">Select a verse</option>';
        bibleResult.innerHTML = '<p><em>Select a chapter and verse.</em></p>';
        if (!book) return;
        const chapters = book === "Psalms" ? 150 : book === "Isaiah" ? 66 : 21;
        for (let i = 1; i <= chapters; i++) {
            const option = document.createElement('option');
            option.value = i;
            option.textContent = `Chapter ${i}`;
            bibleChapterSelect.appendChild(option);
        }
    });

    // Chapter → Verses
    bibleChapterSelect.addEventListener('change', function() {
        const chapter = parseInt(this.value);
        bibleVerseSelect.innerHTML = '<option value="">Select a verse</option>';
        bibleResult.innerHTML = '<p><em>Select a verse to see it here.</em></p>';
        if (!chapter) return;
        const verses = chapter === 119 ? 176 : 30;
        for (let i = 1; i <= verses; i++) {
            const option = document.createElement('option');
            option.value = i;
            option.textContent = `Verse ${i}`;
            bibleVerseSelect.appendChild(option);
        }
    });

    // Verse → Fetch from local database
    bibleVerseSelect.addEventListener('change', function() {
        const book = bibleBookSelect.value;
        const chapter = bibleChapterSelect.value;
        const verse = this.value;
        const version = bibleVersionSelect.value;
        if (!book || !chapter || !verse) {
            bibleResult.innerHTML = '<p><em>Select a book, chapter, and verse.</em></p>';
            return;
        }
        fetch('/includes/bible_lookup.php?book=' + encodeURIComponent(book) + '&chapter=' + chapter + '&verse=' + verse + '&version=' + version)
        .then(response => response.text())
        .then(text => {
            bibleResult.innerHTML = `<div class="verse-display"><strong>${book} ${chapter}:${verse} (${version})</strong><p>${text}</p></div>`;
        })
        .catch(error => {
            bibleResult.innerHTML = `<p style="color:red;">Error: ${error.message}</p>`;
        });
    });

    // Refresh verse when translation changes
    if (bibleVersionSelect) {
        bibleVersionSelect.addEventListener('change', function() {
            const book = bibleBookSelect.value;
            const chapter = bibleChapterSelect.value;
            const verse = bibleVerseSelect.value;
            if (book && chapter && verse) {
                bibleVerseSelect.dispatchEvent(new Event('change'));
            }
        });
    }

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