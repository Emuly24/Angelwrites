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
                <h1>Welcome back, <span class="rose-text"><?php echo htmlspecialchars($user['name']); ?></span>!</h1>
                <p class="hero-sub">Your personal reading journey — curated just for you.</p>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <i class="fas fa-fire"></i>
                        <strong><?php echo $current_streak; ?> day streak</strong>
                    </div>
                    <div class="hero-stat">
                        <i class="fas fa-clock"></i>
                        <strong><?php echo $total_hours; ?>h <?php echo $total_minutes; ?>m</strong> read
                    </div>
                    <div class="hero-stat">
                        <i class="fas fa-trophy"></i>
                        <strong>Level <?php echo $rep_level; ?></strong>
                        <span class="hero-stat-points">(<?php echo $rep_points; ?> pts)</span>
                    </div>
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
            <div class="stat-card stat-reading">
                <div class="stat-icon"><i class="fas fa-book"></i></div>
                <div class="stat-number"><?php echo $books_reading; ?></div>
                <div class="stat-label">Reading</div>
            </div>
            <div class="stat-card stat-finished">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $books_finished; ?></div>
                <div class="stat-label">Finished</div>
            </div>
            <div class="stat-card stat-poems">
                <div class="stat-icon"><i class="fas fa-feather-alt"></i></div>
                <div class="stat-number"><?php echo $poems_read; ?></div>
                <div class="stat-label">Poems Read</div>
            </div>
            <div class="stat-card stat-videos">
                <div class="stat-icon"><i class="fas fa-video"></i></div>
                <div class="stat-number"><?php echo $videos_watched; ?></div>
                <div class="stat-label">Videos Watched</div>
            </div>
            <div class="stat-card stat-questions">
                <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                <div class="stat-number"><?php echo $questions_asked; ?></div>
                <div class="stat-label">Questions</div>
            </div>
            <div class="stat-card stat-sessions">
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
                        <h2><i class="fas fa-book-open section-icon"></i> Currently Reading</h2>
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
                                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Continue</a>
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
                        <h2><i class="fas fa-check-circle section-icon"></i> Recently Finished</h2>
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
                                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-outline">Re-read</a>
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
                        <h2><i class="fas fa-feather-alt section-icon"></i> Recent Poems</h2>
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
                        <h2><i class="fas fa-video section-icon"></i> Recent Videos</h2>
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
                                            <div class="video-thumb-placeholder"><i class="fas fa-video"></i></div>
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
                        <h2><i class="fas fa-blog section-icon"></i> Recent Blog Posts</h2>
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
                        <h2><i class="fas fa-pray section-icon"></i> Recent Reflections</h2>
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
                                            <div class="reflection-thumb-placeholder"><i class="fas fa-pray"></i></div>
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
                        <h2><i class="fas fa-calendar-check section-icon"></i> Upcoming Sessions</h2>
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
                        <h2><i class="fas fa-question-circle section-icon"></i> Recent Questions</h2>
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
/* ===== ROOT VARIABLES (identical to index) ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }

.rose-text { color:var(--rose); }

/* ===== BUTTONS (exact match from index) ===== */
.btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
    border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
    cursor:pointer; text-decoration:none; transition:all var(--transition);
    box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px;
}
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-white { background:var(--white); color:var(--dark); border:2px solid var(--white); }
.btn-white:hover { background:var(--vanilla); border-color:var(--vanilla); }
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-danger { background:#e74c3c; color:white; border:2px solid #e74c3c; }
.btn-danger:hover { background:#c0392b; border-color:#c0392b; }
.btn-success { background:#28a745; color:white; border:2px solid #28a745; }
.btn-success:hover { background:#218838; border-color:#218838; }

/* ===== DASHBOARD PAGE ===== */
.dashboard-page { padding:32px 0 60px; font-family:'Inter',sans-serif; }

/* ===== HERO ===== */
.dashboard-hero {
    background: linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius:20px; padding:24px 32px; margin-bottom:24px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;
    border:1px solid var(--rose-light); box-shadow:var(--shadow); position:relative; overflow:hidden;
}
.dashboard-hero::before {
    content:''; position:absolute; top:-50%; right:-20%; width:300px; height:300px;
    background:rgba(219,161,162,0.08); border-radius:50%; pointer-events:none;
}
.hero-content { flex:1; min-width:250px; position:relative; z-index:1; }
.hero-content h1 { font-size:2.4rem; margin:0 0 4px 0; color:var(--text); line-height:1.1; font-weight:700; }
.hero-content .hero-sub { color:var(--text-light); font-size:1.05rem; margin:0 0 12px 0; max-width:500px; }
.hero-stats { display:flex; gap:12px; flex-wrap:wrap; }
.hero-stat {
    display:flex; align-items:center; gap:6px; font-size:0.85rem; color:var(--text-light);
    background:var(--card-bg); padding:6px 14px; border-radius:20px; border:1px solid var(--border);
    box-shadow:var(--shadow); transition:all 0.2s ease;
}
.hero-stat:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.hero-stat i { color:var(--rose); }
.hero-stat strong { color:var(--text); font-weight:600; }

.hero-profile { display:flex; align-items:center; gap:16px; flex-shrink:0; position:relative; z-index:1; }
.profile-pic-large { width:80px; height:80px; border-radius:50%; overflow:hidden; background:var(--vanilla); display:flex; align-items:center; justify-content:center; border:3px solid var(--rose-light); flex-shrink:0; box-shadow:var(--shadow); }
.profile-pic-large i { font-size:3.5rem; color:var(--rose); }
.profile-details h3 { font-size:1.2rem; margin:0 0 2px 0; font-weight:700; color:var(--text); }
.profile-details .user-email { color:var(--text-light); font-size:0.9rem; margin:0; }

.badge-container { display:flex; gap:4px; margin-top:4px; flex-wrap:wrap; }
.badge-container .badge { background:var(--rose); color:white; padding:0 10px; border-radius:12px; font-size:0.7rem; font-weight:600; }

/* ===== STATS ROW ===== */
.stats-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:20px; }
.stat-card {
    background:var(--card-bg); border-radius:10px; padding:10px 12px; display:flex; align-items:center; gap:8px;
    border:1px solid var(--border); box-shadow:var(--shadow); transition:all 0.2s ease;
    position:relative; overflow:hidden; min-height:55px;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; }
.stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }

/* ===== STAT CARD COLORS (brand consistent) ===== */
.stat-reading::before { background:var(--rose); }
.stat-reading .stat-icon { background:rgba(219,161,162,0.15); color:var(--rose); }

.stat-finished::before { background:var(--rose-dark); }
.stat-finished .stat-icon { background:rgba(192,138,139,0.15); color:var(--rose-dark); }

.stat-poems::before { background:var(--rose-light); }
.stat-poems .stat-icon { background:rgba(232,192,192,0.15); color:var(--rose-light); }

.stat-videos::before { background:var(--vanilla); }
.stat-videos .stat-icon { background:rgba(239,216,214,0.15); color:var(--vanilla); }

.stat-questions::before { background:var(--rose); }
.stat-questions .stat-icon { background:rgba(219,161,162,0.15); color:var(--rose); }

.stat-sessions::before { background:var(--rose-dark); }
.stat-sessions .stat-icon { background:rgba(192,138,139,0.15); color:var(--rose-dark); }

.stat-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0; }
.stat-number { font-size:1.1rem; font-weight:700; color:var(--text); line-height:1.1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stat-label { font-size:0.5rem; color:var(--text-light); text-transform:uppercase; letter-spacing:0.3px; font-weight:600; line-height:1.1; white-space:normal; word-break:break-word; max-width:100%; }

/* ===== GRID LAYOUT ===== */
.dashboard-grid { display:grid; grid-template-columns:2fr 1fr; gap:32px; }
.main-content { display:flex; flex-direction:column; gap:32px; }

/* ===== SECTIONS ===== */
.dashboard-section {
    background:var(--card-bg); border-radius:16px; padding:24px;
    border:1px solid var(--border); box-shadow:var(--shadow); transition:all 0.2s ease;
}
.dashboard-section:hover { box-shadow:var(--shadow-hover); }

.section-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
.section-header h2 { font-size:1.2rem; margin:0; display:flex; align-items:center; gap:8px; font-weight:700; color:var(--text); }
.section-header h2 .section-icon { color:var(--rose); }
.section-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* ===== MINI GRIDS ===== */
.mini-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; }

/* ===== BOOK CARDS ===== */
.book-card { background:var(--bg); border-radius:12px; overflow:hidden; border:1px solid var(--border); transition:all 0.3s cubic-bezier(0.4,0,0.2,1); }
.book-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); }
.book-cover-wrapper { position:relative; height:140px; background:var(--vanilla); overflow:hidden; }
.book-cover-wrapper img { width:100%; height:100%; object-fit:cover; }
.placeholder-cover { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.5rem; color:var(--rose); }
.book-info { padding:10px; }
.book-info h3 { font-size:0.9rem; margin:0 0 2px; color:var(--text); font-weight:600; }
.book-author { font-size:0.75rem; color:var(--text-light); margin:0; }

/* ===== POEM CARDS ===== */
.poem-card { background:var(--bg); border-radius:12px; overflow:hidden; border:1px solid var(--border); transition:all 0.2s ease; }
.poem-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.poem-thumbnail { height:100px; background:var(--vanilla); overflow:hidden; }
.poem-thumbnail img { width:100%; height:100%; object-fit:cover; }
.poem-thumbnail-placeholder { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.5rem; color:var(--rose); }
.poem-body { padding:10px; }
.poem-body h3 { font-size:0.9rem; margin:0 0 2px; color:var(--text); font-weight:600; }

/* ===== BLOG CARDS ===== */
.blog-card { background:var(--bg); border-radius:12px; overflow:hidden; border:1px solid var(--border); transition:all 0.2s ease; }
.blog-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.blog-content { padding:10px; }
.blog-content h3 { font-size:0.9rem; margin:0 0 2px; color:var(--text); font-weight:600; }
.blog-excerpt { font-size:0.75rem; color:var(--text-light); margin:0 0 4px; }

/* ===== REFLECTION CARDS ===== */
.reflection-card { background:var(--bg); border-radius:12px; overflow:hidden; border:1px solid var(--border); transition:all 0.2s ease; }
.reflection-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.reflection-body { padding:10px; }
.reflection-body h3 { font-size:0.9rem; margin:0 0 2px; color:var(--text); font-weight:600; }

/* ===== VIDEO CARDS ===== */
.video-card { background:var(--bg); border-radius:12px; overflow:hidden; border:1px solid var(--border); transition:all 0.2s ease; }
.video-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.video-thumb { height:100px; background:var(--vanilla); overflow:hidden; }
.video-thumb img { width:100%; height:100%; object-fit:cover; }
.video-thumb-placeholder { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.5rem; color:var(--rose); }
.video-info { padding:10px; }
.video-info h3 { font-size:0.9rem; margin:0 0 2px; color:var(--text); font-weight:600; }

/* ===== SESSION & QA LISTS ===== */
.session-list, .qa-list { display:flex; flex-direction:column; gap:8px; }
.session-item, .qa-item { background:var(--bg); padding:12px; border-radius:10px; border:1px solid var(--border); transition:all 0.2s ease; }
.session-item:hover, .qa-item:hover { box-shadow:var(--shadow); border-color:var(--rose-light); }
.session-info { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.session-date, .session-time { font-weight:500; font-size:0.9rem; color:var(--text); }

/* ===== STATUS BADGES ===== */
.status-badge { padding:2px 12px; border-radius:12px; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; white-space:nowrap; }
.status-pending { background:#f1c40f; color:white; }
.status-unread { background:var(--rose); color:white; }
.status-available { background:#2ecc71; color:white; }
.status-missing { background:#e74c3c; color:white; }

/* ===== EMPTY STATE ===== */
.empty-state { text-align:center; padding:24px; color:var(--text-light); }
.empty-state-icon { display:block; font-size:2.5rem; color:var(--rose); margin-bottom:12px; opacity:0.6; }
.empty-state p { margin:0; font-size:0.95rem; }
.empty-state a { color:var(--rose); font-weight:600; text-decoration:none; }
.empty-state a:hover { text-decoration:underline; }

/* ===== SIDEBAR ===== */
.dashboard-sidebar { display:flex; flex-direction:column; gap:32px; }
.sidebar-card { background:var(--card-bg); border-radius:16px; padding:20px; border:1px solid var(--border); box-shadow:var(--shadow); transition:all 0.2s ease; }
.sidebar-card:hover { box-shadow:var(--shadow-hover); }
.sidebar-card .card-header { margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.sidebar-card .card-header h4 { font-size:1rem; margin:0; display:flex; align-items:center; gap:8px; font-weight:700; color:var(--text); }
.sidebar-card .card-header h4 i { color:var(--rose); }
.sidebar-card .card-header-actions { display:flex; gap:8px; align-items:center; }
.view-all-link { font-size:0.8rem; font-weight:600; color:var(--rose); text-decoration:none; transition:color 0.2s; }
.view-all-link:hover { color:var(--rose-dark); text-decoration:underline; }

/* ===== QUICK ACTIONS ===== */
.quick-actions-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:8px; }
.quick-action-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:12px 8px; background:var(--bg); border-radius:10px; border:1px solid var(--border); text-decoration:none; color:var(--text); transition:all 0.3s cubic-bezier(0.4,0,0.2,1); gap:6px; }
.quick-action-btn:hover { background:var(--vanilla); border-color:var(--rose); transform:translateY(-3px); box-shadow:var(--shadow); }
.quick-action-btn i { font-size:1.4rem; color:var(--rose); }
.quick-action-btn span { font-size:0.7rem; text-align:center; line-height:1.2; font-weight:500; word-break:break-word; white-space:normal; max-width:100%; }

/* ===== NOTIFICATIONS ===== */
.notification-list { display:flex; flex-direction:column; gap:8px; }
.notification-item { background:var(--bg); padding:12px; border-radius:10px; border-left:3px solid transparent; transition:all 0.2s ease; }
.notification-item:hover { box-shadow:var(--shadow); }
.notification-item.unread { border-left-color:var(--rose); }
.notif-content { flex:1; }
.notif-title { font-weight:600; font-size:0.9rem; color:var(--text); }
.notif-message { font-size:0.85rem; color:var(--text-light); margin:2px 0; }
.notif-date { font-size:0.75rem; color:var(--text-light); }

/* ===== ACHIEVEMENTS ===== */
.achievement-list { display:flex; flex-direction:column; gap:6px; }
.achievement-item { display:flex; align-items:center; gap:10px; padding:8px 12px; background:var(--bg); border-radius:8px; border:1px solid var(--border); transition:all 0.2s ease; }
.achievement-item:hover { box-shadow:var(--shadow); }
.achievement-icon { font-size:1.2rem; }
.achievement-name { font-weight:500; font-size:0.85rem; flex:1; color:var(--text); }
.achievement-date { font-size:0.7rem; color:var(--text-light); }
.no-items { text-align:center; color:var(--text-light); font-size:0.9rem; padding:8px 0; }

/* ===== RESPONSIVE ===== */
@media (max-width:1024px) { .dashboard-grid { grid-template-columns:1fr; } }
@media (max-width:768px) {
    .dashboard-hero { flex-direction:column; text-align:center; align-items:center; padding:20px; }
    .hero-profile { flex-direction:column; text-align:center; align-items:center; }
    .hero-content h1 { font-size:1.8rem; }
    .hero-content .hero-sub { font-size:1rem; }
    .hero-stats { justify-content:center; }
    .dashboard-section { padding:16px; }
    .mini-grid { grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); }
}
@media (max-width:480px) {
    .stats-row { grid-template-columns:1fr 1fr; gap:8px; }
    .mini-grid { grid-template-columns:1fr 1fr; gap:8px; }
    .book-cover-wrapper { height:100px; }
    .poem-thumbnail, .video-thumb { height:80px; }
    .section-header { flex-direction:column; align-items:flex-start; gap:8px; }
    .section-actions { width:100%; }
    .quick-actions-grid { grid-template-columns:1fr 1fr; }
}
</style>

<?php require_once 'includes/footer.php'; ?>