<?php
// reader/reader_circles.php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'join_circle') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO reading_circles (book_id, user_id) VALUES (?, ?)");
    $stmt->execute([$book_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'leave_circle') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("DELETE FROM reading_circles WHERE book_id = ? AND user_id = ?");
    $stmt->execute([$book_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'circle_members') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.email, c.last_read_position, c.joined_at
        FROM reading_circles c
        JOIN users u ON c.user_id = u.id
        WHERE c.book_id = ?
        ORDER BY c.joined_at DESC
    ");
    $stmt->execute([$book_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'members' => $members]);
    exit;
}