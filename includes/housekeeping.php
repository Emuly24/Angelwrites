<?php
// Q50: several tables only ever grew. This removes or trims what is no longer needed, at most once a
// day. It is started by about 1 request in 100 (no cost on the others) and never stops the page.
// Read history (view_logs, poem_reads, blog_reads) is kept: it feeds the dashboards and profiles.
function aw_housekeeping(PDO $db) {
    try {
        $last = $db->query("SELECT value FROM settings WHERE key = 'last_housekeeping'")->fetchColumn();
        if ($last && strtotime($last) > time() - 86400) {
            return;
        }
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('last_housekeeping', ?)")->execute([date('Y-m-d H:i:s')]);
        $steps = [
            // expired "remember me" tokens can never be used again
            "DELETE FROM remember_tokens WHERE expires_at < datetime('now')",
            // finished background jobs (nothing reads them)
            "DELETE FROM jobs WHERE COALESCE(status, '') != 'pending' AND created_at < datetime('now', '-30 days')",
            // Q17: group activity feeds show the latest entries; keep a year of history
            "DELETE FROM group_activity_log WHERE created_at < datetime('now', '-365 days')",
            // IP addresses are only needed for recent abuse checks; the view counts stay
            "UPDATE view_logs SET ip_address = NULL WHERE ip_address IS NOT NULL AND viewed_at < datetime('now', '-90 days')",
        ];
        // Q49: rate-limit counters older than a day have expired
        foreach (glob(__DIR__ . '/../data/ratelimit/*.tmp') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) @unlink($f);
        }
        foreach ($steps as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                error_log('aw_housekeeping: ' . $e->getMessage()); // e.g. a table not created yet
            }
        }
    } catch (Throwable $e) {
        error_log('aw_housekeeping: ' . $e->getMessage());
    }
}
