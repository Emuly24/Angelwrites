<?php
/**
 * Reads the site's private settings (.env): email password, Google and Facebook keys, journal key…
 *
 * It looks in every usual place, in this order, and reads the first one it finds:
 *   1. the site folder (htdocs/.env, next to index.php)
 *   2. the folder above it (outside the web, the safest place)
 *   3. includes/.env
 *   4. in the folder above the site only: "env" or ".env.txt" (file managers and Windows sometimes hide
 *      the dot or add .txt). Inside the site folder only ".env" is read, because .htaccess blocks
 *      downloading it.
 * It copes with a byte-order mark, Windows line endings, "export NAME=value", quotes and comments
 * after a value. Values are kept in memory for aw_env(), and also offered to getenv()/$_ENV where
 * the host allows it, so the site works even where putenv() is switched off.
 * Nothing is printed and nothing throws. aw_env_report() tells Admin > Settings what was read
 * (file location and key NAMES only, never values).
 */

/** Where the site looks for the file, in order. */
function aw_env_candidates() {
    $site = dirname(__DIR__);
    $dirs = [$site, dirname($site), __DIR__];
    $out = [];
    foreach ($dirs as $d) $out[] = $d . DIRECTORY_SEPARATOR . '.env';
    // other names only outside the web folder (inside it, only .env is guaranteed blocked from download)
    foreach (['env', '.env.txt'] as $name) $out[] = dirname($site) . DIRECTORY_SEPARATOR . $name;
    return $out;
}

/** Load one file. Returns the number of settings read, or false when it cannot be read. */
function loadEnv($file) {
    if (!is_file($file) || !is_readable($file)) return false;
    $raw = @file_get_contents($file);
    if ($raw === false) return false;
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3); // byte-order mark from Notepad
    $n = 0;
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        if (stripos($line, 'export ') === 0) $line = trim(substr($line, 7));
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $q = $value[0];
            $end = strpos($value, $q, 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        } else {
            $value = trim(preg_replace('/\s+#.*$/', '', $value)); // "abc   # note" -> "abc"
        }
        $GLOBALS['AW_ENV'][$name] = $value;
        if (getenv($name) === false || getenv($name) === '') {
            if (function_exists('putenv')) @putenv("$name=$value");
            $_ENV[$name] = $value;
        }
        $n++;
    }
    return $n;
}

/** A setting from .env (or the server's own environment). '' when not set. */
function aw_env($name, $default = '') {
    if (isset($GLOBALS['AW_ENV'][$name]) && $GLOBALS['AW_ENV'][$name] !== '') return (string)$GLOBALS['AW_ENV'][$name];
    $v = getenv($name);
    if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
    if (isset($_ENV[$name]) && trim((string)$_ENV[$name]) !== '') return trim((string)$_ENV[$name]);
    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && trim($_SERVER[$name]) !== '') return trim($_SERVER[$name]);
    return (string)$default;
}

/** What was read, for Admin > Settings: ['file' => where (relative), 'count' => n, 'names' => [...], 'looked' => [...]]. */
function aw_env_report() {
    $site = dirname(__DIR__);
    $rel = function ($p) use ($site) {
        if (strpos($p, $site . DIRECTORY_SEPARATOR) === 0) return 'site folder/' . substr($p, strlen($site) + 1);
        if (strpos($p, dirname($site) . DIRECTORY_SEPARATOR) === 0) return 'folder above the site/' . substr($p, strlen(dirname($site)) + 1);
        return basename($p);
    };
    return ['file' => isset($GLOBALS['AW_ENV_FILE']) ? $rel($GLOBALS['AW_ENV_FILE']) : '', 'count' => count($GLOBALS['AW_ENV'] ?? []),
            'names' => array_keys($GLOBALS['AW_ENV'] ?? []), 'looked' => array_map($rel, aw_env_candidates())];
}

if (!isset($GLOBALS['AW_ENV'])) {
    $GLOBALS['AW_ENV'] = [];
    foreach (aw_env_candidates() as $aw_env_f) {
        if (loadEnv($aw_env_f) !== false) { $GLOBALS['AW_ENV_FILE'] = $aw_env_f; break; }
    }
    unset($aw_env_f);
}
