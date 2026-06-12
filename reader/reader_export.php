<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_GET['book_id'];

$data = [];

// Bookmarks
$stmt = $db->prepare("SELECT chapter_index, offset, note, created_at FROM bookmarks WHERE user_id = ? AND book_id = ?");
$stmt->execute([$user_id, $book_id]);
$data['bookmarks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Highlights
$stmt = $db->prepare("SELECT chapter_index, paragraph_index, text, color, note, created_at FROM highlights WHERE user_id = ? AND book_id = ?");
$stmt->execute([$user_id, $book_id]);
$data['highlights'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Progress
$stmt = $db->prepare("SELECT progress_percent, last_accessed_at FROM reading_progress WHERE user_id = ? AND book_id = ?");
$stmt->execute([$user_id, $book_id]);
$data['progress'] = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($data);