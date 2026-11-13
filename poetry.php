<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

// ===== WEBP IMAGE HELPER (Copied from poem_view.php for Deep Enhancement) =====
if (!function_exists('get_image_url')) {
    function get_image_url($path) {
        if (empty($path)) return '';
        $base = rtrim(SITE_URL, '/');
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $webp_support = strpos($accept, 'image/webp') !== false;
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($webp_support && in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/', '.webp', $path);
            $full_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $webp_path;
            if (file_exists($full_path)) {
                return $base . '/' . $webp_path;
            }
        }
        return $base . '/' . ltrim($path, '/');
    }
}

// V5: the catalogue – search, "with a recording" filter, sort, a featured newest poem and cards that
// show each poem's opening lines. Built on includes/poem_reader.php; paged with the shared pager.
require_once __DIR__ . '/includes/poem_reader.php';
require_once __DIR__ . '/includes/poem_card.php'; // M1: the shared poem card
require_once __DIR__ . '/includes/pagination.php';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = in_array($_GET['sort'] ?? '', ['newest', 'popular', 'az'], true) ? $_GET['sort'] : 'newest';
$only_audio = !empty($_GET['audio']);
$order = ['newest' => 'created_at DESC, id DESC', 'popular' => 'view_count DESC, created_at DESC', 'az' => 'title COLLATE NOCASE ASC'][$sort];

$where = "id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')"; // F122: drafts are not public
$params = [];
if ($search !== '') {
    $where .= " AND (title LIKE ? OR intro LIKE ? OR content LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($only_audio) {
    // Only poems whose recording file exists (the same rule that shows the player).
    $ids = [0];
    foreach ($db->query("SELECT id, audio_path FROM poems WHERE audio_path IS NOT NULL AND audio_path <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (aw_poem_audio_url($r) !== '') $ids[] = (int)$r['id'];
    }
    $where .= ' AND id IN (' . implode(',', $ids) . ')';
}
$stmt = $db->prepare("SELECT COUNT(*) FROM poems WHERE $where");
$stmt->execute($params);
$total_poems = (int)$stmt->fetchColumn();
[$page, $per_page] = aw_page(12);
[$page, $offset] = aw_clamp($total_poems, $page, $per_page);
$stmt = $db->prepare("SELECT * FROM poems WHERE $where ORDER BY $order LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$per_page, $offset]));
$poems = $stmt->fetchAll(PDO::FETCH_ASSOC);
$featured = ($page === 1 && $search === '' && !$only_audio && $sort === 'newest' && count($poems) > 2) ? array_shift($poems) : null;

function aw_cat_link(array $change) {
    $q = array_merge($_GET, $change);
    unset($q['page']);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null && $v !== 0 && $v !== '0'; });
    return SITE_URL . '/poetry.php' . ($q ? '?' . htmlspecialchars(http_build_query($q)) : '');
}
function aw_cat_img(array $poem) {
    $img = trim((string)($poem['image_path'] ?? ''));
    return $img === '' ? '' : get_image_url($img);
}

$pageTitle = 'Poetry';
$og_description = "Poems by Angella Bottoman – words of faith, healing and hope.";
?>
<?php require_once 'includes/header.php'; ?>
<?php echo aw_poem_assets(); ?>

<div class="poetry-page">
    <div class="container">
        <header class="aw-cat-hero">
            <div class="aw-hero-kicker">The poetry of Angella Bottoman</div>
            <h1>Poetry</h1>
            <p>Words that speak to the soul — of faith, healing and the beauty of being known by God.</p>
        </header>

        <div class="aw-cat-tools">
            <form method="GET" class="aw-cat-search" role="search">
                <label for="poemSearch" class="sr-only" style="position:absolute;left:-9999px;">Search poems</label>
                <input type="search" id="poemSearch" name="search" placeholder="Search by title, line or theme…" value="<?php echo htmlspecialchars($search); ?>">
                <?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>"><?php endif; ?>
                <?php if ($only_audio): ?><input type="hidden" name="audio" value="1"><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search" aria-hidden="true"></i> Search</button>
            </form>
            <nav class="aw-cat-filters" aria-label="Filter and sort">
                <a href="<?php echo aw_cat_link(['audio' => '']); ?>" class="<?php echo !$only_audio ? 'on' : ''; ?>">All poems</a>
                <a href="<?php echo aw_cat_link(['audio' => 1]); ?>" class="<?php echo $only_audio ? 'on' : ''; ?>">🎧 With recording</a>
                <span aria-hidden="true" style="width:1px;height:24px;background:var(--border);margin:0 4px;"></span>
                <a href="<?php echo aw_cat_link(['sort' => '']); ?>" class="<?php echo $sort === 'newest' ? 'on' : ''; ?>">Newest</a>
                <a href="<?php echo aw_cat_link(['sort' => 'popular']); ?>" class="<?php echo $sort === 'popular' ? 'on' : ''; ?>">Most read</a>
                <a href="<?php echo aw_cat_link(['sort' => 'az']); ?>" class="<?php echo $sort === 'az' ? 'on' : ''; ?>">A–Z</a>
            </nav>
            <div class="aw-cat-count"><?php echo number_format($total_poems); ?> poem<?php echo $total_poems === 1 ? '' : 's'; ?><?php if ($search !== ''): ?> matching “<?php echo htmlspecialchars($search); ?>” · <a href="<?php echo aw_cat_link(['search' => '']); ?>">clear search</a><?php endif; ?></div>
        </div>

        <?php if ($featured):
            $fs = aw_poem_stats($featured); $fimg = aw_cat_img($featured); ?>
            <a class="aw-featured" href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo (int)$featured['id']; ?>">
                <div class="aw-featured-img"<?php if ($fimg): ?> style="background-image:url('<?php echo htmlspecialchars($fimg, ENT_QUOTES); ?>')"<?php endif; ?>>
                    <?php if (!$fimg): ?><span class="aw-ph" style="position:absolute;inset:0;display:grid;place-items:center;font-size:3.6rem;color:var(--rose);"><i class="fas fa-heart"></i></span><?php endif; ?>
                    <?php if (aw_poem_audio_url($featured) !== ''): ?><span class="aw-badge">🎧 Listen</span><?php endif; ?>
                </div>
                <div class="aw-featured-body">
                    <div class="aw-hero-kicker" style="color:var(--rose-dark);">Newest poem</div>
                    <h2><?php echo htmlspecialchars($featured['title']); ?></h2>
                    <p class="aw-opening"><?php foreach (aw_poem_opening($featured, 4) as $l): ?><span><?php echo htmlspecialchars($l); ?></span><?php endforeach; ?></p>
                    <div class="aw-card-foot" style="border:0;padding:0;">
                        <span><i class="far fa-calendar" aria-hidden="true"></i><?php echo date('M j, Y', strtotime($featured['created_at'])); ?></span>
                        <span><i class="far fa-clock" aria-hidden="true"></i><?php echo $fs['minutes']; ?> min read</span>
                        <span><i class="fas fa-align-center" aria-hidden="true"></i><?php echo $fs['stanzas']; ?> stanzas</span>
                    </div>
                    <span class="btn btn-primary btn-sm" style="margin-top:18px;align-self:flex-start;"><i class="fas fa-book-open" aria-hidden="true"></i> Read the poem</span>
                </div>
            </a>
        <?php endif; ?>

        <?php if ($poems): ?>
            <div class="aw-cat-grid">
                <?php foreach ($poems as $poem): ?>
                    <?php echo aw_poem_card($poem); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$poems && !$featured): ?>
            <div class="aw-empty">
                <div class="aw-ph"><i class="fas fa-heart" aria-hidden="true"></i></div>
                <h3>No poems found</h3>
                <p><?php echo ($search !== '' || $only_audio) ? 'Try another word, or show all poems.' : 'Check back soon for new poetry from Angella.'; ?></p>
                <?php if ($search !== '' || $only_audio): ?><a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline btn-sm">Show all poems</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php echo aw_pager($total_poems, $page, $per_page); ?>
    </div>
</div>

<!-- ===== BACK TO TOP BUTTON ===== -->
<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<script>
document.addEventListener('DOMContentLoaded', function() {

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

/* ===== TYPOGRAPHY & LAYOUT ===== */
* { box-sizing: border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); margin:0; padding:0; }
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
.rose-text { color:var(--rose); }

/* ===== PAGE WRAPPER (Ensures Footer sticks to bottom) ===== */
.poetry-page { padding:32px 0 60px; display:flex; flex-direction:column; min-height:100vh; }

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

/* V5: hero, tools, cards and pager are styled in assets/css/aw-poem.css and style.css. */
.poetry-page .aw-pager { margin-top: 36px; }

/* ===== BACK TO TOP ===== */
.back-to-top {
    position:fixed; bottom:24px; right:24px; width:44px; height:44px;
    border-radius:50%; background:var(--rose); color:white; border:none;
    font-size:1.2rem; display:none; align-items:center; justify-content:center;
    box-shadow:0 4px 12px rgba(0,0,0,0.15); cursor:pointer; transition:transform 0.2s; z-index:1000;
}
.back-to-top:hover { transform:scale(1.05); }

</style>

<?php require_once 'includes/footer.php'; ?>