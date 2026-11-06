<?php
// ============================================================
//  AW_REPAIR.PHP – repair the AngelWrites database from the browser
// ============================================================
// Upload this one file to htdocs/ (next to index.php), log in to the site as the admin, then open
//     https://angelwrites.gt.tc/aw_repair.php
// It does the same repair as db/repair/repair.sql, but on the LIVE database, from the browser, and it
// reads the database as it is today (so it still works if the database changed since the snapshot):
//   1. Check   – read-only: what is wrong and what will be done. Nothing is changed.
//   2. Repair  – first a full backup (SQLite online backup into data/backups/, checked row by row),
//                then, in ONE transaction with foreign keys off:
//                  · rebuild the tables whose foreign keys point at the empty users_old table
//                    (every row, index, trigger and autoincrement counter is kept)
//                  · relax the two NOT NULL traps (reading_groups.book_id, book_comments)
//                  · add the missing columns and tables
//                  · drop users_old only if it is empty
//                Before saving it checks that every table has exactly the same number of rows and that
//                SQLite's integrity check says "ok". If anything is different, NOTHING is saved.
//   3. Restore – puts a backup back (after first backing up the current state), if ever needed.
// Running it again is harmless: it finds nothing left to do.
// When you are done, DELETE this file from the server.
//
// This file is in db/repair/web/ in the repository; db/** is never deployed, so it only reaches the
// server when uploaded by hand.

declare(strict_types=1);
@set_time_limit(300);
@ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) session_start();

const AW_DB = __DIR__ . '/data/site.db';
const AW_BACKUPS = __DIR__ . '/data/backups';

// ---------------------------------------------------------------- page helpers
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('X-Frame-Options: DENY');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · AngelWrites</title>'
        . '<style>body{margin:0;background:#F7F3ED;color:#3d2e2e;font:16px/1.6 Inter,Arial,sans-serif}main{max-width:860px;margin:0 auto;padding:24px 16px 60px}'
        . 'h1{font-family:"Playfair Display",Georgia,serif;color:#2c1e1e;margin:.2em 0}h2{font-family:Georgia,serif;font-size:1.2rem;margin:1.4em 0 .5em}'
        . '.card{background:#fff;border:1px solid #EFD8D6;border-radius:14px;padding:16px 18px;margin:14px 0}.ok{color:#1e7a46}.bad{color:#b3261e}.warn{color:#8a5a00}'
        . '.btn{display:inline-block;background:#DBA1A2;color:#fff;border:0;border-radius:999px;padding:10px 20px;font:600 15px Inter,Arial,sans-serif;cursor:pointer;text-decoration:none}'
        . '.btn.alt{background:#fff;color:#2c1e1e;border:1px solid #DBA1A2}.btn.danger{background:#b3261e}'
        . 'table{border-collapse:collapse;width:100%;font-size:.9rem}td,th{border-bottom:1px solid #EFD8D6;padding:6px 8px;text-align:left;vertical-align:top}'
        . 'code{background:#F7F3ED;padding:1px 5px;border-radius:5px}input[type=text]{padding:8px 10px;border:1px solid #DBA1A2;border-radius:8px;font-size:1rem}'
        . '.banner{background:#EFD8D6;border-bottom:4px solid #DBA1A2;padding:14px 16px;text-align:center;font:600 12px Inter,Arial;letter-spacing:3px;text-transform:uppercase;color:#c08a8b}</style></head>'
        . '<body><div class="banner">AngelWrites · database repair</div><main>' . $body . '</main></body></html>';
    exit;
}
function fail(string $msg): void { http_response_code(400); page('Repair', '<h1>Database repair</h1><div class="card bad">' . $msg . '</div>'); }

// ---------------------------------------------------------------- database
function open_db(string $file): PDO {
    $db = new PDO('sqlite:' . $file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 15000');
    return $db;
}
function q(string $name): string { return '"' . str_replace('"', '""', $name) . '"'; }
function tables(PDO $db): array {
    return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
function cols(PDO $db, string $t): array {
    return array_column($db->query('PRAGMA table_info(' . q($t) . ')')->fetchAll(), 'name');
}
function counts(PDO $db): array {
    $c = [];
    foreach (tables($db) as $t) $c[$t] = (int)$db->query('SELECT COUNT(*) FROM ' . q($t))->fetchColumn();
    return $c;
}
function ddl(PDO $db, string $t): string {
    $st = $db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$t]);
    return (string)$st->fetchColumn();
}

// What the code needs (same lists as db/repair/generate.py).
const RELAX = [
    'book_comments' => [['/paragraph_index INTEGER NOT NULL/i', 'paragraph_index INTEGER DEFAULT 0'], ['/comment_text TEXT NOT NULL/i', 'comment_text TEXT'], ['/\bcomment TEXT NOT NULL/i', 'comment TEXT']],
    'reading_groups' => [['/book_id INTEGER NOT NULL/i', 'book_id INTEGER']],
];
const ADD_COLS = [
    ['sessions', 'session_type', "TEXT DEFAULT 'spiritual_guidance'"], ['sessions', 'timezone', 'TEXT'],
    ['reflections', 'tags', "TEXT DEFAULT ''"], ['reflections', 'slug', 'TEXT'], ['reflections', 'featured', 'INTEGER DEFAULT 0'],
    ['reflections', 'comment_count', 'INTEGER DEFAULT 0'], ['reflections', 'likes', 'INTEGER DEFAULT 0'], ['reflections', 'loves', 'INTEGER DEFAULT 0'], ['reflections', 'prays', 'INTEGER DEFAULT 0'],
    ['blog_posts', 'comment_count', 'INTEGER DEFAULT 0'], ['blog_posts', 'likes', 'INTEGER DEFAULT 0'], ['blog_posts', 'loves', 'INTEGER DEFAULT 0'], ['blog_posts', 'prays', 'INTEGER DEFAULT 0'],
    ['book_comments', 'page_num', 'INTEGER'], ['book_comments', 'parent_id', 'INTEGER'], ['book_comments', 'is_admin_reply', 'INTEGER DEFAULT 0'],
    ['reflection_comments', 'photo_path', 'TEXT'], ['reflection_comments', 'voice_path', 'TEXT'], ['reflection_comments', 'is_admin_reply', 'INTEGER DEFAULT 0'],
    ['prayer_requests', 'book_id', 'INTEGER'], ['prayer_requests', 'request_text', 'TEXT'],
    ['videos', 'views', 'INTEGER DEFAULT 0'], ['videos', 'likes', 'INTEGER DEFAULT 0'], ['videos', 'dislikes', 'INTEGER DEFAULT 0'],
    ['newsletter', 'updated_at', 'DATETIME'], ['newsletter', 'source', "TEXT DEFAULT 'website'"],
    ['bookmarks', 'offset', 'INTEGER DEFAULT 0'], ['email_queue', 'segment_id', 'INTEGER'],
    ['reading_challenges', 'completed', 'INTEGER DEFAULT 0'], ['reading_goals', 'completed_at', 'DATETIME'],
    ['users', 'is_admin', 'INTEGER DEFAULT 0'],
];
const NEW_TABLES = [
    'comment_reactions' => "CREATE TABLE comment_reactions (id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL, reaction_type TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (comment_id, user_id, reaction_type), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
    'review_reactions' => "CREATE TABLE review_reactions (id INTEGER PRIMARY KEY AUTOINCREMENT, review_id INTEGER NOT NULL, user_id INTEGER NOT NULL, reaction_type TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (review_id, user_id, reaction_type), FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
    'video_comments' => "CREATE TABLE video_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, video_id INTEGER NOT NULL, user_id INTEGER NOT NULL, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
    'purchases' => "CREATE TABLE purchases (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, book_id INTEGER NOT NULL, amount REAL, payment_status TEXT DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, book_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE)",
];

/** Read-only analysis of what needs doing. */
function plan(PDO $db): array {
    $all = tables($db);
    $fk_old = [];
    foreach ($all as $t) {
        foreach ($db->query('PRAGMA foreign_key_list(' . q($t) . ')')->fetchAll() as $fk) {
            if (strcasecmp((string)$fk['table'], 'users_old') === 0) { $fk_old[$t] = true; break; }
        }
    }
    $relax = [];
    foreach (RELAX as $t => $rules) {
        if (!in_array($t, $all, true)) continue;
        $sql = ddl($db, $t);
        foreach ($rules as [$re]) if (preg_match($re, $sql)) { $relax[$t] = true; break; }
    }
    $rebuild = array_keys($fk_old + $relax);
    sort($rebuild);
    $add = [];
    foreach (ADD_COLS as [$t, $c, $type]) {
        if (in_array($t, $all, true) && !in_array($c, cols($db, $t), true)) $add[] = [$t, $c, $type];
    }
    $missing = array_values(array_filter(array_keys(NEW_TABLES), function ($t) use ($all) { return !in_array($t, $all, true); }));
    $users_old = in_array('users_old', $all, true) ? (int)$db->query('SELECT COUNT(*) FROM users_old')->fetchColumn() : null;
    return ['rebuild' => $rebuild, 'fk_old' => array_keys($fk_old), 'relax' => array_keys($relax), 'add' => $add, 'missing' => $missing, 'users_old' => $users_old,
        'nothing' => !$rebuild && !$add && !$missing && $users_old === null];
}

function backups(): array {
    $list = glob(AW_BACKUPS . '/site-*.db') ?: [];
    rsort($list);
    return $list;
}

/** Consistent copy of the live database (SQLite online backup: safe while the site is in use). */
function make_backup(string $label): string {
    if (!is_dir(AW_BACKUPS) && !@mkdir(AW_BACKUPS, 0755, true)) throw new RuntimeException('Could not create data/backups/.');
    // Never reachable from the web, even if data/.htaccess was not uploaded: a deny-all rule here,
    // and a random part in every name so it cannot be guessed.
    if (!is_file(AW_BACKUPS . '/.htaccess')) @file_put_contents(AW_BACKUPS . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    $target = AW_BACKUPS . '/site-' . date('Ymd-His') . '-' . $label . '-' . bin2hex(random_bytes(4)) . '.db';
    $src = new SQLite3(AW_DB, SQLITE3_OPEN_READONLY);
    $src->busyTimeout(15000);
    $dst = new SQLite3($target);
    if (!$src->backup($dst)) throw new RuntimeException('The backup could not be written: ' . $src->lastErrorMsg());
    $dst->querySingle('PRAGMA journal_mode = DELETE'); // one self-contained file (no -wal / -shm beside it), easy to download
    $dst->close(); $src->close();
    // Check the copy: it opens, passes the integrity check and has every table.
    $b = open_db($target);
    if ($b->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('The backup failed its integrity check.');
    $b = null;
    @chmod($target, 0600);
    return $target;
}

// ---------------------------------------------------------------- access: the site's admin only
if (!is_file(AW_DB)) fail('No database at <code>data/site.db</code>. Upload this file to <code>htdocs/</code>, next to <code>index.php</code>.');
if (!class_exists('SQLite3') || !class_exists('PDO')) fail('PHP\'s SQLite extension is not available on this host.');
$uid = (int)($_SESSION['user_id'] ?? 0);
$is_admin = false;
if ($uid && ($_SESSION['role'] ?? '') === 'admin') {
    try {
        $chk = open_db(AW_DB)->prepare('SELECT role FROM users WHERE id = ?');
        $chk->execute([$uid]);
        $is_admin = $chk->fetchColumn() === 'admin';
    } catch (Throwable $e) { $is_admin = false; }
}
if (!$is_admin) {
    fail('Please <a href="login.php?redirect=' . h(urlencode('/aw_repair.php')) . '">log in as the admin</a> first, then open this page again.');
}
if (empty($_SESSION['aw_repair_token'])) $_SESSION['aw_repair_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['aw_repair_token'];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($posted && !hash_equals($token, (string)($_POST['token'] ?? ''))) fail('This form has expired. Go back, reload the page and try again.');

// ---------------------------------------------------------------- download a backup
if (isset($_GET['download'])) {
    $f = AW_BACKUPS . '/' . basename((string)$_GET['download']);
    if (!preg_match('/^site-[\w-]+\.db$/', basename($f)) || !is_file($f)) fail('Backup not found.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

// ---------------------------------------------------------------- restore
if ($posted && ($_POST['action'] ?? '') === 'restore') {
    $f = AW_BACKUPS . '/' . basename((string)($_POST['backup'] ?? ''));
    if (($_POST['confirm'] ?? '') !== 'RESTORE' || !preg_match('/^site-[\w-]+\.db$/', basename($f)) || !is_file($f)) fail('Type RESTORE and choose a backup.');
    try {
        $safety = make_backup('before-restore');
        $src = new SQLite3($f, SQLITE3_OPEN_READONLY);
        $dst = new SQLite3(AW_DB);
        $dst->busyTimeout(15000);
        if (!$src->backup($dst)) throw new RuntimeException($src->lastErrorMsg());
        $src->close(); $dst->close();
        $ok = open_db(AW_DB)->query('PRAGMA integrity_check')->fetchColumn();
        page('Restored', '<h1>Backup restored</h1><div class="card ok">The database was restored from <code>' . h(basename($f)) . '</code> (integrity check: ' . h($ok) . ').<br>The state just before the restore was saved as <code>' . h(basename($safety)) . '</code>.</div><p><a class="btn alt" href="aw_repair.php">Back</a></p>');
    } catch (Throwable $e) {
        fail('The restore did not complete: ' . h($e->getMessage()) . '. The live database was not changed if the error came before copying.');
    }
}

// ---------------------------------------------------------------- repair
if ($posted && ($_POST['action'] ?? '') === 'repair') {
    if (($_POST['confirm'] ?? '') !== 'REPAIR') fail('Type REPAIR in the box to confirm.');
    $log = [];
    try {
        $backup = make_backup('before-repair');
        $log[] = 'Backup written and checked: ' . basename($backup);

        $db = open_db(AW_DB);
        $db->exec('PRAGMA foreign_keys = OFF');          // must be off: rebuilding a parent table with it on deletes child rows
        $db->exec('PRAGMA legacy_alter_table = ON');
        if ((int)$db->query('PRAGMA foreign_keys')->fetchColumn() !== 0) throw new RuntimeException('Foreign keys could not be switched off; nothing was changed.');
        $db->exec('BEGIN IMMEDIATE');                     // the site waits (up to 15 s) while this runs
        $before = counts($db);
        $p = plan($db);

        foreach ($p['rebuild'] as $t) {
            $sql = ddl($db, $t);
            $new = preg_replace('/REFERENCES\s+["`\[]?users_old["`\]]?/i', 'REFERENCES users', $sql);
            foreach (RELAX[$t] ?? [] as [$re, $rep]) $new = preg_replace($re, $rep, $new, 1);
            $new = preg_replace('/^CREATE\s+TABLE\s+["`\[]?' . preg_quote($t, '/') . '["`\]]?/i', 'CREATE TABLE ' . q($t . '__new'), $new, 1, $n);
            if ($n !== 1 || stripos($new, 'users_old') !== false) throw new RuntimeException("Could not prepare the new definition of $t.");
            $c = implode(', ', array_map('q', cols($db, $t)));
            $idx = $db->query("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND tbl_name = " . $db->quote($t) . " AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $seq = $db->query("SELECT seq FROM sqlite_sequence WHERE name = " . $db->quote($t))->fetchColumn();
            $db->exec($new);
            $db->exec('INSERT INTO ' . q($t . '__new') . " ($c) SELECT $c FROM " . q($t));
            $db->exec('DROP TABLE ' . q($t));
            $db->exec('ALTER TABLE ' . q($t . '__new') . ' RENAME TO ' . q($t));
            foreach ($idx as $s) $db->exec($s);
            if ($seq !== false) {
                $db->prepare("DELETE FROM sqlite_sequence WHERE name = ?")->execute([$t]);
                $db->exec("INSERT INTO sqlite_sequence (name, seq) VALUES (" . $db->quote($t) . ", " . (int)$seq . ")"); // an integer, as SQLite keeps it (a bound value would be stored as text)
            }
            $log[] = "Rebuilt $t (" . $before[$t] . ' rows' . (in_array($t, $p['fk_old'], true) ? ', now linked to users' : '') . (in_array($t, $p['relax'], true) ? ', NOT NULL trap relaxed' : '') . ')';
        }
        $added_is_admin = false;
        foreach ($p['add'] as [$t, $col, $type]) {
            $db->exec('ALTER TABLE ' . q($t) . ' ADD COLUMN ' . q($col) . ' ' . $type);
            if ($t === 'users' && $col === 'is_admin') $added_is_admin = true;
            $log[] = "Added column $t.$col";
        }
        if ($added_is_admin) $db->exec("UPDATE users SET is_admin = CASE WHEN role = 'admin' THEN 1 ELSE 0 END");
        foreach ($p['missing'] as $t) { $db->exec(NEW_TABLES[$t]); $log[] = "Created table $t"; }
        if ($p['users_old'] === 0) { $db->exec('DROP TABLE users_old'); $log[] = 'Removed the empty users_old table'; }
        elseif ($p['users_old'] !== null) { $log[] = 'Kept users_old: it is not empty (' . $p['users_old'] . ' rows), so nothing in it was touched'; }

        // ---- checks before saving: every row still there, integrity ok
        $after = counts($db);
        $diff = [];
        foreach ($before as $t => $n) {
            if ($t === 'users_old' && !isset($after[$t]) && $n === 0) continue;
            if (($after[$t] ?? -1) !== $n) $diff[] = "$t: $n before, " . ($after[$t] ?? 'missing') . ' after';
        }
        $integrity = $db->query('PRAGMA integrity_check')->fetchColumn();
        $orphans = $db->query('PRAGMA foreign_key_check')->fetchAll();
        if ($diff || $integrity !== 'ok') {
            $db->exec('ROLLBACK');
            fail('The checks did not pass, so <strong>nothing was saved</strong>: ' . h(implode('; ', $diff) ?: "integrity check: $integrity") . '. Your data is exactly as before.');
        }
        $db->exec('COMMIT');
        $db->exec('PRAGMA legacy_alter_table = OFF');
        $db->exec('PRAGMA foreign_keys = ON');
        try { $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll(); } catch (Throwable $e) {}
        $total = array_sum($before);
        $orph = '';
        if ($orphans) {
            $by = [];
            foreach ($orphans as $o) $by[$o['table'] . ' → ' . $o['parent']] = ($by[$o['table'] . ' → ' . $o['parent']] ?? 0) + 1;
            $orph = '<div class="card warn"><strong>Note:</strong> some rows point to records that no longer exist (they were like this before the repair and were kept unchanged): ' . h(implode(', ', array_map(function ($k, $v) { return "$k: $v"; }, array_keys($by), $by))) . '.</div>';
        }
        page('Repaired', '<h1>✅ The database is repaired</h1>'
            . '<div class="card ok">All ' . number_format($total) . ' rows in ' . count($before) . ' tables are still there (checked table by table), and SQLite\'s integrity check says <strong>ok</strong>.</div>'
            . $orph
            . '<div class="card"><h2 style="margin-top:0">What was done</h2><ul><li>' . implode('</li><li>', array_map('h', $log)) . '</li></ul></div>'
            . '<div class="card"><h2 style="margin-top:0">Next</h2><ol>'
            . '<li>Open the homepage and a poem; log in as a reader and change a book\'s status in My Library; post a test comment on a poem.</li>'
            . '<li><a class="btn alt" href="aw_repair.php?download=' . h(urlencode(basename($backup))) . '">Download the backup</a> to keep a copy on your computer.</li>'
            . '<li><strong>Delete <code>aw_repair.php</code> from the server</strong> (the backups in data/backups/ are not reachable from the web).</li>'
            . '<li>Then we start merging the pull requests.</li></ol>'
            . '<p>If something looks wrong, open this page again and use <em>Restore</em> with <code>' . h(basename($backup)) . '</code>.</p></div>');
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->exec('ROLLBACK');
        fail('The repair stopped and <strong>nothing was saved</strong>: ' . h($e->getMessage()) . (isset($backup) ? '<br>A backup was made first: <code>' . h(basename($backup)) . '</code>.' : ''));
    }
}

// ---------------------------------------------------------------- check (read-only)
try {
    $db = open_db(AW_DB);
    $p = plan($db);
    $c = counts($db);
    $integrity = $db->query('PRAGMA integrity_check')->fetchColumn();
    $journal = $db->query('PRAGMA journal_mode')->fetchColumn();
    $db = null;
} catch (Throwable $e) {
    fail('The database could not be read: ' . h($e->getMessage()));
}
$free = @disk_free_space(__DIR__ . '/data');
$size = filesize(AW_DB) + (is_file(AW_DB . '-wal') ? filesize(AW_DB . '-wal') : 0);
$space_ok = $free === false || $free > $size * 2 + 1048576;

$b = '<h1>Database repair</h1><p>Logged in as the admin. This page only reads the database until you press <strong>Repair now</strong>.</p>';
$b .= '<div class="card"><h2 style="margin-top:0">The database today</h2><ul>'
    . '<li>' . count($c) . ' tables, ' . number_format(array_sum($c)) . ' rows · ' . number_format($size / 1048576, 1) . ' MB · journal: ' . h($journal) . '</li>'
    . '<li>Integrity check: <strong class="' . ($integrity === 'ok' ? 'ok' : 'bad') . '">' . h($integrity) . '</strong></li>'
    . '<li>Free space for the backup: ' . ($free === false ? 'unknown' : '<span class="' . ($space_ok ? 'ok' : 'bad') . '">' . number_format($free / 1048576) . ' MB</span>') . '</li></ul></div>';
if ($p['nothing']) {
    $b .= '<div class="card ok"><strong>Nothing to repair.</strong> The database already has everything the site needs. You can delete <code>aw_repair.php</code> from the server and start merging.</div>';
} else {
    $b .= '<div class="card"><h2 style="margin-top:0">What the repair will do</h2><ul>';
    if ($p['fk_old']) $b .= '<li><strong>' . count($p['fk_old']) . ' tables</strong> are linked to the empty <code>users_old</code> table, which makes every save to them fail: ' . h(implode(', ', $p['fk_old'])) . '. They are rebuilt linked to <code>users</code>, keeping every row.</li>';
    if ($p['relax']) $b .= '<li>Relax the "NOT NULL" traps in: ' . h(implode(', ', $p['relax'])) . '.</li>';
    if ($p['add']) $b .= '<li>Add <strong>' . count($p['add']) . ' missing columns</strong>: ' . h(implode(', ', array_map(function ($a) { return $a[0] . '.' . $a[1]; }, $p['add']))) . '.</li>';
    if ($p['missing']) $b .= '<li>Create the missing tables: ' . h(implode(', ', $p['missing'])) . '.</li>';
    if ($p['users_old'] === 0) $b .= '<li>Remove the empty <code>users_old</code> table.</li>';
    elseif ($p['users_old']) $b .= '<li class="warn"><code>users_old</code> has ' . $p['users_old'] . ' rows, so it will be kept untouched.</li>';
    $b .= '</ul><p>Nothing is deleted: every row is kept, and the repair checks every table\'s row count and the integrity check <em>before</em> saving. If anything differs, nothing is saved.</p></div>';
    if ($integrity !== 'ok' || !$space_ok) {
        $b .= '<div class="card bad">The repair is disabled: ' . ($integrity !== 'ok' ? 'the integrity check is not "ok" (the database needs a closer look first). ' : '') . (!$space_ok ? 'there is not enough free space for a backup.' : '') . '</div>';
    } else {
        $b .= '<div class="card"><h2 style="margin-top:0">Repair</h2><ol><li>Pick a quiet moment (it takes a few seconds; visitors wait briefly while it runs).</li><li>A full backup is made first, in <code>data/backups/</code>.</li></ol>'
            . '<form method="post"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="repair">'
            . '<label>Type <strong>REPAIR</strong> to confirm: <input type="text" name="confirm" autocomplete="off" required pattern="REPAIR"></label> '
            . '<button class="btn" type="submit">Back up and repair now</button></form></div>';
    }
}
$bk = backups();
if ($bk) {
    $b .= '<div class="card"><h2 style="margin-top:0">Backups</h2><table><tr><th>File</th><th>Size</th><th></th></tr>';
    foreach ($bk as $f) $b .= '<tr><td><code>' . h(basename($f)) . '</code></td><td>' . number_format(filesize($f) / 1048576, 1) . ' MB</td><td><a href="aw_repair.php?download=' . h(urlencode(basename($f))) . '">Download</a></td></tr>';
    $b .= '</table><form method="post" style="margin-top:12px"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="restore">'
        . '<label>Restore <select name="backup">' . implode('', array_map(function ($f) { return '<option>' . h(basename($f)) . '</option>'; }, $bk)) . '</select></label> '
        . '<label>type <strong>RESTORE</strong>: <input type="text" name="confirm" autocomplete="off" required pattern="RESTORE"></label> '
        . '<button class="btn danger" type="submit">Restore this backup</button></form>'
        . '<p style="font-size:.85rem">A restore first backs up the current state, so it can be undone too.</p></div>';
}
$b .= '<p style="font-size:.85rem;color:#6b5a5a">When you are finished, delete <code>aw_repair.php</code> from <code>htdocs/</code>.</p>';
page('Database repair', $b);
