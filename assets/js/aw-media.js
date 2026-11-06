// ============================================================
//  AW-MEDIA.JS – cleaner recordings and an editor before sending
// ============================================================
// One module for every audio and video recorder on the site.
//
//  M1  AWMedia.getStream(constraints)
//      Like getUserMedia, with noise reduction: the browser's own noise suppression, echo
//      cancellation and level control, then a voice chain (rumble filter, adaptive noise gate from
//      aw-noise-gate.worklet.js and a gentle compressor). Stopping the stream's tracks also releases
//      the microphone. If anything is unsupported it returns the plain stream, never silence.
//
//  M2  AWMedia.review(blob, { kind: 'audio' | 'video', filter: 'css filter', title })
//      Opens the editor and resolves with the edited recording, or the original one if nothing was
//      changed or the person chose "Use original". Editing:
//        Trim & cut  – set start and end, select any part on the waveform and cut it out, undo
//        Crop        – video: free or 1:1, 4:5, 16:9, 9:16 box, mirror
//        Filters     – video: presets plus brightness, contrast, saturation, warmth, vignette
//        Sound       – noise reduction (light/medium/strong), voice clarity, volume, normalise,
//                      fade in/out, mute (video)
//      The result is rendered in the browser in real time (as long as the edited clip) and
//      recorded again with MediaRecorder, so the upload stays a small WebM like before.
//
//      AWMedia.attachEditButton(input, preview, kind) adds "✂️ Edit recording" next to a preview,
//      so a recording can be edited again before the form is sent.
(function () {
    'use strict';
    if (window.AWMedia) return;

    var SCRIPT_URL = (document.currentScript && document.currentScript.src) || '/assets/js/aw-media.js';
    var WORKLET_URL = SCRIPT_URL.replace(/aw-media\.js(\?.*)?$/, 'aw-noise-gate.worklet.js');
    var AC = window.AudioContext || window.webkitAudioContext;
    var workletLoads = new WeakMap();

    function loadGate(ctx) {
        if (!ctx.audioWorklet || typeof AudioWorkletNode === 'undefined') return Promise.resolve(false);
        if (!workletLoads.has(ctx)) {
            workletLoads.set(ctx, ctx.audioWorklet.addModule(WORKLET_URL).then(function () { return true; }, function () { return false; }));
        }
        return workletLoads.get(ctx);
    }

    // Noise-reduction levels: how far the gate turns noise down, and the band kept for the voice.
    var NR = {
        off:    { reduction: 1,    hp: 40,  lp: 20000 },
        light:  { reduction: 0.35, hp: 80,  lp: 16000 },
        medium: { reduction: 0.18, hp: 100, lp: 12000 },
        strong: { reduction: 0.07, hp: 120, lp: 8000 }
    };

    // input -> rumble filter -> noise gate -> hiss filter -> clarity EQ -> compressor -> volume -> fade -> edge -> output
    function buildChain(ctx, opts) {
        return loadGate(ctx).then(function (hasGate) {
            var hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.Q.value = 0.707;
            var gate = hasGate
                ? new AudioWorkletNode(ctx, 'aw-noise-gate', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1], channelCount: 1, channelCountMode: 'explicit' })
                : ctx.createGain();
            var lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 0.707;
            var presence = ctx.createBiquadFilter(); presence.type = 'peaking'; presence.frequency.value = 3200; presence.Q.value = 0.9;
            var mud = ctx.createBiquadFilter(); mud.type = 'peaking'; mud.frequency.value = 300; mud.Q.value = 1;
            var comp = ctx.createDynamicsCompressor();
            var vol = ctx.createGain(), fade = ctx.createGain(), edge = ctx.createGain();
            hp.connect(gate); gate.connect(lp); lp.connect(presence); presence.connect(mud); mud.connect(comp);
            comp.connect(vol); vol.connect(fade); fade.connect(edge);
            function update(p) {
                p = p || {};
                var nr = NR[p.nr] || NR.off;
                hp.frequency.value = nr.hp;
                lp.frequency.value = Math.min(nr.lp, ctx.sampleRate / 2 - 100);
                if (hasGate) {
                    gate.parameters.get('reduction').value = nr.reduction;
                    gate.parameters.get('enabled').value = (p.nr && p.nr !== 'off') ? 1 : 0;
                }
                presence.gain.value = p.voice ? 4 : 0;
                mud.gain.value = p.voice ? -3 : 0;
                if (p.voice || p.compress) {
                    comp.threshold.value = -24; comp.knee.value = 12; comp.ratio.value = 3; comp.attack.value = 0.005; comp.release.value = 0.25;
                } else {
                    comp.threshold.value = 0; comp.knee.value = 0; comp.ratio.value = 1; comp.attack.value = 0.003; comp.release.value = 0.25;
                }
                vol.gain.value = p.mute ? 0 : Math.pow(10, ((p.volumeDb || 0) + (p.normDb || 0)) / 20);
            }
            update(opts);
            return { input: hp, output: edge, update: update, fade: fade, edge: edge, hasGate: hasGate };
        });
    }

    // ---------------------------------------------------------------- M1: live noise reduction
    function getStream(constraints, options) {
        var c = Object.assign({}, constraints || {});
        if (c.audio) {
            c.audio = Object.assign({ echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 },
                typeof c.audio === 'object' ? c.audio : {});
        }
        // Created now, inside the click, so browsers allow it to run.
        var ctx = (c.audio && AC && !(options && options.process === false)) ? new AC() : null;
        return navigator.mediaDevices.getUserMedia(c).then(function (raw) {
            var mic = raw.getAudioTracks()[0];
            if (!mic || !ctx) { if (ctx) ctx.close().catch(function () {}); return raw; }
            return Promise.resolve(ctx.state === 'suspended' ? ctx.resume().catch(function () {}) : null)
                .then(function () { return buildChain(ctx, { nr: 'light', compress: true }); })
                .then(function (chain) {
                    if (ctx.state !== 'running') throw new Error('audio processing is not running');
                    var src = ctx.createMediaStreamSource(new MediaStream([mic]));
                    var dest = ctx.createMediaStreamDestination();
                    src.connect(chain.input); chain.output.connect(dest);
                    var out = dest.stream.getAudioTracks()[0];
                    var stopOut = out.stop.bind(out);
                    out.stop = function () { stopOut(); mic.stop(); ctx.close().catch(function () {}); };
                    var stream = new MediaStream(raw.getVideoTracks().concat([out]));
                    stream.awNoiseReduced = true;
                    return stream;
                })
                .catch(function (e) {
                    if (window.console) console.warn('AWMedia: recording without extra noise reduction –', e && e.message);
                    ctx.close().catch(function () {});
                    return raw;
                });
        }, function (err) { if (ctx) ctx.close().catch(function () {}); throw err; });
    }

    function pickMime(kind) {
        var list = kind === 'video'
            ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4']
            : ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
        if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
        for (var i = 0; i < list.length; i++) if (MediaRecorder.isTypeSupported(list[i])) return list[i];
        return '';
    }

    // ---------------------------------------------------------------- helpers
    function fmt(t) {
        if (!isFinite(t)) t = 0;
        var m = Math.floor(t / 60), s = t - m * 60;
        return m + ':' + (s < 10 ? '0' : '') + s.toFixed(1);
    }
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function el(tag, attrs, html) {
        var e = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (html != null) e.innerHTML = html;
        return e;
    }
    function once(target, ev) { return new Promise(function (res) { target.addEventListener(ev, res, { once: true }); }); }
    function seek(media, t) {
        return new Promise(function (res) {
            var done = false;
            function fin() { if (!done) { done = true; res(); } }
            media.addEventListener('seeked', fin, { once: true });
            setTimeout(fin, 3000);
            media.currentTime = t;
        });
    }
    // MediaRecorder WebM files often report an unknown (Infinity) duration until the end is reached.
    function knownDuration(media) {
        if (isFinite(media.duration) && media.duration > 0) return Promise.resolve(media.duration);
        return new Promise(function (res) {
            function check() {
                if (isFinite(media.duration) && media.duration > 0) {
                    media.removeEventListener('durationchange', check);
                    media.removeEventListener('timeupdate', check);
                    media.currentTime = 0;
                    res(media.duration);
                }
            }
            media.addEventListener('durationchange', check);
            media.addEventListener('timeupdate', check);
            media.currentTime = 1e7;
            setTimeout(function () { res(isFinite(media.duration) ? media.duration : 0); }, 5000);
        });
    }

    var PRESETS = {
        none:     { label: 'Original', b: 100, c: 100, s: 100, w: 0,   v: 0,  g: 0,   p: 0 },
        vivid:    { label: 'Vivid',    b: 103, c: 110, s: 140, w: 0,   v: 0,  g: 0,   p: 0 },
        warm:     { label: 'Warm',     b: 102, c: 104, s: 110, w: 35,  v: 0,  g: 0,   p: 0 },
        cool:     { label: 'Cool',     b: 100, c: 106, s: 100, w: -35, v: 0,  g: 0,   p: 0 },
        bw:       { label: 'B&W',      b: 100, c: 115, s: 100, w: 0,   v: 10, g: 100, p: 0 },
        sepia:    { label: 'Sepia',    b: 102, c: 95,  s: 100, w: 0,   v: 15, g: 0,   p: 80 },
        vintage:  { label: 'Vintage',  b: 105, c: 90,  s: 80,  w: 15,  v: 40, g: 0,   p: 35 },
        dramatic: { label: 'Dramatic', b: 95,  c: 140, s: 90,  w: 0,   v: 50, g: 0,   p: 0 },
        soft:     { label: 'Soft',     b: 110, c: 85,  s: 80,  w: 5,   v: 0,  g: 0,   p: 0 }
    };
    function cssFilter(f, base) {
        var s = 'brightness(' + f.b + '%) contrast(' + f.c + '%) saturate(' + f.s + '%)';
        if (f.g) s += ' grayscale(' + f.g + '%)';
        if (f.p) s += ' sepia(' + f.p + '%)';
        return (base && base !== 'none' ? base + ' ' : '') + s;
    }
    function tintColor(w) {
        var a = Math.abs(w) / 100 * 0.55;
        return w >= 0 ? 'rgba(255,150,40,' + a + ')' : 'rgba(40,120,255,' + a + ')';
    }

    var STYLE = '\
.awm-back{position:fixed;inset:0;background:rgba(10,8,12,.72);z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:12px;font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}\
.awm{background:#1d1b22;color:#eee;border-radius:14px;width:min(920px,100%);max-height:100%;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.5)}\
.awm *{box-sizing:border-box}\
.awm-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #333}\
.awm-head h2{font-size:1.05rem;margin:0;color:#fff;font-weight:600}\
.awm-x{background:none;border:0;color:#ccc;font-size:1.5rem;cursor:pointer;width:40px;height:40px;border-radius:8px}\
.awm-x:hover{background:#2c2933}\
.awm-body{overflow:auto;padding:12px 14px;display:flex;flex-direction:column;gap:10px}\
.awm-stage{position:relative;background:#000;border-radius:10px;height:min(46vh,420px);overflow:hidden;touch-action:none}\
.awm-frame{position:absolute}\
.awm-frame video{width:100%;height:100%;display:block}\
.awm-ov{position:absolute;inset:0;pointer-events:none}\
.awm-tint{mix-blend-mode:soft-light}\
.awm-crop{position:absolute;box-shadow:0 0 0 9999px rgba(0,0,0,.6);outline:2px solid #fff;outline-offset:-2px;cursor:move;touch-action:none}\
.awm-crop.off{box-shadow:none;outline:0;pointer-events:none}\
.awm-crop i{position:absolute;width:22px;height:22px;background:#fff;border-radius:50%;border:3px solid #DBA1A2}\
.awm-crop.off i{display:none}\
.awm-crop i[data-h=nw]{left:-11px;top:-11px;cursor:nwse-resize}.awm-crop i[data-h=ne]{right:-11px;top:-11px;cursor:nesw-resize}\
.awm-crop i[data-h=sw]{left:-11px;bottom:-11px;cursor:nesw-resize}.awm-crop i[data-h=se]{right:-11px;bottom:-11px;cursor:nwse-resize}\
.awm-audiocard{display:flex;align-items:center;justify-content:center;height:90px;background:linear-gradient(135deg,#3a2a35,#22202a);border-radius:10px;font-size:2rem}\
.awm-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}\
.awm-time{font-variant-numeric:tabular-nums;color:#ccc;min-width:120px}\
.awm-tl{position:relative;height:72px;background:#26232c;border-radius:8px;cursor:crosshair;touch-action:none;outline-offset:2px}\
.awm-tl canvas{width:100%;height:100%;display:block}\
.awm-btn{background:#2f2b37;color:#eee;border:1px solid #444;border-radius:8px;padding:8px 12px;cursor:pointer;font:inherit;min-height:40px}\
.awm-btn:hover:not(:disabled){background:#3a3544}\
.awm-btn:disabled{opacity:.45;cursor:default}\
.awm-btn.on{background:#DBA1A2;color:#1d1b22;border-color:#DBA1A2;font-weight:600}\
.awm-btn.pri{background:#DBA1A2;color:#1d1b22;border-color:#DBA1A2;font-weight:600}\
.awm-tabs{display:flex;gap:4px;border-bottom:1px solid #333;flex-wrap:wrap}\
.awm-tab{background:none;border:0;color:#bbb;padding:8px 12px;cursor:pointer;font:inherit;border-bottom:2px solid transparent}\
.awm-tab[aria-selected=true]{color:#fff;border-bottom-color:#DBA1A2}\
.awm-pane{display:none;flex-direction:column;gap:10px;padding-top:4px}\
.awm-pane.show{display:flex}\
.awm-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}\
.awm-row label{min-width:120px;color:#ccc}\
.awm-row input[type=range]{flex:1;min-width:140px;accent-color:#DBA1A2}\
.awm-row output{min-width:56px;text-align:right;color:#ddd;font-variant-numeric:tabular-nums}\
.awm-chips{display:flex;gap:6px;flex-wrap:wrap}\
.awm-note{color:#aaa;font-size:.85rem;margin:0}\
.awm-foot{display:flex;gap:8px;justify-content:space-between;align-items:center;padding:10px 14px;border-top:1px solid #333;flex-wrap:wrap}\
.awm-prog{position:absolute;inset:0;background:rgba(20,18,24,.92);display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;z-index:5;padding:20px;text-align:center}\
.awm-prog.show{display:flex}\
.awm-prog progress{width:min(360px,80%);height:12px;accent-color:#DBA1A2}\
.awm-btn:focus-visible,.awm-tab:focus-visible,.awm-tl:focus-visible,.awm-x:focus-visible{outline:3px solid #DBA1A2;outline-offset:2px}\
@media (max-width:640px){.awm-back{padding:0}.awm{border-radius:0;height:100%}.awm-stage{height:38vh}.awm-row label{min-width:100%}}\
.awm-attach{margin-top:6px}';

    function injectStyle() {
        if (document.getElementById('awm-style')) return;
        var s = el('style', { id: 'awm-style' }); s.textContent = STYLE; document.head.appendChild(s);
    }

    // ---------------------------------------------------------------- M2: the editor
    function review(blob, opts) {
        opts = opts || {};
        if (!blob || !blob.size || !AC || !window.MediaRecorder) return Promise.resolve(blob);
        var kind = opts.kind === 'video' ? 'video' : 'audio';
        injectStyle();
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(blob);
            var st = {
                dur: 0, a: 0, b: 0, cuts: [], sel: null, hist: [],
                crop: { x: 0, y: 0, w: 1, h: 1 }, aspect: 'free', mirror: false,
                f: Object.assign({}, PRESETS.none), preset: 'none', base: (opts.filter && opts.filter !== 'none') ? opts.filter : '',
                snd: { nr: 'off', voice: false, volumeDb: 0, normalise: false, normDb: 0, fadeIn: 0, fadeOut: 0, mute: false }
            };
            var peaks = null, decoded = null;
            var prevFocus = document.activeElement;

            var back = el('div', { 'class': 'awm-back' });
            var box = el('div', { 'class': 'awm', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Edit your recording' });
            box.style.position = 'relative';
            box.innerHTML =
                '<div class="awm-head"><h2>✂️ ' + (opts.title || (kind === 'video' ? 'Edit your video' : 'Edit your recording')) + '</h2>' +
                '<button type="button" class="awm-x" data-act="close" aria-label="Close and use the original">×</button></div>' +
                '<div class="awm-body">' +
                (kind === 'video'
                    ? '<div class="awm-stage"><div class="awm-frame"><video playsinline preload="auto"></video><div class="awm-ov awm-tint"></div><div class="awm-ov awm-vig"></div><div class="awm-ov awm-fadeov" style="background:#000;opacity:0"></div><div class="awm-crop off"><i data-h="nw"></i><i data-h="ne"></i><i data-h="sw"></i><i data-h="se"></i></div></div></div>'
                    : '<div class="awm-audiocard" aria-hidden="true">🎙️</div><audio preload="auto"></audio>') +
                '<div class="awm-bar"><button type="button" class="awm-btn" data-act="play" aria-label="Play">▶ Play</button>' +
                '<button type="button" class="awm-btn" data-act="tostart" aria-label="Back to start">⏮</button>' +
                '<span class="awm-time" aria-live="off">0:00.0 / 0:00.0</span>' +
                '<span class="awm-note awm-kept"></span></div>' +
                '<div class="awm-tl" tabindex="0" role="slider" aria-label="Timeline. Drag to select a part; arrow keys move the playhead" aria-valuemin="0"><canvas></canvas></div>' +
                '<div class="awm-tabs" role="tablist">' +
                '<button type="button" class="awm-tab" role="tab" aria-selected="true" data-tab="cut">✂️ Trim &amp; cut</button>' +
                (kind === 'video' ? '<button type="button" class="awm-tab" role="tab" aria-selected="false" data-tab="crop">⬚ Crop</button><button type="button" class="awm-tab" role="tab" aria-selected="false" data-tab="filters">🎨 Filters</button>' : '') +
                '<button type="button" class="awm-tab" role="tab" aria-selected="false" data-tab="sound">🎚 Sound</button></div>' +
                // Trim & cut
                '<div class="awm-pane show" data-pane="cut">' +
                '<div class="awm-row"><button type="button" class="awm-btn" data-act="setA">⇤ Start here</button><button type="button" class="awm-btn" data-act="setB">End here ⇥</button>' +
                '<button type="button" class="awm-btn" data-act="cut" disabled>✂️ Cut selected part</button>' +
                '<button type="button" class="awm-btn" data-act="undo" disabled>↶ Undo</button><button type="button" class="awm-btn" data-act="reset">Reset all</button></div>' +
                '<p class="awm-note">Drag the white handles to trim the start and end. Drag across the waveform to select a part, then cut it out. Space plays and pauses.</p></div>' +
                // Crop
                (kind === 'video' ? '<div class="awm-pane" data-pane="crop"><div class="awm-chips" data-group="aspect">' +
                    ['free|Free', 'full|Full frame', '1:1|Square 1:1', '4:5|Portrait 4:5', '9:16|Story 9:16', '16:9|Wide 16:9'].map(function (s) {
                        var p = s.split('|'); return '<button type="button" class="awm-btn" data-aspect="' + p[0] + '">' + p[1] + '</button>';
                    }).join('') + '</div>' +
                    '<div class="awm-row"><label><input type="checkbox" data-k="mirror"> Mirror (flip left–right)</label></div>' +
                    '<p class="awm-note">Drag the box to move it and the round corners to resize it.</p></div>' +
                    // Filters
                    '<div class="awm-pane" data-pane="filters"><div class="awm-chips" data-group="preset">' +
                    Object.keys(PRESETS).map(function (k) { return '<button type="button" class="awm-btn" data-preset="' + k + '">' + PRESETS[k].label + '</button>'; }).join('') + '</div>' +
                    slider('b', 'Brightness', 50, 150, 1, '%') + slider('c', 'Contrast', 50, 160, 1, '%') + slider('s', 'Saturation', 0, 200, 1, '%') +
                    slider('w', 'Warmth', -50, 50, 1, '') + slider('v', 'Vignette', 0, 100, 1, '') +
                    (st.base ? '<p class="awm-note">The camera filter you recorded with is included. Choose a preset to replace it.</p>' : '') + '</div>' : '') +
                // Sound
                '<div class="awm-pane" data-pane="sound">' +
                '<div class="awm-row"><label>Noise reduction</label><div class="awm-chips" data-group="nr">' +
                ['off:Off', 'light:Light', 'medium:Medium', 'strong:Strong'].map(function (s) { var p = s.split(':'); return '<button type="button" class="awm-btn" data-nr="' + p[0] + '">' + p[1] + '</button>'; }).join('') + '</div></div>' +
                '<div class="awm-row"><label><input type="checkbox" data-k="voice"> Voice clarity (clearer, more even speech)</label></div>' +
                sliderS('volumeDb', 'Volume', -12, 12, 1, ' dB') +
                '<div class="awm-row"><label><input type="checkbox" data-k="normalise"> Normalise loudness</label></div>' +
                sliderS('fadeIn', 'Fade in', 0, 3, 0.1, ' s') + sliderS('fadeOut', 'Fade out', 0, 3, 0.1, ' s') +
                (kind === 'video' ? '<div class="awm-row"><label><input type="checkbox" data-k="mute"> Remove the sound</label></div>' : '') +
                '<p class="awm-note">Background noise was already reduced while recording. Use Medium or Strong for a noisy room. Press Play to hear the result.</p></div>' +
                '</div>' +
                '<div class="awm-foot"><button type="button" class="awm-btn" data-act="original">Use original</button>' +
                '<button type="button" class="awm-btn pri" data-act="apply">✔ Apply edits &amp; use</button></div>' +
                '<div class="awm-prog" role="status" aria-live="polite"><strong>Preparing your edited recording…</strong><progress max="100" value="0"></progress><span class="awm-pct">0%</span>' +
                '<p class="awm-note">This takes as long as the edited recording. Please keep this window open.</p><button type="button" class="awm-btn" data-act="cancelexport">Cancel</button></div>';

            function slider(k, label, min, max, step, unit) {
                return '<div class="awm-row"><label for="awm-f-' + k + '">' + label + '</label><input type="range" id="awm-f-' + k + '" data-f="' + k + '" min="' + min + '" max="' + max + '" step="' + step + '"><output data-o="' + k + '" data-u="' + unit + '"></output></div>';
            }
            function sliderS(k, label, min, max, step, unit) {
                return '<div class="awm-row"><label for="awm-s-' + k + '">' + label + '</label><input type="range" id="awm-s-' + k + '" data-s="' + k + '" min="' + min + '" max="' + max + '" step="' + step + '"><output data-os="' + k + '" data-u="' + unit + '"></output></div>';
            }

            back.appendChild(box);
            document.body.appendChild(back);
            var q = function (s) { return box.querySelector(s); };
            var qa = function (s) { return Array.prototype.slice.call(box.querySelectorAll(s)); };
            var media = q(kind === 'video' ? 'video' : 'audio');
            var tl = q('.awm-tl'), cv = tl.querySelector('canvas'), c2 = cv.getContext('2d');
            var timeEl = q('.awm-time'), keptEl = q('.awm-kept');
            media.src = url;

            // Preview sound goes through the same chain as the export, so what you hear is what you get.
            var pctx = new AC(), pchain = null;
            buildChain(pctx, st.snd).then(function (ch) {
                pchain = ch;
                pctx.createMediaElementSource(media).connect(ch.input);
                ch.output.connect(pctx.destination);
            }).catch(function () {});

            // Waveform, duration and peak level from the decoded audio.
            blob.arrayBuffer().then(function (buf) { return pctx.decodeAudioData(buf); }).then(function (ab) {
                decoded = ab;
                if (!st.dur || Math.abs(st.dur - ab.duration) > 0.5) setDuration(ab.duration);
                var n = 1200, ch = ab.getChannelData(0), step = Math.max(1, Math.floor(ch.length / n));
                peaks = new Float32Array(n);
                for (var i = 0; i < n; i++) {
                    var m = 0, s0 = i * step, e0 = Math.min(ch.length, s0 + step);
                    for (var j = s0; j < e0; j++) { var v = Math.abs(ch[j]); if (v > m) m = v; }
                    peaks[i] = m;
                }
                draw();
            }).catch(function () { var n = q('[data-k=normalise]'); if (n) { n.disabled = true; n.parentNode.title = 'Not available for this recording'; } });

            media.addEventListener('loadedmetadata', function () {
                layoutFrame();
                knownDuration(media).then(function (d) { if (!st.dur && d) setDuration(d); });
            });

            function setDuration(d) {
                var full = st.b === st.dur;
                st.dur = d;
                if (!st.b || full) st.b = d;
                st.a = Math.min(st.a, st.b);
                tl.setAttribute('aria-valuemax', d.toFixed(1));
                draw(); refresh();
            }

            // ----- segments: what is kept
            function segments() {
                var segs = [[st.a, st.b]];
                st.cuts.slice().sort(function (x, y) { return x[0] - y[0]; }).forEach(function (c) {
                    var out = [];
                    segs.forEach(function (s) {
                        if (c[1] <= s[0] || c[0] >= s[1]) { out.push(s); return; }
                        if (c[0] > s[0]) out.push([s[0], c[0]]);
                        if (c[1] < s[1]) out.push([c[1], s[1]]);
                    });
                    segs = out;
                });
                return segs.filter(function (s) { return s[1] - s[0] > 0.05; });
            }
            function kept() { return segments().reduce(function (t, s) { return t + s[1] - s[0]; }, 0); }
            function outPos(t) {
                var p = 0, segs = segments();
                for (var i = 0; i < segs.length; i++) {
                    if (t >= segs[i][1]) p += segs[i][1] - segs[i][0];
                    else { if (t > segs[i][0]) p += t - segs[i][0]; break; }
                }
                return p;
            }
            function fadeFactor(pos, total) {
                var g = 1;
                if (st.snd.fadeIn > 0) g = Math.min(g, pos / st.snd.fadeIn);
                if (st.snd.fadeOut > 0) g = Math.min(g, (total - pos) / st.snd.fadeOut);
                return clamp(g, 0, 1);
            }
            function changed() {
                var segs = segments();
                var trimmed = !(segs.length === 1 && segs[0][0] < 0.05 && st.dur - segs[0][1] < 0.05);
                var cropped = kind === 'video' && (st.crop.w < 0.999 || st.crop.h < 0.999 || st.mirror);
                var filtered = kind === 'video' && (st.preset !== 'none' || ['b', 'c', 's', 'w', 'v'].some(function (k) { return st.f[k] !== PRESETS.none[k]; }));
                var s = st.snd, sound = s.nr !== 'off' || s.voice || s.volumeDb || s.normalise || s.fadeIn || s.fadeOut || s.mute;
                return trimmed || cropped || filtered || sound;
            }
            function pushHist() { st.hist.push(JSON.stringify({ a: st.a, b: st.b, cuts: st.cuts })); if (st.hist.length > 50) st.hist.shift(); }

            // ----- timeline drawing and interaction
            function draw() {
                var w = tl.clientWidth, h = tl.clientHeight, dpr = window.devicePixelRatio || 1;
                if (!w) return;
                cv.width = w * dpr; cv.height = h * dpr; c2.setTransform(dpr, 0, 0, dpr, 0, 0);
                c2.clearRect(0, 0, w, h);
                var d = st.dur || 1, X = function (t) { return t / d * w; };
                c2.fillStyle = '#6f6680';
                if (peaks) {
                    for (var x = 0; x < w; x++) {
                        var i = Math.floor(x / w * peaks.length), v = Math.min(1, peaks[i] * 1.6) * (h / 2 - 6);
                        c2.fillRect(x, h / 2 - v, 1, Math.max(1, v * 2));
                    }
                } else { c2.fillRect(0, h / 2 - 1, w, 2); }
                c2.fillStyle = 'rgba(0,0,0,.6)';
                c2.fillRect(0, 0, X(st.a), h); c2.fillRect(X(st.b), 0, w - X(st.b), h);
                st.cuts.forEach(function (c) {
                    c2.fillStyle = 'rgba(220,60,70,.45)'; c2.fillRect(X(c[0]), 0, X(c[1]) - X(c[0]), h);
                    c2.strokeStyle = 'rgba(255,120,120,.9)'; c2.beginPath(); c2.moveTo(X(c[0]), 0); c2.lineTo(X(c[1]), h); c2.moveTo(X(c[1]), 0); c2.lineTo(X(c[0]), h); c2.stroke();
                });
                if (st.sel) { c2.fillStyle = 'rgba(219,161,162,.35)'; c2.fillRect(X(st.sel[0]), 0, X(st.sel[1]) - X(st.sel[0]), h); }
                [st.a, st.b].forEach(function (t) { c2.fillStyle = '#fff'; c2.fillRect(X(t) - 2, 0, 4, h); c2.fillRect(X(t) - 6, h / 2 - 12, 12, 24); });
                c2.fillStyle = '#DBA1A2'; c2.fillRect(X(media.currentTime || 0) - 1, 0, 2, h);
            }
            function tAt(ev) { var r = tl.getBoundingClientRect(); return clamp((ev.clientX - r.left) / r.width, 0, 1) * (st.dur || 0); }
            var drag = null;
            tl.addEventListener('pointerdown', function (ev) {
                if (!st.dur) return;
                tl.setPointerCapture(ev.pointerId);
                var t = tAt(ev), tol = st.dur * 14 / tl.clientWidth;
                if (Math.abs(t - st.a) < tol) drag = { k: 'a' };
                else if (Math.abs(t - st.b) < tol) drag = { k: 'b' };
                else drag = { k: 'sel', t0: t, moved: false };
                if (drag.k !== 'sel') pushHist();
            });
            tl.addEventListener('pointermove', function (ev) {
                if (!drag) return;
                var t = tAt(ev);
                if (drag.k === 'a') st.a = clamp(t, 0, st.b - 0.2);
                else if (drag.k === 'b') st.b = clamp(t, st.a + 0.2, st.dur);
                else { if (Math.abs(t - drag.t0) > st.dur * 4 / tl.clientWidth) drag.moved = true; if (drag.moved) st.sel = [Math.min(t, drag.t0), Math.max(t, drag.t0)]; }
                draw(); refresh();
            });
            tl.addEventListener('pointerup', function (ev) {
                if (drag && drag.k === 'sel' && !drag.moved) { st.sel = null; media.currentTime = tAt(ev); }
                if (drag && drag.k === 'a') media.currentTime = st.a;
                drag = null; draw(); refresh();
            });
            tl.addEventListener('keydown', function (ev) {
                var t = media.currentTime || 0;
                if (ev.key === 'ArrowRight') { media.currentTime = Math.min(st.dur, t + (ev.shiftKey ? 5 : 1)); ev.preventDefault(); }
                else if (ev.key === 'ArrowLeft') { media.currentTime = Math.max(0, t - (ev.shiftKey ? 5 : 1)); ev.preventDefault(); }
                else if (ev.key === '[') { act('setA'); }
                else if (ev.key === ']') { act('setB'); }
            });

            // ----- playback (skips trimmed and cut parts)
            var raf = 0;
            function loop() {
                var t = media.currentTime || 0;
                if (!media.paused && st.dur) {
                    if (t < st.a - 0.05) media.currentTime = st.a;
                    st.cuts.forEach(function (c) { if (t >= c[0] && t < c[1] - 0.02) media.currentTime = c[1]; });
                    if (t >= st.b) { media.pause(); media.currentTime = st.a; }
                }
                var g = fadeFactor(outPos(t), kept());
                if (pchain) pchain.fade.gain.setTargetAtTime(g, pctx.currentTime, 0.01);
                var fo = q('.awm-fadeov'); if (fo) fo.style.opacity = String(1 - g);
                timeEl.textContent = fmt(t) + ' / ' + fmt(st.dur);
                tl.setAttribute('aria-valuenow', t.toFixed(1)); tl.setAttribute('aria-valuetext', fmt(t));
                draw();
                raf = requestAnimationFrame(loop);
            }
            raf = requestAnimationFrame(loop);
            media.addEventListener('play', function () { q('[data-act=play]').textContent = '⏸ Pause'; });
            media.addEventListener('pause', function () { q('[data-act=play]').textContent = '▶ Play'; });

            // ----- video frame, crop box and look
            var frame = q('.awm-frame'), stage = q('.awm-stage'), cropEl = q('.awm-crop');
            function layoutFrame() {
                if (!frame || !media.videoWidth) return;
                var pad = 14; // room for the crop handles
                var sw = stage.clientWidth, sh = stage.clientHeight, r = media.videoWidth / media.videoHeight;
                var w = sw - 2 * pad, h = w / r; if (h > sh - 2 * pad) { h = sh - 2 * pad; w = h * r; }
                frame.style.width = w + 'px'; frame.style.height = h + 'px';
                frame.style.left = (sw - w) / 2 + 'px'; frame.style.top = (sh - h) / 2 + 'px';
                placeCrop();
            }
            function placeCrop() {
                if (!cropEl) return;
                var c = st.crop;
                cropEl.style.left = c.x * 100 + '%'; cropEl.style.top = c.y * 100 + '%';
                cropEl.style.width = c.w * 100 + '%'; cropEl.style.height = c.h * 100 + '%';
                var full = c.w > 0.999 && c.h > 0.999;
                cropEl.classList.toggle('off', full && !(q('[data-pane=crop]') || {}).classList.contains('show'));
            }
            function aspectN() { // crop width/height ratio in frame units for the chosen aspect
                if (st.aspect === 'free' || st.aspect === 'full' || !media.videoWidth) return 0;
                var p = st.aspect.split(':'); return (p[0] / p[1]) / (media.videoWidth / media.videoHeight);
            }
            function setAspect(a) {
                st.aspect = a;
                if (a === 'full') { st.crop = { x: 0, y: 0, w: 1, h: 1 }; st.aspect = 'free'; }
                else {
                    var n = aspectN();
                    if (n) { var w = 1, h = w / n; if (h > 1) { h = 1; w = n; } st.crop = { x: (1 - w) / 2, y: (1 - h) / 2, w: w, h: h }; }
                }
                qa('[data-aspect]').forEach(function (b) { b.classList.toggle('on', b.dataset.aspect === st.aspect); });
                placeCrop(); refresh();
            }
            if (cropEl) {
                var cd = null;
                cropEl.addEventListener('pointerdown', function (ev) {
                    ev.stopPropagation(); cropEl.setPointerCapture(ev.pointerId);
                    cd = { h: ev.target.dataset.h || 'move', x: ev.clientX, y: ev.clientY, c: Object.assign({}, st.crop) };
                });
                cropEl.addEventListener('pointermove', function (ev) {
                    if (!cd) return;
                    var fw = frame.clientWidth, fh = frame.clientHeight, dx = (ev.clientX - cd.x) / fw, dy = (ev.clientY - cd.y) / fh, c = cd.c, n = aspectN(), min = 0.1;
                    if (cd.h === 'move') {
                        st.crop = { x: clamp(c.x + dx, 0, 1 - c.w), y: clamp(c.y + dy, 0, 1 - c.h), w: c.w, h: c.h };
                    } else {
                        var left = cd.h.indexOf('w') >= 0, top = cd.h.indexOf('n') >= 0;
                        var ax = left ? c.x + c.w : c.x, ay = top ? c.y + c.h : c.y;   // the opposite corner stays put
                        var w = clamp(left ? c.w - dx : c.w + dx, min, left ? ax : 1 - ax);
                        var h = clamp(top ? c.h - dy : c.h + dy, min, top ? ay : 1 - ay);
                        if (n) { h = w / n; var maxH = top ? ay : 1 - ay; if (h > maxH) { h = maxH; w = h * n; } if (h < min) { h = min; w = h * n; } }
                        st.crop = { x: left ? ax - w : ax, y: top ? ay - h : ay, w: w, h: h };
                    }
                    placeCrop(); refresh();
                });
                cropEl.addEventListener('pointerup', function () { cd = null; });
            }
            function applyLook() {
                if (kind !== 'video') return;
                media.style.filter = cssFilter(st.f, st.preset === 'none' ? st.base : '');
                media.style.transform = st.mirror ? 'scaleX(-1)' : '';
                q('.awm-tint').style.background = st.f.w ? tintColor(st.f.w) : 'transparent';
                q('.awm-vig').style.background = st.f.v ? 'radial-gradient(ellipse at center, rgba(0,0,0,0) 45%, rgba(0,0,0,' + (st.f.v / 100 * 0.85) + ') 100%)' : 'transparent';
            }

            // ----- controls
            function refresh() {
                q('[data-act=cut]').disabled = !st.sel;
                q('[data-act=undo]').disabled = !st.hist.length;
                keptEl.textContent = st.dur ? 'Kept: ' + fmt(kept()) + (st.cuts.length ? ' · ' + st.cuts.length + ' cut' + (st.cuts.length > 1 ? 's' : '') : '') : '';
                qa('[data-f]').forEach(function (i) { i.value = st.f[i.dataset.f]; });
                qa('[data-o]').forEach(function (o) { o.textContent = st.f[o.dataset.o] + o.dataset.u; });
                qa('[data-s]').forEach(function (i) { i.value = st.snd[i.dataset.s]; });
                qa('[data-os]').forEach(function (o) { var v = st.snd[o.dataset.os]; o.textContent = (o.dataset.os === 'volumeDb' && v > 0 ? '+' : '') + v + o.dataset.u; });
                qa('[data-k]').forEach(function (i) { i.checked = i.dataset.k === 'mirror' ? st.mirror : !!st.snd[i.dataset.k]; });
                qa('[data-nr]').forEach(function (b) { b.classList.toggle('on', b.dataset.nr === st.snd.nr); b.setAttribute('aria-pressed', b.dataset.nr === st.snd.nr); });
                qa('[data-preset]').forEach(function (b) { b.classList.toggle('on', b.dataset.preset === st.preset); });
                q('[data-act=apply]').textContent = changed() ? '✔ Apply edits & use' : '✔ Use recording';
                applyLook();
                if (pchain) pchain.update(st.snd);
            }
            function normalise() {
                st.snd.normDb = 0;
                if (!st.snd.normalise || !decoded) return;
                var peak = 0, sr = decoded.sampleRate;
                for (var c = 0; c < decoded.numberOfChannels; c++) {
                    var d = decoded.getChannelData(c);
                    segments().forEach(function (s) {
                        for (var i = Math.floor(s[0] * sr), e = Math.min(d.length, Math.floor(s[1] * sr)); i < e; i++) { var v = Math.abs(d[i]); if (v > peak) peak = v; }
                    });
                }
                if (peak > 0) st.snd.normDb = clamp(-1 - 20 * Math.log(peak) / Math.LN10, -12, 12);
            }
            function act(name) {
                var t = media.currentTime || 0;
                switch (name) {
                    case 'play':
                        if (pctx.state === 'suspended') pctx.resume();
                        if (media.paused) { if (t >= st.b - 0.05 || t < st.a) media.currentTime = st.a; media.play(); } else media.pause();
                        break;
                    case 'tostart': media.currentTime = st.a; break;
                    case 'setA': if (t < st.b - 0.2) { pushHist(); st.a = t; } break;
                    case 'setB': if (t > st.a + 0.2) { pushHist(); st.b = t; } break;
                    case 'cut': if (st.sel) { pushHist(); st.cuts.push(st.sel); st.sel = null; } break;
                    case 'undo': if (st.hist.length) { var h = JSON.parse(st.hist.pop()); st.a = h.a; st.b = h.b; st.cuts = h.cuts; } break;
                    case 'reset':
                        pushHist(); st.a = 0; st.b = st.dur; st.cuts = []; st.sel = null;
                        st.crop = { x: 0, y: 0, w: 1, h: 1 }; st.aspect = 'free'; st.mirror = false;
                        st.f = Object.assign({}, PRESETS.none); st.preset = 'none';
                        st.snd = { nr: 'off', voice: false, volumeDb: 0, normalise: false, normDb: 0, fadeIn: 0, fadeOut: 0, mute: false };
                        placeCrop(); break;
                    case 'close': case 'original': finish(blob); return;
                    case 'apply': if (!changed()) { finish(blob); return; } exportNow(); return;
                    case 'cancelexport': if (job) job.cancelled = true; return;
                }
                normalise(); refresh(); draw();
            }
            box.addEventListener('click', function (ev) {
                var b = ev.target.closest('button'); if (!b || !box.contains(b)) return;
                if (b.dataset.act) act(b.dataset.act);
                else if (b.dataset.tab) {
                    qa('.awm-tab').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
                    qa('.awm-pane').forEach(function (p) { p.classList.toggle('show', p.dataset.pane === b.dataset.tab); });
                    placeCrop();
                } else if (b.dataset.aspect) setAspect(b.dataset.aspect);
                else if (b.dataset.preset) {
                    st.preset = b.dataset.preset; var p = PRESETS[st.preset];
                    st.f = { b: p.b, c: p.c, s: p.s, w: p.w, v: p.v, g: p.g, p: p.p }; refresh();
                } else if (b.dataset.nr) { st.snd.nr = b.dataset.nr; refresh(); }
            });
            box.addEventListener('input', function (ev) {
                var i = ev.target;
                if (i.dataset.f) { st.f[i.dataset.f] = +i.value; refresh(); }
                else if (i.dataset.s) { st.snd[i.dataset.s] = +i.value; refresh(); }
                else if (i.dataset.k === 'mirror') { st.mirror = i.checked; refresh(); }
                else if (i.dataset.k) { st.snd[i.dataset.k] = i.checked; normalise(); refresh(); }
            });
            function onKey(ev) {
                if (ev.key === 'Escape') { ev.preventDefault(); if (job) job.cancelled = true; else act('close'); }
                else if (ev.key === ' ' && !/INPUT|BUTTON|SELECT|TEXTAREA/.test(document.activeElement.tagName)) { ev.preventDefault(); act('play'); }
                else if (ev.key === 'Tab') { // keep focus inside the dialog
                    var f = qa('button:not(:disabled),input:not(:disabled),[tabindex="0"]').filter(function (x) { return x.offsetParent; });
                    if (!f.length) return;
                    if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
                    else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
                }
            }
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', onResize);
            function onResize() { layoutFrame(); draw(); }

            // ----- export: play the kept parts through the edits and record the result
            var job = null;
            function exportNow() {
                media.pause();
                // Created inside the click so the browser lets them play with sound.
                var xm = document.createElement(kind === 'video' ? 'video' : 'audio');
                xm.src = url; xm.preload = 'auto'; xm.playsInline = true;
                xm.style.cssText = 'position:fixed;left:-9999px;top:0;width:2px;height:2px;opacity:0;pointer-events:none';
                document.body.appendChild(xm);
                var xctx = new AC();
                job = { cancelled: false };
                var prog = q('.awm-prog'), bar = prog.querySelector('progress'), pct = prog.querySelector('.awm-pct');
                prog.classList.add('show'); prog.querySelector('button').focus();
                var progress = function (f) { bar.value = Math.round(f * 100); pct.textContent = Math.round(f * 100) + '%'; };
                render(xm, xctx, job, progress).then(function (out) {
                    finish(out);
                }, function (err) {
                    prog.classList.remove('show');
                    if (!job || !job.cancelled) alert('Sorry, the edits could not be applied in this browser (' + (err && err.message || err) + '). You can still use the original recording.');
                    job = null;
                }).then(function () {
                    xm.removeAttribute('src'); xm.load(); xm.remove(); xctx.close().catch(function () {});
                });
            }
            function render(xm, xctx, jb, progress) {
                var segs = segments(), total = kept();
                var canvas = null, cx = null, W = 0, H = 0;
                return (xm.readyState >= 1 ? Promise.resolve() : once(xm, 'loadedmetadata'))
                    .then(function () { return xctx.state === 'suspended' ? xctx.resume() : null; })
                    .then(function () { return buildChain(xctx, st.snd); })
                    .then(function (chain) {
                        xctx.createMediaElementSource(xm).connect(chain.input);
                        var dest = xctx.createMediaStreamDestination();
                        chain.output.connect(dest);
                        var stream;
                        if (kind === 'video') {
                            var vw = xm.videoWidth, vh = xm.videoHeight, c = st.crop;
                            var sw = c.w * vw, sh = c.h * vh, scale = Math.min(1, 1280 / Math.max(sw, sh));
                            W = Math.max(2, Math.round(sw * scale / 2) * 2); H = Math.max(2, Math.round(sh * scale / 2) * 2);
                            canvas = document.createElement('canvas'); canvas.width = W; canvas.height = H;
                            cx = canvas.getContext('2d');
                            stream = canvas.captureStream(30);
                            dest.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); });
                        } else {
                            stream = dest.stream;
                        }
                        var mime = pickMime(kind);
                        var rec = new MediaRecorder(stream, mime ? { mimeType: mime, videoBitsPerSecond: 2500000, audioBitsPerSecond: 128000 } : undefined);
                        var chunks = [];
                        rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
                        var stopped = once(rec, 'stop');
                        var filter = cssFilter(st.f, st.preset === 'none' ? st.base : '');
                        function paint(g) {
                            if (!cx) return;
                            var c = st.crop, vw = xm.videoWidth, vh = xm.videoHeight;
                            var sx = (st.mirror ? 1 - c.x - c.w : c.x) * vw;
                            cx.save();
                            cx.filter = filter;
                            if (st.mirror) { cx.translate(W, 0); cx.scale(-1, 1); }
                            cx.drawImage(xm, sx, c.y * vh, c.w * vw, c.h * vh, 0, 0, W, H);
                            cx.restore();
                            if (st.f.w) { cx.save(); cx.globalCompositeOperation = 'soft-light'; cx.fillStyle = tintColor(st.f.w); cx.fillRect(0, 0, W, H); cx.restore(); }
                            if (st.f.v) {
                                var grd = cx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.3, W / 2, H / 2, Math.hypot(W, H) / 2);
                                grd.addColorStop(0, 'rgba(0,0,0,0)'); grd.addColorStop(1, 'rgba(0,0,0,' + (st.f.v / 100 * 0.85) + ')');
                                cx.fillStyle = grd; cx.fillRect(0, 0, W, H);
                            }
                            if (g < 1) { cx.fillStyle = 'rgba(0,0,0,' + (1 - g) + ')'; cx.fillRect(0, 0, W, H); }
                        }
                        var base = 0, started = false;
                        function playSeg(i) {
                            if (i >= segs.length) return Promise.resolve();
                            var a = segs[i][0], b = segs[i][1];
                            return seek(xm, a).then(function () {
                                if (jb.cancelled) throw new Error('cancelled');
                                paint(fadeFactor(base, total));
                                chain.edge.gain.cancelScheduledValues(xctx.currentTime);
                                chain.edge.gain.setValueAtTime(0, xctx.currentTime);
                                chain.edge.gain.linearRampToValueAtTime(1, xctx.currentTime + 0.02);
                                if (!started) { rec.start(500); started = true; } else rec.resume();
                                return xm.play();
                            }).then(function () {
                                return new Promise(function (res, rej) {
                                    (function tick() {
                                        if (jb.cancelled) { xm.pause(); rej(new Error('cancelled')); return; }
                                        var t = xm.currentTime, pos = base + Math.max(0, t - a), g = fadeFactor(pos, total);
                                        chain.fade.gain.setTargetAtTime(g, xctx.currentTime, 0.01);
                                        paint(g);
                                        progress(Math.min(1, pos / total));
                                        if (t >= b - 0.03 || xm.ended) {
                                            chain.edge.gain.setTargetAtTime(0, xctx.currentTime, 0.006);
                                            setTimeout(function () { xm.pause(); if (rec.state === 'recording') rec.pause(); res(); }, 35);
                                            return;
                                        }
                                        setTimeout(tick, 15);
                                    })();
                                });
                            }).then(function () { base += b - a; return playSeg(i + 1); });
                        }
                        return playSeg(0).then(function () {
                            progress(1);
                            if (rec.state !== 'inactive') rec.stop();
                            return stopped;
                        }, function (err) {
                            if (rec.state !== 'inactive') rec.stop();
                            throw err;
                        }).then(function () {
                            var type = (rec.mimeType || mime || (kind === 'video' ? 'video/webm' : 'audio/webm'));
                            var out = new Blob(chunks, { type: type });
                            if (!out.size) throw new Error('nothing was recorded');
                            return out;
                        });
                    });
            }

            var finished = false;
            function finish(result) {
                if (finished) return; finished = true;
                cancelAnimationFrame(raf);
                document.removeEventListener('keydown', onKey, true);
                window.removeEventListener('resize', onResize);
                media.pause(); media.removeAttribute('src'); media.load();
                pctx.close().catch(function () {});
                back.remove();
                if (result !== blob) URL.revokeObjectURL(url); else setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                if (prevFocus && prevFocus.focus) try { prevFocus.focus(); } catch (e) {}
                resolve(result);
            }

            refresh();
            setTimeout(function () { layoutFrame(); draw(); q('[data-act=play]').focus(); }, 0);
        });
    }

    // Adds (once) an "Edit recording" button after a preview; edits replace the file in the input.
    function attachEditButton(input, preview, kind, name) {
        if (!input || !preview || !window.DataTransfer) return;
        var anchor = preview;
        var btn = anchor.parentNode.querySelector('.awm-attach');
        if (btn) return;
        btn = el('button', { type: 'button', 'class': 'btn btn-sm btn-outline awm-attach' }, '✂️ Edit recording');
        anchor.insertAdjacentElement('afterend', btn);
        btn.addEventListener('click', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            review(file, { kind: kind }).then(function (out) {
                if (out === file) return;
                var f = new File([out], name || file.name, { type: file.type || out.type });
                var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
                preview.srcObject = null;
                preview.src = URL.createObjectURL(f);
                if (preview.load) preview.load();
            });
        });
    }

    window.AWMedia = { getStream: getStream, review: review, attachEditButton: attachEditButton, pickMime: pickMime };
})();
