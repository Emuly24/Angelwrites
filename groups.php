<?php
// ============================================================
//  GROUPS.PHP – Reading Groups Dashboard (User)
//  Supports Books, Poems, and Newsletters.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/reading_groups.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];

// ===== HANDLE JOIN GROUP VIA INVITE CODE =====
$join_error = '';
$join_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_group'])) {
    $invite_code = strtoupper(trim($_POST['invite_code']));
    if (empty($invite_code)) {
        $join_error = 'Please enter an invite code.';
    } else {
        $result = joinGroupByCode($invite_code, $user_id);
        if ($result === false) {
            $join_error = 'Invalid invite code.';
        } elseif ($result === 'already_member') {
            $join_error = 'You are already a member of this group.';
        } else {
            $join_success = 'You have joined the group! <a href="group.php?id=' . $result . '">Go to group</a>';
        }
    }
}

// ===== FETCH USER GROUPS =====
$groups = getUserGroups($user_id);

$pageTitle = 'My Reading Groups';
?>
<?php require_once 'includes/header.php'; ?>

<div class="groups-dashboard">
    <div class="container">
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <h1>📚 My Reading Groups</h1>
            <div class="header-actions">
                <a href="create_group.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Group
                </a>
                <button onclick="showJoinModal()" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Join Group
                </button>
            </div>
        </div>

        <?php if ($join_error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($join_error); ?></div>
        <?php endif; ?>
        <?php if ($join_success): ?>
            <div class="alert alert-success"><?php echo $join_success; ?></div>
        <?php endif; ?>

        <!-- ===== GROUP LIST ===== -->
        <?php if (empty($groups)): ?>
            <div class="empty-state">
                <div class="empty-icon">📖</div>
                <h2>No groups yet</h2>
                <p>Start a reading group with friends or join an existing one using an invite code.</p>
                <div class="empty-actions">
                    <a href="create_group.php" class="btn btn-primary">Create Your First Group</a>
                    <button onclick="showJoinModal()" class="btn btn-secondary">Join a Group</button>
                </div>
            </div>
        <?php else: ?>
            <div class="groups-grid">
                <?php foreach ($groups as $group):
                    // Determine content title and author based on content_type
                    $content_title = '';
                    $content_author = '';
                    $type_icon = '';
                    $type_label = '';
                    $type_class = '';

                    switch ($group['content_type']) {
                        case 'book':
                            $content_title = $group['book_title'] ?? 'Untitled Book';
                            $content_author = $group['book_author'] ?? '';
                            $type_icon = '📖';
                            $type_label = 'Book';
                            $type_class = 'badge-book';
                            break;
                        case 'poem':
                            $content_title = $group['poem_title'] ?? 'Untitled Poem';
                            $content_author = $group['poem_author'] ?? '';
                            $type_icon = '✍️';
                            $type_label = 'Poem';
                            $type_class = 'badge-poem';
                            break;
                        case 'newsletter':
                            $content_title = $group['newsletter_title'] ?? 'Untitled Newsletter';
                            $content_author = $group['newsletter_author'] ?? '';
                            $type_icon = '📰';
                            $type_label = 'Newsletter';
                            $type_class = 'badge-newsletter';
                            break;
                        default:
                            $content_title = 'Unknown';
                            $content_author = '';
                            $type_icon = '📄';
                            $type_label = 'Content';
                            $type_class = 'badge-other';
                    }
                ?>
                <div class="group-card">
                    <div class="group-card-header">
                        <h3>
                            <a href="group.php?id=<?php echo $group['id']; ?>">
                                <?php echo htmlspecialchars($group['name']); ?>
                            </a>
                        </h3>
                        <span class="member-badge">
                            <i class="fas fa-users"></i> <?php echo $group['member_count']; ?>
                        </span>
                    </div>
                    <div class="group-card-body">
                        <p class="content-info">
                            <span class="content-type-badge <?php echo $type_class; ?>">
                                <?php echo $type_icon . ' ' . $type_label; ?>
                            </span>
                            <strong><?php echo htmlspecialchars($content_title); ?></strong>
                            <?php if ($content_author): ?>
                                <br><small>by <?php echo htmlspecialchars($content_author); ?></small>
                            <?php endif; ?>
                        </p>
                        <?php if ($group['description']): ?>
                            <p class="group-description"><?php echo htmlspecialchars($group['description']); ?></p>
                        <?php endif; ?>
                        <div class="group-stats">
                            <span title="Notes"><i class="fas fa-sticky-note"></i> <?php echo $group['note_count']; ?></span>
                            <span title="Discussions"><i class="fas fa-comments"></i> <?php echo $group['discussion_count']; ?></span>
                        </div>
                    </div>
                    <div class="group-card-footer">
                        <a href="group.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-arrow-right"></i> Open Group
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== JOIN GROUP MODAL ===== -->
<div id="joinModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>🔑 Join a Reading Group</h2>
            <button onclick="closeJoinModal()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <p>Enter the invite code provided by the group creator.</p>
            <form method="POST" onsubmit="closeJoinModalAfterSubmit()">
                <div class="form-group">
                    <label for="invite_code">Invite Code</label>
                    <input type="text" id="invite_code" name="invite_code" placeholder="e.g., ABC123XYZ" required>
                </div>
                <button type="submit" name="join_group" class="btn btn-primary">Join Group</button>
            </form>
        </div>
    </div>
</div>

<script>
function showJoinModal() {
    document.getElementById('joinModal').style.display = 'flex';
}
function closeJoinModal() {
    document.getElementById('joinModal').style.display = 'none';
}
// Close modal when clicking outside
document.getElementById('joinModal').addEventListener('click', function(e) {
    if (e.target === this) closeJoinModal();
});
</script>

<style>
/* ============================================================
   GROUPS PAGE STYLES – Enhanced & Professional
   ============================================================ */
.groups-dashboard { padding: 32px 0 60px; background: var(--bg); }

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 16px; margin-bottom: 28px;
}
.page-header h1 { margin: 0; font-size: 2rem; color: var(--dark); }
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ===== GROUP GRID ===== */
.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.group-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: transform 0.2s, box-shadow 0.2s;
}
.group-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.group-card-header {
    background: var(--vanilla);
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.group-card-header h3 {
    margin: 0; font-size: 1.1rem;
}
.group-card-header h3 a {
    color: var(--text); text-decoration: none;
}
.group-card-header h3 a:hover { color: var(--rose); }
.member-badge {
    background: var(--rose); color: white;
    padding: 2px 12px; border-radius: 20px;
    font-size: 0.8rem; font-weight: 600;
}

.group-card-body { padding: 16px 18px; }

.content-info {
    margin: 0 0 10px; font-size: 0.95rem;
    display: flex; flex-direction: column; gap: 4px;
}
.content-type-badge {
    display: inline-block; padding: 2px 12px; border-radius: 20px;
    font-size: 0.7rem; font-weight: 600; margin-bottom: 4px;
}
.badge-book { background: #e3f2fd; color: #0d47a1; }
.badge-poem { background: #f3e5f5; color: #4a148c; }
.badge-newsletter { background: #e8f5e9; color: #1b5e20; }
.badge-other { background: #f1f3f5; color: #495057; }

.content-info strong { color: var(--dark); }
.content-info small { color: var(--text-light); }

.group-description {
    color: var(--text-light); font-size: 0.9rem; margin: 0 0 10px;
}
.group-stats {
    display: flex; gap: 14px; font-size: 0.85rem; color: var(--text-light);
}
.group-stats i { margin-right: 2px; color: var(--rose); }

.group-card-footer {
    padding: 12px 18px; border-top: 1px solid var(--border);
    text-align: right; background: var(--card-bg);
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center; padding: 60px 20px;
}
.empty-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.6; }
.empty-state h2 { margin-bottom: 8px; color: var(--text); }
.empty-state p { color: var(--text-light); }
.empty-actions {
    display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 16px;
}

/* ===== MODAL ===== */
.modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 9999;
    align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-content {
    background: var(--card-bg);
    max-width: 480px; width: 90%;
    border-radius: 14px;
    padding: 28px;
    box-shadow: var(--shadow-hover);
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
}
.modal-header h2 { margin: 0; font-size: 1.3rem; color: var(--dark); }
.close-btn {
    background: none; border: none; font-size: 1.6rem;
    cursor: pointer; color: var(--text-light);
    transition: color 0.2s;
}
.close-btn:hover { color: var(--rose); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: 500; color: var(--text); }
.form-group input {
    width: 100%; padding: 10px 14px; border: 1px solid var(--border);
    border-radius: 8px; font-size: 1rem; background: var(--input-bg); color: var(--text);
}
.form-group input:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.1);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-actions { width: 100%; justify-content: flex-start; }
}
@media (max-width: 480px) {
    .groups-grid { grid-template-columns: 1fr; }
    .group-card-header { flex-direction: column; align-items: flex-start; gap: 6px; }
    .group-card-footer { text-align: center; }
}
</style>

<?php require_once 'includes/footer.php'; ?>