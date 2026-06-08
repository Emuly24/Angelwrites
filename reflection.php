<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$reflection_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch reflection
$stmt = $db->prepare("SELECT * FROM reflections WHERE id = ?");
$stmt->execute([$reflection_id]);
$reflection = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reflection) {
    header('Location: ' . SITE_URL . '/reflections.php');
    exit;
}

// Increment view count
$stmt = $db->prepare("UPDATE reflections SET views = views + 1 WHERE id = ?");
$stmt->execute([$reflection_id]);

// ===== TRACKING: User read this reflection =====
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("INSERT OR IGNORE INTO reflection_reads (user_id, reflection_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $reflection_id]);
}

$pageTitle = htmlspecialchars($reflection['title']) . ' — Reflection';
?>
<?php require_once 'includes/header.php'; ?>

<div class="reflection-page">
    <div class="container">
        <div class="reflection-header">
            <a href="<?php echo SITE_URL; ?>/reflections.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Reflections
            </a>
            <h1><?php echo htmlspecialchars($reflection['title']); ?></h1>
            <?php if ($reflection['excerpt']): ?>
                <p class="reflection-excerpt"><?php echo htmlspecialchars($reflection['excerpt']); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($reflection['image_path']): ?>
            <div class="reflection-image">
                <img src="<?php echo SITE_URL . '/' . $reflection['image_path']; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>">
            </div>
        <?php endif; ?>

        <div class="reflection-content">
            <?php echo nl2br(htmlspecialchars($reflection['content'])); ?>
        </div>

        <!-- Share Section -->
        <div class="reflection-share">
            <span>Share:</span>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . '/reflection.php?id=' . $reflection_id); ?>" target="_blank" class="share-btn facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Read this reflection by Angella: ' . $reflection['title']); ?>&url=<?php echo urlencode(SITE_URL . '/reflection.php?id=' . $reflection_id); ?>" target="_blank" class="share-btn twitter"><i class="fab fa-twitter"></i></a>
            <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Read this reflection: ' . SITE_URL . '/reflection.php?id=' . $reflection_id); ?>" target="_blank" class="share-btn whatsapp"><i class="fab fa-whatsapp"></i></a>
        </div>
    </div>
</div>

<style>
.reflection-page { padding: 32px 0 60px; }
.reflection-header { margin-bottom: 24px; }
.reflection-header .back-link { color: var(--text-light); font-size: 0.95rem; text-decoration: none; display: inline-block; margin-bottom: 12px; }
.reflection-header .back-link:hover { color: var(--rose); }
.reflection-header h1 { font-size: 2rem; margin: 0 0 8px; }
.reflection-excerpt { font-size: 1.05rem; color: var(--text-light); line-height: 1.6; font-style: italic; margin-bottom: 0; }
.reflection-image { margin-bottom: 24px; border-radius: 12px; overflow: hidden; }
.reflection-image img { width: 100%; height: auto; display: block; }
.reflection-content { line-height: 1.9; font-size: 1.05rem; margin-bottom: 24px; }
.reflection-content p { margin-bottom: 16px; }
.reflection-share { display: flex; align-items: center; gap: 10px; }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }
</style>

<?php require_once 'includes/footer.php'; ?>