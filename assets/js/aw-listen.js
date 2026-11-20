/* AngelWrites listen mode: books, poems, reflections and the Bible read aloud by the device's own voice
   (the browser's speech synthesis: free, private, works offline, nothing is sent anywhere).
   A page calls AWListen.attach({ blocks: function () { return [elements] }, lang: 'en', start: 0,
   onBlock: function (el, i) {} }). The player reads one paragraph (or stanza, or verse) at a time,
   highlights it, keeps it in view, and remembers speed and voice on this device. */
(function () {
  'use strict';
  var synth = window.speechSynthesis;
  var AW = window.AWListen = { supported: !!(synth && window.SpeechSynthesisUtterance) };
  if (!AW.supported) { AW.attach = function () { return null; }; return; }

  var store = { get: function (k, d) { try { var v = localStorage.getItem('awl_' + k); return v === null ? d : v; } catch (e) { return d; } },
                set: function (k, v) { try { localStorage.setItem('awl_' + k, v); } catch (e) {} } };
  var ICON = { play: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>',
               pause: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>',
               prev: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 6h2v12H6zM9.5 12L18 18V6z"/></svg>',
               next: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M16 6h2v12h-2zM6 18l8.5-6L6 6z"/></svg>',
               stop: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M6 6h12v12H6z"/></svg>' };
  var cfg = null, blocks = [], idx = 0, parts = [], partIdx = 0, playing = false, paused = false, ui = null, voices = [], token = 0;

  function textOf(el) {
    var c = el.cloneNode(true);
    [].forEach.call(c.querySelectorAll('sup, .aw-stanza-num, .br-vn, [aria-hidden="true"], .sr-only, script, style, button'), function (x) { x.remove(); });
    // line breaks and poem lines become short pauses, so words are not run together
    [].forEach.call(c.querySelectorAll('br'), function (x) { x.replaceWith(', '); });
    [].forEach.call(c.querySelectorAll('.aw-line, div, p, li'), function (x) { x.append(' '); });
    return (c.textContent || '').replace(/\s+/g, ' ').replace(/\s+([,.;:!?])/g, '$1').replace(/,\s*,/g, ',').trim();
  }
  // long text is spoken in sentence-sized pieces (some browsers stop long utterances after ~15 s)
  function split(t) {
    var out = [], s = t.match(/[^.!?;:…]+[.!?;:…]*["'”’)]*\s*/g) || [t];
    s.forEach(function (x) {
      x = x.trim(); if (!x) return;
      while (x.length > 220) { var cut = x.lastIndexOf(' ', 200); if (cut < 60) cut = 200; out.push(x.slice(0, cut)); x = x.slice(cut).trim(); }
      if (x) out.push(x);
    });
    return out;
  }
  function pickVoice() {
    voices = synth.getVoices() || [];
    var want = store.get('voice', ''), lang = (cfg && cfg.lang || 'en').toLowerCase();
    var v = voices.filter(function (x) { return x.voiceURI === want; })[0];
    if (!v) v = voices.filter(function (x) { return x.lang && x.lang.toLowerCase().indexOf(lang) === 0 && x.localService; })[0]
             || voices.filter(function (x) { return x.lang && x.lang.toLowerCase().indexOf(lang) === 0; })[0]
             || voices.filter(function (x) { return x.default; })[0] || voices[0];
    return v || null;
  }
  function mark(i) {
    blocks.forEach(function (b, j) { b.classList.toggle('aw-listen-on', j === i); });
    var el = blocks[i]; if (!el) return;
    if (cfg.onBlock) cfg.onBlock(el, i);
    el.classList.toggle('awl-big', el.offsetHeight > innerHeight * 0.45); // a whole page: a quiet line instead of a background
    var r = el.getBoundingClientRect();
    if (el.offsetParent !== null && !el.classList.contains('awl-big') && (r.top < 80 || r.bottom > innerHeight - 120)) el.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
  }
  function speakPart() {
    var my = token;
    if (!playing) return;
    if (partIdx >= parts.length) { idx++; return startBlock(); }
    var u = new SpeechSynthesisUtterance(parts[partIdx]);
    var v = pickVoice(); u.lang = (v && v.lang) || cfg.lang || 'en'; if (v) { try { u.voice = v; } catch (e) {} }
    u.rate = parseFloat(store.get('rate', '1')) || 1;
    u.onend = function () { if (my !== token) return; partIdx++; speakPart(); };
    u.onerror = function (e) { if (my !== token || e.error === 'interrupted' || e.error === 'canceled') return; partIdx++; speakPart(); };
    synth.speak(u);
  }
  function startBlock() {
    while (idx < blocks.length && !textOf(blocks[idx])) idx++;
    if (idx >= blocks.length) { stop(true); return; }
    parts = split(textOf(blocks[idx])); partIdx = 0;
    mark(idx); update(); speakPart();
  }
  function play() {
    if (!cfg) return;
    if (paused) { paused = false; playing = true; synth.resume(); update(); return; }
    blocks = cfg.blocks() || [];
    if (!blocks.length) return;
    if (!playing) { idx = Math.max(0, Math.min(blocks.length - 1, typeof cfg.start === 'function' ? cfg.start(blocks) : (cfg.start || 0))); }
    playing = true; token++; synth.cancel(); startBlock();
  }
  function pause() { if (!playing) return; paused = true; playing = false; synth.pause(); update(); }
  function stop(finished) {
    token++; playing = false; paused = false; synth.cancel();
    blocks.forEach(function (b) { b.classList.remove('aw-listen-on'); });
    update(finished ? 'Finished. Press play to start again.' : '');
  }
  function jump(d) {
    if (!blocks.length) blocks = cfg.blocks() || [];
    idx = Math.max(0, Math.min(blocks.length - 1, idx + d));
    token++; synth.cancel(); paused = false; playing = true; startBlock();
  }

  function update(msg) {
    if (!ui) return;
    ui.root.classList.toggle('is-playing', playing);
    ui.play.setAttribute('aria-label', playing ? 'Pause' : (paused ? 'Resume' : 'Listen'));
    ui.play.innerHTML = playing ? ICON.pause : ICON.play;
    ui.label.textContent = msg || (playing ? 'Reading aloud' + (blocks.length > 1 ? ' · ' + (idx + 1) + ' of ' + blocks.length : '') : (paused ? 'Paused' : (cfg.label || 'Listen')));
  }
  function build() {
    if (!document.getElementById('awlStyle')) { var st = document.createElement('style'); st.id = 'awlStyle'; st.textContent = '.aw-listen{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:9980;display:flex;align-items:center;gap:8px;padding:6px 8px 6px 6px;border-radius:999px;background:#2c1e22;color:#fff;box-shadow:0 14px 34px rgba(30,18,18,.35);font:600 .9rem/1 Inter,system-ui,sans-serif;max-width:calc(100vw - 24px)}\n.aw-listen button{display:inline-grid;place-items:center;width:40px;height:40px;border-radius:50%;border:0;background:transparent;color:#fff;cursor:pointer;font-size:.95rem}\n.aw-listen button:hover,.aw-listen button:focus-visible{background:rgba(255,255,255,.14)}\n.aw-listen .awl-main{width:44px;height:44px;background:linear-gradient(135deg,#c98d8f,#9c5457);box-shadow:0 4px 12px rgba(156,84,87,.5)}\n.aw-listen .awl-main:hover{filter:brightness(1.1);background:linear-gradient(135deg,#c98d8f,#9c5457)}\n.aw-listen .awl-label{padding:0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:40vw}\n.aw-listen .awl-more{display:none;align-items:center;gap:2px}\n.aw-listen.is-open .awl-more{display:inline-flex}\n.aw-listen select{height:36px;border-radius:999px;border:1px solid rgba(255,255,255,.3)!important;background:rgba(255,255,255,.08)!important;color:#fff!important;padding:0 10px;font:600 .82rem Inter,system-ui,sans-serif;max-width:150px;box-shadow:none!important;background-image:none!important}\n.aw-listen select option{color:#2c1e22;background:#fff}\n.aw-listen .awl-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}\n.aw-listen.is-playing .awl-main{animation:awlPulse 2s ease-in-out infinite}\n@keyframes awlPulse{50%{box-shadow:0 0 0 8px rgba(201,141,143,.25)}}\n.aw-listen-on{background:rgba(214,168,92,.22)!important;border-radius:8px;box-shadow:0 0 0 4px rgba(214,168,92,.22);transition:background .3s}\n.aw-listen-on.awl-big{background:none!important;box-shadow:-6px 0 0 -2px #d6a85c;border-radius:0}\n@media (max-width:560px){.aw-listen{bottom:12px;flex-wrap:wrap;justify-content:center;border-radius:22px}.aw-listen .awl-label{max-width:46vw}.aw-listen .awl-voice{display:none}}\n@media (prefers-reduced-motion:reduce){.aw-listen.is-playing .awl-main{animation:none}}'; document.head.appendChild(st); }
    var root = document.createElement('div');
    root.className = 'aw-listen'; root.setAttribute('role', 'region'); root.setAttribute('aria-label', 'Listen');
    root.innerHTML =
      '<button type="button" class="awl-main" data-a="play" aria-label="Listen">' + ICON.play + '</button>' +
      '<span class="awl-label" aria-live="polite">Listen</span>' +
      '<span class="awl-more">' +
        '<button type="button" data-a="prev" aria-label="Previous paragraph">' + ICON.prev + '</button>' +
        '<button type="button" data-a="next" aria-label="Next paragraph">' + ICON.next + '</button>' +
        '<button type="button" data-a="stop" aria-label="Stop">' + ICON.stop + '</button>' +
        '<label class="awl-sel"><span class="awl-sr">Speed</span><select data-a="rate" aria-label="Reading speed">' +
          ['0.75', '0.9', '1', '1.15', '1.3', '1.5'].map(function (r) { return '<option value="' + r + '">' + r.replace(/^1$/, '1') + '×</option>'; }).join('') + '</select></label>' +
        '<label class="awl-sel awl-voice"><span class="awl-sr">Voice</span><select data-a="voice" aria-label="Voice"></select></label>' +
      '</span>';
    document.body.appendChild(root);
    ui = { root: root, play: root.querySelector('[data-a="play"]'), label: root.querySelector('.awl-label'), rate: root.querySelector('[data-a="rate"]'), voice: root.querySelector('[data-a="voice"]') };
    ui.rate.value = store.get('rate', '1');
    root.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-a]'); if (!b) return;
      var a = b.getAttribute('data-a');
      if (a === 'play') { root.classList.add('is-open'); playing ? pause() : play(); }
      else if (a === 'stop') stop(); else if (a === 'prev') jump(-1); else if (a === 'next') jump(1);
    });
    ui.rate.addEventListener('change', function () { store.set('rate', ui.rate.value); if (playing) { var keep = idx; token++; synth.cancel(); idx = keep; startBlock(); } });
    ui.voice.addEventListener('change', function () { store.set('voice', ui.voice.value); if (playing) { token++; synth.cancel(); startBlock(); } });
    fillVoices();
    if (typeof synth.onvoiceschanged !== 'undefined') synth.onvoiceschanged = fillVoices;
    window.addEventListener('pagehide', function () { synth.cancel(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (playing || paused)) stop(); });
  }
  function fillVoices() {
    if (!ui) return;
    voices = synth.getVoices() || [];
    var lang = (cfg && cfg.lang || 'en').toLowerCase(), cur = pickVoice();
    var mine = voices.filter(function (v) { return v.lang && v.lang.toLowerCase().indexOf(lang) === 0; });
    var list = mine.length ? mine : voices;
    ui.voice.innerHTML = list.map(function (v) { return '<option value="' + v.voiceURI.replace(/"/g, '&quot;') + '">' + (v.name + ' (' + v.lang + ')').replace(/</g, '&lt;') + '</option>'; }).join('');
    if (cur) ui.voice.value = cur.voiceURI;
    ui.voice.parentNode.hidden = list.length < 2;
  }

  AW.attach = function (c) {
    cfg = c || {};
    if (!ui) build();
    update();
    return { play: play, pause: pause, stop: stop };
  };
  AW.stop = function () { stop(); };
})();
