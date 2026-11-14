<?php
/**
 * Manage poems: the list, and one full-page editor for adding and editing (P-ED).
 *
 * Every part of a poem that the public page shows can be edited here:
 *   title, purpose (intro), the poem text, the author line, the date it was written,
 *   the cover image (replace or remove), the recording (replace or remove) and whether it is live.
 *
 * "Date written" (poems.written_on, YYYY-MM-DD, optional) is for poems written long before they were
 * added to the site. It is the date readers see; the date the poem was added (created_at) still
 * decides what counts as "latest".
 *
 * All changes are POST with a CSRF token (publish, unpublish and delete used to be plain links).
 * Errors are logged and shown as a message; nothing is thrown at the admin.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/mail_helper.php';
require_once '../includes/upload.php'; // R10: shared upload limits

aw_require('manage_content'); // K1

const AW_POEM_DEFAULT_AUTHOR = 'Angella Bottoman';
define('AW_POEMS_ADMIN', true); // the view partials refuse to run on their own

// ===== HELPERS =====================================================================

/** Make sure the optional columns exist (added once; existing rows keep NULL). Never throws. */
function aw_poem_columns(PDO $db) {
    static $done = false;
    if ($done) return true;
    try {
        $cols = array_column($db->query("PRAGMA table_info(poems)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('written_on', $cols, true)) $db->exec("ALTER TABLE poems ADD COLUMN written_on TEXT");
        if (!in_array('author', $cols, true)) $db->exec("ALTER TABLE poems ADD COLUMN author TEXT");
        $done = true;
        return true;
    } catch (Throwable $e) {
        error_log('manage_poems columns: ' . $e->getMessage());
        return false;
    }
}

// F123: poems created without a poem_status row count as published; set the status, inserting the row.
function aw_set_poem_status(PDO $db, array $ids, $status) {
    $status = $status === 'published' ? 'published' : 'draft';
    $upd = $db->prepare("UPDATE poem_status SET status = ? WHERE poem_id = ?");
    $ins = $db->prepare("INSERT INTO poem_status (poem_id, status) VALUES (?, ?)");
    foreach ($ids as $pid) {
        $upd->execute([$status, (int)$pid]);
        if ($upd->rowCount() === 0) $ins->execute([(int)$pid, $status]);
    }
}

// F130: remove reactions (on the poem and its comments), reads and view logs with the poem.
function aw_delete_poems(PDO $db, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $files = $db->prepare("SELECT image_path, audio_path FROM poems WHERE id IN ($ph)");
    $files->execute($ids);
    $paths = [];
    foreach ($files->fetchAll(PDO::FETCH_ASSOC) as $f) { $paths[] = $f['image_path']; $paths[] = $f['audio_path']; }
    $db->beginTransaction();
    $db->prepare("DELETE FROM reactions WHERE target_type = 'comment' AND target_id IN (SELECT id FROM reviews WHERE target_type = 'poem' AND target_id IN ($ph))")->execute($ids);
    $db->prepare("DELETE FROM reactions WHERE target_type = 'poem' AND target_id IN ($ph)")->execute($ids);
    $db->prepare("DELETE FROM poem_reads WHERE poem_id IN ($ph)")->execute($ids);
    $db->prepare("DELETE FROM view_logs WHERE target_type = 'poem' AND target_id IN ($ph)")->execute($ids);
    $db->prepare("DELETE FROM poem_status WHERE poem_id IN ($ph)")->execute($ids);
    $db->prepare("DELETE FROM reviews WHERE target_type = 'poem' AND target_id IN ($ph)")->execute($ids);
    $st = $db->prepare("DELETE FROM poems WHERE id IN ($ph)");
    $st->execute($ids);
    $n = $st->rowCount();
    $db->commit();
    foreach ($paths as $p) aw_poem_unlink($p);
    return $n;
}

/** Delete an uploaded poem file, but only inside assets/uploads/poems. */
function aw_poem_unlink($path) {
    $path = trim((string)$path);
    if ($path === '' || strpos($path, 'assets/uploads/poems/') !== 0 || strpos($path, '..') !== false) return;
    $full = dirname(__DIR__) . '/' . $path;
    if (is_file($full)) @unlink($full);
}

/** Validate an optional YYYY-MM-DD date: '' (none), a valid date, or false (invalid). */
function aw_poem_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$d || $d->format('Y-m-d') !== $raw) return false;
    if ((int)$d->format('Y') < 1900 || $d > new DateTime('tomorrow')) return false;
    return $raw;
}

function aw_flash($msg, $type = 'success') { $_SESSION['flash_message'] = $msg; $_SESSION['flash_type'] = $type; }
function aw_back($query = '') { header('Location: ' . SITE_URL . '/admin/manage_poems.php' . ($query !== '' ? '?' . $query : '')); exit; }

aw_poem_columns($db);

// ===== OLD LINKS ===================================================================
// The old GET links (?publish=, ?unpublish=, ?delete=) changed data without a token. They now only
// open the list; nothing changes without a POST.
if (isset($_GET['publish']) || isset($_GET['unpublish']) || isset($_GET['delete'])) aw_back();

// R10: a request over the server's post limit arrives with no fields
if (($aw_overflow = aw_upload_post_overflow()) !== '') {
    aw_flash($aw_overflow, 'error');
    aw_back(isset($_GET['edit']) ? 'edit=' . (int)$_GET['edit'] : 'new=1');
}

// ===== POST ACTIONS ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        aw_flash('Your session expired, so nothing was changed. Please try again.', 'error');
        aw_back();
    }

    // --- one row's buttons in the list (publish / draft / delete) ---
    if (isset($_POST['row_action'])) {
        [$act, $rid] = array_pad(explode(':', (string)$_POST['row_action'], 2), 2, 0);
        $rid = (int)$rid;
        try {
            if ($rid > 0 && ($act === 'publish' || $act === 'draft')) {
                aw_set_poem_status($db, [$rid], $act === 'publish' ? 'published' : 'draft');
                aw_flash($act === 'publish' ? 'Published. The poem is now live.' : 'Moved to draft. The poem is no longer shown on the site.');
            } elseif ($rid > 0 && $act === 'delete') {
                aw_delete_poems($db, [$rid]);
                aw_flash('Poem deleted.');
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('manage_poems row action: ' . $e->getMessage());
            aw_flash('That did not work. Nothing was changed.', 'error');
        }
        aw_back(isset($_POST['return_page']) ? 'page=' . max(1, (int)$_POST['return_page']) : '');
    }

    // --- bulk actions ---
    if (isset($_POST['bulk_action'])) {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $action = (string)$_POST['bulk_action'];
        if (!$ids || !in_array($action, ['publish', 'draft', 'delete'], true)) {
            aw_flash('Choose an action and at least one poem.', 'error');
            aw_back();
        }
        try {
            if ($action === 'delete') {
                $n = aw_delete_poems($db, $ids);
                aw_flash($n . ' poem' . ($n === 1 ? '' : 's') . ' deleted.');
            } else {
                aw_set_poem_status($db, $ids, $action === 'publish' ? 'published' : 'draft');
                aw_flash(count($ids) . ' poem' . (count($ids) === 1 ? '' : 's') . ($action === 'publish' ? ' published.' : ' moved to draft.'));
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('manage_poems bulk: ' . $e->getMessage());
            aw_flash('That did not work. Nothing was changed.', 'error');
        }
        aw_back();
    }

    // --- save (add or edit) ---
    if (isset($_POST['save_poem'])) {
        $id = (int)($_POST['poem_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $intro = trim((string)($_POST['intro'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $author = trim(strip_tags((string)($_POST['author'] ?? '')));
        $written = aw_poem_date($_POST['written_on'] ?? '');
        $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $back = $id > 0 ? 'edit=' . $id : 'new=1';

        $error = null;
        if ($title === '') $error = 'Please give the poem a title.';
        elseif (mb_strlen($title) > 200) $error = 'The title is too long (200 characters at most).';
        elseif (trim(strip_tags($content)) === '') $error = 'Please write the poem.';
        elseif (mb_strlen($author) > 120) $error = 'The author name is too long (120 characters at most).';
        elseif ($written === false) $error = 'The date written is not a valid date (and cannot be in the future).';

        $old = null;
        if (!$error && $id > 0) {
            $q = $db->prepare("SELECT id, image_path, audio_path FROM poems WHERE id = ?");
            $q->execute([$id]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            if (!$old) { aw_flash('That poem no longer exists.', 'error'); aw_back(); }
        }

        // R10: every upload goes through the shared helper (type by content, size, image resize)
        $image_path = null; $audio_path = null; $new_files = [];
        foreach ([['image', 'image', 'Image'], ['image_fallback', 'image', 'Image'],
                  ['audio', 'audio', 'Recording'], ['audio_recording', 'audio', 'Recording']] as [$f, $kind, $label]) {
            if ($error) break;
            if ($kind === 'image' ? $image_path !== null : $audio_path !== null) continue;
            $up = aw_upload_store($_FILES[$f] ?? null, $kind, 'assets/uploads/poems', ['prefix' => 'poem_']);
            if (!empty($up['ok'])) {
                if ($kind === 'image') $image_path = $up['path']; else $audio_path = $up['path'];
                $new_files[] = $up['path'];
            } elseif (empty($up['empty'])) {
                $error = $label . ': ' . $up['error'];
            }
        }
        if ($error) {
            foreach ($new_files as $p) aw_poem_unlink($p);
            $_SESSION['poem_draft'] = compact('title', 'intro', 'content', 'author', 'status') + ['written_on' => (string)($_POST['written_on'] ?? '')];
            aw_flash($error, 'error');
            aw_back($back);
        }

        $remove_image = !empty($_POST['remove_image']) && $image_path === null;
        $remove_audio = !empty($_POST['remove_audio']) && $audio_path === null;
        $author_db = ($author === '' || $author === AW_POEM_DEFAULT_AUTHOR) ? null : $author;
        $written_db = $written === '' ? null : $written;

        try {
            $db->beginTransaction();
            if ($old) {
                $img = $image_path ?? ($remove_image ? null : $old['image_path']);
                $aud = $audio_path ?? ($remove_audio ? null : $old['audio_path']);
                $db->prepare("UPDATE poems SET title = ?, intro = ?, content = ?, author = ?, written_on = ?,
                                  image_path = ?, audio_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$title, $intro, $content, $author_db, $written_db, $img, $aud, $id]);
                aw_set_poem_status($db, [$id], $status);
                $db->commit();
                // old files go once the database no longer points at them
                if ($img !== $old['image_path']) aw_poem_unlink($old['image_path']);
                if ($aud !== $old['audio_path']) aw_poem_unlink($old['audio_path']);
                aw_flash($status === 'published' ? 'Saved. The poem is live.' : 'Saved as a draft.');
            } else {
                $db->prepare("INSERT INTO poems (title, intro, content, author, written_on, image_path, audio_path, view_count, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
                   ->execute([$title, $intro, $content, $author_db, $written_db, $image_path, $audio_path]);
                $id = (int)$db->lastInsertId();
                aw_set_poem_status($db, [$id], $status);
                $db->commit();
                aw_flash($status === 'published' ? 'Poem added and published.' : 'Poem added as a draft. Publish it when it is ready.');
            }
            unset($_SESSION['poem_draft']);
            aw_back('edit=' . $id);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($new_files as $p) aw_poem_unlink($p);
            error_log('manage_poems save: ' . $e->getMessage());
            $_SESSION['poem_draft'] = compact('title', 'intro', 'content', 'author', 'status') + ['written_on' => (string)($_POST['written_on'] ?? '')];
            aw_flash('The poem could not be saved. Please try again.', 'error');
            aw_back($back);
        }
    }
    aw_back();
}

// ===== FLASH =======================================================================
$flash_message = (string)($_SESSION['flash_message'] ?? '');
$flash_type = (string)($_SESSION['flash_type'] ?? 'success');
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// ===== EDITOR VIEW =================================================================
$editing = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$creating = isset($_GET['new']) || (($_GET['action'] ?? '') === 'new');
$poem = null;
if ($editing > 0) {
    $q = $db->prepare("SELECT p.*, COALESCE(s.status, 'published') AS status FROM poems p LEFT JOIN poem_status s ON s.poem_id = p.id WHERE p.id = ?");
    $q->execute([$editing]);
    $poem = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$poem) { aw_flash('That poem no longer exists.', 'error'); aw_back(); }
}
if ($poem || $creating) {
    $draft = $_SESSION['poem_draft'] ?? null; // what was typed before a failed save
    unset($_SESSION['poem_draft']);
    $f = [
        'id' => (int)($poem['id'] ?? 0),
        'title' => $draft['title'] ?? (string)($poem['title'] ?? ''),
        'intro' => $draft['intro'] ?? (string)($poem['intro'] ?? ''),
        'content' => $draft['content'] ?? (string)($poem['content'] ?? ''),
        'author' => $draft['author'] ?? (string)(($poem['author'] ?? '') !== '' ? $poem['author'] : AW_POEM_DEFAULT_AUTHOR),
        'written_on' => $draft['written_on'] ?? (string)($poem['written_on'] ?? ''),
        'status' => $draft['status'] ?? (string)($poem['status'] ?? 'draft'),
        'image' => (string)($poem['image_path'] ?? ''),
        'audio' => (string)($poem['audio_path'] ?? ''),
        'created_at' => (string)($poem['created_at'] ?? ''),
        'updated_at' => (string)($poem['updated_at'] ?? ''),
    ];
    $pageTitle = $f['id'] ? 'Edit: ' . $f['title'] : 'New poem';
    require_once '../includes/header.php';
    require __DIR__ . '/partials/poem_editor_view.php';
    aw_upload_script();
    require_once '../includes/footer.php';
    exit;
}

// ===== LIST VIEW ===================================================================
$search = trim((string)($_GET['search'] ?? ''));
$sql = "SELECT p.id, p.title, p.intro, p.image_path, p.audio_path, p.view_count, p.created_at, p.written_on,
               COALESCE(s.status, 'published') AS status
        FROM poems p LEFT JOIN poem_status s ON p.id = s.poem_id";
$params = [];
if ($search !== '') {
    $sql .= " WHERE p.title LIKE ? OR p.intro LIKE ? OR p.content LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}
require_once __DIR__ . '/../includes/pagination.php';
[$page, $per_page] = aw_page(20);
$cst = $db->prepare("SELECT COUNT(*) FROM (" . $sql . ")");
$cst->execute($params);
$poems_total = (int)$cst->fetchColumn();
[$page, $offset] = aw_clamp($poems_total, $page, $per_page);
$st = $db->prepare($sql . " ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?");
$st->execute(array_merge($params, [$per_page, $offset]));
$poems = $st->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Poems';
require_once '../includes/header.php';
require __DIR__ . '/partials/poem_list_view.php';
require_once '../includes/footer.php';
