<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Redirect non-logged-in users
redirectIfNotLoggedIn();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// === Helper: Safe fetch ===
function safeFetch($db, $sql, $params = [], $limit = 6) {
    try {
        $stmt = $db->prepare($sql . " LIMIT " . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// === Helper: Get image ===
function getImageSrc($row, $column) {
    if (isset($row[$column]) && !empty($row[$column])) {
        return SITE_URL . '/' . $row[$column];
    }
    return null;
}

// === STATS QUERIES ===
$books_finished = safeFetch($db, "SELECT COUNT(*) as count FROM reading_status WHERE user_id = ? AND status = 'finished'", [$user_id])[0]['count'] ?? 0;
$poems_read = safeFetch($db, "SELECT COUNT(*) as count FROM poem_reads WHERE user_id = ?", [$user_id])[0]['count'] ?? 0;
$videos_watched = safeFetch($db, "SELECT COUNT(*) as count FROM video_watches WHERE user_id = ?", [$user_id])[0]['count'] ?? 0;
$questions_asked = safeFetch($db, "SELECT COUNT(*) as count FROM questions WHERE user_id = ?", [$user_id])[0]['count'] ?? 0;
$sessions_booked = safeFetch($db, "SELECT COUNT(*) as count FROM sessions WHERE user_id = ?", [$user_id])[0]['count'] ?? 0;

// === 1. Books you have reading status ===
$my_books = safeFetch($db, "
    SELECT b.*, rs.status, rs.progress 
    FROM books b
    JOIN reading_status rs ON b.id = rs.book_id
    WHERE rs.user_id = ? AND rs.status IN ('currently reading', 'finished')
    ORDER BY rs.updated_at DESC
", [$user_id], 6);

// === 2. Poems you have read ===
$read_poems = safeFetch($db, "
    SELECT p.* FROM poems p
    JOIN poem_reads pr ON p.id = pr.poem_id
    WHERE pr.user_id = ?
    ORDER BY pr.read_at DESC
", [$user_id], 6);

// === 3. Videos you have watched ===
$watched_videos = safeFetch($db, "
    SELECT v.* FROM videos v
    JOIN video_watches vw ON v.id = vw.video_id
    WHERE vw.user_id = ?
    ORDER BY vw.watched_at DESC
", [$user_id], 6);

// === 4. Blog posts you have read ===
$read_blog_posts = safeFetch($db, "
    SELECT bp.* FROM blog_posts bp
    JOIN blog_reads br ON bp.id = br.blog_post_id
    WHERE br.user_id = ?
    ORDER BY br.read_at DESC
", [$user_id], 6);

// === 5. Christian Reflections you have read ===
$read_reflections = safeFetch($db, "
    SELECT r.* FROM reflections r
    JOIN reflection_reads rr ON r.id = rr.reflection_id
    WHERE rr.user_id = ?
    ORDER BY rr.read_at DESC
", [$user_id], 6);

// === 6. Your upcoming sessions ===
$my_sessions = safeFetch($db, "
    SELECT * FROM sessions 
    WHERE user_id = ? AND date >= date('now')
    ORDER BY date ASC, time ASC
", [$user_id], 6);

// === 7. Your community questions ===
$my_questions = safeFetch($db, "
    SELECT q.*, COUNT(a.id) AS answer_count 
    FROM questions q
    LEFT JOIN answers a ON q.id = a.question_id
    WHERE q.user_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
", [$user_id], 6);

// === 8. Recent notifications ===
$notifications = safeFetch($db, "
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 8
", [$user_id], 8);

// ===== HANDLE MARK ALL NOTIFICATIONS AS READ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'My Dashboard';
?>
<?php require_once 'includes/header.php'; ?>

<div class="dashboard-page">
    <div class="container">
        
        <!-- ===== HERO / WELCOME SECTION (Enhanced Layout) ===== -->
        <div class="dashboard-hero">
            <div class="hero-content">
                <h1>Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</h1>
                <p class="hero-sub">Your personal reading and listening journey — curated just for you.</p>
            </div>
            <div class="hero-profile">
                <div class="profile-pic-large">
                    <?php if ($user['profile_pic']): ?>
                        <img src="<?php echo SITE_URL . '/' . $user['profile_pic']; ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                    <?php else: ?>
                        <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </div>
                <div class="profile-details">
                    <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                    <p class="user-email"><?php echo htmlspecialchars($user['email']); ?></p>
                    <?php if ($user['bio']): ?>
                        <p class="user-bio"><?php echo htmlspecialchars($user['bio']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== STATS ROW (Compact & Clean) ===== -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                <div class="stat-number"><?php echo $books_finished; ?></div>
                <div class="stat-label">Books</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-feather-alt"></i></div>
                <div class="stat-number"><?php echo $poems_read; ?></div>
                <div class="stat-label">Poems</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-video"></i></div>
                <div class="stat-number"><?php echo $videos_watched; ?></div>
                <div class="stat-label">Videos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                <div class="stat-number"><?php echo $questions_asked; ?></div>
                <div class="stat-label">Questions</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-number"><?php echo $sessions_booked; ?></div>
                <div class="stat-label">Sessions</div>
            </div>
        </div>

        <!-- ===== DASHBOARD GRID ===== -->
        <div class="dashboard-grid">
            
            <!-- ===== MAIN CONTENT AREA ===== -->
            <div class="main-content">
                
                <!-- Books You're Reading -->
                <section class="dashboard-section" id="my-books">
                    <div class="section-header">
                        <h2><i class="fas fa-book" style="color: var(--rose);"></i> Books You're Reading</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-sm btn-outline">Browse All →</a>
                            <a href="<?php echo SITE_URL; ?>/library.php" class="btn btn-sm btn-secondary">View Library</a>
                        </div>
                    </div>
                    <?php if (count($my_books) > 0): ?>
                        <div class="book-grid">
                            <?php foreach ($my_books as $book): ?>
                                <div class="book-card">
                                    <div class="book-cover-wrapper">
                                        <?php $img = getImageSrc($book, 'cover_path'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                        <?php endif; ?>
                                        <span class="status-badge <?php echo $book['status'] ?? 'want to read'; ?>">
                                            <?php echo ucfirst($book['status'] ?? 'Want to Read'); ?>
                                        </span>
                                    </div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p class="book-desc"><?php echo htmlspecialchars(substr($book['description'] ?? '', 0, 60)); ?>...</p>
                                        <div class="book-actions">
                                            <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Continue</a>
                                            <?php if ($book['status'] === 'currently reading'): ?>
                                                <form method="POST" action="<?php echo SITE_URL; ?>/library.php" style="display:inline;">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                    <input type="hidden" name="status" value="finished">
                                                    <input type="hidden" name="update_status" value="1">
                                                    <button type="submit" class="btn btn-sm btn-secondary" title="Mark as finished">
                                                        <i class="fas fa-check"></i> Finished
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="<?php echo SITE_URL; ?>/library.php" style="display:inline;" onsubmit="return confirm('Remove this book from your library?');">
                                                <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                <input type="hidden" name="remove_status" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline" title="Remove from library">
                                                    <i class="fas fa-times"></i> Remove
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
                            <p>No books in your reading list. <a href="<?php echo SITE_URL; ?>/books.php">Find your next book</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Poems You've Read -->
                <section class="dashboard-section" id="my-poems">
                    <div class="section-header">
                        <h2><i class="fas fa-pen" style="color: var(--rose);"></i> Poems You've Read</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/poems.php" class="btn btn-sm btn-outline">Browse All →</a>
                        </div>
                    </div>
                    <?php if (count($read_poems) > 0): ?>
                        <div class="poem-grid">
                            <?php foreach ($read_poems as $poem): 
                                $intro_parts = explode("\n\n", $poem['intro'] ?? '');
                                $verse = $intro_parts[0] ?? '';
                                $purpose = $intro_parts[1] ?? '';
                            ?>
                            <div class="poem-card">
                                <div class="poem-thumbnail">
                                    <?php if ($poem['image_path']): ?>
                                        <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>">
                                    <?php else: ?>
                                        <div class="poem-thumbnail-placeholder"><i class="fas fa-pen"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="poem-body">
                                    <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                                    <?php if ($verse): ?>
                                    <div class="poem-verse-box">
                                        <span class="verse-label">✧ Verse</span>
                                        <p><?php echo htmlspecialchars(substr($verse, 0, 120)); ?>...</p>
                                    </div>
                                    <?php endif; ?>
                                    <div class="poem-actions">
                                        <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary">Read Again</a>
                                        <?php if ($poem['audio_path']): ?>
                                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>&autoplay=1" class="btn btn-sm btn-outline">Listen</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-feather-alt"></i></div>
                            <p>You haven't read any poems yet. <a href="<?php echo SITE_URL; ?>/poems.php">Start reading</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Videos You've Watched -->
                <section class="dashboard-section" id="my-videos">
                    <div class="section-header">
                        <h2><i class="fas fa-video" style="color: var(--rose);"></i> Videos You've Watched</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-sm btn-outline">Browse All →</a>
                        </div>
                    </div>
                    <?php if (count($watched_videos) > 0): ?>
                        <div class="video-grid">
                            <?php foreach ($watched_videos as $video): ?>
                                <div class="video-card">
                                    <div class="video-thumb">
                                        <?php $img = getImageSrc($video, 'thumbnail'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                                            <div class="play-overlay"><i class="fas fa-play-circle"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-video"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="video-info">
                                        <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                        <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary">Watch Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-film"></i></div>
                            <p>No videos watched yet. <a href="<?php echo SITE_URL; ?>/videos.php">Explore videos</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Blog Posts You've Read -->
                <section class="dashboard-section" id="my-blog-posts">
                    <div class="section-header">
                        <h2><i class="fas fa-pen-fancy" style="color: var(--rose);"></i> Blog Posts You've Read</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-sm btn-outline">Browse All →</a>
                        </div>
                    </div>
                    <?php if (count($read_blog_posts) > 0): ?>
                        <div class="blog-grid">
                            <?php foreach ($read_blog_posts as $post): ?>
                                <div class="blog-card">
                                    <div class="blog-thumbnail">
                                        <?php if ($post['featured_image']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                                        <?php else: ?>
                                            <div class="blog-thumbnail-placeholder"><i class="fas fa-pen-fancy"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="blog-body">
                                        <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                        <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'] ?? '', 0, 80)); ?>...</p>
                                        <a href="<?php echo SITE_URL; ?>/blog_post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline">Read Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-blog"></i></div>
                            <p>No blog posts read yet. <a href="<?php echo SITE_URL; ?>/blog.php">Start reading</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Christian Reflections You've Read -->
                <section class="dashboard-section" id="my-reflections">
                    <div class="section-header">
                        <h2><i class="fas fa-pray" style="color: var(--rose);"></i> Reflections You've Read</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-sm btn-outline">Browse All →</a>
                        </div>
                    </div>
                    <?php if (count($read_reflections) > 0): ?>
                        <div class="reflection-grid">
                            <?php foreach ($read_reflections as $reflection): ?>
                                <div class="reflection-card">
                                    <div class="reflection-thumb">
                                        <?php $img = getImageSrc($reflection, 'image_path'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-pray"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reflection-body">
                                        <h3><?php echo htmlspecialchars($reflection['title']); ?></h3>
                                        <a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>" class="btn btn-sm btn-primary">Read Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-pray"></i></div>
                            <p>No reflections read yet. <a href="<?php echo SITE_URL; ?>/reflections.php">Start reading</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Your Upcoming Sessions -->
                <section class="dashboard-section" id="my-sessions">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-check" style="color: var(--rose);"></i> Your Upcoming Sessions</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-sm btn-primary">Book Session</a>
                        </div>
                    </div>
                    <?php if (count($my_sessions) > 0): ?>
                        <div class="session-list">
                            <?php foreach ($my_sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-info">
                                        <div class="session-date"><?php echo htmlspecialchars($session['date']); ?></div>
                                        <div class="session-time"><?php echo htmlspecialchars($session['time']); ?></div>
                                        <span class="status-badge <?php echo $session['status'] ?? 'pending'; ?>"><?php echo ucfirst($session['status'] ?? 'Pending'); ?></span>
                                        <?php if (isset($session['message'])): ?>
                                            <p class="session-message"><?php echo htmlspecialchars($session['message']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="session-actions">
                                        <a href="<?php echo SITE_URL; ?>/session_edit.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-outline">Edit</a>
                                        <a href="<?php echo SITE_URL; ?>/session_cancel.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this session?');">Cancel</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-calendar-plus"></i></div>
                            <p>No upcoming sessions. <a href="<?php echo SITE_URL; ?>/book_session.php">Book a session</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Your Community Questions -->
                <section class="dashboard-section" id="my-questions">
                    <div class="section-header">
                        <h2><i class="fas fa-question-circle" style="color: var(--rose);"></i> Your Questions</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-sm btn-primary">Ask a Question</a>
                        </div>
                    </div>
                    <?php if (count($my_questions) > 0): ?>
                        <div class="qa-list">
                            <?php foreach ($my_questions as $q): ?>
                                <div class="qa-item">
                                    <div class="qa-title">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>">
                                            <?php echo htmlspecialchars($q['title']); ?>
                                        </a>
                                    </div>
                                    <div class="qa-meta">
                                        <span><?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                                        <span><?php echo $q['answer_count'] ?? 0; ?> answers</span>
                                    </div>
                                    <div class="qa-actions">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline">View</a>
                                        <a href="<?php echo SITE_URL; ?>/community_edit.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline">Edit</a>
                                        <a href="<?php echo SITE_URL; ?>/community_delete.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this question?');">Delete</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-comments"></i></div>
                            <p>No questions asked yet. <a href="<?php echo SITE_URL; ?>/community.php">Ask a question</a></p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ===== SIDEBAR ===== -->
            <div class="dashboard-sidebar">
                
                <!-- Notifications -->
                <div class="sidebar-card notifications-card">
                    <div class="card-header">
                        <h4><i class="fas fa-bell" style="color: var(--rose);"></i> Notifications</h4>
                        <div class="card-header-actions">
                            <?php if (count($notifications) > 0): ?>
                                <form method="POST" style="display:inline;">
                                    <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline">Mark all read</button>
                                </form>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/notifications.php" class="view-all-link">View all →</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($notifications) > 0): ?>
                            <div class="notification-list">
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?php echo isset($notif['is_read']) && $notif['is_read'] ? 'read' : 'unread'; ?>">
                                        <div class="notif-content">
                                            <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div class="notif-date"><?php echo date('M j, Y', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                        <?php if (isset($notif['is_read']) && !$notif['is_read']): ?>
                                            <a href="<?php echo SITE_URL; ?>/notification_read.php?id=<?php echo $notif['id']; ?>" class="btn btn-sm btn-outline">Mark read</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-items">No notifications yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="sidebar-card quick-actions-card">
                    <div class="card-header">
                        <h4><i class="fas fa-bolt" style="color: var(--rose);"></i> Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="<?php echo SITE_URL; ?>/books.php" class="quick-action-btn">
                                <i class="fas fa-book"></i>
                                <span>Browse Books</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/poem_view.php" class="quick-action-btn">
                                <i class="fas fa-pen"></i>
                                <span>Read Poems</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/videos.php" class="quick-action-btn">
                                <i class="fas fa-video"></i>
                                <span>Watch Videos</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/blog.php" class="quick-action-btn">
                                <i class="fas fa-blog"></i>
                                <span>Read Blog</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/reflections.php" class="quick-action-btn">
                                <i class="fas fa-church"></i>
                                <span>Reflections</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/community.php" class="quick-action-btn">
                                <i class="fas fa-question-circle"></i>
                                <span>Community Q&A</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/book_session.php" class="quick-action-btn">
                                <i class="fas fa-calendar-check"></i>
                                <span>Book Session</span>
                            </a>
                            <a href="<?php echo SITE_URL; ?>/profile.php" class="quick-action-btn">
                                <i class="fas fa-user-cog"></i>
                                <span>My Profile</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== DASHBOARD PAGE ===== */
.dashboard-page { padding: 40px 0 60px; }

/* ===== HERO SECTION ===== */
.dashboard-hero {
    background: linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius: 20px;
    padding: 40px;
    margin-bottom: 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 24px;
    border: 1px solid var(--rose-light);
}

.hero-content {
    flex: 1;
    min-width: 250px;
}

.hero-content h1 {
    font-size: 2.2rem;
    margin: 0 0 8px 0;
    color: var(--text);
    line-height: 1.2;
}

.hero-content .hero-sub {
    color: var(--text-light);
    font-size: 1.1rem;
    margin: 0;
    max-width: 500px;
}

.hero-profile {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

.profile-pic-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--vanilla);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid var(--rose-light);
    flex-shrink: 0;
}

.profile-pic-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-pic-large i {
    font-size: 3.5rem;
    color: var(--rose);
}

.profile-details h3 {
    font-size: 1.2rem;
    margin: 0 0 2px 0;
    font-weight: 700;
}

.profile-details .user-email {
    color: var(--text-light);
    font-size: 0.9rem;
    margin: 0;
}

.profile-details .user-bio {
    color: var(--text);
    font-size: 0.9rem;
    line-height: 1.4;
    margin: 4px 0 0 0;
}

/* ===== STATS ROW ===== */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform 0.2s, box-shadow 0.2s;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.stat-icon {
    font-size: 2rem;
    color: var(--rose);
    margin-bottom: 4px;
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
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ===== GRID LAYOUT ===== */
.dashboard-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 32px;
}

.main-content {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

/* ===== SECTIONS ===== */
.dashboard-section {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.section-header h2 {
    font-size: 1.3rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* ===== CARDS GRIDS ===== */
.book-grid, .poem-grid, .video-grid, .blog-grid, .reflection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
}

/* ===== BOOK CARD ===== */
.book-card {
    background: var(--bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
    transition: transform 0.2s, box-shadow 0.2s;
}

.book-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.book-cover-wrapper {
    position: relative;
    height: 160px;
    background: var(--vanilla);
    overflow: hidden;
}

.book-cover-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-cover-wrapper .placeholder-cover {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--rose);
}

.book-cover-wrapper .status-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    color: white;
}

.status-badge.currently\ reading { background: var(--rose); }
.status-badge.finished { background: #27ae60; }
.status-badge.want\ to\ read { background: #3498db; }

.book-info {
    padding: 12px;
}

.book-info h3 {
    font-size: 0.95rem;
    margin: 0 0 4px;
}

.book-desc {
    font-size: 0.8rem;
    color: var(--text-light);
    margin-bottom: 8px;
}

.book-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.book-actions .btn {
    padding: 4px 8px;
    font-size: 0.75rem;
    flex: 1;
    min-width: 50px;
}

/* ===== POEM CARD ===== */
.poem-card {
    background: var(--bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
}

.poem-thumbnail {
    height: 120px;
    background: var(--vanilla);
    overflow: hidden;
}

.poem-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.poem-thumbnail-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--rose);
}

.poem-body {
    padding: 12px;
}

.poem-body h3 {
    font-size: 0.95rem;
    margin: 0 0 6px;
}

.poem-verse-box {
    background: var(--vanilla);
    padding: 6px 10px;
    border-radius: 6px;
    border-left: 3px solid var(--rose);
    margin-bottom: 8px;
}

.verse-label {
    display: block;
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--rose);
}

.poem-verse-box p {
    font-size: 0.8rem;
    color: var(--text-light);
    margin: 2px 0 0;
}

.poem-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.poem-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
    flex: 1;
}

/* ===== VIDEO CARD ===== */
.video-card {
    background: var(--bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
}

.video-thumb {
    position: relative;
    height: 120px;
    background: var(--vanilla);
    overflow: hidden;
}

.video-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.play-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.3);
    color: white;
    font-size: 2.5rem;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.video-thumb:hover .play-overlay {
    opacity: 1;
}

.video-thumb .placeholder-cover {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--rose);
}

.video-info {
    padding: 12px;
}

.video-info h3 {
    font-size: 0.95rem;
    margin: 0 0 8px;
}

.video-info .btn {
    width: 100%;
    padding: 4px;
    font-size: 0.75rem;
}

/* ===== BLOG & REFLECTION CARDS ===== */
.blog-card, .reflection-card {
    background: var(--bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border);
}

.blog-thumbnail, .reflection-thumb {
    height: 120px;
    background: var(--vanilla);
    overflow: hidden;
}

.blog-thumbnail img, .reflection-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.blog-thumbnail-placeholder, .reflection-thumb .placeholder-cover {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--rose);
}

.blog-body, .reflection-body {
    padding: 12px;
}

.blog-body h3, .reflection-body h3 {
    font-size: 0.95rem;
    margin: 0 0 6px;
}

.blog-excerpt {
    font-size: 0.8rem;
    color: var(--text-light);
    margin-bottom: 8px;
}

.blog-body .btn, .reflection-body .btn {
    width: 100%;
    padding: 4px;
    font-size: 0.75rem;
}

/* ===== SESSION & QA LISTS ===== */
.session-list, .qa-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.session-item, .qa-item {
    background: var(--bg);
    padding: 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
}

.session-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.session-date, .session-time {
    font-weight: 500;
    font-size: 0.9rem;
}

.session-message {
    font-size: 0.8rem;
    color: var(--text-light);
    margin: 4px 0 0;
    width: 100%;
}

.session-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.session-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
}

.qa-title {
    font-weight: 500;
}

.qa-title a {
    color: var(--text);
    text-decoration: none;
}

.qa-title a:hover {
    color: var(--rose);
}

.qa-meta {
    font-size: 0.8rem;
    color: var(--text-light);
    display: flex;
    gap: 8px;
}

.qa-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.qa-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
}

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 20px;
    color: var(--text-light);
}

.empty-state-icon {
    display: block;
    font-size: 2.5rem;
    color: var(--rose);
    margin-bottom: 12px;
}

.empty-state p {
    margin: 0;
}

.empty-state a {
    color: var(--rose);
    font-weight: 600;
    text-decoration: none;
}

.empty-state a:hover {
    text-decoration: underline;
}

/* ===== SIDEBAR ===== */
.dashboard-sidebar {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.sidebar-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 20px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.sidebar-card .card-header {
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.sidebar-card .card-header h4 {
    font-size: 1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sidebar-card .card-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.sidebar-card .card-header-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
}

.sidebar-card .card-header-actions .view-all-link {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--rose-dark);
    text-decoration: none;
    transition: color var(--transition);
}

.sidebar-card .card-header-actions .view-all-link:hover {
    color: var(--rose);
    text-decoration: underline;
}

.notification-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.notification-item {
    background: var(--bg);
    padding: 10px;
    border-radius: 8px;
    border-left: 3px solid transparent;
}

.notification-item.unread {
    border-left-color: var(--rose);
}

.notif-title {
    font-weight: 600;
    font-size: 0.9rem;
}

.notif-message {
    font-size: 0.8rem;
    color: var(--text-light);
}

.notif-date {
    font-size: 0.7rem;
    color: var(--text-light);
    margin-top: 2px;
}

.no-items {
    text-align: center;
    color: var(--text-light);
    font-size: 0.9rem;
}

/* ===== QUICK ACTIONS ===== */
.quick-actions-card .card-body {
    padding: 0;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 8px;
    padding: 12px;
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 12px;
    background: var(--bg);
    border-radius: 8px;
    border: 1px solid var(--border);
    text-decoration: none;
    color: var(--text);
    transition: all 0.2s;
}

.quick-action-btn:hover {
    background: var(--vanilla);
    border-color: var(--rose);
    transform: translateY(-2px);
}

.quick-action-btn i {
    font-size: 1.3rem;
    color: var(--rose);
    margin-bottom: 4px;
}

.quick-action-btn span {
    font-size: 0.75rem;
    text-align: center;
    line-height: 1.2;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-hero {
        flex-direction: column;
        text-align: center;
        align-items: center;
    }

    .hero-profile {
        flex-direction: column;
        text-align: center;
        align-items: center;
    }

    .hero-content h1 {
        font-size: 1.8rem;
    }

    .hero-content .hero-sub {
        font-size: 1rem;
    }

    .stats-row {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    }

    .stat-number {
        font-size: 1.4rem;
    }

    .book-grid, .poem-grid, .video-grid, .blog-grid, .reflection-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    }

    .quick-actions-grid {
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    }

    .book-cover-wrapper {
        height: 140px;
    }

    .poem-thumbnail, .video-thumb, .blog-thumbnail, .reflection-thumb {
        height: 100px;
    }
}

@media (max-width: 480px) {
    .dashboard-section {
        padding: 16px;
    }

    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .section-actions {
        width: 100%;
    }

    .section-actions .btn {
        flex: 1;
        text-align: center;
    }

    .stats-row {
        grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
        gap: 8px;
    }

    .stat-card {
        padding: 10px;
    }

    .stat-icon {
        font-size: 1.2rem;
    }

    .stat-number {
        font-size: 1.2rem;
    }

    .stat-label {
        font-size: 0.6rem;
    }

    .book-grid, .poem-grid, .video-grid, .blog-grid, .reflection-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>