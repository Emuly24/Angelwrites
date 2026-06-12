<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ===== FETCH STATISTICS =====
$stats = [];

$stmt = $db->query("SELECT COUNT(*) FROM users");
$stats['total_users'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM books");
$stats['total_books'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM poems");
$stats['total_poems'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM sessions");
$stats['total_sessions'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM blog_posts");
$stats['total_posts'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM questions");
$stats['total_questions'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM newsletter WHERE is_active = 1");
$stats['total_subscribers'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM blog_posts WHERE category = 'Christian Reflections'");
$stats['total_reflections'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM videos");
$stats['total_videos'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM reading_groups");
$stats['total_groups'] = $stmt->fetchColumn();

// ===== READER ANALYTICS =====
// Total reading hours
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions");
$total_seconds = $stmt->fetchColumn() ?? 0;
$stats['total_reading_hours'] = floor($total_seconds / 3600);

// Active readers (last 7 days)
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')");
$stats['active_readers_7days'] = $stmt->fetchColumn();

// Active readers (last 30 days)
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-30 days')");
$stats['active_readers_30days'] = $stmt->fetchColumn();

// Books with highest completion rate
$stmt = $db->query("
    SELECT b.title, 
           COUNT(DISTINCT rp.user_id) as readers,
           SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) as completions,
           ROUND(100.0 * SUM(CASE WHEN rp.progress_percent >= 100 THEN 1 ELSE 0 END) / COUNT(DISTINCT rp.user_id), 1) as completion_rate
    FROM reading_progress rp
    JOIN books b ON rp.book_id = b.id
    GROUP BY rp.book_id
    ORDER BY completion_rate DESC
    LIMIT 5
");
$stats['book_completion_rates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Drop-off points (chapters where most readers stop)
$stmt = $db->query("
    SELECT rp.position_section as chapter, COUNT(*) as drop_offs
    FROM reading_progress rp
    WHERE rp.progress_percent < 100
    GROUP BY rp.position_section
    ORDER BY drop_offs DESC
    LIMIT 5
");
$stats['drop_off_points'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Most active readers
$stmt = $db->query("
    SELECT u.name, u.email, COUNT(rs.id) as sessions, SUM(rs.duration_seconds) as total_time
    FROM reading_sessions rs
    JOIN users u ON rs.user_id = u.id
    GROUP BY rs.user_id
    ORDER BY total_time DESC
    LIMIT 5
");
$stats['most_active_readers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT ITEMS =====
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
    SELECT * FROM blog_posts 
    WHERE category = 'Christian Reflections' 
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

// Recent videos
$stmt = $db->prepare("
    SELECT * FROM videos 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reading activity (for activity feed)
$stmt = $db->prepare("
    SELECT u.name as user_name, b.title as book_title, rp.progress_percent, rp.last_accessed_at
    FROM reading_progress rp
    JOIN users u ON rp.user_id = u.id
    JOIN books b ON rp.book_id = b.id
    WHERE rp.progress_percent > 0
    ORDER BY rp.last_accessed_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_reading_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Dashboard';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-dashboard">
    <div class="container">
        <!-- ===== PAGE HEADER ===== -->
        <div class="dashboard-header">
            <div class="dashboard-header-text">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></strong>! Here's what's happening on your site.</p>
            </div>
            <div class="dashboard-header-actions">
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
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
                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="btn btn-secondary">
                    <i class="fas fa-newspaper"></i> New Newsletter
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-secondary">
                    <i class="fas fa-video"></i> New Video
                </a>
            </div>
        </div>

        <!-- ===== STATISTICS CARDS ===== -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
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

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(192, 57, 43, 0.15); color: var(--rose);">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_groups']; ?></span>
                    <span class="stat-label">Reading Groups</span>
                </div>
            </div>

            <!-- ===== READER ANALYTICS STATS ===== -->
            <div class="stat-card" style="grid-column: span 1;">
                <div class="stat-icon" style="background: rgba(192, 57, 43, 0.15); color: var(--rose);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_reading_hours']; ?></span>
                    <span class="stat-label">Total Reading Hours</span>
                </div>
            </div>

            <div class="stat-card" style="grid-column: span 1;">
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['active_readers_7days']; ?></span>
                    <span class="stat-label">Active Readers (7d)</span>
                </div>
            </div>

            <div class="stat-card" style="grid-column: span 1;">
                <div class="stat-icon" style="background: rgba(52, 152, 219, 0.15); color: #3498db;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['active_readers_30days']; ?></span>
                    <span class="stat-label">Active Readers (30d)</span>
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
            <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="action-card">
                <i class="fas fa-users"></i>
                <span>Manage Reading Groups</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="action-card">
                <i class="fas fa-chart-line"></i>
                <span>Reader Analytics</span>
            </a>
        </div>

        <!-- ===== DASHBOARD SECTIONS GRID ===== -->
        <div class="dashboard-grid">
            <!-- ===== PENDING SESSIONS ===== -->
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

            <!-- ===== UNREAD MESSAGES ===== -->
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

            <!-- ===== RECENTLY ADDED BOOKS ===== -->
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

            <!-- ===== RECENTLY ADDED POEMS ===== -->
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

            <!-- ===== RECENT BLOG POSTS ===== -->
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

            <!-- ===== RECENT REFLECTIONS ===== -->
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

            <!-- ===== RECENT QUESTIONS ===== -->
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

            <!-- ===== RECENT USERS ===== -->
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

            <!-- ===== RECENT VIDEOS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-video"></i> Recent Videos</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_videos) > 0): ?>
                        <?php foreach ($recent_videos as $video): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($video['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($video['created_at'])); ?></small>
                                </div>
                                <span class="status-badge available">Added</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-video"></i>
                            <p>No videos yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== BOOK COMPLETION RATES ===== -->
            <div class="dashboard-section-card" style="grid-column: span 1;">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-trophy"></i> Top Completion Rates</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($stats['book_completion_rates']) > 0): ?>
                        <?php foreach ($stats['book_completion_rates'] as $book): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                    <small><?php echo $book['readers']; ?> readers • <?php echo $book['completions']; ?> finished</small>
                                </div>
                                <span class="status-badge available"><?php echo $book['completion_rate']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-trophy"></i>
                            <p>No completion data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== DROP-OFF POINTS ===== -->
            <div class="dashboard-section-card" style="grid-column: span 1;">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Drop-off Points</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($stats['drop_off_points']) > 0): ?>
                        <?php foreach ($stats['drop_off_points'] as $drop): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong>Chapter <?php echo $drop['chapter'] + 1; ?></strong>
                                    <small><?php echo $drop['drop_offs']; ?> readers stopped here</small>
                                </div>
                                <span class="status-badge missing">⚠️</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No drop-off data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== MOST ACTIVE READERS ===== -->
            <div class="dashboard-section-card" style="grid-column: span 1;">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-fire"></i> Most Active Readers</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($stats['most_active_readers']) > 0): ?>
                        <?php foreach ($stats['most_active_readers'] as $reader): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($reader['name']); ?></strong>
                                    <small><?php echo $reader['sessions']; ?> sessions</small>
                                </div>
                                <span class="status-badge available"><?php echo formatDuration($reader['total_time']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-fire"></i>
                            <p>No active readers yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== RECENT READING ACTIVITY ===== -->
            <div class="dashboard-section-card" style="grid-column: 1 / -1;">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-book-reader"></i> Recent Reading Activity</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-list-body">
                    <?php if (count($recent_reading_activity) > 0): ?>
                        <?php foreach ($recent_reading_activity as $activity): ?>
                            <div class="dashboard-list-item">
                                <div class="dashboard-list-item-info">
                                    <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong>
                                    <small>read <?php echo htmlspecialchars($activity['book_title']); ?></small>
                                </div>
                                <span class="status-badge available"><?php echo $activity['progress_percent']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <i class="fas fa-book-reader"></i>
                            <p>No reading activity yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('dashboardTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('dashboardTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.admin-dashboard { padding: 32px 0 60px; }
.dashboard-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 32px; }
.dashboard-header h1 { font-size: 2rem; margin-bottom: 4px; }
.dashboard-header p { color: var(--text-light); font-size: 1.05rem; }
.dashboard-header-actions { display: flex; gap: 12px; flex-wrap: wrap; }

/* ===== STATS CARDS ===== */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
.stat-card { background: var(--card-bg); border-radius: 16px; padding: 20px; box-shadow: var(--shadow); border: 1px solid var(--border); display: flex; align-items: center; gap: 16px; transition: all 0.2s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; background: rgba(192,57,43,0.15); color: var(--rose); }
.stat-content { display: flex; flex-direction: column; }
.stat-number { font-size: 1.6rem; font-weight: 700; color: var(--dark); line-height: 1.2; }
.stat-label { font-size: 0.85rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }

/* ===== QUICK ACTIONS ===== */
.quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 32px; }
.action-card { background: var(--card-bg); border-radius: 12px; padding: 16px; text-align: center; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s; text-decoration: none; color: var(--text); display: flex; flex-direction: column; align-items: center; gap: 6px; }
.action-card i { font-size: 1.4rem; color: var(--rose); display: block; }
.action-card span { font-weight: 500; font-size: 0.9rem; }
.action-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: var(--rose); color: var(--rose); }

/* ===== DASHBOARD SECTIONS ===== */
.dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
.dashboard-section-card { background: var(--card-bg); border-radius: 12px; border-left: 6px solid var(--rose); box-shadow: var(--shadow); overflow: hidden; }
.dashboard-section-header { background: var(--vanilla); padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); }
.dashboard-section-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--dark); margin: 0; display: flex; align-items: center; gap: 6px; }
.dashboard-section-header h3 i { color: var(--rose-dark); font-size: 1rem; }
.view-all-link { font-size: 0.8rem; font-weight: 600; color: var(--rose-dark); text-decoration: none; transition: color 0.2s; }
.view-all-link:hover { color: var(--rose); text-decoration: underline; }

.dashboard-list-body { background: var(--white); }
.dashboard-list-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; border-bottom: 1px solid var(--border); transition: background 0.2s; }
.dashboard-list-item:last-child { border-bottom: none; }
.dashboard-list-item:hover { background: rgba(219,161,162,0.05); }
.dashboard-list-item-info { display: flex; flex-direction: column; gap: 2px; }
.dashboard-list-item-info strong { font-size: 0.9rem; color: var(--text); }
.dashboard-list-item-info small { font-size: 0.75rem; color: var(--text-light); }

.empty-state-card { padding: 16px 12px; display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--text-light); background: var(--fantasy); font-size: 0.85rem; min-height: 40px; }
.empty-state-card i { font-size: 1.2rem; color: var(--border); }
.empty-state-card p { margin: 0; font-weight: 400; }

.status-badge { padding: 2px 10px; border-radius: 10px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
.status-badge.pending { background: #f1c40f; color: #fff; }
.status-badge.unread { background: var(--rose); color: #fff; }
.status-badge.available { background: #2ecc71; color: #fff; }
.status-badge.missing { background: #e74c3c; color: #fff; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .dashboard-header { flex-direction: column; align-items: flex-start; }
    .dashboard-header-actions { width: 100%; }
    .dashboard-header-actions .btn { flex: 1; justify-content: center; }
    .dashboard-grid { grid-template-columns: 1fr !important; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .quick-actions-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<?php require_once '../includes/footer.php'; ?>