<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

// ===== PAGINATION SETUP (EXAMPLE FOR BOOKS) =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 4;
$offset = ($page - 1) * $limit;

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

// Recent books (with pagination)
$stmt = $db->prepare("
    SELECT * FROM books 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$limit, $offset]);
$recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total books for pagination
$stmt = $db->query("SELECT COUNT(*) FROM books");
$total_books = $stmt->fetchColumn();
$total_pages = ceil($total_books / $limit);

// Recent poems
$stmt = $db->prepare("
    SELECT * FROM poems 
    ORDER BY created_at DESC 
    LIMIT 6
");
$stmt->execute();
$recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent blog posts
$stmt = $db->prepare("
    SELECT * FROM blog_posts 
    ORDER BY created_at DESC 
    LIMIT 6
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
    LIMIT 6
");
$stmt->execute();
$recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== NEW: RECENT READING GROUPS =====
$stmt = $db->prepare("
    SELECT name, created_at FROM reading_groups 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <div class="stat-card stat-users"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-number"><?php echo $stats['total_users']; ?></div><div class="stat-label">Total Users</div></div>
            <div class="stat-card stat-books"><div class="stat-icon"><i class="fas fa-book-open"></i></div><div class="stat-number"><?php echo $stats['total_books']; ?></div><div class="stat-label">Total Books</div></div>
            <div class="stat-card stat-poems"><div class="stat-icon"><i class="fas fa-pen"></i></div><div class="stat-number"><?php echo $stats['total_poems']; ?></div><div class="stat-label">Total Poems</div></div>
            <div class="stat-card stat-sessions"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-number"><?php echo $stats['total_sessions']; ?></div><div class="stat-label">Total Sessions</div></div>
            <div class="stat-card stat-posts"><div class="stat-icon"><i class="fas fa-blog"></i></div><div class="stat-number"><?php echo $stats['total_posts']; ?></div><div class="stat-label">Blog Posts</div></div>
            <div class="stat-card stat-questions"><div class="stat-icon"><i class="fas fa-question-circle"></i></div><div class="stat-number"><?php echo $stats['total_questions']; ?></div><div class="stat-label">Community Q&A</div></div>
            <div class="stat-card stat-subscribers"><div class="stat-icon"><i class="fas fa-envelope"></i></div><div class="stat-number"><?php echo $stats['total_subscribers']; ?></div><div class="stat-label">Newsletter Subscribers</div></div>
            <div class="stat-card stat-reflections"><div class="stat-icon"><i class="fas fa-church"></i></div><div class="stat-number"><?php echo $stats['total_reflections']; ?></div><div class="stat-label">Reflections</div></div>
            <div class="stat-card stat-videos"><div class="stat-icon"><i class="fas fa-video"></i></div><div class="stat-number"><?php echo $stats['total_videos']; ?></div><div class="stat-label">Videos</div></div>
            <div class="stat-card stat-groups"><div class="stat-icon"><i class="fas fa-users-cog"></i></div><div class="stat-number"><?php echo $stats['total_groups']; ?></div><div class="stat-label">Reading Groups</div></div>
            <div class="stat-card stat-hours"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-number"><?php echo $stats['total_reading_hours']; ?></div><div class="stat-label">Total Reading Hours</div></div>
            <div class="stat-card stat-active7"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div class="stat-number"><?php echo $stats['active_readers_7days']; ?></div><div class="stat-label">Active (7 days)</div></div>
        </div>

        <!-- ===== MAIN GRID ===== -->
        <div class="dashboard-grid">
            <div class="main-content">
                <div class="alert-row">
                    <section class="dashboard-section compact-section" id="pending-sessions">
                        <div class="section-header"><h2><i class="fas fa-clock section-icon"></i> Pending</h2><a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="btn btn-sm btn-outline">View All</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_sessions) > 0): ?>
                                <div class="session-list compact-list">
                                    <?php foreach ($recent_sessions as $session): ?>
                                        <div class="session-item">
                                            <div class="session-info">
                                                <div class="session-date"><?php echo date('M j', strtotime($session['date'])); ?></div>
                                                <div class="session-time"><?php echo date('g:i a', strtotime($session['time'])); ?></div>
                                                <span class="status-badge status-pending">Pending</span>
                                                <small> – <?php echo htmlspecialchars($session['user_name']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state compact-empty"><i class="fas fa-check-circle"></i> No pending sessions</div>
                            <?php endif; ?>
                        </div>
                    </section>
                    <section class="dashboard-section compact-section" id="unread-messages">
                        <div class="section-header"><h2><i class="fas fa-envelope section-icon"></i> Unread</h2><a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-sm btn-outline">View All</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_messages) > 0): ?>
                                <div class="session-list compact-list">
                                    <?php foreach ($recent_messages as $message): ?>
                                        <div class="session-item">
                                            <div class="session-info">
                                                <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                                                <small><?php echo htmlspecialchars(substr($message['message'], 0, 35)); ?>...</small>
                                                <span class="status-badge status-unread">Unread</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state compact-empty"><i class="fas fa-inbox"></i> No unread messages</div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="recent-content-grid">
                    <div class="dashboard-section compact-section">
                        <div class="section-header"><h2><i class="fas fa-book section-icon"></i> Books</h2><a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_books) > 0): ?>
                                <div class="book-grid mini-grid">
                                    <?php foreach ($recent_books as $book): ?>
                                        <div class="book-card">
                                            <div class="book-cover-wrapper" style="height:120px;">
                                                <?php if ($book['cover_path']): ?><img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>"><?php else: ?><div class="placeholder-cover"><i class="fas fa-book"></i></div><?php endif; ?>
                                            </div>
                                            <div class="book-info"><h3><?php echo htmlspecialchars($book['title']); ?></h3><p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($total_pages > 1): ?>
                                    <div class="pagination mini-pagination">
                                        <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?>" class="page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?><a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                                        <?php if ($page < $total_pages): ?><a href="?page=<?php echo $page + 1; ?>" class="page-link"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-state compact-empty"><i class="fas fa-book-open"></i> No books yet</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dashboard-section compact-section">
                        <div class="section-header"><h2><i class="fas fa-pen section-icon"></i> Poems</h2><a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_poems) > 0): ?>
                                <div class="horizontal-scroll">
                                    <?php foreach ($recent_poems as $poem): ?>
                                        <div class="scroll-item poem-scroll-item">
                                            <div class="poem-thumbnail" style="height:100px; width:100px;">
                                                <?php if ($poem['image_path']): ?><img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>"><?php else: ?><div class="poem-thumbnail-placeholder"><i class="fas fa-feather-alt"></i></div><?php endif; ?>
                                            </div>
                                            <div class="scroll-item-body"><h4><?php echo htmlspecialchars($poem['title']); ?></h4></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state compact-empty"><i class="fas fa-feather-alt"></i> No poems yet</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dashboard-section compact-section">
                        <div class="section-header"><h2><i class="fas fa-question-circle section-icon"></i> Questions</h2><a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_questions) > 0): ?>
                                <div class="horizontal-scroll">
                                    <?php foreach ($recent_questions as $question): ?>
                                        <div class="scroll-item question-scroll-item">
                                            <div class="question-content"><h4><?php echo htmlspecialchars($question['title']); ?></h4><p class="question-excerpt"><?php echo htmlspecialchars(substr($question['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $question['is_answered'] ? 'status-available' : 'status-pending'; ?>"><?php echo $question['is_answered'] ? 'Answered' : 'Pending'; ?></span></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><p>No recent questions.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="dashboard-section compact-section">
                        <div class="section-header"><h2><i class="fas fa-envelope section-icon"></i> Messages</h2><a href="<?php echo SITE_URL; ?>/admin/manage_messages.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_messages) > 0): ?>
                                <div class="horizontal-scroll">
                                    <?php foreach ($recent_messages as $message): ?>
                                        <div class="scroll-item message-scroll-item">
                                            <div class="message-content"><h4><?php echo htmlspecialchars($message['subject']); ?></h4><p class="message-excerpt"><?php echo htmlspecialchars(substr($message['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $message['is_read'] ? 'status-available' : 'status-pending'; ?>"><?php echo $message['is_read'] ? 'Read' : 'Unread'; ?></span></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><p>No recent messages.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="dashboard-section compact-section">
                        <div class="section-header"><h2><i class="fas fa-blog section-icon"></i> Blog</h2><a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_posts) > 0): ?>
                                <div class="horizontal-scroll">
                                    <?php foreach ($recent_posts as $post): ?>
                                        <div class="scroll-item blog-scroll-item">
                                            <div class="blog-content"><h4><?php echo htmlspecialchars($post['title']); ?></h4><p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'] ?? $post['content'], 0, 60)); ?>...</p><span class="status-badge <?php echo $post['status'] === 'published' ? 'status-available' : 'status-pending'; ?>"><?php echo ucfirst($post['status']); ?></span></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-blog"></i> No blog posts yet</div><?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-section compact-section">
                    <div class="section-header"><h2><i class="fas fa-newspaper section-icon"></i> Manage Newsletter</h2><a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php" class="btn btn-sm btn-outline">Manage</a></div>
                </div>

                <!-- ===== EXPANDED BOTTOM ROW ===== -->
                <div class="bottom-row">
                    <section class="dashboard-section compact-section" id="newest-users">
                        <div class="section-header"><h2><i class="fas fa-users section-icon"></i> Newest Users</h2><a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_users) > 0): ?>
                                <div class="user-table">
                                    <?php foreach ($recent_users as $user): ?>
                                        <div class="user-row">
                                            <div class="user-name"><strong><?php echo htmlspecialchars($user['name']); ?></strong> <small><?php echo htmlspecialchars($user['email']); ?></small></div>
                                            <div class="user-role"><span class="status-badge <?php echo $user['role'] === 'admin' ? 'status-unread' : 'status-available'; ?>"><?php echo ucfirst($user['role'] ?? 'User'); ?></span></div>
                                            <?php if ($user['id'] !== $_SESSION['user_id']): ?><a href="<?php echo SITE_URL; ?>/admin/manage_users.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user?');"><i class="fas fa-trash"></i></a><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-users"></i> No users yet</div><?php endif; ?>
                        </div>
                    </section>

                    <!-- EXPANDED: Reading Groups -->
                    <section class="dashboard-section compact-section" id="manage-groups">
                        <div class="section-header"><h2><i class="fas fa-users-cog section-icon"></i> Reading Groups</h2><a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_groups) > 0): ?>
                                <div class="session-list compact-list">
                                    <?php foreach ($recent_groups as $group): ?>
                                        <div class="session-item">
                                            <div class="session-info">
                                                <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                                                <small>Created <?php echo date('M j, Y', strtotime($group['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-users-cog"></i> No reading groups yet</div><?php endif; ?>
                        </div>
                    </section>

                    <!-- EXPANDED: Community Questions -->
                    <section class="dashboard-section compact-section" id="manage-questions">
                        <div class="section-header"><h2><i class="fas fa-question-circle section-icon"></i> Community Questions</h2><a href="<?php echo SITE_URL; ?>/admin/manage_questions.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_questions) > 0): ?>
                                <div class="session-list compact-list">
                                    <?php foreach ($recent_questions as $question): ?>
                                        <div class="session-item">
                                            <div class="session-info">
                                                <strong><?php echo htmlspecialchars($question['title']); ?></strong>
                                                <small><?php echo htmlspecialchars(substr($question['content'], 0, 35)); ?>...</small>
                                                <span class="status-badge <?php echo $question['is_answered'] ? 'status-available' : 'status-pending'; ?>"><?php echo $question['is_answered'] ? 'Answered' : 'Pending'; ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-question-circle"></i> No questions yet</div><?php endif; ?>
                        </div>
                    </section>

                    <!-- EXPANDED: Christian Reflections -->
                    <section class="dashboard-section compact-section" id="manage-reflections">
                        <div class="section-header"><h2><i class="fas fa-church section-icon"></i> Christian Reflections</h2><a href="<?php echo SITE_URL; ?>/admin/manage_blog.php?category=Christian+Reflections" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_reflections) > 0): ?>
                                <div class="session-list compact-list">
                                    <?php foreach ($recent_reflections as $reflection): ?>
                                        <div class="session-item">
                                            <div class="session-info">
                                                <strong><?php echo htmlspecialchars($reflection['title']); ?></strong>
                                                <small><?php echo htmlspecialchars(substr($reflection['excerpt'] ?? $reflection['content'], 0, 35)); ?>...</small>
                                                <span class="status-badge <?php echo $reflection['status'] === 'published' ? 'status-available' : 'status-pending'; ?>"><?php echo ucfirst($reflection['status']); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-church"></i> No reflections yet</div><?php endif; ?>
                        </div>
                    </section>

                    <section class="dashboard-section compact-section" id="recent-videos">
                        <div class="section-header"><h2><i class="fas fa-video section-icon"></i> Videos</h2><a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="btn btn-sm btn-outline">Manage</a></div>
                        <div class="dashboard-section-body">
                            <?php if (count($recent_videos) > 0): ?>
                                <div class="horizontal-scroll">
                                    <?php foreach ($recent_videos as $video): ?>
                                        <div class="scroll-item video-scroll-item">
                                            <div class="video-thumb" style="height:80px; width:120px;">
                                                <?php if ($video['thumbnail']): ?><img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>"><?php else: ?><div class="video-thumb-placeholder"><i class="fas fa-video"></i></div><?php endif; ?>
                                            </div>
                                            <div class="scroll-item-body"><h4><?php echo htmlspecialchars($video['title']); ?></h4></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?><div class="empty-state compact-empty"><i class="fas fa-video"></i> No videos yet</div><?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>

            <!-- ===== SIDEBAR ===== -->
            <div class="dashboard-sidebar">
                <div class="sidebar-card quick-actions-card">
                    <div class="card-header"><h4><i class="fas fa-bolt" style="color: var(--rose);"></i> Quick Actions</h4></div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="<?php echo SITE_URL; ?>/admin/manage_books.php" class="quick-action-btn"><i class="fas fa-book"></i><span>Books</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php" class="quick-action-btn"><i class="fas fa-pen"></i><span>Poems</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_sessions.php" class="quick-action-btn"><i class="fas fa-calendar-check"></i><span>Sessions</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_users.php" class="quick-action-btn"><i class="fas fa-users-cog"></i><span>Users</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_blog.php" class="quick-action-btn"><i class="fas fa-edit"></i><span>Blog</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_videos.php" class="quick-action-btn"><i class="fas fa-video"></i><span>Videos</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="quick-action-btn"><i class="fas fa-cog"></i><span>Settings</span></a>
                            <a href="<?php echo SITE_URL; ?>/admin/manage_groups.php" class="quick-action-btn"><i class="fas fa-users"></i><span>Groups</span></a>
                        </div>
                    </div>
                </div>

                <div class="sidebar-card">
                    <div class="card-header"><h4><i class="fas fa-fire" style="color: var(--rose);"></i> Active Readers</h4></div>
                    <div class="card-body">
                        <?php if (count($stats['most_active_readers']) > 0): ?>
                            <div class="achievement-list">
                                <?php foreach ($stats['most_active_readers'] as $reader): ?>
                                    <div class="achievement-item"><span class="achievement-icon">🔥</span><span class="achievement-name"><?php echo htmlspecialchars($reader['name']); ?></span><span class="achievement-date"><?php echo $reader['sessions']; ?> sessions</span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?><p class="no-items">No active readers yet.</p><?php endif; ?>
                    </div>
                </div>

                <div class="sidebar-card">
                    <div class="card-header"><h4><i class="fas fa-book-reader" style="color: var(--rose);"></i> Recent Reading</h4></div>
                    <div class="card-body">
                        <?php if (count($recent_reading_activity) > 0): ?>
                            <div class="achievement-list">
                                <?php foreach ($recent_reading_activity as $activity): ?>
                                    <div class="achievement-item"><span class="achievement-icon">📖</span><span class="achievement-name"><?php echo htmlspecialchars($activity['user_name']); ?></span><span class="achievement-date"><?php echo htmlspecialchars($activity['book_title']); ?></span><span class="achievement-date"><?php echo $activity['progress_percent']; ?>%</span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?><p class="no-items">No reading activity yet.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ================================================================
   DASHBOARD CSS – PROPORTIONAL & RESPONSIVE (MIRRORS READER)
   ================================================================ */

:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #fff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --bg: #F7F3ED; --card-bg: #fff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.06);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.10);
    --shadow-lg: 0 16px 48px rgba(44,30,30,0.10);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);

    /* Dashboard Specific Sizing Variables */
    --dash-hero-pad: 24px 32px;
    --dash-card-pad: 24px;
    --dash-stat-pad: 10px 12px;
    --dash-stat-height: 55px;
    --dash-heading-size: 2.4rem;
    --dash-sub-size: 1.05rem;
    --dash-stat-fsize: 1.1rem;
}

[data-theme="dark"] {
    --bg: #1a1212; --card-bg: #2c1e1e; --text: #e8dddd;
    --text-light: #a08a8a; --border: #4a3a3a; --vanilla: #2c1e1e;
    --fantasy: #2c1e1e; --shadow: 0 4px 16px rgba(0,0,0,0.5);
    --shadow-hover: 0 8px 30px rgba(0,0,0,0.7);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    transition: background var(--transition), color var(--transition);
    min-height: 100vh;
}
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--border); border-radius: 4px; }
::-webkit-scrollbar-thumb { background: var(--rose); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--rose-dark); }

h1, h2, h3, h4, h5, h6 {
    font-family: 'Playfair Display', Georgia, serif;
    color: var(--dark); line-height: 1.3; letter-spacing: -0.02em;
}
.rose-text { color: var(--rose); }

/* --- Buttons --- */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 28px; border-radius: 50px; font-weight: 700;
    font-size: 0.95rem; border: none; cursor: pointer;
    text-decoration: none; transition: all var(--transition);
    box-shadow: 0 2px 8px rgba(44,30,30,0.06); letter-spacing: 0.3px;
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }

.btn-primary { background: var(--rose); color: var(--white); border: 2px solid var(--rose); }
.btn-primary:hover { background: var(--rose-dark); border-color: var(--rose-dark); }
.btn-secondary { background: var(--vanilla); color: var(--dark); border: 2px solid var(--vanilla); }
.btn-secondary:hover { background: var(--rose-light); border-color: var(--rose-light); }
.btn-outline { background: transparent; color: var(--rose); border: 2px solid var(--rose); }
.btn-outline:hover { background: var(--rose); color: var(--white); }
.btn-sm { padding: 8px 20px; font-size: 0.85rem; }
.btn-danger { background: #dc3545; color: #fff; border: 2px solid #dc3545; }
.btn-danger:hover { background: #c82333; border-color: #c82333; }

/* --- Dashboard Components --- */
.dashboard-page { padding: 32px 0 60px; }

/* Hero Section */
.dashboard-hero {
    background: linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius: 20px; padding: var(--dash-hero-pad); margin-bottom: 24px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 16px;
    border: 1px solid var(--rose-light); box-shadow: var(--shadow);
    position: relative; overflow: hidden;
}
.dashboard-hero::before {
    content: ''; position: absolute; top: -50%; right: -20%;
    width: 300px; height: 300px; background: rgba(219,161,162,0.08);
    border-radius: 50%; pointer-events: none;
}
.hero-content { flex: 1; min-width: 250px; position: relative; z-index: 1; }
.hero-content h1 { font-size: var(--dash-heading-size); margin: 0 0 4px 0; color: var(--text); line-height: 1.1; font-weight: 700; }
.hero-content .hero-sub { color: var(--text-light); font-size: var(--dash-sub-size); margin: 0 0 12px 0; max-width: 500px; }
.hero-stats { display: flex; gap: 12px; flex-wrap: wrap; }
.hero-stat {
    display: flex; align-items: center; gap: 6px; font-size: 0.85rem;
    color: var(--text-light); background: var(--card-bg); padding: 6px 14px;
    border-radius: 20px; border: 1px solid var(--border); box-shadow: var(--shadow);
    transition: all 0.2s ease;
}
.hero-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.hero-stat i { color: var(--rose); }
.hero-stat strong { color: var(--text); font-weight: 600; }

.hero-profile {
    display: flex; align-items: center; gap: 16px; flex-shrink: 0;
    position: relative; z-index: 1;
}
.profile-pic-large {
    width: 80px; height: 80px; border-radius: 50%; overflow: hidden;
    background: var(--vanilla); display: flex; align-items: center; justify-content: center;
    border: 3px solid var(--rose-light); flex-shrink: 0; box-shadow: var(--shadow);
}
.profile-pic-large i { font-size: 3.5rem; color: var(--rose); }
.profile-details h3 { font-size: 1.2rem; margin: 0 0 2px 0; font-weight: 700; color: var(--text); }
.profile-details .user-email { color: var(--text-light); font-size: 0.9rem; margin: 0; }

/* Stats Row */
.stats-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px; margin-bottom: 20px;
}
.stat-card {
    background: var(--card-bg); border-radius: 10px; padding: var(--dash-stat-pad);
    display: flex; align-items: center; gap: 8px;
    border: 1px solid var(--border); box-shadow: var(--shadow);
    transition: all 0.2s ease; position: relative; overflow: hidden;
    min-height: var(--dash-stat-height);
}
.stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: 10px 10px 0 0;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }

.stat-users::before { background: var(--rose); }
.stat-books::before { background: var(--rose-dark); }
.stat-poems::before { background: var(--rose-light); }
.stat-sessions::before { background: var(--vanilla); }
.stat-posts::before { background: var(--rose); }
.stat-questions::before { background: var(--rose-dark); }
.stat-subscribers::before { background: var(--rose-light); }
.stat-reflections::before { background: var(--fantasy); }
.stat-videos::before { background: var(--vanilla); }
.stat-groups::before { background: var(--rose); }
.stat-hours::before { background: var(--rose-dark); }
.stat-active7::before { background: var(--rose-light); }

.stat-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; flex-shrink: 0;
}
.stat-users .stat-icon { background: rgba(219,161,162,0.15); color: var(--rose); }
.stat-books .stat-icon { background: rgba(192,138,139,0.15); color: var(--rose-dark); }
.stat-poems .stat-icon { background: rgba(232,192,192,0.15); color: var(--rose-light); }
.stat-sessions .stat-icon { background: rgba(239,216,214,0.15); color: var(--vanilla); }
.stat-posts .stat-icon { background: rgba(219,161,162,0.15); color: var(--rose); }
.stat-questions .stat-icon { background: rgba(192,138,139,0.15); color: var(--rose-dark); }
.stat-subscribers .stat-icon { background: rgba(232,192,192,0.15); color: var(--rose-light); }
.stat-reflections .stat-icon { background: rgba(247,243,237,0.15); color: var(--fantasy); }
.stat-videos .stat-icon { background: rgba(239,216,214,0.15); color: var(--vanilla); }
.stat-groups .stat-icon { background: rgba(219,161,162,0.15); color: var(--rose); }
.stat-hours .stat-icon { background: rgba(192,138,139,0.15); color: var(--rose-dark); }
.stat-active7 .stat-icon { background: rgba(232,192,192,0.15); color: var(--rose-light); }

.stat-number { font-size: var(--dash-stat-fsize); font-weight: 700; color: var(--text); line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-label { font-size: 0.55rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600; white-space: normal; word-break: break-word; }

/* Dashboard Grid */
.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }
.main-content { display: flex; flex-direction: column; gap: 32px; }
.alert-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.recent-content-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.dashboard-section { background: var(--card-bg); border-radius: 16px; padding: var(--dash-card-pad); border: 1px solid var(--border); box-shadow: var(--shadow); transition: all var(--transition); }
.dashboard-section:hover { box-shadow: var(--shadow-hover); }
.section-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.section-header h2 { font-size: 1.2rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.section-header h2 .section-icon { color: var(--rose); }

/* Mini Grid */
.mini-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
.book-card, .poem-card, .blog-card, .reflection-card, .video-card { background: var(--bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s ease; }
.book-card:hover, .poem-card:hover, .blog-card:hover, .reflection-card:hover, .video-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.book-cover-wrapper, .poem-thumbnail, .video-thumb { height: 140px; background: var(--vanilla); overflow: hidden; }
.book-cover-wrapper img, .poem-thumbnail img, .video-thumb img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-cover, .poem-thumbnail-placeholder, .video-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); }
.book-info, .poem-body, .blog-content, .reflection-body, .video-info { padding: 10px; }
.book-info h3, .poem-body h3, .blog-content h3, .reflection-body h3, .video-info h3 { font-size: 0.9rem; margin: 0 0 2px; color: var(--text); font-weight: 600; }
.book-author { font-size: 0.75rem; color: var(--text-light); margin: 0; }
.blog-excerpt { font-size: 0.75rem; color: var(--text-light); margin: 0 0 4px; }

/* Lists */
.session-list { display: flex; flex-direction: column; gap: 8px; }
.session-item { background: var(--bg); padding: 12px; border-radius: 10px; border: 1px solid var(--border); transition: all 0.2s ease; }
.session-item:hover { box-shadow: var(--shadow); border-color: var(--rose-light); }
.user-table { display: flex; flex-direction: column; gap: 6px; }
.user-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border); transition: all var(--transition); }
.user-row:hover { border-color: var(--rose); background: rgba(219,161,162,0.04); }
.user-row .user-name { font-size: 0.85rem; flex: 1; }
.user-row .user-name small { color: var(--text-light); }
.user-row .user-role { margin-right: 10px; display: flex; align-items: center; gap: 8px; }

.status-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.status-pending { background: #f1c40f; color: #fff; }
.status-unread { background: var(--rose); color: #fff; }
.status-available { background: #2ecc71; color: #fff; }
.status-missing { background: #e74c3c; color: #fff; }

/* Quick Actions */
.quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; }
.quick-action-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px 8px; background: var(--bg); border-radius: 10px; border: 1px solid var(--border); text-decoration: none; color: var(--text); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); gap: 6px; }
.quick-action-btn:hover { background: var(--vanilla); border-color: var(--rose); transform: translateY(-3px); box-shadow: var(--shadow); }
.quick-action-btn i { font-size: 1.4rem; color: var(--rose); }
.quick-action-btn span { font-size: 0.7rem; text-align: center; line-height: 1.2; font-weight: 500; word-break: break-word; }

/* Achievements */
.achievement-list { display: flex; flex-direction: column; gap: 6px; }
.achievement-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border); transition: all 0.2s ease; }
.achievement-item:hover { box-shadow: var(--shadow); }
.achievement-icon { font-size: 1.2rem; }
.achievement-name { font-weight: 500; font-size: 0.85rem; flex: 1; color: var(--text); }
.achievement-date { font-size: 0.7rem; color: var(--text-light); }
.no-items { text-align: center; color: var(--text-light); font-size: 0.9rem; padding: 8px 0; }

/* Pagination */
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: #fff; border-color: var(--rose); }
.mini-pagination { margin-top: 12px; gap: 4px; }
.mini-pagination .page-link { padding: 4px 10px; font-size: 0.75rem; min-width: 28px; }

/* Sidebar */
.dashboard-sidebar { display: flex; flex-direction: column; gap: 32px; }
.sidebar-card { background: var(--card-bg); border-radius: 16px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: all 0.2s ease; }
.sidebar-card:hover { box-shadow: var(--shadow-hover); }
.sidebar-card .card-header { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.sidebar-card .card-header h4 { font-size: 1rem; margin: 0; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text); }
.sidebar-card .card-header h4 i { color: var(--rose); }
.view-all-link { font-size: 0.8rem; font-weight: 600; color: var(--rose); text-decoration: none; transition: color 0.2s; }
.view-all-link:hover { color: var(--rose-dark); text-decoration: underline; }

/* Empty State */
.empty-state { text-align: center; padding: 24px; color: var(--text-light); }
.empty-state-icon { display: block; font-size: 2.5rem; color: var(--rose); margin-bottom: 12px; opacity: 0.6; }
.empty-state p { margin: 0; font-size: 0.95rem; }
.compact-empty { display: flex; align-items: center; gap: 8px; padding: 16px 0; color: var(--text-light); font-size: 0.9rem; }
.compact-empty i { font-size: 1rem; color: var(--rose-light); opacity: 0.8; }

/* Horizontal Scroll */
.horizontal-scroll { display: flex; gap: 12px; overflow-x: auto; padding: 4px 2px 8px 2px; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
.horizontal-scroll::-webkit-scrollbar { height: 4px; }
.horizontal-scroll::-webkit-scrollbar-track { background: var(--border); border-radius: 2px; }
.horizontal-scroll::-webkit-scrollbar-thumb { background: var(--rose); border-radius: 2px; }
.scroll-item { flex: 0 0 auto; scroll-snap-align: start; background: var(--bg); border-radius: 8px; border: 1px solid var(--border); overflow: hidden; transition: all 0.2s; }
.scroll-item:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.poem-scroll-item { width: 120px; display: flex; flex-direction: column; align-items: center; }
.poem-scroll-item .poem-thumbnail { width: 100px; border-radius: 8px; overflow: hidden; margin-top: 8px; }
.poem-scroll-item .scroll-item-body { padding: 8px; text-align: center; }
.poem-scroll-item .scroll-item-body h4 { font-size: 0.8rem; margin: 0; line-height: 1.2; }
.blog-scroll-item { width: 200px; padding: 10px; }
.blog-scroll-item .blog-content h4 { font-size: 0.85rem; margin: 0 0 4px; }
.blog-scroll-item .blog-excerpt { font-size: 0.75rem; margin: 0 0 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.video-scroll-item { width: 140px; display: flex; flex-direction: column; }
.video-scroll-item .video-thumb { border-radius: 6px; overflow: hidden; margin-top: 8px; }
.video-scroll-item .scroll-item-body { padding: 6px; text-align: center; }
.video-scroll-item .scroll-item-body h4 { font-size: 0.75rem; margin: 0; line-height: 1.2; }

@media (max-width: 480px) {
    :root {
        --dash-hero-pad: 12px 16px;
        --dash-card-pad: 12px;
        --dash-stat-pad: 6px 8px;
        --dash-stat-height: 38px;
        --dash-heading-size: 1.4rem;
        --dash-sub-size: 0.85rem;
        --dash-stat-fsize: 0.9rem;
    }

    /* --- STATS (Keeps 2 short columns - fits perfectly) --- */
    .stats-row { grid-template-columns: 1fr 1fr !important; gap: 4px !important; }
    .stat-icon { width: 24px; height: 24px; font-size: 0.7rem; }
    .stat-label { font-size: 0.45rem; letter-spacing: 0; }
    .hero-stats { gap: 6px; }
    .hero-stat { font-size: 0.7rem; padding: 4px 8px; }

    /* --- SINGLE COLUMN WRAPPER FOR ALL CARDS AFTER STATS --- */
    .recent-content-grid, .bottom-row, .alert-row,
    .quick-actions-grid, .mini-grid, .horizontal-scroll,
    .dashboard-sidebar {
        display: grid !important;
        grid-template-columns: 1fr !important; /* Stacks everything in one column */
        width: 92% !important; /* Matches the stats aesthetic (slightly shorter than screen) */
        max-width: 420px !important; /* Prevents stretching on larger phones */
        margin: 0 auto !important; /* Centers the whole column beautifully */
        gap: 8px !important;
    }

    /* --- CONVERT HORIZONTAL SCROLLS TO VERTICAL STACK --- */
    .horizontal-scroll {
        overflow: visible !important;
        padding: 0 !important;
    }

    /* --- FILL THE COLUMN WITH NEAT, SHORTENED CARDS --- */
    .book-card, .poem-card, .blog-card, .reflection-card, .video-card,
    .scroll-item, .quick-action-btn, .session-item, .user-row, 
    .compact-empty, .achievement-item {
        width: 100% !important;
        box-sizing: border-box !important;
        margin: 0 !important;
        padding: 8px !important;
        border-radius: 8px !important;
    }

    /* --- YOUR EXACT HEIGHTS AND FONTS --- */
    .book-cover-wrapper, .poem-thumbnail, .video-thumb { height: 70px !important; width: 70px !important; flex-shrink: 0 !important; }
    .book-info h3, .poem-body h3, .blog-content h4 { font-size: 0.7rem !important; margin: 0 !important; }
    .book-author, .blog-excerpt { font-size: 0.6rem !important; margin: 0 !important; }
    
    .quick-action-btn { padding: 8px 4px !important; display: flex !important; flex-direction: column !important; align-items: center !important; }
    .quick-action-btn i { font-size: 1rem !important; }
    .quick-action-btn span { font-size: 0.6rem !important; }

    /* --- YOUR EXACT USER ROW WRAPPING --- */
    .user-row {
        flex-wrap: wrap !important;
        padding: 4px 6px !important;
        gap: 2px !important;
    }
    .user-row .user-name {
        flex: 1 1 100% !important;
        font-size: 0.7rem !important;
        word-break: break-word !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }
    .user-row .user-role {
        width: 100% !important;
        margin-right: 0 !important;
        justify-content: flex-end !important;
        gap: 2px !important;
        padding-top: 2px !important;
        border-top: 1px dashed var(--border) !important;
    }
    .user-row .user-role .status-badge { font-size: 0.45rem !important; padding: 1px 6px !important; }
    .user-row .user-role .btn-sm { padding: 2px 6px !important; font-size: 0.5rem !important; }

    /* --- COMPRESS HEADERS & EMPTY STATE --- */
    .section-header { flex-wrap: nowrap !important; gap: 2px !important; }
    .section-header h2 { font-size: 0.75rem !important; gap: 2px !important; flex-shrink: 1 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; min-width: 0 !important; }
    .section-header h2 .section-icon { font-size: 0.7rem !important; }
    .section-header .btn, .section-header .btn-outline, .section-header .btn-sm { padding: 1px 6px !important; font-size: 0.5rem !important; border-radius: 8px !important; white-space: nowrap !important; flex-shrink: 0 !important; min-width: auto !important; }

    .compact-empty { padding: 4px 6px !important; font-size: 0.65rem !important; display: flex !important; flex-wrap: nowrap !important; align-items: center !important; gap: 2px !important; }
    .compact-empty i { font-size: 0.7rem !important; }
    .session-item .session-info, .compact-empty { white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; flex: 1 1 0 !important; min-width: 0 !important; display: block !important; }
    .session-item .status-badge { font-size: 0.45rem !important; padding: 1px 4px !important; flex-shrink: 0 !important; }

    /* --- PAGINATION --- */
    .pagination .page-link { padding: 2px 6px; font-size: 0.6rem; min-width: 20px; }
}
</style>

<?php require_once '../includes/footer.php'; ?>