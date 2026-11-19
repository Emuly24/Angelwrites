/* AngelWrites contrast guard (every page that uses the site header).
   A safety net so text and symbols never disappear when the theme changes: after the page loads, and
   again whenever the theme switches, any text or icon whose colour reads poorly on what is behind it
   (below 4.5:1 for text, 3:1 for large text and icons) is given whichever of the site's dark ink or
   white reads better there. Pictures behind text are left alone (they cannot be measured), and an
   element can opt out with class="aw-no-guard". It only ever changes colours. */
(function () {
  'use strict';
  var INK = [44, 30, 30], WHITE = [255, 255, 255];
  function parse(s) {
    if (!s) return null;
    var m = s.match(/[\d.]+/g); if (!m) return null;
    m = m.map(Number);
    if (/^color\(srgb/.test(s)) return [m[0] * 255, m[1] * 255, m[2] * 255, m.length > 3 ? m[3] : 1];
    return [m[0], m[1], m[2], m.length > 3 ? m[3] : 1];
  }
  function lum(c) {
    function f(v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function blend(fg, bg) { var a = fg[3] == null ? 1 : fg[3]; return [0, 1, 2].map(function (i) { return fg[i] * a + bg[i] * (1 - a); }); }
  function gradient(img) {
    if (/url\(/.test(img)) return null; // a picture: unknown
    var cols = (img.match(/rgba?\([^)]*\)|color\(srgb[^)]*\)/g) || []).map(parse).filter(function (c) { return c && c[3] >= 0.5; });
    if (!cols.length) return 'clear';
    return [0, 1, 2].map(function (i) { return cols.reduce(function (s, c) { return s + c[i]; }, 0) / cols.length; });
  }
  // the colour behind an element, or null when it cannot be known (a picture)
  function backdrop(el) {
    var stack = [], node = el;
    while (node && node.nodeType === 1) {
      var cs = getComputedStyle(node);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') {
        var g = gradient(cs.backgroundImage);
        if (g === null) return null;
        if (g !== 'clear') { stack.push(g.concat(1)); break; }
      }
      var c = parse(cs.backgroundColor);
      if (c && c[3] > 0) { stack.push(c); if (c[3] >= 0.95) break; }
      node = node.parentElement;
    }
    var base = WHITE;
    if (!node || node.nodeType !== 1) { var b = parse(getComputedStyle(document.body).backgroundColor); base = b && b[3] > 0 ? b : WHITE; }
    for (var i = stack.length - 1; i >= 0; i--) base = blend(stack[i], base);
    return base;
  }
  function candidates() {
    var out = [], all = document.body ? document.body.getElementsByTagName('*') : [];
    for (var i = 0; i < all.length; i++) {
      var e = all[i], tag = e.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'SVG' || tag === 'IMG' || tag === 'VIDEO' || tag === 'CANVAS' || tag === 'IFRAME') continue;
      var icon = tag === 'I' && /\bfa-/.test(e.className);
      var text = false;
      if (!icon) for (var n = e.firstChild; n; n = n.nextSibling) if (n.nodeType === 3 && n.nodeValue.trim().length > 0) { text = true; break; }
      if (icon || text) out.push([e, icon]);
    }
    return out;
  }
  function run() {
    var t0 = performance.now();
    document.querySelectorAll('[data-aw-guard]').forEach(function (e) { e.style.removeProperty('color'); e.removeAttribute('data-aw-guard'); });
    var list = candidates(), fixes = [];
    for (var i = 0; i < list.length; i++) {
      var e = list[i][0];
      if (e.closest('.aw-no-guard, #reader-app, .bible-app, [contenteditable="true"], .ql-editor, .tox, .mce-content-body')) continue;
      if (!e.getClientRects().length) continue; // not on screen (a closed menu, a hidden tab)
      var cs = getComputedStyle(e);
      if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity < 0.2) continue;
      if (cs.webkitTextFillColor && /rgba?\(0, 0, 0, 0\)/.test(cs.webkitTextFillColor)) continue; // gradient text
      var fg = parse(cs.color); if (!fg) continue;
      // icons drawn as masks paint their own background in the text colour: measure what is behind them
      var bg = backdrop(list[i][1] ? (e.parentElement || e) : e); if (!bg) continue;
      var size = parseFloat(cs.fontSize), big = size >= 24 || (size >= 18.6 && +cs.fontWeight >= 700);
      var need = (list[i][1] || big) ? 3 : 4.5;
      if (ratio(blend(fg, bg), bg) >= need) continue;
      fixes.push([e, ratio(INK, bg) >= ratio(WHITE, bg) ? 'rgb(44, 30, 30)' : 'rgb(255, 255, 255)']);
    }
    // write after reading, so the page lays out once
    fixes.forEach(function (f) { f[0].style.setProperty('color', f[1], 'important'); f[0].setAttribute('data-aw-guard', '1'); });
    if (window.AW_GUARD_DEBUG) console.log('contrast guard: ' + fixes.length + ' fixed in ' + Math.round(performance.now() - t0) + ' ms');
  }
  var timer = null;
  function soon(ms) { clearTimeout(timer); timer = setTimeout(function () { (window.requestIdleCallback || function (f) { f(); })(run); }, ms || 120); }
  function start() {
    soon(250);
    new MutationObserver(function () { soon(80); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.addEventListener('load', function () { soon(400); });
    document.addEventListener('aw:content', function () { soon(150); }); // pages can ask for a re-check after adding content
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.awGuard = run;
})();
