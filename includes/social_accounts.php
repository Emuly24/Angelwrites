<?php
/**
 * AngelWrites' own social media accounts (the "Connect" icons in the footer).
 *
 * Until an account exists, its icon opens an honest note: "AngelWrites on Facebook is coming soon",
 * with the ways to reach us today (a message in the app, or angelwrites@zohomail.com). Once the team
 * creates the account, an admin adds it in Admin > Social accounts (admin/social_accounts.php): the
 * public link and handle, whether it is live, the team members who look after it, and optionally its
 * sign-in details.
 *
 * Sign-in details are encrypted (libsodium) with a key kept outside the database: SOCIAL_VAULT_KEY in
 * .env when set, otherwise a key generated once in data/ (never served over the web, never deployed).
 * Only the owner, admins and that platform's overseers can reveal them; every reveal is logged.
 * A password manager is still the safest home for them.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_SOCIAL_PLATFORMS = [
    'facebook'  => ['Facebook',  'fab fa-facebook-f'],
    'instagram' => ['Instagram', 'fab fa-instagram'],
    'tiktok'    => ['TikTok',    'fab fa-tiktok'],
    'youtube'   => ['YouTube',   'fab fa-youtube'],
    'x'         => ['X (Twitter)', 'fab fa-x-twitter'],
    'whatsapp'  => ['WhatsApp channel', 'fab fa-whatsapp'],
    'telegram'  => ['Telegram',  'fab fa-telegram'],
    'pinterest' => ['Pinterest', 'fab fa-pinterest-p'],
    'linkedin'  => ['LinkedIn',  'fab fa-linkedin-in'],
    'threads'   => ['Threads',   'fab fa-threads'],
];
const AW_SOCIAL_FOOTER_DEFAULT = ['facebook', 'instagram', 'tiktok', 'youtube', 'whatsapp'];
const AW_CONTACT_EMAIL = 'angelwrites@zohomail.com';

function aw_social_accounts_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS social_accounts (
            platform TEXT PRIMARY KEY,
            url TEXT,
            handle TEXT,
            is_live INTEGER NOT NULL DEFAULT 0,
            show_in_footer INTEGER NOT NULL DEFAULT 0,
            overseers TEXT,
            login_name TEXT,
            secret_enc TEXT,
            notes TEXT,
            updated_by INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('social accounts table: ' . $e->getMessage());
        return $ok = false;
    }
}

function aw_social_accounts(PDO $db) {
    $out = [];
    foreach (AW_SOCIAL_PLATFORMS as $k => $p) {
        $out[$k] = ['platform' => $k, 'label' => $p[0], 'icon' => $p[1], 'url' => '', 'handle' => '', 'is_live' => 0,
                    'show_in_footer' => in_array($k, AW_SOCIAL_FOOTER_DEFAULT, true) ? 1 : 0, 'overseers' => [], 'login_name' => '', 'has_secret' => false, 'notes' => '', 'updated_at' => null, 'saved' => false];
    }
    if (!aw_social_accounts_table($db)) return $out;
    try {
        foreach ($db->query("SELECT * FROM social_accounts") as $r) {
            if (!isset($out[$r['platform']])) continue;
            $o = &$out[$r['platform']];
            $o['url'] = (string)$r['url']; $o['handle'] = (string)$r['handle']; $o['is_live'] = (int)$r['is_live'];
            $o['show_in_footer'] = (int)$r['show_in_footer']; $o['overseers'] = array_map('intval', (array)json_decode((string)$r['overseers'], true));
            $o['login_name'] = (string)$r['login_name']; $o['has_secret'] = trim((string)$r['secret_enc']) !== ''; $o['notes'] = (string)$r['notes'];
            $o['updated_at'] = $r['updated_at']; $o['saved'] = true;
            unset($o);
        }
    } catch (Throwable $e) {
        error_log('social accounts read: ' . $e->getMessage());
    }
    return $out;
}

/** The footer icons: live accounts link out; the others open the honest "coming soon" note. */
function aw_social_footer_icons(PDO $db = null) {
    $list = $db instanceof PDO ? aw_social_accounts($db) : [];
    if (!$list) foreach (AW_SOCIAL_FOOTER_DEFAULT as $k) $list[$k] = ['label' => AW_SOCIAL_PLATFORMS[$k][0], 'icon' => AW_SOCIAL_PLATFORMS[$k][1], 'is_live' => 0, 'url' => '', 'show_in_footer' => 1];
    $h = '';
    foreach ($list as $k => $a) {
        if (empty($a['show_in_footer']) && empty($a['is_live'])) continue;
        if (!empty($a['is_live']) && preg_match('#^https://#i', (string)$a['url'])) {
            $h .= '<a href="' . htmlspecialchars($a['url']) . '" target="_blank" rel="noopener me" aria-label="AngelWrites on ' . htmlspecialchars($a['label']) . ' (opens in a new tab)"><i class="' . $a['icon'] . '" aria-hidden="true"></i></a>';
        } else {
            $h .= '<button type="button" class="aw-soon" data-aw-soon="' . htmlspecialchars($a['label']) . '" aria-haspopup="dialog" aria-label="AngelWrites on ' . htmlspecialchars($a['label']) . ' (coming soon)"><i class="' . $a['icon'] . '" aria-hidden="true"></i></button>';
        }
    }
    return $h; // the "coming soon" note is printed after the footer (aw_social_soon_dialog), never inside the icon row
}

/** The "coming soon" note (printed once per page). */
function aw_social_soon_dialog() {
    static $done = false;
    if ($done) return '';
    $done = true;
    $msg = htmlspecialchars(SITE_URL . '/messages.php?new=1');
    $mail = htmlspecialchars(AW_CONTACT_EMAIL);
    return <<<HTML
<dialog id="awSoon" class="aw-soon-dialog" aria-labelledby="awSoonTitle">
  <form method="dialog" class="aw-soon-close-f"><button class="aw-soon-x" aria-label="Close">&times;</button></form>
  <div class="aw-soon-ico" aria-hidden="true"><i class="fas fa-seedling"></i></div>
  <h2 id="awSoonTitle">AngelWrites on <span data-aw-soon-name>this platform</span> is coming soon</h2>
  <p>We are still setting it up, so there is nothing to follow there yet. Thank you for wanting to connect!</p>
  <p>In the meantime, we would love to hear from you:</p>
  <div class="aw-soon-actions">
    <a class="aw-soon-btn aw-soon-primary" href="{$msg}"><i class="fas fa-comment-dots" aria-hidden="true"></i> Send us a message in the app</a>
    <a class="aw-soon-btn" href="mailto:{$mail}" aria-label="Email us ({$mail})"><i class="fas fa-envelope" aria-hidden="true"></i> Email us</a>
  </div>
</dialog>
<style>
.aw-soon { background: none; border: 0; padding: 0; font: inherit; color: inherit; cursor: pointer; }
.aw-soon-dialog { --sd-bg: #ffffff; --sd-ink: #2c1e1e; --sd-text: #4a3a3a; --sd-line: #e5d5d5; --sd-rose: #9c5457;
    position: fixed; inset: 0; margin: auto; height: fit-content; max-height: calc(100vh - 32px); overflow: auto;
    border: 0; border-radius: 22px; padding: 28px 26px 24px; width: min(440px, calc(100vw - 32px)); max-width: none; background: var(--sd-bg); color: var(--sd-text); box-shadow: 0 24px 60px rgba(30, 18, 18, .3); text-align: center; }
html[data-theme="dark"] .aw-soon-dialog { --sd-bg: #2a1f22; --sd-ink: #f6ecec; --sd-text: #e6d6d6; --sd-line: rgba(240, 196, 197, .25); --sd-rose: #f0c4c5; }
.aw-soon-dialog * { color: inherit; }
.aw-soon-dialog h2 span { color: var(--sd-rose); }
.aw-soon-dialog::backdrop { background: rgba(30, 18, 18, .45); backdrop-filter: blur(3px); }
.aw-soon-dialog h2 { font-family: 'Playfair Display', Georgia, serif; font-size: 1.35rem; margin: 6px 0 10px; color: var(--sd-ink); }
.aw-soon-dialog p { margin: 0 0 10px; line-height: 1.6; color: var(--sd-text); }
.aw-soon-ico { width: 56px; height: 56px; margin: 0 auto; border-radius: 18px; display: grid; place-items: center; font-size: 1.4rem; color: #fff; background: linear-gradient(135deg, #9c5457, #c48f91); }
.aw-soon-actions { display: grid; gap: 10px; margin-top: 16px; }
.aw-soon-dialog .aw-soon-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: auto; min-height: 46px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--sd-rose); background: transparent; color: var(--sd-rose); text-decoration: none; font: 600 .95rem Inter, system-ui, sans-serif; }
.aw-soon-dialog .aw-soon-btn i { color: inherit; }
.aw-soon-dialog .aw-soon-primary { background: #9c5457; border-color: #9c5457; color: #fff; }
.aw-soon-primary:hover { color: #fff; filter: brightness(.94); }
.aw-soon-close-f { position: absolute; top: 8px; right: 10px; margin: 0; }
.aw-soon-dialog .aw-soon-x { width: 44px; height: 44px; border: 0; background: none; font-size: 1.6rem; color: var(--sd-text); cursor: pointer; border-radius: 50%; display: grid; place-items: center; }
.aw-soon-x:focus-visible, .aw-soon-btn:focus-visible { outline: 3px solid #9c5457; outline-offset: 2px; }
html[data-theme="dark"] .aw-soon-dialog h2 { color: #f6ecec; }
html[data-theme="dark"] .aw-soon-dialog .aw-soon-primary { background: #f0c4c5; border-color: #f0c4c5; color: #2c1e1e; }
</style>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-aw-soon]'); if (!b) return;
  var d = document.getElementById('awSoon'); if (!d) return;
  d.querySelector('[data-aw-soon-name]').textContent = b.getAttribute('data-aw-soon');
  if (d.showModal) d.showModal(); else d.setAttribute('open', '');
  d._opener = b;
});
document.addEventListener('close', function (e) { if (e.target.id === 'awSoon' && e.target._opener) e.target._opener.focus(); }, true);
</script>
HTML;
}

/* ---------- sign-in details (encrypted) ---------- */

function aw_social_vault_key() {
    $env = trim((string)getenv('SOCIAL_VAULT_KEY'));
    if ($env !== '') return sodium_crypto_generichash($env, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $file = dirname(__DIR__) . '/data/social_vault.key';
    if (!is_file($file)) {
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), LOCK_EX);
        @chmod($file, 0600);
    }
    $k = base64_decode(trim((string)@file_get_contents($file)), true);
    return ($k !== false && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $k : null;
}

function aw_social_encrypt($plain) {
    if (!function_exists('sodium_crypto_secretbox') || ($k = aw_social_vault_key()) === null) return null;
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n . sodium_crypto_secretbox((string)$plain, $n, $k));
}

function aw_social_decrypt($enc) {
    if (!function_exists('sodium_crypto_secretbox_open') || ($k = aw_social_vault_key()) === null) return null;
    $raw = base64_decode((string)$enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
    return $p === false ? null : $p;
}

/** May the signed-in person manage everything (owner/admin), or look after this platform? */
function aw_social_can_manage() { return function_exists('aw_can') && aw_can('manage_settings'); }
function aw_social_can_see(array $a) {
    if (aw_social_can_manage()) return true;
    return in_array((int)($_SESSION['user_id'] ?? 0), (array)$a['overseers'], true);
}
