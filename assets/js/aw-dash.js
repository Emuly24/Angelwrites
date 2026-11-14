/* AngelWrites dashboards: things appear as they scroll into view, numbers count up, and a soft light
   follows the pointer over cards. Nothing is fetched; everything was on the page already.
   People who ask for less motion get the final state at once. */
(function () {
  'use strict';
  var root = document.querySelector('.dsh'); if (!root) return;
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var items = root.querySelectorAll('.dsh-reveal');
  function countUp(el) {
    var end = parseFloat(el.getAttribute('data-count')) || 0, fmt = new Intl.NumberFormat();
    if (calm || end === 0) { el.textContent = fmt.format(end); return; }
    var t0 = null, dur = Math.min(1600, 600 + end * 4);
    function step(t) { if (!t0) t0 = t; var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 4); el.textContent = fmt.format(Math.round(end * e)); if (p < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step);
  }
  function show(el) {
    if (el.classList.contains('is-in')) return;
    el.classList.add('is-in');
    el.querySelectorAll('[data-count]').forEach(countUp);
  }
  if (calm || !('IntersectionObserver' in window)) { items.forEach(show); }
  else {
    var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { show(en.target); io.unobserve(en.target); } }); }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(function (el) { io.observe(el); });
    setTimeout(function () { items.forEach(function (el) { if (el.getBoundingClientRect().top < innerHeight) show(el); }); }, 1200); // safety net
  }
  if (!calm) root.addEventListener('pointermove', function (e) {
    var c = e.target.closest && e.target.closest('.dsh-card'); if (!c) return;
    var r = c.getBoundingClientRect(); c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });
})();
