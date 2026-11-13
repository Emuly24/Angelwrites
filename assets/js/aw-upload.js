/* R: browser-side upload checks. The server (includes/upload.php) re-checks everything; this only saves
   people a wasted upload by refusing, with a clear reason, a file that is obviously too big, the wrong
   type or too long, before it is sent.

   Any <input type="file"> printed with aw_upload_attrs() is checked automatically.
   Page scripts that build their own uploads (recorders, cameras) can call
     AWUpload.check(file, {max, exts, label, seconds, tooBig})  -> '' or a message (size and type)
     AWUpload.checkLength(file, seconds, label)                 -> Promise<'' or a message> */
(function () {
    'use strict';

    function mb(n) {
        if (n < 1048576) return Math.round(n / 1024) + ' KB';
        var v = n / 1048576;
        return (Math.abs(v - Math.round(v)) < 0.05 ? Math.round(v) : v.toFixed(1)) + ' MB';
    }
    function mins(s) { var m = s / 60; return (m === Math.floor(m) ? m : m.toFixed(1)) + ' minute' + (m === 1 ? '' : 's'); }
    function clock(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

    function check(file, o) {
        if (!file) return '';
        var label = o.label || 'file';
        if (o.max && file.size > o.max) {
            return o.tooBig ? o.tooBig + ' (it is ' + mb(file.size) + '; the limit is ' + mb(o.max) + ').'
                            : 'That ' + label + ' is ' + mb(file.size) + '. The limit is ' + mb(o.max) + '.';
        }
        var exts = o.exts || [];
        var name = String(file.name || '');
        var dot = name.lastIndexOf('.');
        var ext = dot > -1 ? name.slice(dot + 1).toLowerCase() : '';
        if (exts.length && ext && ext !== 'blob' && exts.indexOf(ext) === -1) {
            return 'Files ending in ".' + ext + '" cannot be used here. Allowed: ' + exts.join(', ').toUpperCase() + '.';
        }
        return '';
    }

    // Length of an audio/video file, read by the browser. Resolves '' when it is fine or cannot be told.
    function checkLength(file, seconds, label) {
        return new Promise(function (resolve) {
            if (!file || !seconds || !window.URL || !/^(audio|video)\//.test(file.type || '')) { resolve(''); return; }
            var el = document.createElement(/^video\//.test(file.type) ? 'video' : 'audio');
            var url = URL.createObjectURL(file);
            var done = false;
            function finish(msg) { if (done) return; done = true; URL.revokeObjectURL(url); resolve(msg); }
            el.preload = 'metadata';
            el.onloadedmetadata = function () {
                var d = el.duration;
                if (isFinite(d) && d > seconds + 2) {
                    finish('That ' + (label || 'file') + ' is ' + clock(d) + ' long. The limit is ' + mins(seconds) + '.');
                } else { finish(''); }
            };
            el.onerror = function () { finish(''); };
            setTimeout(function () { finish(''); }, 4000);
            el.src = url;
        });
    }

    function messageBox(input) {
        var id = 'awUploadMsg_' + (input.id || input.name || 'f').replace(/[^a-z0-9_]/gi, '');
        var box = document.getElementById(id);
        if (!box) {
            box = document.createElement('div');
            box.id = id;
            box.setAttribute('role', 'alert');
            box.style.cssText = 'margin:8px 0 0;padding:10px 14px;border-radius:12px;border:1px solid #ecc5c9;border-left:4px solid #b4505a;background:#f6e1e3;color:#6e2a31;font-size:0.9rem;font-weight:500;line-height:1.45;';
            box.hidden = true;
            // some inputs are hidden behind a styled drop zone: put the message after the visible wrapper
            var anchor = input;
            if (input.offsetParent === null && input.parentElement) anchor = input.parentElement;
            anchor.insertAdjacentElement('afterend', box);
        }
        return box;
    }
    function show(input, msg) {
        var box = messageBox(input);
        box.textContent = msg;
        box.hidden = !msg;
        if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    }

    function optionsOf(input) {
        return {
            max: parseInt(input.getAttribute('data-aw-max'), 10) || 0,
            exts: (input.getAttribute('data-aw-exts') || '').split(',').filter(Boolean),
            label: input.getAttribute('data-aw-label') || 'file',
            seconds: parseInt(input.getAttribute('data-aw-seconds'), 10) || 0,
            tooBig: input.getAttribute('data-aw-toobig') || ''
        };
    }

    function refuse(input, msg, e) {
        try { input.value = ''; } catch (x) {}
        show(input, msg);
        if (e) { e.stopImmediatePropagation(); }
        // let page scripts that draw their own preview know the choice was cleared
        input.dispatchEvent(new CustomEvent('aw-upload-refused', { bubbles: true, detail: { message: msg } }));
    }

    // Capture phase, so this runs before a page's own "change" handlers start previews for a refused file.
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input || input.type !== 'file' || !input.hasAttribute('data-aw-upload')) return;
        var o = optionsOf(input);
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) { show(input, ''); return; }
        for (var i = 0; i < files.length; i++) {
            var msg = check(files[i], o);
            if (msg) { refuse(input, msg, e); return; }
        }
        show(input, '');
        if (o.seconds) {
            checkLength(files[0], o.seconds, o.label).then(function (msg) {
                if (msg && input.files && input.files[0] === files[0]) refuse(input, msg, null);
            });
        }
    }, true);

    // A form whose chosen files are still over the limit (for example, set by script) is not sent.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.querySelectorAll) return;
        var inputs = form.querySelectorAll('input[type="file"][data-aw-upload]');
        for (var i = 0; i < inputs.length; i++) {
            var files = inputs[i].files || [];
            for (var j = 0; j < files.length; j++) {
                var msg = check(files[j], optionsOf(inputs[i]));
                if (msg) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    show(inputs[i], msg);
                    messageBox(inputs[i]).scrollIntoView({ block: 'center', behavior: 'smooth' });
                    return;
                }
            }
        }
    }, true);

    window.AWUpload = { check: check, checkLength: checkLength, mb: mb };
})();
