<?php
/**
 * Member books (PROGRAM_PLAN.md wish H, section 5.2).
 *
 * A member can share any book that will help others on the site and fits the community's goals,
 * whoever wrote it, in one of two ways:
 *   - Upload the book file, when it may be shared: they wrote it, it is in the public domain, it was
 *     released free to share (for example Creative Commons, or a free ministry e-book), or its author
 *     or publisher gave permission. The member says which, with the licence or permission;
 *   - Suggest the book without a file (any other book, such as one they bought): it goes on the
 *     shelf with why it helps and where to get it, so members read their own copies together, in a
 *     reading group for example. A book by someone else is never published here without the right
 *     to share it: that would be copyright infringement, and the host suspends sites for it.
 *
 *   1. they upload the file (PDF, EPUB, DOCX, ODT, RTF or TXT, through the shared upload helper,
 *      with its size limit and the member's quota), or suggest the book, and say why it helps;
 *   2. it waits in a review queue; nobody else can read it, and it is in no list, until an admin
 *      approves it (admin/member_books.php). The admin can read it first and must give a reason
 *      when declining;
 *   3. once approved it becomes a free book on the "Community books" shelf, prepared for the reader
 *      automatically and read on the site only, like every other book (no download);
 *   4. it can be taken down at any time: by the member ("Remove my book"), or by the team, for
 *      example after a concern sent through "Report a concern" on the book page. Taking it down
 *      removes the book, its reader text and its files everywhere.
 *
 * Submissions live in member_books; an approved one is copied into books (books.submitted_by holds
 * the member), so every existing list of books only ever sees approved ones.
 *
 * Every function returns a value and logs on failure; nothing throws.
 */

require_once __DIR__ . '/book_extract.php';
require_once __DIR__ . '/book_cover.php'; // covers are made automatically

const AW_MB_MAX_WAITING = 3;     // books waiting for review at once, per member
const AW_MB_MAX_PER_30D = 5;     // books submitted in 30 days, per member
/** Why a book file may be shared: [label, help, needs details]. */
const AW_MB_RIGHTS = [
    'own'           => ['I wrote it', 'You are the author, or you hold its rights.', false],
    'public_domain' => ['It is in the public domain', 'Its copyright has ended, as for classics whose authors died long ago (for example older hymn books, or Spurgeon and Bunyan).', true],
    'free_licence'  => ['It was released free to share', 'The author or publisher lets anyone share it: a Creative Commons licence, or a free e-book given away to share.', true],
    'permission'    => ['The author or publisher gave permission', 'You have their permission to share it on AngelWrites.', true],
];
const AW_MB_ATTEST = [
    'rights' => 'I may share this book in the way I chose: I upload a file only when sharing it is allowed, as I explained.',
    'rules'  => 'It fits the community guidelines and will help others here.',
    'online' => 'I understand the team reviews it first, and that shared books are read on AngelWrites only (members cannot download them).',
];

function aw_mb_tables(PDO $db) {
    static $done = false;
    if ($done) return true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS member_books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            author_name TEXT NOT NULL,
            description TEXT,
            cover_path TEXT,
            file_path TEXT,
            file_type TEXT,
            file_size INTEGER DEFAULT 0,
            words INTEGER DEFAULT 0,
            pages INTEGER DEFAULT 0,
            attest_text TEXT,
            attest_at DATETIME,
            status TEXT NOT NULL DEFAULT 'waiting',
            review_note TEXT,
            reviewed_by INTEGER,
            reviewed_at DATETIME,
            book_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_member_books_status ON member_books(status, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_member_books_user ON member_books(user_id, created_at)");
        $mcols = [];
        foreach ($db->query("PRAGMA table_info(member_books)") as $c) $mcols[$c['name']] = true;
        foreach (['kind' => "TEXT NOT NULL DEFAULT 'file'", 'rights' => 'TEXT', 'rights_detail' => 'TEXT', 'why' => 'TEXT', 'link' => 'TEXT'] as $col => $def) {
            if (!isset($mcols[$col])) $db->exec("ALTER TABLE member_books ADD COLUMN $col $def");
        }
        $cols = [];
        foreach ($db->query("PRAGMA table_info(books)") as $c) $cols[$c['name']] = true;
        if ($cols && !isset($cols['submitted_by'])) $db->exec("ALTER TABLE books ADD COLUMN submitted_by INTEGER");
        $done = true;
        return true;
    } catch (Throwable $e) {
        error_log('member books tables: ' . $e->getMessage());
        return false;
    }
}

/** Plain words for each status. */
function aw_mb_status_label($s) {
    return ['waiting' => 'Waiting for review', 'published' => 'On the site', 'declined' => 'Not accepted',
            'withdrawn' => 'Withdrawn', 'removed' => 'Taken down'][$s] ?? $s;
}

function aw_mb_get(PDO $db, $id) {
    aw_mb_tables($db);
    try {
        $st = $db->prepare("SELECT m.*, u.username, u.display_name, u.email FROM member_books m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('member book get: ' . $e->getMessage());
        return null;
    }
}

function aw_mb_mine(PDO $db, $uid) {
    aw_mb_tables($db);
    try {
        $st = $db->prepare("SELECT * FROM member_books WHERE user_id = ? AND status <> 'withdrawn' ORDER BY created_at DESC, id DESC LIMIT 50");
        $st->execute([(int)$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('member books mine: ' . $e->getMessage());
        return [];
    }
}

function aw_mb_waiting_count(PDO $db) {
    aw_mb_tables($db);
    try { return (int)$db->query("SELECT COUNT(*) FROM member_books WHERE status = 'waiting'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** '' when the member may share a book now, otherwise why not. */
function aw_mb_can_submit(PDO $db, $uid) {
    if (!$uid) return 'Please log in to share a book.';
    if (function_exists('aw_cp_restriction')) {
        $r = (string)aw_cp_restriction($db, $uid);
        if ($r !== '') return str_replace('Posting is paused', 'Sharing is paused', $r);
    }
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM member_books WHERE user_id = ? AND status = 'waiting'");
        $st->execute([(int)$uid]);
        if ((int)$st->fetchColumn() >= AW_MB_MAX_WAITING) return 'You have ' . AW_MB_MAX_WAITING . ' books waiting for review. You can share another once the team has looked at them.';
        $st = $db->prepare("SELECT COUNT(*) FROM member_books WHERE user_id = ? AND created_at >= datetime('now', '-30 days')");
        $st->execute([(int)$uid]);
        if ((int)$st->fetchColumn() >= AW_MB_MAX_PER_30D) return 'You have shared ' . AW_MB_MAX_PER_30D . ' books in the last 30 days, the most for now. Please try again later.';
    } catch (Throwable $e) {
        error_log('member books limit: ' . $e->getMessage());
    }
    return '';
}

/** Remove stored files ('' and missing are fine). */
function aw_mb_unlink(...$paths) {
    foreach ($paths as $p) {
        $abs = aw_book_file_abs((string)$p);
        if ($abs !== '') @unlink($abs);
    }
}

/** Tell owners and admins that a book is waiting. */
function aw_mb_notify_team(PDO $db, $mb_id, $title) {
    if (!function_exists('awNotify')) return;
    try {
        foreach ($db->query("SELECT id FROM users WHERE role IN ('owner', 'admin')") as $r) {
            awNotify($db, (int)$r['id'], 'member_book', 'A member book is waiting for review', '"' . $title . '"', SITE_URL . '/admin/member_books.php?id=' . (int)$mb_id);
        }
    } catch (Throwable $e) {
        error_log('member books notify team: ' . $e->getMessage());
    }
}

/**
 * A member shares a book. $in: title, author_name, description, attest (array of keys).
 * $file: the book file from $_FILES. ($cover is no longer sent: covers are made automatically.)
 * Returns ['ok' => true, 'id' => n] or ['ok' => false, 'errors' => [field => message]].
 */
function aw_mb_submit(PDO $db, $uid, array $in, $file, $cover = null) {
    aw_mb_tables($db);
    $errors = [];
    $why = aw_mb_can_submit($db, $uid);
    if ($why !== '') return ['ok' => false, 'errors' => ['form' => $why]];

    $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['title'] ?? ''))));
    $author = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['author_name'] ?? ''))));
    $desc = trim(strip_tags((string)($in['description'] ?? '')));
    $why = trim(strip_tags((string)($in['why'] ?? '')));
    $kind = ($in['kind'] ?? '') === 'suggestion' ? 'suggestion' : 'file';
    $rights = (string)($in['rights'] ?? '');
    $detail = trim(strip_tags((string)($in['rights_detail'] ?? '')));
    $link = trim((string)($in['link'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) $errors['title'] = 'Give the book\'s title (up to 160 characters).';
    if ($author === '' || mb_strlen($author) > 100) $errors['author_name'] = 'Add who wrote the book (up to 100 characters).';
    if (mb_strlen($desc) > 2000) $errors['description'] = 'Keep the description to 2,000 characters.';
    if (mb_strlen($why) < 20 || mb_strlen($why) > 1000) $errors['why'] = 'In a sentence or two (20 to 1,000 characters), say how this book will help others here.';
    if ($link !== '' && (!preg_match('#^https?://[^\s<>"]+$#i', $link) || mb_strlen($link) > 300)) $errors['link'] = 'Use a full web address starting with https://, or leave it empty.';
    if ($kind === 'file') {
        if (!isset(AW_MB_RIGHTS[$rights])) $errors['rights'] = 'Choose why this book may be shared, or suggest it without a file.';
        elseif (AW_MB_RIGHTS[$rights][2] && (mb_strlen($detail) < 5 || mb_strlen($detail) > 500)) $errors['rights_detail'] = $rights === 'public_domain' ? 'Say why it is in the public domain (for example when the author died, or where you found it).' : ($rights === 'free_licence' ? 'Name the licence or where the free copy is offered (a web address is best).' : 'Say who gave permission and how (for example an email from the publisher).');
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $errors['book_file'] = 'Choose the book file, or suggest the book without a file.';
    } else {
        $rights = 'suggest';
        $detail = '';
    }
    $attest = (array)($in['attest'] ?? []);
    foreach (array_keys(AW_MB_ATTEST) as $k) if (!in_array($k, $attest, true)) { $errors['attest'] = 'Please tick each of the three statements.'; break; }
    if ($errors) return ['ok' => false, 'errors' => $errors];

    if ($kind === 'suggestion') {
        $cover_path = '';
        if (!empty($cover) && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            require_once __DIR__ . '/upload.php';
            $cu = aw_upload_store($cover, 'image', 'assets/uploads/books', ['prefix' => 'mcover_', 'user_id' => (int)$uid, 'role' => 'member', 'db' => $db]);
            if (empty($cu['ok'])) return ['ok' => false, 'errors' => ['cover' => $cu['error'] ?? 'The cover could not be saved.']];
            $cover_path = $cu['path'];
        }
        try {
            $db->prepare("INSERT INTO member_books (user_id, title, author_name, description, cover_path, kind, rights, why, link, attest_text, attest_at, status)
                          VALUES (?, ?, ?, ?, ?, 'suggestion', 'suggest', ?, ?, ?, CURRENT_TIMESTAMP, 'waiting')")
               ->execute([(int)$uid, $title, $author, $desc, $cover_path, $why, $link, implode("\n", AW_MB_ATTEST)]);
            $id = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log('member book suggest: ' . $e->getMessage());
            aw_mb_unlink($cover_path);
            return ['ok' => false, 'errors' => ['form' => 'Your suggestion could not be saved just now. Please try again.']];
        }
        aw_mb_notify_team($db, $id, $title);
        if (function_exists('aw_audit')) aw_audit('member_book_suggest', 'member_book', $id, $title);
        return ['ok' => true, 'id' => $id];
    }

    require_once __DIR__ . '/upload.php';
    $up = aw_upload_store($file, 'book', 'assets/uploads/books', ['prefix' => 'member_', 'user_id' => (int)$uid, 'role' => 'member', 'db' => $db]);
    if (empty($up['ok'])) return ['ok' => false, 'errors' => ['book_file' => $up['error'] ?? 'The file could not be saved.']];

    // the book must have text the reader can show: checked now, so the member hears at once
    $ex = aw_book_extract(aw_book_file_abs($up['path']));
    if (empty($ex['ok'])) {
        aw_mb_unlink($up['path']);
        return ['ok' => false, 'errors' => ['book_file' => $ex['error'] ?? 'The file could not be read.']];
    }

    $cover_path = '';
    if (!empty($cover) && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $cu = aw_upload_store($cover, 'image', 'assets/uploads/books', ['prefix' => 'mcover_', 'user_id' => (int)$uid, 'role' => 'member', 'db' => $db]);
        if (empty($cu['ok'])) { aw_mb_unlink($up['path']); return ['ok' => false, 'errors' => ['cover' => $cu['error'] ?? 'The cover could not be saved.']]; }
        $cover_path = $cu['path'];
    }

    $attest_text = implode("\n", AW_MB_ATTEST);
    try {
        $db->prepare("INSERT INTO member_books (user_id, title, author_name, description, cover_path, file_path, file_type, file_size, words, pages, kind, rights, rights_detail, why, link, attest_text, attest_at, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'file', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'waiting')")
           ->execute([(int)$uid, $title, $author, $desc, $cover_path, $up['path'], $ex['type'] ?? '', (int)($up['bytes'] ?? 0), (int)$ex['words'], count($ex['pages']), $rights, $detail, $why, $link, $attest_text]);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('member book submit: ' . $e->getMessage());
        aw_mb_unlink($up['path'], $cover_path);
        return ['ok' => false, 'errors' => ['form' => 'Your book could not be saved just now. Please try again.']];
    }
    aw_mb_notify_team($db, $id, $title);
    if (function_exists('aw_audit')) aw_audit('member_book_submit', 'member_book', $id, $title);
    return ['ok' => true, 'id' => $id];
}

/** Delete an approved book and everything that serves it. */
function aw_mb_drop_book(PDO $db, $book_id) {
    $book_id = (int)$book_id;
    if (!$book_id) return;
    try {
        foreach (['book_content', 'book_content_history'] as $t) {
            try { $db->prepare("DELETE FROM $t WHERE book_id = ?")->execute([$book_id]); } catch (Throwable $e) { /* table may not exist */ }
        }
        $db->prepare("DELETE FROM books WHERE id = ? AND submitted_by IS NOT NULL")->execute([$book_id]);
    } catch (Throwable $e) {
        error_log('member book drop ' . $book_id . ': ' . $e->getMessage());
    }
}

/** The member withdraws a waiting book, or takes down a published one. */
function aw_mb_member_remove(PDO $db, $uid, $id) {
    $mb = aw_mb_get($db, $id);
    if (!$mb || (int)$mb['user_id'] !== (int)$uid || !in_array($mb['status'], ['waiting', 'published', 'declined'], true)) return false;
    $new = $mb['status'] === 'published' ? 'removed' : 'withdrawn';
    if ($mb['status'] === 'published') aw_mb_drop_book($db, $mb['book_id']);
    aw_mb_unlink($mb['file_path'], $mb['cover_path']);
    try {
        // review_note stays the team's words only; removed_by_author tells the two apart
        $db->prepare("UPDATE member_books SET status = ?, review_note = CASE WHEN ? = 'removed' THEN '' ELSE review_note END, reviewed_by = CASE WHEN ? = 'removed' THEN NULL ELSE reviewed_by END, file_path = '', cover_path = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$new, $new, $new, (int)$id]);
    } catch (Throwable $e) {
        error_log('member book remove: ' . $e->getMessage());
        return false;
    }
    if (function_exists('aw_audit')) aw_audit('member_book_' . $new, 'member_book', (int)$id, 'by the author');
    return true;
}

/**
 * Staff decision: 'approve', 'decline' (reason required) or 'takedown' (reason required).
 * Returns ['ok' => bool, 'message' => ...].
 */
function aw_mb_decide(PDO $db, $id, $action, $reason, $staff_id) {
    $mb = aw_mb_get($db, $id);
    if (!$mb) return ['ok' => false, 'message' => 'That book was not found.'];
    $reason = trim(strip_tags((string)$reason));
    if (in_array($action, ['decline', 'takedown'], true) && $reason === '') return ['ok' => false, 'message' => 'Please give the member a reason.'];
    if (mb_strlen($reason) > 1000) $reason = mb_substr($reason, 0, 1000);
    try {
        if ($action === 'approve') {
            if ($mb['status'] !== 'waiting') return ['ok' => false, 'message' => 'Only a book waiting for review can be approved.'];
            $is_file = ($mb['kind'] ?? 'file') !== 'suggestion';
            if ($is_file && aw_book_file_abs($mb['file_path']) === '') return ['ok' => false, 'message' => 'The book file is missing; ask the member to share it again.'];
            $db->beginTransaction();
            $db->prepare("INSERT INTO books (title, author, description, price, is_free, is_sale, cover_path, file_path, file_type, file_size, file_author, release_date) VALUES (?, ?, ?, 0, 1, 0, ?, ?, ?, ?, ?, ?)")
               ->execute([$mb['title'], $mb['author_name'], $mb['description'], $mb['cover_path'], $is_file ? $mb['file_path'] : '', $is_file ? $mb['file_type'] : '', $is_file ? (int)$mb['file_size'] : 0, $mb['author_name'], date('Y-m-d')]);
            $book_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE books SET submitted_by = ? WHERE id = ?")->execute([(int)$mb['user_id'], $book_id]);
            $db->prepare("UPDATE member_books SET status = 'published', book_id = ?, review_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$book_id, $reason, (int)$staff_id, (int)$id]);
            $db->commit();
            $prep = $is_file ? aw_book_prepare($db, $book_id, true) : ['ok' => true];
            aw_book_auto_cover($db, $book_id); // the file's own cover, or a designed one
            if (function_exists('awNotify')) awNotify($db, (int)$mb['user_id'], 'member_book', 'Your book is on AngelWrites', '"' . $mb['title'] . '" is now on the Community books shelf.' . ($reason !== '' ? ' Note from the team: ' . $reason : ''), SITE_URL . '/book.php?id=' . $book_id);
            if (function_exists('aw_audit')) aw_audit('member_book_approve', 'member_book', (int)$id, 'book ' . $book_id);
            return ['ok' => true, 'message' => 'Approved. "' . $mb['title'] . '" is on the Community books shelf' . (!$is_file ? ' as a suggested book (members bring their own copy).' : (!empty($prep['ok']) ? ' and ready in the reader.' : ', but the reader could not prepare it: ' . ($prep['error'] ?? '') . '.')), 'book_id' => $book_id];
        }
        if ($action === 'decline') {
            if ($mb['status'] !== 'waiting') return ['ok' => false, 'message' => 'Only a book waiting for review can be declined.'];
            aw_mb_unlink($mb['file_path'], $mb['cover_path']);
            $db->prepare("UPDATE member_books SET status = 'declined', review_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, file_path = '', cover_path = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$reason, (int)$staff_id, (int)$id]);
            if (function_exists('awNotify')) awNotify($db, (int)$mb['user_id'], 'member_book', 'About the book you shared', '"' . $mb['title'] . '" was not added. ' . $reason, SITE_URL . '/share_book.php');
            if (function_exists('aw_audit')) aw_audit('member_book_decline', 'member_book', (int)$id, $reason);
            return ['ok' => true, 'message' => 'Declined. The member has been told why, and the file was deleted.'];
        }
        if ($action === 'takedown') {
            if ($mb['status'] !== 'published') return ['ok' => false, 'message' => 'Only a book on the site can be taken down.'];
            aw_mb_drop_book($db, $mb['book_id']);
            aw_mb_unlink($mb['file_path'], $mb['cover_path']);
            $db->prepare("UPDATE member_books SET status = 'removed', review_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, file_path = '', cover_path = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$reason, (int)$staff_id, (int)$id]);
            if (function_exists('awNotify')) awNotify($db, (int)$mb['user_id'], 'member_book', 'Your book was taken down', '"' . $mb['title'] . '" has been removed from AngelWrites. ' . $reason, SITE_URL . '/share_book.php');
            if (function_exists('aw_audit')) aw_audit('member_book_takedown', 'member_book', (int)$id, $reason);
            return ['ok' => true, 'message' => 'Taken down. The book, its reader text and its files are removed everywhere, and the member has been told.'];
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('member book decide ' . $id . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => 'That did not work just now. Please try again.'];
    }
    return ['ok' => false, 'message' => 'Unknown action.'];
}

/** For the book page: the member who shared a community book, or null. */
function aw_mb_sharer(PDO $db, array $book) {
    if (empty($book['submitted_by'])) return null;
    try {
        $st = $db->prepare("SELECT m.id AS mb_id, m.user_id, m.kind, m.rights, m.rights_detail, m.why, m.link, u.display_name, u.username FROM member_books m LEFT JOIN users u ON u.id = m.user_id WHERE m.book_id = ? AND m.status = 'published'");
        $st->execute([(int)$book['id']]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('member book sharer: ' . $e->getMessage());
        return null;
    }
}

/** How a book was shared, in a few words for readers. */
function aw_mb_rights_label($rights) {
    if ($rights === 'suggest') return 'Suggested by a member';
    return isset(AW_MB_RIGHTS[$rights]) ? ['own' => 'Shared by its author', 'public_domain' => 'Public domain', 'free_licence' => 'Free to share', 'permission' => 'Shared with permission'][$rights] : '';
}
