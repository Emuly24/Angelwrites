<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/commerce.php';
require_once 'includes/mail_helper.php';

// ============================================================
// 1. FULL-PAGE CACHE FOR ANONYMOUS USERS
// ============================================================
$cacheFile = __DIR__ . '/cache/index.html';
$cacheTime = 300; // 5 minutes

// If user is not logged in, serve cached version if available
// F174: GET only. A guest POST (newsletter form with its CSRF token) was answered from the
// cache and never reached its handler.
if (!isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        // F174: the cached page holds a placeholder instead of the CSRF token of whoever
        // filled the cache; give this visitor their own session token.
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        echo str_replace('__AW_CSRF_TOKEN__', $_SESSION['csrf_token'], file_get_contents($cacheFile));
        exit;
    }
    ob_start();
    // F210: cache/ does not exist on the host, so writing the cache warned on every guest
    // visit. Only cache when the folder exists (create it only after #7's F174 token fix).
    $doCache = is_dir(dirname($cacheFile));
} else {
    $doCache = false;
}

// ============================================================
// 2. CSRF PROTECTION HELPER (if not already defined)
// ============================================================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ============================================================
// 3. WEBP IMAGE HELPER
// ============================================================
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

// ============================================================
// 4. FETCH CONTENT
// ============================================================
$isLoggedIn = isLoggedIn();
$userId = $isLoggedIn ? $_SESSION['user_id'] : 0;

// Featured Books (latest 3)
$stmt = $db->prepare("SELECT * FROM books ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$featured_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest Poems (latest 3)
$stmt = $db->prepare("SELECT * FROM poems WHERE id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft') ORDER BY created_at DESC LIMIT 3"); // F122: no drafts
$stmt->execute();
$latest_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest Blog Posts (published, latest 3)
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 3");
$stmt->execute();
$latest_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH TESTIMONIALS (real, approved, public) =====
// L1: three approved stories chosen at random on each page load (no timer, no flip cards), joined to
// the author in one query. A story the member did not mark "featured publicly" is never shown.
function aw_voice_name(array $row) {
    // first name only on the public home page
    $n = trim(awDisplayName($row));
    $first = preg_split('/\s+/u', $n)[0] ?? '';
    return $first !== '' ? $first : 'A member';
}
function aw_voice_short($text, $len) {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (function_exists('mb_strlen')) return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len)) . '…' : $t;
    return strlen($t) > $len ? rtrim(substr($t, 0, $len)) . '…' : $t;
}
$testimonials = [];
$testimonial_total = 0;
try {
    $testimonials = $db->query("SELECT t.testimony, t.created_at, u.name, u.first_name, u.last_name, u.username
        FROM testimonials t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.approved = 1 AND COALESCE(t.is_public, 1) = 1
        ORDER BY RANDOM() LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    $testimonial_total = (int)$db->query("SELECT COUNT(*) FROM testimonials WHERE approved = 1 AND COALESCE(is_public, 1) = 1")->fetchColumn();
} catch (Throwable $e) {
    error_log('home testimonials: ' . $e->getMessage());
}

// ===== FETCH LIVE STATS =====
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM books WHERE is_free = 1");
$stmt->execute();
$free_books = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM prayer_requests");
$stmt->execute();
$total_prayers = $stmt->fetchColumn();

// ===== PERSONALIZED RECOMMENDATIONS =====
$recommended_books = [];
if ($isLoggedIn) {
    $stmt = $db->prepare("
        SELECT b.* FROM books b
        WHERE b.id NOT IN (
            SELECT book_id FROM reading_status WHERE user_id = ?
        )
        ORDER BY RANDOM() LIMIT 2
    ");
    $stmt->execute([$userId]);
    $recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$greeting = $isLoggedIn ? "Welcome back, " . htmlspecialchars($_SESSION['name'] ?? 'Friend') . "!" : "Welcome Home.";

// ===== NEWSLETTER (with CSRF) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $newsletter_error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['newsletter_email']);
        $name = isset($_POST['newsletter_name']) ? trim($_POST['newsletter_name']) : '';
        
        if (empty($email)) {
            $newsletter_error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $newsletter_error = 'Please enter a valid email address.';
        } else {
            // F176: double opt-in (includes/newsletter_optin.php)
            require_once 'includes/newsletter_optin.php';
            $optin = newsletter_request_optin($db, $email, $name);
            if ($optin['ok']) {
                $newsletter_success = $optin['message'];
            } else {
                $newsletter_error = $optin['message'];
            }
        }
    }
}

// ===== TESTIMONIAL SUBMISSION (with CSRF) =====
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_testimonial'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $testimonial_error = 'Invalid request. Please try again.';
    } else {
        $testimony = trim($_POST['testimony']);
        $is_public = isset($_POST['is_public']) ? 1 : 0;
        
        if (empty($testimony)) {
            $testimonial_error = 'Please share your story.';
        } elseif (strlen($testimony) < 20) {
            $testimonial_error = 'Please write at least 20 characters.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO testimonials (user_id, testimony, is_public, approved, created_at)
                VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$userId, $testimony, $is_public]);
            $testimonial_success = 'Thank you for sharing! Your story will be reviewed and featured soon.';
            
            $admin_email = aw_admin_email();
            $subject = 'New Testimonial Submission';
            $body = "A new testimonial has been submitted.\n\nUser: " . $_SESSION['name'] . "\nPublic: " . ($is_public ? 'Yes' : 'No') . "\n\n$testimony";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }
    }
}

// ===== COMMUNITY VOICES (L1) =====
// Three public comments on poems, picked at random from the 30 most recent on each page load,
// each linking to the poem it is about. Replaces the 5-second auto-playing carousel.
$community_voices = [];
try {
    $community_voices = $db->query("SELECT * FROM (
            SELECT r.comment, r.created_at, p.id AS poem_id, p.title AS poem_title,
                   u.name, u.first_name, u.last_name, u.username
            FROM reviews r
            JOIN users u ON u.id = r.user_id
            JOIN poems p ON p.id = r.target_id
            WHERE r.target_type = 'poem' AND r.deleted_at IS NULL AND r.is_private = 0
              AND TRIM(COALESCE(r.comment, '')) != ''
              AND p.id NOT IN (SELECT poem_id FROM poem_status WHERE status = 'draft')
            ORDER BY r.created_at DESC LIMIT 30
        ) ORDER BY RANDOM() LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('home community voices: ' . $e->getMessage());
}

// C2: "Angella's picks" — a poem, a story and a reflection chosen by the team (admin/picks.php)
require_once 'includes/picks.php';
require_once 'includes/poem_card.php'; // M1
$aw_picks = aw_picks($db);

$pageTitle = 'AngelWrites — Christian Writing & Community';
?>
<?php require_once 'includes/header.php'; ?>
<?php echo aw_poem_assets(); /* M1: poem card styles */ ?>

<!-- PWA Manifest and Service Worker -->
<link rel="manifest" href="/manifest.json">
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
</script>

<style>
    /* ===== ROOT & BASE ===== */
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
    body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }

    /* ===== TYPOGRAPHY ===== */
    h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
    h2 { font-size:2.2rem; margin-bottom:8px; }
    .section-header { text-align:center; max-width:700px; margin:0 auto 32px; }
    .section-header h2 { margin-bottom:4px; }
    .section-header p { color:var(--text-light); font-size:1.05rem; }

    .rose-text { color:var(--rose); }
    .text-center { text-align:center; }

    /* ===== PROFESSIONAL ABOUT TEXT ===== */
.about-section {
    background: var(--fantasy);
    border-top: 1px solid var(--rose-light);
    border-bottom: 1px solid var(--rose-light);
    padding: 60px 0;
}

.about-text {
    max-width: 820px;
    margin: 0 auto;
    padding: 0 20px;
    text-align: center;
}

/* ---- Heading ---- */
.about-text h2 {
    font-size: 2.4rem;
    margin-bottom: 12px;
    position: relative;
    display: inline-block;
    padding-bottom: 10px;
}
.about-text h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background: var(--rose);
    border-radius: 4px;
}

/* ---- Lead (the first long sentence) ---- */
.about-lead-wrapper {
    margin: 20px 0 24px;
}
/* ===== CORMORANT GARAMOND – ELEGANT & VISIBLE ===== */
.about-lead {
    font-family: 'Cormorant Garamond', Georgia, serif !important;
    font-size: 1.6rem !important;
    font-weight: 600 !important;
    font-style: italic !important;
    line-height: 1.6 !important;
    color: var(--dark) !important; /* Much darker for visibility */
    padding: 24px 32px !important;
    background: rgba(219, 161, 162, 0.15) !important;
    border-left: 6px solid var(--rose) !important;
    border-radius: 0 16px 16px 0 !important;
    text-align: left !important;
    max-width: 740px !important;
    margin: 0 auto !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04) !important;
}

.hero-quote {
    font-family: 'Cormorant Garamond', Georgia, serif !important;
    font-size: 1.5rem !important;
    font-weight: 700 !important;
    font-style: italic !important;
    color: var(--dark) !important; /* Darker for visibility */
    text-align: center !important;
    max-width: 480px !important;
    margin: 8px auto 0 !important;
    line-height: 1.5 !important;
    letter-spacing: 0.3px !important;
    padding: 0 12px !important;
}

.hero-quote::after {
    content: '';
    display: block;
    width: 50px;
    height: 2px;
    background: var(--rose);
    margin: 8px auto 0;
    border-radius: 4px;
}
/* ---- Body paragraphs ---- */
.about-body {
    font-size: 1.05rem;
    line-height: 1.8;
    color: var(--text);
    max-width: 650px;
    margin: 0 auto 18px;
    text-align: center;
}
.about-body strong {
    color: var(--rose-dark);
    font-weight: 700;
}

/* ---- "Here, you will find:" ---- */
.about-body-intro {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--dark);
    margin-top: 8px;
    margin-bottom: 12px;
    letter-spacing: 0.5px;
}

/* ---- Features Grid (already included from previous) ---- */
.about-features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-top: 24px;
}
.about-feature {
    background: var(--card-bg);
    border: 1px solid var(--rose-light);
    border-radius: 16px;
    padding: 24px 20px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.about-feature:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
    border-color: var(--rose);
}

    /* ===== ENHANCED BUTTONS ===== */
    .btn {
        display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
        border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
        cursor:pointer; text-decoration:none; transition:all var(--transition);
        box-shadow:0 3px 10px rgba(44,30,30,0.12);
        letter-spacing:0.3px;
    }
    .btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
    .btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
    .btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
    .btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
    .btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
    .btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
    .btn-outline:hover { background:var(--rose); color:var(--white); }
    .btn-white { background:var(--white); color:var(--dark); border:2px solid var(--white); }
    .btn-white:hover { background:var(--vanilla); border-color:var(--vanilla); }
    .btn-white-outline { background:transparent; border:2px solid var(--white); color:var(--white); }
    .btn-white-outline:hover { background:var(--white); color:var(--dark); }
    .btn-sm { padding:8px 20px; font-size:0.85rem; }
    .btn-sm:hover { transform:translateY(-1px); }

    /* ===== SECTIONS ===== */
    .section-padding { padding:60px 0; }
    .container { max-width:1200px; margin:0 auto; padding:0 20px; }

    /* ===== HERO ===== */
    .hero { padding:60px 0; background:linear-gradient(135deg,#DBA1A2 0%,#EFD8D6 50%,#F7F3ED 100%); }
    .hero-content { display:grid; grid-template-columns:1fr 1fr; gap:40px; align-items:center; }
    .hero-badge { display:inline-block; background:var(--rose); color:white; padding:4px 16px; border-radius:20px; font-size:0.85rem; font-weight:600; letter-spacing:0.5px; margin-bottom:12px; }
    .hero h1 { font-size:3rem; margin:0 0 12px; }
    .hero-sub { font-size:1.2rem; color:var(--text-light); margin-bottom:24px; max-width:480px; }
    .hero-buttons { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .hero-search { max-width:400px; }
    .hero-search .search-form { display:flex; gap:8px; }
    .hero-search .search-form input { flex:1; padding:10px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.95rem; background:var(--input-bg); color:var(--text); }
    .hero-search .search-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }

    /* ===== HERO IMAGE & QUOTE (no container) ===== */
    .hero-image {
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:12px;
    }
    .hero-image-container { width:420px; height:420px; display:flex; justify-content:center; align-items:center; }
    .hero-image-container img { width:100%; height:100%; object-fit:contain; }
    
    /* ===== ABOUT ===== */
    .about-section { text-align:center; }
    .about-text { max-width:800px; margin:0 auto; }
    .about-lead { font-size:1.2rem; color:var(--text-light); font-weight:500; font-style:italic; }
    .about-features-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin:20px 0; }
    .about-feature { background:var(--card-bg); border:1px solid var(--border); border-radius:12px; padding:20px; transition:all var(--transition); text-align:center; }
    .about-feature:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); }
    .about-feature i { font-size:1.5rem; color:var(--rose); display:block; margin-bottom:4px; }
    .about-feature h4 { font-size:1rem; margin-bottom:2px; }
    .about-feature p { font-size:0.85rem; color:var(--text-light); margin:0; }

    /* ===== STATS ===== */
    .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:24px; text-align:center; }
    .stat-item { background:var(--card-bg); border-radius:16px; padding:24px; border:1px solid var(--border); box-shadow:var(--shadow); transition:all var(--transition); }
    .stat-item:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); }
    .stat-number { font-size:2.6rem; font-weight:700; color:var(--rose); }
    .stat-label { font-size:0.95rem; color:var(--text-light); margin-top:4px; }

    /* ===== L1: VOICES (testimonials and community comments share one card) ===== */
    .aw-voices { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:24px; }
    .aw-voice { margin:0; background:var(--card-bg); border:1px solid var(--border); border-radius:20px; padding:26px 24px 22px; box-shadow:var(--shadow); display:flex; flex-direction:column; gap:14px; position:relative; }
    .aw-voice::before { content:"\201C"; position:absolute; top:6px; left:18px; font:700 3.4rem/1 'Playfair Display', Georgia, serif; color:var(--rose-light, #e8c0c0); pointer-events:none; }
    .aw-voice blockquote { margin:0; padding-top:18px; }
    .aw-voice blockquote p { margin:0; font-size:1rem; line-height:1.65; color:var(--text); font-style:italic; }
    .aw-voice figcaption { margin-top:auto; font-weight:600; font-size:.9rem; color:var(--text-light); }
    .aw-voice figcaption a { color:var(--rose-dark, #a8676a); }
    .aw-voices-empty { text-align:center; color:var(--text-light); }
    .aw-invite { margin:28px auto 0; max-width:640px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:12px; }
    .aw-invite p { margin:0; color:var(--text-light); }

    /* ===== CONTENT GATE ===== */
    .content-gate { background:var(--vanilla); border-top:2px solid var(--rose); border-bottom:2px solid var(--rose); padding:60px 0; }
    .gate-message { text-align:center; max-width:600px; margin:0 auto; }
    .gate-icon { font-size:3rem; color:var(--rose); margin-bottom:12px; }
    .gate-message h2 { font-size:2rem; margin-bottom:8px; }
    .gate-message p { font-size:1.1rem; color:var(--text-light); }
    .gate-buttons { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; margin-top:16px; }

    /* ===== MODAL ===== */
    .modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:2000; display:none; align-items:center; justify-content:center; }
    .modal-content { background:var(--card-bg); border-radius:20px; padding:32px; max-width:500px; width:90%; box-shadow:var(--shadow-hover); border:1px solid var(--rose-light); }
    .modal-content h3 { margin-top:0; }
    .modal-content textarea { width:100%; padding:12px; border:1px solid var(--border); border-radius:12px; resize:vertical; min-height:80px; font-size:0.95rem; background:var(--input-bg); color:var(--text); }
    .modal-content textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
    .modal-actions { display:flex; gap:12px; margin-top:12px; justify-content:flex-end; }

    /* ===== BOOKS GRID ===== */
    .books-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:24px; }
    .book-card { background:var(--card-bg); border-radius:16px; overflow:hidden; border:1px solid var(--border); transition:all var(--transition); display:flex; flex-direction:column; height:100%; }
    .book-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-hover); }
    .book-cover-wrapper { position:relative; height:260px; overflow:hidden; flex-shrink:0; }
    .book-cover-wrapper img { width:100%; height:100%; object-fit:cover; transition:transform 0.3s; }
    .book-cover-wrapper:hover img { transform:scale(1.05); }
    .placeholder-cover { width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:var(--vanilla); font-size:3rem; color:var(--text-light); }
    .badge { position:absolute; top:8px; right:8px; padding:4px 12px; border-radius:20px; font-size:0.7rem; font-weight:700; text-transform:uppercase; color:white; }
    .badge.free { background:#4f8a63; }
    .badge.sale { background:#b4505a; }
    .book-details { padding:16px; display:flex; flex-direction:column; flex:1; justify-content:space-between; }
    .book-details h3 { font-size:1.05rem; margin:0 0 4px; line-height:1.3; }
    .book-author { font-size:0.85rem; color:var(--text-light); }
    .book-description { font-size:0.85rem; line-height:1.5; color:var(--text); max-height:64px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; transition:max-height 0.3s; }
    .book-description.expanded { max-height:500px; -webkit-line-clamp:unset; }
    .toggle-desc-btn { background:none; border:none; color:var(--rose); font-size:0.75rem; cursor:pointer; font-weight:600; margin-top:4px; }
    .toggle-desc-btn:hover { text-decoration:underline; }
    .book-bottom { display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding-top:8px; border-top:1px solid var(--border); }
    .book-price { font-size:0.9rem; font-weight:600; color:var(--text); }
    .free-text { color:#4f8a63; }
    .sale-text { color:#b4505a; text-decoration:line-through; }

    /* ===== BLOG ===== */
    .blog-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:24px; }
    .blog-card { background:var(--card-bg); border-radius:16px; overflow:hidden; border:1px solid var(--border); transition:all var(--transition); }
    .blog-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); }
    .blog-thumbnail { width:100%; height:180px; overflow:hidden; }
    .blog-thumbnail img { width:100%; height:100%; object-fit:cover; }
    .blog-content { padding:16px 20px; }
    .blog-meta { display:flex; gap:8px; flex-wrap:wrap; font-size:0.75rem; color:var(--text-light); margin-bottom:4px; }
    .blog-content h3 { font-size:1.1rem; margin:0 0 6px; }
    .blog-excerpt { font-size:0.9rem; color:var(--text-light); }

    /* ===== NEWSLETTER ===== */
    .newsletter-form { display:flex; gap:8px; max-width:500px; margin:16px auto; flex-wrap:wrap; justify-content:center; }
    .newsletter-form input { flex:1; min-width:180px; padding:10px 16px; border:1px solid var(--border); border-radius:50px; font-size:0.9rem; background:var(--input-bg); color:var(--text); }
    .newsletter-form input:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
    .newsletter-form .btn { padding:10px 24px; }

    /* ===== RESPONSIVE ===== */
    @media (max-width:992px) {
        .hero-content { grid-template-columns:1fr; text-align:center; }
        .hero h1 { font-size:2.4rem; }
        .hero-sub { margin:0 auto; }
        .hero-buttons { justify-content:center; }
        .hero-search { margin:0 auto; }
        .hero-search .search-form { flex-direction:column; }
        .hero-image-container { width:280px; height:280px; margin:0 auto; }
        .hero-quote { max-width:300px; }
        .about-features-grid { grid-template-columns:1fr 1fr; }
        .books-grid { grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); }
        .book-cover-wrapper { height:200px; }
    }
    @media (max-width:576px) {
        .about-features-grid { grid-template-columns:1fr; }
        .hero h1 { font-size:1.8rem; }
        .books-grid { grid-template-columns:1fr 1fr; }
        .book-cover-wrapper { height:160px; }
    }
/* C2: Angella's picks */
.aw-picks-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 360px)); justify-content:center; gap:24px; }
.aw-pick { display:flex; flex-direction:column; background:var(--card-bg, #fff); border:1px solid var(--border, #e5d5d5); border-radius:20px; overflow:hidden; box-shadow:var(--shadow); color:inherit; text-decoration:none; transition:transform .25s, box-shadow .25s; }
.aw-pick:hover, .aw-pick:focus-visible { transform:translateY(-4px); box-shadow:var(--shadow-hover); text-decoration:none; }
.aw-pick:focus-visible { outline:3px solid var(--rose-dark, #a8676a); outline-offset:3px; }
.aw-pick-img { position:relative; aspect-ratio:16/9; background:linear-gradient(135deg, var(--fantasy, #F7F3ED), var(--vanilla, #EFD8D6)); display:grid; place-items:center; overflow:hidden; }
.aw-pick-img img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
.aw-pick-img i { font-size:2.2rem; color:var(--rose, #DBA1A2); }
.aw-pick-body { padding:18px 20px 20px; display:flex; flex-direction:column; gap:8px; flex:1; }
.aw-pick-kind { font:600 .75rem/1 'Inter', sans-serif; letter-spacing:.08em; text-transform:uppercase; color:var(--text-light); }
.aw-pick-body h3 { margin:0; font-family:'Playfair Display', Georgia, serif; font-size:1.2rem; line-height:1.3; color:var(--text); }
.aw-pick-excerpt { margin:0; color:var(--text-light); font-size:.95rem; line-height:1.55; }
.aw-pick-why { margin:auto 0 0; padding-top:12px; border-top:1px solid var(--border, #e5d5d5); font-style:italic; color:var(--text); font-size:.92rem; line-height:1.5; }
@media (prefers-reduced-motion: reduce) { .aw-pick, .aw-pick:hover { transition:none; transform:none; } }
</style>

<!-- ===== HTML CONTENT ===== -->

<!-- HERO -->
<section class="hero">
    <div class="container hero-content">
        <div class="hero-text">
            <span class="hero-badge">✧ A Safe Place for Your Heart</span>
            <h1>
                <?php if ($isLoggedIn): ?>
                    Welcome Back, <span class="rose-text">Home</span>.
                <?php else: ?>
                    Your Story <span class="rose-text">Lives Here</span>
                <?php endif; ?>
            </h1>
            <p class="hero-sub">
                <?php if ($isLoggedIn): ?>
                    You are home. Dive deeper into faith, healing, and community.
                <?php else: ?>
                    No judgment. No criticism. Just kindness, love, and a God who holds your pain with you. Here, you can show up exactly as you are — raw, broken, honest — and be met with grace.
                <?php endif; ?>
            </p>
            <div class="hero-buttons">
                <?php if ($isLoggedIn): ?>
                    <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-primary">Browse Books</a>
                    <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Read Poetry</a>
                    <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-secondary">Book a Session</a>
                <?php else: ?>
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Join Free — No Credit Card</a>
                    <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline">Sign In</a>
                    <a href="#about" class="btn btn-secondary">See What's Here for You</a>
                <?php endif; ?>
            </div>
            <div class="hero-search">
                <form action="<?php echo SITE_URL; ?>/search_results.php" method="GET" class="search-form">
                    <input type="text" name="q" placeholder="Search books, poems, reflections..." required>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>
        </div>
        <div class="hero-image">
            <div class="hero-image-container">
                <img src="<?php echo get_image_url('/assets/images/hero-logo.png'); ?>" alt="AngelWrites - Your Story Lives Here" loading="lazy">
            </div>
            <p class="hero-quote">"You don't have to be fixed before you walk in. Just come as you are."</p>
        </div>
    </div>
</section>

<!-- ABOUT -->
<section class="about-section section-padding" id="about">
    <div class="container">
        <div class="about-text">
            <h2>Welcome to <span class="rose-text">AngelWrites</span></h2>
            <div class="about-lead-wrapper">
                <p class="about-lead">You're here because something inside you is crying out for hope. You've been carrying pain, confusion, or loneliness — and you're looking for a place where you can just be real. You've found it.</p>
            </div>
            <p class="about-body">AngelWrites is not about one person. It's about <strong>you</strong> and every human like you who needs to know that God hasn't given up on you. This is a <strong>community</strong> where you can heal, grow, and discover that your story matters.</p>
            <p class="about-body-intro">Here, you will find:</p>
            <div class="about-features-grid">
                <div class="about-feature"><i class="fas fa-book-reader"></i><h4>Books &amp; Poems</h4><p>Read words that speak to your soul.</p></div>
                <div class="about-feature"><i class="fas fa-pen-fancy"></i><h4>Reflections &amp; Blog</h4><p>Daily thoughts, honest stories, and insights.</p></div>
                <div class="about-feature"><i class="fas fa-hands-praying"></i><h4>Prayer Support</h4><p>You don't have to pray alone.</p></div>
                <div class="about-feature"><i class="fas fa-comments"></i><h4>1-on-1 Chats</h4><p>Book a free, confidential session with Angella.</p></div>
                <div class="about-feature"><i class="fas fa-users"></i><h4>Reading Groups</h4><p>Join or create a circle where you can grow together.</p></div>
                <div class="about-feature"><i class="fas fa-bible"></i><h4>Bible Reader</h4><p>All common translations with highlights and notes.</p></div>
            </div>
            <p>This community was built by <strong>Angella Bottoman</strong> — a Christian writer who believes every broken vessel holds a beautiful story.</p>
            <div class="about-cta">
                <?php if (!$isLoggedIn): ?>
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Join the Community — It's Free</a>
                    <p class="about-small">Already a member? <a href="<?php echo SITE_URL; ?>/login.php">Sign in</a></p>
                <?php else: ?>
                    <a href="<?php echo SITE_URL; ?>/dashboard.php" class="btn btn-primary">Go to My Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($aw_picks)): ?>
<!-- C2: ANGELLA'S PICKS -->
<section class="picks-section section-padding" id="picks" aria-labelledby="picksTitle">
    <div class="container">
        <div class="section-header">
            <h2 id="picksTitle">Angella's <span class="rose-text">Picks</span></h2>
            <p>Chosen by hand, with a word on why.</p>
        </div>
        <div class="aw-picks-grid">
            <?php foreach ($aw_picks as $slot => $pk): ?>
                <a class="aw-pick" href="<?php echo htmlspecialchars($pk['url']); ?>">
                    <div class="aw-pick-img">
                        <i class="fas <?php echo $pk['icon']; ?>" aria-hidden="true"></i>
                        <?php if ($pk['image'] !== ''): ?>
                            <img src="<?php echo htmlspecialchars(get_image_url($pk['image'])); ?>" alt="" loading="lazy" onerror="this.remove()">
                        <?php endif; ?>
                    </div>
                    <div class="aw-pick-body">
                        <span class="aw-pick-kind"><?php echo htmlspecialchars($pk['label']); ?></span>
                        <h3><?php echo htmlspecialchars($pk['title']); ?></h3>
                        <?php if ($pk['excerpt'] !== ''): ?><p class="aw-pick-excerpt"><?php echo htmlspecialchars($pk['excerpt']); ?></p><?php endif; ?>
                        <?php if ($pk['note'] !== ''): ?><p class="aw-pick-why"><?php echo htmlspecialchars($pk['note']); ?></p><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- STATS -->
<section class="stats-section section-padding" style="background-color: var(--vanilla);">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $total_users; ?>">0</div>
                <div class="stat-label">Members</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $free_books; ?>">0</div>
                <div class="stat-label">Free Books Downloaded</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo $total_prayers; ?>">0</div>
                <div class="stat-label">Prayers Offered</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" data-target="<?php echo (int)$testimonial_total; ?>">0</div>
                <div class="stat-label">Testimonies Shared</div>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIALS (L1: static cards, three per page load) -->
<section class="testimonials-section section-padding" id="testimonials">
    <div class="container">
        <div class="section-header">
            <h2>Real Stories. <span class="rose-text">Real Hope.</span></h2>
            <p>Hear from members whose lives have been touched by AngelWrites.</p>
        </div>
        <?php if ($testimonials): ?>
            <div class="aw-voices">
                <?php foreach ($testimonials as $t): ?>
                    <figure class="aw-voice">
                        <blockquote><p><?php echo htmlspecialchars(aw_voice_short($t['testimony'], 280)); ?></p></blockquote>
                        <figcaption><?php echo htmlspecialchars(aw_voice_name($t)); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="aw-voices-empty">No stories have been shared yet.</p>
        <?php endif; ?>
        <div class="aw-invite">
            <?php if ($isLoggedIn): ?>
                <p>Has something here helped you? Your story could be the hope someone needs today.</p>
                <button type="button" class="btn btn-primary" id="testimonialPromptBtn">Share your story</button>
            <?php else: ?>
                <p>You do not have to be fixed before you walk in. There is a place here for you.</p>
                <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Join the community</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- TESTIMONIAL MODAL -->
<div id="testimonialModal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>Share Your AngelWrites Story</h3>
        <p>Your testimony could be the hope someone needs today.</p>
        <?php if (isset($testimonial_error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($testimonial_error); ?></div><?php endif; ?>
        <?php if (isset($testimonial_success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($testimonial_success); ?></div><?php endif; ?>
        <form method="POST" action="<?php echo SITE_URL; ?>/index.php#testimonials">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="submit_testimonial" value="1">
            <div class="form-group">
                <label for="testimony">Your Story</label>
                <textarea id="testimony" name="testimony" rows="4" placeholder="Share how AngelWrites has impacted your life..." required></textarea>
                <small>Minimum 20 characters</small>
            </div>
            <div class="checkbox-group">
                <input type="checkbox" id="is_public" name="is_public" checked>
                <label for="is_public">Yes, I want my story featured publicly</label>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">Submit</button>
                <button type="button" class="btn btn-secondary" id="closeTestimonialModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== CONTENT GATING ===== -->
<?php if (!$isLoggedIn): ?>
    <div class="content-gate">
        <div class="container">
            <div class="gate-message">
                <i class="fas fa-lock gate-icon"></i>
                <h2>Explore the Full Community</h2>
                <p>Books, poems, reading groups, and more – all waiting for you.</p>
                <div class="gate-buttons">
                    <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">Create Free Account</a>
                    <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline">Already a member? Log In</a>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>

    <!-- RECOMMENDED BOOKS -->
    <?php if (!empty($recommended_books)): ?>
    <section class="recommended-section section-padding" style="background-color: var(--vanilla);">
        <div class="container">
            <div class="section-header">
                <h2>Recommended for <span class="rose-text">You</span></h2>
                <p>Based on your reading journey – books we think you'll love.</p>
            </div>
            <div class="books-grid">
                <?php foreach ($recommended_books as $book): ?>
                <div class="book-card">
                    <div class="book-cover-wrapper">
                        <?php if ($book['cover_path']): ?>
                            <img src="<?php echo get_image_url($book['cover_path']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                        <?php if ($book['is_free']): ?><span class="badge free">Free</span><?php endif; ?>
                        <?php if (aw_book_on_sale($book)): // F79 ?><span class="badge sale">Sale</span><?php endif; ?>
                    </div>
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <p class="book-author">by Angella Bottoman</p>
                        <div class="book-description-wrapper">
                            <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                            </div>
                            <?php if (strlen($book['description'] ?? '') > 100): ?>
                                <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                            <?php endif; ?>
                        </div>
                        <div class="book-bottom">
                            <div class="book-price">
                                <?php if ($book['is_free']): ?>
                                    <span class="free-text">Free</span>
                                <?php elseif (aw_book_on_sale($book)): ?>
                                    <span class="sale-text"><s><?php echo aw_mwk($book['price']); ?></s> <?php echo aw_mwk($book['sale_price']); ?></span><!-- F79 -->
                                <?php else: ?>
                                    <span><?php echo aw_mwk($book['price']); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-book-open"></i> Read
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- FEATURED BOOKS -->
    <?php if (!empty($featured_books)): ?>
    <section class="featured-books section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Featured <span class="rose-text">Books</span></h2>
                <p>Explore Angella's latest writings and download free or purchase.</p>
            </div>
            <div class="books-grid">
                <?php foreach ($featured_books as $book): ?>
                <div class="book-card">
                    <div class="book-cover-wrapper">
                        <?php if ($book['cover_path']): ?>
                            <img src="<?php echo get_image_url($book['cover_path']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="placeholder-cover"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                        <?php if ($book['is_free']): ?><span class="badge free">Free</span><?php endif; ?>
                        <?php if (aw_book_on_sale($book)): // F79 ?><span class="badge sale">Sale</span><?php endif; ?>
                    </div>
                    <div class="book-details">
                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                        <p class="book-author">by Angella Bottoman</p>
                        <div class="book-description-wrapper">
                            <div class="book-description" id="desc-<?php echo $book['id']; ?>">
                                <?php echo nl2br(htmlspecialchars($book['description'] ?? 'A beautiful story waiting to be read.')); ?>
                            </div>
                            <?php if (strlen($book['description'] ?? '') > 100): ?>
                                <button class="toggle-desc-btn" data-id="<?php echo $book['id']; ?>">Read More</button>
                            <?php endif; ?>
                        </div>
                        <div class="book-bottom">
                            <div class="book-price">
                                <?php if ($book['is_free']): ?>
                                    <span class="free-text">Free</span>
                                <?php elseif (aw_book_on_sale($book)): ?>
                                    <span class="sale-text"><s><?php echo aw_mwk($book['price']); ?></s> <?php echo aw_mwk($book['sale_price']); ?></span><!-- F79 -->
                                <?php else: ?>
                                    <span><?php echo aw_mwk($book['price']); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo $book['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-book-open"></i> Read
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/books.php" class="btn btn-outline">View All Books →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- LATEST POEMS -->
    <?php if (!empty($latest_poems)): ?>
    <section class="latest-poems section-padding" style="background-color: var(--vanilla);">
        <div class="container">
            <div class="section-header">
                <h2>Latest <span class="rose-text">Poems</span></h2>
                <p>Words that speak to the soul.</p>
            </div>
            <!-- M1: the same poem card as the Poetry page -->
            <div class="aw-cat-grid">
                <?php foreach ($latest_poems as $poem): ?>
                    <?php echo aw_poem_card($poem); ?>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Explore All Poems →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA SECTION -->
    <section class="cta-section section-padding" style="background: linear-gradient(135deg, #DBA1A2 0%, #EFD8D6 100%);">
        <div class="container">
            <div class="cta-content">
                <div class="cta-text">
                    <h2>Need Guidance or a Listening Ear?</h2>
                    <p>Book a 1-on-1 live session with Angella. She is passionate about helping women discover their purpose and find healing through faith.</p>
                    <div class="cta-buttons">
                        <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-white">Book a Session</a>
                        <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-white-outline">Join Community Q&A</a>
                    </div>
                </div>
                <div class="cta-image"><i class="fas fa-hands-praying"></i></div>
            </div>
        </div>
    </section>

    <!-- BLOG POSTS -->
    <?php if (!empty($latest_posts)): ?>
    <section class="latest-blog section-padding">
        <div class="container">
            <div class="section-header">
                <h2>Christian <span class="rose-text">Reflections</span></h2>
                <p>Faith, hope, and encouragement for everyday life.</p>
            </div>
            <div class="blog-grid">
                <?php foreach ($latest_posts as $post): ?>
                    <div class="blog-card">
                        <?php if ($post['featured_image']): ?>
                            <div class="blog-thumbnail">
                                <img src="<?php echo get_image_url($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="blog-content">
                            <div class="blog-meta">
                                <span class="blog-category"><?php echo htmlspecialchars($post['category']); ?></span>
                                <span class="blog-date"><?php echo date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])); ?></span>
                            </div>
                            <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                            <?php if ($post['excerpt']): ?>
                                <p class="blog-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                            <?php else: ?>
                                <p class="blog-excerpt"><?php echo htmlspecialchars(substr($post['content'], 0, 120)); ?>...</p>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo $post['slug']; ?>" class="read-more">Read full reflection →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="section-footer">
                <a href="<?php echo SITE_URL; ?>/blog.php" class="btn btn-outline">Read All Reflections →</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

<?php endif; ?>

<!-- ===== COMMUNITY VOICES (L1: static cards, three per page load) ===== -->
<?php if ($community_voices): ?>
<section class="community-voices-section section-padding" aria-labelledby="voicesTitle">
    <div class="container">
        <div class="section-header">
            <h2 id="voicesTitle">What the <span class="rose-text">Community</span> Is Saying</h2>
            <p>Words members left on the poems.</p>
        </div>
        <div class="aw-voices">
            <?php foreach ($community_voices as $c): ?>
                <figure class="aw-voice">
                    <blockquote><p><?php echo htmlspecialchars(aw_voice_short($c['comment'], 220)); ?></p></blockquote>
                    <figcaption><?php echo htmlspecialchars(aw_voice_name($c)); ?>, on <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo (int)$c['poem_id']; ?>"><?php echo htmlspecialchars($c['poem_title']); ?></a></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
        <div class="aw-invite">
            <p>Read a poem that speaks to you, and leave a word for the next reader.</p>
            <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline">Read the poems</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- NEWSLETTER -->
<section class="newsletter-section section-padding" style="background-color: var(--fantasy);">
    <div class="container">
        <div class="newsletter-content">
            <h2>Stay <span class="rose-text">Inspired</span></h2>
            <p>Join the newsletter to receive Angella's latest writings, book updates, and free resources directly to your inbox.</p>
            <?php if (isset($newsletter_error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($newsletter_error); ?></div><?php endif; ?>
            <?php if (isset($newsletter_success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($newsletter_success); ?></div><?php endif; ?>
            <form action="<?php echo SITE_URL; ?>/index.php" method="POST" class="newsletter-form">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="email" name="newsletter_email" placeholder="Your email address" required>
                <input type="text" name="newsletter_name" placeholder="Your name (optional)">
                <button type="submit" class="btn btn-primary">Subscribe Free</button>
            </form>
            <small>No spam. Unsubscribe anytime.</small>
        </div>
    </div>
</section>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Description (from books)
    document.querySelectorAll('.toggle-desc-btn').forEach(btn => {
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

    // Stats Counter
    const statNumbers = document.querySelectorAll('.stat-number');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const target = parseInt(entry.target.dataset.target);
                animateNumber(entry.target, target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    statNumbers.forEach(stat => observer.observe(stat));

    function animateNumber(element, target) {
        let current = 0;
        const increment = Math.ceil(target / 50);
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                element.textContent = target;
                clearInterval(timer);
            } else {
                element.textContent = current;
            }
        }, 30);
    }

    // Testimonial Modal
    const modal = document.getElementById('testimonialModal');
    const openBtn = document.getElementById('testimonialPromptBtn');
    const closeBtn = document.getElementById('closeTestimonialModal');
    if (openBtn && modal) {
        openBtn.addEventListener('click', function() { modal.style.display = 'flex'; });
    }
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
        window.addEventListener('click', function(e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

});
</script>

<?php
// ============================================================
// 5. SAVE CACHE FOR ANONYMOUS USERS
// ============================================================
if ($doCache) {
    $content = ob_get_contents();
    // F174: never store this visitor's CSRF token in the shared cache file.
    if (!empty($_SESSION['csrf_token'])) {
        $content = str_replace($_SESSION['csrf_token'], '__AW_CSRF_TOKEN__', $content);
    }
    file_put_contents($cacheFile, $content);
    ob_end_flush();
}
?>

<?php require_once 'includes/footer.php'; ?>