<?php
/**
 * download.php - Handles book file downloads
 * 
 * For free books: any logged-in user can download.
 * For paid books: user must have a successful purchase record.
 * 
 * Expected database columns:
 *   - books table: id, title, file_path, is_free, price
 *   - purchases table: id, user_id, book_id, payment_status (or is_paid), created_at
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';

// F40: errors used to be bare die() white pages with no site layout or way back. They are now a
// normal site page with a link onward (and the right HTTP status).
function aw_download_error($status, $title, $message, $link_url, $link_text) {
    global $db;
    http_response_code($status);
    $pageTitle = $title;
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container" style="max-width:640px;margin:40px auto;padding:0 16px;text-align:center;">'
       . '<h1 style="font-size:1.6rem;">' . htmlspecialchars($title) . '</h1>'
       . '<p>' . htmlspecialchars($message) . '</p>'
       . '<p><a class="btn btn-primary" href="' . htmlspecialchars($link_url) . '">' . htmlspecialchars($link_text) . '</a></p>'
       . '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ===== Ensure user is logged in =====
if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ===== Get book ID =====
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
if (!$book_id) {
    aw_download_error(400, 'No book chosen', 'This download link does not say which book to download.', SITE_URL . '/books.php', 'Browse books');
}

// ===== Fetch book details =====
$stmt = $db->prepare("SELECT id, title, file_path, is_free, price FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    aw_download_error(404, 'Book not found', 'This book does not exist or has been removed.', SITE_URL . '/books.php', 'Browse books');
}

// ===== Check if the user is allowed to download =====
$allowed = false;

if ($book['is_free']) {
    $allowed = true;
} else {
    // Paid book – check if user has purchased it
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("
        SELECT id FROM purchases 
        WHERE user_id = ? AND book_id = ? AND payment_status = 'completed'
        LIMIT 1
    ");
    $stmt->execute([$user_id, $book_id]);
    if ($stmt->fetch()) {
        $allowed = true;
    }
}

if (!$allowed) {
    // F40: say why, and offer the way to buy it (the order form on books.php, F75).
    aw_download_error(403, 'Buy this book to download it', '"' . $book['title'] . '" is a paid book. Order it from the books page; the download unlocks as soon as your payment is confirmed.', SITE_URL . '/books.php?error=not_purchased#book-' . (int)$book['id'], 'Order this book');
}

// ===== Check if file exists =====
$file_path = $book['file_path'];
if (empty($file_path) || !file_exists($file_path)) {
    aw_download_error(404, 'File not available', 'The file for "' . $book['title'] . '" is not available right now. Please contact us and we will send it to you.', SITE_URL . '/contact.php', 'Contact us');
}

// ===== Serve the file =====
// Get file extension and set MIME type
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'epub' => 'application/epub+zip',
    'mobi' => 'application/x-mobipocket-ebook',
    'azw3' => 'application/vnd.amazon.ebook',
    'txt'  => 'text/plain',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];
$mime = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';

// Clean the filename for download
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $book['title']) . '.' . $ext;

// Set headers
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Output file
readfile($file_path);
exit;