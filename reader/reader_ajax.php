<?php
// ============================================================
//  READER_AJAX.PHP – COMPLETE (All endpoints)
// ============================================================

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : '';

if (empty($action)) {
    echo json_encode(['success' => false, 'error' => 'No action specified.']);
    exit;
}

// ================================================================
// 1. SAVE READING POSITION
// ================================================================
if ($action === 'save_position') {
    $book_id = (int)$_POST['book_id'];
    $offset = (int)$_POST['offset'];
    $chapter = isset($_POST['chapter']) ? (int)$_POST['chapter'] : 0;
    $percent = isset($_POST['percent']) ? (int)$_POST['percent'] : 0;

    $stmt = $db->prepare("SELECT id FROM books WHERE id = ?");
    $stmt->execute([$book_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Book not found.']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM reading_progress WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $exists = $stmt->fetch();

    if ($exists) {
        $stmt = $db->prepare("UPDATE reading_progress SET position_offset = ?, position_section = ?, progress_percent = ?, last_accessed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
        $stmt->execute([$offset, $chapter, $percent, $user_id, $book_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO reading_progress (user_id, book_id, position_offset, position_section, progress_percent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $book_id, $offset, $chapter, $percent]);
    }

    updateReadingStreak($user_id);
    updateReadingSessionDuration($user_id, $book_id);

    if ($percent >= 50 && $percent < 100) {
        checkMilestone($user_id, $book_id, 50);
    }
    if ($percent >= 100) {
        checkMilestone($user_id, $book_id, 100);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 2. START / END READING SESSION
// ================================================================
if ($action === 'start_session') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("UPDATE reading_sessions SET end_time = CURRENT_TIMESTAMP, duration_seconds = strftime('%s', CURRENT_TIMESTAMP) - strftime('%s', start_time) WHERE user_id = ? AND book_id = ? AND end_time IS NULL");
    $stmt->execute([$user_id, $book_id]);
    $stmt = $db->prepare("INSERT INTO reading_sessions (user_id, book_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $book_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'end_session') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("UPDATE reading_sessions SET end_time = CURRENT_TIMESTAMP, duration_seconds = strftime('%s', CURRENT_TIMESTAMP) - strftime('%s', start_time) WHERE user_id = ? AND book_id = ? AND end_time IS NULL");
    $stmt->execute([$user_id, $book_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 3. BOOKMARKS
// ================================================================
if ($action === 'add_bookmark') {
    $book_id = (int)$_POST['book_id'];
    $chapter = isset($_POST['chapter']) ? (int)$_POST['chapter'] : 0;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $stmt = $db->prepare("INSERT INTO bookmarks (user_id, book_id, chapter_index, offset, note) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $book_id, $chapter, $offset, $note]);
    echo json_encode(['success' => true, 'bookmark_id' => $db->lastInsertId()]);
    exit;
}

if ($action === 'remove_bookmark') {
    $bookmark_id = (int)$_POST['bookmark_id'];
    $stmt = $db->prepare("DELETE FROM bookmarks WHERE id = ? AND user_id = ?");
    $stmt->execute([$bookmark_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'list_bookmarks') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("SELECT id, chapter_index, offset, note, created_at FROM bookmarks WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, created_at DESC");
    $stmt->execute([$user_id, $book_id]);
    $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'bookmarks' => $bookmarks]);
    exit;
}

// ================================================================
// 4. HIGHLIGHTS
// ================================================================
if ($action === 'add_highlight') {
    $book_id = (int)$_POST['book_id'];
    $chapter = isset($_POST['chapter']) ? (int)$_POST['chapter'] : 0;
    $paragraph = isset($_POST['paragraph']) ? (int)$_POST['paragraph'] : 0;
    $text = trim($_POST['text']);
    $color = isset($_POST['color']) ? $_POST['color'] : 'yellow';
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';

    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'Highlight text cannot be empty.']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO highlights (user_id, book_id, chapter_index, paragraph_index, text, color, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $book_id, $chapter, $paragraph, $text, $color, $note]);
    echo json_encode(['success' => true, 'highlight_id' => $db->lastInsertId()]);
    exit;
}

if ($action === 'remove_highlight') {
    $highlight_id = (int)$_POST['highlight_id'];
    $stmt = $db->prepare("DELETE FROM highlights WHERE id = ? AND user_id = ?");
    $stmt->execute([$highlight_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'list_highlights') {
    $book_id = (int)$_POST['book_id'];
    $stmt = $db->prepare("SELECT id, chapter_index, paragraph_index, text, color, note, created_at FROM highlights WHERE user_id = ? AND book_id = ? ORDER BY chapter_index, paragraph_index");
    $stmt->execute([$user_id, $book_id]);
    $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'highlights' => $highlights]);
    exit;
}

// ================================================================
// 5. QUESTIONS & TYPO REPORTS
// ================================================================
if ($action === 'ask_question') {
    $book_id = (int)$_POST['book_id'];
    $chapter = isset($_POST['chapter']) ? (int)$_POST['chapter'] : 0;
    $paragraph = isset($_POST['paragraph']) ? (int)$_POST['paragraph'] : 0;
    $question = trim($_POST['question']);

    if (empty($question)) {
        echo json_encode(['success' => false, 'error' => 'Question cannot be empty.']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO reader_questions (user_id, book_id, chapter_index, paragraph_index, question) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $book_id, $chapter, $paragraph, $question]);

    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT title FROM books WHERE id = ?");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    $admin_email = 'angelwrites@zohomail.com';
    $subject = '❓ Reader Question: ' . $book['title'];
    $body = "<h2>New Reader Question</h2>";
    $body .= "<p><strong>User:</strong> " . $user['email'] . "</p>";
    $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
    $body .= "<p><strong>Chapter:</strong> " . ($chapter + 1) . "</p>";
    $body .= "<p><strong>Question:</strong><br>" . nl2br(htmlspecialchars($question)) . "</p>";
    $body .= "<p><a href='" . SITE_URL . "/admin/reader_questions.php'>Manage Questions</a></p>";
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'report_typo') {
    $book_id = (int)$_POST['book_id'];
    $chapter = (int)$_POST['chapter'];
    $description = trim($_POST['description']);

    $stmt = $db->prepare("SELECT title FROM books WHERE id = ?");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    $admin_email = 'angelwrites@zohomail.com';
    $subject = '📝 Typo Report: ' . $book['title'];
    $body = "<h2>Typo Report</h2>";
    $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
    $body .= "<p><strong>Chapter:</strong> " . ($chapter + 1) . "</p>";
    $body .= "<p><strong>Description:</strong><br>" . nl2br(htmlspecialchars($description)) . "</p>";
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');

    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 6. GOALS
// ================================================================
if ($action === 'update_goal') {
    $goal_id = (int)$_POST['goal_id'];
    $progress = (int)$_POST['progress'];

    $stmt = $db->prepare("UPDATE reading_goals SET current_value = current_value + ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$progress, $goal_id, $user_id]);

    $stmt = $db->prepare("SELECT target_value, completed FROM reading_goals WHERE id = ? AND user_id = ?");
    $stmt->execute([$goal_id, $user_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($goal && $goal['completed'] == 0 && $goal['target_value'] > 0) {
        $stmt = $db->prepare("SELECT current_value FROM reading_goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$goal_id, $user_id]);
        $current = $stmt->fetchColumn();

        if ($current >= $goal['target_value']) {
            $stmt = $db->prepare("UPDATE reading_goals SET completed = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$goal_id, $user_id]);
            $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
            $stmt->execute([$user_id, 'goal_completed']);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 7. STATS
// ================================================================
if ($action === 'get_stats') {
    $book_id = (int)$_POST['book_id'];

    $stmt = $db->prepare("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions WHERE user_id = ? AND book_id = ? AND end_time IS NOT NULL");
    $stmt->execute([$user_id, $book_id]);
    $total_seconds = $stmt->fetchColumn() ?? 0;

    $stmt = $db->prepare("SELECT SUM(pages_read) as total_pages FROM reading_sessions WHERE user_id = ? AND book_id = ?");
    $stmt->execute([$user_id, $book_id]);
    $total_pages = $stmt->fetchColumn() ?? 0;

    $stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak = $stmt->fetchColumn() ?? 0;

    $stmt = $db->prepare("SELECT achievement_type, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'total_seconds' => $total_seconds,
        'total_pages' => $total_pages,
        'streak' => $streak,
        'achievements' => $achievements
    ]);
    exit;
}

// ================================================================
// 8. MONTHLY CHALLENGE
// ================================================================
if ($action === 'get_monthly_challenge') {
    $user_id = (int)$_POST['user_id'];
    $month = date('m');
    $year = date('Y');

    $stmt = $db->prepare("SELECT * FROM reading_challenges WHERE user_id = ? AND month = ? AND year = ?");
    $stmt->execute([$user_id, $month, $year]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        $stmt = $db->prepare("INSERT INTO reading_challenges (user_id, month, year, goal, target, progress) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$user_id, $month, $year, 'Read 30 pages this month', 30, 0]);
        $challenge = [
            'id' => $db->lastInsertId(),
            'user_id' => $user_id,
            'month' => $month,
            'year' => $year,
            'goal' => 'Read 30 pages this month',
            'target' => 30,
            'progress' => 0
        ];
    }

    echo json_encode([
        'success' => true,
        'goal' => $challenge['goal'],
        'target' => (int)$challenge['target'],
        'progress' => (int)$challenge['progress']
    ]);
    exit;
}

if ($action === 'update_challenge_progress') {
    $user_id = (int)$_POST['user_id'];
    $pages_read = (int)$_POST['pages_read'];
    $month = date('m');
    $year = date('Y');

    $stmt = $db->prepare("UPDATE reading_challenges SET progress = progress + ? WHERE user_id = ? AND month = ? AND year = ?");
    $stmt->execute([$pages_read, $user_id, $month, $year]);

    $stmt = $db->prepare("SELECT target, progress FROM reading_challenges WHERE user_id = ? AND month = ? AND year = ?");
    $stmt->execute([$user_id, $month, $year]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($challenge && $challenge['progress'] >= $challenge['target']) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
        $stmt->execute([$user_id, 'monthly_challenge_completed']);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 9. GROUP NOTES
// ================================================================
if ($action === 'get_notes') {
    $group_id = (int)$_GET['group_id'];
    $book_id = (int)$_GET['book_id'];
    $chapter = (int)$_GET['chapter'];

    $stmt = $db->prepare("
        SELECT n.*, u.username, u.display_name, u.avatar
        FROM group_notes n
        JOIN users u ON n.user_id = u.id
        WHERE n.group_id = ? AND n.book_id = ? AND n.chapter_index = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$group_id, $book_id, $chapter]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    echo json_encode(['success' => true, 'notes' => $notes]);
    exit;
}

if ($action === 'add_reader_note') {
    $group_id = (int)$_POST['group_id'];
    $book_id = (int)$_POST['book_id'];
    $chapter = (int)$_POST['chapter_index'];
    $text = trim($_POST['text']);
    $is_private = isset($_POST['is_private']) ? 1 : 0;

    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'Note text cannot be empty.']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO group_notes (group_id, user_id, book_id, chapter_index, text, is_private)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $book_id, $chapter, $text, $is_private]);

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_reader_note') {
    $note_id = (int)$_POST['note_id'];
    $stmt = $db->prepare("DELETE FROM group_notes WHERE id = ? AND user_id = ?");
    $stmt->execute([$note_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_reader_reaction') {
    $note_id = (int)$_POST['note_id'];
    $reaction_type = $_POST['reaction_type'];

    $stmt = $db->prepare("SELECT id FROM group_reactions WHERE target_type = 'note' AND target_id = ? AND user_id = ? AND reaction_type = ?");
    $stmt->execute([$note_id, $user_id, $reaction_type]);
    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO group_reactions (target_type, target_id, user_id, reaction_type) VALUES ('note', ?, ?, ?)");
        $stmt->execute([$note_id, $user_id, $reaction_type]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'toggle_note_reaction') {
    $note_id = (int)$_POST['note_id'];
    $reaction_type = $_POST['reaction_type'];

    $stmt = $db->prepare("SELECT id FROM group_reactions WHERE target_type = 'note' AND target_id = ? AND user_id = ? AND reaction_type = ?");
    $stmt->execute([$note_id, $user_id, $reaction_type]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare("DELETE FROM group_reactions WHERE id = ?");
        $stmt->execute([$existing['id']]);
    } else {
        $stmt = $db->prepare("INSERT INTO group_reactions (target_type, target_id, user_id, reaction_type) VALUES ('note', ?, ?, ?)");
        $stmt->execute([$note_id, $user_id, $reaction_type]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 10. HELPER FUNCTIONS
// ================================================================

function updateReadingStreak($user_id) {
    global $db;
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM reading_streaks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $streak = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$streak) {
        $stmt = $db->prepare("INSERT INTO reading_streaks (user_id, current_streak, longest_streak, last_read_date) VALUES (?, 1, 1, ?)");
        $stmt->execute([$user_id, $today]);
        return;
    }

    $last_read = $streak['last_read_date'];
    $current = (int)$streak['current_streak'];
    $longest = (int)$streak['longest_streak'];

    if ($last_read === $today) return;
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($last_read === $yesterday) {
        $current++;
        if ($current > $longest) $longest = $current;
        if ($current === 7) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
            $stmt->execute([$user_id, 'streak_7_days']);
        }
        if ($current === 30) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO achievements (user_id, achievement_type) VALUES (?, ?)");
            $stmt->execute([$user_id, 'streak_30_days']);
        }
    } else {
        $current = 1;
    }

    $stmt = $db->prepare("UPDATE reading_streaks SET current_streak = ?, longest_streak = ?, last_read_date = ? WHERE user_id = ?");
    $stmt->execute([$current, $longest, $today, $user_id]);
}

function updateReadingSessionDuration($user_id, $book_id) {
    global $db;
    $stmt = $db->prepare("UPDATE reading_sessions SET duration_seconds = strftime('%s', CURRENT_TIMESTAMP) - strftime('%s', start_time) WHERE user_id = ? AND book_id = ? AND end_time IS NULL");
    $stmt->execute([$user_id, $book_id]);
}

function checkMilestone($user_id, $book_id, $percent) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM reader_admin_notifications WHERE user_id = ? AND book_id = ? AND event_type = ?");
    $stmt->execute([$user_id, $book_id, 'progress_' . $percent]);
    if ($stmt->fetch()) return;

    $stmt = $db->prepare("INSERT INTO reader_admin_notifications (user_id, book_id, event_type) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $book_id, 'progress_' . $percent]);

    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT title FROM books WHERE id = ?");
    $stmt->execute([$book_id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    $admin_email = 'angelwrites@zohomail.com';
    $subject = '📖 Reader Milestone: ' . $percent . '% – ' . $book['title'];
    $body = "<h2>Reader Milestone Reached</h2>";
    $body .= "<p><strong>User:</strong> " . $user['email'] . "</p>";
    $body .= "<p><strong>Book:</strong> " . $book['title'] . "</p>";
    $body .= "<p><strong>Progress:</strong> " . $percent . "%</p>";
    $body .= "<p><a href='" . SITE_URL . "/admin/reader_analytics.php'>View Analytics</a></p>";
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;