<?php
/**
 * download.php - books are no longer downloaded.
 *
 * Every book is read on AngelWrites only, in the reader (reader/reader.php), which prepares any
 * uploaded file automatically and checks who may read it (free books: everyone; paid books: the
 * buyer and the team). Old "Download" links, bookmarks and emails land here and are sent to the
 * reader, or to the book page when the visitor may not read it yet. The file itself is never sent.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';

$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$book = null;
if ($book_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, is_free FROM books WHERE id = ?");
        $stmt->execute([$book_id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('download redirect: ' . $e->getMessage());
    }
}

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php', true, 302);
    exit;
}

$may_read = !empty($book['is_free']) || isAdmin()
    || (isLoggedIn() && function_exists('aw_has_purchased') && aw_has_purchased($db, $_SESSION['user_id'], $book_id));

header('Location: ' . SITE_URL . ($may_read ? '/reader/reader.php?id=' . $book_id : '/book.php?id=' . $book_id), true, 302);
exit;
