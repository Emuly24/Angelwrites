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

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:3px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="poetry-page">
    <div class="container">
        <!-- Page Header -->
        <div class="poetry-header">
            <div class="header-content">
                <h1>Poetry</h1>
                <p>Words that speak to the soul — discover Angella's poetic collection.</p>
            </div>
            <div class="header-decoration">
                <span class="decoration-line"></span>
            </div>
        </div>

        <!-- Search & Count -->
        <div class="poetry-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search poems by title or theme..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Clear</a>
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
                        <?php else: ?>
                            <div class="poem-thumbnail placeholder">
                                <i class="fas fa-feather-alt"></i>
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
                                <div class="poem-meta">
                                    <span class="poem-date"><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($poem['created_at'])); ?></span>
                                    <span class="poem-views"><i class="fas fa-eye"></i> <?php echo number_format($poem['view_count'] ?? 0); ?></span>
                                </div>
                                <div class="poem-actions">
                                    <?php if ($poem['audio_path']): ?>
                                        <span class="audio-indicator"><i class="fas fa-headphones"></i> Audio</span>
                                    <?php endif; ?>
                                    <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-book-open"></i> Read
                                    </a>
                                </div>
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

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== BACK TO TOP BUTTON =====
    const backToTopBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 400) {
            backToTopBtn.style.display = 'flex';
        } else {
            backToTopBtn.style.display = 'none';
        }
    });
});
</script>

<style>
/* ===== BRAND VARIABLES ===== */
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

/* ===== TYPOGRAPHY ===== */
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); }
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
.rose-text { color:var(--rose); }

/* ===== BUTTONS ===== */
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
.btn-sm { padding:8px 20px; font-size:0.85rem; }

/* ===== PAGE LAYOUT ===== */
.poetry-page { padding:40px 0 80px; }

/* ===== HEADER ===== */
.poetry-header { text-align:center; margin-bottom:32px; position:relative; }
.poetry-header h1 { font-size:2.8rem; margin-bottom:4px; }
.poetry-header p { color:var(--text-light); font-size:1.15rem; max-width:600px; margin:0 auto; }
.header-decoration { display:flex; justify-content:center; margin-top:12px; }
.decoration-line { width:60px; height:3px; background:var(--rose); border-radius:4px; }

/* ===== TOOLS ===== */
.poetry-tools { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:16px; margin-bottom:32px; }
.search-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; flex:1; min-width:200px; }
.search-form input { flex:1; min-width:180px; padding:12px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.95rem; background:var(--card-bg); color:var(--text); transition:border-color 0.2s; }
.search-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.search-form .btn { padding:8px 20px; font-size:0.85rem; border-radius:50px; }
.poem-count { font-size:0.9rem; color:var(--text-light); font-weight:500; }

/* ===== POEM GRID ===== */
.poems-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:24px; }
.poem-card {
    background:var(--card-bg); border-radius:20px; overflow:hidden;
    border:1px solid var(--border); box-shadow:var(--shadow);
    transition:all var(--transition); display:flex; flex-direction:column;
}
.poem-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-hover); border-color:var(--rose-light); }
.poem-thumbnail {
    width:100%; height:200px; overflow:hidden; background:var(--vanilla);
    display:flex; align-items:center; justify-content:center;
}
.poem-thumbnail img { width:100%; height:100%; object-fit:cover; transition:transform 0.6s; }
.poem-card:hover .poem-thumbnail img { transform:scale(1.08); }
.poem-thumbnail.placeholder { background:var(--vanilla); }
.poem-thumbnail.placeholder i { font-size:3.5rem; color:var(--rose-light); opacity:0.6; }

.poem-card-content { padding:24px; display:flex; flex-direction:column; flex:1; }
.poem-card-content h3 { font-size:1.3rem; margin-bottom:8px; line-height:1.3; }
.poem-card-content h3 a { color:var(--text); text-decoration:none; transition:color 0.2s; }
.poem-card-content h3 a:hover { color:var(--rose); }

.poem-intro-preview {
    background:var(--vanilla); padding:12px 16px; border-radius:12px;
    margin:4px 0 12px; border-left:4px solid var(--rose);
}
.poem-intro-preview .intro-label { display:block; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--rose); margin-bottom:4px; }
.poem-intro-preview p { font-style:italic; color:var(--text); font-size:0.95rem; line-height:1.5; margin:0; }

.poem-footer {
    margin-top:auto; padding-top:12px; border-top:1px solid var(--border);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;
}
.poem-meta { display:flex; gap:12px; font-size:0.8rem; color:var(--text-light); }
.poem-meta i { margin-right:2px; }
.poem-actions { display:flex; gap:8px; align-items:center; }
.audio-indicator { font-size:0.8rem; color:var(--rose); display:flex; align-items:center; gap:4px; background:rgba(219,161,162,0.1); padding:2px 10px; border-radius:20px; font-weight:500; }
.poem-footer .btn { padding:4px 14px; font-size:0.75rem; border-radius:50px; }

/* ===== PAGINATION ===== */
.pagination { display:flex; justify-content:center; gap:6px; margin-top:32px; flex-wrap:wrap; }
.page-link {
    display:inline-flex; align-items:center; justify-content:center;
    padding:8px 16px; border-radius:12px; background:var(--card-bg);
    border:1px solid var(--border); color:var(--text); font-size:0.9rem;
    transition:all 0.2s; min-width:40px; text-decoration:none;
}
.page-link:hover { border-color:var(--rose); transform:translateY(-2px); }
.page-link.active { background:var(--rose); color:white; border-color:var(--rose); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-light); }
.empty-state h3 { font-size:1.4rem; margin-bottom:6px; color:var(--dark); }
.empty-state p { font-size:0.95rem; }

/* ===== BACK TO TOP ===== */
.back-to-top {
    position:fixed; bottom:24px; right:24px; width:44px; height:44px;
    border-radius:50%; background:var(--rose); color:white; border:none;
    font-size:1.2rem; display:none; align-items:center; justify-content:center;
    box-shadow:0 4px 12px rgba(0,0,0,0.15); cursor:pointer; transition:transform 0.2s; z-index:1000;
}
.back-to-top:hover { transform:scale(1.05); }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) {
    .poetry-header h1 { font-size:2.2rem; }
    .poetry-tools { flex-direction:column; align-items:stretch; }
    .search-form { flex-direction:column; }
    .search-form input { width:100%; }
    .poem-count { text-align:center; }
}
@media (max-width:480px) {
    .poetry-header h1 { font-size:1.8rem; }
    .poems-grid { grid-template-columns:1fr; }
    .poem-thumbnail { height:160px; }
    .poem-footer { flex-direction:column; align-items:flex-start; }
    .poem-actions { width:100%; justify-content:space-between; }
}
</style>

<?php require_once 'includes/footer.php'; ?>