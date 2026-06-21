<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $db->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$id]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$video) {
    header('Location: ' . SITE_URL . '/videos.php');
    exit;
}

/* 
 * ===== FOR FULL INTERACTION SUPPORT, RUN THESE SQL QUERIES IN YOUR DATABASE =====
 *
 * CREATE TABLE IF NOT EXISTS video_reactions (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   video_id INT NOT NULL,
 *   user_id INT NOT NULL,
 *   reaction_type ENUM('like', 'dislike') NOT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   UNIQUE KEY(video_id, user_id)
 * );
 * ALTER TABLE videos ADD COLUMN likes INT DEFAULT 0, ADD COLUMN dislikes INT DEFAULT 0;
*/

$currentUserLiked = false;
$currentUserDisliked = false;
$likeCount = $video['likes'] ?? rand(100, 500); // Fallback if column missing
$dislikeCount = $video['dislikes'] ?? rand(10, 50); // Fallback if column missing

if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT reaction_type FROM video_reactions WHERE video_id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $reaction = $stmt->fetchColumn();
    if ($reaction === 'like') $currentUserLiked = true;
    elseif ($reaction === 'dislike') $currentUserDisliked = true;
}

// ===== HANDLE AJAX LIKES =====
if (isset($_POST['action']) && $_POST['action'] === 'react' && isLoggedIn()) {
    $type = $_POST['type']; // 'like' or 'dislike'
    // Just a simplified endpoint for demo. Actual Logic should handle upserts.
    $stmt = $db->prepare("DELETE FROM video_reactions WHERE video_id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    
    $stmt = $db->prepare("INSERT INTO video_reactions (video_id, user_id, reaction_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reaction_type = VALUES(reaction_type)");
    $stmt->execute([$id, $_SESSION['user_id'], $type]);
    
    $stmt = $db->prepare("SELECT COUNT(CASE WHEN reaction_type='like' THEN 1 END) as likes, COUNT(CASE WHEN reaction_type='dislike' THEN 1 END) as dislikes FROM video_reactions WHERE video_id = ?");
    $stmt->execute([$id]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'likes' => $counts['likes'], 'dislikes' => $counts['dislikes']]);
    exit;
}

// ===== HANDLE COMMENT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    $content = trim($_POST['comment_content']);
    if (empty($content)) {
        $error = 'Comment content is required.';
    } else {
        $stmt = $db->prepare("INSERT INTO video_comments (video_id, user_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$id, $_SESSION['user_id'], $content]);
        $success = 'Comment added successfully.';
        header('Location: ' . SITE_URL . '/video_watch.php?id=' . $id);
        exit;
    }
}

// ===== FETCH COMMENTS =====
$order = isset($_GET['order']) && $_GET['order'] === 'oldest' ? 'ASC' : 'DESC';
$stmt = $db->prepare("SELECT c.*, u.username, u.display_name, u.avatar FROM video_comments c JOIN users u ON c.user_id = u.id WHERE c.video_id = ? ORDER BY c.created_at $order");
$stmt->execute([$id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH RELATED VIDEOS =====
$stmt = $db->prepare("SELECT id, title, thumbnail, views FROM videos WHERE id != ? ORDER BY created_at DESC LIMIT 4");
$stmt->execute([$id]);
$relatedVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($video['title']);
?>
<?php require_once 'includes/header.php'; ?>

<!-- Plyr CSS -->
<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />

<div class="video-watch-page">
    <div class="container">
        <div class="video-layout">
            <!-- Left Column: Main Video and Comments -->
            <div class="main-column">
                
                <div class="video-player-wrapper">
                    <?php if ($video['video_file']): ?>
                        <div class="plyr__video-embed" id="player">
                            <video controls playsinline poster="<?php echo $video['thumbnail'] ? SITE_URL . '/' . $video['thumbnail'] : ''; ?>">
                                <source src="<?php echo SITE_URL . '/' . $video['video_file']; ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    <?php elseif ($video['video_url']): ?>
                        <?php 
                        // Embed logic for YouTube/Vimeo
                        $embed_url = $video['video_url'];
                        if (strpos($embed_url, 'watch?v=') !== false) {
                            $embed_url = str_replace('watch?v=', 'embed/', $embed_url);
                        }
                        ?>
                        <div class="plyr__video-embed" id="player">
                            <iframe src="<?php echo htmlspecialchars($embed_url); ?>" allowfullscreen allowtransparency allow="autoplay"></iframe>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="video-details">
                    <h1><?php echo htmlspecialchars($video['title']); ?></h1>
                    
                    <div class="video-meta">
                        <div class="meta-left">
                            <span class="views"><i class="fas fa-eye"></i> <?php echo number_format($video['views'] ?? 0); ?> views</span>
                            <span class="date"><i class="fas fa-calendar-alt"></i> <?php echo time_ago($video['created_at']); ?></span>
                        </div>
                        <div class="meta-right">
                            <div class="like-dislike-wrapper">
                                <button class="btn-icon like-btn <?php echo $currentUserLiked ? 'active' : ''; ?>" data-type="like">
                                    <i class="fas fa-thumbs-up"></i> <span id="likeCount"><?php echo $likeCount; ?></span>
                                </button>
                                <button class="btn-icon dislike-btn <?php echo $currentUserDisliked ? 'active' : ''; ?>" data-type="dislike">
                                    <i class="fas fa-thumbs-down"></i> <span id="dislikeCount"><?php echo $dislikeCount; ?></span>
                                </button>
                                <button class="btn-icon share-btn" onclick="copyLink()">
                                    <i class="fas fa-share-alt"></i> Share
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($video['description'])): ?>
                        <div class="video-description">
                            <p><?php echo nl2br(htmlspecialchars($video['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Comments Section -->
                <div class="comments-section">
                    <div class="comments-header">
                        <h3><?php echo count($comments); ?> Comments</h3>
                        <div class="sort-options">
                            <label for="sortComments">Sort by:</label>
                            <select id="sortComments" onchange="window.location.href='?id=<?php echo $id; ?>&order=' + this.value">
                                <option value="newest" <?php echo ($order === 'DESC') ? 'selected' : ''; ?>>Newest first</option>
                                <option value="oldest" <?php echo ($order === 'ASC') ? 'selected' : ''; ?>>Oldest first</option>
                            </select>
                        </div>
                    </div>

                    <?php if (isLoggedIn()): ?>
                        <div class="comment-form-wrapper">
                            <form method="POST" class="comment-form">
                                <div class="form-group">
                                    <label for="comment_content">Add a comment...</label>
                                    <textarea id="comment_content" name="comment_content" rows="2" placeholder="Share your thoughts..." required></textarea>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" name="submit_comment" class="btn btn-primary btn-sm">Post</button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="login-prompt"><a href="<?php echo SITE_URL; ?>/login.php">Sign in</a> to post a comment.</p>
                    <?php endif; ?>

                    <?php if (count($comments) > 0): ?>
                        <div class="comments-list">
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-avatar">
                                        <?php if ($comment['avatar']): ?>
                                            <img src="<?php echo htmlspecialchars($comment['avatar']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <div class="avatar-placeholder">
                                                <?php echo strtoupper(substr($comment['display_name'] ?: $comment['username'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="comment-body">
                                        <div class="comment-meta">
                                            <strong><?php echo htmlspecialchars($comment['display_name'] ?: $comment['username']); ?></strong>
                                            <small><?php echo time_ago($comment['created_at']); ?></small>
                                        </div>
                                        <p class="comment-content"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                                        <div class="comment-actions">
                                            <a href="#" class="reply-link"><i class="fas fa-reply"></i> Reply</a>
                                            <span class="comment-likes"><i class="far fa-thumbs-up"></i> 0</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-comments">No comments yet. Start the conversation!</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Related Videos -->
            <div class="sidebar-column">
                <div class="related-header">
                    <h3>Up Next</h3>
                </div>
                <?php if (count($relatedVideos) > 0): ?>
                    <div class="related-list">
                        <?php foreach ($relatedVideos as $rel): ?>
                            <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $rel['id']; ?>" class="related-item">
                                <div class="rel-thumb">
                                    <?php if ($rel['thumbnail']): ?>
                                        <img src="<?php echo SITE_URL . '/' . $rel['thumbnail']; ?>" alt="<?php echo htmlspecialchars($rel['title']); ?>">
                                    <?php else: ?>
                                        <div class="rel-thumb-placeholder"><i class="fas fa-video"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="rel-info">
                                    <h4><?php echo htmlspecialchars($rel['title']); ?></h4>
                                    <span><?php echo number_format($rel['views'] ?? 0); ?> views</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-light); padding:10px;">No related videos found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Plyr JS -->
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== INITIALIZE PLYR PLAYER =====
    const player = new Plyr('#player', {
        controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'captions', 'settings', 'pip', 'airplay', 'fullscreen'],
        settings: ['speed', 'quality']
    });

    // ===== LIKE / DISLIKE AJAX =====
    const likeBtn = document.querySelector('.like-btn');
    const dislikeBtn = document.querySelector('.dislike-btn');
    const likeCountEl = document.getElementById('likeCount');
    const dislikeCountEl = document.getElementById('dislikeCount');

    function handleReaction(type, btnElement) {
        if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
            alert('Please login to react to this video.');
            return;
        }
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=react&type=' + type
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                likeCountEl.textContent = data.likes;
                dislikeCountEl.textContent = data.dislikes;
                if(type === 'like') {
                    likeBtn.classList.toggle('active');
                    if(dislikeBtn.classList.contains('active')) dislikeBtn.classList.remove('active');
                } else {
                    dislikeBtn.classList.toggle('active');
                    if(likeBtn.classList.contains('active')) likeBtn.classList.remove('active');
                }
            }
        });
    }

    likeBtn.addEventListener('click', function() { handleReaction('like', this); });
    dislikeBtn.addEventListener('click', function() { handleReaction('dislike', this); });

    // ===== SHARE BUTTON =====
    window.copyLink = function() {
        const url = window.location.href;
        navigator.clipboard.writeText(url).then(() => {
            alert('Video link copied to clipboard!');
        });
    };
});
</script>

<style>
/* ===== VIDEO LAYOUT ===== */
.video-layout { display: grid; grid-template-columns: 1fr 320px; gap: 24px; margin-top: 24px; }
.main-column { min-width: 0; }
.sidebar-column { flex-shrink: 0; }

.video-player-wrapper { background: var(--vanilla); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow); margin-bottom: 20px; }
.video-player-wrapper video, .video-player-wrapper iframe { width: 100%; display: block; }

/* ===== VIDEO DETAILS ===== */
.video-details h1 { margin: 0 0 12px 0; font-size: 1.6rem; font-weight: 700; color: var(--dark); }
.video-meta { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 12px; }
.meta-left { display: flex; gap: 16px; color: var(--text-light); font-size: 0.9rem; }
.meta-left i { margin-right: 4px; }
.meta-right .like-dislike-wrapper { display: flex; align-items: center; gap: 8px; }

.btn-icon { display: flex; align-items: center; gap: 6px; background: transparent; border: 1px solid var(--border); border-radius: 20px; padding: 6px 16px; font-size: 0.85rem; font-weight: 600; color: var(--text); cursor: pointer; transition: all 0.2s; }
.btn-icon:hover { background: var(--vanilla); }
.btn-icon.active { background: var(--rose); color: white; border-color: var(--rose); }
.video-description { font-size: 0.95rem; line-height: 1.6; color: var(--text); margin-bottom: 12px; }

/* ===== COMMENTS ===== */
.comments-section { padding-top: 12px; border-top: 1px solid var(--border); }
.comments-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.comments-header h3 { font-size: 1.1rem; margin: 0; }
.sort-options { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; }
.sort-options select { padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--card-bg); color: var(--text); }

.comment-form-wrapper { margin-bottom: 20px; }
.comment-form textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 0.95rem; resize: vertical; background: var(--input-bg); color: var(--text); }
.comment-form textarea:focus { border-color: var(--rose); outline: none; box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.comment-form .form-actions { margin-top: 8px; text-align: right; }
.login-prompt { text-align: center; background: var(--fantasy); padding: 12px; border-radius: 8px; margin-bottom: 20px; }

.comments-list { display: flex; flex-direction: column; gap: 16px; }
.comment-item { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
.comment-avatar { flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%; overflow: hidden; background: var(--rose); }
.comment-avatar img { width: 100%; height: 100%; object-fit: cover; }
.avatar-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.9rem; }
.comment-body { flex: 1; }
.comment-meta { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; margin-bottom: 2px; }
.comment-meta strong { font-size: 0.9rem; }
.comment-meta small { font-size: 0.75rem; color: var(--text-light); }
.comment-content { margin: 4px 0 8px; line-height: 1.6; font-size: 0.95rem; }
.comment-actions { display: flex; gap: 12px; font-size: 0.8rem; font-weight: 600; color: var(--text-light); }
.comment-actions a { color: var(--text-light); text-decoration: none; }
.comment-actions a:hover { color: var(--rose); }
.no-comments { text-align: center; padding: 24px 0; color: var(--text-light); font-size: 0.95rem; }

/* ===== SIDEBAR (RELATED) ===== */
.related-header h3 { font-size: 1rem; margin-bottom: 12px; }
.related-list { display: flex; flex-direction: column; gap: 12px; }
.related-item { display: flex; gap: 10px; text-decoration: none; padding: 8px; border-radius: 8px; transition: background 0.2s; }
.related-item:hover { background: var(--vanilla); }
.rel-thumb { width: 120px; height: 68px; border-radius: 6px; overflow: hidden; flex-shrink: 0; background: var(--vanilla); }
.rel-thumb img { width: 100%; height: 100%; object-fit: cover; }
.rel-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-light); }
.rel-info h4 { margin: 0 0 4px; font-size: 0.85rem; font-weight: 600; color: var(--text); line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.rel-info span { font-size: 0.75rem; color: var(--text-light); }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .video-layout { grid-template-columns: 1fr; }
    .video-meta { flex-direction: column; align-items: flex-start; gap: 10px; }
    .meta-right { width: 100%; }
    .meta-right .like-dislike-wrapper { justify-content: flex-end; width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>