<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// ===== PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';

// ===== SORT =====
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// ===== FETCH UNIQUE VIDEO TYPES =====
$stmt = $db->query("SELECT DISTINCT type FROM videos ORDER BY type ASC");
$types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ===== FETCH TOTAL VIDEOS =====
$count_sql = "SELECT COUNT(*) FROM videos WHERE 1=1";
$count_params = [];

if ($search) {
    $count_sql .= " AND (title LIKE ? OR description LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}
if ($filter_type && in_array($filter_type, $types)) {
    $count_sql .= " AND type = ?";
    $count_params[] = $filter_type;
}

$stmt = $db->prepare($count_sql);
$stmt->execute($count_params);
$total_videos = $stmt->fetchColumn();
$total_pages = ceil($total_videos / $limit);

// ===== FETCH VIDEOS =====
$sql = "SELECT * FROM videos WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_type && in_array($filter_type, $types)) {
    $sql .= " AND type = ?";
    $params[] = $filter_type;
}

switch ($sort) {
    case 'oldest':
        $sql .= " ORDER BY created_at ASC";
        break;
    case 'most_viewed':
        $sql .= " ORDER BY views DESC";
        break;
    case 'most_commented':
        $sql .= " ORDER BY comment_count DESC";
        break;
    default:
        $sql .= " ORDER BY created_at DESC";
        break;
}

$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== READING TIME CALCULATION (for video duration) =====
function formatDuration($seconds) {
    if (!$seconds) return '';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

$pageTitle = 'Videos';
?>
<?php require_once 'includes/header.php'; ?>

<!-- ===== READING PROGRESS BAR ===== -->
<div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

<div class="videos-page">
    <div class="container">
        <!-- Page Header -->
        <div class="videos-header">
            <h1>Videos</h1>
            <p>Watch, learn, and be inspired — AngelWrites video messages and teachings.</p>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" id="videosSearchForm" class="search-form">
                <input type="text" name="search" id="searchInput" placeholder="Search videos..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <div id="searchResults" class="search-results-dropdown" style="display:none;"></div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <a href="<?php echo SITE_URL; ?>/videos.php" class="btn btn-outline btn-sm">Clear</a>
            </form>
        </div>

        <!-- Controls Bar -->
        <div class="videos-controls">
            <div class="videos-controls-left">
                <!-- Type Filter -->
                <div class="type-filter">
                    <span>Filter:</span>
                    <a href="<?php echo SITE_URL; ?>/videos.php" class="type-link <?php echo !$filter_type ? 'active' : ''; ?>">All</a>
                    <?php foreach ($types as $type): ?>
                        <a href="<?php echo SITE_URL; ?>/videos.php?type=<?php echo urlencode($type); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="type-link <?php echo $filter_type === $type ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars(ucfirst($type)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Sort Dropdown -->
                <div class="sort-dropdown">
                    <select id="sortSelect" onchange="this.form.submit()">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                        <option value="most_viewed" <?php echo $sort === 'most_viewed' ? 'selected' : ''; ?>>Most viewed</option>
                        <option value="most_commented" <?php echo $sort === 'most_commented' ? 'selected' : ''; ?>>Most commented</option>
                    </select>
                </div>
            </div>

            <div class="videos-controls-right">
                <!-- View Toggle -->
                <button id="viewToggle" class="btn btn-sm btn-outline" onclick="toggleView()">
                    <i class="fas fa-th"></i>
                </button>

                <!-- Dark Mode Toggle -->
                <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>

                <!-- Video Count -->
                <span class="video-count"><?php echo $total_videos; ?> video<?php echo $total_videos != 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <!-- Videos Grid -->
        <?php if (count($videos) > 0): ?>
            <div class="videos-grid" id="videosGrid">
                <?php foreach ($videos as $video): ?>
                    <div class="video-card">
                        <a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>" class="video-thumbnail">
                            <?php if ($video['thumbnail']): ?>
                                <img src="<?php echo SITE_URL . '/' . $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="video-thumbnail-placeholder">
                                    <i class="fas fa-video"></i>
                                    <span>No thumbnail</span>
                                </div>
                            <?php endif; ?>
                            <div class="play-overlay">
                                <i class="fas fa-play-circle"></i>
                            </div>
                            <span class="video-type-badge"><?php echo htmlspecialchars(ucfirst($video['type'] ?? 'video')); ?></span>
                            <?php if (!empty($video['duration'])): ?>
                                <span class="video-duration"><?php echo formatDuration($video['duration']); ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <div class="video-info">
                            <h3><a href="<?php echo SITE_URL; ?>/video_watch.php?id=<?php echo $video['id']; ?>"><?php echo htmlspecialchars($video['title']); ?></a></h3>
                            <?php if ($video['description']): ?>
                                <p class="video-description"><?php echo htmlspecialchars(substr($video['description'], 0, 100)); ?>...</p>
                            <?php endif; ?>
                            
                            <div class="video-footer">
                                <div class="video-meta">
                                    <span class="video-date"><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($video['created_at'])); ?></span>
                                    <span class="video-views"><i class="fas fa-eye"></i> <?php echo number_format($video['views'] ?? 0); ?></span>
                                </div>
                                
                                <!-- 🚀 ENHANCED: Reactions & Share -->
                                <div class="video-actions">
                                    <button class="action-btn like-btn" onclick="reactVideo(<?php echo $video['id']; ?>, 'like')">
                                        <i class="fas fa-thumbs-up"></i> <span id="likes-<?php echo $video['id']; ?>"><?php echo $video['likes'] ?? 0; ?></span>
                                    </button>
                                    <button class="action-btn love-btn" onclick="reactVideo(<?php echo $video['id']; ?>, 'love')">
                                        ❤️ <span id="loves-<?php echo $video['id']; ?>"><?php echo $video['loves'] ?? 0; ?></span>
                                    </button>
                                    <button class="action-btn pray-btn" onclick="reactVideo(<?php echo $video['id']; ?>, 'pray')">
                                        🙏 <span id="prays-<?php echo $video['id']; ?>"><?php echo $video['prays'] ?? 0; ?></span>
                                    </button>
                                    <button class="action-btn share-btn" onclick="copyVideoLink(<?php echo $video['id']; ?>)">
                                        <i class="fas fa-share-alt"></i>
                                    </button>
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
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter_type ? '&type=' . urlencode($filter_type) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter_type ? '&type=' . urlencode($filter_type) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter_type ? '&type=' . urlencode($filter_type) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-video" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                <h3>No videos found</h3>
                <p><?php echo $search ? 'Try adjusting your search.' : 'Check back soon for new videos from AngelWrites.'; ?></p>
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

    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('videosTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('videosTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== VIEW TOGGLE =====
    const viewToggle = document.getElementById('viewToggle');
    const videosGrid = document.getElementById('videosGrid');
    const currentView = localStorage.getItem('videosView') || 'grid';
    if (currentView === 'list') {
        videosGrid.classList.add('list-view');
        viewToggle.innerHTML = '<i class="fas fa-list"></i>';
    }

    window.toggleView = function() {
        videosGrid.classList.toggle('list-view');
        const isList = videosGrid.classList.contains('list-view');
        localStorage.setItem('videosView', isList ? 'list' : 'grid');
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

        fetch('<?php echo SITE_URL; ?>/ajax_search_videos.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    let html = '<ul>';
                    data.forEach(item => {
                        html += '<li><a href="<?php echo SITE_URL; ?>/video_watch.php?id=' + item.id + '">' + item.title + '</a></li>';
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
        if (!e.target.closest('#videosSearchForm')) {
            searchResults.style.display = 'none';
        }
    });

    // ===== SORT DROPDOWN =====
    document.getElementById('sortSelect').addEventListener('change', function() {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('sort', this.value);
        window.location.href = currentUrl.toString();
    });
    
    // ===== SHARE VIDEO LINK =====
    window.copyVideoLink = function(videoId) {
        const url = '<?php echo SITE_URL; ?>/video_watch.php?id=' + videoId;
        navigator.clipboard.writeText(url).then(() => {
            alert('Video link copied to clipboard!');
        });
    };

    // ===== REACTIONS (Like, Love, Pray) =====
    window.reactVideo = function(videoId, reaction) {
        fetch('<?php echo SITE_URL; ?>/videos_reactions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'video_id=' + videoId + '&reaction=' + reaction
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('likes-' + videoId).textContent = data.likes;
                document.getElementById('loves-' + videoId).textContent = data.loves;
                document.getElementById('prays-' + videoId).textContent = data.prays;
            } else if (data.error) {
                alert(data.error); // e.g., "Please login first"
            }
        });
    };
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

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background 0.3s, color 0.3s; }

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

/* ===== DARK MODE SUPPORT ===== */
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --text: #e8dddd;
    --text-light: #a08a8a;
    --border: #4a3a3a;
    --vanilla: #2c1e1e;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}

/* ===== VIDEOS PAGE ===== */
.videos-page { padding:32px 0 60px; }
.videos-header { text-align:center; margin-bottom:32px; }
.videos-header h1 { font-size:2.4rem; margin-bottom:4px; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.videos-header p { color:var(--text-light); font-size:1.1rem; }

/* ===== SEARCH BAR ===== */
.search-bar { margin-bottom:24px; position:relative; }
.search-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.search-form input { flex:1; min-width:200px; padding:10px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.95rem; background:var(--card-bg); color:var(--text); }
.search-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.search-results-dropdown { position:absolute; top:100%; left:0; right:0; background:var(--card-bg); border:1px solid var(--border); border-radius:12px; max-height:200px; overflow-y:auto; z-index:100; box-shadow:var(--shadow); }
.search-results-dropdown ul { list-style:none; padding:0; margin:0; }
.search-results-dropdown li { padding:8px 12px; border-bottom:1px solid var(--border); }
.search-results-dropdown li:last-child { border-bottom:none; }
.search-results-dropdown a { color:var(--text); text-decoration:none; display:block; }
.search-results-dropdown a:hover { color:var(--rose); }
.search-results-dropdown .no-results { padding:8px 12px; color:var(--text-light); }

/* ===== CONTROLS BAR ===== */
.videos-controls { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px; margin-bottom:24px; }
.videos-controls-left { display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
.videos-controls-right { display:flex; gap:8px; align-items:center; }

.type-filter { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
.type-filter span { color:var(--text-light); font-size:0.85rem; }
.type-link { padding:4px 12px; border-radius:20px; background:var(--card-bg); border:1px solid var(--border); color:var(--text); font-size:0.8rem; transition:all 0.2s; text-decoration:none; }
.type-link:hover { border-color:var(--rose); }
.type-link.active { background:var(--rose); color:white; border-color:var(--rose); }

.sort-dropdown select { padding:6px 12px; border-radius:50px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); font-size:0.9rem; }
.video-count { font-size:0.85rem; color:var(--text-light); }

/* ===== VIDEOS GRID ===== */
.videos-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:24px; }
.videos-grid.list-view { grid-template-columns:1fr; }

.video-card { background:var(--card-bg); border-radius:16px; overflow:hidden; border:1px solid var(--border); box-shadow:var(--shadow); transition:transform 0.3s, box-shadow 0.3s; }
.video-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-hover); }

.video-thumbnail { position:relative; height:180px; overflow:hidden; background:var(--vanilla); display:block; }
.video-thumbnail img { width:100%; height:100%; object-fit:cover; transition:transform 0.4s; }
.video-card:hover .video-thumbnail img { transform:scale(1.05); }
.video-thumbnail-placeholder { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:2.5rem; color:var(--rose); background:var(--vanilla); }
.video-thumbnail-placeholder span { font-size:0.8rem; color:var(--text-light); margin-top:4px; }

.play-overlay { position:absolute; top:0; left:0; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.15); color:white; font-size:3rem; opacity:0; transition:opacity 0.3s, transform 0.3s; transform:scale(0.9); }
.video-card:hover .play-overlay { opacity:1; transform:scale(1); }

.video-type-badge { position:absolute; top:12px; left:12px; padding:4px 12px; border-radius:20px; font-size:0.7rem; font-weight:700; text-transform:uppercase; background:var(--rose); color:white; z-index:1; }
.video-duration { position:absolute; bottom:12px; right:12px; padding:2px 8px; background:rgba(0,0,0,0.75); color:white; border-radius:4px; font-size:0.75rem; z-index:1; }

.video-info { padding:16px; }
.video-info h3 { font-size:1.1rem; margin-bottom:4px; font-family:'Playfair Display',Georgia,serif; }
.video-info h3 a { color:var(--text); text-decoration:none; transition:color 0.2s; }
.video-info h3 a:hover { color:var(--rose); }
.video-description { color:var(--text-light); font-size:0.9rem; line-height:1.5; margin-bottom:12px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

.video-footer { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:8px; }
.video-meta { display:flex; gap:12px; font-size:0.85rem; color:var(--text-light); }
.video-meta i { margin-right:2px; }

/* 🚀 ENHANCED: Interactive Action Buttons */
.video-actions { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
.action-btn { background:transparent; border:none; cursor:pointer; color:var(--text-light); font-size:0.8rem; transition:all 0.2s; display:flex; align-items:center; gap:3px; padding:4px 8px; border-radius:30px; }
.action-btn:hover { background:var(--vanilla); color:var(--rose); transform:scale(1.05); }
.action-btn.like-btn:hover { color:#3b82f6; }
.action-btn.love-btn:hover { color:#ef4444; }
.action-btn.pray-btn:hover { color:#8b5cf6; }
.action-btn.share-btn { padding:4px 6px; font-size:0.9rem; }
.action-btn.share-btn:hover { color:var(--rose); }

/* ===== PAGINATION ===== */
.pagination { display:flex; justify-content:center; gap:6px; margin-top:32px; flex-wrap:wrap; }
.page-link { display:inline-flex; align-items:center; justify-content:center; padding:6px 14px; border-radius:8px; background:var(--card-bg); border:1px solid var(--border); color:var(--text); font-size:0.9rem; transition:all 0.2s; min-width:36px; text-decoration:none; }
.page-link:hover { border-color:var(--rose); }
.page-link.active { background:var(--rose); color:white; border-color:var(--rose); }

/* ===== EMPTY STATE ===== */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-light); }
.empty-state h3 { font-size:1.4rem; margin-bottom:6px; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }

/* ===== BACK TO TOP ===== */
.back-to-top { position:fixed; bottom:24px; right:24px; width:44px; height:44px; border-radius:50%; background:var(--rose); color:white; border:none; font-size:1.2rem; display:none; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(0,0,0,0.15); cursor:pointer; transition:transform 0.2s; z-index:1000; }
.back-to-top:hover { transform:scale(1.05); }

/* ===== RESPONSIVE ===== */
@media (max-width:768px) {
    .videos-controls { flex-direction:column; align-items:stretch; }
    .videos-controls-left { flex-direction:column; align-items:stretch; }
    .videos-controls-right { justify-content:space-between; }
    .type-filter { justify-content:center; }
    .videos-header h1 { font-size:2rem; }
    .video-footer { flex-direction:column; align-items:flex-start; }
    .video-actions { width:100%; justify-content:flex-start; }
}
@media (max-width:480px) {
    .videos-grid { grid-template-columns:1fr; }
    .search-form { flex-direction:column; }
    .search-form input { width:100%; }
    .videos-header h1 { font-size:1.6rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>