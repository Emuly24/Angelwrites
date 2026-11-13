/* AngelWrites members' editor: Quill 2 over a plain textarea.
   Without JavaScript (or if Quill fails to load) the textarea works on its own; the server turns
   plain text into paragraphs and cleans any HTML it receives (includes/sanitize.php, "member"). */
(function () {
  'use strict';
  var LABELS = { bold: 'Bold', italic: 'Italic', underline: 'Underline', blockquote: 'Quote', link: 'Link', clean: 'Remove formatting',
                 'list:ordered': 'Numbered list', 'list:bullet': 'Bulleted list' };
  function init(box) {
    if (!window.Quill || box.dataset.ready) return;
    var ta = box.querySelector('textarea');
    if (!ta) return;
    box.dataset.ready = '1';
    var host = document.createElement('div');
    host.className = 'cp-editor-surface';
    ta.insertAdjacentElement('beforebegin', host);
    var q = new window.Quill(host, {
      theme: 'snow',
      placeholder: box.dataset.placeholder || '',
      modules: { toolbar: [['bold', 'italic', 'underline'], ['blockquote', { list: 'ordered' }, { list: 'bullet' }], ['link', 'clean']] }
    });
    if (ta.value.trim() !== '') {
      if (/<[a-z][\s\S]*>/i.test(ta.value)) q.clipboard.dangerouslyPasteHTML(ta.value); // our own saved, server-cleaned HTML
      else q.setText(ta.value);
    }
    ta.hidden = true;
    ta.setAttribute('aria-hidden', 'true');
    ta.tabIndex = -1;
    // the editing surface takes the textarea's accessible name
    var ed = host.querySelector('.ql-editor');
    ed.setAttribute('role', 'textbox');
    ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('aria-label', ta.getAttribute('aria-label') || 'Text');
    if (ta.getAttribute('aria-describedby')) ed.setAttribute('aria-describedby', ta.getAttribute('aria-describedby'));
    if (ta.id) { var lab = document.querySelector('label[for="' + ta.id + '"]'); if (lab) lab.addEventListener('click', function (e) { e.preventDefault(); q.focus(); }); }
    // toolbar buttons get names a screen reader can say
    box.querySelectorAll('.ql-toolbar button').forEach(function (b) {
      var k = (b.className.match(/ql-(\w+)/) || [])[1];
      var key = b.value ? k + ':' + b.value : k;
      if (LABELS[key]) { b.setAttribute('aria-label', LABELS[key]); b.title = LABELS[key]; }
    });
    function sync() { ta.value = q.getText().trim() === '' ? '' : q.root.innerHTML; }
    q.on('text-change', sync);
    var form = ta.form;
    if (form) form.addEventListener('submit', sync);
    box.quill = q;
  }
  function all() { document.querySelectorAll('[data-editor]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
  window.awEditorInit = all;
})();
