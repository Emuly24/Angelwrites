<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all'; // all, pending_reports, flagged

// ===== HANDLE REPORT ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_action'])) {
    $report_id = (int)$_POST['report_id'];
    $action = $_POST['report_action'];
    
    if ($action === 'dismiss') {
        $stmt = $db->prepare("UPDATE reports SET status = 'dismissed' WHERE id = ?");
        $stmt->execute([$report_id]);
    } elseif ($action === 'action_taken') {
        $stmt = $db->prepare("UPDATE reports SET status = 'action_taken' WHERE id = ?");
        $stmt->execute([$report_id]);
    } elseif ($action === 'delete_content') {
        // Delete the reported content
        $stmt = $db->prepare("SELECT target_type, target_id FROM reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($report) {
            if ($report['target_type'] === 'question') {
                $stmt = $db->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$report['target_id']]);
            } elseif ($report['target_type'] === 'answer') {
                $stmt = $db->prepare("DELETE FROM answers WHERE id = ?");
                $stmt->execute([$report['target_id']]);
            }
            $stmt = $db->prepare("UPDATE reports SET status = 'action_taken' WHERE id = ?");
            $stmt->execute([$report_id]);
        }
    }
    header('Location: ' . SITE_URL . '/admin/manage_community.php');
    exit;
}

// ===== FETCH REPORTS =====
$sql = "SELECT r.*, u.name as reporter_name FROM reports r JOIN users u ON r.reporter_user_id = u.id";
$params = [];
if ($filter === 'pending_reports') {
    $sql .= " WHERE r.status = 'pending'";
} elseif ($filter === 'flagged') {
    $sql .= " WHERE r.status IN ('pending', 'action_taken')";
}
$sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH TOTAL REPORTS COUNT =====
$count_sql = "SELECT COUNT(*) FROM reports";
if ($filter === 'pending_reports') {
    $count_sql .= " WHERE status = 'pending'";
} elseif ($filter === 'flagged') {
    $count_sql .= " WHERE status IN ('pending', 'action_taken')";
}
$stmt = $db->prepare($count_sql);
$stmt->execute();
$total_reports = $stmt->fetchColumn();
$total_pages = ceil($total_reports / $limit);

$pageTitle = 'Manage Community';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>⚖️ Manage Community</h1>
            <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </div>

        <!-- Filter -->
        <div class="search-bar">
            <form method="GET" class="search-form">
                <select name="filter">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Reports</option>
                    <option value="pending_reports" <?php echo $filter === 'pending_reports' ? 'selected' : ''; ?>>Pending</option>
                    <option value="flagged" <?php echo $filter === 'flagged' ? 'selected' : ''; ?>>Flagged</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            </form>
        </div>

        <!-- Reports Table -->
        <div class="card">
            <div class="card-header">
                <h2>Reports (<?php echo $total_reports; ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($reports) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Reporter</th>
                                    <th>Target</th>
                                    <th>Reason</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <tr>
                                        <td><?php echo $report['id']; ?></td>
                                        <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                        <td><?php echo ucfirst($report['target_type']); ?> #<?php echo $report['target_id']; ?></td>
                                        <td><?php echo htmlspecialchars($report['reason']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($report['details'] ?? '', 0, 50)); ?><?php if (strlen($report['details'] ?? '') > 50) echo '...'; ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $report['status']; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                                        <td class="actions">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <button type="submit" name="report_action" value="dismiss" class="btn btn-sm btn-secondary">Dismiss</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <button type="submit" name="report_action" value="delete_content" class="btn btn-sm btn-danger" onclick="return confirm('Delete the reported content?')">Delete Content</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <button type="submit" name="report_action" value="action_taken" class="btn btn-sm btn-success">Mark Resolved</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="no-items">No reports found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.pending { background: #f1c40f; color: #fff; }
.status-badge.dismissed { background: #95a5a6; color: #fff; }
.status-badge.action_taken { background: #2ecc71; color: #fff; }
</style>

<?php require_once '../includes/footer.php'; ?>