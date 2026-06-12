<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// ===== FETCH BOOKS =====
$sql = "SELECT * FROM books WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'free') {
    $sql .= " AND is_free = 1";
} elseif ($filter === 'sale') {
    $sql .= " AND is_sale = 1";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Books';
?>
<?php require_once 'includes/header.php'; ?>

<div class="books-page">
    <div class="container">
        <!-- Page Header -->
        <div class="books-header">
            <h1>All Books</h1>
            <p>Explore Angella's writings — available for reading, download, or purchase.</p>
        </div>

        <!-- Search & Filter -->
        <div class="books-tools">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search books by title, author, or description..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="filter">
                    <option value="">All</option>
                    <option value="free" <?php echo $filter === 'free' ? 'selected' : ''; ?>>Free</option>
                    <option value="sale" <?php echo $filter === 'sale' ? 'selected' : ''; ?>>On Sale</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
            <div class="book-count"><?php echo count($books); ?> book<?php echo count($books) != 1 ? 's' : ''; ?></div>
        </div>

        <!-- Book Grid -->
        <?php if (count($books) > 0): ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                        <!-- Book Cover (Centered) -->
                        <div class="book-cover-wrapper">
                            <?php if ($book['cover_path']): ?>
                                <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="placeholder-cover">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>
                            <?php if ($book['is_free']): ?>
                                <span class="badge free">Free</span>
                            <?php elseif ($book['is_sale']): ?>
                                <span class="badge sale">Sale</span>
                            <?php endif; ?>
                        </div>

                        <!-- Book Details -->
                        <div class="book-details">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>

                            <!-- Full Description (Justified) with Read More toggle -->
                            <div class="book-description-wrapper">
                                <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                    <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                                </div>
                                <?php if (strlen($book['description'] ?? '') > 400): ?>
                                    <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                                <?php endif; ?>
                            </div>

                            <!-- Bottom: Price & Action -->
                            <div class="book-bottom">
                                <div class="book-price">
                                    <?php if ($book['is_free']): ?>
                                        <span class="free-text">Free</span>
                                    <?php elseif ($book['is_sale']): ?>
                                        <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                    <?php else: ?>
                                        <span>MWK <?php echo number_format($book['price'], 2); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-book-open"></i> Read
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No Books Found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new releases from Angella.'; ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtns = document.querySelectorAll('.toggle-desc-btn');
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const desc = document.getElementById('desc-' + id);
            if (desc.classList.contains('expanded')) {
                desc.classList.remove('expanded');
                this.textContent = 'Read More';
            } else {
                desc.classList.add('expanded');
                this.textContent = 'Show Less';
            }
        });
    });
});
</script>

<style>
.books-page { padding: 32px 0 60px; }
.books-header { text-align: center; margin-bottom: 32px; }
.books-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.books-header p { color: var(--text-light); font-size: 1.1rem; }

.books-tools { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 24px; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; flex: 1; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-form select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.search-form .btn { padding: 8px 16px; font-size: 0.85rem; }
.book-count { font-size: 0.9rem; color: var(--text-light); }

.books-grid { display: grid; grid-template-columns: 1fr; gap: 40px; max-width: 600px; margin: 0 auto; justify-content: center; }
@media (min-width: 768px) {
    .books-grid { grid-template-columns: repeat(auto-fill, minmax(500px, 1fr)); max-width: 1000px; }
}

.book-card { background: var(--card-bg); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid var(--border); transition: transform 0.3s ease, box-shadow 0.3s ease; display: flex; flex-direction: column; }
.book-card:hover { transform: translateY(-6px); box-shadow: 0 12px 40px rgba(0,0,0,0.10); }

.book-cover-wrapper { position: relative; width: 100%; height: 380px; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.book-cover-wrapper img { width: auto; height: 100%; object-fit: cover; display: block; max-width: 100%; }
.placeholder-cover { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 4rem; color: var(--rose); background: var(--vanilla); }

.badge { position: absolute; top: 16px; right: 16px; padding: 4px 16px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: white; }
.badge.free { background: #27ae60; }
.badge.sale { background: #e74c3c; }

.book-details { padding: 28px; flex: 1; display: flex; flex-direction: column; }
.book-details h3 { font-size: 1.6rem; margin: 0 0 4px 0; text-align: center; font-family: 'Playfair Display', serif; color: var(--text); }
.book-author { text-align: center; color: var(--text-light); font-size: 1rem; margin-bottom: 16px; }

.book-description-wrapper { flex: 1; }
.book-description { font-size: 1rem; line-height: 1.8; color: var(--text); text-align: justify; margin-bottom: 12px; max-height: 200px; overflow: hidden; transition: max-height 0.5s ease; }
.book-description.expanded { max-height: none; }
.toggle-desc-btn { background: none; border: none; color: var(--rose); font-size: 0.9rem; font-weight: 600; cursor: pointer; padding: 0; margin-bottom: 12px; }
.toggle-desc-btn:hover { text-decoration: underline; }

.book-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.book-price { font-weight: 700; font-size: 1.1rem; color: var(--text); }
.free-text { color: #27ae60; }
.sale-text { color: #e74c3c; }
.book-bottom .btn { padding: 10px 28px; border-radius: 30px; font-size: 0.95rem; }

.empty-state { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state i { display: block; margin-bottom: 16px; }
.empty-state h3 { font-size: 1.4rem; margin-bottom: 4px; }

@media (max-width: 480px) {
    .books-grid { max-width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>