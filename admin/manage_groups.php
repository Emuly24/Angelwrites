<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/reading_groups.php';

redirectIfNotAdmin();

$pageTitle = 'Manage Reading Groups';
require_once '../includes/header.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete_group') {
        $group_id = (int)$_POST['group_id'];
        // Delete group and all related data
        $db->exec("DELETE FROM reading_groups WHERE id = $group_id");
        $db->exec("DELETE FROM group_members WHERE group_id = $group_id");
        $db->exec("DELETE FROM group_notes WHERE group_id = $group_id");
        $db->exec("DELETE FROM group_discussions WHERE group_id = $group_id");
        $db->exec("DELETE FROM group_schedules WHERE group_id = $group_id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_member') {
        $group_id = (int)$_POST['group_id'];
        $user_id = (int)$_POST['user_id'];
        $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'change_role') {
        $group_id = (int)$_POST['group_id'];
        $user_id = (int)$_POST['user_id'];
        $role = $_POST['role'];
        $stmt = $db->prepare("UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$role, $group_id, $user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_group') {
        $group_id = (int)$_POST['group_id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $stmt = $db->prepare("UPDATE reading_groups SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $group_id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// Fetch all groups with stats
$stmt = $db->prepare("
    SELECT g.*, b.title as book_title, b.author as book_author,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
    (SELECT COUNT(*) FROM group_notes WHERE group_id = g.id) as note_count,
    (SELECT COUNT(*) FROM group_discussions WHERE group_id = g.id) as discussion_count,
    u.username as creator_username, u.display_name as creator_display_name
    FROM reading_groups g
    JOIN books b ON g.book_id = b.id
    LEFT JOIN users u ON g.creator_id = u.id
    ORDER BY g.created_at DESC
");
$stmt->execute();
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-manage-groups">
    <div class="container">
        <div class="admin-header">
            <h1>📚 Manage Reading Groups</h1>
            <div class="admin-tabs">
    <button class="tab-btn active" data-tab="groups">📚 Groups</button>
    <button class="tab-btn" data-tab="activity">📊 Activity Feed</button>
</div>
            <div class="header-actions">
                <span class="total-groups">Total: <?php echo count($groups); ?> groups</span>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if (empty($groups)): ?>
            <div class="empty-state">
                <div class="empty-icon">📖</div>
                <h2>No reading groups yet</h2>
                <p>Users haven't created any reading groups yet.</p>
            </div>
        <?php else: ?>
            <div class="groups-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Book</th>
                            <th>Creator</th>
                            <th>Members</th>
                            <th>Notes</th>
                            <th>Discussions</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                                    <div class="group-invite-code">
                                        <small>Code: <code><?php echo $group['invite_code']; ?></code></small>
                                    </div>
                                    <?php if ($group['description']): ?>
                                        <div class="group-desc-small">
                                            <?php echo htmlspecialchars(substr($group['description'], 0, 40)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($group['book_title']); ?>
                                    <br>
                                    <small><?php echo htmlspecialchars($group['book_author']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($group['creator_display_name'] ?: $group['creator_username']); ?>
                                </td>
                                <td><?php echo $group['member_count']; ?></td>
                                <td><?php echo $group['note_count']; ?></td>
                                <td><?php echo $group['discussion_count']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($group['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-info" onclick="viewGroupDetails(<?php echo $group['id']; ?>)">
                                            👁️ View
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="editGroup(<?php echo $group['id']; ?>)">
                                            ✏️
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteGroup(<?php echo $group['id']; ?>)">
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- Activity Feed Tab -->
<div class="tab-content" id="tab-activity" style="display:none;">
    <div class="activity-feed-header">
        <h2>📊 Recent Group Activity</h2>
        <div class="activity-controls">
            <button class="btn btn-sm btn-outline" onclick="loadActivityFeed()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="btn btn-sm btn-outline" onclick="clearAllActivity()" style="color:#dc3545;">
                <i class="fas fa-trash"></i> Clear All
            </button>
        </div>
    </div>
    <div id="activityFeedContainer">
        <p style="text-align:center;color:#999;">Loading activity feed...</p>
    </div>
</div>

<!-- View Group Details Modal -->
<div id="groupDetailsModal" class="modal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="modalGroupTitle">Group Details</h2>
            <button onclick="closeGroupDetails()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body" id="modalGroupBody">
            <div id="groupDetailsContent">Loading...</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeGroupDetails()">Close</button>
        </div>
    </div>
</div>

<!-- Edit Group Modal -->
<div id="editGroupModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>✏️ Edit Group</h2>
            <button onclick="closeEditGroup()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editGroupForm" onsubmit="submitEditGroup(event)">
                <input type="hidden" id="edit_group_id" name="group_id">
                <div class="form-group">
                    <label for="edit_group_name">Group Name</label>
                    <input type="text" id="edit_group_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="edit_group_description">Description</label>
                    <textarea id="edit_group_description" name="description" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
function viewGroupDetails(groupId) {
    document.getElementById('modalGroupTitle').textContent = '📚 Group Details';
    document.getElementById('groupDetailsContent').innerHTML = '<p style="text-align:center;color:#999;">Loading...</p>';
    document.getElementById('groupDetailsModal').style.display = 'flex';

    const formData = new FormData();
    formData.append('action', 'get_group_details');
    formData.append('group_id', groupId);

    fetch('manage_groups_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        document.getElementById('groupDetailsContent').innerHTML = html;
    })
    .catch(error => {
        document.getElementById('groupDetailsContent').innerHTML = '<p style="color:red;">Error loading details: ' + error.message + '</p>';
    });
}
// Tab switching
document.querySelectorAll('.admin-tabs .tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.admin-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
        document.getElementById('tab-' + this.dataset.tab).style.display = 'block';
        if (this.dataset.tab === 'activity') {
            loadActivityFeed();
        }
    });
});

function loadActivityFeed() {
    const container = document.getElementById('activityFeedContainer');
    container.innerHTML = '<p style="text-align:center;color:#999;">Loading activity feed...</p>';

    const formData = new FormData();
    formData.append('action', 'get_activity_feed');

    fetch('manage_groups_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        container.innerHTML = html;
    })
    .catch(error => {
        container.innerHTML = '<p style="color:red;">Error loading activity: ' + error.message + '</p>';
    });
}

function clearAllActivity() {
    if (!confirm('Are you sure you want to clear all activity logs? This cannot be undone.')) {
        return;
    }
    if (!confirm('Confirm again: All group activity history will be permanently deleted.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'clear_activity_logs');

    fetch('manage_groups_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadActivityFeed();
            alert('✅ Activity logs cleared.');
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

// Load activity feed by default if the tab is active
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.tab-btn[data-tab="activity"].active')) {
        loadActivityFeed();
    }
});

function closeGroupDetails() {
    document.getElementById('groupDetailsModal').style.display = 'none';
}

function editGroup(groupId) {
    document.getElementById('edit_group_id').value = groupId;
    // Pre-fill with current data (simplified – we'll fetch via AJAX)
    const formData = new FormData();
    formData.append('action', 'get_group_edit_data');
    formData.append('group_id', groupId);

    fetch('manage_groups_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('edit_group_name').value = data.name;
            document.getElementById('edit_group_description').value = data.description || '';
            document.getElementById('editGroupModal').style.display = 'flex';
        } else {
            alert('Error loading group data: ' + data.error);
        }
    });
}

function closeEditGroup() {
    document.getElementById('editGroupModal').style.display = 'none';
}

function submitEditGroup(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'update_group');

    fetch('manage_groups.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditGroup();
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    });
}

function deleteGroup(groupId) {
    if (!confirm('Are you sure you want to delete this group? All related data (notes, discussions, members) will be permanently removed.')) {
        return;
    }
    if (!confirm('This action cannot be undone. Confirm again?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_group');
    formData.append('group_id', groupId);

    fetch('manage_groups.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error deleting group: ' + (data.error || 'Unknown error'));
        }
    });
}

// Modal close on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<style>
.admin-manage-groups { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { margin: 0; }
.total-groups { background: var(--vanilla); padding: 4px 12px; border-radius: 20px; font-size: 0.9rem; }

.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th { background: var(--vanilla); padding: 10px 12px; text-align: left; border-bottom: 2px solid var(--border); }
.admin-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); }
.admin-table tr:hover { background: rgba(0,0,0,0.02); }

.group-invite-code code { background: #f1f3f5; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; }
.group-desc-small { font-size: 0.85rem; color: var(--text-light); }
.action-buttons { display: flex; gap: 4px; }

.modal-lg { max-width: 800px; width: 90%; }
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
.modal-content { background: white; max-width: 520px; width: 90%; border-radius: 12px; padding: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
.modal-content.modal-lg { max-width: 800px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-header h2 { margin: 0; }
.close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999; }
.close-btn:hover { color: #333; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: 500; }
.form-group input, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 1rem; }
.modal-footer { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); text-align: right; }

.empty-state { text-align: center; padding: 60px 20px; }
.empty-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.6; }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; border-radius: 4px; }
.admin-tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 2px solid var(--border); flex-wrap: wrap; }
.admin-tabs .tab-btn { padding: 8px 16px; border: none; background: none; cursor: pointer; font-size: 0.95rem; border-radius: 6px 6px 0 0; }
.admin-tabs .tab-btn:hover { background: var(--vanilla); }
.admin-tabs .tab-btn.active { background: var(--rose); color: white; }
.tab-content { display: none; }
.tab-content.active { display: block; }

.activity-feed-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.activity-feed-header h2 { margin: 0; }
.activity-controls { display: flex; gap: 8px; }

.activity-table tbody tr:hover { background: rgba(0,0,0,0.02); }
.activity-table a { color: var(--rose); text-decoration: none; }
.activity-table a:hover { text-decoration: underline; }
</style>

<?php require_once '../includes/footer.php'; ?>