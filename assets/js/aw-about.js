/* AngelWrites — About page: scroll reveals, count-up, card spotlight, reading progress.
   Without JavaScript, or with reduced motion, everything is simply shown. */
(function () {
  var root = document.querySelector('.ab');
  if (!root) return;
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function countUp(el) {
    var end = parseInt(el.getAttribute('data-count'), 10) || 0, fmt = new Intl.NumberFormat();
    if (calm || end < 2) { el.textContent = fmt.format(end); return; }
    var t0 = null, dur = 1200;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt.format(Math.round(end * e));
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  function show(el) {
    el.classList.add('is-in');
    el.querySelectorAll('[data-count]').forEach(countUp);
  }

  var items = root.querySelectorAll('.ab-reveal');
  if (!calm && 'IntersectionObserver' in window) {
    root.classList.add('ab-js');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { show(en.target); io.unobserve(en.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(function (el) { io.observe(el); });
  }

  // spotlight follows the pointer on the offer cards
  if (!calm) {
    root.querySelectorAll('.ab-card').forEach(function (c) {
      c.addEventListener('pointermove', function (e) {
        var r = c.getBoundingClientRect();
        c.style.setProperty('--mx', (e.clientX - r.left) + 'px');
        c.style.setProperty('--my', (e.clientY - r.top) + 'px');
      });
    });
  }

  // reading progress
  var bar = document.getElementById('abProgress');
  if (bar && !calm) {
    var ticking = false;
    var draw = function () {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0) + ')';
      ticking = false;
    };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(draw); } }, { passive: true });
    draw();
  }
})();
