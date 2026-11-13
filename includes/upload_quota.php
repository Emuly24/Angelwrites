<?php
/**
 * R: per-member upload quotas (limits in upload_limits.php, enforced by includes/upload.php).
 */

/**
 * Usage is kept in its own small table, one row per member per day. It cannot be derived from
 * existing records: uploads are stored as paths in a dozen different columns (covers, voice paths,
 * avatars, book files) with no owner or size, and several are replaced in place.
 * "Total" is everything the member has ever stored; deleting content does not hand quota back.
 * The table is created the first time an upload happens (no schema-version step needed).
 */
function aw_upload_usage_table(PDO $db): void
{
    static $ready = false;
    if ($ready) return;
    $db->exec("CREATE TABLE IF NOT EXISTS member_upload_usage (
        user_id INTEGER NOT NULL,
        day TEXT NOT NULL,
        bytes_sent INTEGER NOT NULL DEFAULT 0,   -- what the member uploaded (counts toward the daily limit)
        bytes INTEGER NOT NULL DEFAULT 0,        -- what was stored after resizing (counts toward the total)
        files INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, day)
    )");
    $ready = true;
}

function aw_upload_usage(PDO $db, int $userId): array
{
    aw_upload_usage_table($db);
    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN day = ? THEN bytes_sent ELSE 0 END), 0) AS today, COALESCE(SUM(bytes), 0) AS total
                        FROM member_upload_usage WHERE user_id = ?");
    $st->execute([date('Y-m-d'), $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['today' => 0, 'total' => 0];
    $st->closeCursor();
    return ['today' => (int)$r['today'], 'total' => (int)$r['total']];
}

function aw_upload_usage_add(PDO $db, int $userId, int $sent, int $stored): void
{
    aw_upload_usage_table($db);
    $db->prepare("INSERT INTO member_upload_usage (user_id, day, bytes_sent, bytes, files) VALUES (?, ?, ?, ?, 1)
                  ON CONFLICT(user_id, day) DO UPDATE SET bytes_sent = bytes_sent + excluded.bytes_sent,
                                                          bytes = bytes + excluded.bytes, files = files + 1")
       ->execute([$userId, date('Y-m-d'), $sent, $stored]);
}

/**
 * '' when the member may upload this file; otherwise the message to show.
 * The daily limit counts what the member sent (so 60 MB of photos in a day stops at 50 MB, even though
 * resizing stores far less); the total counts what is actually kept on disk.
 */
function aw_upload_quota_check(PDO $db, int $userId, string $role, int $sent, int $stored): string
{
    $q = aw_upload_limits()['_quota'];
    if (in_array($role, $q['exempt_roles'], true)) return '';
    $u = aw_upload_usage($db, $userId);
    if ($u['today'] + $sent > $q['per_day_bytes']) {
        return 'You have reached today\'s upload limit (' . aw_upload_mb($q['per_day_bytes']) . ' a day). Please try again tomorrow.';
    }
    if ($u['total'] + $stored > $q['total_bytes']) {
        return 'You have used all of your upload space (' . aw_upload_mb($q['total_bytes']) . '). Please contact us if you need more.';
    }
    return '';
}
