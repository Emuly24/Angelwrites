<?php
// ============================================================
//  GROUPS.PHP – Reading Groups Dashboard (Public)
//  Fully enhanced with all features.
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
                <?php foreach ($groups as $group): ?>
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
                            <p class="book-info">
                                <strong>Book:</strong>
                                <?php echo htmlspecialchars($group['book_title']); ?>
                                <br>
                                <small>by <?php echo htmlspecialchars($group['book_author']); ?></small>
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
.groups-dashboard { padding: 32px 0 60px; }
.page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.page-header h1 { margin: 0; }
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.groups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.group-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: transform 0.2s;
}
.group-card:hover { transform: translateY(-2px); }

.group-card-header {
    background: var(--vanilla);
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.group-card-header h3 { margin: 0; font-size: 1.1rem; }
.group-card-header h3 a { color: var(--text); text-decoration: none; }
.group-card-header h3 a:hover { color: var(--rose); }
.member-badge { background: var(--rose); color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem; }

.group-card-body { padding: 14px 16px; }
.book-info { margin: 0 0 8px; font-size: 0.95rem; }
.group-description { color: var(--text-light); font-size: 0.9rem; margin: 0 0 8px; }
.group-stats { display: flex; gap: 12px; font-size: 0.85rem; color: var(--text-light); }
.group-stats i { margin-right: 2px; color: var(--rose); }

.group-card-footer { padding: 12px 16px; border-top: 1px solid var(--border); text-align: right; }

.empty-state { text-align: center; padding: 60px 20px; }
.empty-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.6; }
.empty-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 16px; }

/* ===== MODAL ===== */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: white;
    max-width: 480px;
    width: 90%;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-header h2 { margin: 0; }
.close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999; }
.close-btn:hover { color: #333; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: 500; }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 1rem; }

@media (max-width: 480px) {
    .groups-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once 'includes/footer.php'; ?>