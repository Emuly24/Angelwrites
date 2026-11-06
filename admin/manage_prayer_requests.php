<?php
// ============================================================
//  MANAGE_PRAYER_REQUESTS.PHP – prayer requests from blog posts and the reader
// ============================================================
// F138: members send prayer requests from blog posts (blog_post.php) and from the reader
// (reader_ajax.php), but no admin screen listed them. This page lists them, newest first,
// and lets the admin mark one as prayed for or delete it.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

redirectIfNotAdmin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Invalid request.');
    }
    $rid = (int)($_POST['request_id'] ?? 0);
    if (isset($_POST['mark_prayed'])) {
        $db->prepare("UPDATE prayer_requests SET status = 'prayed' WHERE id = ?")->execute([$rid]);
    } elseif (isset($_POST['mark_open'])) {
        $db->prepare("UPDATE prayer_requests SET status = 'pending' WHERE id = ?")->execute([$rid]);
    } elseif (isset($_POST['delete_request'])) {
        $db->prepare("DELETE FROM prayer_requests WHERE id = ?")->execute([$rid]);
    }
    header('Location: ' . SITE_URL . '/admin/manage_prayer_requests.php');
    exit;
}

// P6: was capped at 300 with no way to see older requests; 20 per page now.
require_once __DIR__ . '/../includes/pagination.php';
[$page, $per_page] = aw_page(20);
$requests_total = (int)$db->query("SELECT COUNT(*) FROM prayer_requests")->fetchColumn();
[$page, $offset] = aw_clamp($requests_total, $page, $per_page);
$requests = $db->query("
    SELECT pr.id, pr.status, pr.created_at,
           COALESCE(NULLIF(pr.message, ''), pr.request_text) AS text,
           u.name, u.first_name, u.last_name, u.username, u.email,
           bp.title AS post_title, bp.slug AS post_slug, b.title AS book_title, pr.book_id
    FROM prayer_requests pr
    LEFT JOIN users u ON u.id = pr.user_id
    LEFT JOIN blog_posts bp ON bp.id = pr.reflection_id
    LEFT JOIN books b ON b.id = pr.book_id
    ORDER BY (pr.status = 'prayed'), pr.created_at DESC, pr.id DESC
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Prayer Requests';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:960px;margin:0 auto;padding:24px 16px;">
        <h1>🙏 Prayer Requests</h1>
        <p><a href="<?php echo SITE_URL; ?>/admin/dashboard.php">← Admin dashboard</a></p>
        <?php if (!$requests): ?>
            <p>No prayer requests yet.</p>
        <?php endif; ?>
        <?php foreach ($requests as $r): ?>
            <div class="comment-item" style="border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:12px;<?php echo $r['status'] === 'prayed' ? 'opacity:0.65;' : ''; ?>">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <strong><?php echo htmlspecialchars($r['username'] !== null ? awDisplayName($r) : 'Deleted member'); ?></strong>
                    <small><?php echo htmlspecialchars((string)$r['email']); ?> · <?php echo date('M j, Y g:i a', strtotime($r['created_at'])); ?></small>
                </div>
                <div style="font-size:0.9rem;margin:4px 0;">
                    <?php if ($r['post_title']): ?>From the post <a href="<?php echo SITE_URL; ?>/blog_post.php?slug=<?php echo urlencode((string)$r['post_slug']); ?>"><?php echo htmlspecialchars($r['post_title']); ?></a>
                    <?php elseif ($r['book_title']): ?>From the book <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$r['book_id']; ?>"><?php echo htmlspecialchars($r['book_title']); ?></a>
                    <?php endif; ?>
                    · <?php echo $r['status'] === 'prayed' ? '✅ Prayed for' : '⏳ Open'; ?>
                </div>
                <p style="margin:8px 0;"><?php echo nl2br(htmlspecialchars((string)$r['text'])); ?></p>
                <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                    <?php if ($r['status'] === 'prayed'): ?>
                        <button type="submit" name="mark_open" value="1" class="btn btn-sm btn-outline">Mark open</button>
                    <?php else: ?>
                        <button type="submit" name="mark_prayed" value="1" class="btn btn-sm btn-primary">Mark prayed for</button>
                    <?php endif; ?>
                    <?php if (!empty($r['email'])): ?>
                        <a class="btn btn-sm btn-outline" href="mailto:<?php echo htmlspecialchars($r['email']); ?>?subject=<?php echo rawurlencode('Your prayer request'); ?>">Reply by email</a>
                    <?php endif; ?>
                    <button type="submit" name="delete_request" value="1" class="btn btn-sm btn-danger" onclick="return confirm('Delete this prayer request?');">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php echo aw_pager($requests_total, $page, $per_page); // P6 ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
