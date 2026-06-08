<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Fetch all books, newest first
$stmt = $db->query("SELECT * FROM books ORDER BY created_at DESC");
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

        <!-- Book Grid -->
        <?php if (count($books) > 0): ?>
            <div class="books-grid">
                <?php foreach ($books as $book): ?>
                    <div class="book-card">
                        <!-- Book Cover -->
                        <div class="book-cover">
                            <?php if ($book['cover_path']): ?>
                                <img src="<?php echo SITE_URL . '/' . $book['cover_path']; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                            <?php else: ?>
                                <div class="book-cover-placeholder">
                                    <i class="fas fa-book"></i>
                                </div>
                            <?php endif; ?>

                            <!-- Badges -->
                            <?php if ($book['is_free']): ?>
                                <span class="badge free">Free</span>
                            <?php elseif ($book['is_sale']): ?>
                                <span class="badge sale">Sale</span>
                            <?php endif; ?>
                        </div>

                        <!-- Book Info -->
                        <div class="book-info">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                            <p class="book-desc"><?php echo htmlspecialchars(substr($book['description'] ?? '', 0, 100)); ?><?php if (strlen($book['description'] ?? '') > 100) echo '...'; ?></p>
                            <div class="book-bottom">
                                <div class="book-price">
                                    <?php if ($book['is_free']): ?>
                                        <span class="free-text">Free</span>
                                    <?php elseif ($book['is_sale']): ?>
                                        <span class="sale-text">$<?php echo number_format($book['price'], 2); ?></span>
                                    <?php else: ?>
                                        <span>$<?php echo number_format($book['price'], 2); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?php echo SITE_URL; ?>/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">
                                    <?php if ($book['file_path']): ?>
                                        <i class="fas fa-book-open"></i> Read
                                    <?php else: ?>
                                        <i class="fas fa-info-circle"></i> Details
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No Books Yet</h3>
                <p>Check back soon for new releases from Angella.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.books-page {
    padding: 32px 0;
}

.books-header {
    text-align: center;
    margin-bottom: 32px;
}
.books-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.books-header p {
    color: var(--text-light);
    font-size: 1.1rem;
}

.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 24px;
}

.book-card {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    transition: transform var(--transition), box-shadow var(--transition);
    display: flex;
    flex-direction: column;
}
.book-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
}

.book-cover {
    position: relative;
    height: 240px;
    background: var(--vanilla);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.book-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.book-cover-placeholder {
    font-size: 4rem;
    color: var(--rose);
}

.badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 16px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.badge.free { background: #2ecc71; color: white; }
.badge.sale { background: #e67e22; color: white; }

.book-info {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.book-info h3 {
    font-size: 1.1rem;
    margin-bottom: 2px;
}
.book-author {
    font-size: 0.85rem;
    color: var(--text-light);
}
.book-desc {
    font-size: 0.9rem;
    color: var(--text-light);
    margin: 6px 0 12px;
    line-height: 1.4;
    flex: 1;
}

.book-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
}
.book-price {
    font-weight: 700;
    font-size: 1rem;
}
.free-text { color: #2ecc71; }
.sale-text { color: #e67e22; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin-bottom: 6px;
}

@media (max-width: 480px) {
    .books-grid {
        grid-template-columns: 1fr 1fr;
    }
    .book-cover {
        height: 160px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>