<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

// Fetch statistics
$stats = [];

// Total users
$stmt = $db->query("SELECT COUNT(*) FROM users");
$stats['total_users'] = $stmt->fetchColumn();

// Total books
$stmt = $db->query("SELECT COUNT(*) FROM books");
$stats['total_books'] = $stmt->fetchColumn();

// Total poems
$stmt = $db->query("SELECT COUNT(*) FROM poems");
$stats['total_poems'] = $stmt->fetchColumn();

// Total sessions booked
$stmt = $db->query("SELECT COUNT(*) FROM sessions");
$stats['total_sessions'] = $stmt->fetchColumn();

// Total blog posts
$stmt = $db->query("SELECT COUNT(*) FROM blog_posts");
$stats['total_posts'] = $stmt->fetchColumn();

// Total questions (community)
$stmt = $db->query("SELECT COUNT(*) FROM questions");
$stats['total_questions'] = $stmt->fetchColumn();

// Total newsletter subscribers
$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1");
$stats['total_subscribers'] = $stmt->fetchColumn();

// Recent sessions (pending)
$stmt = $db->prepare("
    SELECT s.*, u.name AS user_name, u.email 
    FROM sessions s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.status = 'pending' 
    ORDER BY s.date ASC, s.time ASC 
    LIMIT 5
");
$stmt->execute();
$recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent contact messages
$stmt = $db->prepare("
    SELECT * FROM contact_messages 
    WHERE is_read = 0 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent book uploads
$stmt = $db->prepare("
    SELECT * FROM books 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-dashboard">
    <div class="container">
        <!-- Page Header -->
        <div class="dashboard-header">
            <div class="dashboard-header-text">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>! Here's what's happening on your site.</p>
            </div>
            <div class="dashboard-header-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-primary">
                    <i class="fa-pen-fancy"></i> New Book
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-secondary">
                    <i class="fa-pen-fancy"></i> New Poem
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(219, 161, 162, 0.15); color: var(--rose);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_users']; ?></span>
                    <span class="stat-label">Total Users</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71;">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_books']; ?></span>
                    <span class="stat-label">Total Books</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(155, 89, 182, 0.15); color: #9b59b6;">
                    <i class="fas fa-feather-alt"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_poems']; ?></span>
                    <span class="stat-label">Total Poems</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(52, 152, 219, 0.15); color: #3498db;">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_sessions']; ?></span>
                    <span class="stat-label">Total Sessions</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(241, 196, 15, 0.15); color: #f1c40f;">
                    <i class="fas fa-blog"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_posts']; ?></span>
                    <span class="stat-label">Blog Posts</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(231, 76, 60, 0.15); color: #e74c3c;">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_questions']; ?></span>
                    <span class="stat-label">Community Q&A</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 64, 129, 0.15); color: #ff4081;">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_subscribers']; ?></span>
                    <span class="stat-label">Newsletter Subscribers</span>
                </div>
            </div>
        </div>

        <!-- ===== QUICK ACTIONS ===== -->
<div class="quick-actions-grid">
    <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="action-card">
        <i class="fas fa-book"></i>
        <span>Manage Books</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="action-card">
        <i class="fas fa-feather-alt"></i>
        <span>Manage Poems</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="action-card">
        <i class="fas fa-calendar-check"></i>
        <span>Manage Sessions</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="action-card">
        <i class="fas fa-users-cog"></i>
        <span>Manage Users</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="action-card">
        <i class="fas fa-edit"></i>
        <span>Manage Blog</span>
    </a>
    <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="action-card">
        <i class="fas fa-cog"></i>
        <span>Site Settings</span>
    </a>
</div>

<!-- ===== DASHBOARD SECTIONS ===== -->
<div class="dashboard-grid">

    <!-- Pending Sessions -->
    <div class="dashboard-section-card">
        <div class="dashboard-section-header">
            <h3><i class="fas fa-clock"></i> Pending Sessions</h3>
            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="view-all-link">View All &rarr;</a>
        </div>
        <div class="dashboard-list-body">
            <?php if (count($recent_sessions) > 0): ?>
                <?php foreach ($recent_sessions as $session): ?>
                    <div class="dashboard-list-item">
                        <div class="dashboard-list-item-info">
                            <strong><?php echo htmlspecialchars($session['user_name']); ?></strong>
                            <small><?php echo htmlspecialchars($session['date']); ?> at <?php echo htmlspecialchars($session['time']); ?></small>
                        </div>
                        <span class="status-badge pending">Pending</span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-items-message">No pending sessions.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Unread Messages -->
    <div class="dashboard-section-card">
        <div class="dashboard-section-header">
            <h3><i class="fas fa-envelope"></i> Unread Messages</h3>
            <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="view-all-link">View All &rarr;</a>
        </div>
        <div class="dashboard-list-body">
            <?php if (count($recent_messages) > 0): ?>
                <?php foreach ($recent_messages as $message): ?>
                    <div class="dashboard-list-item">
                        <div class="dashboard-list-item-info">
                            <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                            <small><?php echo htmlspecialchars(substr($message['message'], 0, 50)); ?>...</small>
                        </div>
                        <span class="status-badge unread">Unread</span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-items-message">No unread messages.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recently Added Books -->
    <div class="dashboard-section-card">
        <div class="dashboard-section-header">
            <h3><i class="fas fa-book"></i> Recently Added Books</h3>
            <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="view-all-link">View All &rarr;</a>
        </div>
        <div class="dashboard-list-body">
            <?php if (count($recent_books) > 0): ?>
                <?php foreach ($recent_books as $book): ?>
                    <div class="dashboard-list-item">
                        <div class="dashboard-list-item-info">
                            <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                            <small>by <?php echo htmlspecialchars($book['author']); ?></small>
                        </div>
                        <span class="status-badge available">Available</span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-items-message">No books added yet.</div>
            <?php endif; ?>
        </div>
    </div>

</div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
<style>

/* ===== QUICK ACTIONS GRID ===== */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 32px;
}

.action-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: all var(--transition);
    text-decoration: none;
    color: var(--text);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.action-card i {
    font-size: 1.4rem;
    color: var(--rose);
    display: block;
}

.action-card span {
    font-weight: 500;
    font-size: 0.9rem;
}

.action-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
    border-color: var(--rose);
    color: var(--rose);
}

/* ===== DASHBOARD CARDS (Sessions, Messages, Books) ===== */
.dashboard-section-card {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    margin-bottom: 24px;
    overflow: hidden;
}

.dashboard-section-header {
    background: var(--vanilla);
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-section-header h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dashboard-section-header h3 i {
    color: var(--rose);
}

.view-all-link {
    font-size: 0.85rem;
    color: var(--text-light);
    font-weight: 500;
    text-decoration: none;
    transition: color var(--transition);
}

.view-all-link:hover {
    color: var(--rose);
}

.dashboard-list-body {
    padding: 4px 0;
}

.dashboard-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    transition: background var(--transition);
}

.dashboard-list-item:last-child {
    border-bottom: none;
}

.dashboard-list-item:hover {
    background: rgba(219, 161, 162, 0.05);
}

.dashboard-list-item-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.dashboard-list-item-info strong {
    font-size: 0.95rem;
    color: var(--text);
}

.dashboard-list-item-info small {
    font-size: 0.8rem;
    color: var(--text-light);
}

.status-badge {
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}
.status-badge.pending { background: #f1c40f; color: white; }
.status-badge.unread { background: var(--rose); color: white; }
.status-badge.available { background: #2ecc71; color: white; }

.no-items-message {
    padding: 20px;
    text-align: center;
    color: var(--text-light);
    font-size: 0.9rem;
    font-style: italic;
}
</style>

