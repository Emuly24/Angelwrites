<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$video_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch video
$stmt = $db->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$video_id]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    header('Location: ' . SITE_URL . '/videos.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE videos SET views = views + 1 WHERE id = ?");
$stmt->execute([$video_id]);

// ===== TRACKING: User watched this video =====
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO video_watches (user_id, video_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $video_id]);
}

$pageTitle = htmlspecialchars($video['title']) . ' — Video';
?>
<?php require_once 'includes/header.php'; ?>

<div class="video-watch-page">
    <div class="container">
        <div class="video-header">
            <a href="<?php echo SITE_URL; ?>/videos.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Videos
            </a>
            <h1><?php echo htmlspecialchars($video['title']); ?></h1>
            <?php if ($video['description']): ?>
                <p class="video-description"><?php echo nl2br(htmlspecialchars($video['description'])); ?></p>
            <?php endif; ?>
        </div>

        <div class="video-player">
            <?php if ($video['video_url']): ?>
                <!-- If hosted on YouTube/Vimeo, use embed -->
                <?php if (strpos($video['video_url'], 'youtube') !== false || strpos($video['video_url'], 'youtu.be') !== false): ?>
                    <iframe src="<?php echo str_replace('watch?v=', 'embed/', $video['video_url']); ?>" width="100%" height="500" frameborder="0" allowfullscreen></iframe>
                <?php elseif (strpos($video['video_url'], 'vimeo') !== false): ?>
                    <iframe src="<?php echo str_replace('vimeo.com/', 'player.vimeo.com/video/', $video['video_url']); ?>" width="100%" height="500" frameborder="0" allowfullscreen></iframe>
                <?php else: ?>
                    <!-- Local video file -->
                    <video controls width="100%" poster="<?php echo SITE_URL . '/' . ($video['thumbnail'] ?? ''); ?>">
                        <source src="<?php echo SITE_URL . '/' . $video['video_url']; ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php endif; ?>
            <?php else: ?>
                <div class="video-unavailable">
                    <i class="fas fa-video-slash"></i>
                    <p>Video content is not available at this time.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Share Section -->
        <div class="video-share">
            <span>Share:</span>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/video_watch.php?id=' . $video_id); ?>" target="_blank" class="share-btn facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Watch this video by Angella: ' . $video['title']); ?>&url=<?php echo urlencode(SITE_URL . '/video_watch.php?id=' . $video_id); ?>" target="_blank" class="share-btn twitter"><i class="fab fa-twitter"></i></a>
            <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Watch this video: ' . SITE_URL . '/video_watch.php?id=' . $video_id); ?>" target="_blank" class="share-btn whatsapp"><i class="fab fa-whatsapp"></i></a>
        </div>
    </div>
</div>

<style>
.video-watch-page { padding: 32px 0 60px; }
.video-header { margin-bottom: 24px; }
.video-header .back-link { color: var(--text-light); font-size: 0.95rem; text-decoration: none; display: inline-block; margin-bottom: 12px; }
.video-header .back-link:hover { color: var(--rose); }
.video-header h1 { font-size: 2rem; margin: 0 0 8px; }
.video-description { color: var(--text-light); font-size: 1.05rem; line-height: 1.6; margin-bottom: 0; }
.video-player { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); margin-bottom: 20px; }
.video-player video, .video-player iframe { display: block; width: 100%; }
.video-unavailable { text-align: center; padding: 60px 20px; color: var(--text-light); }
.video-unavailable i { font-size: 3rem; color: var(--rose); display: block; margin-bottom: 12px; }
.video-share { display: flex; align-items: center; gap: 10px; }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }
</style>

<?php require_once 'includes/footer.php'; ?>