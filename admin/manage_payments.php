<?php
// ============================================================
//  MANAGE_PAYMENTS.PHP – book orders paid by mobile money or bank, confirmed by hand
// ============================================================
// F78: payments were written to the database but no admin screen showed them, so the admin could
// not see who had ordered what or confirm that money had arrived. Orders are placed on books.php
// (process_payment.php) as 'pending' with the reference AW-<id>. Here the admin sets the payment
// details members are shown, checks each order against their mobile-money / bank statement, and
// confirms or rejects it.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commerce.php';

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
    $msg = '';
    if (isset($_POST['save_instructions'])) {
        $text = mb_substr(trim((string)($_POST['payment_instructions'] ?? '')), 0, 2000);
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('payment_instructions', ?)")->execute([$text]);
        $msg = $text === '' ? 'Payment details cleared: ordering is closed.' : 'Payment details saved.';
    } else {
        $pid = (int)($_POST['payment_id'] ?? 0);
        if (isset($_POST['confirm'])) {
            if (aw_confirm_payment($db, $pid)) {
                aw_notify_payment_member($db, $pid, 'completed'); // F77
                $msg = 'Order AW-' . $pid . ' confirmed; the member has been told.';
            } else {
                $msg = 'That order is not pending.';
            }
        } elseif (isset($_POST['unlock_legacy'])) {
            if (aw_unlock_legacy_payment($db, $pid)) {
                aw_notify_payment_member($db, $pid, 'completed'); // F77
                $msg = 'Order AW-' . $pid . ' unlocked; the member has been told.';
            } else {
                $msg = 'Nothing to unlock for that order.';
            }
        } elseif (isset($_POST['reject'])) {
            if (aw_reject_payment($db, $pid)) {
                aw_notify_payment_member($db, $pid, 'rejected'); // F77
                $msg = 'Order AW-' . $pid . ' rejected; the member has been told.';
            } else {
                $msg = 'That order is not pending.';
            }
        }
    }
    header('Location: ' . SITE_URL . '/admin/manage_payments.php?msg=' . urlencode($msg));
    exit;
}

$filter = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'completed', 'rejected', 'all'], true)) {
    $filter = 'pending';
}
$where = $filter === 'all' ? '' : 'WHERE p.status = ' . $db->quote($filter);
$orders = $db->query("
    SELECT p.id, p.amount, p.payment_method, p.transaction_id, p.phone_number, p.status, p.created_at, p.completed_at,
           b.title AS book_title, p.book_id,
           u.name, u.first_name, u.last_name, u.username, u.email, p.user_id
    FROM payments p
    LEFT JOIN books b ON b.id = p.book_id
    LEFT JOIN users u ON u.id = p.user_id
    $where
    ORDER BY p.created_at DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);
$counts = $db->query("SELECT status, COUNT(*) FROM payments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$methods = ['airtel' => 'Airtel Money', 'mpamba' => 'TNM Mpamba', 'nbm' => 'NBM Mo626'];

$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-page">
    <div class="container" style="max-width:1000px;margin:0 auto;padding:24px 16px;">
        <h1>💳 Book orders and payments</h1>
        <p><a href="<?php echo SITE_URL; ?>/admin/dashboard.php">← Admin dashboard</a></p>
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-success" style="padding:10px 14px;border-radius:8px;margin:10px 0;"><?php echo htmlspecialchars((string)$_GET['msg']); ?></div>
        <?php endif; ?>

        <form method="POST" style="border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin:16px 0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <label for="payment_instructions"><strong>Payment details shown to members</strong></label>
            <p style="font-size:0.9rem;margin:4px 0 8px;">Where members send the money, e.g. "Airtel Money: 099… (name)", "TNM Mpamba: 088…", bank account. They are told to use their order reference. While this is empty, ordering is closed.</p>
            <textarea id="payment_instructions" name="payment_instructions" rows="4" style="width:100%;"><?php echo htmlspecialchars(aw_payment_instructions()); ?></textarea>
            <button type="submit" name="save_instructions" value="1" class="btn btn-primary btn-sm" style="margin-top:8px;">Save payment details</button>
        </form>

        <p>
            <?php foreach (['pending' => '⏳ Waiting', 'completed' => '✅ Confirmed', 'rejected' => '✖ Rejected', 'all' => 'All'] as $k => $label): ?>
                <a href="?status=<?php echo $k; ?>" class="btn btn-sm <?php echo $filter === $k ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $label; ?><?php if ($k !== 'all'): ?> (<?php echo (int)($counts[$k] ?? 0); ?>)<?php endif; ?></a>
            <?php endforeach; ?>
        </p>

        <?php if (!$orders): ?>
            <p>No orders here.</p>
        <?php endif; ?>
        <?php foreach ($orders as $o): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <strong>AW-<?php echo (int)$o['id']; ?> · <?php echo htmlspecialchars((string)($o['book_title'] ?? 'Deleted book')); ?></strong>
                    <small><?php echo date('M j, Y g:i a', strtotime($o['created_at'])); ?></small>
                </div>
                <div style="font-size:0.95rem;margin:6px 0;line-height:1.6;">
                    Member: <?php echo htmlspecialchars($o['username'] !== null ? awDisplayName($o) : 'Deleted member'); ?>
                    <?php if (!empty($o['email'])): ?>(<a href="mailto:<?php echo htmlspecialchars($o['email']); ?>"><?php echo htmlspecialchars($o['email']); ?></a>)<?php endif; ?><br>
                    Amount: <strong>MWK <?php echo number_format((float)$o['amount'], 2); ?></strong>
                    · Method: <?php echo htmlspecialchars($methods[$o['payment_method']] ?? (string)$o['payment_method']); ?>
                    · Phone: <?php echo htmlspecialchars((string)($o['phone_number'] ?? '') ?: 'not recorded'); ?><br>
                    Member's transaction ID: <?php echo htmlspecialchars((string)($o['transaction_id'] ?? '') ?: 'not given'); ?><br>
                    Status: <?php echo $o['status'] === 'completed' ? '✅ Confirmed' . ($o['completed_at'] ? ' ' . date('M j, Y', strtotime($o['completed_at'])) : '') : ($o['status'] === 'rejected' ? '✖ Rejected' : '⏳ Waiting for payment'); ?>
                </div>
                <?php if ($o['status'] === 'completed' && !aw_has_purchased($db, $o['user_id'], $o['book_id'])): ?>
                    <p style="font-size:0.9rem;margin:6px 0;">⚠ Recorded as paid by the old checkout, which took no money and checked nothing. The member cannot open the book. Check your statement before unlocking it.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int)$o['id']; ?>">
                        <button type="submit" name="unlock_legacy" value="1" class="btn btn-sm btn-outline" onclick="return confirm('Have you checked that this payment really arrived?');">Money verified: unlock</button>
                    </form>
                <?php endif; ?>
                <?php if ($o['status'] === 'pending'): ?>
                    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int)$o['id']; ?>">
                        <button type="submit" name="confirm" value="1" class="btn btn-sm btn-primary" onclick="return confirm('Confirm that MWK <?php echo number_format((float)$o['amount'], 2); ?> for AW-<?php echo (int)$o['id']; ?> has arrived? The member can then read and download the book.');">Payment received: confirm</button>
                        <button type="submit" name="reject" value="1" class="btn btn-sm btn-danger" onclick="return confirm('Reject order AW-<?php echo (int)$o['id']; ?>?');">Reject</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
