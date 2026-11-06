<?php
require_once __DIR__ . '/load_env.php';
// Site Configuration
define('SITE_NAME', getenv('SITE_NAME'));
define('UPLOAD_PATH', getenv('UPLOAD_PATH'));
define('BIBLE_PATH', getenv('BIBLE_PATH'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('DB_PATH', __DIR__ . '/../data/site.db');
define('SITE_URL', 'https://angelwrites.gt.tc');

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

// Saved admin email if it is a real address; 'admin@angelawrites.com' is the old seed value (see
// F108), never set by the admin, so it is ignored. Then ADMIN_EMAIL from .env, then the inbox the
// site has always used.
function aw_admin_email() {
    $saved = (string)aw_setting('admin_email', '');
    if ($saved !== '' && strcasecmp($saved, 'admin@angelawrites.com') !== 0 && filter_var($saved, FILTER_VALIDATE_EMAIL)) {
        return $saved;
    }
    return (ADMIN_EMAIL && strcasecmp(ADMIN_EMAIL, 'admin@angelawrites.com') !== 0 && filter_var(ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)) ? ADMIN_EMAIL : 'angelwrites@zohomail.com';
}

function aw_logo_path() {
    $logo = (string)aw_setting('logo_path', '');
    return ($logo !== '' && strpos($logo, '..') === false) ? $logo : 'assets/images/logo.png';
}
?>