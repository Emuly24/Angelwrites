<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// ============================================================
// 1. CSRF PROTECTION HELPER
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
// 2. WEBP IMAGE HELPER
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
// 3. RATE LIMITING HELPER
// ============================================================
if (!function_exists('rate_limit')) {
    function rate_limit($key, $limit = 10, $window = 60) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $cache_key = 'rate_limit_' . md5($ip . '_' . $key);
        $file = sys_get_temp_dir() . '/' . $cache_key . '.txt';
        $current = time();
        if (file_exists($file)) {
            $data = file_get_contents($file);
            list($timestamp, $count) = explode('|', $data);
            if ($current - $timestamp < $window) {
                if ($count >= $limit) {
                    http_response_code(429);
                    exit('Rate limit exceeded. Try again later.');
                }
                $count++;
            } else {
                $timestamp = $current;
                $count = 1;
            }
        } else {
            $timestamp = $current;
            $count = 1;
        }
        file_put_contents($file, "$timestamp|$count");
    }
}

// ============================================================
// 4. GET POEM ID
// ============================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: ' . SITE_URL . '/poetry.php');
    exit;
}

// ============================================================
// 5. FETCH POEM
// ============================================================
$stmt = $db->prepare("SELECT * FROM poems WHERE id = ?");
$stmt->execute([$id]);
$poem = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$poem) {
    header('Location: ' . SITE_URL . '/poetry.php');
    exit;
}

// ============================================================
// 6. UPDATE VIEW COUNT
// ============================================================
$db->prepare("UPDATE poems SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);

// ============================================================
// 7. TRACK USER READ – Bulletproof Check-then-Insert
// ============================================================
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_exists = $stmt->fetchColumn();
    if ($user_exists && $poem) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ? AND poem_id = ?");
        $stmt->execute([$user_id, $id]);
        $already_read = $stmt->fetchColumn();
        if (!$already_read) {
            try {
                $stmt = $db->prepare("INSERT INTO poem_reads (user_id, poem_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $id]);
            } catch (PDOException $e) {
                error_log("Poem tracking failed: " . $e->getMessage());
            }
        }
    }
}

// ============================================================
// 8. OPEN GRAPH META
// ============================================================
$base_url = rtrim(SITE_URL, '/');
$share_url = $base_url . '/poem_view.php?id=' . $id;
$og_title = htmlspecialchars($poem['title']);
$og_desc = htmlspecialchars(substr($poem['intro'] ?? strip_tags($poem['content']), 0, 150));
$og_image = $base_url . '/img/' . $id . '?v=' . time();

// ============================================================
// 9. HANDLE POST REQUESTS (Comment / Reply / Private)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && isLoggedIn()) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $target_type = $_POST['target_type'];
    $target_id = (int)$_POST['target_id'];
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $is_private = isset($_POST['is_private']) ? 1 : 0;
    $target_user_id = $is_private ? 1 : null; // admin ID (Angella)

    if (!empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment, parent_id, is_private, target_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$target_type, $target_id, $_SESSION['user_id'], $rating, $comment, $parent_id, $is_private, $target_user_id]);
        $comment_id = $db->lastInsertId();

        $payload = json_encode([
            'comment_id' => $comment_id,
            'poem_id' => $target_id,
            'comment_text' => $comment,
            'author_id' => $_SESSION['user_id'],
            'is_private' => $is_private,
            'parent_id' => $parent_id,
            'poem_title' => $poem['title']
        ]);
        $stmt = $db->prepare("INSERT INTO jobs (job_type, payload) VALUES ('process_comment', ?)");
        $stmt->execute([$payload]);

        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id);
        exit;
    }
}

// ============================================================
// 10. FETCH COMMENTS (threaded)
// ============================================================
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
$admin_id = 1; // change to your actual admin user ID

$stmt = $db->prepare("
    SELECT r.*, u.name AS author_name, u.profile_pic AS author_pic,
           (SELECT COUNT(*) FROM reactions WHERE target_type='comment' AND target_id=r.id AND reaction_type='like') AS likes,
           (SELECT COUNT(*) FROM reactions WHERE target_type='comment' AND target_id=r.id) AS total_reactions
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.target_type = 'poem' AND r.target_id = ?
    AND (r.is_private = 0 OR (r.is_private = 1 AND r.target_user_id = ?) OR r.user_id = ? OR ? = 1)
    ORDER BY r.parent_id ASC, r.created_at ASC
");
$stmt->execute([$id, $user_id, $user_id, $admin_id]);
$comments_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$comments = [];
foreach ($comments_raw as $c) {
    $c['children'] = [];
    if ($c['parent_id'] === null) {
        $comments[$c['id']] = $c;
    } else {
        if (!isset($comments[$c['parent_id']])) {
            $comments[$c['parent_id']] = ['children' => []];
        }
        $comments[$c['parent_id']]['children'][$c['id']] = $c;
    }
}

function render_comment($comment, $level = 0) {
    $id = $GLOBALS['id'];
    ?>
    <div class="review-item" style="margin-left:<?php echo $level*20; ?>px; border-left:<?php echo $level>0?'2px solid var(--rose)':'none'; ?>; padding-left:<?php echo $level>0?'12px':'0'; ?>;">
        <div class="review-header">
            <span class="review-author">
                <i class="fas fa-user-circle"></i>
                <?php echo htmlspecialchars($comment['author_name']); ?>
                <?php if ($comment['is_admin_reply']): ?>
                    <span class="admin-badge">🛡️ Angella</span>
                <?php endif; ?>
                <?php if ($comment['is_private']): ?>
                    <span class="private-badge">🔒 Private</span>
                <?php endif; ?>
            </span>
            <span class="review-date"><?php echo date('M j, Y', strtotime($comment['created_at'])); ?></span>
        </div>
        <?php if ($comment['rating'] > 0): ?>
            <div class="review-rating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fas fa-star <?php echo $i <= $comment['rating'] ? 'filled' : 'empty'; ?>"></i>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        <div class="review-comment"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></div>
        <div class="reaction-buttons" data-target-type="comment" data-target-id="<?php echo $comment['id']; ?>">
            <button class="reaction-btn" data-reaction="like">👍 <span class="count"><?php echo $comment['likes']; ?></span></button>
            <button class="reaction-btn" data-reaction="love">❤️ <span class="count">0</span></button>
            <button class="reaction-btn" data-reaction="laugh">😂 <span class="count">0</span></button>
            <button class="reaction-btn" data-reaction="wow">😮 <span class="count">0</span></button>
            <button class="reaction-btn" data-reaction="sad">😢 <span class="count">0</span></button>
            <button class="reaction-btn" data-reaction="angry">😡 <span class="count">0</span></button>
        </div>
        <div class="reply-link" data-comment-id="<?php echo $comment['id']; ?>">Reply</div>
        <div class="comment-reply-form" style="display:none;">
            <form method="POST" class="reply-form">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="target_type" value="poem">
                <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                <input type="hidden" name="parent_id" value="<?php echo $comment['id']; ?>">
                <textarea name="comment" rows="2" placeholder="Write a reply..." required></textarea>
                <button type="submit" name="submit_review" class="btn btn-sm btn-primary">Reply</button>
                <button type="button" class="btn btn-sm btn-secondary cancel-reply">Cancel</button>
            </form>
        </div>
        <?php if (!empty($comment['children'])): ?>
            <?php foreach ($comment['children'] as $child): ?>
                <?php render_comment($child, $level + 1); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================
// 11. FETCH RATINGS STATS
// ============================================================
$stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type='poem' AND target_id=? AND is_private=0");
$stmt->execute([$id]);
$rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
$avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
$total_reviews = $rating_data['total'] ?? 0;

// ============================================================
// 12. OUTPUT PAGE
// ============================================================
?>
<?php require_once 'includes/header.php'; ?>
<meta property="og:title" content="<?php echo $og_title; ?>">
<meta property="og:description" content="<?php echo $og_desc; ?>">
<meta property="og:image" content="<?php echo $og_image; ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="<?php echo $share_url; ?>">
<meta name="twitter:card" content="summary_large_image">

<style>
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #ffffff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --bg: #F7F3ED; --card-bg: #ffffff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08); --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --input-bg: #ffffff;
}
body.dark-mode { --bg: #1a1a1a; --card-bg: #2a2a2a; --border: #444; --text: #e8dddd; --text-light: #aaa; --vanilla: #2a2a2a; --fantasy: #1a1a1a; --shadow: 0 4px 20px rgba(0,0,0,0.4); --shadow-hover: 0 12px 40px rgba(0,0,0,0.5); --input-bg: #333; }
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }
.poem-view-page { padding: 32px 0 60px; }
.poem-nav { margin-bottom: 24px; }
.poem-nav .back-link { color: var(--text-light); font-size: 0.95rem; transition: color 0.2s; }
.poem-nav .back-link:hover { color: var(--rose); }
.poem-nav .back-link i { margin-right: 6px; }
.poem-header { text-align: center; margin-bottom: 32px; }
.poem-header h1 { font-family: 'Playfair Display', serif; font-size: clamp(2rem, 4vw, 3.2rem); color: var(--dark); margin-bottom: 8px; line-height: 1.2; }
.poem-meta { display: flex; justify-content: center; gap: 24px; color: var(--text-light); font-size: 0.9rem; flex-wrap: wrap; }
.poem-meta i { margin-right: 4px; }
.poem-image-container { margin: 0 auto 32px; max-width: 700px; text-align: center; }
.poem-feature-image { width: 100%; height: auto; border: 6px solid var(--rose); border-radius: 16px; box-shadow: var(--shadow-hover); display: block; }
.poem-audio-player { max-width: 700px; margin: 0 auto 24px; background: var(--card-bg); border-radius: 12px; padding: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.audio-label { display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
.audio-label i { color: var(--rose); font-size: 1.2rem; }
#customAudioPlayer { position: relative; }
#waveCanvas { width: 100%; height: 100px; border-radius: 8px; display: block; }
.audio-controls-bar { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.audio-controls-bar .play-btn { background: var(--rose); border: none; color: white; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; }
.audio-controls-bar .play-btn:hover { background: var(--rose-dark); }
.progress-container { flex: 1; min-width: 80px; }
.progress-bar { height: 4px; background: var(--border); border-radius: 2px; cursor: pointer; position: relative; }
.progress-fill { height: 100%; background: var(--rose); border-radius: 2px; width: 0%; transition: width 0.1s; }
.time-display { font-size: 0.85rem; color: var(--text-light); min-width: 70px; text-align: center; }
.volume-control { display: flex; align-items: center; gap: 4px; }
.volume-control button { background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 0.9rem; padding: 2px; }
.volume-control input[type="range"] { width: 60px; accent-color: var(--rose); background: var(--border); height: 4px; border-radius: 2px; }
.poem-intro-section { max-width: 700px; margin: 0 auto 32px; background: var(--fantasy); border-left: 4px solid var(--rose); border-radius: 0 12px 12px 0; padding: 20px 24px; }
.intro-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--rose); margin-bottom: 6px; }
.intro-body { font-style: italic; font-size: 1.05rem; color: var(--text); line-height: 1.8; text-align: justify; }
.poem-content-section { max-width: 700px; margin: 0 auto 32px; border: 4px solid var(--rose); border-radius: 16px; padding: 32px; background: var(--card-bg); box-shadow: var(--shadow-hover); }
.poem-body { font-family: 'Georgia', serif; font-size: 1.15rem; line-height: 2.4; color: var(--text); text-align: center; padding: 0; }
.poem-body p { margin-bottom: 24px; }
.poem-body p:last-child { margin-bottom: 0; }
.poem-body br { display: block; content: ""; margin: 12px 0; }
.poem-body img { max-width: 100%; height: auto; margin: 16px auto; display: block; border-radius: 8px; }
.reviews-section { max-width: 700px; margin: 48px auto 0; }
.reviews-section h3 { font-size: 1.4rem; margin-bottom: 16px; }
.rating-summary { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.rating-stars { display: flex; gap: 2px; }
.rating-stars .filled { color: #f1c40f; }
.rating-stars .empty { color: #ddd; }
.rating-score { font-weight: 700; font-size: 1.1rem; }
.rating-count { color: var(--text-light); font-size: 0.9rem; }
.review-form-container { background: var(--vanilla); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.review-form-container h4 { margin-bottom: 12px; }
.review-form .star-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.review-form .stars { display: flex; flex-direction: row-reverse; gap: 2px; }
.review-form .stars input { display: none; }
.review-form .stars label { font-size: 1.4rem; color: #ddd; cursor: pointer; transition: color 0.2s; }
.review-form .stars label:hover, .review-form .stars label:hover ~ label { color: #f1c40f; }
.review-form .stars input:checked ~ label { color: #f1c40f; }
.review-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; background: var(--input-bg); color: var(--text); }
.review-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.review-form .btn { margin-top: 8px; }
.reaction-section { text-align: center; margin: 20px 0; }
.reaction-buttons { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin-top: 4px; }
.reaction-btn { background: none; border: 1px solid var(--border); cursor: pointer; font-size: 1.2rem; padding: 4px 10px; border-radius: 20px; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; }
.reaction-btn:hover { background: var(--rose-light); border-color: var(--rose); }
.reaction-btn.active { background: var(--rose); color: #fff; border-color: var(--rose); }
.reaction-btn .count { font-size: 0.8rem; font-weight: 600; }

/* ===== UPDATED SPLASHY EFFECT (Longer delay, more particles) ===== */
.reaction-particle{position:fixed;pointer-events:none;z-index:99999;font-size:2rem;animation:burst 1.6s cubic-bezier(.2,.8,.2,1.2) forwards}@keyframes burst{0%{opacity:1;transform:translate(0)scale(.5)}100%{opacity:0;transform:translate(var(--tx),var(--ty))scale(1.8)rotate(720deg)}}.reaction-btn:active{transform:scale(.85);transition:transform .1s}.reaction-btn.active{animation:pop-active .4s ease}@keyframes pop-active{0%{transform:scale(1)}50%{transform:scale(1.3);box-shadow:0 0 20px var(--rose)}100%{transform:scale(1)}}

.comment-reply-form { margin-left: 20px; margin-top: 8px; }
.reply-link { cursor: pointer; color: var(--rose); font-size: 0.8rem; margin-left: 8px; text-decoration: underline; }
.reply-link:hover { color: var(--rose-dark); }
.private-badge { background: #ffd700; color: #333; font-size: 0.6rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: 6px; }

/* ===== UPDATED TAG SUGGESTIONS (Bulletproof positioning) ===== */
.tag-suggestions { position: fixed; background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; max-height: 150px; overflow-y: auto; display: none; z-index: 99999; min-width: 150px; box-shadow: 0 4px 16px rgba(0,0,0,0.15); padding: 4px 0; }
.tag-suggestions div { padding: 8px 16px; cursor: pointer; font-size: 0.95rem; color: var(--text); }
.tag-suggestions div:hover { background: var(--vanilla); }

.checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.checkbox-group input[type="checkbox"] { accent-color: var(--rose); width: 16px; height: 16px; }
.review-item { background: var(--card-bg); border-radius: 12px; padding: 16px 20px; border: 1px solid var(--border); margin-bottom: 12px; }
.review-item.admin-reply { background: var(--vanilla); border-left: 5px solid var(--rose); }
.review-author { font-weight: 600; display: flex; align-items: center; gap: 8px; }
.review-author i { color: var(--rose); }
.admin-badge { background: var(--rose); color: white; font-size: 0.7rem; padding: 2px 10px; border-radius: 12px; font-weight: 600; }
.review-date { font-size: 0.85rem; color: var(--text-light); margin: 2px 0 6px; }
.review-rating { margin-bottom: 6px; }
.review-rating .filled { color: #f1c40f; }
.review-rating .empty { color: #ddd; }
.review-comment { line-height: 1.6; color: var(--text); }
.voice-comment-player { margin: 6px 0; }
.voice-comment-player audio { width: 100%; border-radius: 8px; }
.poem-footer-actions { max-width: 700px; margin: 32px auto 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding-top: 24px; border-top: 1px solid var(--border); }
.share-section { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-light); }
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 0.9rem; transition: transform 0.2s; }
.share-btn:hover { transform: scale(1.05); opacity: 0.85; }
.share-btn.facebook { background: #1877f2; }
.share-btn.twitter { background: #1da1f2; }
.share-btn.whatsapp { background: #25d366; }
.reading-actions .btn { font-size: 0.85rem; }
.back-to-top { position: fixed; bottom: 24px; right: 24px; width: 44px; height: 44px; border-radius: 50%; background: var(--rose); color: white; border: none; font-size: 1.2rem; display: none; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15); cursor: pointer; transition: transform 0.2s; z-index: 1000; }
.back-to-top:hover { transform: scale(1.05); }
@media (max-width: 480px) {
    .poem-header h1 { font-size: 1.8rem; }
    .poem-meta { flex-direction: column; gap: 4px; align-items: center; }
    .poem-footer-actions { flex-direction: column; align-items: center; }
    .poem-body { font-size: 1rem; line-height: 2; }
    .audio-controls-bar { gap: 6px; }
    .volume-control input[type="range"] { width: 40px; }
}
</style>

<div class="poem-view-page">
    <div class="container">
        <div class="poem-nav">
            <a href="<?php echo SITE_URL; ?>/poetry.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Poetry
            </a>
        </div>

        <header class="poem-header">
            <h1><?php echo htmlspecialchars($poem['title']); ?></h1>
            <div class="poem-meta">
                <span class="poem-date"><?php echo date('F j, Y', strtotime($poem['created_at'])); ?></span>
                <span class="poem-views"><i class="fas fa-eye"></i> <?php echo number_format($poem['view_count'] ?? 1); ?> views</span>
                <span class="poem-reading-time"><i class="fas fa-clock"></i> <?php echo $poem['reading_time'] ?? '1 min read'; ?></span>
            </div>
        </header>

        <?php if ($poem['image_path']): ?>
            <div class="poem-image-container">
                <img src="<?php echo get_image_url($poem['image_path']); ?>" alt="<?php echo htmlspecialchars($poem['title']); ?>" class="poem-feature-image" loading="lazy">
            </div>
        <?php endif; ?>

        <?php if ($poem['audio_path']): ?>
            <div class="poem-audio-player">
                <div class="audio-label">
                    <i class="fas fa-headphones"></i>
                    <span>Listen to this poem</span>
                </div>
                <div id="customAudioPlayer">
                    <canvas id="waveCanvas"></canvas>
                    <audio id="audioSource" src="<?php echo $base_url . '/' . ltrim($poem['audio_path'], '/'); ?>" preload="metadata"></audio>
                    <div class="audio-controls-bar">
                        <button id="playPauseBtn" class="play-btn" aria-label="Play"><i class="fas fa-play"></i></button>
                        <div class="progress-container">
                            <div class="progress-bar" id="progressBar"><div class="progress-fill" id="progressFill"></div></div>
                        </div>
                        <span class="time-display" id="timeDisplay">0:00 / 0:00</span>
                        <div class="volume-control">
                            <button id="muteBtn" aria-label="Mute"><i class="fas fa-volume-up"></i></button>
                            <input type="range" id="volumeSlider" min="0" max="1" step="0.05" value="1">
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($poem['intro']): ?>
            <div class="poem-intro-section">
                <div class="intro-label">✧ Purpose of this poem</div>
                <div class="intro-body"><?php echo nl2br(htmlspecialchars($poem['intro'])); ?></div>
            </div>
        <?php endif; ?>

        <div class="poem-content-section">
            <div class="poem-body">
                <?php echo $poem['content']; ?>
            </div>
        </div>

        <div class="reaction-section">
            <span>React to this poem:</span>
            <div class="reaction-buttons" data-target-type="poem" data-target-id="<?php echo $id; ?>">
                <button class="reaction-btn" data-reaction="like">👍 <span class="count">0</span></button>
                <button class="reaction-btn" data-reaction="love">❤️ <span class="count">0</span></button>
                <button class="reaction-btn" data-reaction="laugh">😂 <span class="count">0</span></button>
                <button class="reaction-btn" data-reaction="wow">😮 <span class="count">0</span></button>
                <button class="reaction-btn" data-reaction="sad">😢 <span class="count">0</span></button>
                <button class="reaction-btn" data-reaction="angry">😡 <span class="count">0</span></button>
            </div>
        </div>

        <div class="reviews-section">
            <h3><i class="fas fa-comments" style="color: var(--rose);"></i> Comments & Ratings</h3>
            <div class="rating-summary">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-score"><?php echo number_format($avg_rating, 1); ?> / 5</span>
                <span class="rating-count">(<?php echo $total_reviews; ?> reviews)</span>
            </div>

            <?php if (isLoggedIn()): ?>
                <div class="review-form-container">
                    <h4>Write a Comment</h4>
                    <form method="POST" class="review-form" id="commentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="target_type" value="poem">
                        <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                        <div class="star-rating">
                            <span>Your rating:</span>
                            <div class="stars">
                                <input type="radio" name="rating" value="5" id="star5"><label for="star5"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="4" id="star4"><label for="star4"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="3" id="star3"><label for="star3"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="2" id="star2"><label for="star2"><i class="fas fa-star"></i></label>
                                <input type="radio" name="rating" value="1" id="star1"><label for="star1"><i class="fas fa-star"></i></label>
                            </div>
                        </div>
                        <div class="form-group" style="position:relative;">
                            <textarea name="comment" id="commentText" rows="3" placeholder="Share your thoughts... Use @username to tag someone." required></textarea>
                            <div id="tagSuggestions" class="tag-suggestions"></div>
                        </div>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="is_private" value="1">
                                Private – only Angella can see this
                            </label>
                        </div>
                        <button type="submit" name="submit_review" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode(SITE_URL . '/poem_view.php?id=' . $id); ?>">Login</a> to comment, rate, or react.</p>
                </div>
            <?php endif; ?>

            <div class="reviews-list">
                <?php foreach ($comments as $comment): ?>
                    <?php render_comment($comment); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="poem-footer-actions">
            <div class="share-section">
                <span>Share:</span>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($share_url); ?>&display=popup" target="_blank" class="share-btn facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode($og_title . ' — a poem by Angella Bottoman'); ?>&url=<?php echo urlencode($share_url); ?>" target="_blank" class="share-btn twitter"><i class="fab fa-twitter"></i></a>
                <a href="https://api.whatsapp.com/send?text=<?php echo urlencode($og_title . ' — read this poem on AngelWrites: ' . $share_url); ?>" target="_blank" class="share-btn whatsapp"><i class="fab fa-whatsapp"></i></a>
            </div>
            <div class="reading-actions">
                <a href="<?php echo SITE_URL; ?>/poetry.php" class="btn btn-outline"><i class="fas fa-list"></i> More Poems</a>
            </div>
        </div>
    </div>
</div>

<button id="backToTop" class="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="fas fa-arrow-up"></i></button>

<!-- ================================================================ -->
<!-- COMPLETE JAVASCRIPT – Audio, Reactions, Tagging, Reply toggle    -->
<!-- ================================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== UPDATED SPLASHY BURST FUNCTION (Exact emoji, more particles, longer life) =====
    function createReactionBurst(element, emoji) {
        const rect = element.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const count = 30; // More splashes
        // Use ONLY the exact clicked emoji
        for (let i = 0; i < count; i++) {
            const particle = document.createElement('div');
            particle.className = 'reaction-particle';
            particle.textContent = emoji; // Only the reaction symbol
            const angle = Math.random() * Math.PI * 2;
            const distance = 80 + Math.random() * 150; // Fly farther
            const tx = Math.cos(angle) * distance;
            const ty = Math.sin(angle) * distance - 40;
            particle.style.left = x + 'px';
            particle.style.top = y + 'px';
            particle.style.setProperty('--tx', tx + 'px');
            particle.style.setProperty('--ty', ty + 'px');
            particle.style.fontSize = (1.5 + Math.random() * 2.5) + 'rem';
            document.body.appendChild(particle);
            setTimeout(() => particle.remove(), 1800); // Extended delay
        }
    }

    // ===== REACTION TOGGLE =====
    document.querySelectorAll('.reaction-buttons').forEach(container => {
        const targetType = container.dataset.targetType;
        const targetId = container.dataset.targetId;
        const buttons = container.querySelectorAll('.reaction-btn');

        // Load existing reactions
        fetch(`ajax_get_reactions.php?target_type=${targetType}&target_id=${targetId}`)
            .then(res => res.json())
            .then(data => {
                buttons.forEach(btn => {
                    const reaction = btn.dataset.reaction;
                    const countSpan = btn.querySelector('.count');
                    if (data[reaction]) {
                        countSpan.textContent = data[reaction].count;
                        if (data[reaction].active) btn.classList.add('active');
                    }
                });
            })
            .catch(err => console.error('Error loading reactions:', err));

        // Handle click events
        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                const reaction = this.dataset.reaction;
                const emoji = this.textContent.trim().charAt(0);
                createReactionBurst(this, emoji); // Exact splash on click

                fetch('ajax_toggle_reaction.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `target_type=${targetType}&target_id=${targetId}&reaction=${reaction}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        if (data.error.includes('logged in')) {
                            alert(data.error);
                        }
                        return;
                    }
                    const countSpan = this.querySelector('.count');
                    countSpan.textContent = data.count;
                    if (data.active) this.classList.add('active');
                    else this.classList.remove('active');
                })
                .catch(err => console.error('Error toggling reaction:', err));
            });
        });
    });

    // ===== FIXED TAGGING AUTOCOMPLETE (Bulletproof positioning) =====
    const commentText = document.getElementById('commentText');
    const suggestions = document.getElementById('tagSuggestions');
    let usersList = [];
    let tagTimer = null;

    fetch('ajax_get_users.php')
        .then(res => res.json())
        .then(data => usersList = data)
        .catch(err => console.error('Failed to load users for tagging:', err));

    commentText.addEventListener('input', function() {
        clearTimeout(tagTimer);
        const text = this.value;
        const atPos = text.lastIndexOf('@');
        
        if (atPos !== -1 && text.length > atPos + 1) {
            const query = text.substring(atPos + 1);
            tagTimer = setTimeout(() => {
                const matches = usersList.filter(u => 
                    u.name.toLowerCase().startsWith(query.toLowerCase())
                );
                if (matches.length > 0) {
                    suggestions.innerHTML = matches.map(u => 
                        `<div data-id="${u.id}" data-name="${u.name}">${u.name}</div>`
                    ).join('');
                    
                    // Set position fixed relative to textarea
                    const rect = this.getBoundingClientRect();
                    suggestions.style.position = 'fixed';
                    suggestions.style.top = rect.bottom + 'px';
                    suggestions.style.left = rect.left + 'px';
                    suggestions.style.width = rect.width + 'px';
                    suggestions.style.display = 'block';
                } else {
                    suggestions.style.display = 'none';
                }
            }, 300);
        } else {
            suggestions.style.display = 'none';
        }
    });

    // Click a suggestion to insert the tag
    suggestions.addEventListener('click', function(e) {
        if (e.target.tagName === 'DIV') {
            const name = e.target.dataset.name;
            const text = commentText.value;
            const atPos = text.lastIndexOf('@');
            commentText.value = text.substring(0, atPos) + '@' + name + ' ';
            suggestions.style.display = 'none';
            commentText.focus();
        }
    });

    // Close tagging dropdown if clicking outside
    document.addEventListener('click', function(e) {
        if (suggestions.style.display === 'block' && 
            !suggestions.contains(e.target) && 
            e.target !== commentText) {
            suggestions.style.display = 'none';
        }
    });

    // ===== REPLY TOGGLE =====
    document.querySelectorAll('.reply-link').forEach(link => {
        link.addEventListener('click', function() {
            const form = this.nextElementSibling;
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
            form.querySelector('textarea').focus();
        });
    });
    document.querySelectorAll('.cancel-reply').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.comment-reply-form').style.display = 'none';
        });
    });

    // ===== AUDIO PLAYER =====
    const audio = document.getElementById('audioSource');
    const playBtn = document.getElementById('playPauseBtn');
    const playIcon = playBtn.querySelector('i');
    const progressFill = document.getElementById('progressFill');
    const progressBar = document.getElementById('progressBar');
    const timeDisplay = document.getElementById('timeDisplay');
    const muteBtn = document.getElementById('muteBtn');
    const volumeSlider = document.getElementById('volumeSlider');
    const canvas = document.getElementById('waveCanvas');
    const ctx = canvas.getContext('2d');
    let isPlaying = false;
    let audioContext = null;
    let analyser = null;
    let source = null;
    let animationId = null;
    let dataArray = null;

    function resizeCanvas() {
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width;
        canvas.height = 100;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    function initAudioContext() {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.8;
            source = audioContext.createMediaElementSource(audio);
            source.connect(analyser);
            analyser.connect(audioContext.destination);
            dataArray = new Uint8Array(analyser.frequencyBinCount);
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
    }

    function drawWave() {
        if (!analyser) return;
        animationId = requestAnimationFrame(drawWave);
        analyser.getByteFrequencyData(dataArray);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const barCount = 64;
        const barWidth = (canvas.width / barCount) * 0.6;
        const gap = (canvas.width / barCount) * 0.4;
        const halfHeight = canvas.height / 2;
        const gradient = ctx.createLinearGradient(0, 0, canvas.width, 0);
        gradient.addColorStop(0, '#DBA1A2');
        gradient.addColorStop(0.5, '#e8c0c0');
        gradient.addColorStop(1, '#DBA1A2');
        ctx.fillStyle = gradient;
        for (let i = 0; i < barCount; i++) {
            const value = dataArray[i] / 255;
            const barHeight = value * halfHeight * 1.5;
            const x = i * (barWidth + gap) + gap / 2;
            const y = halfHeight - barHeight / 2;
            const radius = 4;
            ctx.beginPath();
            ctx.moveTo(x + radius, y);
            ctx.lineTo(x + barWidth - radius, y);
            ctx.quadraticCurveTo(x + barWidth, y, x + barWidth, y + radius);
            ctx.lineTo(x + barWidth, y + barHeight - radius);
            ctx.quadraticCurveTo(x + barWidth, y + barHeight, x + barWidth - radius, y + barHeight);
            ctx.lineTo(x + radius, y + barHeight);
            ctx.quadraticCurveTo(x, y + barHeight, x, y + barHeight - radius);
            ctx.lineTo(x, y + radius);
            ctx.quadraticCurveTo(x, y, x + radius, y);
            ctx.closePath();
            ctx.fill();
        }
        const overlayGradient = ctx.createLinearGradient(0, 0, canvas.width, 0);
        overlayGradient.addColorStop(0, 'rgba(219, 161, 162, 0.15)');
        overlayGradient.addColorStop(0.5, 'rgba(219, 161, 162, 0)');
        overlayGradient.addColorStop(1, 'rgba(219, 161, 162, 0.15)');
        ctx.fillStyle = overlayGradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    function stopVisualizer() {
        if (animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
        }
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }

    playBtn.addEventListener('click', function() {
        if (audio.paused) {
            initAudioContext();
            audio.play();
            isPlaying = true;
            playIcon.className = 'fas fa-pause';
            if (!animationId) drawWave();
        } else {
            audio.pause();
            isPlaying = false;
            playIcon.className = 'fas fa-play';
            stopVisualizer();
        }
    });

    audio.addEventListener('ended', function() {
        isPlaying = false;
        playIcon.className = 'fas fa-play';
        stopVisualizer();
        progressFill.style.width = '0%';
        updateTimeDisplay();
    });

    audio.addEventListener('timeupdate', function() {
        const percent = (audio.currentTime / audio.duration) * 100;
        progressFill.style.width = percent + '%';
        updateTimeDisplay();
    });

    progressBar.addEventListener('click', function(e) {
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percent = x / rect.width;
        audio.currentTime = percent * audio.duration;
    });

    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    function updateTimeDisplay() {
        const current = formatTime(audio.currentTime || 0);
        const total = formatTime(audio.duration || 0);
        timeDisplay.textContent = current + ' / ' + total;
    }

    audio.addEventListener('loadedmetadata', updateTimeDisplay);

    volumeSlider.addEventListener('input', function() {
        audio.volume = this.value;
        muteBtn.querySelector('i').className = this.value == 0 ? 'fas fa-volume-mute' : 'fas fa-volume-up';
    });

    muteBtn.addEventListener('click', function() {
        if (audio.volume > 0) {
            audio.volume = 0;
            volumeSlider.value = 0;
            muteBtn.querySelector('i').className = 'fas fa-volume-mute';
        } else {
            audio.volume = 1;
            volumeSlider.value = 1;
            muteBtn.querySelector('i').className = 'fas fa-volume-up';
        }
    });

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && isPlaying && !animationId) {
            drawWave();
        }
        if (document.hidden && animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
        }
    });

    window.addEventListener('beforeunload', function() {
        if (audioContext) {
            audioContext.close();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>