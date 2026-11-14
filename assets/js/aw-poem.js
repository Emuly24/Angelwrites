// ============================================================
//  AW-POEM.JS – poem reader (V1)
// ============================================================
// Works on any page with <div class="aw-reader"> (poem_view.php, admin/preview_poem.php):
//  - toolbar: text size, font (Garamond / Georgia / easy-read), centre or left, Page / Paper / Night,
//    focus mode (only the poem), print, share; choices are remembered on this device
//  - stanzas: tap one to copy it, share it or copy a link to it (#stanza-3 opens and highlights it)
//  - player (only present when a recording exists): play/pause, seek, speed, "Follow along"
//    highlights the stanza being read (estimated from each stanza's share of the words); if the
//    recording cannot be loaded the player removes itself
//  - a reading progress bar along the top of the window
(function () {
    'use strict';
    var reader = document.querySelector('.aw-reader');
    if (!reader) return;
    var poem = reader.querySelector('.aw-poem');
    var title = reader.getAttribute('data-title') || document.title;
    var KEY = 'aw_poem_prefs';
    var prefs = { size: 1.25, font: 'garamond', align: 'center', theme: 'auto' };
    try { Object.assign(prefs, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (e) {}

    function save() { try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch (e) {} }
    function apply() {
        reader.style.setProperty('--aw-size', prefs.size);
        reader.setAttribute('data-font', prefs.font);
        reader.setAttribute('data-align', prefs.align);
        reader.setAttribute('data-theme', prefs.theme);
        var b;
        if ((b = reader.querySelector('[data-act="align"]'))) b.innerHTML = prefs.align === 'center' ? '<i class="fas fa-align-center" aria-hidden="true"></i><span class="aw-l">Centred</span>' : '<i class="fas fa-align-left" aria-hidden="true"></i><span class="aw-l">Left</span>';
        if ((b = reader.querySelector('[data-act="font"]'))) b.textContent = { garamond: 'Garamond', georgia: 'Georgia', easy: 'Easy read' }[prefs.font] || 'Garamond';
        if ((b = reader.querySelector('[data-act="theme"]'))) b.innerHTML = { auto: '<i class="far fa-file" aria-hidden="true"></i> Page', paper: '<i class="fas fa-scroll" aria-hidden="true"></i> Paper', night: '<i class="fas fa-moon" aria-hidden="true"></i> Night' }[prefs.theme];
    }
    apply();

    var toastEl = null, toastTimer = 0;
    function toast(msg) {
        if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'aw-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
        toastEl.textContent = msg; toastEl.classList.add('show');
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
    }
    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        return new Promise(function (res, rej) {
            var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy') ? res() : rej(); } catch (e) { rej(e); }
            ta.remove();
        });
    }
    // the share link (through the preview relay when one is set up), else this page
    function pageUrl(hash) { if (!hash && window.AW_SHARE_URL) return window.AW_SHARE_URL; return location.href.split('#')[0].replace(/[?&]preview=1/, '') + (hash ? '#' + hash : ''); }

    // ----- toolbar
    reader.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-act]');
        if (!b || !reader.contains(b)) return;
        switch (b.getAttribute('data-act')) {
            case 'smaller': prefs.size = Math.max(0.9, +(prefs.size - 0.1).toFixed(2)); break;
            case 'bigger': prefs.size = Math.min(2.2, +(prefs.size + 0.1).toFixed(2)); break;
            case 'font': prefs.font = { garamond: 'georgia', georgia: 'easy', easy: 'garamond' }[prefs.font] || 'garamond'; break;
            case 'align': prefs.align = prefs.align === 'center' ? 'left' : 'center'; break;
            case 'theme': prefs.theme = { auto: 'paper', paper: 'night', night: 'auto' }[prefs.theme] || 'auto'; break;
            case 'focus': setFocus(!document.body.classList.contains('aw-focus')); return;
            case 'print': window.print(); return;
            case 'share': share(title, pageUrl('')); return;
            default: return;
        }
        save(); apply();
    });
    function setFocus(on) {
        document.body.classList.toggle('aw-focus', on);
        var f = reader.querySelector('[data-act="focus"]'); if (f) f.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (on) { reader.scrollIntoView({ behavior: 'smooth', block: 'start' }); toast('Focus mode — press Esc to leave'); }
    }
    var exitBtn = document.createElement('button');
    exitBtn.type = 'button'; exitBtn.className = 'aw-chip aw-exit-focus'; exitBtn.innerHTML = '✕ Leave focus';
    exitBtn.addEventListener('click', function () { setFocus(false); });
    document.body.appendChild(exitBtn);
    function share(t, url, text) {
        if (navigator.share) { navigator.share({ title: t, text: text || t, url: url }).catch(function () {}); return; }
        copy((text ? text + '\n\n' : '') + url).then(function () { toast('Link copied'); }, function () { toast('Could not copy the link'); });
    }

    // ----- stanza actions
    var stanzas = poem ? Array.prototype.slice.call(poem.querySelectorAll('.aw-stanza')) : [];
    var tools = document.createElement('div');
    tools.className = 'aw-stanza-tools'; tools.setAttribute('role', 'toolbar'); tools.setAttribute('aria-label', 'Stanza actions');
    tools.innerHTML = '<button type="button" data-st="copy">Copy</button><button type="button" data-st="share">Share</button><button type="button" data-st="link">Copy link</button>';
    var selected = null;
    if (stanzas.length) {
        reader.style.position = 'relative';
        reader.appendChild(tools);
    }
    function stanzaText(st) {
        return Array.prototype.map.call(st.querySelectorAll('.aw-line'), function (l) { return l.textContent.trim(); }).join('\n');
    }
    function select(st) {
        if (selected) selected.classList.remove('is-selected');
        selected = st;
        if (!st) { tools.classList.remove('show'); return; }
        st.classList.add('is-selected');
        var r = st.getBoundingClientRect(), rr = reader.getBoundingClientRect();
        tools.classList.add('show');
        var left = r.left - rr.left + r.width / 2 - tools.offsetWidth / 2;
        tools.style.left = Math.max(4, Math.min(rr.width - tools.offsetWidth - 4, left)) + 'px';
        tools.style.top = (r.top - rr.top - tools.offsetHeight - 8) + 'px';
    }
    stanzas.forEach(function (st) {
        st.addEventListener('click', function (ev) {
            if (window.getSelection && String(window.getSelection()).length > 2) return; // let people select text
            ev.stopPropagation();
            select(selected === st ? null : st);
        });
        st.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') select(st); });
    });
    tools.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-st]'); if (!b || !selected) return;
        ev.stopPropagation();
        var n = selected.getAttribute('data-stanza'), text = stanzaText(selected);
        var quote = text + '\n\n— ' + title + ', Angella Bottoman';
        if (b.dataset.st === 'copy') copy(quote).then(function () { toast('Stanza copied'); }, function () { toast('Could not copy'); });
        if (b.dataset.st === 'share') share(title, pageUrl('stanza-' + n), quote);
        if (b.dataset.st === 'link') copy(pageUrl('stanza-' + n)).then(function () { toast('Link to stanza ' + n + ' copied'); }, function () { toast('Could not copy'); });
        select(null);
    });
    document.addEventListener('click', function (ev) { if (!tools.contains(ev.target)) select(null); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { select(null); if (document.body.classList.contains('aw-focus')) setFocus(false); }
    });
    window.addEventListener('resize', function () { if (selected) select(selected); });
    // #stanza-N opens on that stanza and makes it glow
    var m = location.hash.match(/^#stanza-(\d+)$/);
    if (m) {
        var target = document.getElementById('stanza-' + m[1]);
        if (target) setTimeout(function () { target.scrollIntoView({ block: 'center' }); target.classList.add('is-linked'); target.focus({ preventScroll: true }); }, 250);
    }

    // ----- reading progress
    var bar = document.createElement('div'); bar.className = 'aw-progress'; bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);
    function progress() {
        if (!poem) return;
        var r = poem.getBoundingClientRect(), h = r.height - window.innerHeight * 0.6;
        var p = h > 0 ? (window.innerHeight * 0.4 - r.top) / h : (r.top < window.innerHeight ? 1 : 0);
        bar.style.width = Math.max(0, Math.min(1, p)) * 100 + '%';
    }
    window.addEventListener('scroll', progress, { passive: true }); progress();

    // ----- player
    var player = document.getElementById('awPlayer');
    var audio = document.getElementById('awAudio');
    if (!player || !audio) return;
    var playBtn = document.getElementById('awPlay'), seek = document.getElementById('awSeek'), timeEl = document.getElementById('awTime');
    var speedBtn = document.getElementById('awSpeed'), followBtn = document.getElementById('awFollow');
    var follow = true, current = null, dur = 0;
    function fmt(t) { t = Math.max(0, t || 0); var m = Math.floor(t / 60), s = Math.floor(t % 60); return m + ':' + (s < 10 ? '0' : '') + s; }
    function knownDuration() {
        if (isFinite(audio.duration) && audio.duration > 0) { dur = audio.duration; return; }
        // Browser recordings (WebM) often report no duration until the end has been seen.
        var back = audio.currentTime;
        audio.addEventListener('durationchange', function once() {
            if (isFinite(audio.duration)) { audio.removeEventListener('durationchange', once); dur = audio.duration; audio.currentTime = back; update(); }
        });
        audio.currentTime = 1e7;
    }
    audio.addEventListener('loadedmetadata', function () { knownDuration(); update(); });
    audio.addEventListener('error', function () { player.remove(); }); // no playable recording: no play button
    // Stanza boundaries as fractions of the recording, from each stanza's share of the words.
    var weights = stanzas.map(function (s) { return Math.max(1, +s.getAttribute('data-words') || 1); });
    var total = weights.reduce(function (a, b) { return a + b; }, 0), marks = [], acc = 0;
    weights.forEach(function (w) { acc += w; marks.push(acc / total); });
    function update() {
        var t = audio.currentTime || 0;
        timeEl.textContent = fmt(t) + ' / ' + (dur ? fmt(dur) : '–:––');
        if (dur && document.activeElement !== seek) seek.value = Math.round(t / dur * 1000);
        seek.style.setProperty('--aw-pos', (seek.value / 10) + '%');
        seek.setAttribute('aria-valuetext', fmt(t) + ' of ' + fmt(dur));
        if (!follow || !dur || !stanzas.length || audio.paused && t === 0) { setCurrent(null); return; }
        var f = t / dur, i = 0;
        while (i < marks.length - 1 && f > marks[i]) i++;
        setCurrent(stanzas[i]);
    }
    function setCurrent(st) {
        if (st === current) return;
        if (current) current.classList.remove('is-current');
        current = st;
        if (st) {
            st.classList.add('is-current');
            var r = st.getBoundingClientRect();
            if (!audio.paused && (r.top < 80 || r.bottom > window.innerHeight - 40)) st.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    playBtn.addEventListener('click', function () { if (audio.paused) audio.play().catch(function () { toast('The recording could not be played'); }); else audio.pause(); });
    audio.addEventListener('play', function () { player.classList.add('is-playing'); playBtn.setAttribute('aria-label', 'Pause the recording'); });
    audio.addEventListener('pause', function () { player.classList.remove('is-playing'); playBtn.setAttribute('aria-label', 'Play the recording'); });
    audio.addEventListener('ended', function () { setCurrent(null); });
    audio.addEventListener('timeupdate', update);
    seek.addEventListener('input', function () { if (dur) audio.currentTime = seek.value / 1000 * dur; });
    var speeds = [1, 1.25, 1.5, 0.75];
    speedBtn.addEventListener('click', function () {
        var i = (speeds.indexOf(audio.playbackRate) + 1) % speeds.length;
        audio.playbackRate = speeds[i]; speedBtn.textContent = speeds[i] + '×';
    });
    followBtn.addEventListener('click', function () {
        follow = !follow; followBtn.setAttribute('aria-pressed', follow ? 'true' : 'false'); update();
    });
    // Tap a stanza while listening: jump the recording there.
    stanzas.forEach(function (st, i) {
        st.addEventListener('dblclick', function () { if (dur) { audio.currentTime = (i ? marks[i - 1] : 0) * dur; if (audio.paused) audio.play(); } });
    });
})();
