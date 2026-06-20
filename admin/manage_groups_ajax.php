<?php
// ============================================================
//  GROUP_AJAX.PHP – AJAX endpoint for user‑facing group actions
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];

// ===== ONLY POST REQUESTS =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'];

try {
    // ===== Helper: Get group and verify membership =====
    $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
    if (!$group_id) {
        throw new Exception('Group ID is required.');
    }

    $group = getGroupDetails($group_id, $user_id);
    if (!$group || !$group['user_role']) {
        throw new Exception('You are not a member of this group.');
    }

    $is_admin = in_array($group['user_role'], ['admin', 'creator']);

    // ===== 1. CREATE DISCUSSION =====
    if ($action === 'create_discussion') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $chapter_index = isset($_POST['chapter_index']) && $_POST['chapter_index'] !== '' ? (int)$_POST['chapter_index'] : null;

        if (empty($title) || empty($content)) {
            throw new Exception('Title and content are required.');
        }

        $discussion_id = createDiscussion($group_id, $user_id, $title, $content, $chapter_index);
        echo json_encode(['success' => true, 'discussion_id' => $discussion_id]);
        exit;
    }

    // ===== 2. ADD NOTE =====
    if ($action === 'add_note') {
        $text = trim($_POST['text'] ?? '');
        $chapter_index = isset($_POST['chapter_index']) && $_POST['chapter_index'] !== '' ? (int)$_POST['chapter_index'] : null;
        $is_private = isset($_POST['is_private']) ? 1 : 0;

        if (empty($text)) {
            throw new Exception('Note text is required.');
        }

        // For multi‑content support: pass the content_id as book_id (legacy column)
        $content_id = (int)$group['content_id'];

        $note_id = addGroupNote(
            $group_id,
            $user_id,
            $content_id,          // maps to book_id in the notes table
            $text,
            $chapter_index,
            null,                 // paragraph_index
            null,                 // page_number
            $is_private
        );

        echo json_encode(['success' => true, 'note_id' => $note_id]);
        exit;
    }

    // ===== 3. DELETE NOTE =====
    if ($action === 'delete_note') {
        $note_id = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
        if (!$note_id) {
            throw new Exception('Note ID is required.');
        }

        // Verify note belongs to this user or user is admin
        $stmt = $db->prepare("SELECT user_id FROM group_notes WHERE id = ? AND group_id = ?");
        $stmt->execute([$note_id, $group_id]);
        $note_owner = $stmt->fetchColumn();

        if (!$note_owner || ($note_owner != $user_id && !$is_admin)) {
            throw new Exception('You do not have permission to delete this note.');
        }

        $stmt = $db->prepare("DELETE FROM group_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== 4. UPDATE READING PROGRESS =====
    if ($action === 'update_progress') {
        $chapter_index = isset($_POST['chapter_index']) ? (int)$_POST['chapter_index'] : -1;
        if ($chapter_index < 0) {
            throw new Exception('Invalid chapter index.');
        }

        // Determine current status (toggle logic: unread → reading → finished)
        $my_progress = getUserReadingProgress($group_id, $user_id);
        $current_status = isset($my_progress[$chapter_index]) ? $my_progress[$chapter_index]['status'] : 'unread';

        $next_status = 'reading';
        if ($current_status === 'reading') $next_status = 'finished';
        if ($current_status === 'finished') $next_status = 'unread';

        updateReadingProgress($group_id, $user_id, $chapter_index, $next_status);
        echo json_encode(['success' => true, 'new_status' => $next_status]);
        exit;
    }

    // ===== 5. ADD SCHEDULE ITEM (Admin only) =====
    if ($action === 'add_schedule') {
        if (!$is_admin) {
            throw new Exception('Only group admins can manage the schedule.');
        }

        $chapter_index = isset($_POST['chapter_index']) ? (int)$_POST['chapter_index'] : -1;
        $due_date = trim($_POST['due_date'] ?? '');

        if ($chapter_index < 0 || empty($due_date)) {
            throw new Exception('Chapter and due date are required.');
        }

        addScheduleItem($group_id, $chapter_index, $due_date);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== 6. DELETE SCHEDULE ITEM (Admin only) =====
    if ($action === 'delete_schedule') {
        if (!$is_admin) {
            throw new Exception('Only group admins can delete schedule items.');
        }

        $schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
        if (!$schedule_id) {
            throw new Exception('Schedule ID is required.');
        }

        $stmt = $db->prepare("DELETE FROM group_schedules WHERE id = ? AND group_id = ?");
        $stmt->execute([$schedule_id, $group_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== 7. UPDATE GROUP (Admin only) =====
    if ($action === 'update_group') {
        if (!$is_admin) {
            throw new Exception('Only group admins can edit group details.');
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            throw new Exception('Group name is required.');
        }

        $stmt = $db->prepare("UPDATE reading_groups SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $group_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== 8. CHANGE MEMBER ROLE (Admin only) =====
    if ($action === 'change_member_role') {
        if (!$is_admin) {
            throw new Exception('Only group admins can change member roles.');
        }

        $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $role = trim($_POST['role'] ?? '');

        if (!$target_user_id || !in_array($role, ['admin', 'reader', 'member'])) {
            throw new Exception('Invalid user or role.');
        }

        // Prevent changing the creator's role
        if ($target_user_id == $group['creator_id']) {
            throw new Exception('Cannot change the role of the group creator.');
        }

        $stmt = $db->prepare("UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$role, $group_id, $target_user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== 9. REMOVE MEMBER (Admin only) =====
    if ($action === 'remove_member') {
        if (!$is_admin) {
            throw new Exception('Only group admins can remove members.');
        }

        $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if (!$target_user_id) {
            throw new Exception('User ID is required.');
        }

        // Prevent removing the creator
        if ($target_user_id == $group['creator_id']) {
            throw new Exception('Cannot remove the group creator.');
        }

        $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $target_user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== UNKNOWN ACTION =====
    throw new Exception('Unknown action.');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}