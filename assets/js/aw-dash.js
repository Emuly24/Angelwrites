/* AngelWrites dashboards: things appear as they scroll into view, numbers count up, and a soft light
   follows the pointer over cards. Nothing is fetched; everything was on the page already.
   People who ask for less motion get the final state at once. */
(function () {
  'use strict';
  var root = document.querySelector('.dsh'); if (!root) return;
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // masonry without CSS columns: deal the cards into real columns, each into the shortest one
  function masonry() {
    root.querySelectorAll('.dsh-row-3, .dsh-tools').forEach(function (box) {
      var cards = box._awCards || (box._awCards = [].slice.call(box.children).filter(function (c) { return !c.classList.contains('dsh-col'); }));
      var width = box.getBoundingClientRect().width;
      var n = Math.max(1, Math.min(4, Math.floor((width + 16) / (270 + 16))));
      if (box._awCols === n) return;
      box._awCols = n;
      [].slice.call(box.querySelectorAll(':scope > .dsh-col')).forEach(function (c) { c.remove(); });
      if (n === 1) { box.classList.remove('dsh-masonry'); cards.forEach(function (c) { box.appendChild(c); }); return; }
      box.classList.add('dsh-masonry');
      var cols = [], h = [];
      for (var i = 0; i < n; i++) { var c = document.createElement('div'); c.className = 'dsh-col'; box.appendChild(c); cols.push(c); h.push(0); }
      cards.forEach(function (card) {
        var k = h.indexOf(Math.min.apply(null, h));
        cols[k].appendChild(card);
        h[k] += card.getBoundingClientRect().height + 16;
      });
    });
  }
  try { masonry(); var mt; window.addEventListener('resize', function () { clearTimeout(mt); mt = setTimeout(masonry, 150); }); } catch (e) {}
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
