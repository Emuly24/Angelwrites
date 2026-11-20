<?php
/**
 * download.php - a book's file, only when its author allows downloads.
 *
 * Books are read on AngelWrites, in the reader (reader/reader.php). The file is sent only when the
 * book's author chose to allow downloads (books.allow_download: the member who wrote it, or the team
 * for Angella's own books) and the visitor is signed in and may read it (aw_book_download_ok).
 * Everything else (old "Download" links, bookmarks, emails) is sent to the reader, or to the book
 * page when the visitor may not read it yet, as before.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';
require_once 'includes/member_books.php'; // aw_book_download_ok()
require_once 'includes/book_extract.php'; // aw_book_file_abs()

$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$book = null;
if ($book_id > 0) {
    try {
        aw_mb_tables($db); // the allow_download column
        $stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
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

// the author allowed downloads, and this reader may have the file
if (isset($_GET['file']) && aw_book_download_ok($db, $book)) {
    $abs = aw_book_file_abs((string)$book['file_path']);
    if ($abs !== '' && is_readable($abs)) {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'epub' => 'application/epub+zip', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                  'odt' => 'application/vnd.oasis.opendocument.text', 'rtf' => 'application/rtf', 'txt' => 'text/plain; charset=utf-8'];
        $name = trim(preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string)($book['title'] ?? 'book'))) ?: 'book';
        try { $db->prepare("UPDATE books SET download_count = download_count + 1 WHERE id = ?")->execute([$book_id]); } catch (Throwable $e) { error_log('download count: ' . $e->getMessage()); }
        if (function_exists('aw_audit')) aw_audit('book_download', 'book', $book_id, '');
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . substr($name, 0, 80) . '.' . $ext . '"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($abs);
        exit;
    }
}

$may_read = !empty($book['is_free']) || isAdmin()
    || (isLoggedIn() && function_exists('aw_has_purchased') && aw_has_purchased($db, $_SESSION['user_id'], $book_id));

header('Location: ' . SITE_URL . ($may_read ? '/reader/reader.php?id=' . $book_id : '/book.php?id=' . $book_id), true, 302);
exit;
