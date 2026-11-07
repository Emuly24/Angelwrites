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

    AW.ready = ready;
    AW.finePointer = finePointer;
})();
