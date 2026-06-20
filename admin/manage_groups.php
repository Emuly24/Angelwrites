<?php
// ============================================================
//  MANAGE_GROUPS.PHP – Admin management for all reading groups
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/reading_groups.php';

redirectIfNotAdmin();

$pageTitle = 'Manage Reading Groups';
require_once '../includes/header.php';

// ===== HANDLE POST ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete_group') {
        $group_id = (int)$_POST['group_id'];
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

// ===== FETCH ALL GROUPS WITH CONTENT AND STATS =====
$groups = [];
$db_error = null;

try {
    $stmt = $db->prepare("
        SELECT g.*,
               b.title as book_title, b.author as book_author,
               p.title as poem_title, p.author as poem_author,
               n.title as newsletter_title, n.author as newsletter_author,
               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
               (SELECT COUNT(*) FROM group_notes WHERE group_id = g.id) as note_count,
               (SELECT COUNT(*) FROM group_discussions WHERE group_id = g.id) as discussion_count,
               u.username as creator_username, u.display_name as creator_display_name
        FROM reading_groups g
        LEFT JOIN books b ON g.content_type = 'book' AND g.content_id = b.id
        LEFT JOIN poems p ON g.content_type = 'poem' AND g.content_id = p.id
        LEFT JOIN newsletters n ON g.content_type = 'newsletter' AND g.content_id = n.id
        LEFT JOIN users u ON g.creator_id = u.id
        ORDER BY g.created_at DESC
    ");
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log("Manage groups query failed: " . $db_error);
}
?>

<div class="admin-manage-groups">
    <div class="container">
        <!-- Header -->
        <div class="admin-header">
            <div>
                <h1>📚 Manage Reading Groups</h1>
                <p class="subtitle">Overview of all reading groups across books, poems, and newsletters.</p>
            </div>
            <div class="header-actions">
                <span class="total-groups">Total: <strong><?php echo count($groups); ?></strong> groups</span>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Tabs -->
        <div class="admin-tabs">
            <button class="tab-btn active" data-tab="groups">📚 Groups</button>
            <button class="tab-btn" data-tab="activity">📊 Activity Feed</button>
        </div>

        <!-- Database Error Alert -->
        <?php if ($db_error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                Database error: <code><?php echo htmlspecialchars($db_error); ?></code>
            </div>
        <?php endif; ?>

        <!-- ===== TAB: GROUPS ===== -->
        <div class="tab-content" id="tab-groups">
            <?php if (empty($groups)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📖</div>
                    <h2>No reading groups yet</h2>
                    <p>Users haven't created any reading groups for books, poems, or newsletters yet.</p>
                </div>
            <?php else: ?>
                <div class="groups-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Content</th>
                                <th>Type</th>
                                <th>Creator</th>
                                <th>Members</th>
                                <th>Notes</th>
                                <th>Discussions</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group):
                                // Determine content title and author
                                $content_title = $group['book_title'] ?? $group['poem_title'] ?? $group['newsletter_title'] ?? 'Unknown';
                                $content_author = $group['book_author'] ?? $group['poem_author'] ?? $group['newsletter_author'] ?? '';
                                $type_label = ucfirst($group['content_type']);
                                $type_icon = [
                                    'book' => '📖',
                                    'poem' => '✍️',
                                    'newsletter' => '📰'
                                ][$group['content_type']] ?? '📄';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                                    <div class="group-invite-code">
                                        <small>Code: <code><?php echo $group['invite_code']; ?></code></small>
                                    </div>
                                    <?php if ($group['description']): ?>
                                        <div class="group-desc-small">
                                            <?php echo htmlspecialchars(substr($group['description'], 0, 40)); ?>…
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($content_title); ?>
                                    <br>
                                    <small><?php echo htmlspecialchars($content_author); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $group['content_type']; ?>">
                                        <?php echo $type_icon . ' ' . $type_label; ?>
                                    </span>
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

        <!-- ===== TAB: ACTIVITY FEED ===== -->
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
    </div>
</div>

<!-- ===== MODALS ===== -->

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

<!-- ===== JAVASCRIPT ===== -->
<script>
// View Group Details
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

// Load Activity Feed
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

// Clear Activity
function clearAllActivity() {
    if (!confirm('Are you sure you want to clear all activity logs? This cannot be undone.')) return;
    if (!confirm('Confirm again: All group activity history will be permanently deleted.')) return;

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

// Edit Group
function editGroup(groupId) {
    document.getElementById('edit_group_id').value = groupId;
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

// Delete Group
function deleteGroup(groupId) {
    if (!confirm('Are you sure you want to delete this group? All related data will be permanently removed.')) return;
    if (!confirm('This action cannot be undone. Confirm again?')) return;

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

function closeGroupDetails() {
    document.getElementById('groupDetailsModal').style.display = 'none';
}

// Close modals on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});

// Load activity feed on initial load if tab is active
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.tab-btn[data-tab="activity"].active')) {
        loadActivityFeed();
    }
});
</script>

<style>
/* ============================================================
   STYLES – Professional Admin Panel
   ============================================================ */
.admin-manage-groups { padding: 32px 0 60px; background: var(--bg); }

.admin-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    flex-wrap: wrap; gap: 16px; margin-bottom: 24px;
}
.admin-header h1 { margin: 0 0 4px 0; font-size: 2rem; color: var(--dark); }
.admin-header .subtitle { margin: 0; color: var(--text-light); font-size: 0.95rem; }
.header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.total-groups {
    background: var(--vanilla); padding: 6px 14px; border-radius: 20px;
    font-size: 0.9rem; color: var(--text);
}

.admin-tabs {
    display: flex; gap: 4px; margin-bottom: 24px;
    border-bottom: 2px solid var(--border); flex-wrap: wrap;
}
.admin-tabs .tab-btn {
    padding: 10px 20px; border: none; background: none; cursor: pointer;
    font-size: 0.95rem; font-weight: 500; color: var(--text-light);
    border-radius: 8px 8px 0 0; transition: all 0.2s;
}
.admin-tabs .tab-btn:hover { background: var(--vanilla); color: var(--text); }
.admin-tabs .tab-btn.active {
    background: var(--rose); color: white; font-weight: 600;
}
.tab-content { display: none; }
.tab-content.active { display: block; }

.alert-danger {
    background: #f8d7da; color: #721c24; padding: 12px 16px;
    border-radius: 8px; border: 1px solid #f5c6cb; margin-bottom: 20px;
}

.groups-table-container { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
.admin-table {
    width: 100%; border-collapse: collapse; font-size: 0.9rem;
    background: var(--card-bg);
}
.admin-table th {
    background: var(--vanilla); padding: 12px 14px; text-align: left;
    border-bottom: 2px solid var(--border); font-weight: 600; color: var(--text);
}
.admin-table td {
    padding: 12px 14px; border-bottom: 1px solid var(--border); color: var(--text);
}
.admin-table tbody tr:last-child td { border-bottom: none; }
.admin-table tbody tr:hover { background: rgba(219, 161, 162, 0.04); }

.group-invite-code code {
    background: var(--bg); padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; color: var(--text);
}
.group-desc-small { font-size: 0.85rem; color: var(--text-light); margin-top: 2px; }

/* Badges for Content Type */
.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.badge-book { background: #e3f2fd; color: #0d47a1; }
.badge-poem { background: #f3e5f5; color: #4a148c; }
.badge-newsletter { background: #e8f5e9; color: #1b5e20; }

.action-buttons { display: flex; gap: 4px; flex-wrap: nowrap; }
.action-buttons .btn { padding: 4px 10px; font-size: 0.8rem; border-radius: 4px; }

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border); }
.empty-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.6; }
.empty-state h2 { margin-bottom: 8px; color: var(--text); }
.empty-state p { color: var(--text-light); }

/* Modals */
.modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-content {
    background: var(--card-bg); max-width: 520px; width: 90%; border-radius: 16px;
    padding: 24px; box-shadow: var(--shadow-hover);
}
.modal-content.modal-lg { max-width: 800px; }
.modal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
}
.modal-header h2 { margin: 0; font-size: 1.3rem; color: var(--dark); }
.close-btn {
    background: none; border: none; font-size: 1.5rem; cursor: pointer;
    color: var(--text-light); transition: color 0.2s;
}
.close-btn:hover { color: var(--rose); }
.modal-body { max-height: 70vh; overflow-y: auto; }
.modal-footer { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); text-align: right; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: 500; color: var(--text); }
.form-group input, .form-group textarea {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border);
    border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text);
}
.form-group input:focus, .form-group textarea:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.1);
}

/* Activity Feed */
.activity-feed-header {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px; margin-bottom: 16px;
}
.activity-feed-header h2 { margin: 0; }
.activity-controls { display: flex; gap: 8px; }

/* Responsive */
@media (max-width: 768px) {
    .admin-header { flex-direction: column; align-items: flex-start; }
    .header-actions { width: 100%; justify-content: flex-start; }
    .admin-table { font-size: 0.8rem; }
    .admin-table th, .admin-table td { padding: 8px 10px; }
    .action-buttons .btn { padding: 2px 6px; font-size: 0.7rem; }
}
@media (max-width: 480px) {
    .admin-table { font-size: 0.75rem; }
    .admin-table th, .admin-table td { padding: 6px 8px; }
    .total-groups { font-size: 0.8rem; }
}
</style>

<?php require_once '../includes/footer.php'; ?>