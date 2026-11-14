<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/saves.php'; // F2: the Save control
require_once 'includes/og.php'; // link previews: the cover as a 1200x630 JPEG, and the link to share

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
        $file = aw_ratelimit_file($cache_key) /* Q49 */;
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
// F205: release the read snapshot before the view-count write below. With the cursor left
// open, concurrent views failed at once with "database is locked" (WAL snapshot conflict;
// the busy timeout cannot help that case). 60 concurrent views: ~40 failed before, 0 after.
$stmt->closeCursor();
// F122: a draft is visible to the admin only (it used to be public by direct link too).
if ($poem && !isAdmin()) {
    $ds = $db->prepare("SELECT 1 FROM poem_status WHERE poem_id = ? AND status = 'draft'");
    $ds->execute([$id]);
    if ($ds->fetchColumn()) {
        $poem = false;
    }
    $ds->closeCursor();
}
if (!$poem) {
    header('Location: ' . SITE_URL . '/poetry.php');
    exit;
}

// ============================================================
// 6. UPDATE VIEW COUNT & LOG DEEP DIVE STATS
// ============================================================
// 1. Update the aggregate counter
$db->prepare("UPDATE poems SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);

// 2. Log the detailed record for the Modal
try {
    $user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
    $stmt = $db->prepare("INSERT INTO view_logs (target_type, target_id, user_id, ip_address) VALUES ('poem', ?, ?, ?)");
    $stmt->execute([$id, $user_id, $_SERVER['REMOTE_ADDR']]);
} catch (Exception $e) {
    // Table might not exist yet, silently skip
}

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
$page_url = $base_url . '/poem_view.php?id=' . $id;
$share_url = aw_share_url('poem', $id); // through the preview relay when one is set up (includes/og.php)
$og_title = htmlspecialchars($poem['title']);
$og_desc = htmlspecialchars(aw_og_text(($poem['intro'] ?? '') !== '' ? $poem['intro'] : $poem['content'], 150));
// a stable 1200x630 JPEG of the cover (was a WebP at an address that changed on every visit)
$og_image = aw_og_image_url($db, 'poem', $id);

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
    $target_user_id = null; // F129: was the hard-coded admin id 1 (the real admin is another id); private = visible to admins

    if (!empty($comment)) {
        $stmt = $db->prepare("INSERT INTO reviews (target_type, target_id, user_id, rating, comment, parent_id, is_private, target_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$target_type, $target_id, $_SESSION['user_id'], $rating, $comment, $parent_id, $is_private, $target_user_id]);
        $comment_id = $db->lastInsertId();

        // N2: mentions through the shared function (resolved to ids, stored, each person told once)
        require_once __DIR__ . '/includes/mentions.php';
        $aw_actor = awDisplayName(['name' => $_SESSION['name'] ?? '', 'username' => $_SESSION['username'] ?? '']);
        $aw_link = SITE_URL . '/poem_view.php?id=' . $target_id . '#comments';
        if (!$is_private) aw_mentions_save($db, $comment, 'review', (int)$comment_id, 'the poem “' . mb_substr($poem['title'], 0, 80) . '”', $aw_link, $aw_actor);
        // N1: tell the person being replied to (nobody was told before)
        if ($parent_id) {
            $pa = $db->prepare("SELECT user_id, is_private FROM reviews WHERE id = ?");
            $pa->execute([$parent_id]);
            $parent = $pa->fetch(PDO::FETCH_ASSOC);
            if ($parent && (!$parent['is_private'] || isAdmin())) {
                awNotify($db, (int)$parent['user_id'], 'comment_reply', $aw_actor . ' replied to your comment', 'On the poem “' . mb_substr($poem['title'], 0, 80) . '”.', $aw_link);
            }
        }

        // F125: comments were queued as 'process_comment' jobs (NULL status) that nothing ever
        // processed (the only worker, worker.php, is gone), so the admin was never told, not even
        // about private messages. Tell the admin now: an email and a bell notification.
        require_once __DIR__ . '/includes/notifications.php';
        require_once __DIR__ . '/includes/mail_helper.php';
        $from_name = awDisplayName(['name' => $_SESSION['name'] ?? '', 'username' => $_SESSION['username'] ?? '']);
        $what = $is_private ? 'Private message' : ($parent_id ? 'Reply' : 'Comment');
        sendEmail(aw_admin_email(), "$what on \"{$poem['title']}\" from $from_name",
            "$what from $from_name on the poem \"{$poem['title']}\":\n\n$comment\n\nView: " . SITE_URL . '/poem_view.php?id=' . $target_id,
            'angelwrites@zohomail.com', 'AngelWrites', false);
        foreach ($db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $admin_uid) {
            if ((int)$admin_uid !== (int)$_SESSION['user_id']) {
                awNotify($db, $admin_uid, $is_private ? 'private_message' : 'poem_comment', "$what on \"" . mb_substr($poem['title'], 0, 60) . '"',
                    $from_name . ': ' . mb_substr($comment, 0, 120), SITE_URL . '/poem_view.php?id=' . $target_id);
            }
        }

        header('Location: ' . SITE_URL . '/poem_view.php?id=' . $target_id . '#comments'); // P12
        exit;
    }
}

// ============================================================
// 10. FETCH COMMENTS (threaded)
// ============================================================
$user_id = isLoggedIn() ? $_SESSION['user_id'] : 0;
// F129: the old clause "OR ? = 1", bound to a hard-coded admin id 1, was meant to be always true;
// it only stayed closed because SQLite compares the bound string '1' with the integer 1 as
// unequal (on MySQL, or with a typed binding, every private comment would show to everyone).
// Visibility is now explicit: admins see all comments, everyone else sees public comments and
// their own private ones.
$privacy_sql = isAdmin() ? '' : 'AND (r.is_private IS NULL OR r.is_private = 0 OR r.user_id = ?)';

$stmt = $db->prepare("
    SELECT r.*, u.name AS author_name, u.profile_pic AS author_pic,
           (SELECT COUNT(*) FROM reactions WHERE target_type='comment' AND target_id=r.id AND reaction_type='like') AS likes,
           (SELECT COUNT(*) FROM reactions WHERE target_type='comment' AND target_id=r.id) AS total_reactions
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.target_type = 'poem' AND r.target_id = ?
    $privacy_sql
    AND (r.deleted_at IS NULL)
    ORDER BY r.parent_id ASC, r.created_at ASC
");
$stmt->execute(isAdmin() ? [$id] : [$id, $user_id]);
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

// P12: every comment thread was shown on one page. Top-level comments are paged, 10 at a time,
// each with all its replies.
require_once __DIR__ . '/includes/pagination.php';
[$cpage, $cper] = aw_page(10);
$comments = array_reverse($comments, true); // newest threads first (replies stay in order), as on blogs and reviews
$comments_total = count($comments);
[$cpage, $coffset] = aw_clamp($comments_total, $cpage, $cper);
$comments = array_slice($comments, $coffset, $cper, true);

// L2: reaction counts and "you reacted" were fetched with one request per comment plus one for the
// poem (11 on a busy page; the endpoint allows 10 a minute, so some failed). They are now read in
// one query for the poem and the comments on this page, and rendered with the page.
$aw_rx = [];
try {
    $aw_cids = [];
    foreach ($comments as $c) {
        if (!empty($c['id'])) $aw_cids[] = (int)$c['id'];
        foreach (($c['children'] ?? []) as $ch) $aw_cids[] = (int)$ch['id'];
    }
    $aw_sql = "SELECT target_type, target_id, reaction_type, COUNT(*) AS n, MAX(user_id = ?) AS mine
               FROM reactions WHERE reaction_type IN ('like','love','pray') AND ((target_type = 'poem' AND target_id = ?)";
    $aw_params = [(int)($user_id ?? 0), (int)$id];
    if ($aw_cids) {
        $aw_sql .= " OR (target_type = 'comment' AND target_id IN (" . implode(',', array_fill(0, count($aw_cids), '?')) . "))";
        $aw_params = array_merge($aw_params, $aw_cids);
    }
    $aw_sql .= ") GROUP BY target_type, target_id, reaction_type";
    $aw_st = $db->prepare($aw_sql);
    $aw_st->execute($aw_params);
    foreach ($aw_st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aw_rx[$r['target_type'] . ':' . (int)$r['target_id']][$r['reaction_type']] = ['n' => (int)$r['n'], 'mine' => (int)$r['mine'] === 1];
    }
} catch (Throwable $e) {
    error_log('poem_view reactions: ' . $e->getMessage());
}
function aw_rx_buttons($type, $tid) {
    $set = $GLOBALS['aw_rx'][$type . ':' . (int)$tid] ?? [];
    $html = '';
    foreach (['like' => ['👍', 'Like'], 'love' => ['❤️', 'Love'], 'pray' => ['🙏', 'Praying']] as $r => [$emoji, $label]) {
        $n = $set[$r]['n'] ?? 0; $mine = !empty($set[$r]['mine']);
        $html .= '<button type="button" class="reaction-btn' . ($mine ? ' active' : '') . '" data-reaction="' . $r . '" aria-pressed="' . ($mine ? 'true' : 'false') . '" aria-label="' . $label . '">'
               . $emoji . ' <span class="count">' . $n . '</span></button>';
    }
    return $html;
}

function render_comment($comment, $level = 0) {
    $id = $GLOBALS['id'];
    // N4: a comment from someone this member blocked is folded away (they can still open it)
    require_once __DIR__ . '/includes/moderation.php';
    $aw_fold = !empty($comment['user_id']) && isset(aw_blocked_ids($GLOBALS['db'], (int)($_SESSION['user_id'] ?? 0))[(int)$comment['user_id']]);
    if ($aw_fold) echo '<details class="aw-blocked-fold"><summary>A comment from someone you blocked</summary>';
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
        <div class="review-comment"><?php require_once __DIR__ . '/includes/mentions.php'; echo aw_mentions_render($GLOBALS['db'], nl2br(htmlspecialchars($comment['comment'])), 'review', (int)$comment['id']); /* N2 */ ?></div>
        <div class="reaction-buttons" data-target-type="comment" data-target-id="<?php echo (int)$comment['id']; ?>">
            <?php echo aw_rx_buttons('comment', $comment['id']); /* L2: rendered with the page */ ?>
        </div>
        <button type="button" class="reply-link" data-comment-id="<?php echo (int)$comment['id']; ?>" aria-expanded="false">Reply</button><!-- A1: was a clickable div -->
        <?php echo aw_report_controls('review', (int)$comment['id'], (int)($comment['user_id'] ?? 0), (string)$comment['author_name']); /* N4 */ ?>
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
    if ($aw_fold) echo '</details>';
}

// ============================================================
// 11. COMMENT COUNTER & RATINGS
// ============================================================
$stmt = $db->prepare("SELECT COUNT(*) FROM reviews WHERE target_type='poem' AND target_id=? AND is_private=0 AND deleted_at IS NULL");
$stmt->execute([$id]);
$public_comment_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE target_type='poem' AND target_id=? AND is_private=0");
$stmt->execute([$id]);
$rating_data = $stmt->fetch(PDO::FETCH_ASSOC);
$avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
$total_reviews = $rating_data['total'] ?? 0;

// ============================================================
// 12. OUTPUT PAGE
// ============================================================
?>
<?php
// Q32: the page had no $pageTitle (browser tab showed only the site name) and printed its Open Graph
// tags after the header, inside <body>, where share previews ignore them, while the header printed
// generic ones. The header now gets the poem's title, description, URL and image (it escapes them).
$pageTitle = $poem['title'];
$og_title = $poem['title'];
$og_description = aw_og_text(($poem['intro'] ?? '') !== '' ? $poem['intro'] : $poem['content'], 180);
$og_url = $page_url; // the page itself (the canonical address), even when sharing goes through the relay
$og_image_width = 1200;
$og_image_height = 630;
$og_image_alt = 'Cover of the poem “' . $poem['title'] . '” on AngelWrites';
$aw_share_url = $share_url;
require_once 'includes/header.php';
echo aw_save_assets();
?>

<style>
:root {
    --rose: #DBA1A2; --rose-dark: #c08a8b; --rose-light: #e8c0c0;
    --vanilla: #EFD8D6; --fantasy: #F7F3ED; --white: #ffffff;
    --dark: #2c1e1e; --text: #3d2e2e; --text-light: #6b5a5a;
    --bg: #F7F3ED; --card-bg: #ffffff; --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08); --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --input-bg: #ffffff;
}
body.dark-mode { --bg: #1a1212; --card-bg: #2c1e1e; --border: #4a3a3a; --text: #e8dddd; --text-light: #b8a8a8; --vanilla: #2c1e1e; --fantasy: #1a1212; --shadow: 0 4px 20px rgba(0,0,0,0.4); --shadow-hover: 0 12px 40px rgba(0,0,0,0.5); --input-bg: #3d2e2e; }
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
.rating-stars .filled { color: #c9965a; }
.rating-stars .empty { color: #e5d5d5; }
.rating-score { font-weight: 700; font-size: 1.1rem; }
.rating-count { color: var(--text-light); font-size: 0.9rem; }

/* ============================================================
   REACTION BUTTONS (New Modern Styling)
   ============================================================ */
.reaction-section {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin: 24px 0 32px;
    padding: 12px 20px;
    background: var(--vanilla);
    border-radius: 16px;
    border: 1px solid var(--border);
}
.reaction-section > span {
    font-weight: 600;
    color: var(--text);
    margin-right: 4px;
}
.reaction-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.reaction-btn {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 50px;
    padding: 6px 14px;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--text);
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: var(--shadow);
}
.reaction-btn:hover {
    background: var(--vanilla);
    border-color: var(--rose);
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}
.reaction-btn.active {
    background: var(--rose-light);
    border-color: var(--rose);
    color: var(--rose-dark);
}
.reaction-btn .count {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-light);
}
.reaction-particle {
    position: fixed;
    pointer-events: none;
    z-index: 99999;
    font-size: 2rem;
    animation: burst 1.6s cubic-bezier(.2,.8,.2,1.2) forwards;
}
@keyframes burst {
    0% { opacity: 1; transform: translate(0) scale(.5); }
    100% { opacity: 0; transform: translate(var(--tx), var(--ty)) scale(1.8) rotate(720deg); }
}
.reaction-btn:active { transform: scale(.85); transition: transform .1s; }
.reaction-btn.active { animation: pop-active .4s ease; }
@keyframes pop-active {
    0% { transform: scale(1); }
    50% { transform: scale(1.3); box-shadow: 0 0 20px var(--rose); }
    100% { transform: scale(1); }
}

/* ============================================================
   VOICE COMMENT RECORDER (New Modern Styling)
   ============================================================ */
.voice-comment-section {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 16px 20px;
    margin-bottom: 16px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.voice-comment-section h4 {
    font-size: 1rem;
    margin-bottom: 12px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.recorder-wrapper {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
#recordBtn {
    padding: 8px 20px;
    border-radius: 50px;
    background: var(--rose);
    color: white;
    border: none;
    transition: background 0.2s, transform 0.2s;
    font-weight: 600;
    font-size: 0.9rem;
}
#recordBtn:hover { background: var(--rose-dark); transform: translateY(-2px); }
#recordBtn.btn-danger { background: #b4505a; }
#recordBtn.btn-danger:hover { background: #94404a; }

/* ============================================================
   ADMIN REPLY SECTION (New Modern Styling)
   ============================================================ */
.admin-reply-container {
    background: var(--vanilla);
    border-radius: 16px;
    padding: 20px;
    margin: 16px 0;
    border: 1px solid var(--rose-light);
    box-shadow: var(--shadow);
}
.admin-reply-container h4 {
    font-size: 1rem;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--rose-dark);
}
.admin-reply-form .form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 12px;
    resize: vertical;
    min-height: 60px;
    background: var(--input-bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    transition: border-color 0.2s;
}
.admin-reply-form .form-group textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.admin-reply-form .btn-primary {
    margin-top: 8px;
}

/* ============================================================
   COMMENT & REPLY FORM TWEAKS
   ============================================================ */
.comment-reply-form { margin-left: 20px; margin-top: 8px; }
.reply-link { background: none; border: 0; padding: 4px 0; font: inherit; cursor: pointer; color: var(--rose); font-size: 0.8rem; margin-left: 8px; text-decoration: underline; }
.reply-link:hover { color: var(--rose-dark); }
.private-badge { background: #ffd700; color: #3d2e2e; font-size: 0.6rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: 6px; }
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
.review-rating .filled { color: #c9965a; }
.review-rating .empty { color: #e5d5d5; }
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

/* Q32: tap targets at least 44 px on phones (reactions, back link, share and small buttons) */
@media (max-width:768px) {
    .reaction-btn, .poem-nav .back-link, .share-btn, .btn-sm { min-height:44px; min-width:44px; display:inline-flex; align-items:center; justify-content:center; }
    .checkbox-group input[type="checkbox"] { width:22px; height:22px; }
}
</style>

<div class="poem-view-page">
    <div class="container">
        <div class="poem-nav aw-hide-focus">
            <a href="<?php echo SITE_URL; ?>/poetry.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Poetry
            </a>
        </div>

        <?php
        // V2: the poem is shown by the shared reader – clean stanzas, reading tools, and a player
        // only when the recording file exists (includes/poem_reader.php).
        require_once __DIR__ . '/includes/poem_reader.php';
        echo aw_poem_assets();
        echo aw_poem_reader($poem, $db);
        ?>

        <!-- Styled Reaction Section -->
        <div class="reaction-section aw-hide-focus">
            <span>React to this poem:</span>
            <div class="reaction-buttons" data-target-type="poem" data-target-id="<?php echo (int)$id; ?>">
                <?php echo aw_rx_buttons('poem', $id); /* L2: rendered with the page */ ?>
            </div>
        </div>

        <div class="reviews-section aw-hide-focus" id="comments">
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

            <div style="font-size:0.9rem; color:var(--text-light); margin-bottom:12px;">
                <i class="fas fa-comment"></i> <?php echo $public_comment_count; ?> public <?php echo $public_comment_count == 1 ? 'comment' : 'comments'; ?>
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

                <!-- Styled Voice Recorder -->
                <div class="voice-comment-section">
                    <h4>🎙️ Record a Voice Comment</h4>
                    <div class="recorder-wrapper">
                        <button type="button" id="recordBtn" class="btn btn-secondary btn-sm">🎙️ Start Recording</button>
                        <span id="recordingStatus" style="display:none; font-weight:600; color:#b4505a;">🔴 Recording...</span>
                        <form method="POST" enctype="multipart/form-data" id="voiceForm" style="display:none; margin-top:10px;">
                            <input type="hidden" name="submit_voice_comment" value="1">
                            <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                            <input type="file" name="voice_file" id="voiceFileInput" accept="audio/webm" required>
                            <button type="submit" class="btn btn-success btn-sm">Upload Voice Comment</button>
                        </form>
                        <div id="voicePreviewContainer" style="display:none; margin-top:10px;">
                            <audio controls id="voicePreview" style="width:100%;"><source src="" type="audio/webm"></audio>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <p><a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode(SITE_URL . '/poem_view.php?id=' . $id); ?>">Login</a> to comment, rate, or react.</p>
                </div>
            <?php endif; ?>

            <!-- Styled Admin Reply Section -->
            <?php if (isAdmin()): ?>
                <div class="admin-reply-container">
                    <h4>🛡️ Angella's Reply</h4>
                    <form method="POST" class="admin-reply-form">
                        <input type="hidden" name="add_admin_reply" value="1">
                        <input type="hidden" name="target_id" value="<?php echo $id; ?>">
                        <div class="form-group">
                            <textarea name="admin_reply" rows="3" placeholder="Reply to this poem directly..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Post Reply</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="reviews-list">
                <?php if ($comments_total > 0): ?>
                    <?php foreach ($comments as $comment): ?>
                        <?php render_comment($comment); ?>
                    <?php endforeach; ?>
                    <?php echo aw_pager($comments_total, $cpage, $cper, 'comments'); // P12 ?>
                <?php else: ?>
                    <p style="text-align:center; color:var(--text-light);">No comments yet. Be the first to share your thoughts!</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="poem-footer-actions aw-hide-focus">
            <?php echo aw_save_button('poem', $id); ?>
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
<!-- COMPLETE JAVASCRIPT – Audio, Reactions, Tagging, Reply, Recorder  -->
<!-- ================================================================ -->
<script src="<?php echo SITE_URL; ?>/assets/js/aw-media.js?v=1"></script><!-- M2: noise reduction + editor for voice recordings -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== SPLASHY BURST FUNCTION =====
    function createReactionBurst(element, emoji) {
        const rect = element.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const count = 30;
        for (let i = 0; i < count; i++) {
            const particle = document.createElement('div');
            particle.className = 'reaction-particle';
            particle.textContent = emoji;
            const angle = Math.random() * Math.PI * 2;
            const distance = 80 + Math.random() * 150;
            const tx = Math.cos(angle) * distance;
            const ty = Math.sin(angle) * distance - 40;
            particle.style.left = x + 'px';
            particle.style.top = y + 'px';
            particle.style.setProperty('--tx', tx + 'px');
            particle.style.setProperty('--ty', ty + 'px');
            particle.style.fontSize = (1.5 + Math.random() * 2.5) + 'rem';
            document.body.appendChild(particle);
            setTimeout(() => particle.remove(), 1800);
        }
    }

    // ===== REACTION TOGGLE =====
    document.querySelectorAll('.reaction-buttons').forEach(container => {
        const targetType = container.dataset.targetType;
        const targetId = container.dataset.targetId;
        const buttons = container.querySelectorAll('.reaction-btn');

        // L2: counts and state arrive with the page; nothing is fetched on load.

        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                const reaction = this.dataset.reaction;
                const emoji = this.textContent.trim().charAt(0);
                createReactionBurst(this, emoji);

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
                    this.classList.toggle('active', !!data.active);
                    this.setAttribute('aria-pressed', data.active ? 'true' : 'false');
                })
                .catch(err => console.error('Error toggling reaction:', err));
            });
        });
    });

    // ===== TAGGING AUTOCOMPLETE =====
    const commentText = document.getElementById('commentText');
    const suggestions = document.getElementById('tagSuggestions');
    let usersList = [];
    let tagTimer = null;

    // L2: the member list for @-tagging is fetched only when someone first types "@",
    // not on every page view.
    let usersLoaded = false;
    function loadUsersOnce() {
        if (usersLoaded) return;
        usersLoaded = true;
        fetch('ajax_get_users.php')
            .then(res => res.json())
            .then(data => usersList = Array.isArray(data) ? data : [])
            .catch(err => { usersLoaded = false; console.error('Failed to load users for tagging:', err); });
    }

    if(commentText) {
        commentText.addEventListener('input', function() {
            clearTimeout(tagTimer);
            const text = this.value;
            const atPos = text.lastIndexOf('@');
            
            if (atPos !== -1) loadUsersOnce();
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
    }

    if(suggestions) {
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
    }

    document.addEventListener('click', function(e) {
        if (suggestions && suggestions.style.display === 'block' && 
            !suggestions.contains(e.target) && 
            e.target !== commentText) {
            suggestions.style.display = 'none';
        }
    });

    // ===== REPLY TOGGLE =====
    document.querySelectorAll('.reply-link').forEach(link => {
        link.addEventListener('click', function() {
            const form = this.nextElementSibling;
            const open = form.style.display === 'none';
            form.style.display = open ? 'block' : 'none';
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) form.querySelector('textarea').focus();
        });
    });
    document.querySelectorAll('.cancel-reply').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.comment-reply-form').style.display = 'none';
        });
    });

    // ===== VOICE RECORDER =====
    const recordBtn = document.getElementById('recordBtn');
    if (recordBtn) {
        const recordingStatus = document.getElementById('recordingStatus');
        const voiceForm = document.getElementById('voiceForm');
        const voiceFileInput = document.getElementById('voiceFileInput');
        const voicePreviewContainer = document.getElementById('voicePreviewContainer');
        const voicePreview = document.getElementById('voicePreview');
        let mediaRecorder = null;
        let audioChunks = [];

        recordBtn.addEventListener('click', async function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                recordingStatus.style.display = 'none';
                recordBtn.textContent = '🎙️ Start Recording';
                recordBtn.classList.remove('btn-danger');
                recordBtn.classList.add('btn-secondary');
                return;
            }
            try {
                const stream = await AWMedia.getStream({ audio: true }); // M2: noise reduction while recording
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];
                mediaRecorder.ondataavailable = event => {
                    audioChunks.push(event.data);
                };
                mediaRecorder.onstop = async () => {
                    stream.getTracks().forEach(t => t.stop()); // M2: release the microphone
                    // M2: trim, cut and clean up the recording before it is sent
                    const audioBlob = await AWMedia.review(new Blob(audioChunks, { type: 'audio/webm' }), { kind: 'audio', title: 'Edit your voice comment' });
                    const file = new File([audioBlob], 'voice_comment.webm', { type: 'audio/webm' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    voiceFileInput.files = dt.files;
                    const url = URL.createObjectURL(file);
                    voicePreview.src = url;
                    AWMedia.attachEditButton(voiceFileInput, voicePreview, 'audio', 'voice_comment.webm'); // M2
                    voicePreviewContainer.style.display = 'block';
                    voiceForm.style.display = 'block';
                    recordBtn.textContent = '🎙️ Record Again';
                };
                mediaRecorder.start();
                recordingStatus.style.display = 'inline';
                recordBtn.textContent = '⏹️ Stop Recording';
                recordBtn.classList.remove('btn-secondary');
                recordBtn.classList.add('btn-danger');
            } catch (error) {
                alert('Microphone access denied or not available.');
                console.error('Recording error:', error);
            }
        });
    }

    // V2: the recording player now lives in assets/js/aw-poem.js (shown only when a recording exists).
});
</script>

<?php require_once __DIR__ . '/includes/moderation.php'; echo aw_report_assets(); /* N4 */ ?>
<?php require_once 'includes/footer.php'; ?>