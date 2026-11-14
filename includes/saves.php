<?php
/**
 * F1: universal saves and collections (PROGRAM_PLAN.md Phase 4, wish F).
 *
 * One "Save" control on poems, Stories & Help posts, blog posts and reflections, videos, books,
 * Bible verses and reading groups. A save can sit in one of the member's collections (or none).
 *
 *   saves(id, user_id, item_type, item_key, collection_id, created_at)  UNIQUE(user_id, item_type, item_key)
 *   collections(id, user_id, name, position, created_at)
 *
 * item_key is text: an id for most things; for a verse it is "VERSION|Book|chapter|verse".
 * What a save points to is worked out when shown (aw_save_resolve), so a removed or private item
 * simply drops out of the list; nothing about it is copied into the saves table.
 * Nothing here throws; every function returns a value and logs failures.
 */

const AW_SAVE_TYPES = [
    'poem'  => ['Poem', 'fa-feather'],
    'story' => ['Story', 'fa-seedling'],
    'post'  => ['Reflection', 'fa-pen-nib'],
    'video' => ['Video', 'fa-circle-play'],
    'book'  => ['Book', 'fa-book'],
    'verse' => ['Verse', 'fa-bible'],
    'group' => ['Reading group', 'fa-user-group'],
];
const AW_SAVE_LIMITS = ['collections' => 50, 'name' => 60, 'saves' => 2000];

function aw_save_tables(PDO $db) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS collections (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL,
                   position INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_collections_user ON collections (user_id, position)");
        $db->exec("CREATE TABLE IF NOT EXISTS saves (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, item_type TEXT NOT NULL,
                   item_key TEXT NOT NULL, collection_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, item_type, item_key))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_saves_user ON saves (user_id, collection_id, created_at)");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('save tables: ' . $e->getMessage());
        return $ok = false;
    }
}

/** A clean item key, or '' when it is not valid for the type. */
function aw_save_key($type, $key) {
    $key = trim((string)$key);
    if (!isset(AW_SAVE_TYPES[$type])) return '';
    if ($type === 'verse') {
        $p = explode('|', $key);
        if (count($p) !== 4 || !preg_match('/^[A-Z]{2,6}$/', $p[0]) || !preg_match('/^[1-3]?\s?[A-Za-z ]{2,30}$/', $p[1]) || (int)$p[2] < 1 || (int)$p[3] < 1) return '';
        return $p[0] . '|' . trim($p[1]) . '|' . (int)$p[2] . '|' . (int)$p[3];
    }
    return ctype_digit($key) && (int)$key > 0 ? (string)(int)$key : '';
}

/**
 * What a saved item is, if the member may still see it:
 * ['type', 'key', 'title', 'sub', 'link', 'image', 'label', 'icon'] or null.
 */
function aw_save_resolve(PDO $db, $type, $key) {
    $key = aw_save_key($type, $key);
    if ($key === '') return null;
    [$label, $icon] = AW_SAVE_TYPES[$type];
    $u = SITE_URL;
    $img = function ($p) use ($u) { $p = trim((string)$p); return $p === '' ? '' : (preg_match('#^https?://#i', $p) ? $p : $u . '/' . ltrim($p, '/')); };
    $out = ['type' => $type, 'key' => $key, 'label' => $label, 'icon' => $icon, 'sub' => '', 'image' => ''];
    try {
        switch ($type) {
            case 'poem':
                $st = $db->prepare("SELECT id, title, image_path, cover_image FROM poems WHERE id = ? AND id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')");
                $st->execute([(int)$key]);
                if (!$r = $st->fetch(PDO::FETCH_ASSOC)) return null;
                return $out + ['title' => $r['title'], 'link' => $u . '/poem_view.php?id=' . (int)$r['id'], 'image' => $img($r['cover_image'] ?: $r['image_path'])] ;
            case 'story':
                if (!function_exists('aw_cp_get')) require_once __DIR__ . '/community_posts.php';
                $p = aw_cp_get($db, (int)$key);
                if (!$p || !aw_cp_can_view($p) || $p['status'] === 'removed') return null;
                $out['sub'] = AW_CP_TYPES[$p['type']] ?? 'Post';
                return $out + ['title' => $p['title'], 'link' => $u . '/story.php?id=' . (int)$p['id']];
            case 'post':
                $st = $db->prepare("SELECT id, title, slug, featured_image FROM blog_posts WHERE id = ? AND COALESCE(status, 'published') = 'published'");
                $st->execute([(int)$key]);
                if (!$r = $st->fetch(PDO::FETCH_ASSOC)) return null;
                return $out + ['title' => $r['title'], 'link' => $u . '/blog_post.php?slug=' . urlencode((string)$r['slug']), 'image' => $img($r['featured_image'])];
            case 'video':
                $st = $db->prepare("SELECT id, title, thumbnail FROM videos WHERE id = ?");
                $st->execute([(int)$key]);
                if (!$r = $st->fetch(PDO::FETCH_ASSOC)) return null;
                return $out + ['title' => $r['title'], 'link' => $u . '/video_watch.php?id=' . (int)$r['id'], 'image' => $img($r['thumbnail'])];
            case 'book':
                $st = $db->prepare("SELECT id, title, author, cover_path FROM books WHERE id = ?");
                $st->execute([(int)$key]);
                if (!$r = $st->fetch(PDO::FETCH_ASSOC)) return null;
                $out['sub'] = (string)$r['author'];
                return $out + ['title' => $r['title'], 'link' => $u . '/book.php?id=' . (int)$r['id'], 'image' => $img($r['cover_path'])];
            case 'group':
                $st = $db->prepare("SELECT id, name, is_private FROM reading_groups WHERE id = ?");
                $st->execute([(int)$key]);
                if (!$r = $st->fetch(PDO::FETCH_ASSOC)) return null;
                return $out + ['title' => $r['name'], 'link' => $u . '/group.php?id=' . (int)$r['id']];
            case 'verse':
                [$ver, $book, $ch, $v] = explode('|', $key);
                $text = '';
                $bookName = $book;
                if (!function_exists('aw_bible_chapter') && is_file(__DIR__ . '/bible_versions.php')) require_once __DIR__ . '/bible_versions.php';
                if (function_exists('aw_bible_chapter') && ($c = aw_bible_chapter($ver, $book, (int)$ch))) {
                    $bookName = $c['book_name'];
                    foreach ($c['verses'] as $row) if ((int)$row['v'] === (int)$v) { $text = trim(strip_tags((string)$row['text'])); break; }
                }
                if ($text === '') return null;
                $out['sub'] = $text;
                return $out + ['title' => $bookName . ' ' . (int)$ch . ':' . (int)$v . ' (' . $ver . ')',
                               'link' => $u . '/bible_reader.php?v=' . urlencode($ver) . '&b=' . urlencode($book) . '&c=' . (int)$ch . '&vs=' . (int)$v];
        }
    } catch (Throwable $e) {
        error_log('save resolve: ' . $e->getMessage());
    }
    return null;
}

function aw_save_get(PDO $db, $uid, $type, $key) {
    if (!aw_save_tables($db)) return null;
    try {
        $st = $db->prepare("SELECT * FROM saves WHERE user_id = ? AND item_type = ? AND item_key = ?");
        $st->execute([(int)$uid, (string)$type, (string)$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('save get: ' . $e->getMessage()); return null; }
}

function aw_collection_owned(PDO $db, $uid, $cid) {
    if (!$cid) return true;
    try {
        $st = $db->prepare("SELECT 1 FROM collections WHERE id = ? AND user_id = ?");
        $st->execute([(int)$cid, (int)$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Save (or move) an item into a collection (0 = no collection). Returns ['ok', 'message', 'saved']. */
function aw_save_put(PDO $db, $uid, $type, $key, $cid = 0) {
    $key = aw_save_key($type, $key);
    if (!$uid || $key === '' || !aw_save_tables($db)) return ['ok' => false, 'message' => 'This cannot be saved.', 'saved' => false];
    if (!aw_save_resolve($db, $type, $key)) return ['ok' => false, 'message' => 'This is no longer available.', 'saved' => false];
    if (!aw_collection_owned($db, $uid, $cid)) return ['ok' => false, 'message' => 'That collection was not found.', 'saved' => false];
    try {
        $n = $db->prepare("SELECT COUNT(*) FROM saves WHERE user_id = ?");
        $n->execute([(int)$uid]);
        if ((int)$n->fetchColumn() >= AW_SAVE_LIMITS['saves'] && !aw_save_get($db, $uid, $type, $key)) return ['ok' => false, 'message' => 'Your library is full. Please remove some saves first.', 'saved' => false];
        $db->prepare("INSERT INTO saves (user_id, item_type, item_key, collection_id) VALUES (?, ?, ?, ?)
                      ON CONFLICT(user_id, item_type, item_key) DO UPDATE SET collection_id = excluded.collection_id")
           ->execute([(int)$uid, $type, $key, $cid ? (int)$cid : null]);
        return ['ok' => true, 'message' => 'Saved to your library.', 'saved' => true];
    } catch (Throwable $e) { error_log('save put: ' . $e->getMessage()); return ['ok' => false, 'message' => 'That was not saved. Please try again.', 'saved' => false]; }
}

function aw_save_remove(PDO $db, $uid, $type, $key) {
    if (!aw_save_tables($db)) return false;
    try {
        $db->prepare("DELETE FROM saves WHERE user_id = ? AND item_type = ? AND item_key = ?")->execute([(int)$uid, (string)$type, (string)$key]);
        return true;
    } catch (Throwable $e) { error_log('save remove: ' . $e->getMessage()); return false; }
}

function aw_collections(PDO $db, $uid) {
    if (!$uid || !aw_save_tables($db)) return [];
    try {
        $st = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM saves s WHERE s.collection_id = c.id) AS n FROM collections c WHERE c.user_id = ? ORDER BY c.position, c.id");
        $st->execute([(int)$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('collections: ' . $e->getMessage()); return []; }
}

/** Create a collection; returns its id or 0 with $err set. */
function aw_collection_create(PDO $db, $uid, $name, &$err = '') {
    $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$name)));
    if ($name === '') { $err = 'Please give the collection a name.'; return 0; }
    if (mb_strlen($name) > AW_SAVE_LIMITS['name']) { $err = 'Please keep the name under ' . AW_SAVE_LIMITS['name'] . ' characters.'; return 0; }
    if (!aw_save_tables($db)) { $err = 'Collections are not available right now.'; return 0; }
    try {
        $all = aw_collections($db, $uid);
        if (count($all) >= AW_SAVE_LIMITS['collections']) { $err = 'You have ' . AW_SAVE_LIMITS['collections'] . ' collections, the most there can be.'; return 0; }
        foreach ($all as $c) if (mb_strtolower($c['name']) === mb_strtolower($name)) return (int)$c['id'];
        $db->prepare("INSERT INTO collections (user_id, name, position) VALUES (?, ?, ?)")->execute([(int)$uid, $name, count($all)]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) { error_log('collection create: ' . $e->getMessage()); $err = 'The collection was not created.'; return 0; }
}

function aw_collection_rename(PDO $db, $uid, $cid, $name) {
    $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$name)));
    if ($name === '' || mb_strlen($name) > AW_SAVE_LIMITS['name']) return false;
    try {
        $st = $db->prepare("UPDATE collections SET name = ? WHERE id = ? AND user_id = ?");
        $st->execute([$name, (int)$cid, (int)$uid]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('collection rename: ' . $e->getMessage()); return false; }
}

/** Delete a collection. Its saves stay in the library, outside any collection. */
function aw_collection_delete(PDO $db, $uid, $cid) {
    try {
        if (!aw_collection_owned($db, $uid, $cid)) return false;
        $db->prepare("UPDATE saves SET collection_id = NULL WHERE collection_id = ? AND user_id = ?")->execute([(int)$cid, (int)$uid]);
        $db->prepare("DELETE FROM collections WHERE id = ? AND user_id = ?")->execute([(int)$cid, (int)$uid]);
        return true;
    } catch (Throwable $e) { error_log('collection delete: ' . $e->getMessage()); return false; }
}

/** Saved items, newest first, resolved; $cid: null = all, 0 = not in a collection, n = that collection. */
function aw_saves_list(PDO $db, $uid, $cid = null, $type = '') {
    if (!$uid || !aw_save_tables($db)) return [];
    $sql = "SELECT * FROM saves WHERE user_id = ?";
    $args = [(int)$uid];
    if ($cid === 0) $sql .= " AND collection_id IS NULL";
    elseif ($cid !== null) { $sql .= " AND collection_id = ?"; $args[] = (int)$cid; }
    if ($type !== '' && isset(AW_SAVE_TYPES[$type])) { $sql .= " AND item_type = ?"; $args[] = $type; }
    $out = [];
    try {
        $st = $db->prepare($sql . " ORDER BY created_at DESC, id DESC LIMIT 500");
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $r = aw_save_resolve($db, $s['item_type'], $s['item_key']);
            if ($r) $out[] = $r + ['save_id' => (int)$s['id'], 'collection_id' => (int)$s['collection_id'], 'saved_at' => $s['created_at']];
        }
    } catch (Throwable $e) { error_log('saves list: ' . $e->getMessage()); }
    return $out;
}

/**
 * The Save control. Without JavaScript it is a form that saves to the library; with it, a small
 * popover lets the member pick or create a collection. Guests get a sign-in link.
 */
function aw_save_button($type, $key, array $opts = []) {
    $key = aw_save_key($type, $key);
    if ($key === '') return '';
    $u = SITE_URL;
    $cls = 'aw-save' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) {
        $back = $opts['back'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
        return '<a class="' . $cls . '" href="' . htmlspecialchars($u . '/login.php?redirect=' . urlencode($back)) . '"><i class="far fa-bookmark" aria-hidden="true"></i> <span>Save</span></a>';
    }
    $db = $GLOBALS['db'] ?? null;
    $saved = $db instanceof PDO ? aw_save_get($db, $uid, $type, $key) : null;
    $tok = function_exists('generate_csrf_token') ? generate_csrf_token() : (string)($_SESSION['csrf_token'] ?? '');
    return '<form class="' . $cls . '" method="post" action="' . htmlspecialchars($u . '/save.php') . '" data-save data-type="' . htmlspecialchars($type) . '" data-key="' . htmlspecialchars($key) . '">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($tok) . '"><input type="hidden" name="type" value="' . htmlspecialchars($type) . '"><input type="hidden" name="key" value="' . htmlspecialchars($key) . '">'
        . '<input type="hidden" name="action" value="' . ($saved ? 'remove' : 'save') . '"><input type="hidden" name="back" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') . '">'
        . '<button type="submit" class="aw-save-btn" aria-pressed="' . ($saved ? 'true' : 'false') . '" aria-haspopup="dialog"><i class="' . ($saved ? 'fas' : 'far') . ' fa-bookmark" aria-hidden="true"></i> <span>' . ($saved ? 'Saved' : 'Save') . '</span></button>'
        . '</form>';
}

/** Styles and the popover script, once per page. */
function aw_save_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    $u = SITE_URL;
    return '<link rel="stylesheet" href="' . $u . '/assets/css/aw-saves.css?v=1"><script src="' . $u . '/assets/js/aw-saves.js?v=2" defer></script>';
}
