<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/reading_groups.php';

redirectIfNotAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'get_group_details') {
        $group_id = (int)$_POST['group_id'];

        // Fetch group info
        $stmt = $db->prepare("
            SELECT g.*, b.title as book_title, b.author as book_author,
            u.username as creator_username, u.display_name as creator_display_name
            FROM reading_groups g
            JOIN books b ON g.book_id = b.id
            LEFT JOIN users u ON g.creator_id = u.id
            WHERE g.id = ?
        ");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            echo '<p style="color:red;">Group not found.</p>';
            exit;
        }

        // Fetch members
        $members = getGroupMembers($group_id);

        // Fetch notes
        $notes = getGroupNotes($group_id, null, true);

        // Fetch discussions
        $discussions = getGroupDiscussions($group_id);

        // Fetch schedule
        $schedule = getGroupSchedule($group_id);

        // Build HTML
        $html = '<div class="group-details">';
        $html .= '<h3>' . htmlspecialchars($group['name']) . '</h3>';
        $html .= '<p><strong>Book:</strong> ' . htmlspecialchars($group['book_title']) . ' by ' . htmlspecialchars($group['book_author']) . '</p>';
        $html .= '<p><strong>Creator:</strong> ' . htmlspecialchars($group['creator_display_name'] ?: $group['creator_username']) . '</p>';
        $html .= '<p><strong>Invite Code:</strong> <code>' . $group['invite_code'] . '</code></p>';
        $html .= '<p><strong>Created:</strong> ' . date('F j, Y', strtotime($group['created_at'])) . '</p>';
        if ($group['description']) {
            $html .= '<p><strong>Description:</strong> ' . htmlspecialchars($group['description']) . '</p>';
        }

        $html .= '<hr>';

        // Members section
        $html .= '<h4>👥 Members (' . count($members) . ')</h4>';
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
        foreach ($members as $member) {
            $html .= '<div style="background:var(--vanilla);padding:4px 12px;border-radius:20px;font-size:0.85rem;">';
            $html .= htmlspecialchars($member['display_name'] ?: $member['username']);
            $html .= ' <small>(' . $member['role'] . ')</small>';
            $html .= '</div>';
        }
        $html .= '</div>';

        // Notes section
        $html .= '<h4>📝 Notes (' . count($notes) . ')</h4>';
        if (empty($notes)) {
            $html .= '<p style="color:var(--text-light);font-size:0.9rem;">No notes yet.</p>';
        } else {
            $html .= '<div style="max-height:200px;overflow-y:auto;">';
            foreach ($notes as $note) {
                $html .= '<div style="border:1px solid var(--border);border-radius:4px;padding:8px;margin-bottom:8px;">';
                $html .= '<div style="display:flex;justify-content:space-between;">';
                $html .= '<span><strong>' . htmlspecialchars($note['display_name'] ?: $note['username']) . '</strong></span>';
                $html .= '<small>' . time_ago($note['created_at']) . '</small>';
                $html .= '</div>';
                $html .= '<p style="margin:4px 0;">' . htmlspecialchars($note['text']) . '</p>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Discussions section
        $html .= '<h4>💬 Discussions (' . count($discussions) . ')</h4>';
        if (empty($discussions)) {
            $html .= '<p style="color:var(--text-light);font-size:0.9rem;">No discussions yet.</p>';
        } else {
            $html .= '<div style="max-height:200px;overflow-y:auto;">';
            foreach ($discussions as $disc) {
                $html .= '<div style="border:1px solid var(--border);border-radius:4px;padding:8px;margin-bottom:8px;">';
                $html .= '<h5 style="margin:0;">' . htmlspecialchars($disc['title']) . '</h5>';
                $html .= '<p style="margin:4px 0;">' . htmlspecialchars(substr($disc['content'], 0, 100)) . '...</p>';
                $html .= '<small>by ' . htmlspecialchars($disc['display_name'] ?: $disc['username']) . ' • ' . $disc['reply_count'] . ' replies</small>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Schedule section
        $html .= '<h4>📅 Schedule (' . count($schedule) . ')</h4>';
        if (empty($schedule)) {
            $html .= '<p style="color:var(--text-light);font-size:0.9rem;">No schedule set.</p>';
        } else {
            $html .= '<ul style="padding-left:16px;">';
            foreach ($schedule as $item) {
                $html .= '<li>Chapter ' . ($item['chapter_index'] + 1) . ' – Due: ' . date('F j, Y', strtotime($item['due_date'])) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        echo $html;
        exit;
    }
    if ($action === 'get_activity_feed') {
    $activities = getAllGroupActivity(100);
    if (empty($activities)) {
        echo '<div style="text-align:center;padding:40px 20px;color:var(--text-light);">';
        echo '<div style="font-size:3rem;margin-bottom:12px;">📭</div>';
        echo '<p>No activity yet. Activities will appear here as users interact with reading groups.</p>';
        echo '</div>';
        exit;
    }

    $html = '<div class="activity-feed" style="max-height:600px;overflow-y:auto;">';
    $html .= '<table class="activity-table" style="width:100%;border-collapse:collapse;">';
    $html .= '<thead><tr style="background:#f1f3f5;border-bottom:2px solid #ddd;">';
    $html .= '<th style="padding:8px 12px;text-align:left;">Activity</th>';
    $html .= '<th style="padding:8px 12px;text-align:left;">Group</th>';
    $html .= '<th style="padding:8px 12px;text-align:left;">Time</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($activities as $activity) {
        $formatted = formatActivity($activity);
        $time = time_ago($activity['created_at']);
        $html .= '<tr style="border-bottom:1px solid #eee;">';
        $html .= '<td style="padding:8px 12px;">' . $formatted . '</td>';
        $html .= '<td style="padding:8px 12px;">';
        $html .= '<a href="' . SITE_URL . '/group.php?id=' . $activity['group_id'] . '" target="_blank">';
        $html .= htmlspecialchars($activity['group_name']);
        $html .= '</a>';
        $html .= '</td>';
        $html .= '<td style="padding:8px 12px;color:var(--text-light);font-size:0.85rem;">' . $time . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '</div>';
    echo $html;
    exit;
}

if ($action === 'clear_activity_logs') {
    // Check if user is admin
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to clear activity logs.']);
        exit;
    }
    $db->exec("DELETE FROM group_activity_log");
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'get_dashboard_activity') {
    $activities = getAllGroupActivity(10);
    if (empty($activities)) {
        echo '<p style="text-align:center;color:var(--text-light);padding:20px;">No recent activity.</p>';
        exit;
    }

    $html = '<div class="dashboard-activity-feed">';
    foreach ($activities as $activity) {
        $formatted = formatActivity($activity);
        $time = time_ago($activity['created_at']);
        $html .= '<div style="padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:0.9rem;">';
        $html .= $formatted . ' <small style="color:var(--text-light);">' . $time . '</small>';
        $html .= '</div>';
    }
    $html .= '</div>';
    echo $html;
    exit;
}

    if ($action === 'get_group_edit_data') {
        $group_id = (int)$_POST['group_id'];
        $stmt = $db->prepare("SELECT name, description FROM reading_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(['success' => true, 'name' => $row['name'], 'description' => $row['description']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Group not found.']);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);