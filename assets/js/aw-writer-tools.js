/* AngelWrites writing tools for the editors (poems, blog, reflections, newsletter).
   Two buttons are added to every TinyMCE toolbar on the page:
     - "Add a Bible verse": find a verse by its reference (John 3:16, Ps 23, 1 Cor 13:4-7) or by the
       words you remember; choose the version; tick the verses; add them, beautifully formatted with
       their reference. You are then asked whether to add another; if not, the Bible closes.
     - "Research": search Wikipedia without leaving the editor, read the article, select a passage
       and add it as a quotation with a ready-formatted reference; or paste a passage from any other
       website with its title and address. A web search can open in a new tab.
   Load this file straight after tinymce.min.js. It uses the site's own Bible (bible_api.php) and
   Wikipedia's public search, from the browser. Nothing is saved until you save your writing. */
(function () {
  'use strict';
  var SITE = (document.querySelector('meta[name="aw-site"]') || {}).content || '';
  var API = SITE + '/bible_api.php';
  var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var clean = function (t) { return String(t || '').replace(/\{[^}]*\}/g, '').replace(/\s+/g, ' ').trim(); }; // WEB footnotes are in {braces}
  var today = function () { return new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }); };
  function get(url) { return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }

  /* ---------------- styles (once) ---------------- */
  function css() {
    if (document.getElementById('awToolsCss')) return;
    var s = document.createElement('style'); s.id = 'awToolsCss';
    s.textContent = [
      '.awt{border:0;border-radius:22px;padding:0;width:min(760px,calc(100vw - 24px));max-height:calc(100vh - 32px);background:var(--card-bg,#fff);color:var(--text,#3a2629);box-shadow:0 30px 80px rgba(30,18,18,.35);overflow:hidden}',
      '.awt::backdrop{background:rgba(30,18,18,.5);backdrop-filter:blur(3px)}',
      '.awt-in{display:flex;flex-direction:column;max-height:calc(100vh - 32px)}',
      '.awt-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border,#ecdcdc);background:linear-gradient(135deg,rgba(156,84,87,.08),transparent)}',
      '.awt-head h2{margin:0;font:600 1.2rem "Playfair Display",Georgia,serif;flex:1;color:inherit}',
      '.awt-x{width:40px;height:40px;border:0;border-radius:12px;background:transparent;font-size:1.5rem;cursor:pointer;color:inherit}',
      '.awt-x:hover{background:rgba(156,84,87,.1)}',
      '.awt-body{padding:16px 20px;overflow:auto;flex:1}',
      '.awt-foot{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center;padding:12px 20px;border-top:1px solid var(--border,#ecdcdc)}',
      '.awt-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}',
      '.awt input[type=text],.awt input[type=url],.awt select,.awt textarea{min-height:44px;padding:8px 12px;border-radius:12px;border:1.5px solid #dcc3c3;background:var(--card-bg,#fff);color:inherit;font:inherit}',
      '.awt textarea{width:100%;min-height:90px}',
      '.awt input[type=text]{flex:1 1 260px}',
      '.awt-btn{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 18px;border-radius:999px;border:1.5px solid #9c5457;background:transparent;color:#9c5457;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}',
      '.awt-btn.p{background:#9c5457;color:#fff}',
      '.awt-btn:disabled{opacity:.5;cursor:not-allowed}',
      '.awt-hint{color:var(--text-light,#6b5a5a);font-size:.88rem;margin:6px 0 0}',
      '.awt-list{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:8px}',
      '.awt-list button{width:100%;text-align:left;padding:10px 14px;border-radius:14px;border:1px solid var(--border,#ecdcdc);background:var(--card-bg,#fff);color:inherit;font:inherit;cursor:pointer;line-height:1.5}',
      '.awt-list button:hover,.awt-list button:focus-visible{border-color:#9c5457;background:rgba(156,84,87,.06)}',
      '.awt-list b{color:#9c5457}',
      '.awt-list mark{background:rgba(214,168,92,.35);color:inherit;border-radius:3px}',
      '.awt-verses{display:grid;gap:4px;margin-top:10px;font-family:Georgia,serif;line-height:1.7}',
      '.awt-verses label{display:flex;gap:10px;align-items:flex-start;padding:6px 8px;border-radius:10px;cursor:pointer}',
      '.awt-verses label:hover{background:rgba(156,84,87,.06)}',
      '.awt-verses input{margin-top:6px;accent-color:#9c5457;width:18px;height:18px;flex:none}',
      '.awt-verses sup{color:#9c5457;font-weight:700;margin-right:4px}',
      '.awt-verses .on{background:rgba(156,84,87,.1)}',
      '.awt-q{font-size:1.05rem;font-weight:600;margin:0 0 8px}',
      '.awt-sticky{position:sticky;top:-16px;z-index:2;margin:-16px -20px 0;padding:16px 20px 8px;background:var(--card-bg,#fff);border-bottom:1px solid var(--border,#ecdcdc)}',
      '.awt-done{text-align:center;padding:18px 4px}',
      '.awt-done i{font-size:2rem;color:#2f6b45}',
      '.awt-article{font-family:Georgia,serif;line-height:1.7;white-space:pre-wrap;border:1px solid var(--border,#ecdcdc);border-radius:14px;padding:12px 14px;max-height:46vh;overflow:auto;user-select:text}',
      '.awt-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}',
      '.awt-tabs button{min-height:40px;padding:0 14px;border-radius:999px;border:1px solid var(--border,#ecdcdc);background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer}',
      '.awt-tabs button[aria-selected=true]{background:#9c5457;border-color:#9c5457;color:#fff}',
      '.awt-preview{margin:10px 0;font-family:Georgia,serif}',
      '.awt-msg{min-height:1.4em;color:#a3262e;font-size:.9rem;margin:8px 0 0}',
      'html[data-theme=dark] .awt-btn{border-color:#f0c4c5;color:#f0c4c5}',
      'html[data-theme=dark] .awt-btn.p,html[data-theme=dark] .awt-tabs button[aria-selected=true]{background:#f0c4c5;color:#2c1e1e;border-color:#f0c4c5}',
      'html[data-theme=dark] .awt-list b,html[data-theme=dark] .awt-verses sup{color:#f0c4c5}',
      'html[data-theme=dark] .awt input,html[data-theme=dark] .awt select,html[data-theme=dark] .awt textarea{border-color:#57403f;background:#241a1b}',
      '@media (prefers-reduced-motion:no-preference){.awt[open]{animation:awtIn .25s cubic-bezier(.2,.8,.2,1)}@keyframes awtIn{from{opacity:0;transform:translateY(12px) scale(.98)}}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  function dialog(title, icon) {
    css();
    var d = document.createElement('dialog'); d.className = 'awt';
    d.innerHTML = '<div class="awt-in"><div class="awt-head"><span aria-hidden="true">' + icon + '</span><h2>' + esc(title) + '</h2><button type="button" class="awt-x" aria-label="Close">&times;</button></div><div class="awt-body"></div><div class="awt-foot"></div></div>';
    d.querySelector('.awt-x').addEventListener('click', function () { d.close(); });
    d.addEventListener('close', function () { setTimeout(function () { d.remove(); }, 50); });
    document.body.appendChild(d);
    d.showModal();
    return { el: d, body: d.querySelector('.awt-body'), foot: d.querySelector('.awt-foot') };
  }

  /* ---------------- Bible verse picker ---------------- */
  var versionsCache = null, booksCache = {};
  function versions() { return versionsCache ? Promise.resolve(versionsCache) : get(API + '?a=versions').then(function (r) { versionsCache = r.versions || []; return versionsCache; }); }
  function books(v) { return booksCache[v] ? Promise.resolve(booksCache[v]) : get(API + '?a=books&v=' + encodeURIComponent(v)).then(function (r) { booksCache[v] = r.books || []; return booksCache[v]; }); }
  var ABBR = { gen: 'Genesis', ex: 'Exodus', exo: 'Exodus', lev: 'Leviticus', num: 'Numbers', deut: 'Deuteronomy', dt: 'Deuteronomy', josh: 'Joshua', judg: 'Judges', ps: 'Psalms', psa: 'Psalms', psalm: 'Psalms', prov: 'Proverbs', pr: 'Proverbs', eccl: 'Ecclesiastes', ecc: 'Ecclesiastes', song: 'Song of Solomon', sos: 'Song of Solomon', isa: 'Isaiah', jer: 'Jeremiah', lam: 'Lamentations', ezek: 'Ezekiel', dan: 'Daniel', hos: 'Hosea', obad: 'Obadiah', mic: 'Micah', nah: 'Nahum', hab: 'Habakkuk', zeph: 'Zephaniah', hag: 'Haggai', zech: 'Zechariah', mal: 'Malachi', mt: 'Matthew', matt: 'Matthew', mk: 'Mark', lk: 'Luke', jn: 'John', jhn: 'John', rom: 'Romans', gal: 'Galatians', eph: 'Ephesians', phil: 'Philippians', col: 'Colossians', thess: 'Thessalonians', tim: 'Timothy', tit: 'Titus', philem: 'Philemon', heb: 'Hebrews', jas: 'James', pet: 'Peter', jud: 'Jude', rev: 'Revelation', cor: 'Corinthians' };
  function norm(s) { return String(s).toLowerCase().replace(/[^a-z0-9]/g, ''); }
  function findBook(list, typed) {
    var t = String(typed).trim().replace(/\.$/, ''), num = '', m = t.match(/^([1-3])\s*(.*)$/);
    if (m) { num = m[1]; t = m[2]; }
    var full = ABBR[norm(t)] || t, key = norm((num ? num : '') + full);
    var exact = list.filter(function (b) { return norm(b.book) === key || norm(b.name) === key; })[0];
    if (exact) return exact;
    return list.filter(function (b) { return norm(b.book).indexOf(key) === 0 || norm(b.name).indexOf(key) === 0; })[0] || null;
  }
  function parseRef(q) {
    var m = String(q).trim().match(/^([1-3]?\s*[A-Za-z][A-Za-z .]*?)\.?\s+(\d{1,3})(?:\s*[:.v ]\s*(\d{1,3})(?:\s*[-–]\s*(\d{1,3}))?)?\s*$/);
    return m ? { book: m[1], chapter: +m[2], from: m[3] ? +m[3] : 0, to: m[4] ? +m[4] : (m[3] ? +m[3] : 0) } : null;
  }

  function versePicker(insert) {
    var D = dialog('Add a Bible verse', '<i class="fas fa-book-bible"></i>');
    var state = { v: 'WEB', book: null, chapter: 0, verses: [], picked: {} };
    try { state.v = localStorage.getItem('aw_tools_version') || 'WEB'; } catch (e) {}

    function stepFind(prefill) {
      D.body.innerHTML = '<label class="awt-q" for="awtQ">Which verse are you looking for?</label>'
        + '<div class="awt-row"><input type="text" id="awtQ" placeholder="John 3:16, Psalm 23, 1 Cor 13:4-7, or words you remember" value="' + esc(prefill || '') + '" autocomplete="off"><button type="button" class="awt-btn p" id="awtGo"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Find</button></div>'
        + '<p class="awt-hint">Forgotten where it is? Type a few words you remember, such as <em>my grace is sufficient</em>.</p>'
        + '<p class="awt-msg" role="status" aria-live="polite"></p><ul class="awt-list" id="awtResults"></ul>';
      D.foot.innerHTML = '<button type="button" class="awt-btn" data-close>Cancel</button>';
      D.foot.querySelector('[data-close]').onclick = function () { D.el.close(); };
      var q = D.body.querySelector('#awtQ'), msg = D.body.querySelector('.awt-msg'), list = D.body.querySelector('#awtResults');
      function go() {
        var text = q.value.trim(); if (!text) { q.focus(); return; }
        msg.textContent = 'Looking…'; list.innerHTML = '';
        var ref = parseRef(text);
        books(state.v).then(function (bl) {
          var b = ref && findBook(bl, ref.book);
          if (b && ref.chapter >= 1 && ref.chapter <= b.chapters) { msg.textContent = ''; stepChoose(b, ref.chapter, ref.from, ref.to); return; }
          if (text.replace(/\s+/g, '').length < 3) { msg.textContent = 'Type at least 3 letters.'; return; }
          return get(API + '?a=search&v=' + encodeURIComponent(state.v) + '&q=' + encodeURIComponent(text)).then(function (r) {
            var rs = (r && r.results) || [];
            if (!rs.length) { msg.textContent = 'Nothing found for "' + text + '". Try fewer words, or the book and chapter.'; return; }
            msg.textContent = rs.length + (rs.length === 50 ? '+ ' : ' ') + 'verse' + (rs.length === 1 ? '' : 's') + ' found. Choose one:';
            var words = text.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 2; });
            list.innerHTML = rs.map(function (x, i) {
              var t = esc(clean(x.text));
              words.forEach(function (w) { t = t.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); });
              return '<li><button type="button" data-i="' + i + '"><b>' + esc(x.book + ' ' + x.chapter + ':' + x.verse) + '</b> — ' + t + '</button></li>';
            }).join('');
            list.querySelectorAll('button').forEach(function (btn) {
              btn.onclick = function () {
                var x = rs[+btn.dataset.i];
                books(state.v).then(function (bl2) { var bb = findBook(bl2, x.book) || { book: x.book, name: x.book, chapters: x.chapter }; stepChoose(bb, x.chapter, x.verse, x.verse); });
              };
            });
          });
        }).catch(function () { msg.textContent = 'The Bible could not be reached. Check your connection and try again.'; });
      }
      D.body.querySelector('#awtGo').onclick = go;
      q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      setTimeout(function () { q.focus(); }, 30);
    }

    function stepChoose(book, chapter, from, to) {
      state.book = book; state.chapter = chapter; state.picked = {};
      for (var i = from; i && i <= to; i++) state.picked[i] = true;
      versions().then(function (vs) {
        D.body.innerHTML = '<div class="awt-sticky"><p class="awt-q">' + esc(book.name + ' ' + chapter) + '</p>'
          + '<div class="awt-row"><label for="awtV"><strong>Which version would you like to use?</strong></label> <select id="awtV">' + vs.map(function (x) { return '<option value="' + esc(x.code) + '"' + (x.code === state.v ? ' selected' : '') + '>' + esc(x.name + (x.language && x.language !== 'English' ? ' (' + x.language + ')' : '')) + '</option>'; }).join('') + '</select></div>'
          + '<p class="awt-hint">Tick the verse or verses to add.</p><p class="awt-msg" role="status" aria-live="polite"></p></div><div class="awt-verses" id="awtVerses"></div>';
        D.foot.innerHTML = '<button type="button" class="awt-btn" data-back><i class="fas fa-arrow-left" aria-hidden="true"></i> Search again</button><button type="button" class="awt-btn p" data-add disabled><i class="fas fa-plus" aria-hidden="true"></i> Add to my writing</button>';
        D.foot.querySelector('[data-back]').onclick = function () { stepFind(''); };
        var addBtn = D.foot.querySelector('[data-add]');
        addBtn.onclick = add;
        var sel = D.body.querySelector('#awtV');
        sel.onchange = function () { state.v = sel.value; try { localStorage.setItem('aw_tools_version', state.v); } catch (e) {} load(); };
        function load() {
          var box = D.body.querySelector('#awtVerses'), msg = D.body.querySelector('.awt-msg');
          box.innerHTML = '<p class="awt-hint">Opening…</p>';
          get(API + '?a=chapter&v=' + encodeURIComponent(state.v) + '&b=' + encodeURIComponent(book.book) + '&c=' + chapter).then(function (r) {
            if (!r || !r.ok) { box.innerHTML = ''; msg.textContent = 'This chapter is not in that version. Please choose another version.'; return; }
            msg.textContent = '';
            state.verses = r.verses || []; state.bookName = r.book_name || book.name;
            box.innerHTML = state.verses.map(function (x) {
              return '<label class="' + (state.picked[x.v] ? 'on' : '') + '"><input type="checkbox" value="' + x.v + '"' + (state.picked[x.v] ? ' checked' : '') + '><span><sup>' + x.v + '</sup>' + esc(clean(x.text)) + '</span></label>';
            }).join('');
            box.querySelectorAll('input').forEach(function (c) { c.onchange = function () { state.picked[+c.value] = c.checked; c.parentNode.classList.toggle('on', c.checked); sync(); }; });
            var first = box.querySelector('label.on'); if (first) first.scrollIntoView({ block: 'center' }); // the version question stays pinned above
            sync();
          }).catch(function () { box.innerHTML = ''; msg.textContent = 'The Bible could not be reached. Please try again.'; });
        }
        function sync() { addBtn.disabled = !Object.keys(state.picked).some(function (k) { return state.picked[k]; }); }
        load();
      });
    }

    function rangeLabel(nums) {
      var parts = [], s = nums[0], p = nums[0];
      for (var i = 1; i <= nums.length; i++) {
        if (nums[i] === p + 1) { p = nums[i]; continue; }
        parts.push(s === p ? String(s) : s + '–' + p); s = p = nums[i];
      }
      return parts.join(', ');
    }
    function add() {
      var nums = state.verses.map(function (x) { return x.v; }).filter(function (n) { return state.picked[n]; });
      if (!nums.length) return;
      var multi = nums.length > 1;
      var text = state.verses.filter(function (x) { return state.picked[x.v]; }).map(function (x) { return (multi ? '<sup>' + x.v + '</sup>' : '') + esc(clean(x.text)); }).join(' ');
      var ref = state.bookName + ' ' + state.chapter + ':' + rangeLabel(nums) + ' (' + state.v + ')';
      insert('<blockquote class="aw-verse"><p>“' + text + '”</p><p class="aw-verse-ref">— ' + esc(ref) + '</p></blockquote><p></p>', ref);
      stepAnother(ref);
    }
    function stepAnother(ref) {
      D.body.innerHTML = '<div class="awt-done"><i class="fas fa-circle-check" aria-hidden="true"></i><p class="awt-q">' + esc(ref) + ' was added to your writing.</p><p>Would you like to add another verse?</p></div>';
      D.foot.innerHTML = '<button type="button" class="awt-btn" data-no>No, back to my writing</button><button type="button" class="awt-btn p" data-yes><i class="fas fa-plus" aria-hidden="true"></i> Add another verse</button>';
      D.foot.querySelector('[data-no]').onclick = function () { D.el.close(); };
      D.foot.querySelector('[data-yes]').onclick = function () { stepFind(''); };
      setTimeout(function () { D.foot.querySelector('[data-yes]').focus(); }, 30);
    }
    stepFind('');
  }

  /* ---------------- research ---------------- */
  var WIKI = 'https://en.wikipedia.org/w/api.php?origin=*&format=json&';
  function research(insert, selectedInEditor) {
    var D = dialog('Research', '<i class="fas fa-magnifying-glass"></i>');
    function tabs(active) {
      return '<div class="awt-tabs" role="tablist"><button type="button" role="tab" data-t="wiki" aria-selected="' + (active === 'wiki') + '">Search Wikipedia</button><button type="button" role="tab" data-t="paste" aria-selected="' + (active === 'paste') + '">From another website</button></div>';
    }
    function bindTabs() { D.body.querySelectorAll('[data-t]').forEach(function (b) { b.onclick = function () { (b.dataset.t === 'wiki' ? stepSearch : stepPaste)(''); }; }); }
    function webSearchButton(q) { return '<a class="awt-btn" target="_blank" rel="noopener" href="https://duckduckgo.com/?q=' + encodeURIComponent(q || '') + '"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Search the whole web (new tab)</a>'; }

    function stepSearch(prefill) {
      D.body.innerHTML = tabs('wiki') + '<label class="awt-q" for="awtW">What would you like to look up?</label><div class="awt-row"><input type="text" id="awtW" value="' + esc(prefill || selectedInEditor || '') + '" placeholder="e.g. history of the Psalms, forgiveness, Malawi"><button type="button" class="awt-btn p" id="awtWGo"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Search</button></div><p class="awt-msg" role="status" aria-live="polite"></p><ul class="awt-list" id="awtWR"></ul>';
      D.foot.innerHTML = webSearchButton(prefill || selectedInEditor) + '<button type="button" class="awt-btn" data-close>Close</button>';
      D.foot.querySelector('[data-close]').onclick = function () { D.el.close(); };
      bindTabs();
      var q = D.body.querySelector('#awtW'), msg = D.body.querySelector('.awt-msg'), list = D.body.querySelector('#awtWR');
      function go() {
        var t = q.value.trim(); if (t.length < 2) { q.focus(); return; }
        D.foot.querySelector('a').href = 'https://duckduckgo.com/?q=' + encodeURIComponent(t);
        msg.textContent = 'Searching…'; list.innerHTML = '';
        fetch(WIKI + 'action=query&list=search&srlimit=10&srsearch=' + encodeURIComponent(t)).then(function (r) { return r.json(); }).then(function (r) {
          var rs = (r.query && r.query.search) || [];
          msg.textContent = rs.length ? 'Choose an article to read:' : 'Nothing found. Try other words, or search the whole web.';
          list.innerHTML = rs.map(function (x, i) { return '<li><button type="button" data-i="' + i + '"><b>' + esc(x.title) + '</b> — ' + String(x.snippet || '').replace(/<(?!\/?span)[^>]*>/g, '').replace(/<span[^>]*>/g, '<mark>').replace(/<\/span>/g, '</mark>') + '…</button></li>'; }).join('');
          list.querySelectorAll('button').forEach(function (b) { b.onclick = function () { stepRead(rs[+b.dataset.i].title, t); }; });
        }).catch(function () { msg.textContent = 'Wikipedia could not be reached. You can still search the whole web in a new tab.'; });
      }
      D.body.querySelector('#awtWGo').onclick = go;
      q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      if (q.value) go();
      setTimeout(function () { q.focus(); }, 30);
    }

    function stepRead(title, query) {
      D.body.innerHTML = '<p class="awt-q">' + esc(title) + '</p><p class="awt-hint">Select the words you want to quote, then choose "Add the selected text".</p><div class="awt-article" tabindex="0">Opening…</div><p class="awt-msg" role="status" aria-live="polite"></p>';
      var url = 'https://en.wikipedia.org/wiki/' + encodeURIComponent(title.replace(/ /g, '_'));
      D.foot.innerHTML = '<button type="button" class="awt-btn" data-back><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to results</button><a class="awt-btn" target="_blank" rel="noopener" href="' + esc(url) + '">Open on Wikipedia</a><button type="button" class="awt-btn p" data-add><i class="fas fa-quote-left" aria-hidden="true"></i> Add the selected text</button>';
      D.foot.querySelector('[data-back]').onclick = function () { stepSearch(query); };
      var art = D.body.querySelector('.awt-article'), msg = D.body.querySelector('.awt-msg');
      fetch(WIKI + 'action=query&prop=extracts&explaintext=1&exsectionformat=plain&redirects=1&titles=' + encodeURIComponent(title)).then(function (r) { return r.json(); }).then(function (r) {
        var pages = (r.query && r.query.pages) || {}, p = pages[Object.keys(pages)[0]] || {};
        art.textContent = p.extract || 'This article could not be opened.';
      }).catch(function () { art.textContent = 'This article could not be opened.'; });
      var lastSel = '';
      document.addEventListener('selectionchange', function onSel() {
        if (!document.body.contains(art)) { document.removeEventListener('selectionchange', onSel); return; }
        var s = window.getSelection(); if (s && s.rangeCount && art.contains(s.anchorNode)) lastSel = String(s).trim();
      });
      D.foot.querySelector('[data-add]').onclick = function () {
        if (!lastSel) { msg.textContent = 'First select the words you want to quote in the article.'; return; }
        stepConfirm({ text: lastSel, title: title, site: 'Wikipedia', url: url, author: '' }, function () { stepRead(title, query); });
      };
    }

    function stepPaste(prefill) {
      D.body.innerHTML = tabs('paste') + '<p class="awt-hint">Found something on another website? Paste the words and where they come from, and they are added with a proper reference.</p>'
        + '<label class="awt-q" for="awtPT">The words to quote</label><textarea id="awtPT">' + esc(prefill || selectedInEditor || '') + '</textarea>'
        + '<div class="awt-row" style="margin-top:8px"><input type="text" id="awtPTi" placeholder="Title of the page or book"><input type="text" id="awtPA" placeholder="Author (if known)"></div>'
        + '<div class="awt-row" style="margin-top:8px"><input type="text" id="awtPS" placeholder="Website or publisher, e.g. BBC"><input type="url" id="awtPU" placeholder="https:// address of the page"></div><p class="awt-msg" role="status" aria-live="polite"></p>';
      D.foot.innerHTML = webSearchButton('') + '<button type="button" class="awt-btn p" data-next>Continue</button>';
      bindTabs();
      D.foot.querySelector('[data-next]').onclick = function () {
        var g = function (id) { return D.body.querySelector(id).value.trim(); }, msg = D.body.querySelector('.awt-msg');
        if (!g('#awtPT')) { msg.textContent = 'Paste the words you want to quote.'; return; }
        if (g('#awtPU') && !/^https?:\/\//i.test(g('#awtPU'))) { msg.textContent = 'The address must start with https://'; return; }
        stepConfirm({ text: g('#awtPT'), title: g('#awtPTi'), site: g('#awtPS'), url: g('#awtPU'), author: g('#awtPA') }, function () { stepPaste(g('#awtPT')); });
      };
    }

    function reference(src, withRef) {
      if (!withRef) return '';
      var bits = [];
      if (src.author) bits.push(esc(src.author));
      if (src.title) bits.push('“' + esc(src.title) + '”');
      if (src.site) bits.push('<em>' + esc(src.site) + '</em>');
      bits.push('accessed ' + esc(today()));
      var link = src.url ? ', <a href="' + esc(src.url) + '" target="_blank" rel="noopener">' + esc(src.url.replace(/^https?:\/\//, '').replace(/\/$/, '')) + '</a>' : '';
      return '<p class="aw-quote-ref">— ' + bits.join(', ') + link + '</p>';
    }
    function stepConfirm(src, back) {
      D.body.innerHTML = '<p class="awt-q">Add this to your writing?</p><div class="awt-preview" id="awtPrev"></div>'
        + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" id="awtRef" checked> Add a ready-formatted reference to the source</label>';
      var prev = D.body.querySelector('#awtPrev'), cb = D.body.querySelector('#awtRef');
      function html() { return '<blockquote class="aw-quote"><p>“' + esc(src.text).replace(/\n+/g, '</p><p>') + '”</p>' + reference(src, cb.checked) + '</blockquote><p></p>'; }
      function show() { prev.innerHTML = html(); }
      cb.onchange = show; show();
      D.foot.innerHTML = '<button type="button" class="awt-btn" data-back><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</button><button type="button" class="awt-btn p" data-yes><i class="fas fa-check" aria-hidden="true"></i> Yes, add it</button>';
      D.foot.querySelector('[data-back]').onclick = back;
      D.foot.querySelector('[data-yes]').onclick = function () {
        insert(html());
        D.body.innerHTML = '<div class="awt-done"><i class="fas fa-circle-check" aria-hidden="true"></i><p class="awt-q">Added to your writing.</p><p>Would you like to look up something else?</p></div>';
        D.foot.innerHTML = '<button type="button" class="awt-btn" data-no>No, back to my writing</button><button type="button" class="awt-btn p" data-more>Research more</button>';
        D.foot.querySelector('[data-no]').onclick = function () { D.el.close(); };
        D.foot.querySelector('[data-more]').onclick = function () { stepSearch(''); };
      };
    }
    stepSearch('');
  }

  /* ---------------- TinyMCE ---------------- */
  var CONTENT_CSS = 'blockquote.aw-verse,blockquote.aw-quote{margin:1.2em 0;padding:.6em 1.2em;border-left:4px solid #9c5457;background:#faf3f1;border-radius:0 12px 12px 0;font-style:italic}'
    + '.aw-verse-ref,.aw-quote-ref{font-style:normal;font-size:.88em;color:#7a4e50;margin:.4em 0 0}.aw-verse sup{color:#9c5457;font-style:normal;font-weight:700;margin-right:2px}';
  function attach(editor) {
    var insert = function (html) { editor.focus(); editor.insertContent(html); editor.fire('change'); };
    editor.ui.registry.addButton('awverse', { text: 'Bible verse', icon: 'quote', tooltip: 'Add a Bible verse', onAction: function () { versePicker(insert); } });
    editor.ui.registry.addButton('awresearch', { text: 'Research', icon: 'search', tooltip: 'Research and quote a source', onAction: function () { research(insert, editor.selection ? editor.selection.getContent({ format: 'text' }).trim().slice(0, 120) : ''); } });
  }
  function patch() {
    if (!window.tinymce || window.tinymce.__awTools) return !!window.tinymce;
    var orig = window.tinymce.init.bind(window.tinymce);
    window.tinymce.init = function (cfg) {
      cfg = cfg || {};
      var tb = cfg.toolbar === undefined ? 'undo redo | bold italic | bullist numlist' : cfg.toolbar;
      // first on the toolbar, so they never fall into the "more" overflow on narrow screens
      if (typeof tb === 'string') cfg.toolbar = 'awverse awresearch | ' + tb;
      else if (Array.isArray(tb) && tb.length) { tb[0] = 'awverse awresearch | ' + tb[0]; cfg.toolbar = tb; }
      cfg.content_style = (cfg.content_style || '') + CONTENT_CSS;
      if (cfg.promotion === undefined) cfg.promotion = false; // no "Upgrade" advert in the toolbar
      var before = cfg.setup;
      cfg.setup = function (editor) { attach(editor); if (typeof before === 'function') before(editor); };
      return orig(cfg);
    };
    window.tinymce.__awTools = true;
    return true;
  }
  patch();
  window.AWTools = { versePicker: versePicker, research: research, attach: attach, patch: patch };
})();
