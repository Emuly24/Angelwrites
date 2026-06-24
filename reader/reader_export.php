<?php
// ============================================================
//  READER_ANALYTICS.PHP – Comprehensive user analytics for a book
//  Returns JSON with all reading‑related data for the authenticated user.
//  Usage: reader_analytics.php?book_id=123&group_id=456 (optional)
// ============================================================

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/reading_groups.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

if (!$book_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing book_id.']);
    exit;
}

// Verify book exists
$stmt = $db->prepare("SELECT id FROM books WHERE id = ?");
$stmt->execute([$book_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Book not found.']);
    exit;
}

$data = [];

// ------------------------- 1. Bookmarks -------------------------
$stmt = $db->prepare("
    SELECT id, chapter_index, offset, note, created_at
    FROM bookmarks
    WHERE user_id = ? AND book_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id, $book_id]);
$data['bookmarks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------- 2. Highlights -------------------------
$stmt = $db->prepare("
    SELECT id, chapter_index, paragraph_index, text, color, note, created_at
    FROM highlights
    WHERE user_id = ? AND book_id = ?
    ORDER BY chapter_index, created_at
");
$stmt->execute([$user_id, $book_id]);
$data['highlights'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------- 3. Reading Progress -------------------------
$stmt = $db->prepare("
    SELECT progress_percent, position_section, position_offset, last_accessed_at
    FROM reading_progress
    WHERE user_id = ? AND book_id = ?
");
$stmt->execute([$user_id, $book_id]);
$data['progress'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ------------------------- 4. Reading Status -------------------------
$stmt = $db->prepare("SELECT status FROM reading_status WHERE user_id = ? AND book_id = ?");
$stmt->execute([$user_id, $book_id]);
$data['reading_status'] = $stmt->fetchColumn() ?: 'not_started';

// ------------------------- 5. Streak -------------------------
$stmt = $db->prepare("SELECT current_streak, longest_streak, last_read_date FROM reading_streaks WHERE user_id = ?");
$stmt->execute([$user_id]);
$data['streak'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ------------------------- 6. User Level (from gamification) -------------------------
require_once __DIR__ . '/reader_gamification.php'; // if in same folder
$level_data = getReaderLevel($user_id);
$data['level'] = $level_data;

// ------------------------- 7. Group Notes (if group_id provided) -------------------------
if ($group_id > 0) {
    // Verify user is a member of the group
    $stmt = $db->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            SELECT n.id, n.chapter_index, n.text, n.is_private, n.created_at,
                   u.username, u.display_name, u.avatar
            FROM group_notes n
            JOIN users u ON n.user_id = u.id
            WHERE n.group_id = ? AND n.book_id = ?
            ORDER BY n.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$group_id, $book_id]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Include reaction counts
        foreach ($notes as &$note) {
            $stmt = $db->prepare("
                SELECT reaction_type, COUNT(*) as count
                FROM group_reactions
                WHERE target_type = 'note' AND target_id = ?
                GROUP BY reaction_type
            ");
            $stmt->execute([$note['id']]);
            $note['reactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $data['group_notes'] = $notes;
    } else {
        $data['group_notes'] = ['error' => 'Not a member of this group.'];
    }
} else {
    $data['group_notes'] = null;
}

// ------------------------- 8. Monthly Challenge -------------------------
$month = date('m');
$year = date('Y');
$stmt = $db->prepare("
    SELECT goal, target, progress, completed
    FROM reading_challenges
    WHERE user_id = ? AND month = ? AND year = ?
");
$stmt->execute([$user_id, $month, $year]);
$data['challenge'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ------------------------- 9. Reading Sessions Summary -------------------------
$stmt = $db->prepare("
    SELECT
        SUM(duration_seconds) as total_seconds,
        SUM(pages_read) as total_pages,
        COUNT(*) as session_count,
        MAX(start_time) as last_session_start
    FROM reading_sessions
    WHERE user_id = ? AND book_id = ? AND end_time IS NOT NULL
");
$stmt->execute([$user_id, $book_id]);
$data['sessions'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ------------------------- 10. Achievements (unlocked for this book) -------------------------
$stmt = $db->prepare("
    SELECT achievement_type, unlocked_at
    FROM achievements
    WHERE user_id = ?
    ORDER BY unlocked_at DESC
");
$stmt->execute([$user_id]);
$data['achievements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------- Output JSON -------------------------
header('Content-Type: application/json');
echo json_encode($data);
exit;