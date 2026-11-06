<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// RF1: tag filter (?tag=), sort that works, plain-text excerpts, the shared pager and reactions that
// reach a real endpoint (reflections_reactions.php).
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/newsletter.php'; // CSRF token for the reaction buttons
$search = isset($_GET['search']) ? mb_substr(trim((string)$_GET['search']), 0, 100) : '';
$tag = isset($_GET['tag']) ? mb_substr(trim((string)$_GET['tag']), 0, 50) : '';
$sort = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'most_viewed', 'most_commented', 'most_loved'], true) ? $_GET['sort'] : 'newest';

// F71: admin/reflection_editor.php saves reflections as blog_posts (category 'Christian
// Reflections'); this page read the separate reflections table, which nothing writes, so
// published reflections never appeared. Read the published reflection posts instead; the
// alias keeps the column names the rest of the page uses (image_path = featured_image).
$refl_src = "(SELECT id, title, content, excerpt, featured_image AS image_path, created_at, updated_at, views, tags, slug, featured, comment_count, likes, loves, prays FROM blog_posts WHERE status = 'published' AND category = 'Christian Reflections') reflections";

// ===== TAGS (with how many reflections use each) =====
$tag_counts = [];
foreach ($db->query("SELECT tags FROM $refl_src WHERE tags != ''")->fetchAll(PDO::FETCH_COLUMN) as $list) {
    foreach (array_unique(array_filter(array_map('trim', explode(',', $list)))) as $t) {
        $k = mb_strtolower($t);
        if (!isset($tag_counts[$k])) $tag_counts[$k] = ['label' => $t, 'n' => 0];
        $tag_counts[$k]['n']++;
    }
}
uasort($tag_counts, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcasecmp($a['label'], $b['label']); });
$tags = array_column(array_slice($tag_counts, 0, 30), 'label');

// ===== FILTERS =====
$where = []; $params = [];
if ($search !== '') {
    $where[] = "(title LIKE ? OR content LIKE ? OR excerpt LIKE ? OR tags LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($tag !== '') {
    // exact tag in the comma-separated list (spaces after commas ignored)
    $where[] = "(',' || lower(replace(replace(tags, ', ', ','), ' ,', ',')) || ',') LIKE ?";
    $params[] = '%,' . mb_strtolower($tag) . ',%';
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$stmt = $db->prepare("SELECT COUNT(*) FROM $refl_src$where_sql");
$stmt->execute($params);
$total_reflections = (int)$stmt->fetchColumn();
[$page, $limit] = aw_page(9);
[$page, $offset] = aw_clamp($total_reflections, $page, $limit);
$order = ['oldest' => 'created_at ASC', 'most_viewed' => 'views DESC, created_at DESC', 'most_commented' => 'comment_count DESC, created_at DESC', 'most_loved' => '(COALESCE(likes,0) + COALESCE(loves,0) + COALESCE(prays,0)) DESC, created_at DESC'][$sort] ?? 'created_at DESC, id DESC';
$stmt = $db->prepare("SELECT * FROM $refl_src$where_sql ORDER BY $order LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$limit, $offset]));
$reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== SIDEBAR + FEATURED =====
$recent_reflections = $db->query("SELECT id, title, slug, created_at, comment_count FROM $refl_src ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$featured_reflections = $db->query("SELECT * FROM $refl_src WHERE featured = 1 ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

// Which reactions the member already gave on this page's cards.
$my_reactions = [];
if (isLoggedIn() && $reflections) {
    $ids = array_column($reflections, 'id');
    $st = $db->prepare("SELECT target_id, reaction_type FROM reactions WHERE target_type = 'reflection' AND user_id = ? AND target_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute(array_merge([$_SESSION['user_id']], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $my_reactions[$r['target_id']][$r['reaction_type']] = true;
}

function readingTime($content) {
    $word_count = str_word_count(strip_tags((string)$content));
    $minutes = ceil($word_count / 200);
    return $minutes < 1 ? '1 min read' : $minutes . ' min read';
}
// Plain text, cut on a word, never in the middle of a character (substr() split é, ’ …).
function aw_refl_excerpt($row, $len = 150) {
    $src = trim((string)($row['excerpt'] ?? '')) !== '' ? $row['excerpt'] : $row['content'];
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$src), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (mb_strlen($t) <= $len) return $t;
    $cut = mb_substr($t, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $len * 0.6 ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:') . '…';
}
function aw_refl_url($row) { return SITE_URL . '/blog_post.php?slug=' . urlencode((string)$row['slug']); }

// The header prints $pageTitle into <title> as it is, so the search and tag are escaped here.
$pageTitle = htmlspecialchars($search !== '' ? 'Search: ' . $search : ($tag !== '' ? '#' . $tag . ' – Christian Reflections' : 'Christian Reflections'));
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="reflections-page">
    <div class="container">
        <!-- Page Header -->
        <div class="reflections-header">
            <h1>Christian Reflections</h1>
            <p>Faith, hope, and encouragement for everyday life — written by Angella.</p>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" id="reflectionsSearchForm" class="search-form">
                <input type="text" name="search" id="searchInput" placeholder="Search reflections..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <div id="searchResults" class="search-results-dropdown" style="display:none;"></div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <a href="<?php echo SITE_URL; ?>/reflections.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
        </div>

        <!-- Controls Bar -->
        <div class="reflections-controls">
            <div class="reflections-controls-left">
                <!-- Sort Dropdown -->
                <form method="GET" class="sort-dropdown" id="sortForm">
                    <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                    <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?php echo htmlspecialchars($tag); ?>"><?php endif; ?>
                    <label for="sortSelect" style="position:absolute;left:-9999px;">Sort reflections</label>
                    <select id="sortSelect" name="sort" onchange="this.form.submit()">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                        <option value="most_viewed" <?php echo $sort === 'most_viewed' ? 'selected' : ''; ?>>Most viewed</option>
                        <option value="most_commented" <?php echo $sort === 'most_commented' ? 'selected' : ''; ?>>Most commented</option>
                        <option value="most_loved" <?php echo $sort === 'most_loved' ? 'selected' : ''; ?>>Most loved</option>
                    </select>
                    <noscript><button type="submit" class="btn btn-sm btn-outline">Sort</button></noscript>
                </form>
                <?php if ($tag !== ''): ?><a class="active-tag" href="<?php echo SITE_URL; ?>/reflections.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" aria-label="Remove the tag filter">#<?php echo htmlspecialchars($tag); ?> ✕</a><?php endif; ?>
            </div>

            <div class="reflections-controls-right">
                <!-- View Toggle -->
                <button id="viewToggle" class="btn btn-sm btn-outline" onclick="toggleView()">
                    <i class="fas fa-th"></i>
                </button>

                <!-- Dark Mode Toggle -->
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>

                <!-- Post Count -->
                <span class="post-count"><?php echo $total_reflections; ?> reflection<?php echo $total_reflections != 1 ? 's' : ''; ?><?php echo $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : ''; ?></span>
            </div>
        </div>

        <!-- Reflections Layout -->
        <div class="reflections-layout">
            <!-- Main Content -->
            <div class="reflections-main">
                <!-- Featured Reflections -->
                <?php if (count($featured_reflections) > 0 && $page === 1 && $search === '' && $tag === ''): ?>
                    <div class="featured-section">
                        <h3><i class="fas fa-star" style="color: var(--rose);"></i> Featured Reflections</h3>
                        <div class="featured-grid">
                            <?php foreach ($featured_reflections as $fr): ?>
                                <div class="featured-card">
                                    <a href="<?php echo htmlspecialchars(aw_refl_url($fr)); /* F72 */ ?>">
                                        <div class="featured-card-content">
                                            <span class="featured-badge"><i class="fas fa-star"></i> Featured</span>
                                            <h4><?php echo htmlspecialchars($fr['title']); ?></h4>
                                            <p><?php echo htmlspecialchars(aw_refl_excerpt($fr, 120)); ?></p>
                                            <span class="featured-read-more">Read <i class="fas fa-arrow-right"></i></span>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Reflections Grid -->
                <?php if (count($reflections) > 0): ?>
                    <div class="reflections-grid" id="reflectionsGrid">
                        <?php foreach ($reflections as $reflection): ?>
                            <article class="reflection-card">
                                <?php if ($reflection['image_path']): ?>
                                    <div class="reflection-image">
                                        <a href="<?php echo htmlspecialchars(aw_refl_url($reflection)); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo htmlspecialchars(SITE_URL . '/' . ltrim($reflection['image_path'], '/')); ?>" alt="" loading="lazy"></a>
                                    </div>
                                <?php endif; ?>
                                <div class="reflection-card-content">
                                    <div class="reflection-meta">
                                        <span class="reflection-date">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo date('M j, Y', strtotime($reflection['created_at'])); ?>
                                        </span>
                                        <span class="reflection-reading-time">
                                            <i class="fas fa-clock"></i> <?php echo readingTime($reflection['content']); ?>
                                        </span>
                                    </div>
                                    <h3>
                                        <a href="<?php echo htmlspecialchars(aw_refl_url($reflection)); /* F72 */ ?>">
                                            <?php echo htmlspecialchars($reflection['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="reflection-excerpt"><?php echo htmlspecialchars(aw_refl_excerpt($reflection, 150)); ?></p>
                                    <?php $rtags = array_slice(array_filter(array_map('trim', explode(',', (string)$reflection['tags']))), 0, 3); if ($rtags): ?>
                                        <div class="reflection-tags"><?php foreach ($rtags as $t): ?><a href="<?php echo SITE_URL; ?>/reflections.php?tag=<?php echo urlencode($t); ?>">#<?php echo htmlspecialchars($t); ?></a><?php endforeach; ?></div>
                                    <?php endif; ?>
                                    <div class="reflection-footer">
                                        <div class="reflection-reactions">
                                            <button type="button" class="reaction-btn<?php echo !empty($my_reactions[$reflection['id']]['like']) ? ' active' : ''; ?>" aria-pressed="<?php echo !empty($my_reactions[$reflection['id']]['like']) ? 'true' : 'false'; ?>" aria-label="Like" onclick="reactReflection(<?php echo (int)$reflection['id']; ?>, 'like', this)">
                                                <i class="fas fa-thumbs-up"></i> <span id="likes-<?php echo (int)$reflection['id']; ?>"><?php echo (int)($reflection['likes'] ?? 0); ?></span>
                                            </button>
                                            <button type="button" class="reaction-btn<?php echo !empty($my_reactions[$reflection['id']]['love']) ? ' active' : ''; ?>" aria-pressed="<?php echo !empty($my_reactions[$reflection['id']]['love']) ? 'true' : 'false'; ?>" aria-label="Love" onclick="reactReflection(<?php echo (int)$reflection['id']; ?>, 'love', this)">
                                                ❤️ <span id="loves-<?php echo (int)$reflection['id']; ?>"><?php echo (int)($reflection['loves'] ?? 0); ?></span>
                                            </button>
                                            <button type="button" class="reaction-btn<?php echo !empty($my_reactions[$reflection['id']]['pray']) ? ' active' : ''; ?>" aria-pressed="<?php echo !empty($my_reactions[$reflection['id']]['pray']) ? 'true' : 'false'; ?>" aria-label="Praying" onclick="reactReflection(<?php echo (int)$reflection['id']; ?>, 'pray', this)">
                                                🙏 <span id="prays-<?php echo (int)$reflection['id']; ?>"><?php echo (int)($reflection['prays'] ?? 0); ?></span>
                                            </button>
                                        </div>
                                        <div class="reflection-actions">
                                            <span class="comment-count"><i class="fas fa-comment"></i> <?php echo number_format($reflection['comment_count'] ?? 0); ?></span>
                                            <a href="<?php echo htmlspecialchars(aw_refl_url($reflection)); /* F72 */ ?>" class="read-more">
                                                Read <i class="fas fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php echo aw_pager($total_reflections, $page, $limit); // RF1: shared pager (keeps search, tag and sort) ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-church" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                        <h3>No reflections found</h3>
                        <p><?php echo ($search !== '' || $tag !== '') ? 'Try another word or tag.' : 'Check back soon for new reflections from Angella.'; ?></p>
                        <?php if ($search !== '' || $tag !== ''): ?><a class="btn btn-outline btn-sm" href="<?php echo SITE_URL; ?>/reflections.php">Show all reflections</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside class="reflections-sidebar">
                <!-- Recent Reflections -->
                <div class="sidebar-card">
                    <h4><i class="fas fa-clock" style="color: var(--rose);"></i> Recent Reflections</h4>
                    <?php if (count($recent_reflections) > 0): ?>
                        <ul class="recent-posts-list">
                            <?php foreach ($recent_reflections as $rr): ?>
                                <li>
                                    <a href="<?php echo htmlspecialchars(aw_refl_url($rr)); /* F72 */ ?>">
                                        <?php echo htmlspecialchars($rr['title']); ?>
                                    </a>
                                    <small><?php echo date('M j, Y', strtotime($rr['created_at'])); ?> • <?php echo $rr['comment_count'] ?? 0; ?> comments</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">No posts yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Tag Cloud -->
                <?php if (count($tags) > 0): ?>
                    <div class="sidebar-card">
                        <h4><i class="fas fa-cloud" style="color: var(--rose);"></i> Tags</h4>
                        <div class="tag-cloud">
                            <?php foreach ($tags as $t): ?>
                                <a href="<?php echo SITE_URL; ?>/reflections.php?tag=<?php echo urlencode($t); ?>" class="tag-cloud-link<?php echo mb_strtolower($t) === mb_strtolower($tag) ? ' active' : ''; ?>">
                                    #<?php echo htmlspecialchars($t); ?> <small><?php echo (int)$tag_counts[mb_strtolower($t)]['n']; ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Newsletter -->
                <div class="sidebar-card">
                    <h4><i class="fas fa-envelope" style="color: var(--rose);"></i> Stay Updated</h4>
                    <p>Get new reflections delivered to your inbox.</p>
                    <form action="<?php echo SITE_URL; ?>/newsletter.php" method="POST" class="sidebar-newsletter">
                        <input type="hidden" name="redirect" value="/reflections.php">
                        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;">
                        <label for="sidebarName" style="position:absolute;left:-9999px;">Your first name</label>
                        <input type="text" id="sidebarName" name="name" placeholder="Your first name (optional)" maxlength="100">
                        <label for="sidebarEmail" style="position:absolute;left:-9999px;">Your email address</label>
                        <input type="email" id="sidebarEmail" name="email" placeholder="Your email address" required>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Subscribe
                        </button>
                    </form>
                    <small>No spam. Unsubscribe anytime.</small>
                </div>
            </aside>
        </div>
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
    const currentTheme = localStorage.getItem('reflectionsTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('reflectionsTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== VIEW TOGGLE =====
    const viewToggle = document.getElementById('viewToggle');
    const reflectionsGrid = document.getElementById('reflectionsGrid') || document.createElement('div'); // RF1: no grid when nothing matches
    const currentView = localStorage.getItem('reflectionsView') || 'grid';
    if (currentView === 'list') {
        reflectionsGrid.classList.add('list-view');
        viewToggle.innerHTML = '<i class="fas fa-list"></i>';
    }

    window.toggleView = function() {
        reflectionsGrid.classList.toggle('list-view');
        const isList = reflectionsGrid.classList.contains('list-view');
        localStorage.setItem('reflectionsView', isList ? 'list' : 'grid');
        viewToggle.innerHTML = isList ? '<i class="fas fa-list"></i>' : '<i class="fas fa-th"></i>';
    };

    // ===== AJAX LIVE SEARCH =====
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length < 2) {
            searchResults.style.display = 'none';
            return;
        }

        fetch('<?php echo SITE_URL; ?>/ajax_search_reflections.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '<ul>';
                    data.forEach(item => {
                        html += '<li><a href="<?php echo SITE_URL; ?>/blog_post.php?slug=' + encodeURIComponent(item.slug || '') + '">' + item.title + '</a></li>'; // F72
                    });
                    html += '</ul>';
                    searchResults.innerHTML = html;
                    searchResults.style.display = 'block';
                } else {
                    searchResults.innerHTML = '<p class="no-results">No results found.</p>';
                    searchResults.style.display = 'block';
                }
            });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#reflectionsSearchForm')) {
            searchResults.style.display = 'none';
        }
    });

    // ===== REACTIONS (RF1: reflections_reactions.php did not exist, so these never worked) =====
    window.reactReflection = function(reflectionId, reaction, btn) {
        const fd = new FormData();
        fd.append('reflection_id', reflectionId); fd.append('reaction', reaction);
        fd.append('csrf_token', <?php echo json_encode(generate_csrf_token()); ?>);
        fetch('<?php echo SITE_URL; ?>/reflections_reactions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.login) { if (confirm('Please log in to react. Go to the login page now?')) location.href = '<?php echo SITE_URL; ?>/login.php?redirect=' + encodeURIComponent('/reflections.php'); return; }
                if (!data.success) { alert(data.error || 'Please try again.'); return; }
                ['likes', 'loves', 'prays'].forEach(k => { const el = document.getElementById(k + '-' + reflectionId); if (el) el.textContent = data[k]; });
                if (btn) { btn.classList.toggle('active', data.active); btn.setAttribute('aria-pressed', data.active ? 'true' : 'false'); }
            })
            .catch(() => alert('Please try again.'));
    };

    // ===== SORT DROPDOWN =====
    document.getElementById('sortSelect').addEventListener('change', function() {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('sort', this.value);
        window.location.href = currentUrl.toString();
    });
});
</script>

<style>
/* ===== BASE & DARK MODE ===== */
:root {
    /* RF4: the brand palette (assets/css/style.css); these pages used their own red and vanilla */
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #ffffff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --input-bg: #ffffff; --card-bg: #ffffff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08); --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --bg: #F7F3ED;
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

.reflections-page { padding: 32px 0 60px; }
.reflections-header { text-align: center; margin-bottom: 32px; }
.reflections-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.reflections-header p { color: var(--text-light); font-size: 1.1rem; }

/* ===== SEARCH BAR ===== */
.search-bar { margin-bottom: 24px; position: relative; }
.search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.search-form input { flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.search-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.search-results-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; max-height: 200px; overflow-y: auto; z-index: 100; }
.search-results-dropdown ul { list-style: none; padding: 0; margin: 0; }
.search-results-dropdown li { padding: 8px 12px; border-bottom: 1px solid var(--border); }
.search-results-dropdown li:last-child { border-bottom: none; }
.search-results-dropdown a { color: var(--text); text-decoration: none; display: block; }
.search-results-dropdown a:hover { color: var(--rose); }
.search-results-dropdown .no-results { padding: 8px 12px; color: var(--text-light); }

/* ===== CONTROLS BAR ===== */
.reflections-controls { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 24px; }
.reflections-controls-left { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.reflections-controls-right { display: flex; gap: 8px; align-items: center; }

.sort-dropdown select { padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
.post-count { font-size: 0.85rem; color: var(--text-light); }

/* ===== FEATURED SECTION ===== */
.featured-section { margin-bottom: 32px; }
.featured-section h3 { font-size: 1.2rem; margin-bottom: 12px; }
.featured-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; }
.featured-card { background: var(--card-bg); border-radius: 8px; padding: 16px; border: 1px solid var(--border); transition: transform 0.2s; }
.featured-card:hover { transform: translateY(-2px); }
.featured-card a { color: var(--text); text-decoration: none; display: block; }
.featured-badge { display: inline-block; background: var(--rose); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; margin-bottom: 4px; }
.featured-card h4 { font-size: 1rem; margin-bottom: 4px; }
.featured-card p { color: var(--text-light); font-size: 0.85rem; line-height: 1.5; margin-bottom: 4px; }
.featured-read-more { font-size: 0.8rem; color: var(--rose); }

/* ===== REFLECTIONS GRID ===== */
.reflections-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.reflections-grid.list-view { grid-template-columns: 1fr; }
.reflection-card { background: var(--card-bg); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.3s, box-shadow 0.3s; }
.reflection-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.reflection-image { width: 100%; height: 180px; overflow: hidden; }
.reflection-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
.reflection-card:hover .reflection-image img { transform: scale(1.05); }
.reflection-card-content { padding: 24px; flex: 1; display: flex; flex-direction: column; }

.reflection-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; font-size: 0.85rem; color: var(--text-light); }
.reflection-date { display: flex; align-items: center; gap: 4px; }
.reflection-reading-time { display: flex; align-items: center; gap: 4px; }

.reflection-card h3 { font-size: 1.15rem; margin-bottom: 6px; line-height: 1.3; }
.reflection-card h3 a { color: var(--text); text-decoration: none; transition: color 0.2s; }
.reflection-card h3 a:hover { color: var(--rose); }
.reflection-excerpt { color: var(--text-light); font-size: 0.95rem; line-height: 1.6; margin-bottom: 12px; flex: 1; }

.reflection-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; padding-top: 12px; border-top: 1px solid var(--border); }
.reflection-reactions { display: flex; gap: 6px; }
.reaction-btn { background: none; border: none; cursor: pointer; color: var(--text-light); font-size: 0.85rem; transition: color 0.2s; display: flex; align-items: center; gap: 2px; }
.reaction-btn:hover { color: var(--rose); }
.reflection-actions { display: flex; gap: 12px; align-items: center; }
.comment-count { color: var(--text-light); font-size: 0.85rem; display: flex; align-items: center; gap: 4px; }
.read-more { color: var(--rose); font-weight: 500; font-size: 0.9rem; text-decoration: none; transition: color 0.2s; display: inline-flex; align-items: center; gap: 4px; }
.read-more:hover { color: var(--rose-dark); }
.read-more i { font-size: 0.8rem; transition: transform 0.2s; }
.read-more:hover i { transform: translateX(4px); }

/* ===== SIDEBAR ===== */
.reflections-sidebar { display: flex; flex-direction: column; gap: 24px; }
.sidebar-card { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.sidebar-card h4 { font-size: 1.05rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

.recent-posts-list { list-style: none; padding: 0; margin: 0; }
.recent-posts-list li { padding: 8px 0; border-bottom: 1px solid var(--border); }
.recent-posts-list li:last-child { border-bottom: none; }
.recent-posts-list li a { display: block; font-weight: 500; color: var(--text); transition: color 0.2s; }
.recent-posts-list li a:hover { color: var(--rose); }
.recent-posts-list li small { display: block; color: var(--text-light); font-size: 0.8rem; margin-top: 2px; }

.tag-cloud { display: flex; flex-wrap: wrap; gap: 6px; }
.tag-cloud-link { background: var(--vanilla); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; color: var(--text); text-decoration: none; transition: background 0.2s, color 0.2s; }
.tag-cloud-link:hover, .tag-cloud-link.active { background: var(--rose); color: white; }
/* RF1 */
.tag-cloud-link small { opacity: .7; margin-left: 2px; }
.reaction-btn.active { color: var(--rose-dark, #c08a8b); font-weight: 700; }
.reflection-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: -4px 0 12px; }
.reflection-tags a { font-size: .75rem; color: var(--rose-dark, #c08a8b); text-decoration: none; background: var(--fantasy); padding: 2px 8px; border-radius: 999px; }
.active-tag { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 999px; background: var(--rose); color: #fff; text-decoration: none; font-size: .85rem; }
.sidebar-newsletter input[type="text"] { margin-bottom: 8px; }

.sidebar-newsletter input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 12px; background: var(--input-bg); color: var(--text); }
.sidebar-newsletter input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.sidebar-newsletter .btn-block { width: 100%; }
.sidebar-newsletter small { display: block; text-align: center; margin-top: 8px; color: var(--text-light); font-size: 0.8rem; }

.text-muted { color: var(--text-light); font-style: italic; }

/* ===== PAGINATION ===== */
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 32px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align: center; padding: 40px 20px; color: var(--text-light); grid-column: 1 / -1; }
.empty-state h3 { font-size: 1.3rem; margin-bottom: 6px; }

/* ===== BACK TO TOP ===== */
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .reflections-layout { grid-template-columns: 1fr; }
    .reflections-grid { grid-template-columns: 1fr; }
    .reflections-sidebar { order: -1; }
    .reflections-controls { flex-direction: column; align-items: stretch; }
    .reflections-controls-left { flex-direction: column; align-items: stretch; }
    .reflections-controls-right { justify-content: space-between; }
    .featured-grid { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
    .reflection-meta { flex-direction: column; align-items: flex-start; gap: 4px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>