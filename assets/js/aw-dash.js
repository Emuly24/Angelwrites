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
    try {
      // cards in view now are shown at once; the rest fade in as they scroll into view
      items.forEach(function (el) { if (el.getBoundingClientRect().top < innerHeight) show(el); });
      root.classList.add('dsh-anim');
      var io = new IntersectionObserver(function (entries) { entries.forEach(function (en) { if (en.isIntersecting) { show(en.target); io.unobserve(en.target); } }); }, { threshold: 0 });
      items.forEach(function (el) { if (!el.classList.contains('is-in')) io.observe(el); });
      // safety nets: some browsers miss cards in column layouts; nothing stays hidden for long
      window.addEventListener('scroll', function () { items.forEach(function (el) { var r = el.getBoundingClientRect(); if (r.top < innerHeight && r.bottom > 0) show(el); }); }, { passive: true });
      setTimeout(function () { items.forEach(show); }, 2500);
    } catch (e) { root.classList.remove('dsh-anim'); items.forEach(show); }
  }
  if (!calm) root.addEventListener('pointermove', function (e) {
    var c = e.target.closest && e.target.closest('.dsh-card'); if (!c) return;
    var r = c.getBoundingClientRect(); c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });
})();
