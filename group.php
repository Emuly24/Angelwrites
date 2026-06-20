<?php
// ============================================================
//  GROUP.PHP – Single Reading Group Page
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$group_id) {
    header('Location: groups.php');
    exit;
}

// ===== FETCH GROUP DETAILS (with content_type and content_id) =====
$group = getGroupDetails($group_id, $user_id);
if (!$group) {
    header('Location: groups.php');
    exit;
}

// Check if user is a member
if (!$group['user_role']) {
    header('Location: groups.php');
    exit;
}

$is_admin = in_array($group['user_role'], ['admin', 'creator']);

// ===== FETCH RELATED DATA =====
$members = getGroupMembers($group_id);
$discussions = getGroupDiscussions($group_id);
$progress = getGroupReadingProgress($group_id);
$schedule = getGroupSchedule($group_id);
$notes = getGroupNotes($group_id);
$activity = getGroupActivity($group_id);

// ===== DETERMINE CONTENT TYPE AND CHAPTERS =====
$content_type = $group['content_type']; // 'book', 'poem', 'newsletter'
$content_id = $group['content_id'];

$total_chapters = 1; // default for poems/newsletters
$content_title = '';
$content_author = '';

switch ($content_type) {
    case 'book':
        $stmt = $db->prepare("SELECT content_html FROM book_content WHERE book_id = ?");
        $stmt->execute([$content_id]);
        $content = $stmt->fetchColumn();
        if ($content) {
            preg_match_all('/<div class="chapter-container"/', $content, $matches);
            $total_chapters = count($matches[0]) ?: 1;
        }
        // Fetch book title/author (already in group array as book_title/book_author)
        $content_title = $group['book_title'];
        $content_author = $group['book_author'];
        break;
    case 'poem':
        // Poem has no chapters; treat as one "chapter"
        $total_chapters = 1;
        $content_title = $group['poem_title'];
        $content_author = $group['poem_author'];
        break;
    case 'newsletter':
        $total_chapters = 1;
        $content_title = $group['newsletter_title'];
        $content_author = $group['newsletter_author'];
        break;
    default:
        $content_title = 'Unknown';
        $content_author = '';
}

// Helper for displaying content type badge
$type_icon = [
    'book' => '📖',
    'poem' => '✍️',
    'newsletter' => '📰'
][$content_type] ?? '📄';
$type_label = ucfirst($content_type);

$pageTitle = htmlspecialchars($group['name']);
?>
<?php require_once 'includes/header.php'; ?>

<div class="group-page">
    <div class="container">
        <!-- ===== GROUP HEADER ===== -->
        <div class="group-header">
            <div class="group-title">
                <h1><?php echo htmlspecialchars($group['name']); ?></h1>
                <p class="book-info">
                    <span class="content-type-badge badge-<?php echo $content_type; ?>">
                        <?php echo $type_icon . ' ' . $type_label; ?>
                    </span>
                    <?php echo htmlspecialchars($content_title); ?>
                    <small>by <?php echo htmlspecialchars($content_author); ?></small>
                </p>
                <?php if ($group['description']): ?>
                    <p class="group-description"><?php echo htmlspecialchars($group['description']); ?></p>
                <?php endif; ?>
            </div>
            <div class="group-actions">
                <span class="invite-code">
                    <strong>Invite Code:</strong> <code><?php echo $group['invite_code']; ?></code>
                    <button onclick="copyInviteCode()" class="btn btn-sm btn-outline">📋 Copy</button>
                </span>
                <?php if ($is_admin): ?>
                    <button onclick="showEditGroup()" class="btn btn-sm btn-outline">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== TABS ===== -->
        <div class="group-tabs">
            <button class="tab-btn active" data-tab="discussions">💬 Discussions</button>
            <button class="tab-btn" data-tab="notes">📝 Notes</button>
            <button class="tab-btn" data-tab="progress">📊 Progress</button>
            <button class="tab-btn" data-tab="members">👥 Members</button>
            <button class="tab-btn" data-tab="schedule">📅 Schedule</button>
            <button class="tab-btn" data-tab="activity">📈 Activity</button>
        </div>

        <!-- ===== DISCUSSIONS TAB ===== -->
        <div class="tab-content active" id="tab-discussions">
            <div class="discussions-header">
                <h3>Discussions</h3>
                <button onclick="showCreateDiscussion()" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Discussion
                </button>
            </div>
            <div class="discussions-list">
                <?php if (empty($discussions)): ?>
                    <p class="empty-message">No discussions yet. Start one!</p>
                <?php else: ?>
                    <?php foreach ($discussions as $disc): ?>
                        <div class="discussion-item <?php echo $disc['is_pinned'] ? 'pinned' : ''; ?>">
                            <div class="discussion-header">
                                <h4><?php echo htmlspecialchars($disc['title']); ?></h4>
                                <span class="discussion-meta">
                                    by <?php echo htmlspecialchars($disc['display_name'] ?: $disc['username']); ?>
                                    <small><?php echo time_ago($disc['created_at']); ?></small>
                                </span>
                            </div>
                            <p class="discussion-preview"><?php echo htmlspecialchars(substr($disc['content'], 0, 150)); ?>...</p>
                            <div class="discussion-footer">
                                <span class="reaction-badges">
                                    <?php
                                    $reactions = getReactions('discussion', $disc['id']);
                                    foreach ($reactions as $reaction): ?>
                                        <span class="reaction"><?php echo $reaction['reaction_type']; ?> <?php echo $reaction['count']; ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <span><?php echo $disc['reply_count']; ?> replies</span>
                                <a href="discussion.php?id=<?php echo $disc['id']; ?>" class="btn btn-sm btn-outline">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== NOTES TAB ===== -->
        <div class="tab-content" id="tab-notes">
            <div class="notes-header">
                <h3>Group Notes</h3>
                <button onclick="showAddNote()" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Note
                </button>
            </div>
            <div class="notes-list">
                <?php if (empty($notes)): ?>
                    <p class="empty-message">No notes yet. Highlight a paragraph and add your thoughts!</p>
                <?php else: ?>
                    <?php foreach ($notes as $note): ?>
                        <div class="note-item">
                            <div class="note-meta">
                                <span class="note-author">
                                    <?php echo htmlspecialchars($note['display_name'] ?: $note['username']); ?>
                                </span>
                                <small><?php echo time_ago($note['created_at']); ?></small>
                                <?php if ($note['is_private']): ?>
                                    <span class="badge-private">🔒 Private</span>
                                <?php endif; ?>
                            </div>
                            <p class="note-text"><?php echo htmlspecialchars($note['text']); ?></p>
                            <?php if ($note['chapter_index'] !== null && $content_type === 'book'): ?>
                                <div class="note-location">
                                    <span>Chapter <?php echo $note['chapter_index'] + 1; ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="note-footer">
                                <span class="reaction-badges">
                                    <?php
                                    $reactions = getReactions('note', $note['id']);
                                    foreach ($reactions as $reaction): ?>
                                        <span class="reaction"><?php echo $reaction['reaction_type']; ?> <?php echo $reaction['count']; ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <?php if ($note['user_id'] == $user_id || $is_admin): ?>
                                    <button onclick="deleteNote(<?php echo $note['id']; ?>)" class="btn btn-sm btn-danger">🗑️</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== PROGRESS TAB ===== -->
        <div class="tab-content" id="tab-progress">
            <h3>Reading Progress</h3>
            <div class="progress-container">
                <table class="progress-table">
                    <thead>
                        <tr>
                            <th><?php echo $content_type === 'book' ? 'Chapter' : 'Section'; ?></th>
                            <?php foreach ($members as $member): ?>
                                <th><?php echo htmlspecialchars($member['display_name'] ?: $member['username']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < $total_chapters; $i++): ?>
                            <tr>
                                <td>
                                    <?php echo $content_type === 'book' ? 'Chapter ' . ($i + 1) : 'Section ' . ($i + 1); ?>
                                </td>
                                <?php foreach ($members as $member): ?>
                                    <td>
                                        <?php
                                        $status = isset($progress[$member['id']][$i]) ? $progress[$member['id']][$i] : 'unread';
                                        $status_icon = $status === 'finished' ? '✅' : ($status === 'reading' ? '📖' : '⬜');
                                        ?>
                                        <span title="<?php echo $status; ?>"><?php echo $status_icon; ?></span>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="my-progress">
                <h4>My Progress</h4>
                <?php
                $my_progress = getUserReadingProgress($group_id, $user_id);
                ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php for ($i = 0; $i < $total_chapters; $i++): ?>
                        <?php
                        $status = isset($my_progress[$i]) ? $my_progress[$i]['status'] : 'unread';
                        $btn_class = $status === 'finished' ? 'btn-success' : ($status === 'reading' ? 'btn-warning' : 'btn-outline');
                        ?>
                        <button class="btn btn-sm <?php echo $btn_class; ?>" onclick="updateProgress(<?php echo $i; ?>)">
                            <?php echo $i + 1; ?>
                        </button>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- ===== MEMBERS TAB ===== -->
        <div class="tab-content" id="tab-members">
            <h3>Members</h3>
            <div class="members-grid">
                <?php foreach ($members as $member): ?>
                    <div class="member-card">
                        <div class="member-avatar">
                            <?php if ($member['avatar']): ?>
                                <img src="<?php echo htmlspecialchars($member['avatar']); ?>" alt="Avatar">
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?php echo strtoupper(substr($member['display_name'] ?: $member['username'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="member-info">
                            <h4><?php echo htmlspecialchars($member['display_name'] ?: $member['username']); ?></h4>
                            <span class="member-role"><?php echo ucfirst($member['role']); ?></span>
                        </div>
                        <?php if ($is_admin && $member['id'] != $user_id): ?>
                            <div style="margin-top:8px;">
                                <button onclick="changeMemberRole(<?php echo $member['id']; ?>, 'admin')" class="btn btn-sm btn-outline">Make Admin</button>
                                <button onclick="changeMemberRole(<?php echo $member['id']; ?>, 'reader')" class="btn btn-sm btn-outline">Make Reader</button>
                                <button onclick="removeMember(<?php echo $member['id']; ?>)" class="btn btn-sm btn-danger">Remove</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== SCHEDULE TAB ===== -->
        <div class="tab-content" id="tab-schedule">
            <h3>Reading Schedule</h3>
            <?php if ($is_admin): ?>
                <button onclick="showAddSchedule()" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Schedule Item
                </button>
            <?php endif; ?>
            <div class="schedule-list">
                <?php if (empty($schedule)): ?>
                    <p class="empty-message">No schedule set. Add a reading plan!</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($schedule as $item): ?>
                            <li>
                                <strong>
                                    <?php echo $content_type === 'book' ? 'Chapter ' . ($item['chapter_index'] + 1) : 'Section ' . ($item['chapter_index'] + 1); ?>
                                </strong>
                                – Due: <?php echo date('F j, Y', strtotime($item['due_date'])); ?>
                                <?php if ($is_admin): ?>
                                    <button onclick="deleteSchedule(<?php echo $item['id']; ?>)" class="btn btn-sm btn-danger">🗑️</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== ACTIVITY TAB ===== -->
        <div class="tab-content" id="tab-activity">
            <h3>Recent Activity</h3>
            <div class="activity-feed">
                <?php if (empty($activity)): ?>
                    <p class="empty-message">No activity yet.</p>
                <?php else: ?>
                    <?php foreach ($activity as $act): ?>
                        <div class="activity-item">
                            <div class="activity-avatar">
                                <?php if ($act['avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($act['avatar']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <div class="avatar-placeholder" style="width:32px;height:32px;">
                                        <?php echo strtoupper(substr($act['display_name'] ?: $act['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="activity-content">
                                <p><?php echo formatActivity($act); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODALS ===== -->

<!-- Create Discussion Modal -->
<div id="createDiscussionModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>💬 New Discussion</h2>
            <button onclick="closeCreateDiscussion()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createDiscussionForm" onsubmit="submitDiscussion(event)">
                <div class="form-group">
                    <label for="discussion_title">Title</label>
                    <input type="text" id="discussion_title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="discussion_content">Content</label>
                    <textarea id="discussion_content" name="content" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="discussion_chapter">Tie to chapter (optional)</label>
                    <select id="discussion_chapter" name="chapter_index">
                        <option value="">No specific chapter</option>
                        <?php for ($i = 0; $i < $total_chapters; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $content_type === 'book' ? 'Chapter ' . ($i + 1) : 'Section ' . ($i + 1); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Create Discussion</button>
            </form>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div id="addNoteModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📝 Add Note</h2>
            <button onclick="closeAddNote()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addNoteForm" onsubmit="submitNote(event)">
                <div class="form-group">
                    <label for="note_text">Your note</label>
                    <textarea id="note_text" name="text" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="note_chapter">Chapter (optional)</label>
                    <select id="note_chapter" name="chapter_index">
                        <option value="">No specific chapter</option>
                        <?php for ($i = 0; $i < $total_chapters; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $content_type === 'book' ? 'Chapter ' . ($i + 1) : 'Section ' . ($i + 1); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_private" value="1">
                        Make this note private (only visible to me)
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">Add Note</button>
            </form>
        </div>
    </div>
</div>

<!-- Add Schedule Modal -->
<div id="addScheduleModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📅 Add Schedule Item</h2>
            <button onclick="closeAddSchedule()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addScheduleForm" onsubmit="submitSchedule(event)">
                <div class="form-group">
                    <label for="schedule_chapter">Chapter</label>
                    <select id="schedule_chapter" name="chapter_index" required>
                        <?php for ($i = 0; $i < $total_chapters; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $content_type === 'book' ? 'Chapter ' . ($i + 1) : 'Section ' . ($i + 1); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="schedule_due_date">Due Date</label>
                    <input type="date" id="schedule_due_date" name="due_date" required>
                </div>
                <button type="submit" class="btn btn-primary">Add Schedule</button>
            </form>
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
                <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                <div class="form-group">
                    <label for="edit_group_name">Group Name</label>
                    <input type="text" id="edit_group_name" name="name" value="<?php echo htmlspecialchars($group['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="edit_group_description">Description</label>
                    <textarea id="edit_group_description" name="description" rows="3"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
// ===== COPY INVITE CODE =====
function copyInviteCode() {
    const code = document.querySelector('.invite-code code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        alert('Invite code copied!');
    });
}

// ===== TABS =====
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// ===== DISCUSSIONS =====
function showCreateDiscussion() {
    document.getElementById('createDiscussionModal').style.display = 'flex';
}
function closeCreateDiscussion() {
    document.getElementById('createDiscussionModal').style.display = 'none';
}
function submitDiscussion(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'create_discussion');
    formData.append('group_id', <?php echo $group_id; ?>);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            closeCreateDiscussion();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== NOTES =====
function showAddNote() {
    document.getElementById('addNoteModal').style.display = 'flex';
}
function closeAddNote() {
    document.getElementById('addNoteModal').style.display = 'none';
}
function submitNote(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'add_note');
    formData.append('group_id', <?php echo $group_id; ?>);
    // Note: we no longer need book_id, but we keep it for backward compatibility if needed
    // The backend can infer content from group_id
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            closeAddNote();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}
function deleteNote(noteId) {
    if (!confirm('Delete this note?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_note');
    formData.append('note_id', noteId);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== PROGRESS =====
function updateProgress(chapterIndex) {
    const formData = new FormData();
    formData.append('action', 'update_progress');
    formData.append('group_id', <?php echo $group_id; ?>);
    formData.append('chapter_index', chapterIndex);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== SCHEDULE =====
function showAddSchedule() {
    document.getElementById('addScheduleModal').style.display = 'flex';
}
function closeAddSchedule() {
    document.getElementById('addScheduleModal').style.display = 'none';
}
function submitSchedule(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'add_schedule');
    formData.append('group_id', <?php echo $group_id; ?>);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            closeAddSchedule();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}
function deleteSchedule(scheduleId) {
    if (!confirm('Delete this schedule item?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_schedule');
    formData.append('schedule_id', scheduleId);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== EDIT GROUP =====
function showEditGroup() {
    document.getElementById('editGroupModal').style.display = 'flex';
    document.getElementById('edit_group_name').value = <?php echo json_encode($group['name']); ?>;
    document.getElementById('edit_group_description').value = <?php echo json_encode($group['description'] ?? ''); ?>;
}
function closeEditGroup() {
    document.getElementById('editGroupModal').style.display = 'none';
}
function submitEditGroup(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'update_group');
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            closeEditGroup();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== MEMBERS (admin actions) =====
function changeMemberRole(userId, role) {
    if (!confirm('Change this member\'s role to ' + role + '?')) return;
    const formData = new FormData();
    formData.append('action', 'change_member_role');
    formData.append('group_id', <?php echo $group_id; ?>);
    formData.append('user_id', userId);
    formData.append('role', role);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}
function removeMember(userId) {
    if (!confirm('Remove this member from the group?')) return;
    const formData = new FormData();
    formData.append('action', 'remove_member');
    formData.append('group_id', <?php echo $group_id; ?>);
    formData.append('user_id', userId);
    fetch('group_ajax.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ===== MODAL CLOSE ON OUTSIDE CLICK =====
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>

<style>
/* ============================================================
   GROUP PAGE STYLES – Enhanced & Professional
   ============================================================ */
.group-page { padding: 32px 0 60px; background: var(--bg); }
.group-header { margin-bottom: 28px; }
.group-title h1 { margin: 0 0 6px; font-size: 2.2rem; color: var(--dark); }

.book-info {
    color: var(--text-light);
    margin: 0 0 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.book-info small { color: var(--text-light); }
.content-type-badge {
    display: inline-block;
    padding: 2px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-book { background: #e3f2fd; color: #0d47a1; }
.badge-poem { background: #f3e5f5; color: #4a148c; }
.badge-newsletter { background: #e8f5e9; color: #1b5e20; }

.group-description { color: var(--text-light); margin: 0 0 12px; font-size: 1rem; }

.invite-code {
    background: var(--vanilla); padding: 8px 14px; border-radius: 8px;
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 0.9rem; border: 1px solid var(--border);
}
.invite-code code {
    background: white; padding: 2px 8px; border-radius: 4px;
    font-weight: bold; font-size: 0.85rem;
}
.group-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

/* Tabs */
.group-tabs {
    display: flex; gap: 4px; margin-bottom: 28px;
    border-bottom: 2px solid var(--border); flex-wrap: wrap;
}
.tab-btn {
    padding: 10px 20px; border: none; background: none; cursor: pointer;
    font-size: 0.95rem; font-weight: 500; color: var(--text-light);
    border-radius: 8px 8px 0 0; transition: all 0.2s;
}
.tab-btn:hover { background: var(--vanilla); color: var(--text); }
.tab-btn.active { background: var(--rose); color: white; font-weight: 600; }
.tab-content { display: none; min-height: 200px; padding-top: 8px; }
.tab-content.active { display: block; }

/* Discussions */
.discussions-header, .notes-header {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
}
.discussion-item {
    border: 1px solid var(--border); border-radius: 10px;
    padding: 14px 18px; margin-bottom: 14px; background: var(--card-bg);
    transition: box-shadow 0.2s;
}
.discussion-item:hover { box-shadow: var(--shadow); }
.discussion-item.pinned { border-left: 4px solid var(--rose); }
.discussion-header {
    display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}
.discussion-header h4 { margin: 0; font-size: 1.05rem; color: var(--dark); }
.discussion-meta { font-size: 0.85rem; color: var(--text-light); }
.discussion-preview {
    color: var(--text-light); margin: 8px 0; font-size: 0.95rem;
}
.discussion-footer {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px; margin-top: 6px;
}
.reaction-badges { display: flex; gap: 4px; flex-wrap: wrap; }
.reaction {
    background: var(--vanilla); padding: 0 8px; border-radius: 4px;
    font-size: 0.85rem; color: var(--text);
}

/* Notes */
.note-item {
    border: 1px solid var(--border); border-radius: 10px;
    padding: 14px 18px; margin-bottom: 14px; background: var(--card-bg);
}
.note-meta {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.note-author { font-weight: 600; color: var(--dark); }
.badge-private {
    background: #6c757d; color: white; padding: 0 10px;
    border-radius: 4px; font-size: 0.7rem; font-weight: 600;
}
.note-text { margin: 8px 0; font-size: 0.95rem; line-height: 1.6; }
.note-location { font-size: 0.85rem; color: var(--text-light); }
.note-footer {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px; margin-top: 6px;
}

/* Progress */
.progress-container { overflow-x: auto; margin-bottom: 24px; }
.progress-table {
    width: 100%; border-collapse: collapse; font-size: 0.9rem;
}
.progress-table th, .progress-table td {
    padding: 8px 10px; border: 1px solid var(--border); text-align: center;
}
.progress-table th { background: var(--vanilla); font-weight: 600; color: var(--text); }
.progress-table td { min-width: 30px; }
.my-progress { margin-top: 16px; }
.my-progress h4 { margin-bottom: 8px; font-size: 1rem; }
.my-progress .btn { margin-bottom: 4px; }

/* Members */
.members-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}
.member-card {
    border: 1px solid var(--border); border-radius: 10px; padding: 16px;
    text-align: center; background: var(--card-bg); transition: box-shadow 0.2s;
}
.member-card:hover { box-shadow: var(--shadow); }
.member-avatar {
    width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 50%;
    overflow: hidden; background: var(--vanilla);
}
.member-avatar img { width: 100%; height: 100%; object-fit: cover; }
.avatar-placeholder {
    width: 100%; height: 100%; background: var(--rose); color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: bold; font-size: 1.4rem;
}
.member-info h4 { margin: 0; font-size: 0.95rem; color: var(--dark); }
.member-role { font-size: 0.8rem; color: var(--text-light); }
.member-card .btn { margin-top: 6px; }

/* Schedule */
.schedule-list ul { list-style: none; padding: 0; }
.schedule-list li {
    padding: 10px 14px; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px;
}
.schedule-list li:last-child { border-bottom: none; }

/* Activity */
.activity-feed { display: flex; flex-direction: column; gap: 10px; }
.activity-item {
    display: flex; gap: 14px; padding: 10px 14px;
    background: var(--card-bg); border-radius: 8px; border: 1px solid var(--border);
}
.activity-avatar { flex-shrink: 0; }
.activity-avatar img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
.activity-content p { margin: 0; font-size: 0.9rem; color: var(--text); }

/* Empty message */
.empty-message {
    color: var(--text-light); text-align: center; padding: 40px 20px;
    font-size: 1rem;
}

/* Modals */
.modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-content {
    background: var(--card-bg); max-width: 520px; width: 90%; border-radius: 14px;
    padding: 28px; box-shadow: var(--shadow-hover);
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
}
.modal-header h2 { margin: 0; font-size: 1.3rem; color: var(--dark); }
.close-btn {
    background: none; border: none; font-size: 1.6rem; cursor: pointer;
    color: var(--text-light); transition: color 0.2s;
}
.close-btn:hover { color: var(--rose); }
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block; margin-bottom: 4px; font-weight: 500; color: var(--text);
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 10px 14px; border: 1px solid var(--border);
    border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text);
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.1);
}
.form-group textarea { resize: vertical; min-height: 80px; }

/* Responsive */
@media (max-width: 768px) {
    .group-title h1 { font-size: 1.8rem; }
    .group-tabs { flex-direction: column; border-bottom: none; }
    .tab-btn { border-radius: 8px; border: 1px solid var(--border); }
    .tab-btn.active { border-color: var(--rose); }
    .members-grid { grid-template-columns: 1fr 1fr; }
    .progress-table { font-size: 0.8rem; }
}
@media (max-width: 480px) {
    .members-grid { grid-template-columns: 1fr; }
    .group-actions { flex-direction: column; align-items: stretch; }
    .invite-code { justify-content: center; }
    .modal-content { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>