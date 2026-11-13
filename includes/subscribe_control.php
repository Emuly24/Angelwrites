<?php
/**
 * J1: one "Subscribe" control (PROGRAM_PLAN.md Phase 1, wish J).
 *
 * The state is read from the database every time, never from a cookie:
 *   - a member's state comes from the newsletter row for their account email;
 *   - 'active'   -> the control is not shown at all;
 *   - 'pending'  -> "check your inbox to confirm", with a button to send the email again;
 *   - 'none'     -> members subscribe with one click (their account email); guests type an email.
 * It submits to newsletter.php. With JavaScript the answer appears in place (newsletter.php
 * answers JSON when asked); without it the form posts and returns to the same page.
 * Double opt-in is unchanged (includes/newsletter_optin.php).
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

/** 'active', 'pending' or 'none' for an email address. */
function aw_subscription_state(PDO $db, $email) {
    $email = trim((string)$email);
    if ($email === '') return 'none';
    try {
        $st = $db->prepare("SELECT is_active, unsubscribed_at, updated_at FROM newsletter WHERE email = ? COLLATE NOCASE");
        $st->execute([$email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'none';
        if ((int)$row['is_active'] === 1) return 'active';
        // never confirmed, or asked again after unsubscribing (the opt-in stamps updated_at)
        if (empty($row['unsubscribed_at'])) return 'pending';
        return (!empty($row['updated_at']) && strtotime($row['updated_at']) > strtotime($row['unsubscribed_at'])) ? 'pending' : 'none';
    } catch (Throwable $e) {
        error_log('subscribe state: ' . $e->getMessage());
        return 'none';
    }
}

/** The signed-in member's account email, or ''. */
function aw_member_email(PDO $db) {
    if (!function_exists('isLoggedIn') || !isLoggedIn()) return '';
    try {
        $st = $db->prepare("SELECT email FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        return trim((string)$st->fetchColumn());
    } catch (Throwable $e) {
        error_log('subscribe member email: ' . $e->getMessage());
        return '';
    }
}

/**
 * Render the control. $variant: 'footer' (compact, on dark), 'section' (home page band) or
 * 'sidebar' (inside a card on a light page).
 * $message / $error: a result from a no-JavaScript submission to show once.
 */
function aw_subscribe_control(PDO $db, $variant = 'footer', $message = '', $error = '') {
    $member = aw_member_email($db);
    $state = $member !== '' ? aw_subscription_state($db, $member) : 'none';
    if ($state === 'active' && $message === '' && $error === '') return ''; // subscribed: nothing to ask

    $back = strtok((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), '#');
    if ($back === '' || $back[0] !== '/' || strpos($back, '//') === 0) $back = '/index.php';
    $anchor = $variant === 'section' ? 'subscribe' : ($variant === 'footer' ? 'footer-newsletter' : 'subscribe-' . preg_replace('/[^a-z]/', '', (string)$variant));
    $uid = 'awsub-' . $variant;

    $h = '<div class="aw-sub aw-sub-' . htmlspecialchars($variant) . '" id="' . $anchor . '" data-aw-sub>';
    if ($variant === 'section') {
        $h .= '<h2>Stay <span class="rose-text">Inspired</span></h2>';
        $h .= '<p class="aw-sub-lead">Angella\'s new writing, book news and free resources, in your inbox. No spam; leave any time.</p>';
    } else {
        $h .= '<p class="aw-sub-lead">Get new writing straight to your inbox.</p>';
    }

    $h .= '<div class="aw-sub-body">';
    if ($state === 'active') {
        // only reached right after a no-JS submission: say so once
    } elseif ($state === 'pending') {
        $h .= '<p class="aw-sub-note">We sent a confirmation link to <strong>' . htmlspecialchars($member) . '</strong>. Please open it to start receiving the newsletter.</p>';
        $h .= '<form action="' . SITE_URL . '/newsletter.php" method="POST" class="aw-sub-form" data-aw-sub-form>'
            . '<input type="hidden" name="use_account" value="1"><input type="hidden" name="redirect" value="' . htmlspecialchars($back . '#' . $anchor) . '">'
            . '<button type="submit" class="btn btn-sm btn-outline">Send the link again</button></form>';
    } elseif ($member !== '') {
        $h .= '<form action="' . SITE_URL . '/newsletter.php" method="POST" class="aw-sub-form" data-aw-sub-form>'
            . '<input type="hidden" name="use_account" value="1"><input type="hidden" name="redirect" value="' . htmlspecialchars($back . '#' . $anchor) . '">'
            . '<button type="submit" class="btn btn-primary btn-sm">Subscribe with ' . htmlspecialchars($member) . '</button></form>';
    } else {
        $h .= '<form action="' . SITE_URL . '/newsletter.php" method="POST" class="aw-sub-form aw-sub-guest" data-aw-sub-form>'
            . '<label class="sr-only" for="' . $uid . '-name">Your name (optional)</label>'
            . '<input type="text" id="' . $uid . '-name" name="name" placeholder="Your name (optional)" autocomplete="name" maxlength="100">'
            . '<label class="sr-only" for="' . $uid . '-email">Your email</label>'
            . '<input type="email" id="' . $uid . '-email" name="email" placeholder="Your email" autocomplete="email" required>'
            . '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="aw-sub-hp" aria-hidden="true">'
            . '<input type="hidden" name="redirect" value="' . htmlspecialchars($back . '#' . $anchor) . '">'
            . '<button type="submit" class="btn btn-primary btn-sm">Subscribe</button></form>';
    }
    $h .= '<p class="aw-sub-msg" role="status" aria-live="polite">' . ($message !== '' ? htmlspecialchars($message) : '') . '</p>';
    $h .= '<p class="aw-sub-err" role="alert">' . ($error !== '' ? htmlspecialchars($error) : '') . '</p>';
    $h .= '</div></div>';
    return $h;
}

/** CSS and the small inline-submit script, printed once per page. */
function aw_subscribe_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<style>
.aw-sub-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.aw-sub-guest input[type=email], .aw-sub-guest input[type=text] { flex:1 1 180px; min-height:44px; padding:10px 14px; border-radius:12px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); font:inherit; }
.aw-sub .btn { min-height:44px; }
.aw-sub-hp { position:absolute !important; left:-9999px !important; width:1px; height:1px; opacity:0; }
.aw-sub-msg:empty, .aw-sub-err:empty { display:none; }
.aw-sub-msg, .aw-sub-err { margin:10px 0 0; font-weight:600; }
.aw-sub-err { color:#b4505a; }
.aw-sub-note { margin:0 0 10px; }
.aw-sub-section { max-width:620px; margin:0 auto; text-align:center; }
.aw-sub-section .aw-sub-form { justify-content:center; }
.aw-sub-section .aw-sub-lead { color:var(--text-light); margin:0 0 16px; }
.aw-sub-footer .aw-sub-lead { margin:0 0 10px; }
.aw-sub-footer .aw-sub-guest input { background:rgba(255,255,255,.08); color:#fff; border-color:rgba(255,255,255,.25); }
.aw-sub-footer .aw-sub-guest input::placeholder { color:rgba(255,255,255,.7); }
.aw-sub-footer .aw-sub-err { color:#f3c0c6; }
</style>
<script>
if (!window.awSubBound) { window.awSubBound = true; // printed at most once per request, but a cached body can carry a copy
document.addEventListener('submit', function (e) {
    var form = e.target.closest ? e.target.closest('[data-aw-sub-form]') : null;
    if (!form || !window.fetch || !window.FormData) return;
    e.preventDefault();
    var box = form.closest('[data-aw-sub]');
    var msg = box.querySelector('.aw-sub-msg'), err = box.querySelector('.aw-sub-err');
    var btn = form.querySelector('button[type=submit]');
    msg.textContent = ''; err.textContent = '';
    if (btn) btn.disabled = true;
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok) {
                msg.textContent = d.message || 'Please check your inbox to confirm.';
                if (d.state !== 'none') form.remove(); // the answer replaces the form
            } else {
                err.textContent = (d && d.message) || 'That did not work. Please try again.';
                if (btn) btn.disabled = false;
            }
        })
        .catch(function () { err.textContent = 'That did not work. Please try again.'; if (btn) btn.disabled = false; });
});
}
</script>
HTML;
}
