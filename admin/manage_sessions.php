<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE STATUS UPDATE =====
if (isset($_POST['update_status'])) {
    $session_id = (int)$_POST['session_id'];
    $status = $_POST['status'];
    
    $valid_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (in_array($status, $valid_statuses)) {
        $stmt = $db->prepare("UPDATE sessions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$status, $session_id]);
        $success = 'Session status updated successfully.';
    } else {
        $error = 'Invalid status.';
    }
}

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Session deleted successfully.';
    header('Location: ' . SITE_URL . '/admin/manage_sessions.php');
    exit;
}

// ===== FETCH ALL SESSIONS =====
$stmt = $db->prepare("
    SELECT s.*, u.name AS user_name, u.email AS user_email 
    FROM sessions s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY s.date DESC, s.time DESC
");
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== COUNT BY STATUS =====
$status_counts = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0
];
foreach ($sessions as $session) {
    if (isset($status_counts[$session['status']])) {
        $status_counts[$session['status']]++;
    }
}

$pageTitle = 'Manage Sessions';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- Page Header -->
        <div class="admin-header">
            <h1>Manage Sessions</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Status Summary -->
        <div class="status-summary">
            <div class="summary-card">
                <div class="summary-count"><?php echo $status_counts['pending']; ?></div>
                <div class="summary-label">Pending</div>
            </div>
            <div class="summary-card">
                <div class="summary-count"><?php echo $status_counts['confirmed']; ?></div>
                <div class="summary-label">Confirmed</div>
            </div>
            <div class="summary-card">
                <div class="summary-count"><?php echo $status_counts['completed']; ?></div>
                <div class="summary-label">Completed</div>
            </div>
            <div class="summary-card">
                <div class="summary-count"><?php echo $status_counts['cancelled']; ?></div>
                <div class="summary-label">Cancelled</div>
            </div>
        </div>

        <!-- Sessions Table -->
        <div class="card">
            <div class="card-header">
                <h2>All Sessions (<?php echo count($sessions); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($sessions) > 0): ?>
                    <div class="sessions-table-wrapper">
                        <table class="sessions-table">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Message</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($session['user_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($session['user_email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($session['date']); ?></td>
                                        <td><?php echo htmlspecialchars($session['time']); ?></td>
                                        <td><?php echo $session['duration'] ?? 60; ?> min</td>
                                        <td>
                                            <form method="POST" class="status-form">
                                                <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                                <select name="status" onchange="this.form.submit()" class="status-select <?php echo $session['status']; ?>">
                                                    <option value="pending" <?php echo $session['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="confirmed" <?php echo $session['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                    <option value="completed" <?php echo $session['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="cancelled" <?php echo $session['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($session['message']): ?>
                                                <span class="message-preview" title="<?php echo htmlspecialchars($session['message']); ?>">
                                                    <?php echo htmlspecialchars(substr($session['message'], 0, 30)); ?>...
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php?delete=<?php echo $session['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this session?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No session bookings yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== INLINE CSS for sessions page ===== -->
<style>
    .status-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .summary-card {
        background: var(--card-bg);
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }
    .summary-count {
        font-size: 2.4rem;
        font-weight: 700;
        color: var(--rose);
    }
    .summary-label {
        font-size: 0.9rem;
        color: var(--text-light);
        margin-top: 4px;
    }
    
    .sessions-table-wrapper {
        overflow-x: auto;
    }
    .sessions-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }
    .sessions-table th {
        background: var(--vanilla);
        color: var(--text);
        text-align: left;
        padding: 12px 16px;
        font-weight: 600;
        border-bottom: 2px solid var(--border);
    }
    .sessions-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .sessions-table tr:hover {
        background: rgba(219, 161, 162, 0.05);
    }
    
    .status-select {
        padding: 4px 8px;
        border-radius: 6px;
        border: 1px solid var(--border);
        font-size: 0.85rem;
        cursor: pointer;
        background: var(--input-bg);
        color: var(--text);
        transition: border-color var(--transition);
    }
    .status-select:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .status-select.pending { border-left: 4px solid #f1c40f; }
    .status-select.confirmed { border-left: 4px solid #2ecc71; }
    .status-select.completed { border-left: 4px solid #3498db; }
    .status-select.cancelled { border-left: 4px solid #e74c3c; }
    
    .status-form {
        margin: 0;
    }
    .status-form select {
        width: 100%;
        min-width: 100px;
    }
    
    .message-preview {
        cursor: help;
        color: var(--text-light);
    }
    .actions {
        white-space: nowrap;
    }
    .btn-sm {
        padding: 4px 10px;
        font-size: 0.8rem;
    }
</style>

<?php require_once '../includes/footer.php'; ?>