<?php
// ============================================================
//  MY_PURCHASES.PHP – a member's book orders and receipts
// ============================================================
// F77: members had no record of what they had ordered or bought (dashboard, profile and library
// never read payments). This page lists every order with its reference, amount, method, phone
// and status; a confirmed order shows a receipt and links to read or download the book.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/commerce.php';

redirectIfNotLoggedIn();

$stmt = $db->prepare("
    SELECT p.id, p.book_id, p.amount, p.payment_method, p.transaction_id, p.phone_number, p.status, p.created_at, p.completed_at,
           b.title AS book_title
    FROM payments p LEFT JOIN books b ON b.id = p.book_id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT ? OFFSET ?
");
// P4: orders are paged, 10 at a time.
require_once __DIR__ . '/includes/pagination.php';
[$page, $per_page] = aw_page(10);
$cst = $db->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ?");
$cst->execute([$_SESSION['user_id']]);
$orders_total = (int)$cst->fetchColumn();
[$page, $offset] = aw_clamp($orders_total, $page, $per_page);
$stmt->execute([$_SESSION['user_id'], $per_page, $offset]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$methods = ['airtel' => 'Airtel Money', 'mpamba' => 'TNM Mpamba', 'nbm' => 'NBM Mo626'];
$instructions = aw_payment_instructions();

$pageTitle = 'My purchases';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:800px;margin:0 auto;padding:24px 16px;">
    <h1>🧾 My purchases</h1>
    <p><a href="<?php echo SITE_URL; ?>/dashboard.php">← Dashboard</a> · <a href="<?php echo SITE_URL; ?>/books.php">Browse books</a></p>
    <?php if (!$orders): ?>
        <p>You have not ordered any books yet.</p>
    <?php endif; ?>
    <?php foreach ($orders as $o):
        $owned = $o['status'] === 'completed' && aw_has_purchased($db, $_SESSION['user_id'], $o['book_id']);
    ?>
        <div style="border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <strong><?php echo htmlspecialchars((string)($o['book_title'] ?? 'Book no longer available')); ?></strong>
                <span><?php
                    if ($owned) echo '✅ Paid';
                    elseif ($o['status'] === 'pending') echo '⏳ Waiting for payment confirmation';
                    elseif ($o['status'] === 'rejected') echo '✖ Not confirmed';
                    else echo '⏳ Being checked';
                ?></span>
            </div>
            <div style="font-size:0.95rem;margin:6px 0;line-height:1.6;">
                Order reference: <strong>AW-<?php echo (int)$o['id']; ?></strong><br>
                Amount: <?php echo aw_mwk((float)$o['amount']); ?>
                · <?php echo htmlspecialchars($methods[$o['payment_method']] ?? (string)$o['payment_method']); ?>
                <?php if (!empty($o['phone_number'])): ?>· from <?php echo htmlspecialchars($o['phone_number']); ?><?php endif; ?><br>
                <?php if (!empty($o['transaction_id'])): ?>Your transaction ID: <?php echo htmlspecialchars($o['transaction_id']); ?><br><?php endif; ?>
                Ordered <?php echo date('M j, Y g:i a', strtotime($o['created_at'])); ?>
                <?php if ($owned && $o['completed_at']): ?> · Paid <?php echo date('M j, Y', strtotime($o['completed_at'])); ?><?php endif; ?>
            </div>
            <?php if ($o['status'] === 'pending' && $instructions !== ''): ?>
                <div style="white-space:pre-line;font-size:0.9rem;border-top:1px solid var(--border);padding-top:8px;">Send <?php echo aw_mwk((float)$o['amount']); ?> with the reference AW-<?php echo (int)$o['id']; ?> to:
<?php echo htmlspecialchars($instructions); ?></div>
            <?php elseif ($o['status'] === 'rejected'): ?>
                <p style="font-size:0.9rem;">We could not match a payment to this order. If you did pay, please <a href="<?php echo SITE_URL; ?>/contact.php">contact us</a> with the reference AW-<?php echo (int)$o['id']; ?>.</p>
            <?php endif; ?>
            <?php if ($owned): ?>
                <p style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a class="btn btn-sm btn-primary" href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$o['book_id']; ?>">Open book</a>
                    <a class="btn btn-sm btn-outline" href="<?php echo SITE_URL; ?>/download.php?book_id=<?php echo (int)$o['book_id']; ?>">Download</a>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php echo aw_pager($orders_total, $page, $per_page); // P4 ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
