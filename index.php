<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ============================================================
// 1. FULL-PAGE CACHE FOR ANONYMOUS USERS
// ============================================================
$cacheFile = __DIR__ . '/cache/index.html';
$cacheTime = 300; // 5 minutes

// If user is not logged in, serve cached version if available
if (!isLoggedIn()) {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        readfile($cacheFile);
        exit;
    }
    ob_start();
    $doCache = true;
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
$stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$latest_poems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Latest Blog Posts (published, latest 3)
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 3");
$stmt->execute();
$latest_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH TESTIMONIALS (real, approved) =====
$stmt = $db->prepare("SELECT * FROM testimonials WHERE approved = 1 ORDER BY created_at DESC LIMIT 8");
$stmt->execute();
$testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['is_active'] == 1) {
                    $newsletter_error = 'This email is already subscribed.';
                } else {
                    $stmt = $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$existing['id']]);
                    $newsletter_success = 'Your subscription has been reactivated. Welcome back!';
                    
                    $admin_email = 'angelwrites@zohomail.com';
                    $subject = 'Newsletter Subscription Reactivated';
                    $body = "A user has reactivated their newsletter subscription.\n\nEmail: $email\nName: " . ($name ?: 'Not provided');
                    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                }
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token) VALUES (?, ?, 1, ?)");
                if ($stmt->execute([$email, $name, $token])) {
                    $newsletter_success = 'Thank you for subscribing! You will receive updates from Angella.';
                    
                    $admin_email = 'angelwrites@zohomail.com';
                    $subject = 'New Newsletter Subscriber';
                    $body = "A new user has subscribed to the newsletter.\n\nEmail: $email\nName: " . ($name ?: 'Not provided');
                    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                } else {
                    $newsletter_error = 'Something went wrong. Please try again.';
                }
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
            
            $admin_email = 'angelwrites@zohomail.com';
            $subject = 'New Testimonial Submission';
            $body = "A new testimonial has been submitted.\n\nUser: " . $_SESSION['name'] . "\nPublic: " . ($is_public ? 'Yes' : 'No') . "\n\n$testimony";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
        }
    }
}

// ===== FETCH POEM WITH MOST COMMENTS FOR CAROUSEL =====
$carousel_poem = null;
$carousel_comments = [];
$stmt = $db->prepare("
    SELECT p.*, COUNT(r.id) as comment_count
    FROM poems p
    JOIN reviews r ON r.target_type = 'poem' AND r.target_id = p.id
    WHERE r.deleted_at IS NULL AND r.is_private = 0
    GROUP BY p.id
    ORDER BY comment_count DESC
    LIMIT 1
");
$stmt->execute();
$carousel_poem = $stmt->fetch(PDO::FETCH_ASSOC);

if ($carousel_poem) {
    $stmt = $db->prepare("
        SELECT r.*, u.name AS author_name
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.target_type = 'poem' AND r.target_id = ? AND r.deleted_at IS NULL AND r.is_private = 0
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$carousel_poem['id']]);
    $carousel_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($carousel_comments)) {
        $stmt = $db->prepare("SELECT * FROM poems ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $carousel_poem = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($carousel_poem) {
            $stmt = $db->prepare("
                SELECT r.*, u.name AS author_name
                FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.target_type = 'poem' AND r.target_id = ? AND r.deleted_at IS NULL AND r.is_private = 0
                ORDER BY r.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$carousel_poem['id']]);
            $carousel_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$pageTitle = 'AngelWrites — Christian Writing & Community';
?>
<?php require_once 'includes/header.php'; ?>

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

    /* ===== TESTIMONIALS ===== */
    .testimonial-carousel { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:24px; }
    .testimonial-card { perspective:800px; height:220px; }
    .testimonial-card .card-inner { position:relative; width:100%; height:100%; transition:transform 0.8s; transform-style:preserve-3d; }
    .testimonial-card:hover .card-inner { transform:rotateY(180deg); }
    .testimonial-card .card-front, .testimonial-card .card-back { position:absolute; width:100%; height:100%; backface-visibility:hidden; border-radius:16px; padding:20px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; border:1px solid var(--border); box-shadow:var(--shadow); }
    .testimonial-card .card-front { background:var(--card-bg); }
    .testimonial-card .card-back { background:var(--rose); color:white; transform:rotateY(180deg); }
    .testimonial-avatar { font-size:2.5rem; color:var(--rose); margin-bottom:6px; }
    .testimonial-quote { font-size:0.9rem; line-height:1.5; font-style:italic; }
    .testimonial-author { font-weight:600; font-size:0.85rem; color:var(--text-light); margin-top:6px; }

    /* ===== CONTENT GATE ===== */
    .content-gate { background:var(--vanilla); border-top:2px solid var(--rose); border-bottom:2px solid var(--rose); padding:60px 0; }
    .gate-message { text-align:center; max-width:600px; margin:0 auto; }
    .gate-icon { font-size:3rem; color:var(--rose); margin-bottom:12px; }
    .gate-message h2 { font-size:2rem; margin-bottom:8px; }
    .gate-message p { font-size:1.1rem; color:var(--text-light); }
    .gate-buttons { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; margin-top:16px; }

    /* ===== STICKY CTA ===== */
   .sticky-cta{position:fixed;bottom:0;left:0;width:100%;background:var(--rose);color:#fff;padding:12px 0;z-index:999;box-shadow:0 -4px 20px rgba(0,0,0,.1)}.sticky-cta .container{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}.sticky-cta p{margin:0;font-size:.95rem;font-weight:600}.sticky-cta-buttons{display:flex!important;gap:10px}.sticky-cta .btn-primary,.sticky-cta .btn-outline{border:2px solid #fff!important}.sticky-cta .btn-primary{background:#fff!important;color:var(--rose)!important}.sticky-cta .btn-primary:hover{background:transparent!important;color:#fff!important}.sticky-cta .btn-outline{background:var(--rose)!important;color:#fff!important}.sticky-cta .btn-outline:hover{background:#fff!important;color:var(--rose)!important}.sticky-cta-close{background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;padding:0 4px}
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
    .badge.free { background:#28a745; }
    .badge.sale { background:#dc3545; }
    .book-details { padding:16px; display:flex; flex-direction:column; flex:1; justify-content:space-between; }
    .book-details h3 { font-size:1.05rem; margin:0 0 4px; line-height:1.3; }
    .book-author { font-size:0.85rem; color:var(--text-light); }
    .book-description { font-size:0.85rem; line-height:1.5; color:var(--text); max-height:64px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; transition:max-height 0.3s; }
    .book-description.expanded { max-height:500px; -webkit-line-clamp:unset; }
    .toggle-desc-btn { background:none; border:none; color:var(--rose); font-size:0.75rem; cursor:pointer; font-weight:600; margin-top:4px; }
    .toggle-desc-btn:hover { text-decoration:underline; }
    .book-bottom { display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding-top:8px; border-top:1px solid var(--border); }
    .book-price { font-size:0.9rem; font-weight:600; color:var(--text); }
    .free-text { color:#28a745; }
    .sale-text { color:#dc3545; text-decoration:line-through; }

    /* ===== POEMS ===== */
    .poem-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:24px; }
    .poem-card { background:var(--card-bg); border-radius:16px; padding:20px; border:1px solid var(--border); transition:all var(--transition); }
    .poem-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); }
    .poem-thumbnail { width:100%; height:180px; border-radius:12px; overflow:hidden; margin-bottom:12px; }
    .poem-thumbnail img { width:100%; height:100%; object-fit:cover; }
    .poem-content h3 { font-size:1.1rem; margin:0 0 6px; }
    .intro-label { display:block; font-size:0.7rem; font-weight:600; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px; }
    .poem-excerpt { font-size:0.9rem; color:var(--text-light); }
    .poem-audio audio { width:100%; margin-top:8px; border-radius:8px; }

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
        .sticky-cta .container { flex-direction:column; text-align:center; }
        .testimonial-card { height:200px; }
        .books-grid { grid-template-columns:1fr 1fr; }
        .book-cover-wrapper { height:160px; }
    }
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
                <div class="stat-number" data-target="<?php echo count($testimonials); ?>">0</div>
                <div class="stat-label">Testimonies Shared</div>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIALS -->
<section class="testimonials-section section-padding">
    <div class="container">
        <div class="section-header">
            <h2>Real Stories. <span class="rose-text">Real Hope.</span></h2>
            <p>Hear from members whose lives have been touched by AngelWrites.</p>
        </div>
        <?php if ($isLoggedIn): ?>
        <div class="testimonial-prompt">
            <p><i class="fas fa-heart" style="color: var(--rose);"></i> Share your AngelWrites story – your testimony could be the hope someone needs today.</p>
            <button class="btn btn-primary btn-sm" id="testimonialPromptBtn">Share Your Story</button>
        </div>
        <?php endif; ?>
        <div class="testimonial-carousel" id="testimonialCarousel">
            <?php if (count($testimonials) > 0): ?>
                <?php foreach ($testimonials as $index => $testimonial): ?>
                    <?php 
                    $colors = ['#DBA1A2', '#F7B7A3', '#A8D5BA', '#F3D8C7', '#C4A5C9', '#E8C9A0', '#A3C6D4', '#F0D4D4'];
                    $color = $colors[$index % count($colors)];
                    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $stmt->execute([$testimonial['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $name = $user ? $user['name'] : 'Anonymous';
                    ?>
                    <div class="testimonial-card" style="--card-color: <?php echo $color; ?>;">
                        <div class="card-inner">
                            <div class="card-front">
                                <div class="testimonial-avatar"><i class="fas fa-user-circle"></i></div>
                                <p class="testimonial-quote">"<?php echo htmlspecialchars($testimonial['testimony']); ?>"</p>
                                <span class="testimonial-author">– <?php echo htmlspecialchars($name); ?></span>
                            </div>
                            <div class="card-back">
                                <i class="fas fa-pray"></i>
                                <p class="testimonial-prayer">Praying for you, <?php echo htmlspecialchars($name); ?>. May God's peace fill your heart.</p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-content">No stories yet. Be the first to share yours.</p>
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
    <div class="sticky-cta" id="stickyCta">
        <div class="container">
            <p><strong>Join AngelWrites Free</strong> – No credit card required. Start your healing journey.</p>
            <div class="sticky-cta-buttons">
                <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary btn-sm">Get Started</a>
                <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline btn-sm">Sign In</a>
            </div>
            <button class="sticky-cta-close" onclick="document.getElementById('stickyCta').style.display='none'">×</button>
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
                        <?php if ($book['is_sale']): ?><span class="badge sale">Sale</span><?php endif; ?>
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
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php else: ?>
                                    <span>MWK <?php echo number_format($book['price'], 2); ?></span>
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
                        <?php if ($book['is_sale']): ?><span class="badge sale">Sale</span><?php endif; ?>
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
                                <?php elseif ($book['is_sale']): ?>
                                    <span class="sale-text">MWK <?php echo number_format($book['price'], 2); ?></span>
                                <?php else: ?>
                                    <span>MWK <?php echo number_format($book['price'], 2); ?></span>
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
            <div class="poem-grid">
                <?php foreach ($latest_poems as $poem): ?>
                    <?php 
                    $intro_parts = explode("\n\n", $poem['intro'] ?? '');
                    $verse = $intro_parts[0] ?? '';
                    $purpose = $intro_parts[1] ?? '';
                    ?>
                    <div class="poem-card">
                        <?php if ($poem['image_path']): ?>
                            <div class="poem-thumbnail">
                                <img src="<?php echo get_image_url($poem['image_path']); ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="poem-content">
                            <h3><?php echo htmlspecialchars($poem['title']); ?></h3>
                            <?php if ($verse): ?>
                                <div class="poem-intro-preview">
                                    <span class="intro-label">✧ Verse</span>
                                    <p><?php echo htmlspecialchars(substr($verse, 0, 150)); ?><?php if (strlen($verse) > 150) echo '...'; ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($purpose): ?>
                                <p class="poem-excerpt"><?php echo htmlspecialchars(substr($purpose, 0, 120)); ?><?php if (strlen($purpose) > 120) echo '...'; ?></p>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $poem['id']; ?>" class="read-more">Read full poem →</a>
                        </div>
                        <?php if ($poem['audio_path']): ?>
                            <div class="poem-audio">
                                <audio controls>
                                    <source src="<?php echo SITE_URL . '/' . $poem['audio_path']; ?>" type="audio/mpeg">
                                </audio>
                            </div>
                        <?php endif; ?>
                    </div>
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

<!-- ===== CYCLIC COMMENT CAROUSEL ===== -->
<section class="comment-carousel-section">
    <div class="container">
        <div class="section-header">
            <h2>What the <span class="rose-text">Community</span> Is Saying</h2>
            <p>Real voices from real readers – <em><?php echo htmlspecialchars($carousel_poem ? $carousel_poem['title'] : 'our latest poem'); ?></em></p>
        </div>
        <?php if ($carousel_poem && count($carousel_comments) > 0): ?>
            <div class="carousel-container">
                <div class="carousel-wrapper" id="carouselWrapper">
                    <?php foreach ($carousel_comments as $index => $comment): ?>
                        <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                            <div class="comment-card">
                                <div class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['author_name']); ?>
                                </div>
                                <?php if ($comment['rating'] > 0): ?>
                                    <div class="comment-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $comment['rating'] ? 'filled' : 'empty'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="comment-text">
                                    <?php echo nl2br(htmlspecialchars(substr($comment['comment'], 0, 200))); ?>
                                    <?php if (strlen($comment['comment']) > 200): ?>...<?php endif; ?>
                                </div>
                                <div class="comment-date">
                                    <?php echo date('M j, Y', strtotime($comment['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="carousel-controls">
                <button id="carouselPrev"><i class="fas fa-chevron-left"></i></button>
                <button id="carouselNext"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="carousel-indicators" id="carouselIndicators">
                <?php foreach ($carousel_comments as $index => $comment): ?>
                    <span class="dot <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="carousel-pause-indicator">Hover to pause</div>
            <div style="text-align:center; margin-top:12px;">
                <a href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $carousel_poem['id']; ?>" class="btn btn-outline btn-sm">Read this poem →</a>
            </div>
        <?php else: ?>
            <div class="empty-state" style="text-align:center; padding:40px;">
                <i class="fas fa-comments" style="font-size:2.5rem; color:var(--rose);"></i>
                <p>No comments yet. Be the first to share your thoughts!</p>
            </div>
        <?php endif; ?>
    </div>
</section>

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

    // Sticky CTA Close
    const stickyCta = document.getElementById('stickyCta');
    if (stickyCta) {
        const closeBtn = stickyCta.querySelector('.sticky-cta-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() { stickyCta.style.display = 'none'; });
        }
    }

    // ===== CYCLIC COMMENT CAROUSEL =====
    const wrapper = document.getElementById('carouselWrapper');
    const slides = wrapper ? wrapper.querySelectorAll('.carousel-slide') : [];
    const prevBtn = document.getElementById('carouselPrev');
    const nextBtn = document.getElementById('carouselNext');
    const dots = document.querySelectorAll('.carousel-indicators .dot');
    let currentIndex = 0;
    let autoPlayTimer = null;
    const interval = 5000; // 5 seconds

    if (slides.length > 0) {
        function goTo(index) {
            if (index < 0) index = slides.length - 1;
            if (index >= slides.length) index = 0;
            currentIndex = index;
            slides.forEach((slide, i) => {
                slide.classList.remove('active', 'prev', 'next');
                if (i === index) {
                    slide.classList.add('active');
                } else if (i === (index - 1 + slides.length) % slides.length) {
                    slide.classList.add('prev');
                } else if (i === (index + 1) % slides.length) {
                    slide.classList.add('next');
                } else {
                    // hide others
                    slide.style.opacity = 0;
                    setTimeout(() => { slide.style.opacity = ''; }, 800);
                }
            });
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });
        }

        function nextSlide() { goTo(currentIndex + 1); }
        function prevSlide() { goTo(currentIndex - 1); }

        function startAutoPlay() {
            if (autoPlayTimer) clearInterval(autoPlayTimer);
            autoPlayTimer = setInterval(nextSlide, interval);
        }

        function stopAutoPlay() {
            if (autoPlayTimer) {
                clearInterval(autoPlayTimer);
                autoPlayTimer = null;
            }
        }

        // Initialize
        goTo(0);
        startAutoPlay();

        // Event listeners
        if (prevBtn) prevBtn.addEventListener('click', () => { stopAutoPlay(); prevSlide(); startAutoPlay(); });
        if (nextBtn) nextBtn.addEventListener('click', () => { stopAutoPlay(); nextSlide(); startAutoPlay(); });
        dots.forEach(dot => {
            dot.addEventListener('click', function() {
                const idx = parseInt(this.dataset.index);
                stopAutoPlay();
                goTo(idx);
                startAutoPlay();
            });
        });

        // Pause on hover
        const container = document.querySelector('.carousel-container');
        if (container) {
            container.addEventListener('mouseenter', stopAutoPlay);
            container.addEventListener('mouseleave', startAutoPlay);
        }
    }
});
</script>

<?php
// ============================================================
// 5. SAVE CACHE FOR ANONYMOUS USERS
// ============================================================
if ($doCache) {
    $content = ob_get_contents();
    file_put_contents($cacheFile, $content);
    ob_end_flush();
}
?>

<?php require_once 'includes/footer.php'; ?>