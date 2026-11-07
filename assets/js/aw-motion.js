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

    AW.ready = ready;
    AW.finePointer = finePointer;
})();
