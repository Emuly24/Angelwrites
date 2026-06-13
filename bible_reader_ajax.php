<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== AUTHENTICATION REQUIRED FOR MOST ACTIONS =====
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ===== SANITIZE INPUT =====
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// ===== GET NOTES =====
if ($action === 'get_notes' && $user_id > 0) {
    $book = sanitize($_GET['book'] ?? '');
    $chapter = (int)($_GET['chapter'] ?? 0);
    
    $stmt = $db->prepare("
        SELECT n.*, u.username, u.name as display_name, u.profile_pic as avatar 
        FROM bible_notes n
        LEFT JOIN users u ON n.user_id = u.id
        WHERE n.book = ? AND n.chapter = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$book, $chapter]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch reactions for each note
    foreach ($notes as &$note) {
        $stmt2 = $db->prepare("
            SELECT reaction_type, COUNT(*) as count 
            FROM bible_reactions 
            WHERE note_id = ? 
            GROUP BY reaction_type
        ");
        $stmt2->execute([$note['id']]);
        $note['reactions'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $note['user_id'] = (int)$note['user_id'];
    }
    
    echo json_encode(['success' => true, 'notes' => $notes]);
    exit;
}

// ===== ADD NOTE =====
if ($action === 'add_note' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    $verse = (int)($_POST['verse'] ?? 0);
    $text = sanitize($_POST['text'] ?? '');
    $is_private = (int)($_POST['is_private'] ?? 0);
    
    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'Note text is required.']);
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO bible_notes (user_id, book, chapter, verse, text, is_private, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$user_id, $book, $chapter, $verse, $text, $is_private]);
    $note_id = $db->lastInsertId();
    
    // ===== NOTIFY ADMIN (if not private) =====
    if (!$is_private) {
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '📝 New Bible Note';
        $body = "<h2>New Bible Note</h2>";
        $body .= "<p><strong>User:</strong> " . $_SESSION['name'] . " (ID: $user_id)</p>";
        $body .= "<p><strong>Book:</strong> $book</p>";
        $body .= "<p><strong>Chapter:</strong> $chapter</p>";
        $body .= "<p><strong>Verse:</strong> $verse</p>";
        $body .= "<p><strong>Note:</strong><br>" . nl2br($text) . "</p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
    }
    
    echo json_encode(['success' => true, 'note_id' => $note_id]);
    exit;
}

// ===== DELETE NOTE =====
if ($action === 'delete_note' && $user_id > 0) {
    $note_id = (int)($_POST['note_id'] ?? 0);
    
    // Ensure user owns this note
    $stmt = $db->prepare("SELECT user_id FROM bible_notes WHERE id = ?");
    $stmt->execute([$note_id]);
    $owner = $stmt->fetchColumn();
    
    if ($owner != $user_id) {
        echo json_encode(['success' => false, 'error' => 'You can only delete your own notes.']);
        exit;
    }
    
    // Delete reactions first
    $stmt = $db->prepare("DELETE FROM bible_reactions WHERE note_id = ?");
    $stmt->execute([$note_id]);
    
    // Delete note
    $stmt = $db->prepare("DELETE FROM bible_notes WHERE id = ?");
    $stmt->execute([$note_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== ADD REACTION =====
if ($action === 'add_reaction' && $user_id > 0) {
    $note_id = (int)($_POST['note_id'] ?? 0);
    $reaction_type = sanitize($_POST['reaction_type'] ?? '');
    
    // Check if user already reacted with this type
    $stmt = $db->prepare("SELECT id FROM bible_reactions WHERE note_id = ? AND user_id = ? AND reaction_type = ?");
    $stmt->execute([$note_id, $user_id, $reaction_type]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Already reacted with this emoji.']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO bible_reactions (note_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$note_id, $user_id, $reaction_type]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== TOGGLE REACTION =====
if ($action === 'toggle_reaction' && $user_id > 0) {
    $note_id = (int)($_POST['note_id'] ?? 0);
    $reaction_type = sanitize($_POST['reaction_type'] ?? '');
    
    $stmt = $db->prepare("SELECT id FROM bible_reactions WHERE note_id = ? AND user_id = ? AND reaction_type = ?");
    $stmt->execute([$note_id, $user_id, $reaction_type]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Remove reaction
        $stmt = $db->prepare("DELETE FROM bible_reactions WHERE id = ?");
        $stmt->execute([$existing['id']]);
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        // Add reaction
        $stmt = $db->prepare("INSERT INTO bible_reactions (note_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$note_id, $user_id, $reaction_type]);
        echo json_encode(['success' => true, 'action' => 'added']);
    }
    exit;
}

// ===== BOOKMARKS =====
if ($action === 'add_bookmark' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    $verse = (int)($_POST['verse'] ?? 0);
    
    // Check if already bookmarked
    $stmt = $db->prepare("SELECT id FROM bible_bookmarks WHERE user_id = ? AND book = ? AND chapter = ? AND verse = ?");
    $stmt->execute([$user_id, $book, $chapter, $verse]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Already bookmarked.']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO bible_bookmarks (user_id, book, chapter, verse, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$user_id, $book, $chapter, $verse]);
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'remove_bookmark' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    $verse = (int)($_POST['verse'] ?? 0);
    
    $stmt = $db->prepare("DELETE FROM bible_bookmarks WHERE user_id = ? AND book = ? AND chapter = ? AND verse = ?");
    $stmt->execute([$user_id, $book, $chapter, $verse]);
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'check_bookmark' && $user_id > 0) {
    $book = sanitize($_GET['book'] ?? '');
    $chapter = (int)($_GET['chapter'] ?? 0);
    $verse = (int)($_GET['verse'] ?? 0);
    
    $stmt = $db->prepare("SELECT id FROM bible_bookmarks WHERE user_id = ? AND book = ? AND chapter = ? AND verse = ?");
    $stmt->execute([$user_id, $book, $chapter, $verse]);
    $bookmarked = $stmt->fetch() ? true : false;
    
    echo json_encode(['bookmarked' => $bookmarked]);
    exit;
}

// ===== SAVE READING POSITION =====
if ($action === 'save_position' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    $verse = (int)($_POST['verse'] ?? 0);
    $percent = (int)($_POST['percent'] ?? 0);
    
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO bible_reading_positions (user_id, book, chapter, verse, percent, updated_at) 
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$user_id, $book, $chapter, $verse, $percent]);
    
    // Also track reading progress for challenge
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO bible_reading_history (user_id, book, chapter, read_at) 
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$user_id, $book, $chapter]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== MONTHLY CHALLENGE =====
if ($action === 'get_monthly_challenge' && $user_id > 0) {
    $year = date('Y');
    $month = date('m');
    
    // Get or create challenge for this month
    $stmt = $db->prepare("
        SELECT id, target, progress FROM bible_monthly_challenges 
        WHERE user_id = ? AND year = ? AND month = ?
    ");
    $stmt->execute([$user_id, $year, $month]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$challenge) {
        // Default target: 30 chapters per month
        $stmt = $db->prepare("
            INSERT INTO bible_monthly_challenges (user_id, year, month, target, progress, created_at) 
            VALUES (?, ?, ?, 30, 0, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$user_id, $year, $month]);
        
        $challenge = [
            'id' => $db->lastInsertId(),
            'target' => 30,
            'progress' => 0
        ];
    }
    
    // Count unique chapters read this month
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT book || '-' || chapter) as count 
        FROM bible_reading_history 
        WHERE user_id = ? AND strftime('%Y', read_at) = ? AND strftime('%m', read_at) = ?
    ");
    $stmt->execute([$user_id, $year, $month]);
    $chapters_read = $stmt->fetchColumn();
    
    // Update progress
    $stmt = $db->prepare("UPDATE bible_monthly_challenges SET progress = ? WHERE id = ?");
    $stmt->execute([$chapters_read, $challenge['id']]);
    
    echo json_encode([
        'success' => true,
        'goal' => "Read $challenge[target] chapters this month",
        'target' => (int)$challenge['target'],
        'progress' => (int)$chapters_read
    ]);
    exit;
}

if ($action === 'update_challenge_progress' && $user_id > 0) {
    $chapters_read = (int)($_POST['chapters_read'] ?? 0);
    if ($chapters_read <= 0) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid number.']);
        exit;
    }
    
    // Insert reading entries for today
    for ($i = 0; $i < $chapters_read; $i++) {
        $stmt = $db->prepare("
            INSERT INTO bible_reading_history (user_id, book, chapter, read_at) 
            VALUES (?, 'Daily Reading', 0, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$user_id]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== SESSION TRACKING =====
if ($action === 'start_session' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    
    $stmt = $db->prepare("
        INSERT INTO bible_sessions (user_id, book, chapter, started_at, updated_at) 
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$user_id, $book, $chapter]);
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'end_session' && $user_id > 0) {
    $book = sanitize($_POST['book'] ?? '');
    $chapter = (int)($_POST['chapter'] ?? 0);
    
    // Update the most recent session
    $stmt = $db->prepare("
        UPDATE bible_sessions 
        SET ended_at = CURRENT_TIMESTAMP, duration = strftime('%s', CURRENT_TIMESTAMP) - strftime('%s', started_at) 
        WHERE user_id = ? AND book = ? AND chapter = ? AND ended_at IS NULL 
        ORDER BY started_at DESC LIMIT 1
    ");
    $stmt->execute([$user_id, $book, $chapter]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ===== INVALID ACTION =====
echo json_encode(['success' => false, 'error' => 'Invalid action or missing authentication.']);
exit;