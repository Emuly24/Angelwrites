<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

redirectIfNotLoggedIn();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// ===== FETCH USER DATA =====
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== ADVANCED STATS =====
// Books finished
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'");
$stmt->execute([$user_id]);
$books_finished = $stmt->fetchColumn();

// Books currently reading
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'currently reading'");
$stmt->execute([$user_id]);
$books_reading = $stmt->fetchColumn();

// Poems read
$stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ?");
$stmt->execute([$user_id]);
$poems_read = $stmt->fetchColumn();

// Videos watched
$stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ?");
$stmt->execute([$user_id]);
$videos_watched = $stmt->fetchColumn();

// Questions asked
$stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE user_id = ?");
$stmt->execute([$user_id]);
$questions_asked = $stmt->fetchColumn();

// Sessions booked
$stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE user_id = ?");
$stmt->execute([$user_id]);
$sessions_booked = $stmt->fetchColumn();

// ===== READING STREAK =====
$stmt = $db->prepare("SELECT current_streak, longest_streak FROM reading_streaks WHERE user_id = ?");
$stmt->execute([$user_id]);
$streak = $stmt->fetch(PDO::FETCH_ASSOC);
$current_streak = $streak['current_streak'] ?? 0;
$longest_streak = $streak['longest_streak'] ?? 0;

// ===== TOTAL READING TIME =====
$stmt = $db->prepare("SELECT SUM(duration_seconds) as total_seconds FROM reading_sessions WHERE user_id = ? AND end_time IS NOT NULL");
$stmt->execute([$user_id]);
$total_seconds = $stmt->fetchColumn() ?? 0;
$total_hours = floor($total_seconds / 3600);
$total_minutes = floor(($total_seconds % 3600) / 60);

// ===== ACHIEVEMENTS =====
$stmt = $db->prepare("SELECT achievement_type, unlocked_at FROM achievements WHERE user_id = ? ORDER BY unlocked_at DESC");
$stmt->execute([$user_id]);
$achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== USER REPUTATION =====
$stmt = $db->prepare("SELECT points, level, badges FROM user_reputations WHERE user_id = ?");
$stmt->execute([$user_id]);
$reputation = $stmt->fetch(PDO::FETCH_ASSOC);
$rep_points = $reputation['points'] ?? 0;
$rep_level = $reputation['level'] ?? 1;
$badges = json_decode($reputation['badges'] ?? '[]', true);

// ===== CURRENTLY READING BOOKS =====
$stmt = $db->prepare("
    SELECT b.*, rs.progress 
    FROM books b
    JOIN reading_status rs ON b.id = rs.book_id
    WHERE rs.user_id = ? AND rs.status = 'currently reading'
    ORDER BY rs.updated_at DESC
");
$stmt->execute([$user_id]);
$reading_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENTLY FINISHED BOOKS =====
$stmt = $db->prepare("
    SELECT b.* FROM books b
    JOIN reading_status rs ON b.id = rs.book_id
    WHERE rs.user_id = ? AND rs.status = 'finished'
    ORDER BY rs.updated_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$finished_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT POEMS =====
$stmt = $db->prepare("
    SELECT p.* FROM poems p
    JOIN poem_reads pr ON p.id = pr.poem_id
    WHERE pr.user_id = ?
    ORDER BY pr.read_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT VIDEOS =====
$stmt = $db->prepare("
    SELECT v.* FROM videos v
    JOIN video_watches vw ON v.id = vw.video_id
    WHERE vw.user_id = ?
    ORDER BY vw.watched_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT BLOG POSTS =====
$stmt = $db->prepare("
    SELECT bp.* FROM blog_posts bp
    JOIN blog_reads br ON bp.id = br.blog_post_id
    WHERE br.user_id = ?
    ORDER BY br.read_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_blog = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT REFLECTIONS =====
$stmt = $db->prepare("
    SELECT r.* FROM reflections r
    JOIN reflection_reads rr ON r.id = rr.reflection_id
    WHERE rr.user_id = ?
    ORDER BY rr.read_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== UPCOMING SESSIONS =====
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE user_id = ? AND date >= date('now')
    ORDER BY date ASC, time ASC
");
$stmt->execute([$user_id]);
$upcoming_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== RECENT QUESTIONS =====
$stmt = $db->prepare("
    SELECT q.*, COUNT(a.id) as answer_count 
    FROM questions q
    LEFT JOIN answers a ON q.id = a.question_id
    WHERE q.user_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== NOTIFICATIONS =====
$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        <!-- ===== HERO / WELCOME SECTION ===== -->
        <div class="dashboard-hero">
            <div class="hero-content">
                <h1>Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</h1>
                <p class="hero-sub">Your personal reading journey — curated just for you.</p>
                <div class="hero-stats">
                    <span class="hero-stat"><i class="fas fa-fire"></i> <?php echo $current_streak; ?> day streak</span>
                    <span class="hero-stat"><i class="fas fa-clock"></i> <?php echo $total_hours; ?>h <?php echo $total_minutes; ?>m read</span>
                    <span class="hero-stat"><i class="fas fa-trophy"></i> Level <?php echo $rep_level; ?></span>
                </div>
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
                    <?php if ($badges): ?>
                        <div class="badge-container">
                            <?php foreach ($badges as $badge): ?>
                                <span class="badge"><?php echo $badge; ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== STATS ROW ===== -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                <div class="stat-number"><?php echo $books_reading; ?></div>
                <div class="stat-label">Reading</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $books_finished; ?></div>
                <div class="stat-label">Finished</div>
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

        <!-- ===== MAIN GRID ===== -->
        <div class="dashboard-grid">
            <div class="main-content">
                <!-- ===== CURRENTLY READING ===== -->
                <section class="dashboard-section" id="currently-reading">
                    <div class="section-header">
                        <h2><i class="fas fa-book-open" style="color: var(--rose);"></i> Currently Reading</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-sm btn-outline">Browse Books</a>
                        </div>
                    </div>
                    <?php if (count($reading_books) > 0): ?>
                        <div class="book-grid">
                            <?php foreach ($reading_books as $book): ?>
                                <div class="book-card">
                                    <div class="book-cover-wrapper">
                                        <?php if ($book['cover_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                        <?php endif; ?>
                                        <span class="progress-badge"><?php echo $book['progress'] ?? 0; ?>%</span>
                                    </div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                        <div class="book-actions">
                                            <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Continue</a>
                                            <form method="POST" action="<?php echo SITE_URL; ?>/library.php" style="display:inline;">
                                                <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                <input type="hidden" name="status" value="finished">
                                                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="btn btn-sm btn-success">✓ Finished</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
                            <p>No books in progress. <a href="<?php echo SITE_URL; ?>/books.php">Start reading</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- ===== RECENTLY FINISHED ===== -->
                <section class="dashboard-section" id="recently-finished">
                    <div class="section-header">
                        <h2><i class="fas fa-check-circle" style="color: var(--rose);"></i> Recently Finished</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-sm btn-outline">More Books</a>
                        </div>
                    </div>
                    <?php if (count($finished_books) > 0): ?>
                        <div class="book-grid">
                            <?php foreach ($finished_books as $book): ?>
                                <div class="book-card finished">
                                    <div class="book-cover-wrapper">
                                        <?php if ($book['cover_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                        <?php endif; ?>
                                        <span class="finished-badge">✅</span>
                                    </div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                        <div class="book-actions">
                                            <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-outline">Re-read</a>
                                            <a href="<?php echo SITE_URL; ?>/book_review.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-secondary">Review</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-check-circle"></i></div>
                            <p>No finished books yet. <a href="<?php echo SITE_URL; ?>/books.php">Start your first book</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- ===== RECENT POEMS ===== -->
                <section class="dashboard-section" id="recent-poems">
                    <div class="section-header">
                        <h2><i class="fas fa-feather-alt" style="color: var(--rose);"></i> Recent Poems</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-sm btn-outline">More Poetry</a>
                        </div>
                    </div>
                    <?php if (count($recent_poems) > 0): ?>
                        <div class="poem-grid">
                            <?php foreach ($recent_poems as $poem): ?>
                                <div class="poem-card">
                                    <div class="poem-thumbnail">
                                        <?php if ($poem['image_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>">
                                        <?php else: ?>
                                            <div class="poem-thumbnail-placeholder"><i class="fas fa-feather-alt"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="poem-body">
                                        <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                                        <?php if ($poem['intro']): ?>
                                            <p class="poem-intro"><?php echo htmlspecialchars(substr($poem['intro'], 0, 60)); ?>...</p>
                                        <?php endif; ?>
                                        <div class="poem-actions">
                                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary">Read</a>
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
                            <p>No poems read yet. <a href="<?php echo SITE_URL; ?>/poetry.php">Start reading</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- ===== RECENT VIDEOS ===== -->
                <section class="dashboard-section" id="recent-videos">
                    <div class="section-header">
                        <h2><i class="fas fa-video" style="color: var(--rose);"></i> Recent Videos</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-sm btn-outline">More Videos</a>
                        </div>
                    </div>
                    <?php if (count($recent_videos) > 0): ?>
                        <div class="video-grid">
                            <?php foreach ($recent_videos as $video): ?>
                                <div class="video-card">
                                    <div class="video-thumb">
                                        <?php if ($video['thumbnail']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-video"></i></div>
                                        <?php endif; ?>
                                        <div class="play-overlay"><i class="fas fa-play-circle"></i></div>
                                    </div>
                                    <div class="video-info">
                                        <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                        <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary">Watch</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-video"></i></div>
                            <p>No videos watched yet. <a href="<?php echo SITE_URL; ?>/videos.php">Start watching</a></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- ===== RECENT BLOG POSTS ===== -->
                <section class="dashboard-section" id="recent-blog">
                    <div class="section-header">
                        <h2><i class="fas fa-blog" style="color: var(--rose);"></i> Recent Blog Posts</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-sm btn-outline">More Blog</a>
                        </div>
                    </div>
                    <?php if (count($recent_blog) > 0): ?>
                        <div class="blog-grid">
                            <?php foreach ($recent_blog as $post): ?>
                                <div class="blog-card">
                                    <div class="blog-thumbnail">
                                        <?php if ($post['featured_image']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                                        <?php else: ?>
                                            <div class="blog-thumbnail-placeholder"><i class="fas fa-blog"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="blog-body">
                                        <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                        <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'] ?? '', 0, 80)); ?>...</p>
                                        <a href="<?php echo SITE_URL; ?>/blog_post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-primary">Read</a>
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

                <!-- ===== RECENT REFLECTIONS ===== -->
                <section class="dashboard-section" id="recent-reflections">
                    <div class="section-header">
                        <h2><i class="fas fa-pray" style="color: var(--rose);"></i> Recent Reflections</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-sm btn-outline">More Reflections</a>
                        </div>
                    </div>
                    <?php if (count($recent_reflections) > 0): ?>
                        <div class="reflection-grid">
                            <?php foreach ($recent_reflections as $reflection): ?>
                                <div class="reflection-card">
                                    <div class="reflection-thumb">
                                        <?php if ($reflection['image_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $reflection['image_path']; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-pray"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reflection-body">
                                        <h3><?php echo htmlspecialchars($reflection['title']); ?></h3>
                                        <a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>" class="btn btn-sm btn-primary">Read</a>
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

                <!-- ===== UPCOMING SESSIONS ===== -->
                <section class="dashboard-section" id="upcoming-sessions">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-check" style="color: var(--rose);"></i> Upcoming Sessions</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-sm btn-primary">Book Session</a>
                        </div>
                    </div>
                    <?php if (count($upcoming_sessions) > 0): ?>
                        <div class="session-list">
                            <?php foreach ($upcoming_sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-info">
                                        <div class="session-date"><?php echo date('M j, Y', strtotime($session['date'])); ?></div>
                                        <div class="session-time"><?php echo date('g:i a', strtotime($session['time'])); ?></div>
                                        <span class="status-badge <?php echo $session['status']; ?>"><?php echo ucfirst($session['status']); ?></span>
                                        <?php if ($session['message']): ?>
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

                <!-- ===== RECENT QUESTIONS ===== -->
                <section class="dashboard-section" id="recent-questions">
                    <div class="section-header">
                        <h2><i class="fas fa-question-circle" style="color: var(--rose);"></i> Recent Questions</h2>
                        <div class="section-actions">
                            <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-sm btn-primary">Ask a Question</a>
                        </div>
                    </div>
                    <?php if (count($recent_questions) > 0): ?>
                        <div class="qa-list">
                            <?php foreach ($recent_questions as $q): ?>
                                <div class="qa-item">
                                    <div class="qa-title">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>"><?php echo htmlspecialchars($q['title']); ?></a>
                                    </div>
                                    <div class="qa-meta">
                                        <span><?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                                        <span><?php echo $q['answer_count'] ?? 0; ?> answers</span>
                                    </div>
                                    <div class="qa-actions">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline">View</a>
                                        <a href="<?php echo SITE_URL; ?>/community_edit.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline">Edit</a>
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
                <!-- ===== NOTIFICATIONS ===== -->
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
                                    <div class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>">
                                        <div class="notif-content">
                                            <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div class="notif-date"><?php echo date('M j, Y', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                        <?php if (!$notif['is_read']): ?>
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

                <!-- ===== ACHIEVEMENTS ===== -->
                <div class="sidebar-card achievements-card">
                    <div class="card-header">
                        <h4><i class="fas fa-trophy" style="color: var(--rose);"></i> Achievements</h4>
                        <a href="<?php echo SITE_URL; ?>/achievements.php" class="view-all-link">View all →</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($achievements) > 0): ?>
                            <div class="achievement-list">
                                <?php foreach ($achievements as $achievement): ?>
                                    <div class="achievement-item">
                                        <span class="achievement-icon">🏆</span>
                                        <span class="achievement-name"><?php echo ucfirst(str_replace('_', ' ', $achievement['achievement_type'])); ?></span>
                                        <span class="achievement-date"><?php echo date('M j, Y', strtotime($achievement['unlocked_at'])); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-items">No achievements yet. Keep reading to unlock them!</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ===== QUICK ACTIONS ===== -->
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
                            <a href="<?php echo SITE_URL; ?>/poetry.php" class="quick-action-btn">
                                <i class="fas fa-feather-alt"></i>
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
                                <i class="fas fa-pray"></i>
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
.dashboard-page { padding: 32px 0 60px; }

/* ===== HERO SECTION ===== */
.dashboard-hero {
    background: linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius: 20px;
    padding: 40px;
    margin-bottom: 32px;
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
    margin: 0 0 16px 0;
    max-width: 500px;
}

.hero-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.hero-stat {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: var(--text-light);
    background: var(--card-bg);
    padding: 4px 14px;
    border-radius: 20px;
    border: 1px solid var(--border);
}

.hero-stat i {
    color: var(--rose);
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

.badge-container {
    display: flex;
    gap: 4px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.badge-container .badge {
    background: var(--rose);
    color: white;
    padding: 0 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
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

/* ===== BOOK CARDS ===== */
.book-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
}

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

.book-card.finished {
    opacity: 0.8;
}

.book-cover-wrapper {
    position: relative;
    height: 180px;
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

.progress-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    background: var(--rose);
    color: white;
}

.finished-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: 1.5rem;
}

.book-info {
    padding: 12px;
}

.book-info h3 {
    font-size: 0.95rem;
    margin: 0 0 4px;
}

.book-author {
    font-size: 0.8rem;
    color: var(--text-light);
    margin: 0 0 8px;
}

.book-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.book-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
    flex: 1;
}

/* ===== POEM CARDS ===== */
.poem-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
}

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
    margin: 0 0 4px;
}

.poem-intro {
    font-size: 0.8rem;
    color: var(--text-light);
    margin: 0 0 8px;
}

.poem-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.poem-actions .btn {
    padding: 4px 12px;
    font-size: 0.75rem;
}

/* ===== VIDEO CARDS ===== */
.video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
}

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

.video-thumb .placeholder-cover {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--rose);
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
.blog-grid, .reflection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
}

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
    margin: 0 0 4px;
}

.blog-excerpt {
    font-size: 0.8rem;
    color: var(--text-light);
    margin: 0 0 8px;
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

.achievement-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.achievement-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    background: var(--bg);
    border-radius: 6px;
    border: 1px solid var(--border);
}

.achievement-icon {
    font-size: 1.2rem;
}

.achievement-name {
    font-weight: 500;
    font-size: 0.85rem;
    flex: 1;
}

.achievement-date {
    font-size: 0.7rem;
    color: var(--text-light);
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

    .hero-stats {
        justify-content: center;
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