<?php
require_once __DIR__ . '/load_env.php';
// Site Configuration
define('SITE_NAME', getenv('SITE_NAME'));
define('UPLOAD_PATH', getenv('UPLOAD_PATH'));
define('BIBLE_PATH', getenv('BIBLE_PATH'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('DB_PATH', __DIR__ . '/../data/site.db');
define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'https://angelwrites.gt.tc', '/')); // F213: SITE_URL in .env overrides

// F191: admin/settings.php saves site name, admin email and logo in the settings table, but
// nothing read them. These helpers read them (once per request), with safe fallbacks.
function aw_setting($key, $default = null) {
    static $cache = null;
    // Read once the database is open; a call before db.php is loaded uses the default.
    if ($cache === null && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        $cache = [];
        try {
            foreach ($GLOBALS['db']->query("SELECT key, value FROM settings") as $row) {
                $cache[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
}

function aw_site_name() {
    return aw_setting('site_name', SITE_NAME ?: 'AngelWrites');
}

// The address the team reads: angelwrites@zohomail.com. A saved or .env address is used only when it
// is a real mailbox: addresses on angelwrites.com, angelawrites.com or angelwrites.gt.tc (old seed
// values and placeholders, F108) have no mailbox behind them, so they are ignored.
const AW_TEAM_EMAIL = 'angelwrites@zohomail.com';
function aw_email_is_placeholder($email) {
    $domain = strtolower((string)substr(strrchr((string)$email, '@') ?: '', 1));
    return in_array($domain, ['angelwrites.com', 'angelawrites.com', 'angelwrites.gt.tc', 'example.com'], true);
}
function aw_admin_email() {
    foreach ([(string)aw_setting('admin_email', ''), (string)ADMIN_EMAIL] as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !aw_email_is_placeholder($e)) return $e;
    }
    return AW_TEAM_EMAIL;
}

/**
 * Contact details are shown as buttons, never as raw numbers or addresses: a phone button opens the
 * phone's dialler, an email button opens the mail app. The number or address is still in the link
 * (and in the accessible name, so screen readers say it). Styles: .aw-cbtn in aw-design.css.
 */
function aw_call_button($number, $label = 'Call', $who = '') {
    $tel = preg_replace('/[^0-9+]/', '', (string)$number);
    if ($tel === '') return '';
    $name = trim(($who !== '' ? 'Call ' . $who : 'Call') . ' (' . trim((string)$number) . ')');
    return '<a class="aw-cbtn aw-cbtn-call" href="tel:' . htmlspecialchars($tel) . '" aria-label="' . htmlspecialchars($name) . '" title="' . htmlspecialchars($name) . '">'
        . '<i class="fas fa-phone" aria-hidden="true"></i><span>' . htmlspecialchars($label) . '</span></a>';
}
function aw_mail_button($email = '', $label = 'Email us', $subject = '') {
    $email = trim((string)$email) !== '' ? trim((string)$email) : (defined('AW_TEAM_EMAIL') ? AW_TEAM_EMAIL : 'angelwrites@zohomail.com');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
    $href = 'mailto:' . $email . ($subject !== '' ? '?subject=' . rawurlencode($subject) : '');
    $name = $label . ' (' . $email . ')';
    return '<a class="aw-cbtn aw-cbtn-mail" href="' . htmlspecialchars($href) . '" aria-label="' . htmlspecialchars($name) . '" title="' . htmlspecialchars($name) . '">'
        . '<i class="fas fa-envelope" aria-hidden="true"></i><span>' . htmlspecialchars($label) . '</span></a>';
}

function aw_logo_path() {
    $logo = (string)aw_setting('logo_path', '');
    return ($logo !== '' && strpos($logo, '..') === false) ? $logo : 'assets/images/logo.png';
}

// Q49: rate-limit counters (login, registration, contact, password reset) were kept in the system temp
// folder, which on shared hosting can be shared with other sites or emptied at any time, silently
// resetting the limits. They now live in data/ratelimit/ (not web-readable, not deployed), falling
// back to the temp folder only if that cannot be created.
function aw_ratelimit_file($key) {
    $dir = __DIR__ . '/../data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $base = (is_dir($dir) && is_writable($dir)) ? $dir : sys_get_temp_dir();
    return $base . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$key) . '.tmp';
}

