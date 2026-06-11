<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';

// ===== FETCH VIDEOS (with search & filter) =====
$sql = "SELECT * FROM videos WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_type && in_array($filter_type, ['sermon', 'teaching', 'testimony', 'other'])) {
    $sql .= " AND type = ?";
    $params[] = $filter_type;
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH UNIQUE VIDEO TYPES FOR FILTER =====
$stmt = $db->query("SELECT DISTINCT type FROM videos ORDER BY type ASC");
$types = $stmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Videos';
?>
<?php require_once 'includes/header.php'; ?>

<div class="videos-page">
    <div class="container">
        <!-- Page Header -->
        <div class="videos-header">
            <h1>Videos</h1>
            <p>Watch, learn, and be inspired — Angella's video messages and teachings.</p>
        </div>

        <!-- Search & Filter -->
        <div class="videos-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search videos..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="type">
                    <option value="">All types</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
        </div>

        <!-- Videos Grid -->
        <?php if (count($videos) > 0): ?>
            <div class="videos-grid">
                <?php foreach ($videos as $video): ?>
                    <div class="video-card">
                        <div class="video-thumbnail">
                            <?php if ($video['thumbnail']): ?>
                                <img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                            <?php else: ?>
                                <div class="video-thumbnail-placeholder">
                                    <i class="fas fa-video"></i>
                                </div>
                            <?php endif; ?>
                            <div class="play-overlay">
                                <i class="fas fa-play-circle"></i>
                            </div>
                            <span class="video-type-badge"><?php echo htmlspecialchars(ucfirst($video['type'] ?? 'video')); ?></span>
                        </div>
                        <div class="video-info">
                            <h3><a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>"><?php echo htmlspecialchars($video['title']); ?></a></h3>
                            <?php if ($video['description']): ?>
                                <p class="video-description"><?php echo htmlspecialchars(substr($video['description'], 0, 100)); ?>...</p>
                            <?php endif; ?>
                            <div class="video-footer">
                                <span class="video-date"><?php echo date('M j, Y', strtotime($video['created_at'])); ?></span>
                                <span class="video-views"><i class="fas fa-eye"></i> <?php echo number_format($video['views'] ?? 0); ?></span>
                                <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="btn btn-sm btn-primary">Watch</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-video" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No videos found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new videos from Angella.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== EXISTING STYLES (PRESERVED) ===== */
.videos-page { padding: 32px 0; }
.videos-header { text-align: center; margin-bottom: 32px; }
.videos-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.videos-header p { color: var(--text-light); font-size: 1.1rem; }
.videos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
.video-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); transition: transform var(--transition), box-shadow var(--transition); }
.video-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.video-thumbnail { position: relative; height: 180px; overflow: hidden; background: var(--vanilla); }
.video-thumbnail img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.video-card:hover .video-thumbnail img { transform: scale(1.05); }
.video-thumbnail-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--rose); }
.play-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2); color: white; font-size: 3rem; opacity: 0.7; transition: opacity 0.3s; }
.video-card:hover .play-overlay { opacity: 1; }
.video-type-badge { position: absolute; top: 12px; left: 12px; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; background: var(--rose); color: white; }
.video-info { padding: 16px; }
.video-info h3 { font-size: 1.1rem; margin-bottom: 4px; }
.video-info h3 a { color: var(--text); text-decoration: none; }
.video-info h3 a:hover { color: var(--rose); }
.video-description { color: var(--text-light); font-size: 0.9rem; line-height: 1.5; margin-bottom: 8px; }
.video-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 4px; font-size: 0.85rem; color: var(--text-light); }
.video-views { display: flex; align-items: center; gap: 4px; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state h3 { font-size: 1.4rem; margin-bottom: 6px; }

/* ===== NEW SEARCH TOOLS STYLES ===== */
.videos-tools { margin-bottom: 24px; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.search-form input, .search-form select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus, .search-form select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form input { flex: 1; min-width: 200px; }
.search-form .btn-sm { padding: 8px 16px; }
@media (max-width: 480px) { .videos-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once 'includes/footer.php'; ?>