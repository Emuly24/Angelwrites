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

// Total reflections
$stmt = $db->query("SELECT COUNT(*) FROM reflections");
$stats['total_reflections'] = $stmt->fetchColumn();

// Total videos
$stmt = $db->query("SELECT COUNT(*) FROM videos");
$stats['total_videos'] = $stmt->fetchColumn();

// --- RECENT ITEMS ---

// Pending sessions
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

// Unread contact messages
$stmt = $db->prepare("
    SELECT * FROM contact_messages 
    WHERE is_read = 0 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent books
$stmt = $db->prepare("
    SELECT * FROM books 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent poems
$stmt = $db->prepare("
    SELECT * FROM poems 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent blog posts
$stmt = $db->prepare("
    SELECT * FROM blog_posts 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reflections
$stmt = $db->prepare("
    SELECT * FROM reflections 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent questions
$stmt = $db->prepare("
    SELECT * FROM questions 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent users
$stmt = $db->prepare("
    SELECT * FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-dashboard">
    <div class="container">
        <!-- Page Header -->
        <div class="dashboard-header">
            <div class="dashboard-header-text">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></strong>! Here's what's happening on your site.</p>
            </div>
            <div class="dashboard-header-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-primary">
                    <i class="fas fa-book"></i> New Book
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/poem_editor.php" class="btn btn-secondary">
                    <i class="fas fa-pen"></i> New Poem
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/editor.php" class="btn btn-secondary">
                    <i class="fas fa-blog"></i> New Blog
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/reflection_editor.php" class="btn btn-secondary">
                    <i class="fas fa-church"></i> New Reflection
                </a>
                <!-- ===== NEW BUTTONS ADDED HERE ===== -->
                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="btn btn-secondary">
                    <i class="fas fa-newspaper"></i> New Newsletter
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-secondary">
                    <i class="fas fa-video"></i> New Video
                </a>
                <!-- ===================================== -->
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
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
                    <i class="fas fa-pen"></i>
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

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(75, 192, 192, 0.15); color: #4bc0c0;">
                    <i class="fas fa-church"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_reflections']; ?></span>
                    <span class="stat-label">Reflections</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 159, 64, 0.15); color: #ff9f40;">
                    <i class="fas fa-video"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_videos']; ?></span>
                    <span class="stat-label">Videos</span>
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
                <i class="fas fa-pen"></i>
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
            <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="action-card">
                <i class="fas fa-church"></i>
                <span>Manage Reflections</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="action-card">
                <i class="fas fa-question"></i>
                <span>Manage Q&A</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="action-card">
                <i class="fas fa-envelope"></i>
                <span>Manage Messages</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="action-card">
                <i class="fas fa-video"></i>
                <span>Manage Videos</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="action-card">
                <i class="fas fa-newspaper"></i>
                <span>Manage Newsletter</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="action-card">
                <i class="fas fa-cog"></i>
                <span>Site Settings</span>
            </a>
        </div>

        <!-- ===== DASHBOARD SECTIONS GRID ===== -->
        <div class="dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">

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
                        <div class="empty-state-card">
                            <i class="fas fa-clock"></i>
                            <p>No pending sessions</p>
                        </div>
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
                                    <small><?php echo htmlspecialchars(substr($message['message'], 0, 40)); ?>...</small>
                                </div>
                                <span class="status-badge unread">Unread</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-inbox"></i>
                            <p>No unread messages</p>
                        </div>
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
                        <div class="empty-state-card">
                            <i class="fas fa-book"></i>
                            <p>No books added yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recently Added Poems -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-pen"></i> Recently Added Poems</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_poems) > 0): ?>
                        <?php foreach ($recent_poems as $poem): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                </div>
                                <span class="status-badge available">Added</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-pen"></i>
                            <p>No poems added yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Blog Posts -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-blog"></i> Recent Blog Posts</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_posts) > 0): ?>
                        <?php foreach ($recent_posts as $post): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                    <small><?php echo htmlspecialchars($post['category'] ?? 'Uncategorized'); ?></small>
                                </div>
                                <span class="status-badge <?php echo $post['status'] === 'published' ? 'available' : 'pending'; ?>">
                                    <?php echo ucfirst($post['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-blog"></i>
                            <p>No blog posts yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Reflections -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-church"></i> Recent Reflections</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_reflections) > 0): ?>
                        <?php foreach ($recent_reflections as $reflection): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($reflection['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($reflection['created_at'])); ?></small>
                                </div>
                                <span class="status-badge available">Published</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-church"></i>
                            <p>No reflections yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Questions -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-question-circle"></i> Recent Questions</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_questions) > 0): ?>
                        <?php foreach ($recent_questions as $question): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($question['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($question['created_at'])); ?></small>
                                </div>
                                <span class="status-badge <?php echo $question['is_answered'] ? 'available' : 'pending'; ?>">
                                    <?php echo $question['is_answered'] ? 'Answered' : 'Open'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-question-circle"></i>
                            <p>No questions yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Users -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-users"></i> Newest Users</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_users) > 0): ?>
                        <?php foreach ($recent_users as $user): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                    <small><?php echo htmlspecialchars($user['email']); ?></small>
                                </div>
                                <span class="status-badge <?php echo $user['role'] === 'admin' ? 'pending' : 'available'; ?>">
                                    <?php echo ucfirst($user['role'] ?? 'User'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-users"></i>
                            <p>No users yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    /* ===== STATS CARDS ===== */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }
    .stat-card {
        background: var(--card-bg);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 16px;
        transition: all var(--transition);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-hover);
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .stat-content {
        display: flex;
        flex-direction: column;
    }
    .stat-number {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--dark);
        line-height: 1.2;
    }
    .stat-label {
        font-size: 0.85rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* ===== QUICK ACTIONS ===== */
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

    /* ===== DASHBOARD CARDS ===== */
    .dashboard-section-card {
        background: var(--card-bg);
        border-radius: 12px;
        border-left: 6px solid var(--rose);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .dashboard-section-header {
        background: var(--vanilla);
        padding: 10px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
    }
    .dashboard-section-header h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .dashboard-section-header h3 i {
        color: var(--rose-dark);
        font-size: 1rem;
    }
    .view-all-link {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--rose-dark);
        text-decoration: none;
        transition: color var(--transition);
    }
    .view-all-link:hover {
        color: var(--rose);
        text-decoration: underline;
    }

    .dashboard-list-body {
        background: var(--white);
    }

    .dashboard-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 16px;
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
        font-size: 0.9rem;
        color: var(--text);
    }
    .dashboard-list-item-info small {
        font-size: 0.75rem;
        color: var(--text-light);
    }

    .empty-state-card {
        padding: 16px 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: var(--text-light);
        background: var(--fantasy);
        font-size: 0.85rem;
        min-height: 40px;
    }
    .empty-state-card i {
        font-size: 1.2rem;
        color: var(--border);
    }
    .empty-state-card p {
        margin: 0;
        font-weight: 400;
    }

    .status-badge {
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-badge.pending {
        background: #f1c40f;
        color: #fff;
    }
    .status-badge.unread {
        background: var(--rose);
        color: #fff;
    }
    .status-badge.available {
        background: #2ecc71;
        color: #fff;
    }
    .status-badge.missing {
        background: #e74c3c;
        color: #fff;
    }

    /* ===== HEADER ===== */
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 32px;
    }
    .dashboard-header h1 {
        font-size: 2rem;
        margin-bottom: 4px;
    }
    .dashboard-header p {
        color: var(--text-light);
        font-size: 1.05rem;
    }
    .dashboard-header-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 768px) {
        .dashboard-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .dashboard-header-actions {
            width: 100%;
        }
        .dashboard-header-actions .btn {
            flex: 1;
            justify-content: center;
        }
        .dashboard-grid {
            grid-template-columns: 1fr !important;
        }
    }
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr 1fr;
        }
        .quick-actions-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
</style>

<?php require_once '../includes/footer.php'; ?>