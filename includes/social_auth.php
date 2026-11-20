<?php
/**
 * Sign in with Google or Facebook (Hybridauth, already in vendor/). Only these two are offered.
 *
 * A provider's button appears only when its keys are in the .env file on the server:
 *   Google     GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET            (console.cloud.google.com > Credentials)
 *   Facebook   FACEBOOK_APP_ID, FACEBOOK_APP_SECRET              (developers.facebook.com > Facebook Login)
 * Common other spellings are accepted too (AW_SOCIAL_KEY_NAMES below), and the keys are read with
 * aw_env(), which finds the .env file wherever the loader found it (includes/load_env.php).
 * Both use the same return address: https://<site>/social_login.php
 *
 * Accounts: a provider account is remembered in user_social. It signs in to the same AngelWrites
 * account every time. A new provider account joins an existing AngelWrites account only when the
 * provider confirms the email is theirs (Google, Facebook, GitHub's verified email); otherwise the
 * person logs in with their password once and the two are linked. New people answer only what the
 * provider did not tell us (register.php?social=1).
 *
 * Nothing throws; failures are logged and the person gets a plain message.
 */

require_once __DIR__ . '/load_env.php';

/** The names each key may have in .env: the first is the documented one, the others are common spellings. */
const AW_SOCIAL_KEY_NAMES = [
    'Google'   => ['id' => ['GOOGLE_CLIENT_ID', 'GOOGLE_ID', 'GOOGLE_APP_ID', 'GOOGLE_OAUTH_CLIENT_ID', 'GOOGLE_OAUTH_ID'],
                   'secret' => ['GOOGLE_CLIENT_SECRET', 'GOOGLE_SECRET', 'GOOGLE_APP_SECRET', 'GOOGLE_OAUTH_CLIENT_SECRET', 'GOOGLE_OAUTH_SECRET']],
    'Facebook' => ['id' => ['FACEBOOK_APP_ID', 'FACEBOOK_CLIENT_ID', 'FACEBOOK_ID', 'FB_APP_ID', 'FB_CLIENT_ID'],
                   'secret' => ['FACEBOOK_APP_SECRET', 'FACEBOOK_CLIENT_SECRET', 'FACEBOOK_SECRET', 'FB_APP_SECRET', 'FB_CLIENT_SECRET']],
];

/** The first of these names that has a value, as [name, value]; ['', ''] when none. */
function aw_social_key(array $names) {
    foreach ($names as $n) { $v = aw_env($n); if ($v !== '') return [$n, $v]; }
    return ['', ''];
}

function aw_social_providers() {
    $all = [
        'Google'   => ['label' => 'Google',   'icon' => 'fab fa-google',     'scope' => 'openid email profile'],
        'Facebook' => ['label' => 'Facebook', 'icon' => 'fab fa-facebook-f', 'scope' => 'email, public_profile'],
    ];
    foreach ($all as $k => &$p) { $p['id'] = aw_social_key(AW_SOCIAL_KEY_NAMES[$k]['id'])[1]; $p['secret'] = aw_social_key(AW_SOCIAL_KEY_NAMES[$k]['secret'])[1]; }
    unset($p);
    return array_filter($all, function ($p) { return $p['id'] !== '' && $p['secret'] !== ''; });
}

/** The buttons, or '' when no provider is set up. $intent: 'login' or 'register'. */
function aw_social_buttons($intent = 'login') {
    $ps = aw_social_providers();
    if (!$ps) return '';
    $h = '<div class="aw-social"><p class="aw-social-or"><span>' . ($intent === 'register' ? 'Join in one tap with' : 'Or continue with') . '</span></p><div class="aw-social-grid">';
    foreach ($ps as $key => $p) {
        $h .= '<a class="aw-social-btn aw-social-' . strtolower($p['label']) . '" href="' . htmlspecialchars(SITE_URL . '/social_login.php?provider=' . rawurlencode($key)) . '" rel="nofollow">'
            . '<i class="' . $p['icon'] . '" aria-hidden="true"></i><span>' . htmlspecialchars($p['label']) . '</span></a>';
    }
    $h .= '</div></div>';
    static $css = false;
    if (!$css) {
        $css = true;
        $h .= '<style>
.aw-social { margin: 18px 0 6px; }
.aw-social-or { display: flex; align-items: center; gap: 12px; color: var(--text-light, #6b5a5a); font-size: .88rem; margin: 0 0 12px; }
.aw-social-or::before, .aw-social-or::after { content: ""; flex: 1; height: 1px; background: var(--border, #e5d5d5); }
.aw-social-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
.aw-social-btn { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 46px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--border, #e5d5d5); background: var(--card-bg, #fff); color: var(--text, #3d2e2e); font-weight: 600; text-decoration: none; transition: transform .2s, box-shadow .2s, border-color .2s; }
.aw-social-btn:hover { transform: translateY(-1px); border-color: #c48f91; box-shadow: 0 6px 18px rgba(156,84,87,.14); color: var(--text, #3d2e2e); }
.aw-social-btn i { font-size: 1.05rem; }
.aw-social-google i { color: #ea4335; } .aw-social-facebook i { color: #1877f2; }
@media (prefers-reduced-motion: reduce) { .aw-social-btn { transition: none; } .aw-social-btn:hover { transform: none; } }
</style>';
    }
    return $h;
}

function aw_social_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_social (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            provider TEXT NOT NULL,
            provider_uid TEXT NOT NULL,
            email TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME,
            UNIQUE(provider, provider_uid)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_social_user ON user_social(user_id)");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('social table: ' . $e->getMessage());
        return $ok = false;
    }
}

function aw_social_link(PDO $db, $user_id, $provider, $uid, $email = '') {
    aw_social_table($db);
    try {
        $db->prepare("INSERT OR IGNORE INTO user_social (user_id, provider, provider_uid, email, last_used_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)")
           ->execute([(int)$user_id, (string)$provider, (string)$uid, (string)$email]);
        return true;
    } catch (Throwable $e) {
        error_log('social link: ' . $e->getMessage());
        return false;
    }
}

/** The Hybridauth configuration for one provider (the same return address for all). */
function aw_social_config($key) {
    $ps = aw_social_providers();
    if (!isset($ps[$key])) return null;
    $p = $ps[$key];
    $cfg = ['enabled' => true, 'keys' => ['id' => $p['id'], 'secret' => $p['secret']], 'scope' => $p['scope']];
    return ['callback' => SITE_URL . '/social_login.php', 'providers' => [$key => $cfg]];
}

/** A free username from a name or email: "grace_banda", "grace_banda2"... */
function aw_social_username(PDO $db, $seed) {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$seed) ?: ''));
    $base = trim(substr($base, 0, 16), '_');
    if (strlen($base) < 3) $base = 'reader';
    $try = $base;
    for ($i = 2; $i < 500; $i++) {
        try {
            $st = $db->prepare("SELECT 1 FROM users WHERE username = ? COLLATE NOCASE");
            $st->execute([$try]);
            if (!$st->fetchColumn()) return $try;
        } catch (Throwable $e) { return $base . random_int(100, 999); }
        $try = substr($base, 0, 16) . $i;
    }
    return $base . random_int(1000, 9999);
}
