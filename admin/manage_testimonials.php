<?php
// ============================================================
//  MANAGE_TESTIMONIALS.PHP – review testimonials before they appear on the homepage
// ============================================================
// F202: index.php saves testimonials with approved = 0 and shows only approved ones, but no admin
// screen could approve them, so none ever appeared. This page lists them (pending first) and lets
// the admin approve, hide or delete. Only testimonials the member marked public can be approved.
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
    $tid = (int)($_POST['testimonial_id'] ?? 0);
    if (isset($_POST['approve'])) {
        $db->prepare("UPDATE testimonials SET approved = 1 WHERE id = ? AND is_public = 1")->execute([$tid]);
    } elseif (isset($_POST['hide'])) {
        $db->prepare("UPDATE testimonials SET approved = 0 WHERE id = ?")->execute([$tid]);
    } elseif (isset($_POST['delete'])) {
        $db->prepare("DELETE FROM testimonials WHERE id = ?")->execute([$tid]);
    }
    header('Location: ' . SITE_URL . '/admin/manage_testimonials.php');
    exit;
}

// P7: was capped at 300 with no way to see older testimonials; 20 per page now.
require_once __DIR__ . '/../includes/pagination.php';
[$page, $per_page] = aw_page(20);
$items_total = (int)$db->query("SELECT COUNT(*) FROM testimonials")->fetchColumn();
[$page, $offset] = aw_clamp($items_total, $page, $per_page);
$items = $db->query("
    SELECT t.id, t.testimony, t.is_public, t.approved, t.created_at,
           u.name, u.first_name, u.last_name, u.username, u.email
    FROM testimonials t LEFT JOIN users u ON u.id = t.user_id
    ORDER BY t.approved ASC, t.created_at DESC, t.id DESC
    LIMIT $per_page OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Testimonials';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:960px;margin:0 auto;padding:24px 16px;">
        <h1>💬 Testimonials</h1>
        <p><a href="<?php echo SITE_URL; ?>/admin/dashboard.php">← Admin dashboard</a> · Approved testimonials appear on the homepage.</p>
        <?php if (!$items): ?>
            <p>No testimonials yet.</p>
        <?php endif; ?>
        <?php foreach ($items as $t): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <strong><?php echo htmlspecialchars($t['username'] !== null ? awDisplayName($t) : 'Deleted member'); ?></strong>
                    <small><?php echo date('M j, Y g:i a', strtotime($t['created_at'])); ?></small>
                </div>
                <div style="font-size:0.9rem;margin:4px 0;">
                    <?php echo $t['approved'] ? '✅ On the homepage' : '⏳ Waiting for review'; ?>
                    · <?php echo $t['is_public'] ? 'Member allows it to be shown' : '🔒 Member asked to keep it private'; ?>
                </div>
                <p style="margin:8px 0;"><?php echo nl2br(htmlspecialchars((string)$t['testimony'])); ?></p>
                <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="testimonial_id" value="<?php echo (int)$t['id']; ?>">
                    <?php if ($t['approved']): ?>
                        <button type="submit" name="hide" value="1" class="btn btn-sm btn-outline">Hide from homepage</button>
                    <?php elseif ($t['is_public']): ?>
                        <button type="submit" name="approve" value="1" class="btn btn-sm btn-primary">Approve for homepage</button>
                    <?php endif; ?>
                    <button type="submit" name="delete" value="1" class="btn btn-sm btn-danger" onclick="return confirm('Delete this testimonial?');">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php echo aw_pager($items_total, $page, $per_page); // P7 ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
