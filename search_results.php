<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'relevance';

if (empty($q)) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$results = [];
$total_results = 0;

// Build search terms
$search_terms = explode(' ', $q);
$search_terms = array_filter($search_terms);
$search_terms = array_map('trim', $search_terms);
$like_terms = array_map(function($term) { return '%' . $term . '%'; }, $search_terms);

// Helper function to build WHERE clause
function buildSearchWhere($columns, $like_terms) {
    $where_parts = [];
    $params = [];
    foreach ($columns as $col) {
        $where_parts[] = "$col LIKE ?";
        foreach ($like_terms as $term) {
            $params[] = $term;
        }
    }
    $where = '(' . implode(' OR ', $where_parts) . ')';
    return ['where' => $where, 'params' => $params];
}

// Define search configurations
$search_configs = [];

if ($type === 'all' || $type === 'books') {
    $search_configs[] = [
        'type' => 'book',
        'table' => 'books',
        'columns' => ['title', 'author', 'description'],
        'select' => "SELECT 'book' as type, id, title, author as author, description, cover_path as image, created_at, NULL as slug, NULL as content, NULL as excerpt, 0 as comment_count, 0 as likes, 0 as loves, 0 as prays FROM books"
    ];
}
if ($type === 'all' || $type === 'poems') {
    $search_configs[] = [
        'type' => 'poem',
        'table' => 'poems',
        'columns' => ['title', 'intro', 'content'],
        'select' => "SELECT 'poem' as type, id, title, NULL as author, intro as description, image_path as image, created_at, NULL as slug, content, NULL as excerpt, 0 as comment_count, 0 as likes, 0 as loves, 0 as prays FROM poems"
    ];
}
if ($type === 'all' || $type === 'blog') {
    $search_configs[] = [
        'type' => 'blog',
        'table' => 'blog_posts',
        'columns' => ['title', 'content', 'excerpt', 'category'],
        'select' => "SELECT 'blog' as type, id, title, NULL as author, content as description, featured_image as image, created_at, slug, content, excerpt, comment_count, likes, loves, prays FROM blog_posts WHERE status = 'published'"
    ];
}
if ($type === 'all' || $type === 'reflections') {
    $search_configs[] = [
        'type' => 'reflection',
        'table' => 'reflections',
        'columns' => ['title', 'content', 'excerpt'],
        'select' => "SELECT 'reflection' as type, id, title, NULL as author, content as description, image_path as image, created_at, slug, content, excerpt, comment_count, likes, loves, prays FROM reflections"
    ];
}
if ($type === 'all' || $type === 'videos') {
    $search_configs[] = [
        'type' => 'video',
        'table' => 'videos',
        'columns' => ['title', 'description'],
        'select' => "SELECT 'video' as type, id, title, NULL as author, description, thumbnail as image, created_at, NULL as slug, NULL as content, NULL as excerpt, 0 as comment_count, 0 as likes, 0 as loves, 0 as prays FROM videos"
    ];
}

// Execute each search
foreach ($search_configs as $config) {
    $where_data = buildSearchWhere($config['columns'], $like_terms);
    
    // Count total for this config
    $count_sql = "SELECT COUNT(*) FROM " . $config['table'] . " WHERE " . $where_data['where'];
    if ($config['type'] === 'blog') {
        $count_sql = "SELECT COUNT(*) FROM " . $config['table'] . " WHERE status = 'published' AND " . $where_data['where'];
    }
    $stmt = $db->prepare($count_sql);
    $stmt->execute($where_data['params']);
    $count = $stmt->fetchColumn();
    $total_results += $count;

    if ($count > 0) {
        // Fetch results for this config
        $fetch_sql = $config['select'] . " WHERE " . $where_data['where'];
        if ($config['type'] === 'blog') {
            $fetch_sql = str_replace("WHERE status = 'published'", "", $config['select']) . " WHERE status = 'published' AND " . $where_data['where'];
        }
        $fetch_sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $fetch_params = array_merge($where_data['params'], [$limit, $offset]);
        $stmt = $db->prepare($fetch_sql);
        $stmt->execute($fetch_params);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

// Sorting
if ($sort === 'newest') {
    usort($results, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
} elseif ($sort === 'oldest') {
    usort($results, function($a, $b) { return strtotime($a['created_at']) - strtotime($b['created_at']); });
} elseif ($sort === 'most_commented') {
    usort($results, function($a, $b) { return ($b['comment_count'] ?? 0) - ($a['comment_count'] ?? 0); });
}

$total_pages = ceil($total_results / $limit);
$current_page = $page;
$query_params = http_build_query(['q' => $q, 'type' => $type, 'sort' => $sort]);

// Reading time calculation
function readingTime($content) {
    $word_count = str_word_count(strip_tags($content));
    $minutes = ceil($word_count / 200);
    return $minutes < 1 ? '1 min read' : $minutes . ' min read';
}

$pageTitle = 'Search Results: ' . htmlspecialchars($q);
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

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
            <form method="GET" action="<?php echo SITE_URL; ?>/search_results.php" class="filter-form" id="searchForm">
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
                        <option value="most_commented" <?php echo $sort === 'most_commented' ? 'selected' : ''; ?>>Most commented</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                <a href="<?php echo SITE_URL; ?>/search_results.php?q=<?php echo urlencode($q); ?>" class="btn btn-outline btn-sm">Clear Filters</a>
            </form>
        </div>

        <!-- Controls Bar -->
        <div class="search-controls">
            <div class="search-controls-right">
                <!-- View Toggle -->
                <button id="viewToggle" class="btn btn-sm btn-outline" onclick="toggleView()">
                    <i class="fas fa-th"></i>
                </button>

                <!-- Dark Mode Toggle -->
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>

        <!-- Results Grid -->
        <?php if (count($results) > 0): ?>
            <div class="results-grid" id="resultsGrid">
                <?php foreach ($results as $item): ?>
                    <?php
                    $link = '';
                    $image = $item['image'] ? SITE_URL . '/' . $item['image'] : '';
                    $title = htmlspecialchars($item['title']);
                    $description = htmlspecialchars(substr($item['description'] ?? $item['content'] ?? $item['excerpt'] ?? '', 0, 200));
                    if (strlen($description) >= 200) $description .= '...';
                    $read_time = isset($item['content']) ? readingTime($item['content']) : '';

                    switch ($item['type']) {
                        case 'book':
                            $link = SITE_URL . '/reader/reader.php?id=' . $item['id'];
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
                                <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>" loading="lazy">
                            <?php else: ?>
                                <div class="result-image-placeholder">
                                    <i class="fas fa-<?php echo $item['type'] === 'book' ? 'book' : ($item['type'] === 'poem' ? 'pen' : ($item['type'] === 'blog' ? 'blog' : ($item['type'] === 'reflection' ? 'church' : 'video'))); ?>"></i>
                                </div>
                            <?php endif; ?>
                            <span class="result-type-badge"><?php echo ucfirst($item['type']); ?></span>
                        </div>
                        <div class="result-content">
                            <div class="result-meta">
                                <?php if ($item['author']): ?>
                                    <span class="result-author">by <?php echo htmlspecialchars($item['author']); ?></span>
                                <?php endif; ?>
                                <?php if ($read_time): ?>
                                    <span class="result-reading-time"><i class="fas fa-clock"></i> <?php echo $read_time; ?></span>
                                <?php endif; ?>
                                <span class="result-date"><?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                            </div>
                            <h3><a href="<?php echo $link; ?>"><?php echo $title; ?></a></h3>
                            <p class="result-description"><?php echo $description; ?></p>
                            <div class="result-footer">
                                <div class="result-reactions">
                                    <?php if ($item['type'] === 'blog' || $item['type'] === 'reflection'): ?>
                                        <button class="reaction-btn" onclick="reactItem('<?php echo $item['type']; ?>', <?php echo $item['id']; ?>, 'like')">
                                            <i class="fas fa-thumbs-up"></i> <span id="likes-<?php echo $item['type']; ?>-<?php echo $item['id']; ?>"><?php echo $item['likes'] ?? 0; ?></span>
                                        </button>
                                        <button class="reaction-btn" onclick="reactItem('<?php echo $item['type']; ?>', <?php echo $item['id']; ?>, 'love')">
                                            ❤️ <span id="loves-<?php echo $item['type']; ?>-<?php echo $item['id']; ?>"><?php echo $item['loves'] ?? 0; ?></span>
                                        </button>
                                        <button class="reaction-btn" onclick="reactItem('<?php echo $item['type']; ?>', <?php echo $item['id']; ?>, 'pray')">
                                            🙏 <span id="prays-<?php echo $item['type']; ?>-<?php echo $item['id']; ?>"><?php echo $item['prays'] ?? 0; ?></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
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

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ===== JAVASCRIPT ===== -->
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

    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('searchTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('searchTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== VIEW TOGGLE =====
    const viewToggle = document.getElementById('viewToggle');
    const resultsGrid = document.getElementById('resultsGrid');
    const currentView = localStorage.getItem('searchView') || 'grid';
    if (currentView === 'list') {
        resultsGrid.classList.add('list-view');
        viewToggle.innerHTML = '<i class="fas fa-list"></i>';
    }

    window.toggleView = function() {
        resultsGrid.classList.toggle('list-view');
        const isList = resultsGrid.classList.contains('list-view');
        localStorage.setItem('searchView', isList ? 'list' : 'grid');
        viewToggle.innerHTML = isList ? '<i class="fas fa-list"></i>' : '<i class="fas fa-th"></i>';
    };

    // ===== REACTIONS =====
    window.reactItem = function(type, id, reaction) {
        fetch('<?php echo SITE_URL; ?>/search_reactions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'type=' + type + '&id=' + id + '&reaction=' + reaction
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('likes-' + type + '-' + id).textContent = data.likes;
                document.getElementById('loves-' + type + '-' + id).textContent = data.loves;
                document.getElementById('prays-' + type + '-' + id).textContent = data.prays;
            }
        });
    };
});
</script>

<style>
/* ===== BASE & DARK MODE ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.search-results-page { padding: 32px 0 60px; }
.search-header { text-align: center; margin-bottom: 24px; }
.search-header h1 { font-size: 2.2rem; margin-bottom: 4px; }
.search-header p { color: var(--text-light); font-size: 1.05rem; }
.search-meta { color: var(--text-light); font-size: 0.9rem; margin-top: 4px; }

/* ===== SEARCH FILTERS ===== */
.search-filters { margin-bottom: 24px; }
.filter-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; justify-content: center; background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
.filter-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.filter-group select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--input-bg); color: var(--text); font-size: 0.9rem; }
.filter-group select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.filter-form .btn { padding: 8px 20px; border-radius: 30px; }

/* ===== CONTROLS BAR ===== */
.search-controls { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-bottom: 16px; }

/* ===== RESULTS GRID ===== */
.results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; margin-bottom: 32px; }
.results-grid.list-view { grid-template-columns: 1fr; }
.result-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
.result-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }

.result-image { position: relative; height: 160px; background: var(--vanilla); overflow: hidden; }
.result-image img { width: 100%; height: 100%; object-fit: cover; }
.result-image-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--rose); }
.result-type-badge { position: absolute; top: 12px; left: 12px; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: white; background: var(--rose); }

.result-content { padding: 16px; flex: 1; display: flex; flex-direction: column; }
.result-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 4px; font-size: 0.85rem; color: var(--text-light); }
.result-author { font-weight: 500; }
.result-reading-time { display: flex; align-items: center; gap: 4px; }
.result-date { font-size: 0.8rem; }

.result-content h3 { font-size: 1.05rem; margin-bottom: 4px; }
.result-content h3 a { color: var(--text); text-decoration: none; }
.result-content h3 a:hover { color: var(--rose); }
.result-description { color: var(--text-light); font-size: 0.9rem; line-height: 1.5; margin-bottom: 12px; flex: 1; }

.result-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: auto; padding-top: 8px; border-top: 1px solid var(--border); }
.result-reactions { display: flex; gap: 6px; }
.reaction-btn { background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 0.85rem; transition: color 0.2s; display: flex; align-items: center; gap: 2px; }
.reaction-btn:hover { color: var(--rose); }
.result-footer .btn { padding: 4px 16px; font-size: 0.75rem; border-radius: 30px; }

/* ===== PAGINATION ===== */
.pagination { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== NO RESULTS ===== */
.no-results { text-align: center; padding: 60px 20px; color: var(--text-light); }
.no-results-icon { margin-bottom: 16px; }
.no-results h3 { font-size: 1.4rem; margin-bottom: 4px; }
.no-results-suggestions ul { list-style: none; padding: 0; margin: 8px 0 0; }
.no-results-suggestions ul li { padding: 2px 0; }
.no-results-suggestions a { color: var(--rose); font-weight: 500; text-decoration: none; }
.no-results-suggestions a:hover { text-decoration: underline; }

/* ===== BACK TO TOP ===== */
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .results-grid { grid-template-columns: 1fr; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-group { min-width: auto; }
}
</style>

<?php require_once 'includes/footer.php'; ?>