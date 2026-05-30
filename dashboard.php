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
$error = '';
$success = '';

// Fetch user data (for display only)
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== FETCH USER'S BOOKS (purchased or reading) =====
$stmt = $db->prepare("
    SELECT b.*, rs.status, rs.progress 
    FROM books b
    LEFT JOIN reading_status rs ON b.id = rs.book_id AND rs.user_id = ?
    ORDER BY rs.updated_at DESC
");
$stmt->execute([$user_id]);
$my_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH SESSIONS =====
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE user_id = ? 
    ORDER BY date DESC, time DESC
");
$stmt->execute([$user_id]);
$my_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH USER'S QUESTIONS =====
$stmt = $db->prepare("
    SELECT q.*, COUNT(a.id) AS answer_count 
    FROM questions q
    LEFT JOIN answers a ON q.id = a.question_id
    WHERE q.user_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
");
$stmt->execute([$user_id]);
$my_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH NOTIFICATIONS =====
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH CONNECTIONS =====
$stmt = $db->prepare("
    SELECT c.*, u.name AS sender_name, u.email AS sender_email 
    FROM connections c
    JOIN users u ON c.sender_id = u.id
    WHERE c.receiver_id = ? OR c.sender_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH TAGS =====
$stmt = $db->prepare("SELECT * FROM user_tags WHERE user_id = ?");
$stmt->execute([$user_id]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Dashboard';
?>
<?php require_once 'includes/header.php'; ?>

<div class="user-dashboard">
    <div class="container">
        <div class="dashboard-header">
            <h1>My Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</p>
            <div class="header-actions">
                <a href="<?php echo SITE_URL; ?>/library.php" class="btn btn-sm btn-primary">My Library</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Left Column -->
            <div class="dashboard-main">
                <!-- Notifications -->
                <section class="dashboard-section" id="notifications">
                    <h2><i class="fas fa-bell" style="color: var(--rose);"></i> Notifications</h2>
                    <?php if (count($notifications) > 0): ?>
                        <div class="notification-list">
                            <?php foreach ($notifications as $notif): ?>
                                <div class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>">
                                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notif-date"><?php echo date('M j, Y g:i a', strtotime($notif['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No notifications yet.</p>
                    <?php endif; ?>
                </section>

                <!-- My Books -->
                <section class="dashboard-section" id="books">
                    <h2><i class="fas fa-book-open" style="color: var(--rose);"></i> My Books</h2>
                    <?php if (count($my_books) > 0): ?>
                        <div class="book-grid-small">
                            <?php foreach ($my_books as $book): ?>
                                <div class="book-card-mini">
                                    <div class="book-cover-mini">
                                        <?php if ($book['cover_path']): ?>
                                            <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                        <?php else: ?>
                                            <i class="fas fa-book"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="book-info-mini">
                                        <h4><?php echo htmlspecialchars($book['title']); ?></h4>
                                        <span class="status-badge <?php echo $book['status'] ?? 'none'; ?>">
                                            <?php echo $book['status'] ?? 'Not started'; ?>
                                        </span>
                                        <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">Read</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No books in your library yet. <a href="<?php echo SITE_URL; ?>/books.php">Browse books</a></p>
                    <?php endif; ?>
                </section>

                <!-- My Sessions -->
                <section class="dashboard-section" id="sessions">
                    <h2><i class="fas fa-calendar-check" style="color: var(--rose);"></i> My Sessions</h2>
                    <?php if (count($my_sessions) > 0): ?>
                        <div class="session-list">
                            <?php foreach ($my_sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-info">
                                        <strong><?php echo htmlspecialchars($session['date']); ?></strong> at <?php echo htmlspecialchars($session['time']); ?>
                                        <span class="status-badge <?php echo $session['status']; ?>"><?php echo ucfirst($session['status']); ?></span>
                                        <?php if ($session['message']): ?>
                                            <p class="session-message"><?php echo htmlspecialchars($session['message']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">No sessions booked. <a href="<?php echo SITE_URL; ?>/book_session.php">Book a session</a></p>
                    <?php endif; ?>
                </section>

                <!-- My Questions & Admin Answers -->
                <section class="dashboard-section" id="questions">
                    <h2><i class="fas fa-comments" style="color: var(--rose);"></i> My Questions</h2>
                    <?php if (count($my_questions) > 0): ?>
                        <div class="question-list">
                            <?php foreach ($my_questions as $q): ?>
                                <div class="question-item">
                                    <div class="question-title">
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>">
                                            <?php echo htmlspecialchars($q['title']); ?>
                                        </a>
                                    </div>
                                    <div class="question-meta">
                                        <span><?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                                        <span><?php echo $q['answer_count']; ?> answer(s)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-items">You haven't asked any questions yet. <a href="<?php echo SITE_URL; ?>/community.php">Ask a question</a></p>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Right Column -->
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
.dashboard-header h1 { font-size: 2.2rem; }
.dashboard-header .header-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 12px; }

.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 32px; }

.dashboard-section { margin-bottom: 32px; background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); }
.dashboard-section h2 { font-size: 1.3rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

.book-grid-small { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; }
.book-card-mini { background: var(--bg); border-radius: 8px; overflow: hidden; border: 1px solid var(--border); }
.book-cover-mini { height: 120px; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.book-cover-mini img { width: 100%; height: 100%; object-fit: cover; }
.book-cover-mini i { font-size: 3rem; color: var(--rose); }
.book-info-mini { padding: 12px; }
.book-info-mini h4 { font-size: 0.95rem; margin-bottom: 4px; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
.status-badge.currently reading { background: var(--rose); color: white; }
.status-badge.want to read { background: #3498db; color: white; }
.status-badge.finished { background: #27ae60; color: white; }
.status-badge.none { background: var(--border); color: var(--text-light); }
.status-badge.pending { background: #f1c40f; color: white; }
.status-badge.confirmed { background: #2ecc71; color: white; }
.status-badge.completed { background: #3498db; color: white; }
.status-badge.cancelled { background: #e74c3c; color: white; }

.session-list, .notification-list, .question-list { display: flex; flex-direction: column; gap: 8px; }
.session-item, .notification-item, .question-item { background: var(--bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); }
.notification-item.unread { border-left: 3px solid var(--rose); }
.notif-title { font-weight: 600; }
.notif-message { color: var(--text-light); font-size: 0.9rem; }
.notif-date { font-size: 0.8rem; color: var(--text-light); margin-top: 4px; }

.question-title a { color: var(--text); font-weight: 500; }
.question-title a:hover { color: var(--rose); }
.question-meta { display: flex; gap: 12px; font-size: 0.8rem; color: var(--text-light); }

.dashboard-sidebar { display: flex; flex-direction: column; gap: 16px; }
.dashboard-card { background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); }
.dashboard-card h4 { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

.profile-summary { text-align: center; padding: 20px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); }
.profile-pic { width: 100px; height: 100px; border-radius: 50%; margin: 0 auto 12px; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.profile-pic img { width: 100%; height: 100%; object-fit: cover; }
.profile-pic i { font-size: 4rem; color: var(--rose); }
.profile-summary h3 { margin-bottom: 4px; }
.user-email { color: var(--text-light); font-size: 0.9rem; }
.user-bio { color: var(--text); font-size: 0.9rem; margin-top: 8px; line-height: 1.5; }

.connection-list { list-style: none; padding: 0; }
.connection-list li { padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.connection-list li:last-child { border-bottom: none; }

.tags-list { display: flex; flex-wrap: wrap; gap: 6px; }
.tag-pill { background: var(--vanilla); padding: 4px 12px; border-radius: 14px; font-size: 0.8rem; color: var(--text); }

@media (max-width: 768px) {
    .dashboard-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once 'includes/footer.php'; ?>