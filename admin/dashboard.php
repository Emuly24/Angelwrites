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
$stmt = $db->query("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions");
$total_seconds = $stmt->fetchColumn() ?? 0;
$stats['total_reading_hours'] = floor($total_seconds / 3600);

$stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM reading_sessions WHERE start_time > date('now', '-7 days')");
$stats['active_readers_7days'] = $stmt->fetchColumn();

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

// Drop-off points
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

// Recent reading activity
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
                <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></strong>! Here's what's happening across your site.</p>
            </div>
            <div class="dashboard-header-actions">
                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Book
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/poem_editor.php" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> New Poem
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/editor.php" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> New Blog
                </a>
            </div>
        </div>

        <!-- ===== STATISTICS CARDS ===== -->
        <div class="stats-grid">
            <div class="stat-card stat-users">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_users']; ?></span>
                    <span class="stat-label">Total Users</span>
                </div>
            </div>

            <div class="stat-card stat-books">
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_books']; ?></span>
                    <span class="stat-label">Total Books</span>
                </div>
            </div>

            <div class="stat-card stat-poems">
                <div class="stat-icon"><i class="fas fa-pen"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_poems']; ?></span>
                    <span class="stat-label">Total Poems</span>
                </div>
            </div>

            <div class="stat-card stat-sessions">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_sessions']; ?></span>
                    <span class="stat-label">Total Sessions</span>
                </div>
            </div>

            <div class="stat-card stat-posts">
                <div class="stat-icon"><i class="fas fa-blog"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_posts']; ?></span>
                    <span class="stat-label">Blog Posts</span>
                </div>
            </div>

            <div class="stat-card stat-questions">
                <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_questions']; ?></span>
                    <span class="stat-label">Community Q&A</span>
                </div>
            </div>

            <div class="stat-card stat-subscribers">
                <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_subscribers']; ?></span>
                    <span class="stat-label">Newsletter Subscribers</span>
                </div>
            </div>

            <div class="stat-card stat-reflections">
                <div class="stat-icon"><i class="fas fa-church"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_reflections']; ?></span>
                    <span class="stat-label">Reflections</span>
                </div>
            </div>

            <div class="stat-card stat-videos">
                <div class="stat-icon"><i class="fas fa-video"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_videos']; ?></span>
                    <span class="stat-label">Videos</span>
                </div>
            </div>

            <div class="stat-card stat-groups">
                <div class="stat-icon"><i class="fas fa-users-cog"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_groups']; ?></span>
                    <span class="stat-label">Reading Groups</span>
                </div>
            </div>

            <div class="stat-card stat-hours">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['total_reading_hours']; ?></span>
                    <span class="stat-label">Total Reading Hours</span>
                </div>
            </div>

            <div class="stat-card stat-active7">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-content">
                    <span class="stat-number"><?php echo $stats['active_readers_7days']; ?></span>
                    <span class="stat-label">Active (7 days)</span>
                </div>
            </div>
        </div>

        <!-- ===== QUICK ACTIONS ===== -->
        <div class="quick-actions-grid">
            <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="action-card">
                <div class="action-icon"><i class="fas fa-book"></i></div>
                <span>Manage Books</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="action-card">
                <div class="action-icon"><i class="fas fa-pen"></i></div>
                <span>Manage Poems</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="action-card">
                <div class="action-icon"><i class="fas fa-calendar-check"></i></div>
                <span>Manage Sessions</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="action-card">
                <div class="action-icon"><i class="fas fa-users-cog"></i></div>
                <span>Manage Users</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="action-card">
                <div class="action-icon"><i class="fas fa-edit"></i></div>
                <span>Manage Blog</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="action-card">
                <div class="action-icon"><i class="fas fa-church"></i></div>
                <span>Manage Reflections</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="action-card">
                <div class="action-icon"><i class="fas fa-question"></i></div>
                <span>Manage Q&A</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="action-card">
                <div class="action-icon"><i class="fas fa-envelope"></i></div>
                <span>Manage Messages</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="action-card">
                <div class="action-icon"><i class="fas fa-video"></i></div>
                <span>Manage Videos</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="action-card">
                <div class="action-icon"><i class="fas fa-newspaper"></i></div>
                <span>Manage Newsletter</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="action-card">
                <div class="action-icon"><i class="fas fa-cog"></i></div>
                <span>Site Settings</span>
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="action-card">
                <div class="action-icon"><i class="fas fa-users"></i></div>
                <span>Manage Groups</span>
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_sessions) > 0): ?>
                        <?php foreach ($recent_sessions as $session): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($session['user_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($session['date']); ?> • <?php echo htmlspecialchars($session['time']); ?></small>
                                </div>
                                <span class="status-badge status-pending">Pending</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_messages) > 0): ?>
                        <?php foreach ($recent_messages as $message): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                                    <small><?php echo htmlspecialchars(substr($message['message'], 0, 40)); ?>...</small>
                                </div>
                                <span class="status-badge status-unread">Unread</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No unread messages</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== RECENT BOOKS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-book"></i> Recent Books</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($recent_books) > 0): ?>
                        <?php foreach ($recent_books as $book): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                    <small>by <?php echo htmlspecialchars($book['author']); ?></small>
                                </div>
                                <span class="status-badge status-available">Available</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book"></i>
                            <p>No books added yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== RECENT POEMS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-pen"></i> Recent Poems</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($recent_poems) > 0): ?>
                        <?php foreach ($recent_poems as $poem): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($poem['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></small>
                                </div>
                                <span class="status-badge status-available">Added</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_posts) > 0): ?>
                        <?php foreach ($recent_posts as $post): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                    <small><?php echo htmlspecialchars($post['category'] ?? 'Uncategorized'); ?></small>
                                </div>
                                <span class="status-badge <?php echo $post['status'] === 'published' ? 'status-available' : 'status-pending'; ?>">
                                    <?php echo ucfirst($post['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_reflections) > 0): ?>
                        <?php foreach ($recent_reflections as $reflection): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($reflection['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($reflection['created_at'])); ?></small>
                                </div>
                                <span class="status-badge status-available">Published</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_questions) > 0): ?>
                        <?php foreach ($recent_questions as $question): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($question['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($question['created_at'])); ?></small>
                                </div>
                                <span class="status-badge <?php echo $question['is_answered'] ? 'status-available' : 'status-pending'; ?>">
                                    <?php echo $question['is_answered'] ? 'Answered' : 'Open'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-question-circle"></i>
                            <p>No questions yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== NEWEST USERS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-users"></i> Newest Users</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($recent_users) > 0): ?>
                        <?php foreach ($recent_users as $user): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                    <small><?php echo htmlspecialchars($user['email']); ?></small>
                                </div>
                                <span class="status-badge <?php echo $user['role'] === 'admin' ? 'status-unread' : 'status-available'; ?>">
                                    <?php echo ucfirst($user['role'] ?? 'User'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                <div class="dashboard-section-body">
                    <?php if (count($recent_videos) > 0): ?>
                        <?php foreach ($recent_videos as $video): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($video['title']); ?></strong>
                                    <small><?php echo date('M j, Y', strtotime($video['created_at'])); ?></small>
                                </div>
                                <span class="status-badge status-available">Added</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-video"></i>
                            <p>No videos yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== TOP COMPLETION RATES ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-trophy"></i> Top Completion Rates</h3>
                    <a href="<?php echo SITE_URL; ?>/reader/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($stats['book_completion_rates']) > 0): ?>
                        <?php foreach ($stats['book_completion_rates'] as $book): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                    <small><?php echo $book['readers']; ?> readers • <?php echo $book['completions']; ?> finished</small>
                                </div>
                                <span class="status-badge status-available"><?php echo $book['completion_rate']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-trophy"></i>
                            <p>No completion data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== DROP-OFF POINTS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Drop-off Points</h3>
                    <a href="<?php echo SITE_URL; ?>/reader/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($stats['drop_off_points']) > 0): ?>
                        <?php foreach ($stats['drop_off_points'] as $drop): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong>Chapter <?php echo $drop['chapter'] + 1; ?></strong>
                                    <small><?php echo $drop['drop_offs']; ?> readers stopped here</small>
                                </div>
                                <span class="status-badge status-missing">⚠️ Drop-off</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No drop-off data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== MOST ACTIVE READERS ===== -->
            <div class="dashboard-section-card">
                <div class="dashboard-section-header">
                    <h3><i class="fas fa-fire"></i> Most Active Readers</h3>
                    <a href="<?php echo SITE_URL; ?>/reader/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($stats['most_active_readers']) > 0): ?>
                        <?php foreach ($stats['most_active_readers'] as $reader): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($reader['name']); ?></strong>
                                    <small><?php echo $reader['sessions']; ?> sessions</small>
                                </div>
                                <span class="status-badge status-available"><?php echo formatDuration($reader['total_time']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
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
                    <a href="<?php echo SITE_URL; ?>/reader/admin/reader_analytics.php" class="view-all-link">View All &rarr;</a>
                </div>
                <div class="dashboard-section-body">
                    <?php if (count($recent_reading_activity) > 0): ?>
                        <?php foreach ($recent_reading_activity as $activity): ?>
                            <div class="dashboard-list-item">
                                <div class="list-item-info">
                                    <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong>
                                    <small>read <?php echo htmlspecialchars($activity['book_title']); ?></small>
                                </div>
                                <span class="status-badge status-available"><?php echo $activity['progress_percent']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-reader"></i>
                            <p>No reading activity yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== RESET & BASE ===== */
.admin-dashboard *,
.admin-dashboard *::before,
.admin-dashboard *::after {
    box-sizing: border-box;
}

.admin-dashboard {
    padding: 32px 0 60px;
    font-family: 'Inter', sans-serif;
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

.dashboard-header-text h1 {
    font-size: 2.2rem;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--text);
}

.dashboard-header-text p {
    font-size: 1.05rem;
    color: var(--text-light);
    margin: 0;
}

.dashboard-header-text p strong {
    color: var(--text);
}

.dashboard-header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.dashboard-header-actions .btn {
    padding: 10px 20px;
    border-radius: 30px;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.dashboard-header-actions .btn-primary {
    background: var(--rose);
    color: white;
    border: none;
}

.dashboard-header-actions .btn-primary:hover {
    background: var(--rose-dark);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(192, 57, 43, 0.3);
}

.dashboard-header-actions .btn-secondary {
    background: var(--card-bg);
    color: var(--text);
    border: 1px solid var(--border);
}

.dashboard-header-actions .btn-secondary:hover {
    border-color: var(--rose);
    color: var(--rose);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

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
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: 16px 16px 0 0;
}

.stat-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--shadow-hover);
}

.stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
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
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* ===== STAT CARD VARIANTS ===== */
.stat-users::before { background: #3498db; }
.stat-users .stat-icon { background: rgba(52, 152, 219, 0.15); color: #3498db; }

.stat-books::before { background: #2ecc71; }
.stat-books .stat-icon { background: rgba(46, 204, 113, 0.15); color: #2ecc71; }

.stat-poems::before { background: #9b59b6; }
.stat-poems .stat-icon { background: rgba(155, 89, 182, 0.15); color: #9b59b6; }

.stat-sessions::before { background: #f39c12; }
.stat-sessions .stat-icon { background: rgba(243, 156, 18, 0.15); color: #f39c12; }

.stat-posts::before { background: #e67e22; }
.stat-posts .stat-icon { background: rgba(230, 126, 34, 0.15); color: #e67e22; }

.stat-questions::before { background: #e74c3c; }
.stat-questions .stat-icon { background: rgba(231, 76, 60, 0.15); color: #e74c3c; }

.stat-subscribers::before { background: #ff4081; }
.stat-subscribers .stat-icon { background: rgba(255, 64, 129, 0.15); color: #ff4081; }

.stat-reflections::before { background: #1abc9c; }
.stat-reflections .stat-icon { background: rgba(26, 188, 156, 0.15); color: #1abc9c; }

.stat-videos::before { background: #ff9f40; }
.stat-videos .stat-icon { background: rgba(255, 159, 64, 0.15); color: #ff9f40; }

.stat-groups::before { background: #8e44ad; }
.stat-groups .stat-icon { background: rgba(142, 68, 173, 0.15); color: #8e44ad; }

.stat-hours::before { background: #00b4d8; }
.stat-hours .stat-icon { background: rgba(0, 180, 216, 0.15); color: #00b4d8; }

.stat-active7::before { background: #2ecc71; }
.stat-active7 .stat-icon { background: rgba(46, 204, 113, 0.15); color: #2ecc71; }

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
    padding: 18px 12px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    color: var(--text);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.action-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
    border-color: var(--rose);
    color: var(--rose);
}

.action-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(192, 57, 43, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: var(--rose);
    transition: all 0.3s ease;
}

.action-card:hover .action-icon {
    background: var(--rose);
    color: white;
    transform: scale(1.1);
}

.action-card span {
    font-weight: 500;
    font-size: 0.85rem;
}

/* ===== DASHBOARD SECTIONS ===== */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
}

.dashboard-section-card {
    background: var(--card-bg);
    border-radius: 16px;
    box-shadow: var(--shadow);
    overflow: hidden;
    border: 1px solid var(--border);
    transition: all 0.3s ease;
}

.dashboard-section-card:hover {
    box-shadow: var(--shadow-hover);
}

.dashboard-section-header {
    background: var(--vanilla);
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
}

.dashboard-section-header h3 {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dashboard-section-header h3 i {
    color: var(--rose);
    font-size: 1rem;
}

.view-all-link {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--rose);
    text-decoration: none;
    transition: color 0.2s;
}

.view-all-link:hover {
    color: var(--rose-dark);
    text-decoration: underline;
}

.dashboard-section-body {
    padding: 4px 0;
}

/* ===== LIST ITEMS ===== */
.dashboard-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 20px;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s ease;
}

.dashboard-list-item:last-child {
    border-bottom: none;
}

.dashboard-list-item:hover {
    background: rgba(192, 57, 43, 0.04);
}

.list-item-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.list-item-info strong {
    font-size: 0.9rem;
    color: var(--text);
}

.list-item-info small {
    font-size: 0.75rem;
    color: var(--text-light);
}

/* ===== STATUS BADGES ===== */
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.status-pending { background: #f1c40f; color: white; }
.status-unread { background: var(--rose); color: white; }
.status-available { background: #2ecc71; color: white; }
.status-missing { background: #e74c3c; color: white; }

/* ===== EMPTY STATE ===== */
.empty-state {
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--text-light);
}

.empty-state i {
    font-size: 1.6rem;
    color: var(--border);
    opacity: 0.6;
}

.empty-state p {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 400;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .dashboard-header-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .dashboard-header-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .dashboard-section-header h3 {
        font-size: 0.85rem;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .stat-card {
        padding: 14px 16px;
        gap: 12px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }
    
    .stat-number {
        font-size: 1.4rem;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .dashboard-list-item {
        padding: 8px 14px;
        flex-wrap: wrap;
        gap: 4px;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>