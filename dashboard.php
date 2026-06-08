<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

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

// === 1. Books you have reading status (currently reading / finished) ===
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

$pageTitle = 'My Activity';
?>
<?php require_once 'includes/header.php'; ?>

<div class="user-dashboard">
    <div class="container">
        <div class="dashboard-header">
            <h1>Welcome Back, <?php echo htmlspecialchars($user['name']); ?>!</h1>
            <p>Your personal reading and listening journey — curated just for you.</p>
        </div>

        <div class="dashboard-grid">
            <!-- MAIN ACTIVITY FEED -->
            <div class="dashboard-main">
                
                <!-- Books You're Reading -->
                <section class="dashboard-section" id="my-books">
                    <div class="section-header">
                        <h2><i class="fas fa-book" style="color: var(--rose);"></i> Books You're Reading</h2>
                        <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-sm btn-outline">Browse All →</a>
                    </div>
                    <?php if (count($my_books) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($my_books as $book): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php $img = getImageSrc($book, 'cover_path'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <span class="status-badge <?php echo $book['status'] ?? 'want to read'; ?>">
                                            <?php echo ucfirst($book['status'] ?? 'Want to Read'); ?>
                                        </span>
                                        <p class="content-description">
                                            <?php echo htmlspecialchars(substr($book['description'] ?? '', 0, 80)); ?>...
                                        </p>
                                        <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Continue Reading</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No books in your reading list. <a href="<?php echo SITE_URL; ?>/books.php">Find your next book</a></p>
                    <?php endif; ?>
                </section>

                <!-- Poems You've Read -->
                <section class="dashboard-section" id="my-poems">
                    <div class="section-header">
                        <h2><i class="fas fa-pen" style="color: var(--rose);"></i> Poems You've Read</h2>
                        <a href="<?php echo SITE_URL; ?>/poems.php" class="btn btn-sm btn-outline">Browse All →</a>
                    </div>
                    <?php if (count($read_poems) > 0): ?>
                        <div class="poem-grid">
                            <?php foreach ($read_poems as $poem): 
                                $intro_parts = explode("\n\n", $poem['intro'] ?? '');
                                $verse = $intro_parts[0] ?? '';
                                $purpose = $intro_parts[1] ?? '';
                            ?>
                            <div class="poem-card">
                                <?php if ($poem['image_path']): ?>
                                    <div class="poem-thumbnail">
                                        <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="poem-thumbnail-placeholder">
                                        <i class="fas fa-pen"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="poem-content">
                                    <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                                    <?php if ($verse): ?>
                                    <div class="poem-intro-preview">
                                        <span class="intro-label">✧ Verse</span>
                                        <p><?php echo htmlspecialchars(substr($verse, 0, 150)); ?><?php if (strlen($verse) > 150) echo '...'; ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($purpose): ?>
                                    <p class="poem-excerpt"><?php echo htmlspecialchars(substr($purpose, 0, 120)); ?><?php if (strlen($purpose) > 120) echo '...'; ?></p>
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
                        <p class="no-items">You haven't read any poems yet. <a href="<?php echo SITE_URL; ?>/poems.php">Start reading</a></p>
                    <?php endif; ?>
                </section>

                <!-- Videos You've Watched -->
                <section class="dashboard-section" id="my-videos">
                    <div class="section-header">
                        <h2><i class="fas fa-video" style="color: var(--rose);"></i> Videos You've Watched</h2>
                        <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-sm btn-outline">Browse All →</a>
                    </div>
                    <?php if (count($watched_videos) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($watched_videos as $video): ?>
                                <div class="content-card">
                                    <div class="content-cover video-thumb">
                                        <?php $img = getImageSrc($video, 'thumbnail'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                                            <div class="play-overlay"><i class="fas fa-play-circle"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-video"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                        <p class="content-description">
                                            <?php echo htmlspecialchars(substr($video['description'] ?? '', 0, 60)); ?>...
                                        </p>
                                        <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary">Watch Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No videos watched yet. <a href="<?php echo SITE_URL; ?>/videos.php">Explore videos</a></p>
                    <?php endif; ?>
                </section>

                <!-- Blog Posts You've Read -->
                <section class="dashboard-section" id="my-blog-posts">
                    <div class="section-header">
                        <h2><i class="fas fa-pen-fancy" style="color: var(--rose);"></i> Blog Posts You've Read</h2>
                        <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-sm btn-outline">Browse All →</a>
                    </div>
                    <?php if (count($read_blog_posts) > 0): ?>
                        <div class="blog-grid">
                            <?php foreach ($read_blog_posts as $post): ?>
                                <div class="blog-card">
                                    <?php if ($post['featured_image']): ?>
                                        <div class="blog-thumbnail">
                                            <img src="<?php echo SITE_URL . '/' . $post['featured_image']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                                        </div>
                                    <?php else: ?>
                                        <div class="blog-thumbnail-placeholder">
                                            <i class="fas fa-pen-fancy"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="blog-content">
                                        <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                        <?php if ($post['excerpt']): ?>
                                            <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['excerpt'], 0, 100)); ?>...</p>
                                        <?php endif; ?>
                                        <a href="<?php echo SITE_URL; ?>/blog_post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline">Read Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No blog posts read yet. <a href="<?php echo SITE_URL; ?>/blog.php">Start reading</a></p>
                    <?php endif; ?>
                </section>

                <!-- Christian Reflections You've Read -->
                <section class="dashboard-section" id="my-reflections">
                    <div class="section-header">
                        <h2><i class="fas fa-pray" style="color: var(--rose);"></i> Reflections You've Read</h2>
                        <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-sm btn-outline">Browse All →</a>
                    </div>
                    <?php if (count($read_reflections) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($read_reflections as $reflection): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php $img = getImageSrc($reflection, 'image_path'); ?>
                                        <?php if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>">
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-pray"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($reflection['title']); ?></h3>
                                        <?php if ($reflection['excerpt']): ?>
                                            <p class="content-description"><?php echo htmlspecialchars(substr($reflection['excerpt'], 0, 100)); ?>...</p>
                                        <?php endif; ?>
                                        <a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>" class="btn btn-sm btn-primary">Read Again</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No reflections read yet. <a href="<?php echo SITE_URL; ?>/reflections.php">Start reading</a></p>
                    <?php endif; ?>
                </section>

                <!-- Your Upcoming Sessions -->
                <section class="dashboard-section" id="my-sessions">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-check" style="color: var(--rose);"></i> Your Upcoming Sessions</h2>
                        <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-sm btn-outline">Book Session</a>
                    </div>
                    <?php if (count($my_sessions) > 0): ?>
                        <div class="session-list">
                            <?php foreach ($my_sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-info">
                                        <strong><?php echo htmlspecialchars($session['date']); ?></strong> at <?php echo htmlspecialchars($session['time']); ?>
                                        <span class="status-badge <?php echo $session['status'] ?? 'pending'; ?>">
                                            <?php echo ucfirst($session['status'] ?? 'Pending'); ?>
                                        </span>
                                        <?php if (isset($session['message'])): ?>
                                            <p class="session-message"><?php echo htmlspecialchars($session['message']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No upcoming sessions. <a href="<?php echo SITE_URL; ?>/book_session.php">Book a session with Angella</a></p>
                    <?php endif; ?>
                </section>

                <!-- Your Community Questions -->
                <section class="dashboard-section" id="my-questions">
                    <div class="section-header">
                        <h2><i class="fas fa-question-circle" style="color: var(--rose);"></i> Your Questions</h2>
                        <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-sm btn-outline">Ask a Question</a>
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
                                        <span><?php echo isset($q['created_at']) ? date('M j, Y', strtotime($q['created_at'])) : ''; ?></span>
                                        <span><?php echo $q['answer_count'] ?? 0; ?> answer(s)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">You haven't asked any questions yet. <a href="<?php echo SITE_URL; ?>/community.php">Ask a question</a></p>
                    <?php endif; ?>
                </section>

            </div>

            <!-- SIDEBAR -->
            <div class="dashboard-sidebar">
                
                <!-- Profile Summary -->
                <div class="profile-summary" id="profile">
                    <div class="profile-pic">
                        <?php if ($user['profile_pic']): ?>
                            <img src="<?php echo SITE_URL . '/' . $user['profile_pic']; ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                        <?php else: ?>
                            <i class="fas fa-user-circle"></i>
                        <?php endif; ?>
                    </div>
                    <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                    <p class="user-email"><?php echo htmlspecialchars($user['email']); ?></p>
                    <?php if ($user['bio']): ?>
                        <p class="user-bio"><?php echo htmlspecialchars($user['bio']); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Notifications -->
                <div class="dashboard-card" id="notifications">
                    <h4><i class="fas fa-bell" style="color: var(--rose);"></i> Notifications</h4>
                    <?php if (count($notifications) > 0): ?>
                        <div class="mini-list">
                            <?php foreach ($notifications as $notif): ?>
                                <div class="mini-item <?php echo isset($notif['is_read']) && $notif['is_read'] ? 'read' : 'unread'; ?>">
                                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notif-date"><?php echo isset($notif['created_at']) ? date('M j, Y g:i a', strtotime($notif['created_at'])) : ''; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No notifications yet.</p>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.user-dashboard { padding: 32px 0 60px; }
.dashboard-header { text-align: center; margin-bottom: 32px; }
.dashboard-header h1 { font-size: 2.2rem; color: var(--text); }
.dashboard-header p { color: var(--text-light); font-size: 1.1rem; }

.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }

.dashboard-section { margin-bottom: 48px; }
.section-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.section-header h2 { font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 8px; }

.content-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
.content-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: transform 0.2s; }
.content-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
.content-cover { height: 150px; background: var(--vanilla); display: flex; align-items: center; justify-content: center; position: relative; }
.content-cover img { width: 100%; height: 100%; object-fit: cover; }
.placeholder-cover { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--rose); background: var(--vanilla); }
.content-info { padding: 14px; }
.content-info h3 { font-size: 1.05rem; margin: 0 0 6px 0; }
.content-description { font-size: 0.9rem; color: var(--text-light); margin-bottom: 10px; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
.status-badge.currently\ reading { background: var(--rose); color: white; }
.status-badge.finished { background: #27ae60; color: white; }

/* Poem Styles */
.poem-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
.poem-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); transition: all var(--transition); border: 1px solid var(--border); }
.poem-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.poem-thumbnail { width: 100%; height: 160px; overflow: hidden; background: var(--vanilla); }
.poem-thumbnail img { width: 100%; height: 100%; object-fit: cover; }
.poem-thumbnail-placeholder { width: 100%; height: 160px; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--rose); background: var(--vanilla); }
.poem-content { padding: 16px; }
.poem-content h3 { font-size: 1.1rem; margin-bottom: 6px; }
.poem-intro-preview { background: var(--vanilla); padding: 8px 12px; border-radius: 6px; margin: 6px 0 10px; border-left: 3px solid var(--rose); }
.poem-intro-preview .intro-label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--rose); margin-bottom: 2px; }
.poem-excerpt { color: var(--text-light); font-size: 0.9rem; line-height: 1.6; margin-bottom: 10px; }
.poem-actions { display: flex; gap: 8px; margin-top: auto; }

/* Blog Styles */
.blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
.blog-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); }
.blog-thumbnail { width: 100%; height: 140px; overflow: hidden; background: var(--vanilla); }
.blog-thumbnail img { width: 100%; height: 100%; object-fit: cover; }
.blog-thumbnail-placeholder { width: 100%; height: 140px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--rose); background: var(--vanilla); }
.blog-content { padding: 14px; }
.blog-content h3 { font-size: 1rem; margin-bottom: 6px; }
.blog-excerpt { font-size: 0.9rem; color: var(--text-light); margin-bottom: 10px; }

/* Video Styles */
.video-thumb .play-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.3); color: white; font-size: 3.5rem; opacity: 0.7; transition: opacity 0.2s; }
.video-thumb:hover .play-overlay { opacity: 1; }

/* Session & QA */
.session-list { display: flex; flex-direction: column; gap: 8px; }
.session-item { background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); }
.session-message { font-size: 0.85rem; color: var(--text-light); margin-top: 4px; }
.qa-list { display: flex; flex-direction: column; gap: 8px; }
.qa-item { background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); }
.qa-title a { color: var(--text); font-weight: 500; }
.qa-title a:hover { color: var(--rose); }
.qa-meta { display: flex; gap: 12px; font-size: 0.8rem; color: var(--text-light); margin-top: 4px; }

/* Sidebar */
.dashboard-sidebar { display: flex; flex-direction: column; gap: 20px; }
.dashboard-card { background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); }
.dashboard-card h4 { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; font-size: 1rem; }
.mini-list { display: flex; flex-direction: column; gap: 8px; }
.mini-item { background: var(--bg); padding: 10px; border-radius: 8px; border: 1px solid var(--border); }
.mini-item.unread { border-left: 3px solid var(--rose); }
.notif-title { font-weight: 600; font-size: 0.95rem; }
.notif-message { color: var(--text-light); font-size: 0.85rem; }
.notif-date { font-size: 0.75rem; color: var(--text-light); margin-top: 4px; }

.profile-summary { text-align: center; padding: 20px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); }
.profile-pic { width: 90px; height: 90px; border-radius: 50%; margin: 0 auto 10px; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.profile-pic img { width: 100%; height: 100%; object-fit: cover; }
.profile-pic i { font-size: 3.5rem; color: var(--rose); }
.profile-summary h3 { margin-bottom: 4px; }
.user-email { color: var(--text-light); font-size: 0.9rem; }
.user-bio { color: var(--text); font-size: 0.9rem; margin-top: 8px; line-height: 1.5; }

.no-items { color: var(--text-light); padding: 8px 0; text-align: center; font-size: 0.9rem; }
.no-items a { color: var(--rose); }
.btn-sm { padding: 6px 14px; font-size: 0.85rem; }
.btn-outline { background: transparent; border: 1px solid var(--rose); color: var(--rose); }
.btn-outline:hover { background: var(--rose); color: white; }

@media (max-width: 1024px) {
    .dashboard-grid { grid-template-columns: 1fr; }
    .dashboard-sidebar { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
}
@media (max-width: 600px) {
    .dashboard-sidebar { grid-template-columns: 1fr; }
    .content-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 400px) {
    .content-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once 'includes/footer.php'; ?>