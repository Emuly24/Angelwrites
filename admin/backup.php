<?php
/**
 * P0-1: manual database backup for the admin team (PROGRAM_PLAN.md, Phase 0).
 *
 * The free host has no scheduled jobs and no backups we control, so the backup is taken by hand:
 * an admin confirms their password, the site makes a consistent copy of the database and sends it
 * as a download. Nothing is kept on the server afterwards.
 *
 *  - Admins only; POST + CSRF token + the admin's own password.
 *  - The copy is made with SQLite's online backup, so it is consistent even while the site is in use.
 *  - The copy is checked (integrity_check) before it is sent; a damaged copy is never offered.
 *  - The temporary file lives in data/backups/ (not web-reachable) under a random name and is
 *    deleted as soon as the download finishes, or if anything fails.
 *  - Every backup is written to the audit log; the time of the last one is stored in settings.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

redirectIfNotAdmin();

$error = '';
$dbFile = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../data/site.db';

function aw_backup_size($bytes) {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

// ===== MAKE AND SEND THE BACKUP =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_backup'])) {
    $st = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $st->execute([(int)$_SESSION['user_id']]);
    $hash = (string)$st->fetchColumn();
    $st->closeCursor();

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($hash === '' || !password_verify((string)($_POST['password'] ?? ''), $hash)) {
        $error = 'That password is not correct. The backup was not made.';
        error_log('backup refused: wrong password for admin #' . (int)$_SESSION['user_id']);
    } elseif (!class_exists('SQLite3')) {
        $error = 'This server cannot make a backup copy (the SQLite3 extension is missing). Please download data/site.db by FTP instead.';
    } else {
        $dir = dirname($dbFile) . '/backups';
        $tmp = '';
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('The backup folder could not be created.');
            }
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
            }
            // remove anything an interrupted earlier backup may have left behind
            foreach ((array)glob($dir . '/aw-backup-*.db') as $old) {
                if (is_file($old) && filemtime($old) < time() - 600) @unlink($old);
            }
            $free = @disk_free_space($dir);
            $need = (int)filesize($dbFile) * 2;
            if ($free !== false && $free < $need) {
                throw new RuntimeException('There is not enough free space on the server to make the copy.');
            }

            $tmp = $dir . '/aw-backup-' . bin2hex(random_bytes(8)) . '.db';
            $src = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $src->busyTimeout(10000);
            $dst = new SQLite3($tmp);
            if (!$src->backup($dst)) {
                throw new RuntimeException('The database could not be copied.');
            }
            $src->close();
            // a single self-contained file: no -wal / -shm companions needed to open it
            $dst->querySingle('PRAGMA journal_mode = DELETE');
            // Compact the copy (never the live database). A restore is uploaded by FTP, and this host
            // refuses files over about 10 MB, so the smaller the backup the longer that keeps working.
            @$dst->exec('VACUUM');
            $check = (string)$dst->querySingle('PRAGMA integrity_check');
            $users = (int)$dst->querySingle('SELECT COUNT(*) FROM users');
            $dst->close();
            if ($check !== 'ok') {
                throw new RuntimeException('The copy did not pass its integrity check, so it was not sent. Please try again.');
            }
            clearstatcache(true, $tmp);
            $size = (int)filesize($tmp);

            // record it (before sending: after the download starts the page cannot report anything)
            $now = date('Y-m-d H:i:s');
            $db->prepare("INSERT INTO settings (key, value) VALUES ('last_backup_at', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$now]);
            try {
                $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)")
                   ->execute([(int)$_SESSION['user_id'], 'database_backup', 'Database backup downloaded (' . aw_backup_size($size) . ', ' . $users . ' accounts)']);
            } catch (Throwable $e) { /* the audit table is optional */ }

            $name = 'angelwrites-backup-' . date('Y-m-d_Hi') . '.db';
            while (ob_get_level() > 0) { ob_end_clean(); }
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            ignore_user_abort(true);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            readfile($tmp);
            exit;
        } catch (Throwable $e) {
            if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
            error_log('backup failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'The backup could not be made. Please try again.';
        }
    }
}

// ===== PAGE =====
$last = (string)aw_setting('last_backup_at', '');
$dbSize = is_file($dbFile) ? (int)filesize($dbFile) : 0;
$counts = [];
foreach (['Members' => 'users', 'Books' => 'books', 'Poems' => 'poems', 'Blog posts and reflections' => 'blog_posts',
          'Newsletter subscribers' => 'newsletter', 'Contact messages' => 'contact_messages'] as $label => $table) {
    try { $counts[$label] = (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn(); } catch (Throwable $e) { /* table not present */ }
}
$days = $last !== '' ? (int)floor((time() - strtotime($last)) / 86400) : null;

$pageTitle = 'Database Backup';
require_once '../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:860px;">
        <div class="admin-header">
            <div>
                <h1>Database Backup</h1>
                <p>Download a full copy of the site's database and keep it somewhere safe.</p>
            </div>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($days === null): ?>
            <div class="alert alert-warning">No backup has been downloaded yet.</div>
        <?php elseif ($days >= 7): ?>
            <div class="alert alert-warning">The last backup was <?php echo $days; ?> days ago (<?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($last))); ?>). Please take a new one.</div>
        <?php else: ?>
            <div class="alert alert-success">Last backup: <?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($last))); ?><?php echo $days === 0 ? ' (today)' : ' (' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago)'; ?>.</div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-body">
                <h2 style="font-size:1.25rem;margin:0 0 6px;">Download a backup now</h2>
                <p style="color:var(--text-light);margin:0 0 14px;">The database is <?php echo htmlspecialchars(aw_backup_size($dbSize)); ?><?php
                    if ($counts) { $bits = []; foreach ($counts as $l => $n) { $bits[] = number_format($n) . ' ' . strtolower($l); } echo ': ' . htmlspecialchars(implode(', ', $bits)); } ?>.</p>
                <form method="POST" class="admin-form" autocomplete="off" data-no-loading>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="form-group">
                        <label for="backup_password">Confirm your password</label>
                        <input type="password" id="backup_password" name="password" required autocomplete="current-password" style="max-width:340px;">
                        <small class="field-hint">Asked every time, because the file contains every member's details.</small>
                    </div>
                    <button type="submit" name="make_backup" value="1" class="btn btn-primary"><i class="fas fa-download"></i> Download backup</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-body">
                <h2 style="font-size:1.25rem;margin:0 0 10px;">Keeping it safe</h2>
                <ul style="margin:0 0 0 20px;line-height:1.8;">
                    <li>Take a backup <strong>once a week</strong>, and before merging any update to the site.</li>
                    <li>The file holds members' names, email addresses and private writing. Keep it on a device with a passcode, never in a shared folder, and never send it by email or chat.</li>
                    <li>Keep the last four files and delete older ones.</li>
                    <li><strong>Not included:</strong> uploaded pictures, recordings and book files (the <code>assets/uploads</code> folder). Copy that folder with the host's file manager or FTP from time to time.</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 style="font-size:1.25rem;margin:0 0 10px;">If you ever need to restore</h2>
                <ol style="margin:0 0 0 20px;line-height:1.8;">
                    <li>Open the host's file manager (or FTP) and go to <code>htdocs/data</code>.</li>
                    <li>Rename the current <code>site.db</code> to <code>site-before-restore.db</code>. Delete <code>site.db-wal</code> and <code>site.db-shm</code> if they are there.</li>
                    <li>Upload your backup file into that folder and rename it to <code>site.db</code>.</li>
                    <li>Open the site and sign in. Anything added after the backup was taken will be missing.</li>
                    <li>When you are sure all is well, delete <code>site-before-restore.db</code>.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
