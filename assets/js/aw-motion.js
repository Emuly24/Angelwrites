/* AngelWrites motion layer (D2–D8). Progressive enhancement only: every page works
   without it, and nothing moves for visitors who ask for reduced motion. */
(function () {
    'use strict';
    var root = document.documentElement;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var page = root.getAttribute('data-aw-page') || '';
    var AW = window.AWMotion = { reduce: reduce, page: page };

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    /* ===== D2: button ripple ===== */
    document.addEventListener('pointerdown', function (e) {
        if (reduce || e.button !== 0) return;
        var btn = e.target.closest && e.target.closest('.btn');
        if (!btn || btn.disabled) return;
        var r = btn.getBoundingClientRect();
        var size = Math.max(r.width, r.height) * 2.2;
        var dot = document.createElement('span');
        dot.className = 'aw-ripple';
        dot.style.width = dot.style.height = size + 'px';
        dot.style.left = (e.clientX - r.left - size / 2) + 'px';
        dot.style.top = (e.clientY - r.top - size / 2) + 'px';
        btn.appendChild(dot);
        setTimeout(function () { dot.remove(); }, 650);
    }, { passive: true });

    /* ===== D2: working state on real form submits ===== */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var btn = e.submitter;
        if (!btn || !btn.classList || !btn.classList.contains('btn')) return;
        // Wait one tick: forms sent by page scripts call preventDefault(), and those manage their own state.
        setTimeout(function () {
            if (e.defaultPrevented || form.target === '_blank' || btn.hasAttribute('data-no-loading')) return;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            if (!btn.querySelector('.aw-spin')) {
                var s = document.createElement('span');
                s.className = 'aw-spin';
                s.setAttribute('aria-hidden', 'true');
                btn.appendChild(s);
            }
            // a download or a slow server should never leave the button stuck
            setTimeout(function () { clearLoading(btn); }, 12000);
        }, 0);
    });
    function clearLoading(btn) {
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
        var s = btn.querySelector('.aw-spin');
        if (s) s.remove();
    }
    // coming back with the browser's Back button must not show a spinning button
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.btn.is-loading').forEach(clearLoading);
    });

    /* ===== D3: navigation ===== */
    ready(function () {
        // stagger index for the mobile menu links
        document.querySelectorAll('#navLinks > li').forEach(function (li, i) { li.style.setProperty('--aw-i', i); });

        // Collapse the desktop menu into the drawer whenever its links would push the icons off screen.
        var bar0 = document.querySelector('.nav-container'), links = document.getElementById('navLinks'), acts = document.querySelector('.nav-actions');
        function fitNav() {
            if (!bar0 || !links || !acts) return;
            if (window.innerWidth <= 992) { root.classList.remove('aw-nav-compact'); return; }
            var wasOpen = links.classList.contains('open');
            root.classList.remove('aw-nav-compact');
            var tooWide = links.scrollWidth > links.clientWidth + 1 || acts.getBoundingClientRect().right > bar0.getBoundingClientRect().right + 1;
            root.classList.toggle('aw-nav-compact', tooWide);
            if (!tooWide && wasOpen && typeof window.closeMobileMenu === 'function') window.closeMobileMenu();
        }
        fitNav();
        var fitT;
        window.addEventListener('resize', function () { clearTimeout(fitT); fitT = setTimeout(fitNav, 120); }, { passive: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNav);

        // theme button gives a little turn when pressed
        var tt = document.getElementById('themeToggle');
        if (tt && !reduce) tt.addEventListener('click', function () {
            tt.classList.remove('aw-turn'); void tt.offsetWidth; tt.classList.add('aw-turn');
            setTimeout(function () { tt.classList.remove('aw-turn'); }, 650);
        });

        // reading progress bar on long pages (not in the book reader, which has its own)
        if (page === 'reader') return;
        var bar = document.createElement('div');
        bar.className = 'aw-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
        var ticking = false;
        function update() {
            ticking = false;
            var max = document.documentElement.scrollHeight - window.innerHeight;
            var long = max > window.innerHeight * 0.6;
            bar.style.display = long ? '' : 'none';
            if (long) bar.style.transform = 'scaleX(' + Math.min(1, Math.max(0, window.scrollY / max)) + ')';
        }
        window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
        window.addEventListener('resize', update, { passive: true });
        update();
    });

    /* ===== D4: cards and images ===== */
    var CARDS = '.book-card, .poem-card, .blog-card, .video-card, .group-card, .reflection-card, .post-card, ' +
        '.featured-card, .related-card, .feature-card, .about-feature, .stat-card, .stat-item, .admin-stat-card, ' +
        '.question-card, .member-card, .aw-card';
    var TILT = '.book-card, .poem-card, .aw-card';
    AW.CARDS = CARDS;

    function enhanceCards(scope) {
        if (page === 'reader') return;
        (scope || document).querySelectorAll(CARDS).forEach(function (card) {
            if (card.dataset.awCard) return;
            card.dataset.awCard = '1';
            var cs = getComputedStyle(card);
            // only add the pointer light where the page has not drawn its own ::after
            if (finePointer && !reduce && getComputedStyle(card, '::after').content === 'none') {
                if (cs.position === 'static') card.style.position = 'relative';
                card.classList.add('aw-spot');
            }
            if (!finePointer || reduce) return;
            var tilt = card.matches(TILT);
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
                card.style.setProperty('--aw-mx', (x * 100).toFixed(1) + '%');
                card.style.setProperty('--aw-my', (y * 100).toFixed(1) + '%');
                if (tilt) {
                    card.classList.add('aw-tilting');
                    card.style.transform = 'perspective(900px) rotateX(' + ((0.5 - y) * 6).toFixed(2) + 'deg) rotateY(' +
                        ((x - 0.5) * 7).toFixed(2) + 'deg) translateY(-6px)';
                }
            });
            if (tilt) card.addEventListener('pointerleave', function () {
                card.classList.remove('aw-tilting');
                card.style.transform = '';
            });
        });
    }

    function fadeImages(scope) {
        if (reduce) return;
        (scope || document).querySelectorAll('main img:not([data-aw-img])').forEach(function (img) {
            img.setAttribute('data-aw-img', '1');
            if (img.complete) return;
            img.classList.add('aw-img-wait', 'aw-img-in');
            var done = function () { img.classList.remove('aw-img-wait'); };
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', done, { once: true });
            setTimeout(done, 4000);
        });
    }

    ready(function () { enhanceCards(); fadeImages(); });
    AW.enhance = function (scope) { enhanceCards(scope); fadeImages(scope); };

    /* ===== D5: reveal on scroll, and numbers that count up ===== */
    var REVEAL = '.section-header, .section-title, .page-header, .cta-content, .newsletter-content, ' +
        '.testimonial-card, .comment-card, .empty-state, [data-aw-reveal], ' + CARDS;

    function reveal(scope) {
        // not in the book reader, and not in admin screens, which are for work rather than browsing
        if (reduce || page === 'reader' || /\/admin\//.test(location.pathname) || !('IntersectionObserver' in window)) return;
        var io = AW._io || (AW._io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                var el = en.target;
                io.unobserve(el);
                el.classList.add('aw-in');
                // hand the element back to its own transitions once it has arrived
                setTimeout(function () {
                    el.classList.remove('aw-reveal', 'aw-in');
                    el.style.removeProperty('--aw-d');
                }, 1000 + (parseInt(el.style.getPropertyValue('--aw-d'), 10) || 0));
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }));
        (scope || document).querySelectorAll('main ' + REVEAL.split(', ').join(', main ')).forEach(function (el) {
            if (el.dataset.awRev || el.closest('.hero, .aw-reveal')) return;
            el.dataset.awRev = '1';
            // siblings in the same grid arrive one after another
            var sibs = el.parentElement ? Array.prototype.filter.call(el.parentElement.children, function (c) { return c.matches(REVEAL); }) : [el];
            var i = Math.max(0, sibs.indexOf(el));
            el.style.setProperty('--aw-d', (i % 6) * 80 + 'ms');
            el.classList.add('aw-reveal');
            io.observe(el);
        });
    }

    function countUp(scope) {
        if (reduce || !('IntersectionObserver' in window)) return;
        var nodes = (scope || document).querySelectorAll('.stat-number:not([data-target]), .stat-value:not([data-target]), .admin-stat-card h3, .stat-card h3');
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                io.unobserve(en.target);
                var el = en.target, end = +el.dataset.awTo, t0 = null, grouped = el.dataset.awGrouped === '1';
                function step(t) {
                    if (!t0) t0 = t;
                    var p = Math.min(1, (t - t0) / 1300), v = Math.round(end * (1 - Math.pow(1 - p, 3)));
                    el.textContent = grouped ? v.toLocaleString('en-US') : String(v);
                    if (p < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        }, { threshold: 0.4 });
        nodes.forEach(function (el) {
            var txt = el.textContent.trim();
            // only plain whole numbers like 42 or 1,250; anything else ("3h 20m", "4.5") is left as it is
            if (el.children.length || !/^\d{1,3}(,\d{3})*$|^\d+$/.test(txt)) return;
            var n = parseInt(txt.replace(/,/g, ''), 10);
            if (!n || n < 2) return;
            el.dataset.awTo = n;
            el.dataset.awGrouped = txt.indexOf(',') > -1 ? '1' : '0';
            el.textContent = '0';
            io.observe(el);
        });
    }

    ready(function () { reveal(); countUp(); });
    var enhance0 = AW.enhance;
    AW.enhance = function (scope) { enhance0(scope); reveal(scope); };

    /* ===== D6: home page hero ===== */
    ready(function () {
        var hero = document.querySelector('main .hero');
        if (!hero || reduce) return;
        // sparkles rising across the hero
        for (var i = 0; i < 18; i++) {
            var sp = document.createElement('span');
            sp.className = 'aw-spark';
            sp.setAttribute('aria-hidden', 'true');
            sp.style.left = (4 + Math.random() * 92).toFixed(1) + '%';
            sp.style.top = (12 + Math.random() * 80).toFixed(1) + '%';
            sp.style.setProperty('--s', (4 + Math.random() * 7).toFixed(1) + 'px');
            sp.style.setProperty('--t', (5 + Math.random() * 5).toFixed(2) + 's');
            sp.style.setProperty('--dl', (-Math.random() * 8).toFixed(2) + 's');
            hero.appendChild(sp);
        }
        // a cue to scroll on, when the next section has an anchor
        var next = hero.nextElementSibling;
        while (next && next.tagName !== 'SECTION') next = next.nextElementSibling;
        if (next && next.id && !hero.querySelector('.aw-scroll-cue')) {
            var cue = document.createElement('a');
            cue.className = 'aw-scroll-cue';
            cue.href = '#' + next.id;
            cue.setAttribute('aria-label', 'Scroll to the next section');
            cue.innerHTML = '<i class="fas fa-chevron-down" aria-hidden="true"></i>';
            hero.appendChild(cue);
        }
        // the emblem leans gently toward the pointer
        var emblem = hero.querySelector('.hero-image-container');
        if (emblem && finePointer) {
            hero.addEventListener('pointermove', function (e) {
                var r = hero.getBoundingClientRect();
                emblem.style.setProperty('--aw-hx', (((e.clientX - r.left) / r.width - 0.5) * 18).toFixed(1) + 'px');
                emblem.style.setProperty('--aw-hy', (((e.clientY - r.top) / r.height - 0.5) * 14).toFixed(1) + 'px');
            });
            hero.addEventListener('pointerleave', function () {
                emblem.style.setProperty('--aw-hx', '0px');
                emblem.style.setProperty('--aw-hy', '0px');
            });
        }
    });

    /* ===== C2: one theme for the whole site =====
       Older pages kept their own dark mode (body.dark-mode, saved under their own keys such as
       'blogTheme'), separate from the site's theme switch. Their dark styles now simply follow the
       site theme, and the header's theme icon cannot be overwritten by those old page scripts. */
    function siteDark() { return root.getAttribute('data-theme') === 'dark'; }
    function themeIcon() {
        var t = 'light';
        try { t = localStorage.getItem('angelwrites_theme') || 'light'; } catch (e) {}
        return t === 'dark' ? 'fas fa-sun' : (t === 'system' ? 'fas fa-circle-half-stroke' : 'fas fa-moon');
    }
    function syncTheme() {
        if (document.body && document.body.classList.contains('dark-mode') !== siteDark()) {
            document.body.classList.toggle('dark-mode', siteDark());
        }
        var btn = document.getElementById('themeToggle');
        if (btn) {
            var i = btn.querySelector('i');
            var want = themeIcon();
            if (!i || btn.children.length !== 1) { btn.innerHTML = '<i class="' + want + '"></i>'; }
            else if (i.className !== want) { i.className = want; }
        }
    }
    ready(function () {
        syncTheme();
        var mo = new MutationObserver(syncTheme);
        mo.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
        mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        var btn = document.getElementById('themeToggle');
        if (btn) mo.observe(btn, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    });

    /* ===== C4: admin table actions =====
       Icon-only buttons in the last column of admin tables get one shared look, and a spoken
       name when they had none (screen readers announced them as just "button" or "link"). */
    var ICON_NAMES = [
        [/fa-(trash|trash-alt|trash-can)\b/, 'Delete', 'aw-danger'],
        [/fa-(edit|pen|pen-to-square|pencil|pencil-alt)\b/, 'Edit', ''],
        [/fa-eye-slash\b/, 'Hide', ''],
        [/fa-eye\b/, 'View', ''],
        [/fa-(check|check-circle|circle-check)\b/, 'Approve', 'aw-good'],
        [/fa-(times|xmark|ban)\b/, 'Cancel', 'aw-danger'],
        [/fa-(envelope|reply)\b/, 'Email', ''],
        [/fa-(paper-plane)\b/, 'Send', ''],
        [/fa-(link|external-link|external-link-alt|up-right-from-square)\b/, 'Open link', ''],
        [/fa-(star)\b/, 'Feature', ''],
        [/fa-(download|file-download)\b/, 'Download', ''],
        [/fa-(copy|clone)\b/, 'Copy', ''],
        [/fa-(undo|rotate-left|redo|rotate-right)\b/, 'Restore', '']
    ];
    function tableActions(scope) {
        (scope || document).querySelectorAll('.admin-table td:last-child').forEach(function (td) {
            td.querySelectorAll('a, button').forEach(function (b) {
                if (b.classList.contains('aw-iconbtn') || b.textContent.trim() !== '') return;
                var icon = b.querySelector('i');
                if (!icon) return;
                b.classList.add('aw-iconbtn');
                var cls = icon.className + ' ' + b.className;
                for (var k = 0; k < ICON_NAMES.length; k++) {
                    if (ICON_NAMES[k][0].test(cls)) {
                        if (ICON_NAMES[k][2]) b.classList.add(ICON_NAMES[k][2]);
                        if (!b.getAttribute('aria-label') && !b.getAttribute('title')) b.setAttribute('aria-label', ICON_NAMES[k][1]);
                        if (!b.getAttribute('title')) b.setAttribute('title', b.getAttribute('aria-label') || ICON_NAMES[k][1]);
                        break;
                    }
                }
                if (/btn-danger|delete/i.test(b.className)) b.classList.add('aw-danger');
            });
        });
    }
    ready(function () { tableActions(); });
    var enhance1 = AW.enhance;
    AW.enhance = function (scope) { enhance1(scope); tableActions(scope); };

    AW.ready = ready;
    AW.finePointer = finePointer;
})();
