<?php
// F41: create_group.php fills its "choose a book / poem / newsletter" list from this endpoint,
// which did not exist, so the list said "Loading…" forever and no member could create a group.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$type = $_GET['type'] ?? '';
try {
    if ($type === 'book') {
        $rows = $db->query("SELECT id, title, author FROM books ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'poem') {
        // published poems only (drafts are not public, F122)
        $rows = $db->query("SELECT id, title, NULL AS author FROM poems
                            WHERE id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')
                            ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'newsletter') {
        // newsletters that have been sent (the archive)
        $rows = $db->query("SELECT id, subject AS title, NULL AS author FROM newsletter_archive ORDER BY sent_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = [];
    }
} catch (Throwable $e) {
    error_log('get_content_items: ' . $e->getMessage());
    $rows = [];
}
echo json_encode($rows);
