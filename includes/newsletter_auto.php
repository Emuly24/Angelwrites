<?php
// Scheduled letters send themselves. The host has no cron, so two things wake the sender:
//  1. site traffic: aw_nl_auto_maybe() (called from db.php) checks, at most every 2 minutes, whether a
//     letter is due or a send was left half-done, and sends a few emails after the visitor's page has
//     been delivered (fastcgi_finish_request), so nobody waits for it;
//  2. a timer outside the host: .github/workflows/newsletter-schedule.yml calls admin/process_queue.php
//     every 10 minutes with the NEWSLETTER_CRON_KEY, so a letter goes out on time even on a quiet night.
// Both use aw_nl_auto_run(). aw_nl_send_batch() takes a per-campaign lock, so the two (and the admin
// page) never email the same person twice. Times in email_queue.scheduled_at are UTC.

if (!defined('AW_NL_AUTO_EVERY')) define('AW_NL_AUTO_EVERY', 120); // seconds between traffic checks

/** Due or unfinished campaigns exist? One small query. */
function aw_nl_auto_due(PDO $db) {
    try {
        $st = $db->prepare("SELECT 1 FROM email_queue WHERE (status = 'pending' AND scheduled_at IS NOT NULL AND scheduled_at <= ?) OR status = 'processing' LIMIT 1");
        $st->execute([gmdate('Y-m-d H:i:s')]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false; // e.g. the table does not exist yet
    }
}

/**
 * Starts every due scheduled letter and sends batches until $seconds have passed.
 * Returns ['started' => [ids], 'sent' => n, 'failed' => n, 'skipped' => n, 'left' => n] or false.
 */
function aw_nl_auto_run(PDO $db, $seconds = 20, $batch = 5) {
    try {
        require_once __DIR__ . '/newsletter.php';
        $t0 = microtime(true);
        $out = ['started' => [], 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 0];
        $st = $db->prepare("SELECT id FROM email_queue WHERE status = 'pending' AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at, id");
        $st->execute([gmdate('Y-m-d H:i:s')]);
        $start = $db->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ? AND status = 'pending'");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $start->execute([(int)$id]);
            if ($start->rowCount() === 1) { // only one process wins the switch
                $out['started'][] = (int)$id;
                aw_nl_audit($db, 'auto_send', "Scheduled campaign #$id started automatically");
            }
        }
        $ids = $db->query("SELECT id FROM email_queue WHERE status = 'processing' ORDER BY COALESCE(scheduled_at, created_at), id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            while (microtime(true) - $t0 < $seconds) {
                $before = aw_nl_progress($db, (int)$id);
                if (!$before || $before['queue']['status'] !== 'processing') break;
                $p = aw_nl_send_batch($db, (int)$id, $batch);
                if (!$p) break;
                $out['sent'] += $p['sent'] - $before['sent'];
                $out['failed'] += $p['failed'] - $before['failed'];
                $out['skipped'] += $p['skipped'] - $before['skipped'];
                if (empty($p['remaining']) || $p['queue']['status'] !== 'processing') break;
                if (count($p['remaining']) === count($before['remaining'])) break; // locked by another sender
            }
        }
        $out['left'] = (int)$db->query("SELECT COUNT(*) FROM email_queue WHERE status = 'processing'")->fetchColumn();
        return $out;
    } catch (Throwable $e) {
        error_log('aw_nl_auto_run: ' . $e->getMessage());
        return false;
    }
}

/** Called on every request: cheap check, then a short send after the page is delivered. */
function aw_nl_auto_maybe(PDO $db) {
    try {
        if (PHP_SAPI === 'cli' || defined('AW_NL_AUTO_OFF')) return;
        if (!aw_nl_auto_due($db)) return;
        // claim the slot: only one request every AW_NL_AUTO_EVERY seconds does the work
        $now = time();
        $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('nl_auto_last', '0')");
        $claim = $db->prepare("UPDATE settings SET value = ? WHERE key = 'nl_auto_last' AND CAST(value AS INTEGER) <= ?");
        $claim->execute([(string)$now, $now - AW_NL_AUTO_EVERY]);
        if ($claim->rowCount() !== 1) return;
        register_shutdown_function(function () use ($db) {
            try {
                ignore_user_abort(true);
                $detached = (function_exists('fastcgi_finish_request') && @fastcgi_finish_request())
                         || (function_exists('litespeed_finish_request') && @litespeed_finish_request());
                // The visitor already has the whole page. Where the server cannot close the connection
                // first, send a single email so their loading indicator stops a moment later at most;
                // the 10-minute timer does the rest.
                aw_nl_auto_run($db, $detached ? 15 : 1, $detached ? 5 : 1);
            } catch (Throwable $e) {
                error_log('aw_nl_auto_maybe: ' . $e->getMessage());
            }
        });
    } catch (Throwable $e) {
        error_log('aw_nl_auto_maybe: ' . $e->getMessage());
    }
}

/** A date and time typed in site time (datetime-local) to stored UTC, or '' when it cannot be read. */
function aw_nl_local_to_utc($local) {
    $local = trim((string)$local);
    if ($local === '' || strtotime($local) === false) return '';
    require_once __DIR__ . '/events.php';
    return aw_event_to_utc($local);
}

/** A stored UTC time shown in site time, e.g. "21 Nov 2026, 09:00". */
function aw_nl_when($utc, $format = 'j M Y, H:i') {
    if (!$utc) return '';
    require_once __DIR__ . '/events.php';
    return aw_event_local($utc)->format($format);
}

/** "Malawi time" and so on. */
function aw_nl_tz_label() {
    require_once __DIR__ . '/events.php';
    return aw_event_tz_label();
}

/** 3 days 4 hours, 2 hours 5 minutes, 7 minutes. */
function aw_nl_until($seconds) {
    $s = max(60, (int)$seconds);
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    $pl = function ($n, $w) { return $n . ' ' . $w . ($n === 1 ? '' : 's'); };
    if ($d) return $pl($d, 'day') . ($h ? ' ' . $pl($h, 'hour') : '');
    if ($h) return $pl($h, 'hour') . ($m ? ' ' . $pl($m, 'minute') : '');
    return $pl($m, 'minute');
}

/** Now in site time, for a datetime-local input's min. */
function aw_nl_local_now($format = 'Y-m-d\TH:i') {
    return aw_nl_when(gmdate('Y-m-d H:i:s'), $format);
}

/** The configured key for the web timer, or '' when none is set (the endpoint is then closed). */
function aw_nl_cron_key() {
    if (!function_exists('aw_env')) return '';
    return trim((string)(aw_env('NEWSLETTER_CRON_KEY') ?: ''));
}
