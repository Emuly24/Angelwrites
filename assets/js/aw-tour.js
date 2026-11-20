/* Guided tours (includes/tours.php prints window.AW_TOUR). A spotlight on one part of the page and a
   card beside it: Back, Next, × (hide for now), Turn off. Steps on other pages are reached through
   tour.php, which remembers the step. Keyboard: Esc hides, arrow keys move. No polling. */
(function () {
  var T = window.AW_TOUR;
  if (!T) return;
  var css = '.awt-shade{position:fixed;inset:0;z-index:9990;pointer-events:none}' +
    '.awt-spot{position:fixed;z-index:9991;border-radius:12px;box-shadow:0 0 0 4px #f0b9ba,0 0 0 9999px rgba(30,18,20,.55);transition:all .25s ease;pointer-events:none}' +
    '.awt-spot.is-none{box-shadow:0 0 0 9999px rgba(30,18,20,.55);width:0!important;height:0!important;left:50%!important;top:50%!important}' +
    '.awt-card{position:fixed;z-index:9992;width:min(380px,calc(100vw - 32px));background:#fff;color:#3d2e2e;border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.3);padding:18px 18px 14px;font:15px/1.55 Inter,system-ui,sans-serif}' +
    '.awt-card:focus{outline:none}.awt-kicker{margin:0 0 4px;font-size:.75rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9c5457}' +
    '.awt-card h2{margin:0 0 8px;font:600 1.15rem/1.3 "Playfair Display",Georgia,serif;color:#3d2e2e}.awt-card p{margin:0 0 8px}' +
    '.awt-bar{height:4px;border-radius:4px;background:#f1e4e4;margin:10px 0 12px;overflow:hidden}.awt-bar i{display:block;height:100%;background:#9c5457}' +
    '.awt-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.awt-acts button,.awt-pill a,.awt-pill button{font:inherit;cursor:pointer;min-height:40px;border-radius:999px;padding:0 16px;font-weight:600}' +
    '.awt-next{background:#9c5457;color:#fff;border:0;margin-left:auto}.awt-back{background:#fff;color:#9c5457;border:1px solid #d9b3b4}' +
    '.awt-off{background:none;border:0;color:#6b5757;text-decoration:underline;padding:0 4px!important;font-weight:500!important;font-size:.85rem}' +
    '.awt-x{position:absolute;top:8px;right:8px;width:36px;height:36px;border-radius:50%;border:0;background:transparent;color:#6b5757;font-size:1.3rem;cursor:pointer}' +
    '.awt-card button:focus-visible,.awt-pill a:focus-visible,.awt-pill button:focus-visible{outline:3px solid #3d2e2e;outline-offset:2px}' +
    '.awt-pill{position:fixed;z-index:9989;left:50%;transform:translateX(-50%);bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:calc(100vw - 32px);background:#fff;color:#3d2e2e;border-radius:999px;box-shadow:0 8px 28px rgba(0,0,0,.22);padding:6px 6px 6px 16px;font:14px/1.3 Inter,system-ui,sans-serif}' +
    '.awt-pill a{display:inline-flex;align-items:center;background:#9c5457;color:#fff;text-decoration:none}.awt-pill button{background:none;border:0;color:#6b5757;text-decoration:underline;font-weight:500}' +
    'html[data-theme="dark"] .awt-card,html[data-theme="dark"] .awt-pill{background:#2a2122;color:#f3e6e6}html[data-theme="dark"] .awt-card h2{color:#f8eded}' +
    'html[data-theme="dark"] .awt-kicker{color:#f0b9ba}html[data-theme="dark"] .awt-back{background:#2a2122;color:#f0b9ba;border-color:#6a4a4c}html[data-theme="dark"] .awt-off,html[data-theme="dark"] .awt-x,html[data-theme="dark"] .awt-pill button{color:#d8c3c3}' +
    '@media (max-width:600px){.awt-card{left:16px!important;right:16px;bottom:16px;top:auto!important;width:auto}.awt-pill{bottom:84px;width:calc(100vw - 32px);border-radius:18px}}' +
    '@media (prefers-reduced-motion:reduce){.awt-spot{transition:none}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function post(action, step) {
    var fd = new FormData(); fd.append('action', action); fd.append('tour', T.tour); fd.append('csrf_token', T.token);
    if (step !== undefined) fd.append('step', step);
    return fetch(T.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': T.token } })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }
  function goStep(i) { post('step', i).then(function (r) { if (r && r.url) location.href = r.url; }); }
  function turnOff() { post('off').then(function () { cleanup(); }); }

  var spot, card, pill, idx = 0, steps = T.steps || [], keyHandler;
  function cleanup() { [spot, card, pill].forEach(function (x) { if (x && x.parentNode) x.parentNode.removeChild(x); }); if (keyHandler) document.removeEventListener('keydown', keyHandler); }

  function showPill(text, href, label) {
    pill = document.createElement('div'); pill.className = 'awt-pill'; pill.setAttribute('role', 'region'); pill.setAttribute('aria-label', T.title);
    var s = document.createElement('span'); s.textContent = text; pill.appendChild(s);
    var a = document.createElement('a'); a.href = href; a.textContent = label;
    if (href === '#') a.addEventListener('click', function (e) { e.preventDefault(); cleanup(); open(idx); });
    pill.appendChild(a);
    var off = document.createElement('button'); off.type = 'button'; off.textContent = 'Turn off'; off.addEventListener('click', turnOff); pill.appendChild(off);
    document.body.appendChild(pill);
  }

  if (!T.here) { showPill(T.title + ': step ' + (T.current + 1) + ' of ' + T.total + ', ' + T.resumeTitle, T.resumeUrl, 'Continue'); return; }

  function find(sels) {
    for (var k = 0; k < sels.length; k++) {
      try { var el = document.querySelector(sels[k]); if (el) { var r = el.getBoundingClientRect(); if (r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden') return el; } } catch (e) {}
    }
    return null;
  }
  function place(el) {
    var vw = window.innerWidth, vh = window.innerHeight;
    if (!el) { spot.classList.add('is-none'); card.style.left = Math.max(16, (vw - card.offsetWidth) / 2) + 'px'; card.style.top = Math.max(16, (vh - card.offsetHeight) / 2) + 'px'; return; }
    spot.classList.remove('is-none');
    var r = el.getBoundingClientRect(), pad = 6;
    spot.style.left = (r.left - pad) + 'px'; spot.style.top = (r.top - pad) + 'px'; spot.style.width = (r.width + pad * 2) + 'px'; spot.style.height = (r.height + pad * 2) + 'px';
    var cw = card.offsetWidth, ch = card.offsetHeight, top;
    if (r.bottom + 14 + ch < vh) top = r.bottom + 14; else if (r.top - 14 - ch > 0) top = r.top - 14 - ch; else top = Math.max(16, vh - ch - 16);
    var left = Math.min(Math.max(16, r.left), vw - cw - 16);
    card.style.left = left + 'px'; card.style.top = top + 'px';
  }
  function render() {
    var s = steps[idx];
    if (s.click) { try { var c = document.querySelector(s.click); if (c && !c.classList.contains('active')) c.click(); } catch (e) {} }
    var el = find(s.sel || []);
    card.innerHTML = '';
    var x = document.createElement('button'); x.type = 'button'; x.className = 'awt-x'; x.setAttribute('aria-label', 'Hide the tour for now'); x.textContent = '×'; x.addEventListener('click', hide); card.appendChild(x);
    var k = document.createElement('p'); k.className = 'awt-kicker'; k.textContent = T.title + (T.chapter ? ' · ' + T.chapter : '') + ' · ' + (s.i + 1) + ' of ' + T.total; card.appendChild(k);
    var h = document.createElement('h2'); h.id = 'awtTitle'; h.textContent = s.title; card.appendChild(h);
    String(s.body).split(/\n\n+/).forEach(function (para) { var p = document.createElement('p'); p.textContent = para; card.appendChild(p); });
    var bar = document.createElement('div'); bar.className = 'awt-bar'; bar.setAttribute('aria-hidden', 'true'); var bi = document.createElement('i'); bi.style.width = Math.round((s.i + 1) / T.total * 100) + '%'; bar.appendChild(bi); card.appendChild(bar);
    var acts = document.createElement('div'); acts.className = 'awt-acts';
    var off = document.createElement('button'); off.type = 'button'; off.className = 'awt-off'; off.textContent = 'Turn off tour'; off.addEventListener('click', turnOff); acts.appendChild(off);
    if (s.i > 0) { var b = document.createElement('button'); b.type = 'button'; b.className = 'awt-back'; b.textContent = 'Back'; b.addEventListener('click', back); acts.appendChild(b); }
    var last = s.i === T.total - 1;
    var n = document.createElement('button'); n.type = 'button'; n.className = 'awt-next'; n.textContent = last ? 'Finish' : 'Next'; n.addEventListener('click', next); acts.appendChild(n);
    card.appendChild(acts);
    if (el) el.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    setTimeout(function () { place(el); }, el ? 320 : 0);
    card.focus();
    post('step', s.i); // remember where the person is, so the tour resumes here
  }
  function next() {
    var s = steps[idx];
    if (s.i === T.total - 1) { post('done').then(cleanup); return; }
    if (idx < steps.length - 1) { idx++; render(); } else goStep(s.i + 1);
  }
  function back() { if (idx > 0) { idx--; render(); } else goStep(steps[idx].i - 1); }
  function hide() { cleanup(); showPill(T.title + ' is paused', '#', 'Show again'); }
  function open(i) {
    spot = document.createElement('div'); spot.className = 'awt-spot'; document.body.appendChild(spot);
    card = document.createElement('div'); card.className = 'awt-card'; card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'false'); card.setAttribute('aria-labelledby', 'awtTitle'); card.tabIndex = -1;
    document.body.appendChild(card);
    idx = i; render();
    keyHandler = function (e) { if (e.key === 'Escape') hide(); else if (e.key === 'ArrowRight' && document.activeElement && card.contains(document.activeElement)) next(); else if (e.key === 'ArrowLeft' && document.activeElement && card.contains(document.activeElement)) back(); };
    document.addEventListener('keydown', keyHandler);
  }
  var startAt = 0; steps.forEach(function (s, k) { if (s.i === T.current) startAt = k; });
  var reflow = function () { if (card && card.parentNode) place(find(steps[idx].sel || [])); };
  window.addEventListener('resize', reflow); window.addEventListener('scroll', reflow, { passive: true });
  function start() { setTimeout(function () { open(startAt); }, 350); }
  if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();
