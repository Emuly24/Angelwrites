/* B2: the Bible reader. No framework; every request is the passage itself or a member's own data. */
(function () {
  'use strict';
  var C = window.AW_BIBLE, site = C.site, doc = document, root = doc.documentElement;
  var $ = function (id) { return doc.getElementById(id); };
  var st = { v: C.v, b: C.b, c: C.c, vs: C.vs, par: null, books: C.books, sel: null, hl: {}, notes: {} };
  var S = load('awBible', {});

  function load(k, d) { try { return JSON.parse(localStorage.getItem(k) || 'null') || d; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ver(code) { for (var i = 0; i < C.versions.length; i++) if (C.versions[i].code === code) return C.versions[i]; return null; }
  function bookIx(b) { for (var i = 0; i < st.books.length; i++) if (st.books[i].book === b) return i; return -1; }
  function bookName(b) { var i = bookIx(b); return i < 0 ? b : st.books[i].name; }
  function api(q) { return fetch(site + '/bible_api.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function post(action, data) {
    var f = new FormData(); f.append('action', action); f.append('csrf_token', C.csrf);
    Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
    return fetch(site + '/bible_reader_ajax.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
  }
  function toast(msg) { var t = $('brToast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(function () { t.hidden = true; }, 2600); }

  /* ---------- settings ---------- */
  var DEF = { theme: root.getAttribute('data-br-theme') || 'light', size: 3, leading: 2, width: 2, font: 'serif', nums: 1 };
  function setting(k) { return S[k] != null ? S[k] : DEF[k]; }
  function applySettings() {
    ['theme', 'size', 'leading', 'width', 'font', 'nums'].forEach(function (k) { root.setAttribute(k === 'theme' ? 'data-br-theme' : 'data-br-' + k, setting(k)); });
    var m = doc.querySelector('meta[name=theme-color]'); if (m) m.content = getComputedStyle(doc.body).backgroundColor;
    var form = $('brSettings');
    ['theme', 'font', 'leading', 'width'].forEach(function (k) { var i = form.querySelector('input[name=' + k + '][value="' + setting(k) + '"]'); if (i) i.checked = true; });
    $('brNums').checked = String(setting('nums')) !== '0';
    $('brSizeOut').value = setting('size');
  }
  function set(k, v) { S[k] = v; save('awBible', S); applySettings(); }
  $('brSettings').addEventListener('change', function (e) {
    var t = e.target; if (t.name) set(t.name, t.value); if (t.id === 'brNums') set('nums', t.checked ? 1 : 0);
  });
  doc.querySelectorAll('[data-step]').forEach(function (b) {
    b.addEventListener('click', function () { var p = b.dataset.step.split(':'); set(p[0], Math.max(1, Math.min(6, +setting(p[0]) + +p[1]))); });
  });

  /* ---------- sheets ---------- */
  var opener = null;
  function openSheet(id, from) { closeAll(); opener = from || doc.activeElement; var s = $(id); s.hidden = false; var f = s.querySelector('[autofocus], input, [aria-selected=true], button:not([data-close])'); if (f) f.focus(); showBar(); }
  function closeAll() {
    var any = false;
    doc.querySelectorAll('.br-sheet, .br-pop').forEach(function (s) { if (!s.hidden) { s.hidden = true; any = true; } });
    if (st.sel) { var el = $('v' + st.sel); if (el) el.classList.remove('is-sel'); st.sel = null; }
    if (any && opener && doc.contains(opener)) opener.focus();
    return any;
  }
  doc.addEventListener('click', function (e) {
    if (e.target.closest('[data-close]')) closeAll();
    else if (e.target.classList && e.target.classList.contains('br-sheet')) closeAll();
  });
  // keep Tab inside an open sheet
  doc.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') return;
    var s = doc.querySelector('.br-sheet:not([hidden])'); if (!s) return;
    var f = s.querySelectorAll('button, [href], input, textarea, select'); if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /* ---------- the bar steps aside while reading ---------- */
  var bar = $('brBar'), lastY = scrollY, idle, navAt = 0;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function showBar() { bar.classList.remove('is-away'); clearTimeout(idle); if (!reduce) idle = setTimeout(function () { if (scrollY > 120 && !doc.querySelector('.br-sheet:not([hidden]), .br-pop:not([hidden])')) bar.classList.add('is-away'); }, 3000); }
  addEventListener('scroll', function () {
    var y = scrollY;
    if (Date.now() - navAt < 1500) { lastY = y; return; } // the page moved itself (a jump to a verse): keep the bar
    if (!reduce && y > lastY + 6 && y > 120) bar.classList.add('is-away'); else if (y < lastY - 6) showBar();
    lastY = y;
  }, { passive: true });
  addEventListener('mousemove', function (e) { if (e.clientY < 80) showBar(); }, { passive: true });
  bar.addEventListener('focusin', showBar);

  /* ---------- rendering ---------- */
  function versesHtml(ch) {
    var h = '', open = false;
    if (ch.title) h += '<p class="br-title">' + esc(ch.title) + '</p>';
    ch.verses.forEach(function (v) {
      v.heads.forEach(function (hd) { if (open) { h += '</p>'; open = false; } h += '<h2 class="br-head' + (hd.level > 1 ? ' is-minor' : '') + '">' + esc(hd.text) + '</h2>'; });
      if (!open || v.para) { if (open) h += '</p>'; h += '<p class="br-para">'; open = true; }
      var poem = v.text.indexOf('\n') > -1;
      h += '<span class="br-v' + (poem ? ' is-poem' : '') + '" id="v' + v.v + '" data-v="' + v.v + '" tabindex="-1"><sup class="br-num" aria-hidden="true">' + v.v + '</sup><span class="sr-only">verse ' + v.v + ' </span>'
        + esc(v.text).replace(/\n/g, '<br>') + '</span> ';
    });
    if (open) h += '</p>';
    return h;
  }
  function parallelHtml(a, b, va, vb) {
    var map = {}; b.verses.forEach(function (v) { map[v.v] = v.text; });
    var h = '<div class="br-par-cap" aria-hidden="true"><span>' + esc(va.short) + '</span><span>' + esc(vb.short) + '</span></div>';
    a.verses.forEach(function (v) {
      h += '<div class="br-par-row br-v" id="v' + v.v + '" data-v="' + v.v + '" tabindex="-1"><div lang="' + va.lang + '"><sup class="br-num">' + v.v + '</sup>' + esc(v.text).replace(/\n/g, '<br>') + '</div>'
        + '<div lang="' + vb.lang + '">' + (map[v.v] != null ? esc(map[v.v]).replace(/\n/g, '<br>') : '<em class="br-hint">Not in this version</em>') + '</div></div>';
    });
    return h;
  }
  function header(name) {
    $('brChapterTitle').innerHTML = '<span class="br-chapter-book">' + esc(name) + '</span> <span class="br-chapter-num">' + st.c + '</span>';
    $('brRefLabel').textContent = name + ' ' + st.c;
    var vv = ver(st.v); $('brVerLabel').textContent = vv ? vv.short : st.v; $('brVerBtn').setAttribute('aria-label', 'Version: ' + (vv ? vv.name : st.v));
    $('brCredit').innerHTML = vv ? esc(vv.credit) + (vv.license ? ' <a href="' + esc(vv.license) + '" rel="license noopener" target="_blank">Licence</a>' : '') + (st.par && ver(st.par) ? '<br>' + esc(ver(st.par).credit) : '') : '';
    doc.title = name + ' ' + st.c + ' · ' + (vv ? vv.short : st.v) + ' · AngelWrites Bible';
    $('brText').lang = vv ? vv.lang : 'en';
    var i = bookIx(st.b);
    $('brPrev').disabled = i <= 0 && st.c <= 1;
    $('brNext').disabled = i === st.books.length - 1 && st.c >= st.books[i].chapters;
  }
  function go(b, c, vs, opts) {
    opts = opts || {};
    closeAll();
    var want = st.par ? Promise.all([api('a=chapter&v=' + st.v + '&b=' + encodeURIComponent(b) + '&c=' + c), api('a=chapter&v=' + st.par + '&b=' + encodeURIComponent(b) + '&c=' + c)])
                      : api('a=chapter&v=' + st.v + '&b=' + encodeURIComponent(b) + '&c=' + c).then(function (r) { return [r]; });
    return want.then(function (rs) {
      var a = rs[0];
      if (!a.ok) { toast(a.error || 'That chapter could not be opened.'); return; }
      st.b = b; st.c = c; st.vs = vs || 0;
      var cols = $('brCols');
      if (st.par && rs[1]) {
        cols.classList.add('is-par');
        $('brBody').innerHTML = rs[1].ok ? parallelHtml(a, rs[1], ver(st.v), ver(st.par)) : versesHtml(a) + '<p class="br-hint">This chapter is not in ' + esc(ver(st.par).short) + '.</p>';
      } else { cols.classList.remove('is-par'); $('brBody').innerHTML = versesHtml(a); }
      header(a.book_name);
      var url = site + '/bible_reader.php?v=' + st.v + '&b=' + encodeURIComponent(b) + '&c=' + c + (st.vs ? '&vs=' + st.vs : '');
      if (!opts.noHistory) history[opts.replace ? 'replaceState' : 'pushState']({ b: b, c: c, vs: st.vs }, '', url);
      var r = $('brResume'); if (r && !opts.first) r.remove();
      if (st.vs && $('v' + st.vs)) { var t = $('v' + st.vs); t.scrollIntoView({ block: 'center' }); t.classList.add('is-target'); t.focus({ preventScroll: true }); }
      else if (!opts.first) scrollTo(0, 0);
      navAt = Date.now(); remember(); decorate(); showBar();
    }).catch(function () { toast('You seem to be offline. The chapter could not load.'); });
  }
  function step(d) {
    var i = bookIx(st.b), c = st.c + d;
    if (c < 1) { if (i <= 0) return; i--; c = st.books[i].chapters; }
    else if (c > st.books[i].chapters) { if (i >= st.books.length - 1) return; i++; c = 1; }
    go(st.books[i].book, c, 0);
  }
  $('brPrev').addEventListener('click', function () { step(-1); });
  $('brNext').addEventListener('click', function () { step(1); });
  addEventListener('popstate', function (e) { if (e.state && e.state.b) go(e.state.b, e.state.c, e.state.vs, { noHistory: true }); });

  /* ---------- the place you were ---------- */
  function remember() {
    save('awBiblePlace', { v: st.v, b: st.b, c: st.c, par: st.par });
    if (C.member) post('save_position', { book: st.b, chapter: st.c, verse: st.vs || 0, percent: 0 }).catch(function () {});
  }

  /* ---------- members' highlights and notes on this chapter ---------- */
  function decorate() {
    var key = st.b + ' ' + st.c;
    if (!C.member) { var loc = load('awBibleHl', {}); paint(loc[key] || {}); return; }
    fetch(site + '/bible_reader_ajax.php?action=get_highlights&book=' + encodeURIComponent(st.b) + '&chapter=' + st.c, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) { var m = {}; (d.highlights || []).forEach(function (h) { m[h.verse] = h.color; }); paint(m); }).catch(function () {});
    fetch(site + '/bible_reader_ajax.php?action=get_notes&book=' + encodeURIComponent(st.b) + '&chapter=' + st.c, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        st.notes = {}; ((d && d.notes) || []).forEach(function (n) { (st.notes[n.verse] = st.notes[n.verse] || []).push(n); });
        doc.querySelectorAll('.br-v').forEach(function (el) { el.classList.toggle('has-note', !!st.notes[el.dataset.v]); });
      }).catch(function () {});
  }
  function paint(m) { st.hl = m; doc.querySelectorAll('.br-v').forEach(function (el) { var c = m[el.dataset.v]; if (c) el.setAttribute('data-hl', c); else el.removeAttribute('data-hl'); }); }

  /* ---------- a verse ---------- */
  var menu = $('brVerseMenu');
  $('brMain').addEventListener('click', function (e) {
    if (window.getSelection && String(getSelection()).length > 2) return; // reading or copying a selection
    var v = e.target.closest('.br-v');
    if (!v) { if (!e.target.closest('a, button')) { bar.classList.contains('is-away') ? showBar() : null; } return; }
    selectVerse(+v.dataset.v, v);
  });
  $('brMain').addEventListener('keydown', function (e) { var v = e.target.closest && e.target.closest('.br-v'); if (v && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); selectVerse(+v.dataset.v, v); } });
  function selectVerse(n, el) {
    if (st.sel === n) { closeAll(); return; }
    closeAll(); opener = el; st.sel = n; el.classList.add('is-sel');
    $('brVmTitle').textContent = bookName(st.b) + ' ' + st.c + ':' + n;
    menu.querySelectorAll('.br-tint').forEach(function (t) { t.classList.toggle('is-on', st.hl[n] === t.dataset.tint); });
    $('brVmHint').hidden = C.member;
    menu.hidden = false; menu.querySelector('.br-tint').focus();
  }
  menu.addEventListener('click', function (e) {
    var t = e.target.closest('.br-tint'), a = e.target.closest('[data-act]'), n = st.sel; if (!n) return;
    if (t) highlight(n, t.dataset.tint);
    if (a) ({ note: openNote, bookmark: bookmark, copy: copyVerse, share: shareVerse })[a.dataset.act](n);
  });
  function highlight(n, tint) {
    var el = $('v' + n), had = st.hl[n];
    if (tint === 'none') delete st.hl[n]; else st.hl[n] = tint;
    paint(st.hl); menu.querySelectorAll('.br-tint').forEach(function (b) { b.classList.toggle('is-on', st.hl[n] === b.dataset.tint); });
    if (!C.member) { var all = load('awBibleHl', {}); all[st.b + ' ' + st.c] = st.hl; save('awBibleHl', all); return; }
    var p = tint === 'none' ? (had ? post('toggle_highlight', { book: st.b, chapter: st.c, verse: n }) : Promise.resolve({ success: true }))
                            : post('add_highlight', { book: st.b, chapter: st.c, verse: n, color: tint });
    p.then(function (r) { if (!r || r.success === false) toast((r && r.error) || 'That was not saved.'); }).catch(function () { toast('That was not saved. Check your connection.'); });
    if (el) el.focus({ preventScroll: true });
  }
  function verseText(n) { var el = $('v' + n); if (!el) return ''; var c = el.cloneNode(true); c.querySelectorAll('sup, .sr-only').forEach(function (x) { x.remove(); }); return c.textContent.replace(/\s+/g, ' ').trim(); }
  function ref(n) { return bookName(st.b) + ' ' + st.c + ':' + n + ' (' + (ver(st.v) || {}).short + ')'; }
  function link(n) { return site + '/bible_reader.php?v=' + st.v + '&b=' + encodeURIComponent(st.b) + '&c=' + st.c + '&vs=' + n; }
  function copyVerse(n) {
    var text = '“' + verseText(n) + '” ' + ref(n);
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast('Copied'); }).catch(function () { toast('Select the verse and copy it'); });
  }
  function shareVerse(n) {
    var data = { title: ref(n), text: '“' + verseText(n) + '” ' + ref(n), url: link(n) };
    if (navigator.share) navigator.share(data).catch(function () {});
    else (navigator.clipboard ? navigator.clipboard.writeText(data.text + '\n' + data.url) : Promise.reject()).then(function () { toast('Link copied'); }).catch(function () {});
  }
  function bookmark(n) {
    if (!C.member) { toast('Sign in to keep bookmarks'); return; }
    post('add_bookmark', { book: st.b, chapter: st.c, verse: n }).then(function (r) {
      if (r.success) { toast('Bookmarked. Find it on your dashboard.'); return; }
      if (/already/i.test(r.error || '')) return post('remove_bookmark', { book: st.b, chapter: st.c, verse: n }).then(function () { toast('Bookmark removed'); });
      toast(r.error || 'That was not saved.');
    }).catch(function () { toast('That was not saved.'); });
  }

  /* ---------- notes ---------- */
  var noteFor = 0;
  function openNote(n) {
    if (!C.member) { toast('Sign in to write notes'); return; }
    noteFor = n; $('brNoteTitle').textContent = 'Note on ' + bookName(st.b) + ' ' + st.c + ':' + n;
    $('brNoteText').value = ''; listNotes(); openSheet('brNote', $('v' + n)); $('brNoteText').focus();
  }
  function listNotes() {
    var list = st.notes[noteFor] || [];
    $('brNoteList').innerHTML = list.map(function (x) {
      return '<div class="br-note"><small>' + (x.is_private == 1 ? 'Only you' : esc(x.display_name || x.username || 'A member')) + ' · ' + esc((x.created_at || '').slice(0, 10)) + '</small>' + esc(x.text)
        + (x.mine ? '<br><button type="button" data-del="' + x.id + '">Delete</button>' : '') + '</div>';
    }).join('');
  }
  $('brNoteList').addEventListener('click', function (e) {
    var d = e.target.closest('[data-del]'); if (!d || !confirm('Delete this note?')) return;
    post('delete_note', { note_id: d.dataset.del }).then(function (r) { if (r.success) { decorate(); setTimeout(listNotes, 400); toast('Note deleted'); } else toast(r.error || 'Not deleted'); });
  });
  $('brNoteForm').addEventListener('submit', function (e) {
    e.preventDefault(); var t = $('brNoteText').value.trim(); if (!t) { $('brNoteText').focus(); return; }
    post('add_note', { book: st.b, chapter: st.c, verse: noteFor, text: t, is_private: $('brNotePrivate').checked ? 1 : 0 }).then(function (r) {
      if (!r.success) { toast(r.error || 'The note was not saved.'); return; }
      toast('Note saved'); closeAll(); decorate();
    }).catch(function () { toast('The note was not saved.'); });
  });

  /* ---------- book and chapter ---------- */
  var tab = 'nt';
  function drawBooks() {
    var cur = st.books[bookIx(st.b)]; tab = tab || (cur ? cur.t : 'nt');
    $('brTabOt').setAttribute('aria-selected', tab === 'ot'); $('brTabNt').setAttribute('aria-selected', tab === 'nt');
    var bs = st.books.filter(function (x) { return x.t === tab; });
    $('brBooks').innerHTML = bs.length ? bs.map(function (x) {
      return '<button type="button" role="listitem" class="br-book' + (x.book === st.b ? ' is-current' : '') + '" data-book="' + esc(x.book) + '">' + esc(x.name) + (x.name !== x.book ? '<small>' + esc(x.book) + '</small>' : '') + '</button>';
    }).join('') : '<p class="br-hint">This version has only the New Testament.</p>';
    $('brBooks').hidden = false; $('brChapters').hidden = true;
  }
  function drawChapters(b) {
    var x = st.books[bookIx(b)]; $('brChBook').textContent = x.name; var h = '';
    for (var i = 1; i <= x.chapters; i++) h += '<button type="button" role="listitem" class="br-ch' + (b === st.b && i === st.c ? ' is-current' : '') + '" data-ch="' + i + '" data-book="' + esc(b) + '" aria-label="' + esc(x.name) + ' ' + i + '">' + i + '</button>';
    $('brChGrid').innerHTML = h; $('brBooks').hidden = true; $('brChapters').hidden = false;
    var f = $('brChGrid').querySelector('.is-current') || $('brChGrid').firstChild; if (f) f.focus();
  }
  $('brRefBtn').addEventListener('click', function () { var cur = st.books[bookIx(st.b)]; tab = cur ? cur.t : 'nt'; drawBooks(); openSheet('brChooser', this); });
  doc.querySelector('.br-tabs').addEventListener('click', function (e) { var b = e.target.closest('[data-t]'); if (b) { tab = b.dataset.t; drawBooks(); b.focus(); } });
  $('brBooks').addEventListener('click', function (e) { var b = e.target.closest('[data-book]'); if (b) { var x = st.books[bookIx(b.dataset.book)]; if (x.chapters === 1) go(x.book, 1, 0); else drawChapters(b.dataset.book); } });
  $('brChGrid').addEventListener('click', function (e) { var b = e.target.closest('[data-ch]'); if (b) go(b.dataset.book, +b.dataset.ch, 0); });
  $('brBackBooks').addEventListener('click', drawBooks);

  /* ---------- versions ---------- */
  function drawVersions() {
    $('brVerList').innerHTML = C.versions.map(function (x) {
      return '<button type="button" class="br-ver-opt" data-ver="' + x.code + '" aria-pressed="' + (x.code === st.v) + '"><span class="br-ver-code">' + esc(x.code) + '</span><b>' + esc(x.name) + '</b><small>' + esc(x.language) + (x.scope === 'nt' ? ' · New Testament' : '') + '</small></button>';
    }).join('');
    $('brParList').innerHTML = '<button type="button" class="br-ver-opt" data-par="" aria-pressed="' + (!st.par) + '"><span class="br-ver-code">1</span><b>One version</b><small>Just the text</small></button>'
      + C.versions.filter(function (x) { return x.code !== st.v; }).map(function (x) {
        return '<button type="button" class="br-ver-opt" data-par="' + x.code + '" aria-pressed="' + (st.par === x.code) + '"><span class="br-ver-code">+' + esc(x.code) + '</span><b>' + esc(ver(st.v).short) + ' and ' + esc(x.short) + '</b><small>' + esc(x.language) + ' alongside</small></button>';
      }).join('');
  }
  $('brVerBtn').addEventListener('click', function () { drawVersions(); openSheet('brVersions', this); });
  $('brVersions').addEventListener('click', function (e) {
    var b = e.target.closest('[data-ver], [data-par]'); if (!b) return;
    if (b.hasAttribute('data-ver')) {
      var code = b.dataset.ver; if (code === st.v) { closeAll(); return; }
      api('a=books&v=' + code).then(function (r) {
        st.v = code; st.books = r.books || []; if (st.par === code) st.par = null;
        var x = st.books[bookIx(st.b)];
        if (!x) { toast(ver(code).short + ' has only the New Testament. Opening Matthew.'); go(st.books[0].book, 1, 0); }
        else go(st.b, Math.min(st.c, x.chapters), 0, { replace: true });
        save('awBiblePlace', { v: st.v, b: st.b, c: st.c, par: st.par });
      });
    } else { st.par = b.dataset.par || null; S.par = st.par; save('awBible', S); go(st.b, st.c, 0, { replace: true }); }
  });

  /* ---------- find: a reference, or words ---------- */
  var ALIAS = { psalm: 'Psalms', ps: 'Psalms', song: 'Song of Solomon', songs: 'Song of Solomon', 'song of songs': 'Song of Solomon', rev: 'Revelation', revelations: 'Revelation', jn: 'John', mt: 'Matthew', mk: 'Mark', lk: 'Luke', gen: 'Genesis', ex: 'Exodus', rom: 'Romans', phil: 'Philippians', prov: 'Proverbs', isa: 'Isaiah', heb: 'Hebrews', eph: 'Ephesians', gal: 'Galatians', col: 'Colossians' };
  function findBook(name) {
    var n = name.toLowerCase().replace(/\s+/g, ' ').replace(/\.$/, '').trim(); if (!n) return null;
    if (ALIAS[n] && bookIx(ALIAS[n]) > -1) return ALIAS[n];
    var exact = null, pre = null;
    st.books.forEach(function (x) {
      [x.book, x.name].forEach(function (cand) {
        var c = cand.toLowerCase();
        if (c === n) exact = exact || x.book;
        else if (c.indexOf(n) === 0 && n.length >= 2) pre = pre || x.book;
        else if (c.split(' ').pop() === n) pre = pre || x.book; // "Yohane" in "Uthenga ... Yohane"
      });
    });
    return exact || pre;
  }
  function parseRef(q) {
    var m = q.trim().match(/^(\d?\s*[^\d:]+?)\s*(\d+)?(?:\s*[:.]\s*(\d+))?$/); if (!m) return null;
    var b = findBook(m[1]); if (!b) return null;
    var x = st.books[bookIx(b)], c = Math.max(1, Math.min(+(m[2] || 1), x.chapters));
    return { b: b, c: c, vs: m[3] ? +m[3] : 0 };
  }
  $('brSearchBtn').addEventListener('click', function () { openSheet('brSearch', this); $('brSearchInput').focus(); });
  $('brSearchForm').addEventListener('submit', function (e) {
    e.preventDefault(); var q = $('brSearchInput').value.trim(); if (!q) return;
    var r = parseRef(q); if (r) { go(r.b, r.c, r.vs); return; }
    if (q.length < 3) { $('brSearchHint').textContent = 'Type at least 3 letters to search.'; return; }
    $('brResults').innerHTML = '<li class="br-hint">Searching…</li>';
    api('a=search&v=' + st.v + '&q=' + encodeURIComponent(q)).then(function (d) {
      var res = (d && d.results) || [];
      var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
      $('brSearchHint').textContent = res.length ? (res.length === 50 ? 'The first 50 verses with these words:' : res.length + ' verse' + (res.length === 1 ? '' : 's') + ':') : 'Nothing found in ' + ver(st.v).short + '. Try another word or version.';
      $('brResults').innerHTML = res.map(function (x) {
        return '<li><a href="#" data-go="' + esc(x.book) + '|' + x.chapter + '|' + x.verse + '"><b>' + esc(bookName(x.book)) + ' ' + x.chapter + ':' + x.verse + '</b>' + esc(x.text).replace(re, '<mark>$1</mark>') + '</a></li>';
      }).join('');
    }).catch(function () { $('brSearchHint').textContent = 'Search is not available offline.'; $('brResults').innerHTML = ''; });
  });
  $('brResults').addEventListener('click', function (e) { var a = e.target.closest('[data-go]'); if (!a) return; e.preventDefault(); var p = a.dataset.go.split('|'); go(p[0], +p[1], +p[2]); });

  /* ---------- settings, keys and swipes ---------- */
  $('brSetBtn').addEventListener('click', function () { applySettings(); openSheet('brSettings', this); });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { if (closeAll()) e.preventDefault(); return; }
    var t = e.target, typing = t.matches && t.matches('input, textarea, select, [contenteditable]');
    if (typing || e.metaKey || e.ctrlKey || e.altKey || doc.querySelector('.br-sheet:not([hidden])')) return;
    showBar();
    if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'g') { e.preventDefault(); $('brRefBtn').click(); }
    else if (e.key === '/') { e.preventDefault(); $('brSearchBtn').click(); }
    else if (e.key === '+' || e.key === '=') set('size', Math.min(6, +setting('size') + 1));
    else if (e.key === '-') set('size', Math.max(1, +setting('size') - 1));
    else if (e.key === 't') { var o = ['light', 'sepia', 'dark']; set('theme', o[(o.indexOf(setting('theme')) + 1) % 3]); }
  });
  var sx = 0, sy = 0, swiping = false;
  $('brMain').addEventListener('touchstart', function (e) { if (e.touches.length !== 1) return; sx = e.touches[0].clientX; sy = e.touches[0].clientY; swiping = true; }, { passive: true });
  $('brMain').addEventListener('touchend', function (e) {
    if (!swiping) return; swiping = false;
    if (String(window.getSelection ? getSelection() : '').length) return;
    var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 70 && Math.abs(dy) < 45) step(dx < 0 ? 1 : -1);
  }, { passive: true });

  /* ---------- start ---------- */
  applySettings();
  var hasLink = /[?&](b|book)=/.test(location.search), place = load('awBiblePlace', null);
  if (S.par && ver(S.par) && S.par !== st.v) st.par = S.par;
  if (!hasLink && !C.member && place && place.b && (place.b !== st.b || place.c !== st.c || place.v !== st.v)) {
    // a guest's place on this device
    (place.v && place.v !== st.v && ver(place.v) ? api('a=books&v=' + place.v).then(function (r) { st.v = place.v; st.books = r.books || st.books; }) : Promise.resolve())
      .then(function () { if (bookIx(place.b) > -1) go(place.b, place.c, 0, { replace: true, first: true }); });
  } else if (st.par) {
    go(st.b, st.c, st.vs, { replace: true, first: true });
  } else {
    header(bookName(st.b)); history.replaceState({ b: st.b, c: st.c, vs: st.vs }, '', location.href);
    navAt = Date.now();
    if (st.vs && $('v' + st.vs)) { var t = $('v' + st.vs); t.scrollIntoView({ block: 'center' }); t.classList.add('is-target'); }
    remember(); decorate(); showBar();
  }
})();
