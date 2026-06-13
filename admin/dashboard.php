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

<div class="dashboard-page">
    <div class="container">
        <!-- ===== HERO / WELCOME SECTION ===== -->
        <div class="dashboard-hero">
            <div class="hero-content">
                <h1>Welcome back, <span class="rose-text"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>!</h1>
                <p class="hero-sub">Your command center – here's what's happening across your site.</p>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <i class="fas fa-users"></i>
                        <strong><?php echo $stats['total_users']; ?></strong> Users
                    </div>
                    <div class="hero-stat">
                        <i class="fas fa-book-open"></i>
                        <strong><?php echo $stats['total_books']; ?></strong> Books
                    </div>
                    <div class="hero-stat">
                        <i class="fas fa-calendar-check"></i>
                        <strong><?php echo $stats['total_sessions']; ?></strong> Sessions
                    </div>
                </div>
            </div>
            <div class="hero-profile">
                <div class="profile-pic-large">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-details">
                    <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h3>
                    <p class="user-email"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@angelwrites.com'); ?></p>
                    <div class="badge-container">
                        <span class="badge">Admin</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== STATS ROW ===== -->
        <div class="stats-row">
            <div class="stat-card stat-users">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>

            <div class="stat-card stat-books">
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                <div class="stat-number"><?php echo $stats['total_books']; ?></div>
                <div class="stat-label">Total Books</div>
            </div>

            <div class="stat-card stat-poems">
                <div class="stat-icon"><i class="fas fa-pen"></i></div>
                <div class="stat-number"><?php echo $stats['total_poems']; ?></div>
                <div class="stat-label">Total Poems</div>
            </div>

            <div class="stat-card stat-sessions">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-number"><?php echo $stats['total_sessions']; ?></div>
                <div class="stat-label">Total Sessions</div>
            </div>

            <div class="stat-card stat-posts">
                <div class="stat-icon"><i class="fas fa-blog"></i></div>
                <div class="stat-number"><?php echo $stats['total_posts']; ?></div>
                <div class="stat-label">Blog Posts</div>
            </div>

            <div class="stat-card stat-questions">
                <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                <div class="stat-number"><?php echo $stats['total_questions']; ?></div>
                <div class="stat-label">Community Q&A</div>
            </div>

            <div class="stat-card stat-subscribers">
                <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                <div class="stat-number"><?php echo $stats['total_subscribers']; ?></div>
                <div class="stat-label">Newsletter Subscribers</div>
            </div>

            <div class="stat-card stat-reflections">
                <div class="stat-icon"><i class="fas fa-church"></i></div>
                <div class="stat-number"><?php echo $stats['total_reflections']; ?></div>
                <div class="stat-label">Reflections</div>
            </div>

            <div class="stat-card stat-videos">
                <div class="stat-icon"><i class="fas fa-video"></i></div>
                <div class="stat-number"><?php echo $stats['total_videos']; ?></div>
                <div class="stat-label">Videos</div>
            </div>

            <div class="stat-card stat-groups">
                <div class="stat-icon"><i class="fas fa-users-cog"></i></div>
                <div class="stat-number"><?php echo $stats['total_groups']; ?></div>
                <div class="stat-label">Reading Groups</div>
            </div>

            <div class="stat-card stat-hours">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo $stats['total_reading_hours']; ?></div>
                <div class="stat-label">Total Reading Hours</div>
            </div>

            <div class="stat-card stat-active7">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?php echo $stats['active_readers_7days']; ?></div>
                <div class="stat-label">Active (7 days)</div>
            </div>
        </div>

        <!-- ===== MAIN GRID ===== -->
        <div class="dashboard-grid">
            <div class="main-content">
                <!-- ===== PENDING SESSIONS ===== -->
                <section class="dashboard-section" id="pending-sessions">
                    <div class="section-header">
                        <h2><i class="fas fa-clock section-icon"></i> Pending Sessions</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                    </div>
                    <div class="dashboard-section-body">
                        <?php if (count($recent_sessions) > 0): ?>
                            <div class="session-list">
                                <?php foreach ($recent_sessions as $session): ?>
                                    <div class="session-item">
                                        <div class="session-info">
                                            <div class="session-date"><?php echo date('M j, Y', strtotime($session['date'])); ?></div>
                                            <div class="session-time"><?php echo date('g:i a', strtotime($session['time'])); ?></div>
                                            <span class="status-badge status-pending">Pending</span>
                                            <small> – <?php echo htmlspecialchars($session['user_name']); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-clock"></i></div>
                                <p>No pending sessions</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== UNREAD MESSAGES ===== -->
                <section class="dashboard-section" id="unread-messages">
                    <div class="section-header">
                        <h2><i class="fas fa-envelope section-icon"></i> Unread Messages</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                    </div>
                    <div class="dashboard-section-body">
                        <?php if (count($recent_messages) > 0): ?>
                            <div class="session-list">
                                <?php foreach ($recent_messages as $message): ?>
                                    <div class="session-item">
                                        <div class="session-info">
                                            <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                                            <small><?php echo htmlspecialchars(substr($message['message'], 0, 40)); ?>...</small>
                                            <span class="status-badge status-unread">Unread</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                                <p>No unread messages</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== RECENT CONTENT GRID ===== -->
                <div class="recent-content-grid">
                    <!-- RECENT BOOKS -->
                    <div class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-book section-icon"></i> Recent Books</h2>
                            <div class="section-actions">
                                <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                        </div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_books) > 0): ?>
                                <div class="book-grid mini-grid">
                                    <?php foreach ($recent_books as $book): ?>
                                        <div class="book-card">
                                            <div class="book-cover-wrapper" style="height:120px;">
                                                <?php if ($book['cover_path']): ?>
                                                    <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                                <?php else: ?>
                                                    <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="book-info">
                                                <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                                <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>No books added yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RECENT POEMS -->
                    <div class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-pen section-icon"></i> Recent Poems</h2>
                            <div class="section-actions">
                                <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                        </div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_poems) > 0): ?>
                                <div class="poem-grid mini-grid">
                                    <?php foreach ($recent_poems as $poem): ?>
                                        <div class="poem-card">
                                            <div class="poem-thumbnail" style="height:100px;">
                                                <?php if ($poem['image_path']): ?>
                                                    <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>">
                                                <?php else: ?>
                                                    <div class="poem-thumbnail-placeholder"><i class="fas fa-feather-alt"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="poem-body">
                                                <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>No poems added yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RECENT BLOG POSTS -->
                    <div class="dashboard-section">
                        <div class="section-header">
                            <h2><i class="fas fa-blog section-icon"></i> Recent Blog Posts</h2>
                            <div class="section-actions">
                                <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                        </div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_posts) > 0): ?>
                                <div class="blog-grid mini-grid">
                                    <?php foreach ($recent_posts as $post): ?>
                                        <div class="blog-card">
                                            <div class="blog-content">
                                                <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                                <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'] ?? $post['content'], 0, 60)); ?>...</p>
                                                <span class="status-badge <?php echo $post['status'] === 'published' ? 'status-available' : 'status-pending'; ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>No blog posts yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ===== RECENT REFLECTIONS ===== -->
                <section class="dashboard-section" id="recent-reflections">
                    <div class="section-header">
                        <h2><i class="fas fa-church section-icon"></i> Recent Reflections</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                    </div>
                    <div class="dashboard-section-body">
                        <?php if (count($recent_reflections) > 0): ?>
                            <div class="reflection-grid mini-grid">
                                <?php foreach ($recent_reflections as $reflection): ?>
                                    <div class="reflection-card">
                                        <div class="reflection-body">
                                            <h3><?php echo htmlspecialchars($reflection['title']); ?></h3>
                                            <span class="status-badge status-available">Published</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No reflections yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== NEWEST USERS ===== -->
                <section class="dashboard-section" id="newest-users">
                    <div class="section-header">
                        <h2><i class="fas fa-users section-icon"></i> Newest Users</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                    </div>
                    <div class="dashboard-section-body">
                        <?php if (count($recent_users) > 0): ?>
                            <div class="session-list">
                                <?php foreach ($recent_users as $user): ?>
                                    <div class="session-item">
                                        <div class="session-info">
                                            <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                            <small><?php echo htmlspecialchars($user['email']); ?></small>
                                            <span class="status-badge <?php echo $user['role'] === 'admin' ? 'status-unread' : 'status-available'; ?>">
                                                <?php echo ucfirst($user['role'] ?? 'User'); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No users yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- ===== RECENT VIDEOS ===== -->
                <section class="dashboard-section" id="recent-videos">
                    <div class="section-header">
                        <h2><i class="fas fa-video section-icon"></i> Recent Videos</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-sm btn-outline">View All</a>
                        </div>
                    </div>
                    <div class="dashboard-section-body">
                        <?php if (count($recent_videos) > 0): ?>
                            <div class="video-grid mini-grid">
                                <?php foreach ($recent_videos as $video): ?>
                                    <div class="video-card">
                                        <div class="video-thumb" style="height:100px;">
                                            <?php if ($video['thumbnail']): ?>
                                                <img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                                            <?php else: ?>
                                                <div class="video-thumb-placeholder"><i class="fas fa-video"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="video-info">
                                            <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                            <span class="status-badge status-available">Added</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No videos yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- ===== SIDEBAR ===== -->
            <div class="dashboard-sidebar">
                <!-- ===== QUICK ACTIONS ===== -->
                <div class="sidebar-card quick-actions-card">
                    <div class="card-header">
                        <h4><i class="fas fa-bolt" style="color: var(--rose);"></i> Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="quick-action-btn">
                                <i class="fas fa-book"></i>
                                <span>Manage Books</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="quick-action-btn">
                                <i class="fas fa-pen"></i>
                                <span>Manage Poems</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="quick-action-btn">
                                <i class="fas fa-calendar-check"></i>
                                <span>Manage Sessions</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="quick-action-btn">
                                <i class="fas fa-users-cog"></i>
                                <span>Manage Users</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="quick-action-btn">
                                <i class="fas fa-edit"></i>
                                <span>Manage Blog</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_reflections.php" class="quick-action-btn">
                                <i class="fas fa-church"></i>
                                <span>Manage Reflections</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="quick-action-btn">
                                <i class="fas fa-question"></i>
                                <span>Manage Q&A</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="quick-action-btn">
                                <i class="fas fa-envelope"></i>
                                <span>Manage Messages</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="quick-action-btn">
                                <i class="fas fa-video"></i>
                                <span>Manage Videos</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="quick-action-btn">
                                <i class="fas fa-newspaper"></i>
                                <span>Manage Newsletter</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="quick-action-btn">
                                <i class="fas fa-cog"></i>
                                <span>Site Settings</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="quick-action-btn">
                                <i class="fas fa-users"></i>
                                <span>Manage Groups</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ===== MOST ACTIVE READERS ===== -->
                <div class="sidebar-card">
                    <div class="card-header">
                        <h4><i class="fas fa-fire" style="color: var(--rose);"></i> Most Active Readers</h4>
                        <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($stats['most_active_readers']) > 0): ?>
                            <div class="achievement-list">
                                <?php foreach ($stats['most_active_readers'] as $reader): ?>
                                    <div class="achievement-item">
                                        <span class="achievement-icon">🔥</span>
                                        <span class="achievement-name"><?php echo htmlspecialchars($reader['name']); ?></span>
                                        <span class="achievement-date"><?php echo $reader['sessions']; ?> sessions</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-items">No active readers yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ===== RECENT READING ACTIVITY ===== -->
                <div class="sidebar-card">
                    <div class="card-header">
                        <h4><i class="fas fa-book-reader" style="color: var(--rose);"></i> Recent Reading Activity</h4>
                        <a href="<?php echo SITE_URL; ?>/admin/reader_analytics.php" class="view-all-link">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($recent_reading_activity) > 0): ?>
                            <div class="achievement-list">
                                <?php foreach ($recent_reading_activity as $activity): ?>
                                    <div class="achievement-item">
                                        <span class="achievement-icon">📖</span>
                                        <span class="achievement-name"><?php echo htmlspecialchars($activity['user_name']); ?></span>
                                        <span class="achievement-date"><?php echo htmlspecialchars($activity['book_title']); ?></span>
                                        <span class="achievement-date"><?php echo $activity['progress_percent']; ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-items">No reading activity yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
/* ===== DASHBOARD PAGE ===== */
.dashboard-page { padding: 32px 0 60px; font-family: 'Inter', sans-serif; }

/* ===== HERO SECTION ===== */
.dashboard-hero { background: linear-gradient(135deg, var(--vanilla), #fdf5e6, var(--fantasy)); border-radius: 20px; padding: 24px 32px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; border: 1px solid var(--rose-light); box-shadow: var(--shadow); position: relative; overflow: hidden; }
.dashboard-hero::before { content: ''; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px; background: rgba(192, 57, 43, 0.05); border-radius: 50%; pointer-events: none; }

.hero-content { flex: 1; min-width: 250px; position: relative; z-index: 1; }
.hero-content h1 { font-size: 2.4rem; margin: 0 0 4px 0; color: var(--text); line-height: 1.1; font-weight: 700; word-break: break-word; overflow-wrap: break-word; }
.hero-content h1 .rose-text { color: var(--rose); display: inline-block; }
.hero-content .hero-sub { color: var(--text-light); font-size: 1.05rem; margin: 0 0 12px 0; max-width: 500px; }

.hero-stats { display: flex; gap: 12px; flex-wrap: wrap; }
.hero-stat { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-light); background: var(--card-bg); padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s ease; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.hero-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.hero-stat i { color: var(--rose); }
.hero-stat strong { color: var(--text); font-weight: 600; }

.hero-profile { display: flex; align-items: center; gap: 16px; flex-shrink: 0; position: relative; z-index: 1; }
.profile-pic-large { width: 80px; height: 80px; border-radius: 50%; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; border: 3px solid var(--rose-light); flex-shrink: 0; box-shadow: var(--shadow); }
.profile-pic-large i { font-size: 3.5rem; color: var(--rose); }
.profile-details h3 { font-size: 1.2rem; margin: 0 0 2px 0; font-weight: 700; color: var(--text); }
.profile-details .user-email { color: var(--text-light); font-size: 0.9rem; margin: 0; }

.badge-container { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
.badge-container .badge { background: var(--rose); color: white; padding: 0 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }

/* ===== DARK MODE HERO FIXES ===== */
body.dark-mode .dashboard-hero, [data-theme="dark"] .dashboard-hero { background: var(--card-bg) !important; border-color: var(--border) !important; }
body.dark-mode .dashboard-hero h1, [data-theme="dark"] .dashboard-hero h1 { color: var(--text) !important; }
body.dark-mode .dashboard-hero h1 .rose-text, [data-theme="dark"] .dashboard-hero h1 .rose-text { color: var(--rose) !important; }
body.dark-mode .dashboard-hero .hero-sub, [data-theme="dark"] .dashboard-hero .hero-sub { color: var(--text-light) !important; }
body.dark-mode .hero-stat, [data-theme="dark"] .hero-stat { background: var(--card-bg) !important; border-color: var(--border) !important; color: var(--text) !important; }
body.dark-mode .hero-stat i, [data-theme="dark"] .hero-stat i { color: var(--rose) !important; }
body.dark-mode .hero-stat strong, [data-theme="dark"] .hero-stat strong { color: var(--text) !important; }
body.dark-mode .profile-details h3, [data-theme="dark"] .profile-details h3 { color: var(--text) !important; }
body.dark-mode .profile-details .user-email, [data-theme="dark"] .profile-details .user-email { color: var(--text-light) !important; }

/* ===== STATS ROW ===== */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }

.stat-card { background: var(--card-bg); border-radius: 16px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; min-height: 80px; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: 16px 16px 0 0; }
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }

.stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.stat-content { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.stat-number { font-size: 1.6rem; font-weight: 700; color: var(--text); line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-label { font-size: 0.7rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; line-height: 1.2; word-break: break-word; white-space: normal; max-width: 100%; }

/* ===== STAT CARD COLORS ===== */
.stat-users::before { background: #3498db; }
.stat-users .stat-icon { background: rgba(52,152,219,0.15); color: #3498db; }

.stat-books::before { background: #2ecc71; }
.stat-books .stat-icon { background: rgba(46,204,113,0.15); color: #2ecc71; }

.stat-poems::before { background: #9b59b6; }
.stat-poems .stat-icon { background: rgba(155,89,182,0.15); color: #9b59b6; }

.stat-sessions::before { background: #f39c12; }
.stat-sessions .stat-icon { background: rgba(243,156,18,0.15); color: #f39c12; }

.stat-posts::before { background: #e67e22; }
.stat-posts .stat-icon { background: rgba(230,126,34,0.15); color: #e67e22; }

.stat-questions::before { background: #e74c3c; }
.stat-questions .stat-icon { background: rgba(231,76,60,0.15); color: #e74c3c; }

.stat-subscribers::before { background: #ff4081; }
.stat-subscribers .stat-icon { background: rgba(255,64,129,0.15); color: #ff4081; }

.stat-reflections::before { background: #1abc9c; }
.stat-reflections .stat-icon { background: rgba(26,188,156,0.15); color: #1abc9c; }

.stat-videos::before { background: #ff9f40; }
.stat-videos .stat-icon { background: rgba(255,159,64,0.15); color: #ff9f40; }

.stat-groups::before { background: #8e44ad; }
.stat-groups .stat-icon { background: rgba(142,68,173,0.15); color: #8e44ad; }

.stat-hours::before { background: #00b4d8; }
.stat-hours .stat-icon { background: rgba(0,180,216,0.15); color: #00b4d8; }

.stat-active7::before { background: #2ecc71; }
.stat-active7 .stat-icon { background: rgba(46,204,113,0.15); color: #2ecc71; }

/* ===== GRID LAYOUT ===== */
.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }
.main-content { display: flex; flex-direction: column; gap: 32px; }
.recent-content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

/* ===== SECTIONS ===== */
.dashboard-section { background: var(--card-bg); border-radius: 16px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s ease; }
.dashboard-section:hover { box-shadow: var(--shadow-hover); }

.section-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.section-header h2 { font-size: 1.2rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.section-header h2 .section-icon { color: var(--rose); }
.section-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.section-actions .btn { padding: 6px 16px; font-size: 0.8rem; border-radius: 20px; font-weight: 600; }

.dashboard-section-body { padding: 0; }

/* ===== MINI GRIDS ===== */
.mini-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }

/* ===== BOOK CARDS ===== */
.book-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.book-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.book-cover-wrapper { position: relative; height: 140px; background: var(--vanilla); overflow: hidden; }
.book-cover-wrapper img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-cover { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); }
.book-info { padding: 10px; }
.book-info h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }
.book-author { font-size: 0.75rem; color: var(--text-light); margin: 0; }

/* ===== POEM CARDS ===== */
.poem-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.poem-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.poem-thumbnail { height: 100px; background: var(--vanilla); overflow: hidden; }
.poem-thumbnail img { width: 100%; height: 100%; object-fit: cover; }
.poem-thumbnail-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); }
.poem-body { padding: 10px; }
.poem-body h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }

/* ===== BLOG CARDS ===== */
.blog-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.blog-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.blog-content { padding: 10px; }
.blog-content h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }
.blog-excerpt { font-size: 0.75rem; color: var(--text-light); margin: 0 0 4px; }

/* ===== REFLECTION CARDS ===== */
.reflection-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.reflection-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.reflection-body { padding: 10px; }
.reflection-body h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }

/* ===== VIDEO CARDS ===== */
.video-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.video-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.video-thumb { height: 100px; background: var(--vanilla); overflow: hidden; }
.video-thumb img { width: 100%; height: 100%; object-fit: cover; }
.video-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); }
.video-info { padding: 10px; }
.video-info h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }

/* ===== SESSION & QA LISTS ===== */
.session-list, .qa-list { display: flex; flex-direction: column; gap: 8px; }
.session-item, .qa-item { background: var(--bg); padding: 12px; border-radius: 10px; border: 1px solid var(--border); transition: all 0.2s ease; }
.session-item:hover, .qa-item:hover { box-shadow: var(--shadow); border-color: var(--rose-light); }
.session-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.session-date, .session-time { font-weight: 500; font-size: 0.9rem; color: var(--text); }

/* ===== STATUS BADGES ===== */
.status-badge { padding: 2px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.status-pending { background: #f1c40f; color: white; }
.status-unread { background: var(--rose); color: white; }
.status-available { background: #2ecc71; color: white; }
.status-missing { background: #e74c3c; color: white; }

/* ===== EMPTY STATE ===== */
.empty-state { text-align: center; padding: 24px; color: var(--text-light); }
.empty-state-icon { display: block; font-size: 2.5rem; color: var(--rose); margin-bottom: 12px; opacity: 0.6; }
.empty-state p { margin: 0; font-size: 0.95rem; }
.empty-state a { color: var(--rose); font-weight: 600; text-decoration: none; }
.empty-state a:hover { text-decoration: underline; }

/* ===== SIDEBAR ===== */
.dashboard-sidebar { display: flex; flex-direction: column; gap: 32px; }
.sidebar-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s ease; }
.sidebar-card:hover { box-shadow: var(--shadow-hover); }
.sidebar-card .card-header { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.sidebar-card .card-header h4 { font-size: 1rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.sidebar-card .card-header h4 i { color: var(--rose); }
.sidebar-card .card-header-actions { display: flex; gap: 8px; align-items: center; }
.view-all-link { font-size: 0.8rem; font-weight: 600; color: var(--rose); text-decoration: none; transition: color 0.2s; }
.view-all-link:hover { color: var(--rose-dark); text-decoration: underline; }
.card-body { padding: 0; }

/* ===== QUICK ACTIONS ===== */
.quick-actions-card .card-body { padding: 12px; }
.quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; }
.quick-action-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px 8px; background: var(--bg); border-radius: 10px; border: 1px solid var(--border); text-decoration: none; color: var(--text); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); gap: 6px; }
.quick-action-btn:hover { background: var(--vanilla); border-color: var(--rose); transform: translateY(-3px); box-shadow: var(--shadow); }
.quick-action-btn i { font-size: 1.4rem; color: var(--rose); }
.quick-action-btn span { font-size: 0.7rem; text-align: center; line-height: 1.2; font-weight: 500; word-break: break-word; white-space: normal; max-width: 100%; }

/* ===== NOTIFICATIONS ===== */
.notification-list { display: flex; flex-direction: column; gap: 8px; }
.notification-item { background: var(--bg); padding: 12px; border-radius: 10px; border-left: 3px solid transparent; transition: all 0.2s ease; }
.notification-item:hover { box-shadow: var(--shadow); }
.notification-item.unread { border-left-color: var(--rose); }
.notif-content { flex: 1; }
.notif-title { font-weight: 600; font-size: 0.9rem; color: var(--text); }
.notif-message { font-size: 0.85rem; color: var(--text-light); margin: 2px 0; }
.notif-date { font-size: 0.75rem; color: var(--text-light); }

/* ===== MOST ACTIVE READERS LIST ===== */
.achievement-list { display: flex; flex-direction: column; gap: 6px; }
.achievement-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border); transition: all 0.2s ease; }
.achievement-item:hover { box-shadow: var(--shadow); }
.achievement-icon { font-size: 1.2rem; }
.achievement-name { font-weight: 500; font-size: 0.85rem; flex: 1; color: var(--text); }
.achievement-date { font-size: 0.7rem; color: var(--text-light); }
.no-items { text-align: center; color: var(--text-light); font-size: 0.9rem; padding: 8px 0; }

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } .recent-content-grid { grid-template-columns: 1fr; } }
@media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; } .stat-number { font-size: 1.4rem; } .stat-label { font-size: 0.6rem; } }
@media (max-width: 768px) { 
    .dashboard-hero { flex-direction: column; text-align: center; align-items: center; padding: 20px; }
    .hero-profile { flex-direction: column; text-align: center; align-items: center; }
    .hero-content h1 { font-size: 1.8rem; }
    .hero-content .hero-sub { font-size: 1rem; }
    .hero-stats { justify-content: center; }
    .hero-stat { white-space: normal; padding: 4px 10px; font-size: 0.8rem; }
    .stats-grid { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
    .stat-card { padding: 10px 12px; min-height: 70px; }
    .stat-icon { width: 36px; height: 36px; font-size: 1rem; }
    .stat-number { font-size: 1.3rem; }
    .stat-label { font-size: 0.55rem; letter-spacing: 0; }
    .dashboard-section { padding: 16px; }
    .mini-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-card { padding: 8px 10px; min-height: 60px; gap: 8px; }
    .stat-icon { width: 30px; height: 30px; font-size: 0.8rem; }
    .stat-number { font-size: 1.1rem; }
    .stat-label { font-size: 0.5rem; }
    .mini-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .book-cover-wrapper { height: 100px; }
    .poem-thumbnail, .video-thumb { height: 80px; }
    .section-header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .section-actions { width: 100%; }
    .section-actions .btn { flex: 1; text-align: center; }
    .quick-actions-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<?php require_once '../includes/footer.php'; ?>