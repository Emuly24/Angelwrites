<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can access notifications
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// ===== MARK ALL AS READ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

// ===== MARK SINGLE AS READ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notif_id = (int)$_POST['notif_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

// ===== DELETE SINGLE NOTIFICATION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notif'])) {
    $notif_id = (int)$_POST['notif_id'];
    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

// ===== DELETE ALL NOTIFICATIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

// ===== FETCH TOTAL NOTIFICATIONS COUNT =====
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_notifications = $stmt->fetchColumn();
$total_pages = ceil($total_notifications / $limit);

// ===== FETCH NOTIFICATIONS WITH PAGINATION =====
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$user_id, $limit, $offset]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH UNREAD COUNT =====
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();

$pageTitle = 'Notifications';
?>
<?php require_once 'includes/header.php'; ?>

<div class="notifications-page">
    <div class="container">
        <!-- Page Header -->
        <div class="notifications-header">
            <h1>Notifications</h1>
            <p>Stay up to date with everything happening on AngelWrites.</p>
            <div class="notifications-meta">
                <span><?php echo number_format($total_notifications); ?> total</span>
                <span>•</span>
                <span><?php echo number_format($unread_count); ?> unread</span>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="notifications-actions">
            <div class="actions-left">
                <?php if ($unread_count > 0): ?>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-sm btn-primary">
                            <i class="fas fa-check-double"></i> Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="actions-right">
                <?php if ($total_notifications > 0): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete all notifications?');">
                        <button type="submit" name="delete_all" class="btn btn-sm btn-danger">
                            <i class="fas fa-trash-alt"></i> Clear All
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications List -->
        <?php if (count($notifications) > 0): ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>">
                        <div class="notification-content">
                            <div class="notification-icon">
                                <i class="fas <?php echo $notif['icon'] ?? 'fa-bell'; ?>"></i>
                            </div>
                            <div class="notification-details">
                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notification-date">
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('M j, Y, g:i a', strtotime($notif['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="notification-actions">
                            <?php if (!$notif['is_read']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="notif_id" value="<?php echo $notif['id']; ?>">
                                    <button type="submit" name="mark_read" class="btn btn-sm btn-outline" title="Mark as read">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?');">
                                <input type="hidden" name="notif_id" value="<?php echo $notif['id']; ?>">
                                <button type="submit" name="delete_notif" class="btn btn-sm btn-outline" title="Delete">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-bell-slash"></i>
                </div>
                <h3>All clear!</h3>
                <p>You don't have any notifications at the moment.</p>
                <a href="<?php echo SITE_URL; ?>/dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== NOTIFICATIONS PAGE ===== */
.notifications-page {
    padding: 32px 0 60px;
}

.notifications-header {
    text-align: center;
    margin-bottom: 24px;
}

.notifications-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}

.notifications-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

.notifications-meta {
    color: var(--text-light);
    font-size: 0.9rem;
    margin-top: 4px;
}

/* ===== ACTIONS BAR ===== */
.notifications-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
    background: var(--card-bg);
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.notifications-actions .actions-left,
.notifications-actions .actions-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.notifications-actions .btn-sm {
    padding: 6px 16px;
    border-radius: 30px;
}

.btn-danger {
    background: #e74c3c;
    color: white;
    transition: background 0.3s;
}

.btn-danger:hover {
    background: #c0392b;
}

/* ===== NOTIFICATIONS LIST ===== */
.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.notification-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px 20px;
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform 0.2s, box-shadow 0.2s;
}

.notification-item:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.notification-item.unread {
    border-left: 4px solid var(--rose);
    background: var(--fantasy);
}

.notification-item.read {
    border-left: 4px solid var(--border);
}

.notification-content {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex: 1;
    min-width: 200px;
}

.notification-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--vanilla);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--rose);
    font-size: 1.1rem;
}

.notification-details {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text);
}

.notification-message {
    color: var(--text-light);
    font-size: 0.9rem;
    line-height: 1.5;
    margin: 2px 0 4px;
}

.notification-date {
    font-size: 0.8rem;
    color: var(--text-light);
}

.notification-date i {
    margin-right: 4px;
}

.notification-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

.notification-actions .btn-sm {
    padding: 4px 10px;
    font-size: 0.75rem;
    border-radius: 20px;
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}

.empty-state-icon {
    font-size: 4rem;
    color: var(--rose);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.4rem;
    margin-bottom: 4px;
    color: var(--text);
}

.empty-state p {
    margin-bottom: 20px;
}

/* ===== PAGINATION ===== */
.pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 32px;
    flex-wrap: wrap;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 14px;
    border-radius: 8px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 0.9rem;
    transition: all 0.2s;
    min-width: 36px;
    text-decoration: none;
}

.page-link:hover {
    border-color: var(--rose);
}

.page-link.active {
    background: var(--rose);
    color: white;
    border-color: var(--rose);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .notification-item {
        flex-direction: column;
        align-items: stretch;
    }

    .notification-content {
        flex-direction: column;
        align-items: stretch;
    }

    .notification-actions {
        justify-content: flex-end;
        margin-top: 4px;
    }

    .notifications-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .notifications-actions .actions-left,
    .notifications-actions .actions-right {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .notifications-header h1 {
        font-size: 1.8rem;
    }

    .notification-item {
        padding: 12px 16px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>