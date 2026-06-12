<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

// ===== PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// ===== SEARCH =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ===== FETCH TOTAL POEMS =====
$count_sql = "SELECT COUNT(*) FROM poems";
$count_params = [];
if ($search) {
    $count_sql .= " WHERE title LIKE ? OR intro LIKE ? OR content LIKE ?";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_poems = $stmt->fetchColumn();
$total_pages = ceil($total_poems / $limit);

// ===== FETCH POEMS =====
$sql = "SELECT * FROM poems";
$params = [];
if ($search) {
    $sql .= " WHERE title LIKE ? OR intro LIKE ? OR content LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Poetry';
?>
<?php require_once 'includes/header.php'; ?>

<div class="poetry-page">
    <div class="container">
        <!-- Page Header -->
        <div class="poetry-header">
            <h1>Poetry</h1>
            <p>Words that speak to the soul — discover Angella's poetic collection.</p>
        </div>

        <!-- Search & Count -->
        <div class="poetry-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search poems..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
            <div class="poem-count"><?php echo $total_poems; ?> poem<?php echo $total_poems != 1 ? 's' : ''; ?></div>
        </div>

        <!-- Poem Grid -->
        <?php if (count($poems) > 0): ?>
            <div class="poems-grid">
                <?php foreach ($poems as $poem): ?>
                    <div class="poem-card">
                        <?php if ($poem['image_path']): ?>
                            <div class="poem-thumbnail">
                                <img src="<?php echo SITE_URL . '/' . $poem['image_path']; ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="poem-card-content">
                            <h3><a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>"><?php echo htmlspecialchars($poem['title']); ?></a></h3>
                            <?php if ($poem['intro']): ?>
                                <div class="poem-intro-preview">
                                    <span class="intro-label">✧ Purpose</span>
                                    <p><?php echo htmlspecialchars(substr($poem['intro'], 0, 120)); ?><?php if (strlen($poem['intro']) > 120) echo '...'; ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="poem-footer">
                                <span class="poem-date"><?php echo date('M j, Y', strtotime($poem['created_at'])); ?></span>
                                <span class="poem-views"><i class="fas fa-eye"></i> <?php echo number_format($poem['view_count'] ?? 0); ?></span>
                                <?php if ($poem['audio_path']): ?>
                                    <span class="audio-indicator"><i class="fas fa-headphones"></i> Audio</span>
                                <?php endif; ?>
                                <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-outline">Read →</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-pen-fancy" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No poems found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new poetry from Angella.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.poetry-page { padding: 32px 0 60px; }
.poetry-header { text-align: center; margin-bottom: 32px; }
.poetry-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.poetry-header p { color: var(--text-light); font-size: 1.1rem; }

.poetry-tools { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 24px; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; flex: 1; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }
.poem-count { font-size: 0.9rem; color: var(--text-light); }

.poems-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; }
.poem-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.3s, box-shadow 0.3s; display: flex; flex-direction: column; }
.poem-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.poem-card-content { padding: 24px; display: flex; flex-direction: column; flex: 1; }
.poem-card-content h3 { font-family: 'Playfair Display', serif; font-size: 1.3rem; margin-bottom: 8px; color: var(--dark); }
.poem-card-content h3 a { color: var(--text); text-decoration: none; }
.poem-card-content h3 a:hover { color: var(--rose); }
.poem-intro-preview { background: var(--vanilla); padding: 12px 16px; border-radius: 8px; margin: 8px 0 12px; border-left: 3px solid var(--rose); }
.poem-intro-preview .intro-label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--rose); margin-bottom: 4px; }
.poem-intro-preview p { font-style: italic; color: var(--text); font-size: 0.95rem; line-height: 1.5; margin: 0; }
.poem-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 4px; }
.poem-date { font-size: 0.8rem; color: var(--text-light); }
.poem-views { font-size: 0.8rem; color: var(--text-light); margin-left: 4px; }
.audio-indicator { color: var(--rose); font-size: 0.8rem; display: flex; align-items: center; gap: 4px; }
.poem-thumbnail { width: 100%; height: 180px; overflow: hidden; border-radius: 12px 12px 0 0; }
.poem-thumbnail img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.poem-card:hover .poem-thumbnail img { transform: scale(1.05); }
.poem-footer .btn { padding: 4px 14px; font-size: 0.75rem; }

.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

.empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state h3 { font-size: 1.4rem; margin-bottom: 6px; }

@media (max-width: 480px) {
    .poems-grid { grid-template-columns: 1fr; }
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>