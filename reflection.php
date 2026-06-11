<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest'; // newest, oldest, most_viewed

// ===== FETCH TOTAL REFLECTIONS =====
$count_sql = "SELECT COUNT(*) FROM reflections WHERE 1=1";
$count_params = [];
if ($search) {
    $count_sql .= " AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_reflections = $stmt->fetchColumn();
$total_pages = ceil($total_reflections / $limit);

// ===== FETCH REFLECTIONS =====
$sql = "SELECT * FROM reflections WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Sorting
switch ($sort) {
    case 'oldest':
        $sql .= " ORDER BY created_at ASC";
        break;
    case 'most_viewed':
        $sql .= " ORDER BY views DESC";
        break;
    default: // newest
        $sql .= " ORDER BY created_at DESC";
        break;
}

$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ADMIN NOTIFICATION ON NEW REFLECTION (handled in reflection_editor.php, not here) =====

$pageTitle = $search ? 'Search Results: ' . htmlspecialchars($search) : 'Christian Reflections';
?>
<?php require_once 'includes/header.php'; ?>

<div class="reflections-page">
    <div class="container">
        <!-- Page Header -->
        <div class="reflections-header">
            <h1>Christian Reflections</h1>
            <p>Faith, hope, and encouragement for everyday life — written by Angella.</p>
        </div>

        <!-- Search & Sort -->
        <div class="reflections-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search reflections..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="sort">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                    <option value="most_viewed" <?php echo $sort === 'most_viewed' ? 'selected' : ''; ?>>Most viewed</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
            <div class="reflection-count">
                <span><?php echo $total_reflections; ?> reflection<?php echo $total_reflections != 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <!-- Reflections Grid -->
        <?php if (count($reflections) > 0): ?>
            <div class="reflections-grid">
                <?php foreach ($reflections as $reflection): ?>
                    <div class="reflection-card">
                        <?php if ($reflection['image_path']): ?>
                            <div class="reflection-image">
                                <img src="<?php echo SITE_URL . '/' . $reflection['image_path']; ?>" alt="<?php echo htmlspecialchars($reflection['title']); ?>">
                            </div>
                        <?php endif; ?>
                        <div class="reflection-content">
                            <div class="reflection-meta">
                                <span class="reflection-date"><?php echo date('M j, Y', strtotime($reflection['created_at'])); ?></span>
                                <span class="reflection-views"><i class="fas fa-eye"></i> <?php echo number_format($reflection['views'] ?? 0); ?></span>
                            </div>
                            <h3><a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>"><?php echo htmlspecialchars($reflection['title']); ?></a></h3>
                            <?php if ($reflection['excerpt']): ?>
                                <p class="reflection-excerpt"><?php echo htmlspecialchars(substr($reflection['excerpt'], 0, 150)); ?>...</p>
                            <?php else: ?>
                                <p class="reflection-excerpt"><?php echo htmlspecialchars(substr($reflection['content'], 0, 150)); ?>...</p>
                            <?php endif; ?>
                            <div class="reflection-footer">
                                <a href="<?php echo SITE_URL; ?>/reflection.php?id=<?php echo $reflection['id']; ?>" class="btn btn-sm btn-outline">Read Full Reflection →</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-church" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No reflections found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new reflections from Angella.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== EXISTING STYLES (PRESERVED) ===== */
.reflections-page { padding: 32px 0; }
.reflections-header { text-align: center; margin-bottom: 32px; }
.reflections-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.reflections-header p { color: var(--text-light); font-size: 1.1rem; }
.reflections-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; }
.reflection-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); border: 1px solid var(--border); transition: transform var(--transition), box-shadow var(--transition); }
.reflection-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.reflection-image { width: 100%; height: 180px; overflow: hidden; }
.reflection-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.reflection-card:hover .reflection-image img { transform: scale(1.05); }
.reflection-content { padding: 20px; }
.reflection-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 0.85rem; color: var(--text-light); }
.reflection-views { display: flex; align-items: center; gap: 4px; }
.reflection-content h3 { font-size: 1.15rem; margin-bottom: 6px; }
.reflection-content h3 a { color: var(--text); text-decoration: none; }
.reflection-content h3 a:hover { color: var(--rose); }
.reflection-excerpt { color: var(--text-light); font-size: 0.95rem; line-height: 1.6; margin-bottom: 12px; }
.reflection-footer { margin-top: 8px; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state h3 { font-size: 1.4rem; margin-bottom: 6px; }

/* ===== NEW SEARCH/TOOLS/PAGINATION STYLES ===== */
.reflections-tools { margin-bottom: 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; flex: 1; }
.search-form input, .search-form select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus, .search-form select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form input { flex: 1; min-width: 200px; }
.search-form .btn-sm { padding: 8px 16px; }
.reflection-count { font-size: 0.9rem; color: var(--text-light); white-space: nowrap; }
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 32px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all var(--transition); min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }
@media (max-width: 480px) { .reflections-grid { grid-template-columns: 1fr; } .reflections-tools { flex-direction: column; align-items: stretch; } .search-form { flex-direction: column; } }
</style>

<?php require_once 'includes/footer.php'; ?>