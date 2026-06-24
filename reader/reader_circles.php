<?php
// ============================================================
//  READER_CIRCLES.PHP – Complete Reading Circle Management API
//  Handles joining, leaving, invitations, progress updates,
//  member listings, and activity feeds.
//  All actions return JSON.
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail_helper.php';

// --- Authentication ---
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// --- Helper: Check if user is in the circle ---
function isInCircle($db, $user_id, $book_id) {
    $stmt = $db->prepare("SELECT 1 FROM reading_circles WHERE book_id = ? AND user_id = ?");
    $stmt->execute([$book_id, $user_id]);
    return (bool)$stmt->fetchColumn();
}

// --- Helper: Get book title ---
function getBookTitle($db, $book_id) {
    $stmt = $db->prepare("SELECT title FROM books WHERE id = ?");
    $stmt->execute([$book_id]);
    return $stmt->fetchColumn();
}

// --- Helper: Get circle activity feed ---
function getCircleActivity($db, $book_id, $limit = 20) {
    // 1. Joins from reading_circles
    $stmt = $db->prepare("
        SELECT 'join' AS type, user_id, joined_at AS created_at, NULL AS extra
        FROM reading_circles
        WHERE book_id = ?
        UNION ALL
        SELECT 'position' AS type, user_id, updated_at AS created_at, last_read_position AS extra
        FROM reading_circles
        WHERE book_id = ? AND updated_at > joined_at
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$book_id, $book_id, $limit * 2]); // fetch enough to merge later
    $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Check if this book has a reading group (for notes)
    $stmt = $db->prepare("SELECT id FROM reading_groups WHERE book_id = ? LIMIT 1");
    $stmt->execute([$book_id]);
    $group_id = $stmt->fetchColumn();

    if ($group_id) {
        // Fetch notes from group_notes
        $stmt = $db->prepare("
            SELECT 'note' AS type, user_id, created_at, text AS extra
            FROM group_notes
            WHERE group_id = ? AND book_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$group_id, $book_id, $limit]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $activity = array_merge($activity, $notes);
    }

    // Sort all activities by created_at descending
    usort($activity, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Keep only the first $limit items
    $activity = array_slice($activity, 0, $limit);

    // Enrich with user details
    foreach ($activity as &$entry) {
        $stmt = $db->prepare("SELECT username, display_name, avatar FROM users WHERE id = ?");
        $stmt->execute([$entry['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $entry['user'] = $user ?: ['username' => 'Unknown', 'display_name' => 'Unknown', 'avatar' => null];
    }

    return $activity;
}

// --- Route actions ---
switch ($action) {

    // ======================== GET CIRCLE INFO ========================
    case 'get_circle_info':
        $book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        // Book details
        $stmt = $db->prepare("SELECT id, title, cover_path FROM books WHERE id = ?");
        $stmt->execute([$book_id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$book) {
            echo json_encode(['success' => false, 'error' => 'Book not found.']);
            exit;
        }

        // Circle stats
        $stmt = $db->prepare("SELECT COUNT(*) as total_members FROM reading_circles WHERE book_id = ?");
        $stmt->execute([$book_id]);
        $total_members = $stmt->fetchColumn();

        // Membership status
        $is_member = isInCircle($db, $user_id, $book_id);

        // Recent activity
        $activity = getCircleActivity($db, $book_id, 10);

        echo json_encode([
            'success' => true,
            'book' => [
                'id' => $book['id'],
                'title' => $book['title'],
                'cover_path' => $book['cover_path'],
            ],
            'circle' => [
                'total_members' => (int)$total_members,
                'is_member' => $is_member,
                'activity' => $activity,
            ]
        ]);
        exit;

    // ======================== JOIN CIRCLE ========================
    case 'join_circle':
        $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        // Check if already a member
        if (isInCircle($db, $user_id, $book_id)) {
            echo json_encode(['success' => false, 'error' => 'Already a member of this circle.']);
            exit;
        }

        // Verify book exists
        $stmt = $db->prepare("SELECT id FROM books WHERE id = ?");
        $stmt->execute([$book_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Book not found.']);
            exit;
        }

        // Insert into circle
        $stmt = $db->prepare("INSERT INTO reading_circles (book_id, user_id, joined_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$book_id, $user_id]);

        // Update reading streak (helper from reader_functions.php)
        if (function_exists('updateReadingStreak')) {
            updateReadingStreak($user_id);
        }

        echo json_encode(['success' => true]);
        exit;

    // ======================== LEAVE CIRCLE ========================
    case 'leave_circle':
        $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM reading_circles WHERE book_id = ? AND user_id = ?");
        $stmt->execute([$book_id, $user_id]);
        echo json_encode(['success' => true]);
        exit;

    // ======================== UPDATE READING POSITION ========================
    case 'update_position':
        $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
        $position = isset($_POST['position']) ? trim($_POST['position']) : '';
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        // Verify membership
        if (!isInCircle($db, $user_id, $book_id)) {
            echo json_encode(['success' => false, 'error' => 'You are not a member of this circle.']);
            exit;
        }

        $stmt = $db->prepare("UPDATE reading_circles SET last_read_position = ?, updated_at = CURRENT_TIMESTAMP WHERE book_id = ? AND user_id = ?");
        $stmt->execute([$position, $book_id, $user_id]);
        echo json_encode(['success' => true]);
        exit;

    // ======================== CIRCLE MEMBERS ========================
    case 'circle_members':
        $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT u.id, u.username, u.display_name, u.avatar,
                   c.last_read_position, c.joined_at, c.updated_at
            FROM reading_circles c
            JOIN users u ON c.user_id = u.id
            WHERE c.book_id = ?
            ORDER BY c.joined_at DESC
        ");
        $stmt->execute([$book_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'members' => $members]);
        exit;

    // ======================== INVITE MEMBER ========================
    case 'invite_member':
        $book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        if (!$book_id || !$email) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id or email.']);
            exit;
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
            exit;
        }

        // Only members can invite
        if (!isInCircle($db, $user_id, $book_id)) {
            echo json_encode(['success' => false, 'error' => 'Only circle members can invite others.']);
            exit;
        }

        // Check if invitee already exists and is a member
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $invitee_id = $stmt->fetchColumn();
        if ($invitee_id && isInCircle($db, $invitee_id, $book_id)) {
            echo json_encode(['success' => false, 'error' => 'This user is already a member of the circle.']);
            exit;
        }

        // Check for existing pending invite
        $stmt = $db->prepare("SELECT 1 FROM reading_circle_invites WHERE book_id = ? AND email = ? AND status = 'pending'");
        $stmt->execute([$book_id, $email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'An invitation has already been sent to this email.']);
            exit;
        }

        // Generate token
        $token = bin2hex(random_bytes(16));

        // Insert invite
        $stmt = $db->prepare("INSERT INTO reading_circle_invites (book_id, inviter_id, email, token, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$book_id, $user_id, $email, $token]);

        // Get book title
        $book_title = getBookTitle($db, $book_id);
        if (!$book_title) {
            // Fallback
            $book_title = 'the book';
        }

        // Build email
        $invite_link = SITE_URL . '/reader/join_circle.php?token=' . $token;
        $subject = "You've been invited to join a reading circle!";
        $body = "<h2>You're invited to join the reading circle for <em>" . htmlspecialchars($book_title) . "</em>!</h2>";
        $body .= "<p>Click the link below to join:</p>";
        $body .= "<p><a href='$invite_link'>$invite_link</a></p>";
        $body .= "<p>If you don't have an account, you'll be prompted to create one.</p>";
        $body .= "<p>Happy reading!</p>";

        // Send email using your mail helper
        $sent = sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');

        if ($sent) {
            echo json_encode(['success' => true]);
        } else {
            // Delete the invite if email failed
            $stmt = $db->prepare("DELETE FROM reading_circle_invites WHERE token = ?");
            $stmt->execute([$token]);
            echo json_encode(['success' => false, 'error' => 'Failed to send invitation email.']);
        }
        exit;

    // ======================== GET ACTIVITY FEED ========================
    case 'get_activity':
        $book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }

        $activity = getCircleActivity($db, $book_id, $limit);
        echo json_encode(['success' => true, 'activity' => $activity]);
        exit;

    // ======================== CHECK MEMBERSHIP ========================
    case 'is_member':
        $book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
        if (!$book_id) {
            echo json_encode(['success' => false, 'error' => 'Missing book_id.']);
            exit;
        }
        $is_member = isInCircle($db, $user_id, $book_id);
        echo json_encode(['success' => true, 'is_member' => $is_member]);
        exit;

    // ======================== DEFAULT ========================
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action.']);
        exit;
}