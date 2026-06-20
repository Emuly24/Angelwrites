<?php
// ============================================================
//  READING_GROUPS.PHP – Backend functions for reading groups
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ===== GENERATE INVITE CODE =====
function generateInviteCode($length = 8) {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, $length));
}

// ===== CREATE READING GROUP =====
function createReadingGroup($content_type, $content_id, $creator_id, $name, $description = '', $is_private = false) {
    global $db;
    $invite_code = generateInviteCode();
    $stmt = $db->prepare("
        INSERT INTO reading_groups (content_type, content_id, creator_id, name, description, invite_code, is_private)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$content_type, $content_id, $creator_id, $name, $description, $invite_code, $is_private ? 1 : 0]);
    $group_id = $db->lastInsertId();

    // Add creator as member
    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'creator')");
    $stmt->execute([$group_id, $creator_id]);

    logGroupActivity($group_id, $creator_id, 'create', 'group', $group_id, ['name' => $name]);

    return $group_id;
}

// ===== JOIN GROUP BY INVITE CODE =====
function joinGroupByCode($invite_code, $user_id) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM reading_groups WHERE invite_code = ?");
    $stmt->execute([$invite_code]);
    $group_id = $stmt->fetchColumn();

    if (!$group_id) {
        return false;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    if ($stmt->fetchColumn() > 0) {
        return 'already_member';
    }

    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $stmt->execute([$group_id, $user_id]);

    logGroupActivity($group_id, $user_id, 'join', 'member', $user_id);

    return $group_id;
}

// ===== GET USER GROUPS =====
function getUserGroups($user_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT g.*,
               b.title as book_title, b.author as book_author,
               p.title as poem_title, p.author as poem_author,
               n.title as newsletter_title, n.author as newsletter_author,
               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
               (SELECT COUNT(*) FROM group_notes WHERE group_id = g.id) as note_count,
               (SELECT COUNT(*) FROM group_discussions WHERE group_id = g.id) as discussion_count
        FROM reading_groups g
        LEFT JOIN books b ON g.content_type = 'book' AND g.content_id = b.id
        LEFT JOIN poems p ON g.content_type = 'poem' AND g.content_id = p.id
        LEFT JOIN newsletters n ON g.content_type = 'newsletter' AND g.content_id = n.id
        JOIN group_members m ON g.id = m.group_id
        WHERE m.user_id = ?
        ORDER BY g.created_at DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== GET GROUP DETAILS =====
function getGroupDetails($group_id, $user_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT g.*,
               b.title as book_title, b.author as book_author,
               p.title as poem_title, p.author as poem_author,
               n.title as newsletter_title, n.author as newsletter_author,
               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
               (SELECT role FROM group_members WHERE group_id = g.id AND user_id = ?) as user_role
        FROM reading_groups g
        LEFT JOIN books b ON g.content_type = 'book' AND g.content_id = b.id
        LEFT JOIN poems p ON g.content_type = 'poem' AND g.content_id = p.id
        LEFT JOIN newsletters n ON g.content_type = 'newsletter' AND g.content_id = n.id
        WHERE g.id = ?
    ");
    $stmt->execute([$user_id, $group_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ===== GET GROUP MEMBERS =====
function getGroupMembers($group_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.email, u.display_name, u.avatar,
        m.role, m.joined_at
        FROM group_members m
        JOIN users u ON m.user_id = u.id
        WHERE m.group_id = ?
        ORDER BY m.role DESC, m.joined_at ASC
    ");
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== ADD GROUP NOTE =====
function addGroupNote($group_id, $user_id, $content_id, $text, $chapter_index = null, $paragraph_index = null, $page_number = null, $is_private = false) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO group_notes (group_id, user_id, book_id, chapter_index, paragraph_index, page_number, text, is_private)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $content_id, $chapter_index, $paragraph_index, $page_number, $text, $is_private ? 1 : 0]);
    $note_id = $db->lastInsertId();

    logGroupActivity($group_id, $user_id, 'note', 'note', $note_id, ['chapter' => $chapter_index, 'private' => $is_private]);
    return $note_id;
}

// ===== GET GROUP NOTES =====
function getGroupNotes($group_id, $chapter_index = null, $include_private = false) {
    global $db;
    $user_id = $_SESSION['user_id'] ?? 0;
    $sql = "
        SELECT n.*, u.username, u.display_name, u.avatar,
        (SELECT COUNT(*) FROM group_reactions WHERE target_type = 'note' AND target_id = n.id) as reaction_count
        FROM group_notes n
        JOIN users u ON n.user_id = u.id
        WHERE n.group_id = ?
    ";
    $params = [$group_id];

    if ($chapter_index !== null) {
        $sql .= " AND n.chapter_index = ?";
        $params[] = $chapter_index;
    }

    if (!$include_private) {
        $sql .= " AND (n.is_private = 0 OR n.user_id = ?)";
        $params[] = $user_id;
    }

    $sql .= " ORDER BY n.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== CREATE DISCUSSION =====
function createDiscussion($group_id, $user_id, $title, $content, $chapter_index = null) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO group_discussions (group_id, user_id, title, content, chapter_index)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $title, $content, $chapter_index]);
    $discussion_id = $db->lastInsertId();

    logGroupActivity($group_id, $user_id, 'discussion', 'discussion', $discussion_id, ['title' => $title, 'chapter' => $chapter_index]);
    return $discussion_id;
}

// ===== GET DISCUSSIONS =====
function getGroupDiscussions($group_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT d.*, u.username, u.display_name, u.avatar,
        (SELECT COUNT(*) FROM group_discussion_replies WHERE discussion_id = d.id) as reply_count,
        (SELECT COUNT(*) FROM group_reactions WHERE target_type = 'discussion' AND target_id = d.id) as reaction_count
        FROM group_discussions d
        JOIN users u ON d.user_id = u.id
        WHERE d.group_id = ?
        ORDER BY d.is_pinned DESC, d.updated_at DESC
    ");
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== ADD DISCUSSION REPLY =====
function addDiscussionReply($discussion_id, $user_id, $content) {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO group_discussion_replies (discussion_id, user_id, content)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$discussion_id, $user_id, $content]);
    $reply_id = $db->lastInsertId();

    $stmt = $db->prepare("UPDATE group_discussions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$discussion_id]);

    $stmt = $db->prepare("SELECT group_id FROM group_discussions WHERE id = ?");
    $stmt->execute([$discussion_id]);
    $group_id = $stmt->fetchColumn();

    logGroupActivity($group_id, $user_id, 'reply', 'reply', $reply_id, ['discussion_id' => $discussion_id]);
    return $reply_id;
}

// ===== GET DISCUSSION REPLIES =====
function getDiscussionReplies($discussion_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT r.*, u.username, u.display_name, u.avatar,
        (SELECT COUNT(*) FROM group_reactions WHERE target_type = 'reply' AND target_id = r.id) as reaction_count
        FROM group_discussion_replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.discussion_id = ?
        ORDER BY r.created_at ASC
    ");
    $stmt->execute([$discussion_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== ADD REACTION =====
function addReaction($target_type, $target_id, $user_id, $reaction_type) {
    global $db;
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO group_reactions (target_type, target_id, user_id, reaction_type)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$target_type, $target_id, $user_id, $reaction_type]);

    $group_id = null;
    switch ($target_type) {
        case 'note':
            $stmt = $db->prepare("SELECT group_id FROM group_notes WHERE id = ?");
            $stmt->execute([$target_id]);
            $group_id = $stmt->fetchColumn();
            break;
        case 'discussion':
            $stmt = $db->prepare("SELECT group_id FROM group_discussions WHERE id = ?");
            $stmt->execute([$target_id]);
            $group_id = $stmt->fetchColumn();
            break;
        case 'reply':
            $stmt = $db->prepare("SELECT d.group_id FROM group_discussion_replies r JOIN group_discussions d ON r.discussion_id = d.id WHERE r.id = ?");
            $stmt->execute([$target_id]);
            $group_id = $stmt->fetchColumn();
            break;
    }

    if ($group_id) {
        logGroupActivity($group_id, $user_id, 'reaction', $target_type, $target_id, ['reaction' => $reaction_type]);
    }
}

// ===== GET REACTIONS =====
function getReactions($target_type, $target_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM group_reactions
        WHERE target_type = ? AND target_id = ?
        GROUP BY reaction_type
    ");
    $stmt->execute([$target_type, $target_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== UPDATE READING PROGRESS =====
function updateReadingProgress($group_id, $user_id, $chapter_index, $status) {
    global $db;
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO group_reading_progress (group_id, user_id, chapter_index, status)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $chapter_index, $status]);

    logGroupActivity($group_id, $user_id, 'progress', 'chapter', $chapter_index, ['status' => $status]);
}

// ===== GET USER READING PROGRESS =====
function getUserReadingProgress($group_id, $user_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT chapter_index, status
        FROM group_reading_progress
        WHERE group_id = ? AND user_id = ?
    ");
    $stmt->execute([$group_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== GET GROUP READING PROGRESS =====
function getGroupReadingProgress($group_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT user_id, chapter_index, status
        FROM group_reading_progress
        WHERE group_id = ?
    ");
    $stmt->execute([$group_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $progress = [];
    foreach ($rows as $row) {
        if (!isset($progress[$row['user_id']])) {
            $progress[$row['user_id']] = [];
        }
        $progress[$row['user_id']][$row['chapter_index']] = $row['status'];
    }
    return $progress;
}

// ===== GET GROUP SCHEDULE =====
function getGroupSchedule($group_id) {
    global $db;
    $stmt = $db->prepare("
        SELECT s.*
        FROM group_schedules s
        WHERE s.group_id = ?
        ORDER BY s.chapter_index ASC
    ");
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== ADD SCHEDULE ITEM =====
function addScheduleItem($group_id, $chapter_index, $due_date) {
    global $db;
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO group_schedules (group_id, chapter_index, due_date)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$group_id, $chapter_index, $due_date]);
}

// ===== LOG GROUP ACTIVITY =====
function logGroupActivity($group_id, $user_id, $activity_type, $target_type = null, $target_id = null, $metadata = null) {
    global $db;
    $metadata_json = $metadata ? json_encode($metadata) : null;
    $stmt = $db->prepare("
        INSERT INTO group_activity_log (group_id, user_id, activity_type, target_type, target_id, metadata)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $activity_type, $target_type, $target_id, $metadata_json]);
}

// ===== GET GROUP ACTIVITY =====
function getGroupActivity($group_id, $limit = 50) {
    global $db;
    $stmt = $db->prepare("
        SELECT a.*, u.username, u.display_name, u.avatar,
        g.name as group_name
        FROM group_activity_log a
        JOIN users u ON a.user_id = u.id
        JOIN reading_groups g ON a.group_id = g.id
        WHERE a.group_id = ?
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$group_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== GET ALL GROUP ACTIVITY (admin) =====
function getAllGroupActivity($limit = 50) {
    global $db;
    $stmt = $db->prepare("
        SELECT a.*, u.username, u.display_name, u.avatar,
        g.name as group_name, g.id as group_id
        FROM group_activity_log a
        JOIN users u ON a.user_id = u.id
        JOIN reading_groups g ON a.group_id = g.id
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== FORMAT ACTIVITY (for display) =====
function formatActivity($activity) {
    $user = htmlspecialchars($activity['display_name'] ?: $activity['username']);
    $group = htmlspecialchars($activity['group_name']);
    $metadata = $activity['metadata'] ? json_decode($activity['metadata'], true) : null;

    switch ($activity['activity_type']) {
        case 'note':
            return "<strong>{$user}</strong> added a note in <strong>{$group}</strong>";
        case 'discussion':
            return "<strong>{$user}</strong> started a discussion in <strong>{$group}</strong>";
        case 'reply':
            return "<strong>{$user}</strong> replied to a discussion in <strong>{$group}</strong>";
        case 'join':
            return "<strong>{$user}</strong> joined <strong>{$group}</strong>";
        case 'progress':
            $chapter = isset($metadata['chapter']) ? 'Chapter ' . ($metadata['chapter'] + 1) : 'a chapter';
            $status = isset($metadata['status']) ? $metadata['status'] : 'updated';
            return "<strong>{$user}</strong> marked {$chapter} as {$status} in <strong>{$group}</strong>";
        case 'reaction':
            $target = isset($metadata['target']) ? $metadata['target'] : 'a post';
            return "<strong>{$user}</strong> reacted to {$target} in <strong>{$group}</strong>";
        case 'create':
            return "<strong>{$user}</strong> created <strong>{$group}</strong>";
        default:
            return "<strong>{$user}</strong> did something in <strong>{$group}</strong>";
    }
}

// ===== TIME AGO HELPER =====
function time_ago($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
    if ($diff < 31536000) return floor($diff / 2592000) . 'mo ago';
    return floor($diff / 31536000) . 'y ago';
}