<?php
/**
 * Built-in human check (no outside service, no keys, nothing sent by email).
 *
 * Used on sign-up, the contact form, "forgot password" and the recovery form. It works like the
 * familiar "I'm not a robot" box, without Google:
 *   1. the person ticks "I'm not a robot";
 *   2. a picture puzzle opens: nine small pictures drawn on the server (shapes in random colours,
 *      sizes, angles and noise), "Select every picture with a heart". The pictures have random
 *      addresses and the answer never leaves the server, so a bot cannot read it from the page;
 *   3. only a correct answer ticks the box. A wrong answer brings a new puzzle; after 5 wrong
 *      answers the form must be reloaded;
 *   4. someone who solved a puzzle in the last 30 minutes (same browser session) is ticked
 *      straight away, as Google does for people it already trusts;
 *   5. for people who cannot see the pictures, or without JavaScript, a question in words;
 * plus a hidden trap field (bots fill every field), timing (a form sent under 3 seconds or after
 * 2 hours is refused) and limits per network address (aw_hc_limit()).
 * Every challenge is used once. Nothing throws; failures return a message for the person.
 * The endpoint for the puzzle is human_check.php in the site root.
 */

const AW_HC_MIN_SECONDS = 3;
const AW_HC_MAX_SECONDS = 7200;
const AW_HC_TRUST_SECONDS = 1800;   // a solved puzzle is trusted this long
const AW_HC_MAX_TRIES = 5;
const AW_HC_SHAPES = ['heart' => 'a heart', 'star' => 'a star', 'circle' => 'a circle', 'square' => 'a square', 'triangle' => 'a triangle', 'cross' => 'a cross', 'moon' => 'a moon', 'diamond' => 'a diamond'];

function aw_hc_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (!isset($_SESSION['aw_hc']) || !is_array($_SESSION['aw_hc'])) $_SESSION['aw_hc'] = [];
    // keep the newest 12 challenges (several tabs may be open)
    if (count($_SESSION['aw_hc']) > 12) $_SESSION['aw_hc'] = array_slice($_SESSION['aw_hc'], -12, null, true);
}

function aw_hc_words_question() {
    $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
    $a = random_int(2, 9); $b = random_int(1, 9);
    return ['q' => 'What is ' . $words[$a] . ' plus ' . $words[$b] . '? (in numbers)', 'sum' => $a + $b];
}

/** The HTML to put inside a form. */
function aw_hc_field($form) {
    aw_hc_session();
    $id = bin2hex(random_bytes(8));
    $wq = aw_hc_words_question();
    $salt = bin2hex(random_bytes(12));
    $trap = 'aw_hp_' . substr(hash('sha256', $id . $salt), 0, 6);
    $_SESSION['aw_hc'][$id] = ['form' => (string)$form, 'at' => time(), 'trap' => $trap, 'sum' => $wq['sum'], 'q' => $wq['q'], 'passed' => false, 'tries' => 0];
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $h = '<div class="aw-hc" data-aw-hc data-id="' . $e($id) . '" data-endpoint="' . $e(SITE_URL . '/human_check.php') . '">'
       . '<input type="hidden" name="aw_hc_id" value="' . $e($id) . '">'
       . '<div class="aw-hc-trap" aria-hidden="true"><label>Leave this empty <input type="text" name="' . $trap . '" value="" tabindex="-1" autocomplete="off"></label></div>'
       . '<div class="aw-hc-box" hidden>'
       .   '<button type="button" class="aw-hc-check" role="checkbox" aria-checked="false" aria-describedby="aw_hc_note_' . $e($id) . '"><span class="aw-hc-tick" aria-hidden="true"></span><span class="aw-hc-label">I’m not a robot</span></button>'
       .   '<span class="aw-hc-brand" aria-hidden="true"><i class="fas fa-shield-heart"></i><small>AngelWrites check</small></span>'
       . '</div>'
       . '<p class="aw-hc-note" id="aw_hc_note_' . $e($id) . '" role="status" aria-live="polite"></p>'
       . '<div class="aw-hc-puzzle" role="dialog" aria-modal="false" aria-labelledby="aw_hc_t_' . $e($id) . '" hidden>'
       .   '<p class="aw-hc-title" id="aw_hc_t_' . $e($id) . '"></p>'
       .   '<div class="aw-hc-grid" role="group"></div>'
       .   '<div class="aw-hc-acts"><button type="button" class="aw-hc-new" aria-label="A different puzzle"><i class="fas fa-rotate" aria-hidden="true"></i></button>'
       .   '<button type="button" class="aw-hc-words-btn">I can’t see the pictures</button>'
       .   '<button type="button" class="aw-hc-verify">Verify</button></div>'
       . '</div>'
       // without JavaScript, or for people who cannot see the pictures: a question in words
       . '<div class="form-group aw-hc-q" data-aw-hc-words' . '><label for="aw_hc_answer_' . $e($id) . '">Quick check: ' . $e($wq['q']) . '</label>'
       . '<input type="text" inputmode="numeric" id="aw_hc_answer_' . $e($id) . '" name="aw_hc_answer" autocomplete="off"></div>'
       . '</div>';
    return $h . aw_hc_assets();
}

/** '' when the form passed the check, otherwise a message to show. The challenge is used up either way. */
function aw_hc_verify($form, array $post) {
    aw_hc_session();
    $id = (string)($post['aw_hc_id'] ?? '');
    $c = $_SESSION['aw_hc'][$id] ?? null;
    unset($_SESSION['aw_hc'][$id]);
    $again = ' Please try again.';
    if (!$c || $c['form'] !== (string)$form) return 'This form has expired.' . $again;
    if (!empty($post[$c['trap']] ?? '')) { error_log('human check: trap field filled (' . $form . ')'); return 'Something went wrong.' . $again; }
    $age = time() - (int)$c['at'];
    if ($age < AW_HC_MIN_SECONDS) return 'That was very quick! Please take a moment and send the form again.';
    if ($age > AW_HC_MAX_SECONDS) return 'This form was open for a long time and has expired.' . $again;
    if (!empty($c['passed'])) return '';
    $ans = trim((string)($post['aw_hc_answer'] ?? ''));
    if ($ans !== '' && ctype_digit($ans) && (int)$ans === (int)$c['sum']) {
        // the question in words is for people who cannot use the pictures; a bot could read it, so it is
        // limited per connection and never earns the 30-minute trust the picture puzzle gives
        if (!aw_hc_limit('hc_words', 4, 3600)) return 'Please use the picture puzzle (the question has been used several times from this connection).';
        return '';
    }
    return $ans === '' ? 'Please tick “I’m not a robot” and solve the picture puzzle, then send the form again.' : 'The answer to the quick check was not right.' . $again;
}

// ===== the picture puzzle (called by human_check.php) =========================================

/** A new puzzle for challenge $id: ['ok', 'title', 'tiles' => [token...]] or ['ok' => false, 'msg']. */
function aw_hc_new_puzzle($id) {
    aw_hc_session();
    if (!isset($_SESSION['aw_hc'][$id])) return ['ok' => false, 'msg' => 'This form has expired. Please reload the page.'];
    $c = &$_SESSION['aw_hc'][$id];
    if ($c['tries'] >= AW_HC_MAX_TRIES) return ['ok' => false, 'msg' => 'Too many tries. Please reload the page and try again.'];
    if (!function_exists('imagecreatetruecolor')) return ['ok' => false, 'words' => true, 'msg' => 'Please answer the question below instead.'];
    $names = array_keys(AW_HC_SHAPES);
    $target = $names[random_int(0, count($names) - 1)];
    $others = array_values(array_diff($names, [$target]));
    $n = random_int(2, 4);
    $shapes = array_fill(0, $n, $target);
    while (count($shapes) < 9) $shapes[] = $others[random_int(0, count($others) - 1)];
    shuffle($shapes);
    $tiles = [];
    foreach ($shapes as $s) $tiles[bin2hex(random_bytes(6))] = $s;
    $c['puzzle'] = ['target' => $target, 'tiles' => $tiles, 'at' => microtime(true)];
    return ['ok' => true, 'title' => 'Select every picture with ' . AW_HC_SHAPES[$target] . '.', 'target' => $target, 'tiles' => array_keys($tiles)];
}

/** Check an answer: the exact set of tiles with the target shape. */
function aw_hc_check_puzzle($id, array $picked) {
    aw_hc_session();
    if (!isset($_SESSION['aw_hc'][$id]['puzzle'])) return ['ok' => false, 'msg' => 'This puzzle has expired. Here is a new one.'];
    $c = &$_SESSION['aw_hc'][$id];
    $p = $c['puzzle'];
    unset($c['puzzle']); // one answer per puzzle
    $c['tries']++;
    if (microtime(true) - $p['at'] < 1.0) return ['ok' => false, 'msg' => 'That was too quick. Please look at the pictures and try again.'];
    $want = array_keys(array_filter($p['tiles'], function ($s) use ($p) { return $s === $p['target']; }));
    $picked = array_values(array_unique(array_filter(array_map('strval', $picked), function ($t) use ($p) { return isset($p['tiles'][$t]); })));
    sort($want); sort($picked);
    if ($want === $picked) { $c['passed'] = true; $_SESSION['aw_hc_trusted'] = time(); return ['ok' => true]; }
    return ['ok' => false, 'msg' => $c['tries'] >= AW_HC_MAX_TRIES ? 'Too many tries. Please reload the page and try again.' : 'Not quite. Please try this new puzzle.'];
}

/** Someone who solved a puzzle recently is ticked at once. */
function aw_hc_trust($id) {
    aw_hc_session();
    if (!isset($_SESSION['aw_hc'][$id])) return false;
    if (time() - (int)($_SESSION['aw_hc_trusted'] ?? 0) < AW_HC_TRUST_SECONDS) { $_SESSION['aw_hc'][$id]['passed'] = true; return true; }
    return false;
}

/** Draw one tile as PNG bytes, or '' when it cannot be drawn. */
function aw_hc_tile_png($id, $token) {
    aw_hc_session();
    $shape = $_SESSION['aw_hc'][$id]['puzzle']['tiles'][$token] ?? null;
    if ($shape === null || !function_exists('imagecreatetruecolor')) return '';
    $S = 120;
    $im = imagecreatetruecolor($S, $S);
    $hsl = function ($h, $s, $l) use ($im) {
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
        [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
        return imagecolorallocate($im, (int)(($r + $m) * 255), (int)(($g + $m) * 255), (int)(($b + $m) * 255));
    };
    $bgH = random_int(0, 359);
    $bg = $hsl($bgH, random_int(25, 55) / 100, random_int(84, 93) / 100);
    imagefilledrectangle($im, 0, 0, $S, $S, $bg);
    // noise: soft blobs and lines so pictures are never identical
    for ($i = 0; $i < 6; $i++) { $col = $hsl(random_int(0, 359), .35, random_int(72, 86) / 100); imagefilledellipse($im, random_int(0, $S), random_int(0, $S), random_int(10, 40), random_int(10, 40), $col); }
    imagesetthickness($im, 2);
    for ($i = 0; $i < 4; $i++) { $col = $hsl(random_int(0, 359), .3, random_int(60, 80) / 100); imageline($im, random_int(0, $S), random_int(0, $S), random_int(0, $S), random_int(0, $S), $col); }
    $fg = $hsl(($bgH + random_int(120, 240)) % 360, random_int(55, 85) / 100, random_int(30, 45) / 100);
    $cx = $S / 2 + random_int(-12, 12); $cy = $S / 2 + random_int(-12, 12);
    $r = random_int(28, 38);
    $rot = deg2rad(random_int(-35, 35));
    $poly = function (array $pts) use ($im, $cx, $cy, $rot, $fg) {
        $flat = [];
        foreach ($pts as [$x, $y]) { $flat[] = (int)round($cx + $x * cos($rot) - $y * sin($rot)); $flat[] = (int)round($cy + $x * sin($rot) + $y * cos($rot)); }
        if (PHP_VERSION_ID >= 80100) imagefilledpolygon($im, $flat, $fg); else imagefilledpolygon($im, $flat, count($flat) / 2, $fg);
    };
    switch ($shape) {
        case 'circle': imagefilledellipse($im, (int)$cx, (int)$cy, 2 * $r, 2 * $r, $fg); break;
        case 'square': $poly([[-$r * .85, -$r * .85], [$r * .85, -$r * .85], [$r * .85, $r * .85], [-$r * .85, $r * .85]]); break;
        case 'diamond': $poly([[0, -$r * 1.1], [$r * .75, 0], [0, $r * 1.1], [-$r * .75, 0]]); break;
        case 'triangle': $poly([[0, -$r], [$r * .95, $r * .75], [-$r * .95, $r * .75]]); break;
        case 'cross': $w = $r * .36; $poly([[-$w, -$r], [$w, -$r], [$w, -$w], [$r, -$w], [$r, $w], [$w, $w], [$w, $r], [-$w, $r], [-$w, $w], [-$r, $w], [-$r, -$w], [-$w, -$w]]); break;
        case 'star': $pts = []; for ($k = 0; $k < 10; $k++) { $a = -M_PI / 2 + $k * M_PI / 5; $rr = $k % 2 ? $r * .45 : $r; $pts[] = [$rr * cos($a), $rr * sin($a)]; } $poly($pts); break;
        case 'heart': $pts = []; for ($t = 0; $t < 2 * M_PI; $t += .12) { $pts[] = [$r / 17 * 16 * pow(sin($t), 3), -$r / 17 * (13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t))]; } $poly($pts); break;
        case 'moon':
            imagefilledellipse($im, (int)$cx, (int)$cy, 2 * $r, 2 * $r, $fg);
            imagefilledellipse($im, (int)($cx + $r * .55 * cos($rot)), (int)($cy - $r * .35), (int)(1.7 * $r), (int)(1.7 * $r), $bg);
            break;
    }
    // a few specks on top
    for ($i = 0; $i < 60; $i++) imagesetpixel($im, random_int(0, $S - 1), random_int(0, $S - 1), $hsl(random_int(0, 359), .4, random_int(40, 90) / 100));
    ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im);
    return $png;
}

/**
 * A simple per-address limit: true while under $max events in $window seconds (and records this one
 * when $record). Addresses are stored hashed, in data/ (not reachable from the web).
 */
function aw_hc_limit($action, $max, $window, $record = true) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
    $dir = dirname(__DIR__) . '/data/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/hc_' . preg_replace('/[^a-z_]/', '', $action) . '_' . substr(hash('sha256', $ip . '|' . __FILE__), 0, 20) . '.json';
    $now = time();
    $list = [];
    if (is_file($file)) $list = array_values(array_filter((array)json_decode((string)@file_get_contents($file), true), function ($t) use ($now, $window) { return is_int($t) && $t > $now - $window; }));
    if (count($list) >= $max) return false;
    if ($record) { $list[] = $now; @file_put_contents($file, json_encode($list), LOCK_EX); }
    return true;
}

function aw_hc_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<style>
.aw-hc { margin: 10px 0 14px; }
.aw-hc-trap { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }
.aw-hc-box { display: flex; align-items: center; justify-content: space-between; gap: 12px; max-width: 340px; padding: 12px 14px; border: 1px solid #d9c6c6; border-radius: 12px; background: #fbf7f7; box-shadow: 0 1px 3px rgba(61,46,46,.08); }
.aw-hc-check { display: inline-flex; align-items: center; gap: 12px; min-height: 44px; background: none; border: 0; padding: 0; cursor: pointer; font: inherit; font-size: 1rem; color: #3d2e2e; }
.aw-hc-tick { width: 28px; height: 28px; border: 2px solid #8a7676; border-radius: 6px; background: #fff; display: grid; place-items: center; transition: border-color .2s, background .2s; flex: none; }
.aw-hc-check:focus-visible { outline: 3px solid #9c5457; outline-offset: 4px; border-radius: 6px; }
.aw-hc-check[aria-busy="true"] .aw-hc-tick { border-color: #9c5457; border-top-color: transparent; border-radius: 50%; animation: awHcSpin .8s linear infinite; }
.aw-hc-check[aria-checked="true"] .aw-hc-tick { border-color: #2f7a4a; background: #2f7a4a; }
.aw-hc-check[aria-checked="true"] .aw-hc-tick::after { content: ""; width: 8px; height: 14px; border: solid #fff; border-width: 0 3px 3px 0; transform: rotate(45deg) translate(-1px, -2px); }
.aw-hc-brand { display: flex; flex-direction: column; align-items: center; color: #9c5457; font-size: 1.2rem; line-height: 1.1; }
.aw-hc-brand small { font-size: .62rem; color: #6b5757; }
.aw-hc-note { margin: 6px 0 0; font-size: .88rem; color: #6b5757; min-height: 1em; }
.aw-hc-note.is-error { color: #a8432f; }
.aw-hc-puzzle { margin-top: 10px; max-width: 360px; border: 1px solid #d9c6c6; border-radius: 14px; background: #fff; padding: 12px; box-shadow: 0 10px 30px rgba(61,46,46,.15); }
.aw-hc-title { margin: 0 0 10px; padding: 12px 14px; background: #9c5457; color: #fff; border-radius: 10px; font-weight: 600; }
.aw-hc-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.aw-hc-tile { position: relative; padding: 0; border: 3px solid transparent; border-radius: 8px; background: #eee; cursor: pointer; aspect-ratio: 1; overflow: hidden; }
.aw-hc-tile img { width: 100%; height: 100%; display: block; transition: transform .15s; }
.aw-hc-tile[aria-pressed="true"] { border-color: #2f6fb0; }
.aw-hc-tile[aria-pressed="true"] img { transform: scale(.86); }
.aw-hc-tile[aria-pressed="true"]::after { content: "✓"; position: absolute; top: 4px; left: 4px; width: 22px; height: 22px; border-radius: 50%; background: #2f6fb0; color: #fff; font-size: 14px; line-height: 22px; text-align: center; }
.aw-hc-tile:focus-visible { outline: 3px solid #3d2e2e; outline-offset: 1px; }
.aw-hc-acts { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.aw-hc-acts button { min-height: 40px; border-radius: 999px; font: inherit; cursor: pointer; }
.aw-hc-new { width: 40px; border: 1px solid #d9c6c6; background: #fff; color: #6b5757; }
.aw-hc-words-btn { border: 0; background: none; color: #9c5457; text-decoration: underline; font-size: .85rem !important; padding: 0 6px; }
.aw-hc-verify { margin-left: auto; padding: 0 20px; border: 0; background: #9c5457; color: #fff; font-weight: 600; }
.js-hc .aw-hc-q { display: none; }
.js-hc .aw-hc-q.is-shown { display: block; }
html[data-theme="dark"] .aw-hc-box, html[data-theme="dark"] .aw-hc-puzzle { background: #2a2122; border-color: #4a3739; }
html[data-theme="dark"] .aw-hc-check { color: #f3e6e6; }
html[data-theme="dark"] .aw-hc-tick { background: #1f1718; border-color: #b9a3a3; }
html[data-theme="dark"] .aw-hc-note, html[data-theme="dark"] .aw-hc-brand small { color: #d8c3c3; }
html[data-theme="dark"] .aw-hc-brand, html[data-theme="dark"] .aw-hc-words-btn { color: #f0b9ba; }
html[data-theme="dark"] .aw-hc-new { background: #1f1718; border-color: #4a3739; color: #e6d3d3; }
@keyframes awHcSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .aw-hc-check[aria-busy="true"] .aw-hc-tick { animation: none; } }
</style>
<script>
(function () {
  document.documentElement.classList.add('js-hc');
  function post(url, data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { [].concat(data[k]).forEach(function (v) { fd.append(k, v); }); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
  }
  function setup(box) {
    var id = box.getAttribute('data-id'), ep = box.getAttribute('data-endpoint');
    var wrap = box.querySelector('.aw-hc-box'), check = box.querySelector('.aw-hc-check'), note = box.querySelector('.aw-hc-note');
    var puz = box.querySelector('.aw-hc-puzzle'), grid = box.querySelector('.aw-hc-grid'), title = box.querySelector('.aw-hc-title');
    var words = box.querySelector('[data-aw-hc-words]'), wordsInput = words.querySelector('input');
    var form = box.closest('form'), passed = false;
    wrap.hidden = false;
    function say(t, bad) { note.textContent = t || ''; note.classList.toggle('is-error', !!bad); }
    function showWords() { puz.hidden = true; words.classList.add('is-shown'); wordsInput.required = true; wordsInput.focus(); say('Answer the question below instead.'); }
    function done() { passed = true; check.setAttribute('aria-checked', 'true'); check.removeAttribute('aria-busy'); check.disabled = true; puz.hidden = true; say('Thank you. You are verified.'); }
    function load(keep) {
      check.setAttribute('aria-busy', 'true'); if (keep !== true) say('');
      post(ep, { action: 'puzzle', id: id }).then(function (r) {
        check.removeAttribute('aria-busy');
        if (r.passed) { done(); return; }
        if (!r.ok) { if (r.words) showWords(); say(r.msg, !r.words); return; }
        title.textContent = r.title; grid.innerHTML = '';
        grid.setAttribute('aria-label', r.title);
        r.tiles.forEach(function (t, i) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'aw-hc-tile'; b.setAttribute('aria-pressed', 'false'); b.dataset.t = t;
          var img = document.createElement('img'); img.alt = 'Picture ' + (i + 1); img.src = ep + '?tile=' + encodeURIComponent(t) + '&id=' + encodeURIComponent(id); img.draggable = false;
          b.appendChild(img);
          b.addEventListener('click', function () { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); });
          grid.appendChild(b);
        });
        puz.hidden = false; grid.querySelector('button').focus();
      }).catch(function () { check.removeAttribute('aria-busy'); showWords(); });
    }
    check.addEventListener('click', function () { if (!passed) load(); });
    box.querySelector('.aw-hc-new').addEventListener('click', function () { load(); });
    box.querySelector('.aw-hc-words-btn').addEventListener('click', showWords);
    box.querySelector('.aw-hc-verify').addEventListener('click', function () {
      var picked = [].map.call(grid.querySelectorAll('[aria-pressed="true"]'), function (b) { return b.dataset.t; });
      if (!picked.length) { say('Select the pictures first.', true); return; }
      post(ep, { action: 'answer', id: id, 'picked[]': picked }).then(function (r) {
        if (r.ok) { done(); check.focus(); return; }
        say(r.msg, true); if (r.reload) { puz.hidden = true; return; } load(true);
      }).catch(function () { showWords(); });
    });
    if (form) form.addEventListener('submit', function (e) {
      if (passed || (words.classList.contains('is-shown') && wordsInput.value.trim() !== '')) return;
      e.preventDefault(); say('Please tick “I’m not a robot” first.', true); check.focus();
    });
  }
  function init() { document.querySelectorAll('[data-aw-hc]').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
HTML;
}
