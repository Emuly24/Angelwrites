<?php
/**
 * K5: two-step sign-in for staff (owner answer 12). Time-based codes (RFC 6238, 6 digits, 30 s),
 * as used by Google Authenticator, Microsoft Authenticator, Authy and others. No library needed.
 *
 *  - Staff (owner, admins, moderators) can turn it on in Account security (account_security.php).
 *  - With it on, signing in asks for the 6-digit code after the password; "remember me" never skips it.
 *  - Ten one-time recovery codes are shown once, stored only as hashes.
 *  - A code can be used once (replay protection), with one step of clock drift either way.
 *  - The owner can require it for all staff (settings.staff_2fa_required = '1'); staff without it are
 *    then sent to set it up before any staff page opens.
 * Nothing throws.
 */

function aw_2fa_table(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS staff_2fa (user_id INTEGER PRIMARY KEY, secret TEXT NOT NULL, enabled_at DATETIME,
                   last_step INTEGER DEFAULT 0, recovery TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $ok = true;
    } catch (Throwable $e) { error_log('2fa table: ' . $e->getMessage()); return $ok = false; }
}

function aw_b32_encode($bin) {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function aw_b32_decode($s) {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)$s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** The 6-digit code for a secret at a 30-second step. */
function aw_totp($secret, $step) {
    $key = aw_b32_decode($secret);
    $h = hash_hmac('sha1', pack('N*', 0) . pack('N*', (int)$step), $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The step a code matches (window ±1), or 0. */
function aw_totp_match($secret, $code, $now = null) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return 0;
    $t = (int)floor(($now ?? time()) / 30);
    foreach ([0, -1, 1] as $d) if (hash_equals(aw_totp($secret, $t + $d), $code)) return $t + $d;
    return 0;
}

function aw_2fa_row(PDO $db, $uid) {
    if (!aw_2fa_table($db)) return null;
    try { $st = $db->prepare("SELECT * FROM staff_2fa WHERE user_id = ?"); $st->execute([(int)$uid]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { error_log('2fa row: ' . $e->getMessage()); return null; }
}
function aw_2fa_enabled(PDO $db, $uid) { $r = aw_2fa_row($db, $uid); return $r && !empty($r['enabled_at']); }

/** Start setup: a fresh secret (not yet enabled). Returns the secret. */
function aw_2fa_begin(PDO $db, $uid) {
    if (!aw_2fa_table($db)) return '';
    $secret = aw_b32_encode(random_bytes(20));
    try {
        $db->prepare("INSERT INTO staff_2fa (user_id, secret, enabled_at, last_step, recovery) VALUES (?, ?, NULL, 0, NULL)
                      ON CONFLICT(user_id) DO UPDATE SET secret = excluded.secret, enabled_at = NULL, last_step = 0, recovery = NULL")->execute([(int)$uid, $secret]);
        return $secret;
    } catch (Throwable $e) { error_log('2fa begin: ' . $e->getMessage()); return ''; }
}

/** Confirm setup with a code; returns the plain recovery codes (shown once) or [] on failure. */
function aw_2fa_confirm(PDO $db, $uid, $code) {
    $r = aw_2fa_row($db, $uid);
    if (!$r || !empty($r['enabled_at'])) return [];
    $step = aw_totp_match($r['secret'], $code);
    if (!$step) return [];
    $plain = []; $hashes = [];
    for ($i = 0; $i < 10; $i++) { $c = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))); $plain[] = $c; $hashes[] = password_hash($c, PASSWORD_DEFAULT); }
    try {
        $db->prepare("UPDATE staff_2fa SET enabled_at = CURRENT_TIMESTAMP, last_step = ?, recovery = ? WHERE user_id = ?")->execute([$step, json_encode($hashes), (int)$uid]);
        if (function_exists('aw_audit')) aw_audit('2fa_enabled', 'user', (int)$uid, '');
        return $plain;
    } catch (Throwable $e) { error_log('2fa confirm: ' . $e->getMessage()); return []; }
}

/** Check a sign-in code or recovery code. Each works once. */
function aw_2fa_verify(PDO $db, $uid, $input) {
    $r = aw_2fa_row($db, $uid);
    if (!$r || empty($r['enabled_at'])) return false;
    $input = trim((string)$input);
    try {
        $step = aw_totp_match($r['secret'], $input);
        if ($step) {
            if ($step <= (int)$r['last_step']) return false; // already used
            $db->prepare("UPDATE staff_2fa SET last_step = ? WHERE user_id = ?")->execute([$step, (int)$uid]);
            return true;
        }
        $codes = json_decode((string)$r['recovery'], true) ?: [];
        foreach ($codes as $i => $h) {
            if (password_verify(strtoupper($input), $h)) {
                unset($codes[$i]);
                $db->prepare("UPDATE staff_2fa SET recovery = ? WHERE user_id = ?")->execute([json_encode(array_values($codes)), (int)$uid]);
                if (function_exists('aw_audit')) aw_audit('2fa_recovery_used', 'user', (int)$uid, count($codes) . ' left');
                return true;
            }
        }
    } catch (Throwable $e) { error_log('2fa verify: ' . $e->getMessage()); }
    return false;
}

function aw_2fa_disable(PDO $db, $uid) {
    if (!aw_2fa_table($db)) return false;
    try { $db->prepare("DELETE FROM staff_2fa WHERE user_id = ?")->execute([(int)$uid]); if (function_exists('aw_audit')) aw_audit('2fa_disabled', 'user', (int)$uid, ''); return true; }
    catch (Throwable $e) { error_log('2fa disable: ' . $e->getMessage()); return false; }
}

function aw_is_staff_role($role) { return in_array((string)$role, ['admin', 'moderator'], true); }

/** Finish a sign-in: the session, "remember me", last login. Shared by login.php and login_2fa.php. */
function aw_finish_login(PDO $db, array $user, $remember) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name'] = awDisplayName($user);
    $_SESSION['role'] = $user['role'];
    unset($_SESSION['pending_2fa']);
    if ($remember) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE,
                       expires_at DATETIME NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
            $token = bin2hex(random_bytes(32));
            $expires = time() + 30 * 24 * 60 * 60;
            $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")->execute([$user['id'], $token, date('Y-m-d H:i:s', $expires)]);
            setcookie('remember_token', $token, ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        } catch (Throwable $e) { error_log('remember me: ' . $e->getMessage()); }
    }
    try { $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user['id']]); } catch (Throwable $e) {}
}

/** Staff pages: when the owner requires two-step sign-in, staff without it go to set it up first. */
function aw_2fa_enforce() {
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO || empty($_SESSION['user_id'])) return;
    if (!function_exists('aw_setting') || (string)aw_setting('staff_2fa_required', '0') !== '1') return;
    if (!aw_is_staff_role($_SESSION['role'] ?? '')) return;
    if (aw_2fa_enabled($db, (int)$_SESSION['user_id'])) return;
    header('Location: ' . SITE_URL . '/account_security.php?required=1');
    exit;
}
