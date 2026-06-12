<?php
// ============================================================
//  BOOK_DATA.PHP – Fetch book data for the reader
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$book_id) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Fetch book details
$stmt = $db->prepare("SELECT * FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header('Location: ' . SITE_URL . '/books.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE books SET view_count = view_count + 1 WHERE id = ?");
$stmt->execute([$book_id]);

// Fetch processed content
$stmt = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
$stmt->execute([$book_id]);
$processed = $stmt->fetch(PDO::FETCH_ASSOC);
$has_processed = !empty($processed) && $processed['is_processed'] == 1;

// Parse TOC
$toc = $has_processed ? (json_decode($processed['toc_json'], true) ?? []) : [];

// Return as array for use in reader.php
return [
    'book' => $book,
    'book_id' => $book_id,
    'processed' => $processed,
    'has_processed' => $has_processed,
    'toc' => $toc,
    'file_type' => $book['file_type'] ?? 'unknown',
    'file_path' => $book['file_path'] ?? ''
];