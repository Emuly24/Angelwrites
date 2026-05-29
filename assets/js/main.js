/**
 * AngelWrites – Main JavaScript
 * Handles: Hamburger menu, Theme toggle, Bible modal with local JSON
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
    const bibleSearchForm = document.getElementById('bibleSearchForm');
    const bibleQuery = document.getElementById('bibleQuery');
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
        document.cookie = `theme=${theme}; path=/; max-age=${60*60*24*365}`; // 1 year

        // Update icon
        if (themeIcon) {
            if (theme === 'dark') {
                themeIcon.className = 'fas fa-sun';
            } else if (theme === 'light') {
                themeIcon.className = 'fas fa-moon';
            } else {
                themeIcon.className = 'fas fa-circle-half-stroke';
            }
        }

        // Save preference for future visits (localStorage fallback)
        localStorage.setItem('theme', theme);
    }

    function getNextTheme(current) {
        const themes = ['light', 'dark', 'system'];
        const idx = themes.indexOf(current);
        return themes[(idx + 1) % themes.length];
    }

    function initializeTheme() {
        // Priority: cookie > localStorage > prefers-color-scheme
        let theme = document.cookie.replace(/(?:(?:^|.*;\s*)theme\s*=\s*([^;]+).*$)|^.*$/, '$1');
        if (!theme) {
            theme = localStorage.getItem('theme');
        }
        if (!theme) {
            // Default to system
            theme = 'system';
        }
        setTheme(theme);
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = getNextTheme(current);
            setTheme(next);
        });
    }

    // Initialize theme on page load
    initializeTheme();

    // Listen for system theme changes (if browser supports it)
    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', function() {
            const current = document.documentElement.getAttribute('data-theme');
            if (current === 'system') {
                // Re-apply system theme (which adapts automatically via CSS)
                setTheme('system');
            }
        });
    }

    // ============================================================
    // 4. Bible Modal (Local KJV JSON)
    // ============================================================
    let bibleData = null; // Will hold the parsed JSON

    // Load Bible JSON once (lazy load)
    function loadBibleData() {
        if (bibleData !== null) return Promise.resolve(bibleData);
        return fetch('/assets/bible/kjv.json')
            .then(response => {
                if (!response.ok) throw new Error('Bible file not found');
                return response.json();
            })
            .then(data => {
                bibleData = data;
                return bibleData;
            })
            .catch(error => {
                console.error('Failed to load Bible:', error);
                bibleResult.innerHTML = '<p style="color:red;">⚠️ Bible file not found. Please upload kjv.json.</p>';
                return null;
            });
    }

    function openBibleModal() {
        if (bibleModal) {
            bibleModal.classList.add('open');
            document.body.style.overflow = 'hidden';
            // Focus input
            if (bibleQuery) bibleQuery.focus();
        }
    }

    function closeBibleModal() {
        if (bibleModal) {
            bibleModal.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    if (bibleToggle) {
        bibleToggle.addEventListener('click', openBibleModal);
    }

    if (bibleClose) {
        bibleClose.addEventListener('click', closeBibleModal);
    }

    // Click outside modal to close
    if (bibleModal) {
        bibleModal.addEventListener('click', function(e) {
            if (e.target === bibleModal) {
                closeBibleModal();
            }
        });
    }

    // Escape key closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && bibleModal && bibleModal.classList.contains('open')) {
            closeBibleModal();
        }
    });

    // ============================================================
    // 5. Bible Search (Local JSON)
    // ============================================================
    function parseBibleReference(input) {
        // Matches: "John 3:16", "Genesis 1:1", "Psalm 23:4"
        // Supports book names with spaces: "1 Corinthians 13:4"
        const match = input.trim().match(/^([\d\s\w]+)\s+(\d+):(\d+)$/i);
        if (!match) return null;
        const bookName = match[1].trim();
        const chapter = parseInt(match[2], 10);
        const verse = parseInt(match[3], 10);
        return { bookName, chapter, verse };
    }

    function searchBibleVerse(query) {
        const ref = parseBibleReference(query);
        if (!ref) {
            bibleResult.innerHTML = `<p style="color:var(--rose);">❌ Invalid format. Use: <strong>John 3:16</strong></p>`;
            return;
        }

        loadBibleData().then(data => {
            if (!data) return;

            // Try exact book name match
            let bookKey = null;
            const possibleKeys = Object.keys(data);
            for (let key of possibleKeys) {
                if (key.toLowerCase() === ref.bookName.toLowerCase()) {
                    bookKey = key;
                    break;
                }
            }
            // If not found, try partial match
            if (!bookKey) {
                for (let key of possibleKeys) {
                    if (key.toLowerCase().includes(ref.bookName.toLowerCase())) {
                        bookKey = key;
                        break;
                    }
                }
            }

            if (!bookKey) {
                bibleResult.innerHTML = `<p style="color:var(--rose);">❌ Book "${ref.bookName}" not found.</p>`;
                return;
            }

            const chapterData = data[bookKey];
            if (!chapterData || typeof chapterData !== 'object') {
                bibleResult.innerHTML = `<p style="color:var(--rose);">❌ No data for book "${bookKey}".</p>`;
                return;
            }

            const chapterKey = String(ref.chapter);
            const verses = chapterData[chapterKey];
            if (!verses || !Array.isArray(verses)) {
                bibleResult.innerHTML = `<p style="color:var(--rose);">❌ Chapter ${ref.chapter} not found.</p>`;
                return;
            }

            const verseText = verses[ref.verse - 1];
            if (!verseText) {
                bibleResult.innerHTML = `<p style="color:var(--rose);">❌ Verse ${ref.verse} not found.</p>`;
                return;
            }

            // Display result
            bibleResult.innerHTML = `
                <div class="verse-display">
                    <strong>${bookKey} ${ref.chapter}:${ref.verse}</strong>
                    <p>${verseText}</p>
                </div>
            `;
        }).catch(error => {
            bibleResult.innerHTML = `<p style="color:red;">❌ Error: ${error.message}</p>`;
        });
    }

    if (bibleSearchForm) {
        bibleSearchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const query = bibleQuery.value.trim();
            if (!query) {
                bibleResult.innerHTML = `<p>Please enter a verse reference.</p>`;
                return;
            }
            bibleResult.innerHTML = `<p>Searching...</p>`;
            searchBibleVerse(query);
        });
    }

    // Optional: Auto-search on paste/change (better to use submit button)

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

    // ============================================================
    // 7. Optional: Prevent body scroll when modal open (already done)
    // ============================================================

})();