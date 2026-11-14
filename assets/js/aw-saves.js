/* F1: the Save control. Without this script the form saves straight to the library (save.php).
   With it, a small dialog lets the member choose or create a collection, or remove the save. */
(function () {
  'use strict';
  var dlg, opener, cur;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function send(form, extra) {
    var d = new FormData(form);
    Object.keys(extra).forEach(function (k) { d.set(k, extra[k]); });
    return fetch(form.action, { method: 'POST', body: d, headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function paint(form, saved) {
    var b = form.querySelector('.aw-save-btn');
    b.setAttribute('aria-pressed', saved ? 'true' : 'false');
    b.querySelector('i').className = (saved ? 'fas' : 'far') + ' fa-bookmark';
    b.querySelector('span').textContent = saved ? 'Saved' : 'Save';
    form.querySelector('input[name=action]').value = saved ? 'remove' : 'save';
  }
  function toast(msg) {
    var t = document.getElementById('awSaveToast');
    if (!t) { t = document.createElement('div'); t.id = 'awSaveToast'; t.className = 'aw-save-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('is-on'); clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('is-on'); }, 2600);
  }
  function build() {
    dlg = document.createElement('div');
    dlg.className = 'aw-save-dlg'; dlg.hidden = true;
    dlg.innerHTML = '<div class="aw-save-box" role="dialog" aria-modal="true" aria-labelledby="awSaveH"><h2 id="awSaveH">Save to your library</h2>'
      + '<form><fieldset><legend class="aw-save-sr">Collection</legend><div class="aw-save-list"></div></fieldset>'
      + '<label class="aw-save-new"><span>Or a new collection</span><input type="text" name="new_collection" maxlength="60" placeholder="e.g. Comfort in grief"></label>'
      + '<p class="aw-save-msg" role="status" aria-live="polite"></p>'
      + '<div class="aw-save-actions"><button type="button" class="aw-save-remove" hidden>Remove from library</button><span></span><button type="button" data-close class="aw-save-cancel">Cancel</button><button type="submit" class="aw-save-ok">Save</button></div></form></div>';
    document.body.appendChild(dlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.hasAttribute('data-close')) close(); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') { var f = dlg.querySelectorAll('input, button:not([hidden])'); if (!f.length) return; var a = f[0], z = f[f.length - 1];
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    });
    dlg.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target, c = f.querySelector('input[name=cid]:checked'), msg = f.querySelector('.aw-save-msg');
      msg.textContent = 'Saving…';
      send(cur, { action: 'save', collection_id: c ? c.value : '0', new_collection: f.new_collection.value }).then(function (r) {
        if (!r.ok) { msg.textContent = r.message || 'That was not saved.'; return; }
        paint(cur, true); close(); toast(r.message || 'Saved.');
      }).catch(function () { msg.textContent = 'That was not saved. Check your connection.'; });
    });
    dlg.querySelector('.aw-save-remove').addEventListener('click', function () {
      send(cur, { action: 'remove' }).then(function (r) { if (r.ok) { paint(cur, false); close(); toast(r.message); } });
    });
  }
  function open(form) {
    if (!dlg) build();
    cur = form; opener = form.querySelector('button');
    var f = dlg.querySelector('form'), list = f.querySelector('.aw-save-list');
    f.reset(); f.querySelector('.aw-save-msg').textContent = ''; list.innerHTML = '<p class="aw-save-wait">Loading…</p>';
    dlg.hidden = false;
    send(form, { action: 'collections' }).then(function (r) {
      var html = '<label class="aw-save-opt"><input type="radio" name="cid" value="0"' + (!r.collection_id ? ' checked' : '') + '> <span>Library (no collection)</span></label>';
      (r.collections || []).forEach(function (c) { html += '<label class="aw-save-opt"><input type="radio" name="cid" value="' + c.id + '"' + (r.collection_id === c.id ? ' checked' : '') + '> <span>' + esc(c.name) + ' <small>' + c.n + '</small></span></label>'; });
      list.innerHTML = html;
      f.querySelector('.aw-save-remove').hidden = !r.saved;
      f.querySelector('.aw-save-ok').textContent = r.saved ? 'Move' : 'Save';
      (list.querySelector('input:checked') || list.querySelector('input')).focus();
    }).catch(function () { list.innerHTML = '<p>Could not load your collections.</p>'; });
  }
  function close() { if (dlg) dlg.hidden = true; if (opener) opener.focus(); }
  document.addEventListener('submit', function (e) {
    var form = e.target.closest && e.target.closest('form[data-save]');
    if (!form) return;
    e.preventDefault();
    open(form);
  });
  // a new collection name and a chosen collection exclude each other
  document.addEventListener('input', function (e) { if (e.target.name === 'new_collection' && e.target.value && dlg) { var c = dlg.querySelector('input[name=cid]:checked'); if (c) c.checked = false; } });
})();
