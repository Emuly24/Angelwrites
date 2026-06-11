<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // ADDED for Zoho SMTP

// ===== SEARCH QUERY =====
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'relevance'; // relevance, newest, oldest

// If no search query, redirect to home
if (empty($q)) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

// ===== SEARCH LOGIC =====
$results = [];
$total_results = 0;
$error = '';

// Build search terms
$search_terms = explode(' ', $q);
$search_terms = array_filter($search_terms); // Remove empty
$search_terms = array_map('trim', $search_terms);

// Escape for SQL
$like_terms = [];
foreach ($search_terms as $term) {
    $like_terms[] = '%' . $term . '%';
}

// Helper: Build WHERE clause for a table
function buildSearchWhere($table, $columns, $like_terms) {
    $where_parts = [];
    $params = [];
    foreach ($columns as $col) {
        $where_parts[] = "$table.$col LIKE ?";
        foreach ($like_terms as $term) {
            $params[] = $term;
        }
    }
    $where = '(' . implode(' OR ', $where_parts) . ')';
    return ['where' => $where, 'params' => $params];
}

// ===== SEARCH BY TYPE =====
if ($type === 'all' || $type === 'books') {
    $columns = ['title', 'author', 'description'];
    $where_data = buildSearchWhere('books', $columns, $like_terms);
    $sql = "SELECT 'book' as type, id, title, author as author, description, cover_path as image, created_at, NULL as slug, NULL as content, NULL as excerpt FROM books WHERE " . $where_data['where'];
    
    // Count
    $count_sql = "SELECT COUNT(*) FROM books WHERE " . $where_data['where'];
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;
    
    // Fetch
    if ($count > 0) {
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if ($type === 'all' || $type === 'poems') {
    $columns = ['title', 'intro', 'content'];
    $where_data = buildSearchWhere('poems', $columns, $like_terms);
    $sql = "SELECT 'poem' as type, id, title, NULL as author, intro as description, image_path as image, created_at, NULL as slug, content, NULL as excerpt FROM poems WHERE " . $where_data['where'];
    
    $count_sql = "SELECT COUNT(*) FROM poems WHERE " . $where_data['where'];
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;
    
    if ($count > 0) {
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if ($type === 'all' || $type === 'blog') {
    $columns = ['title', 'content', 'excerpt', 'category'];
    $where_data = buildSearchWhere('blog_posts', $columns, $like_terms);
    $sql = "SELECT 'blog' as type, id, title, NULL as author, content as description, featured_image as image, created_at, slug, content, excerpt FROM blog_posts WHERE status = 'published' AND " . $where_data['where'];
    
    $count_sql = "SELECT COUNT(*) FROM blog_posts WHERE status = 'published' AND " . $where_data['where'];
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;
    
    if ($count > 0) {
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if ($type === 'all' || $type === 'reflections') {
    $columns = ['title', 'content', 'excerpt'];
    $where_data = buildSearchWhere('reflections', $columns, $like_terms);
    $sql = "SELECT 'reflection' as type, id, title, NULL as author, content as description, image_path as image, created_at, NULL as slug, content, excerpt FROM reflections WHERE " . $where_data['where'];
    
    $count_sql = "SELECT COUNT(*) FROM reflections WHERE " . $where_data['where'];
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;
    
    if ($count > 0) {
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if ($type === 'all' || $type === 'videos') {
    $columns = ['title', 'description'];
    $where_data = buildSearchWhere('videos', $columns, $like_terms);
    $sql = "SELECT 'video' as type, id, title, NULL as author, description, thumbnail as image, created_at, NULL as slug, NULL as content, NULL as excerpt FROM videos WHERE " . $where_data['where'];
    
    $count_sql = "SELECT COUNT(*) FROM videos WHERE " . $where_data['where'];
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;
    
    if ($count > 0) {
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

// ===== SORT RESULTS =====
if ($sort === 'newest') {
    usort($results, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
} elseif ($sort === 'oldest') {
    usort($results, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });
}
// 'relevance' is default (keep as is, based on DB order)

// ===== PAGINATION =====
$total_pages = ceil($total_results / $limit);
$current_page = $page;

// ===== BUILD URL FOR PAGINATION =====
$query_params = http_build_query([
    'q' => $q,
    'type' => $type,
    'sort' => $sort
]);

$pageTitle = 'Search Results: ' . htmlspecialchars($q);
?>
<?php require_once 'includes/header.php'; ?>

<div class="search-results-page">
    <div class="container">
        <!-- Page Header -->
        <div class="search-header">
            <h1>Search Results</h1>
            <p>Showing results for "<strong><?php echo htmlspecialchars($q); ?></strong>"</p>
            <div class="search-meta">
                <span><?php echo number_format($total_results); ?> result<?php echo $total_results != 1 ? 's' : ''; ?> found</span>
            </div>
        </div>

        <!-- Search Filters -->
        <div class="search-filters">
            <form method="GET" action="<?php echo SITE_URL; ?>/search_results.php" class="filter-form">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                
                <div class="filter-group">
                    <label for="type">Content Type</label>
                    <select id="type" name="type">
                        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="books" <?php echo $type === 'books' ? 'selected' : ''; ?>>Books</option>
                        <option value="poems" <?php echo $type === 'poems' ? 'selected' : ''; ?>>Poems</option>
                        <option value="blog" <?php echo $type === 'blog' ? 'selected' : ''; ?>>Blog Posts</option>
                        <option value="reflections" <?php echo $type === 'reflections' ? 'selected' : ''; ?>>Reflections</option>
                        <option value="videos" <?php echo $type === 'videos' ? 'selected' : ''; ?>>Videos</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort">
                        <option value="relevance" <?php echo $sort === 'relevance' ? 'selected' : ''; ?>>Relevance</option>
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
            </form>
        </div>

        <!-- Results Grid -->
        <?php if (count($results) > 0): ?>
            <div class="results-grid">
                <?php foreach ($results as $item): ?>
                    <?php 
                    $link = '';
                    $image = $item['image'] ? SITE_URL . '/' . $item['image'] : '';
                    $title = htmlspecialchars($item['title']);
                    $description = htmlspecialchars(substr($item['description'] ?? $item['content'] ?? $item['excerpt'] ?? '', 0, 200));
                    if (strlen($description) >= 200) $description .= '...';
                    
                    switch ($item['type']) {
                        case 'book':
                            $link = SITE_URL . '/reader.php?id=' . $item['id'];
                            break;
                        case 'poem':
                            $link = SITE_URL . '/poem_view.php?id=' . $item['id'];
                            break;
                        case 'blog':
                            $link = SITE_URL . '/blog_post.php?slug=' . ($item['slug'] ?? $item['id']);
                            break;
                        case 'reflection':
                            $link = SITE_URL . '/reflection.php?id=' . $item['id'];
                            break;
                        case 'video':
                            $link = SITE_URL . '/video_watch.php?id=' . $item['id'];
                            break;
                    }
                    ?>
                    <div class="result-card">
                        <div class="result-image">
                            <?php if ($image): ?>
                                <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>">
                            <?php else: ?>
                                <div class="result-image-placeholder">
                                    <i class="fas fa-<?php echo $item['type'] === 'book' ? 'book' : ($item['type'] === 'poem' ? 'pen' : ($item['type'] === 'blog' ? 'blog' : ($item['type'] === 'reflection' ? 'church' : 'video'))); ?>"></i>
                                </div>
                            <?php endif; ?>
                            <span class="result-type-badge"><?php echo ucfirst($item['type']); ?></span>
                        </div>
                        <div class="result-content">
                            <h3><a href="<?php echo $link; ?>"><?php echo $title; ?></a></h3>
                            <?php if ($item['author']): ?>
                                <p class="result-author">by <?php echo htmlspecialchars($item['author']); ?></p>
                            <?php endif; ?>
                            <p class="result-description"><?php echo $description; ?></p>
                            <div class="result-footer">
                                <span class="result-date"><?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                                <a href="<?php echo $link; ?>" class="btn btn-sm btn-primary">View</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo $query_params; ?>&page=<?php echo $current_page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?php echo $query_params; ?>&page=<?php echo $i; ?>" class="page-link <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo $query_params; ?>&page=<?php echo $current_page + 1; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-results">
                <div class="no-results-icon">
                    <i class="fas fa-search" style="font-size: 4rem; color: var(--rose);"></i>
                </div>
                <h3>No results found</h3>
                <p>We couldn't find anything matching "<strong><?php echo htmlspecialchars($q); ?></strong>"</p>
                <div class="no-results-suggestions">
                    <p>Try:</p>
                    <ul>
                        <li>Checking for spelling errors</li>
                        <li>Using fewer keywords</li>
                        <li>Using more general terms</li>
                        <li>Browsing the <a href="<?php echo SITE_URL; ?>/books.php">Books</a>, <a href="<?php echo SITE_URL; ?>/poetry.php">Poetry</a>, or <a href="<?php echo SITE_URL; ?>/blog.php">Blog</a> sections</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== SEARCH RESULTS PAGE ===== */
.search-results-page { padding: 32px 0 60px; }

.search-header { text-align: center; margin-bottom: 24px; }
.search-header h1 { font-size: 2.2rem; margin-bottom: 4px; }
.search-header p { color: var(--text-light); font-size: 1.05rem; }
.search-meta { color: var(--text-light); font-size: 0.9rem; margin-top: 4px; }

/* ===== FILTERS ===== */
.search-filters { margin-bottom: 24px; }
.filter-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; justify-content: center; background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
.filter-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.filter-group select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
.filter-group select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.filter-form .btn { padding: 8px 20px; border-radius: 30px; }

/* ===== RESULTS GRID ===== */
.results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; margin-bottom: 32px; }

.result-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
.result-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }

.result-image { position: relative; height: 180px; background: var(--vanilla); overflow: hidden; }
.result-image img { width: 100%; height: 100%; object-fit: cover; }
.result-image-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--rose); }
.result-type-badge { position: absolute; top: 12px; left: 12px; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: white; background: var(--rose); }

.result-content { padding: 16px; flex: 1; display: flex; flex-direction: column; }
.result-content h3 { font-size: 1.05rem; margin-bottom: 4px; }
.result-content h3 a { color: var(--text); text-decoration: none; }
.result-content h3 a:hover { color: var(--rose); }
.result-author { color: var(--text-light); font-size: 0.85rem; margin-bottom: 6px; }
.result-description { color: var(--text-light); font-size: 0.9rem; line-height: 1.5; margin-bottom: 12px; flex: 1; }
.result-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 8px; border-top: 1px solid var(--border); }
.result-date { font-size: 0.8rem; color: var(--text-light); }
.result-footer .btn { padding: 4px 16px; font-size: 0.75rem; border-radius: 30px; }

/* ===== NO RESULTS ===== */
.no-results { text-align: center; padding: 60px 20px; color: var(--text-light); }
.no-results-icon { margin-bottom: 16px; }
.no-results h3 { font-size: 1.4rem; margin-bottom: 4px; }
.no-results-suggestions ul { list-style: none; padding: 0; margin: 8px 0 0; }
.no-results-suggestions ul li { padding: 2px 0; }
.no-results-suggestions a { color: var(--rose); font-weight: 500; text-decoration: none; }
.no-results-suggestions a:hover { text-decoration: underline; }

/* ===== PAGINATION ===== */
.pagination { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .results-grid { grid-template-columns: 1fr; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-group { min-width: auto; }
}
</style>

<?php require_once 'includes/footer.php'; ?>