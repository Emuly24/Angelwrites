<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Redirect non-logged-in users
redirectIfNotLoggedIn();

// Redirect admin away from this page
if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// === DEBUG: Check which columns exist ===
function debugColumns($db, $table) {
    try {
        $stmt = $db->prepare("PRAGMA table_info($table)");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

$debug = [];
$tables = ['books', 'poems', 'blog_posts', 'videos', 'reflections', 'questions', 'sessions', 'notifications', 'connections', 'user_tags'];
foreach ($tables as $table) {
    $debug[$table] = debugColumns($db, $table);
}

// === Helper: Get image with fallback ===
function getImageSrc($row, $column, $placeholder = 'fas fa-image') {
    if (isset($row[$column]) && !empty($row[$column])) {
        return SITE_URL . '/' . $row[$column];
    }
    return null;
}

// === Helper: Get text with fallback ===
function getText($row, $column, $default = 'No description available.') {
    if (isset($row[$column]) && !empty($row[$column])) {
        return htmlspecialchars(substr($row[$column], 0, 100)) . '...';
    }
    return $default;
}

// === Helper: Safe fetch with column check ===
function safeFetch($db, $sql, $params = [], $limit = 6) {
    try {
        $stmt = $db->prepare($sql . " LIMIT " . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// === Angella's Books ===
$angella_books = safeFetch($db, "SELECT * FROM books WHERE is_angella_book = 1 ORDER BY created_at DESC", [], 6);

// === Poems ===
$poems = safeFetch($db, "SELECT * FROM poems ORDER BY created_at DESC", [], 6);

// === Blog Posts ===
$blog_posts = safeFetch($db, "SELECT * FROM blog_posts ORDER BY published_at DESC", [], 6);

// === Short Videos ===
$videos = safeFetch($db, "SELECT * FROM videos WHERE type = 'short' ORDER BY created_at DESC", [], 6);

// === Christian Reflections ===
$reflections = safeFetch($db, "SELECT * FROM reflections ORDER BY created_at DESC", [], 6);

// === Community Q&A ===
$community_questions = safeFetch($db, "
    SELECT q.*, u.name AS author_name, COUNT(a.id) AS answer_count 
    FROM questions q
    JOIN users u ON q.user_id = u.id
    LEFT JOIN answers a ON q.id = a.question_id
    WHERE q.status = 'approved'
    GROUP BY q.id
    ORDER BY q.created_at DESC", [], 5);

// === User Sessions ===
$my_sessions = safeFetch($db, "SELECT * FROM sessions WHERE user_id = ? ORDER BY date DESC, time DESC", [$user_id], 10);

// === User Questions ===
$my_questions = safeFetch($db, "
    SELECT q.*, COUNT(a.id) AS answer_count 
    FROM questions q
    LEFT JOIN answers a ON q.id = a.question_id
    WHERE q.user_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC", [$user_id], 10);

// === Notifications ===
$notifications = safeFetch($db, "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC", [$user_id], 10);

// === Connections ===
$connections = safeFetch($db, "
    SELECT c.*, u.name AS sender_name, u.email AS sender_email 
    FROM connections c
    JOIN users u ON c.sender_id = u.id
    WHERE c.receiver_id = ? OR c.sender_id = ?
    ORDER BY c.created_at DESC", [$user_id, $user_id], 10);

// === Tags ===
$tags = safeFetch($db, "SELECT * FROM user_tags WHERE user_id = ?", [$user_id], 20);

$pageTitle = 'My Dashboard';
?>
<?php require_once 'includes/header.php'; ?>

<div class="user-dashboard">
    <div class="container">
        <!-- DEBUG INFO (Remove this section after fixes) -->
        <div class="debug-section" style="background:#f8f9fa; padding:16px; border-radius:8px; margin-bottom:24px; border:1px solid #ddd;">
            <h3 style="margin-top:0;">🔍 Database Column Check</h3>
            <p>If images aren't showing, check these columns:</p>
            <ul style="columns:2; column-gap:30px;">
                <?php foreach ($debug as $table => $columns): ?>
                    <li><strong><?php echo $table; ?>:</strong> 
                        <?php 
                        $col_names = array_column($columns, 'name');
                        echo empty($col_names) ? '❌ Table missing' : implode(', ', $col_names);
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="font-size:0.9rem; color:#666;">To fix missing columns, use phpLiteAdmin to add them.</p>
        </div>

        <div class="dashboard-header">
            <h1>Welcome Back, <?php echo htmlspecialchars($user['name']); ?>! 🌿</h1>
            <p>Explore the latest from AngelWrites and manage your activity below.</p>
        </div>

        <div class="dashboard-grid">
            <!-- ===== MAIN CONTENT (LEFT COLUMN) ===== -->
            <div class="dashboard-main">
                
                <!-- Angella's Books -->
                <section class="dashboard-section" id="angella-books">
                    <div class="section-header">
                        <h2><i class="fas fa-book" style="color: var(--rose);"></i> Books by Angella</h2>
                        <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-sm btn-outline">View All →</a>
                    </div>
                    <?php if (count($angella_books) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($angella_books as $book): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php 
                                        $img = getImageSrc($book, 'cover_path');
                                        if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="placeholder-cover" style="display:none;"><i class="fas fa-book"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p class="content-description"><?php echo getText($book, 'description', 'A beautiful book by Angella.'); ?></p>
                                        <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">View Book</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No books published yet.</p>
                    <?php endif; ?>
                </section>

                <!-- Poems -->
                <section class="dashboard-section" id="poems">
                    <div class="section-header">
                        <h2><i class="fas fa-feather-alt" style="color: var(--rose);"></i> Poems</h2>
                        <a href="<?php echo SITE_URL; ?>/poems.php" class="btn btn-sm btn-outline">Browse →</a>
                    </div>
                    <?php if (count($poems) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($poems as $poem): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php 
                                        $img = getImageSrc($poem, 'cover_image');
                                        if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="placeholder-cover" style="display:none;"><i class="fas fa-feather-alt"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-feather-alt"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                                        <p class="content-description"><?php echo getText($poem, 'purpose', 'A heartfelt poem from Angella.'); ?></p>
                                        <a href="<?php echo SITE_URL; ?>/poem.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary">Read Poem</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No poems published yet.</p>
                    <?php endif; ?>
                </section>

                <!-- Blog Posts -->
                <section class="dashboard-section" id="blog-posts">
                    <div class="section-header">
                        <h2><i class="fas fa-pen-fancy" style="color: var(--rose);"></i> Blog Posts</h2>
                        <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-sm btn-outline">Read Blog →</a>
                    </div>
                    <?php if (count($blog_posts) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($blog_posts as $post): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php 
                                        $img = getImageSrc($post, 'featured_image');
                                        if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="placeholder-cover" style="display:none;"><i class="fas fa-pen-fancy"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-pen-fancy"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                        <p class="content-description"><?php echo getText($post, 'excerpt', 'A thoughtful post from Angella.'); ?></p>
                                        <a href="<?php echo SITE_URL; ?>/blog_post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-primary">Read Post</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No blog posts yet.</p>
                    <?php endif; ?>
                </section>

                <!-- Short Videos -->
                <section class="dashboard-section" id="videos">
                    <div class="section-header">
                        <h2><i class="fas fa-video" style="color: var(--rose);"></i> Short Videos</h2>
                        <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-sm btn-outline">Watch More →</a>
                    </div>
                    <?php if (count($videos) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($videos as $video): ?>
                                <div class="content-card">
                                    <div class="content-cover video-thumb">
                                        <?php 
                                        $img = getImageSrc($video, 'thumbnail');
                                        if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="play-overlay"><i class="fas fa-play-circle"></i></div>
                                            <div class="placeholder-cover" style="display:none;"><i class="fas fa-video"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-video"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                                        <p class="content-description"><?php echo getText($video, 'description', 'A short video from Angella.'); ?></p>
                                        <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary">Watch Video</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No short videos yet.</p>
                    <?php endif; ?>
                </section>

                <!-- Christian Reflections -->
                <section class="dashboard-section" id="reflections">
                    <div class="section-header">
                        <h2><i class="fas fa-pray" style="color: var(--rose);"></i> Christian Reflections</h2>
                        <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-sm btn-outline">Read All →</a>
                    </div>
                    <?php if (count($reflections) > 0): ?>
                        <div class="content-grid">
                            <?php foreach ($reflections as $reflection): ?>
                                <div class="content-card">
                                    <div class="content-cover">
                                        <?php 
                                        $img = getImageSrc($reflection, 'image_path');
                                        if ($img): ?>
                                            <img src="<?php echo $img; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="placeholder-cover" style="display:none;"><i class="fas fa-pray"></i></div>
                                        <?php else: ?>
                                            <div class="placeholder-cover"><i class="fas fa-pray"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="content-info">
                                        <h3><?php echo htmlspecialchars($reflection['title']); ?></h3>
                                        <p class="content-description"><?php echo getText($reflection, 'excerpt', 'A reflection from Angella.'); ?></p>
                                        <a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>" class="btn btn-sm btn-primary">Read Reflection</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No reflections published yet.</p>
                    <?php endif; ?>
                </section>

                <!-- Community Q&A -->
                <section class="dashboard-section" id="community-qa">
                    <div class="section-header">
                        <h2><i class="fas fa-comments" style="color: var(--rose);"></i> Community Q&A</h2>
                        <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-sm btn-outline">Ask a Question →</a>
                    </div>
                    <?php if (count($community_questions) > 0): ?>
                        <div class="qa-list">
                            <?php foreach ($community_questions as $q): ?>
                                <div class="qa-item">
                                    <div class="qa-title">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>">
                                            <?php echo htmlspecialchars($q['title']); ?>
                                        </a>
                                        <span class="qa-author">— <?php echo htmlspecialchars($q['author_name']); ?></span>
                                    </div>
                                    <div class="qa-meta">
                                        <span><?php echo isset($q['created_at']) ? date('M j, Y', strtotime($q['created_at'])) : ''; ?></span>
                                        <span class="answer-count"><?php echo $q['answer_count'] ?? 0; ?> answer(s)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">Be the first to ask a question!</p>
                    <?php endif; ?>
                </section>

            </div>

            <!-- ===== SIDEBAR (RIGHT COLUMN) ===== -->
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

                <!-- My Sessions -->
                <div class="dashboard-card" id="my-sessions">
                    <h4><i class="fas fa-calendar-check" style="color: var(--rose);"></i> My Sessions</h4>
                    <?php if (count($my_sessions) > 0): ?>
                        <div class="mini-list">
                            <?php foreach ($my_sessions as $session): ?>
                                <div class="mini-item">
                                    <strong><?php echo htmlspecialchars($session['date']); ?></strong> at <?php echo htmlspecialchars($session['time']); ?>
                                    <span class="status-badge <?php echo $session['status'] ?? 'pending'; ?>"><?php echo ucfirst($session['status'] ?? 'pending'); ?></span>
                                    <?php if (isset($session['message'])): ?>
                                        <p class="session-message"><?php echo htmlspecialchars($session['message']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No sessions booked. <a href="<?php echo SITE_URL; ?>/book_session.php">Book a session</a></p>
                    <?php endif; ?>
                </div>

                <!-- My Questions -->
                <div class="dashboard-card" id="my-questions">
                    <h4><i class="fas fa-question-circle" style="color: var(--rose);"></i> My Questions</h4>
                    <?php if (count($my_questions) > 0): ?>
                        <div class="mini-list">
                            <?php foreach ($my_questions as $q): ?>
                                <div class="mini-item">
                                    <div class="question-title">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>">
                                            <?php echo htmlspecialchars($q['title']); ?>
                                        </a>
                                    </div>
                                    <div class="question-meta">
                                        <span><?php echo isset($q['created_at']) ? date('M j, Y', strtotime($q['created_at'])) : ''; ?></span>
                                        <span><?php echo $q['answer_count'] ?? 0; ?> answer(s)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">You haven't asked any questions yet. <a href="<?php echo SITE_URL; ?>/community.php">Ask a question</a></p>
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

                <!-- Connections -->
                <div class="dashboard-card" id="connections">
                    <h4><i class="fas fa-users" style="color: var(--rose);"></i> Connections</h4>
                    <?php if (count($connections) > 0): ?>
                        <ul class="connection-list">
                            <?php foreach ($connections as $conn): ?>
                                <li>
                                    <?php if ($conn['sender_id'] == $user_id): ?>
                                        Sent to <?php echo htmlspecialchars($conn['sender_name']); ?>
                                        <span class="status-badge <?php echo $conn['status']; ?>"><?php echo ucfirst($conn['status']); ?></span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($conn['sender_name']); ?> 
                                        <span class="status-badge <?php echo $conn['status']; ?>"><?php echo ucfirst($conn['status']); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="no-items">No connections yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Tags -->
                <div class="dashboard-card" id="tags">
                    <h4><i class="fas fa-tags" style="color: var(--rose);"></i> Tags You Follow</h4>
                    <?php if (count($tags) > 0): ?>
                        <div class="tags-list">
                            <?php foreach ($tags as $tag): ?>
                                <span class="tag-pill">#<?php echo htmlspecialchars($tag['tag']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No tags followed yet.</p>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

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
.video-thumb .play-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.3); color: white; font-size: 3.5rem; opacity: 0.7; transition: opacity 0.2s; }
.video-thumb:hover .play-overlay { opacity: 1; }
.content-info { padding: 14px; }
.content-info h3 { font-size: 1.05rem; margin: 0 0 6px 0; }
.content-description { font-size: 0.9rem; color: var(--text-light); margin-bottom: 10px; }

.qa-list { display: flex; flex-direction: column; gap: 8px; }
.qa-item { background: var(--bg); padding: 14px; border-radius: 8px; border: 1px solid var(--border); }
.qa-title a { color: var(--text); font-weight: 500; }
.qa-title a:hover { color: var(--rose); }
.qa-author { font-size: 0.85rem; color: var(--text-light); margin-left: 6px; }
.qa-meta { display: flex; gap: 12px; font-size: 0.8rem; color: var(--text-light); margin-top: 4px; }

.dashboard-sidebar { display: flex; flex-direction: column; gap: 20px; }
.dashboard-card { background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); }
.dashboard-card h4 { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; font-size: 1rem; }

.mini-list { display: flex; flex-direction: column; gap: 8px; }
.mini-item { background: var(--bg); padding: 10px; border-radius: 8px; border: 1px solid var(--border); }
.mini-item.unread { border-left: 3px solid var(--rose); }
.notif-title { font-weight: 600; font-size: 0.95rem; }
.notif-message { color: var(--text-light); font-size: 0.85rem; }
.notif-date { font-size: 0.75rem; color: var(--text-light); margin-top: 4px; }

.session-message { font-size: 0.85rem; color: var(--text-light); margin-top: 4px; }

.question-title a { color: var(--text); font-weight: 500; }
.question-title a:hover { color: var(--rose); }
.question-meta { display: flex; gap: 12px; font-size: 0.8rem; color: var(--text-light); margin-top: 4px; }

.profile-summary { text-align: center; padding: 20px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); }
.profile-pic { width: 90px; height: 90px; border-radius: 50%; margin: 0 auto 10px; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.profile-pic img { width: 100%; height: 100%; object-fit: cover; }
.profile-pic i { font-size: 3.5rem; color: var(--rose); }
.profile-summary h3 { margin-bottom: 4px; }
.user-email { color: var(--text-light); font-size: 0.9rem; }
.user-bio { color: var(--text); font-size: 0.9rem; margin-top: 8px; line-height: 1.5; }

.connection-list { list-style: none; padding: 0; margin: 0; }
.connection-list li { padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; }
.connection-list li:last-child { border-bottom: none; }

.tags-list { display: flex; flex-wrap: wrap; gap: 6px; }
.tag-pill { background: var(--vanilla); padding: 4px 12px; border-radius: 14px; font-size: 0.8rem; color: var(--text); }

.status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
.status-badge.pending { background: #f1c40f; color: white; }
.status-badge.confirmed { background: #2ecc71; color: white; }
.status-badge.completed { background: #3498db; color: white; }
.status-badge.cancelled { background: #e74c3c; color: white; }

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