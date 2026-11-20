<?php
/**
 * Built-in human check (no outside service, no keys, nothing sent by email).
 *
 * Used on sign-up (instead of an email code), the contact form and "forgot password". It layers:
 *   1. proof of work: the browser quietly solves a small puzzle (SHA-256 with leading zeros) while
 *      the person types; real visitors never notice, but sending thousands of forms becomes slow
 *      and costly for a bot;
 *   2. a hidden trap field that people never see or fill in (bots fill every field);
 *   3. timing: a form sent less than 3 seconds after it was shown, or after 2 hours, is refused;
 *   4. without JavaScript, a short question written in words ("What is seven plus three?");
 *   5. limits per network address (for example 5 sign-ups an hour), via aw_hc_limit().
 * Every challenge is used once. Nothing throws; failures return a message for the person.
 */

const AW_HC_BITS = 16;          // leading zero bits for the proof of work (~65k hashes: well under a second)
const AW_HC_MIN_SECONDS = 3;
const AW_HC_MAX_SECONDS = 7200;

function aw_hc_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (!isset($_SESSION['aw_hc']) || !is_array($_SESSION['aw_hc'])) $_SESSION['aw_hc'] = [];
    // keep the newest 12 challenges (several tabs may be open)
    if (count($_SESSION['aw_hc']) > 12) $_SESSION['aw_hc'] = array_slice($_SESSION['aw_hc'], -12, null, true);
}

/** The HTML to put inside a form (hidden fields, the trap, the no-JavaScript question, the script). */
function aw_hc_field($form) {
    aw_hc_session();
    $id = bin2hex(random_bytes(8));
    $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
    $a = random_int(2, 9); $b = random_int(1, 9);
    $_SESSION['aw_hc'][$id] = ['form' => (string)$form, 'at' => time(), 'salt' => bin2hex(random_bytes(12)), 'sum' => $a + $b];
    $salt = $_SESSION['aw_hc'][$id]['salt'];
    $trap = 'aw_hp_' . substr(hash('sha256', $id . $salt), 0, 6);
    $_SESSION['aw_hc'][$id]['trap'] = $trap;
    $h = '<div class="aw-hc" data-aw-hc data-salt="' . htmlspecialchars($salt) . '" data-bits="' . AW_HC_BITS . '">'
       . '<input type="hidden" name="aw_hc_id" value="' . htmlspecialchars($id) . '">'
       . '<input type="hidden" name="aw_hc_nonce" value="">'
       // the trap: hidden from people and screen readers, left empty by them
       . '<div class="aw-hc-trap" aria-hidden="true"><label>Leave this empty <input type="text" name="' . $trap . '" value="" tabindex="-1" autocomplete="off"></label></div>'
       . '<noscript><div class="form-group aw-hc-q"><label for="aw_hc_answer_' . $id . '">Quick check: what is ' . $words[$a] . ' plus ' . $words[$b] . '? (in numbers)</label>'
       . '<input type="text" inputmode="numeric" id="aw_hc_answer_' . $id . '" name="aw_hc_answer" autocomplete="off" required></div></noscript>'
       . '<p class="aw-hc-status" role="status" aria-live="polite"><span class="aw-hc-dot" aria-hidden="true"></span><span class="aw-hc-text">Checking you are human…</span></p>'
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
    $nonce = (string)($post['aw_hc_nonce'] ?? '');
    if ($nonce !== '') {
        if (!preg_match('/^\d{1,10}$/', $nonce) || !aw_hc_pow_ok($c['salt'], $nonce, AW_HC_BITS)) return 'The human check did not finish.' . $again;
        return '';
    }
    $ans = trim((string)($post['aw_hc_answer'] ?? ''));
    if ($ans !== '' && ctype_digit($ans) && (int)$ans === (int)$c['sum']) return '';
    return $ans === '' ? 'Please wait for the human check to finish (a second or two), then send the form again.' : 'The answer to the quick check was not right.' . $again;
}

function aw_hc_pow_ok($salt, $nonce, $bits) {
    $hash = hash('sha256', $salt . ':' . $nonce, true);
    $full = intdiv($bits, 8);
    for ($i = 0; $i < $full; $i++) if (ord($hash[$i]) !== 0) return false;
    $rest = $bits % 8;
    return $rest === 0 || (ord($hash[$full]) >> (8 - $rest)) === 0;
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
.aw-hc-trap { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }
.aw-hc-status { display: flex; align-items: center; gap: 8px; margin: 6px 0 12px; font-size: .86rem; color: var(--text-light, #6b5a5a); }
.aw-hc-dot { width: 10px; height: 10px; border-radius: 50%; background: #c48f91; animation: awHcPulse 1s ease-in-out infinite; }
.aw-hc.is-done .aw-hc-dot { background: #2f6b45; animation: none; }
.aw-hc.is-done .aw-hc-status { color: #2f6b45; }
html[data-theme="dark"] .aw-hc.is-done .aw-hc-status { color: #8fd1a5; }
html[data-theme="dark"] .aw-hc.is-done .aw-hc-dot { background: #8fd1a5; }
@keyframes awHcPulse { 50% { opacity: .35; } }
@media (prefers-reduced-motion: reduce) { .aw-hc-dot { animation: none; } }
</style>
<script>
(function () {
  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function zeros(bytes, bits) {
    var full = bits >> 3, rest = bits & 7;
    for (var i = 0; i < full; i++) if (bytes[i] !== 0) return false;
    return rest === 0 || (bytes[full] >> (8 - rest)) === 0;
  }
  function solve(box) {
    var salt = box.getAttribute('data-salt'), bits = parseInt(box.getAttribute('data-bits'), 10) || 16;
    var form = box.closest('form'), nonceEl = box.querySelector('[name="aw_hc_nonce"]'), text = box.querySelector('.aw-hc-text');
    if (!window.crypto || !crypto.subtle || !window.TextEncoder) { text.textContent = 'Please answer the quick check.'; return; }
    var enc = new TextEncoder(), n = 0, busy = true;
    function batch() {
      var tries = [];
      for (var k = 0; k < 400; k++, n++) tries.push(n);
      Promise.all(tries.map(function (x) { return crypto.subtle.digest('SHA-256', enc.encode(salt + ':' + x)).then(function (h) { return [x, new Uint8Array(h)]; }); }))
        .then(function (res) {
          for (var i = 0; i < res.length; i++) if (zeros(res[i][1], bits)) { nonceEl.value = String(res[i][0]); busy = false; box.classList.add('is-done'); text.textContent = 'Human check complete. Thank you!'; return; }
          setTimeout(batch, 0);
        });
    }
    batch();
    if (form) form.addEventListener('submit', function (e) {
      if (busy) { e.preventDefault(); text.textContent = 'One moment: finishing the human check…'; var wait = setInterval(function () { if (!busy) { clearInterval(wait); form.requestSubmit ? form.requestSubmit() : form.submit(); } }, 200); }
    });
  }
  function init() { document.querySelectorAll('[data-aw-hc]').forEach(solve); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
HTML;
}
