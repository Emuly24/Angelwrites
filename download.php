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

// ===== Ensure user is logged in =====
if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ===== Get book ID =====
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
if (!$book_id) {
    die('Invalid request. No book specified.');
}

// ===== Fetch book details =====
$stmt = $db->prepare("SELECT id, title, file_path, is_free, price FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    die('Book not found.');
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
    // If not allowed, redirect to payment or show error
    header('Location: ' . SITE_URL . '/books.php?error=not_purchased');
    exit;
}

// ===== Check if file exists =====
$file_path = $book['file_path'];
if (empty($file_path) || !file_exists($file_path)) {
    die('File not found. Please contact support.');
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