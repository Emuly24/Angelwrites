<?php
/**
 * Automatic daily database backups.
 *
 * The free host has no scheduled jobs, so the site keeps its own: the first page view after 24 hours
 * makes the copy, after that visitor's page has been sent (they never wait for it). The copy is made
 * with SQLite's online backup (consistent while the site is in use), checked with integrity_check,
 * compressed, and kept in data/backups/ (never served over the web: a deny-all .htaccess is written
 * there). The last 7 daily copies are kept, plus one per week for the 4 weeks before.
 *
 * These copies protect against mistakes and damage, not against losing the whole hosting account:
 * an admin should still download one now and then (Admin > Backup) and keep it somewhere safe.
 * Copies are never emailed: they hold every member's details.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

const AW_AUTO_BACKUP_EVERY = 86400;    // seconds between automatic copies
const AW_AUTO_BACKUP_DAILY = 7;        // newest copies kept
const AW_AUTO_BACKUP_WEEKLY = 4;       // older copies kept, one per week

function aw_auto_backup_dir() {
    $db_file = defined('DB_PATH') ? DB_PATH : dirname(__DIR__) . '/data/site.db';
    return dirname($db_file) . '/backups';
}

/** The automatic copies, newest first: [['file' => name, 'path' => abs, 'at' => unix time, 'size' => bytes], ...] */
function aw_auto_backup_list() {
    $out = [];
    foreach ((array)glob(aw_auto_backup_dir() . '/auto-*.db.gz') as $p) {
        if (!is_file($p) || !preg_match('/auto-(\d{8})-(\d{6})\.db\.gz$/', $p, $m)) continue;
        $t = strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2));
        $out[] = ['file' => basename($p), 'path' => $p, 'at' => $t ?: filemtime($p), 'size' => (int)filesize($p)];
    }
    usort($out, function ($a, $b) { return $b['at'] <=> $a['at']; });
    return $out;
}

/** Is a copy due? Cheap: one settings read. */
function aw_auto_backup_due() {
    if (!function_exists('aw_setting') || (string)aw_setting('auto_backup_enabled', '1') === '0') return false;
    $last = strtotime((string)aw_setting('auto_backup_at', '')) ?: 0;
    return time() - $last >= AW_AUTO_BACKUP_EVERY;
}

/**
 * Called at the end of every page (footer). When a copy is due, it is made after the page has been
 * sent to the visitor. Only one request ever does it (a lock file).
 */
function aw_auto_backup_maybe() {
    static $queued = false;
    if ($queued || !aw_auto_backup_due() || !class_exists('SQLite3')) return false;
    $queued = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); // the visitor is not kept waiting
        aw_auto_backup_run();
    });
    return true;
}

/** Make a copy now (also used by "Make a copy now"). Returns ['ok' => bool, 'file' => ..., 'error' => ...]. */
function aw_auto_backup_run($force = false) {
    $dir = aw_auto_backup_dir();
    $lock = null; $tmp = '';
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['ok' => false, 'error' => 'The backup folder could not be created.'];
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        $lock = @fopen($dir . '/.auto.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'A copy is being made right now.'];
        if (!$force && !aw_auto_backup_due()) return ['ok' => false, 'error' => 'Not due yet.'];
        @set_time_limit(120);
        $db_file = defined('DB_PATH') ? DB_PATH : dirname(__DIR__) . '/data/site.db';
        if (!is_file($db_file)) return ['ok' => false, 'error' => 'The database file was not found.'];
        $free = @disk_free_space($dir);
        if ($free !== false && $free < filesize($db_file) * 3) return ['ok' => false, 'error' => 'There is not enough free space on the server.'];

        $tmp = $dir . '/tmp-' . bin2hex(random_bytes(6)) . '.db';
        $src = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
        $src->busyTimeout(10000);
        $dst = new SQLite3($tmp);
        if (!$src->backup($dst)) { $src->close(); $dst->close(); throw new RuntimeException('The database could not be copied.'); }
        $src->close();
        $dst->querySingle('PRAGMA journal_mode = DELETE');
        $ok = (string)$dst->querySingle('PRAGMA integrity_check') === 'ok';
        $dst->close();
        if (!$ok) throw new RuntimeException('The copy did not pass its integrity check.');

        $name = 'auto-' . date('Ymd-His') . '.db.gz';
        $in = fopen($tmp, 'rb'); $out = gzopen($dir . '/' . $name, 'wb6');
        if (!$in || !$out) throw new RuntimeException('The copy could not be compressed.');
        while (!feof($in)) gzwrite($out, (string)fread($in, 1 << 20));
        fclose($in); gzclose($out);
        @unlink($tmp); $tmp = '';
        @chmod($dir . '/' . $name, 0600);

        aw_auto_backup_prune();
        $db = $GLOBALS['db'] ?? null;
        if ($db instanceof PDO) {
            $db->prepare("INSERT INTO settings (key, value) VALUES ('auto_backup_at', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);
            $db->prepare("INSERT INTO settings (key, value) VALUES ('auto_backup_error', '') ON CONFLICT(key) DO UPDATE SET value = ''")->execute();
        }
        return ['ok' => true, 'file' => $name];
    } catch (Throwable $e) {
        error_log('auto backup: ' . $e->getMessage());
        if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
        try {
            $db = $GLOBALS['db'] ?? null;
            if ($db instanceof PDO) {
                // wait a day before trying again, and say why on the backup page
                $db->prepare("INSERT INTO settings (key, value) VALUES ('auto_backup_at', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);
                $db->prepare("INSERT INTO settings (key, value) VALUES ('auto_backup_error', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('j M Y') . ': ' . $e->getMessage()]);
            }
        } catch (Throwable $e2) { error_log('auto backup status: ' . $e2->getMessage()); }
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The copy could not be made.'];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Keep the newest daily copies, then one per ISO week for a few weeks; delete the rest. */
function aw_auto_backup_prune() {
    $list = aw_auto_backup_list();
    $keep = []; $weeks = [];
    foreach ($list as $i => $b) {
        if ($i < AW_AUTO_BACKUP_DAILY) { $keep[$b['file']] = true; continue; }
        $w = date('o-W', $b['at']);
        if (!isset($weeks[$w]) && count($weeks) < AW_AUTO_BACKUP_WEEKLY) { $weeks[$w] = true; $keep[$b['file']] = true; }
    }
    $n = 0;
    foreach ($list as $b) if (!isset($keep[$b['file']])) { if (@unlink($b['path'])) $n++; }
    return $n;
}
