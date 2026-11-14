<?php
// ============================================================
//  MANAGE_GROUPS_AJAX.PHP – AJAX endpoint for admin/manage_groups.php (site admin only)
// ============================================================
// F46: this file was a copy of the member endpoint (group_ajax.php): its includes were relative
// to the current directory, so it fatalled on every request; it only checked login, not admin;
// and it did not answer any of the four actions admin/manage_groups.php sends. It now answers
// exactly those, for the site admin:
//   get_group_details   (HTML)  group, content, members, recent activity
//   get_activity_feed   (HTML)  latest activity across all groups
//   clear_activity_logs (JSON)  empties group_activity_log
//   get_group_edit_data (JSON)  name and description for the edit form
// Deleting and editing groups are handled by admin/manage_groups.php itself.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reading_groups.php';

aw_staff_request_guard(); // K3
if (!aw_can('manage_content')) { // K1
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

$action = $_POST['action'] ?? '';
$group_id = (int)($_POST['group_id'] ?? 0);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if ($action === 'get_group_details') {
    header('Content-Type: text/html; charset=utf-8');
    $g = getGroupDetails($group_id, (int)$_SESSION['user_id']);
    if (!$g) {
        echo '<p>Group not found.</p>';
        exit;
    }
    $content = $g['book_title'] ?? $g['poem_title'] ?? $g['newsletter_title'] ?? '';
    echo '<h3>' . $h($g['name']) . '</h3>';
    echo '<p>' . $h(ucfirst($g['content_type'])) . ($content !== '' ? ': <strong>' . $h($content) . '</strong>' : '') . '</p>';
    if (!empty($g['description'])) echo '<p>' . nl2br($h($g['description'])) . '</p>';
    echo '<p>' . ($g['is_private'] ? 'Private' : 'Public') . ' · invite code <code>' . $h($g['invite_code']) . '</code> · ' . (int)$g['member_count'] . ' members</p>';
    $m = $db->prepare("SELECT u.id, u.username, u.name, u.first_name, u.last_name, gm.role, gm.joined_at
                       FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY gm.role, u.id");
    $m->execute([$group_id]);
    echo '<h4>Members</h4><ul>';
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $mem) {
        echo '<li>' . $h(awDisplayName($mem)) . ' — ' . $h($mem['role']) . '</li>';
    }
    echo '</ul>';
    $a = $db->prepare("SELECT a.*, u.username, u.display_name, g.name AS group_name
                       FROM group_activity_log a JOIN users u ON u.id = a.user_id JOIN reading_groups g ON g.id = a.group_id
                       WHERE a.group_id = ? ORDER BY a.created_at DESC LIMIT 10");
    $a->execute([$group_id]);
    $acts = $a->fetchAll(PDO::FETCH_ASSOC);
    if ($acts) {
        echo '<h4>Recent activity</h4><ul>';
        foreach ($acts as $act) echo '<li>' . formatActivity($act) . ' <small>' . $h(time_ago($act['created_at'])) . '</small></li>';
        echo '</ul>';
    }
    exit;
}

if ($action === 'get_activity_feed') {
    header('Content-Type: text/html; charset=utf-8');
    $acts = getAllGroupActivity(50);
    if (!$acts) {
        echo '<p style="text-align:center;color:#a08a8a;">No group activity yet.</p>';
        exit;
    }
    echo '<ul class="activity-feed">';
    foreach ($acts as $act) echo '<li>' . formatActivity($act) . ' <small>' . $h(time_ago($act['created_at'])) . '</small></li>';
    echo '</ul>';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'clear_activity_logs') {
    $db->exec("DELETE FROM group_activity_log");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'get_group_edit_data') {
    $stmt = $db->prepare("SELECT name, description FROM reading_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ? ['success' => true, 'name' => $row['name'], 'description' => $row['description']]
                          : ['success' => false, 'error' => 'Group not found.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
