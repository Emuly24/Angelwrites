<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'join_group') {
        $invite_code = strtoupper(trim($_POST['invite_code']));
        $result = joinGroupByCode($invite_code, $user_id);
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Invalid invite code.']);
        } elseif ($result === 'already_member') {
            echo json_encode(['success' => false, 'error' => 'You are already a member of this group.']);
        } else {
            echo json_encode(['success' => true, 'group_id' => $result]);
        }
        exit;
    }

    if ($action === 'create_discussion') {
        $group_id = (int)$_POST['group_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $chapter_index = isset($_POST['chapter_index']) && $_POST['chapter_index'] !== '' ? (int)$_POST['chapter_index'] : null;
        if (empty($title) || empty($content)) {
            echo json_encode(['success' => false, 'error' => 'Title and content are required.']);
            exit;
        }
        $discussion_id = createDiscussion($group_id, $user_id, $title, $content, $chapter_index);
        echo json_encode(['success' => true, 'discussion_id' => $discussion_id]);
        exit;
    }

    if ($action === 'add_note') {
        $group_id = (int)$_POST['group_id'];
        $book_id = (int)$_POST['book_id'];
        $text = trim($_POST['text']);
        $chapter_index = isset($_POST['chapter_index']) && $_POST['chapter_index'] !== '' ? (int)$_POST['chapter_index'] : null;
        $is_private = isset($_POST['is_private']) ? 1 : 0;
        if (empty($text)) {
            echo json_encode(['success' => false, 'error' => 'Note text is required.']);
            exit;
        }
        $note_id = addGroupNote($group_id, $user_id, $book_id, $text, $chapter_index, null, null, $is_private);
        echo json_encode(['success' => true, 'note_id' => $note_id]);
        exit;
    }

    if ($action === 'delete_note') {
        $note_id = (int)$_POST['note_id'];
        $stmt = $db->prepare("DELETE FROM group_notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_progress') {
        $group_id = (int)$_POST['group_id'];
        $chapter_index = (int)$_POST['chapter_index'];
        // Toggle: unread -> reading -> finished -> unread
        $stmt = $db->prepare("SELECT status FROM group_reading_progress WHERE group_id = ? AND user_id = ? AND chapter_index = ?");
        $stmt->execute([$group_id, $user_id, $chapter_index]);
        $current = $stmt->fetchColumn();
        $status = 'unread';
        if ($current === 'unread') $status = 'reading';
        elseif ($current === 'reading') $status = 'finished';
        elseif ($current === 'finished') $status = 'unread';
        updateReadingProgress($group_id, $user_id, $chapter_index, $status);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_schedule') {
        $group_id = (int)$_POST['group_id'];
        $chapter_index = (int)$_POST['chapter_index'];
        $due_date = $_POST['due_date'];
        // Check if user is admin
        $stmt = $db->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        $role = $stmt->fetchColumn();
        if (!in_array($role, ['admin', 'creator'])) {
            echo json_encode(['success' => false, 'error' => 'Only admins can add schedule items.']);
            exit;
        }
        addScheduleItem($group_id, $chapter_index, $due_date);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_schedule') {
        $schedule_id = (int)$_POST['schedule_id'];
        $stmt = $db->prepare("SELECT group_id FROM group_schedules WHERE id = ?");
        $stmt->execute([$schedule_id]);
        $group_id = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        $role = $stmt->fetchColumn();
        if (!in_array($role, ['admin', 'creator'])) {
            echo json_encode(['success' => false, 'error' => 'Only admins can delete schedule items.']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM group_schedules WHERE id = ?");
        $stmt->execute([$schedule_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_reaction') {
        $target_type = $_POST['target_type'];
        $target_id = (int)$_POST['target_id'];
        $reaction_type = $_POST['reaction_type'];
        addReaction($target_type, $target_id, $user_id, $reaction_type);
        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);