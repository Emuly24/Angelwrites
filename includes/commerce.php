<?php
// Shared helpers for book orders (COMMERCE batch). Payments are confirmed by hand: the member pays
// by mobile money or bank using the order reference AW-<payment id>, and the admin confirms the
// order on admin/manage_payments.php.

// F75: the payment details members are shown (numbers / account to pay to). Saved by the admin on
// admin/manage_payments.php in the settings table. Empty means ordering is not open yet.
function aw_payment_instructions() {
    return trim((string)aw_setting('payment_instructions', ''));
}

// F79: a book is on sale when "On sale" is ticked and a sale price below the normal price is set.
function aw_book_on_sale(array $book) {
    $sale = (float)($book['sale_price'] ?? 0);
    return !empty($book['is_sale']) && $sale > 0 && $sale < (float)($book['price'] ?? 0);
}

// F35: the price a member is charged for a book, from the database (never from the browser).
// F79: the sale price while the book is on sale.
function aw_book_price(array $book) {
    return round(aw_book_on_sale($book) ? (float)$book['sale_price'] : (float)($book['price'] ?? 0), 2);
}

// F78: the admin confirms that the money for a pending order has arrived. Returns false if the
// order is not pending (already handled, or unknown).
// F34: payments were written to `payments` but the reader (S1), book page and download.php read
// `purchases`, so a paid book could never be opened. Confirming now records the purchase in
// `purchases` too, in the same transaction, so every gate sees it.
function aw_confirm_payment(PDO $db, $payment_id) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE payments SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
        $stmt->execute([(int)$payment_id]);
        if ($stmt->rowCount() !== 1) {
            $db->rollBack();
            return false;
        }
        $p = $db->prepare("SELECT user_id, book_id, amount FROM payments WHERE id = ?");
        $p->execute([(int)$payment_id]);
        $pay = $p->fetch(PDO::FETCH_ASSOC);
        $p->closeCursor();
        $db->prepare("INSERT OR IGNORE INTO purchases (user_id, book_id, amount, payment_status) VALUES (?, ?, ?, 'completed')")
           ->execute([$pay['user_id'], $pay['book_id'], $pay['amount']]);
        $db->prepare("UPDATE purchases SET payment_status = 'completed', amount = ? WHERE user_id = ? AND book_id = ?")
           ->execute([$pay['amount'], $pay['user_id'], $pay['book_id']]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('aw_confirm_payment(' . (int)$payment_id . '): ' . $e->getMessage());
        return false;
    }
}

// F34: has this member bought this book? Reads `purchases`, the table every gate uses. A missing
// table (before the DB repair) counts as not purchased.
function aw_has_purchased(PDO $db, $user_id, $book_id) {
    try {
        $stmt = $db->prepare("SELECT 1 FROM purchases WHERE user_id = ? AND book_id = ? AND payment_status = 'completed' LIMIT 1");
        $stmt->execute([(int)$user_id, (int)$book_id]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

// F78: the admin rejects a pending order (no payment arrived).
function aw_reject_payment(PDO $db, $payment_id) {
    $stmt = $db->prepare("UPDATE payments SET status = 'rejected' WHERE id = ? AND status = 'pending'");
    $stmt->execute([(int)$payment_id]);
    return $stmt->rowCount() === 1;
}

// F34: orders stored as 'completed' by the old simulated checkout (before F75) were never checked
// and are not in `purchases`. After checking the statement, the admin can unlock one.
function aw_unlock_legacy_payment(PDO $db, $payment_id) {
    $p = $db->prepare("SELECT user_id, book_id, amount FROM payments WHERE id = ? AND status = 'completed'");
    $p->execute([(int)$payment_id]);
    $pay = $p->fetch(PDO::FETCH_ASSOC);
    $p->closeCursor();
    if (!$pay || aw_has_purchased($db, $pay['user_id'], $pay['book_id'])) {
        return false;
    }
    $db->prepare("INSERT OR IGNORE INTO purchases (user_id, book_id, amount, payment_status) VALUES (?, ?, ?, 'completed')")
       ->execute([$pay['user_id'], $pay['book_id'], $pay['amount']]);
    $db->prepare("UPDATE purchases SET payment_status = 'completed' WHERE user_id = ? AND book_id = ?")
       ->execute([$pay['user_id'], $pay['book_id']]);
    return true;
}

// F77: tell the member (email receipt + bell notification) when their order is confirmed or rejected.
function aw_notify_payment_member(PDO $db, $payment_id, $status) {
    require_once __DIR__ . '/mail_helper.php';
    require_once __DIR__ . '/notifications.php';
    $st = $db->prepare("SELECT p.id, p.amount, p.completed_at, b.title, u.id AS uid, u.email
                        FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN books b ON b.id = p.book_id WHERE p.id = ?");
    $st->execute([(int)$payment_id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$o) return;
    $ref = 'AW-' . (int)$o['id'];
    $book = (string)($o['title'] ?? 'your book');
    $link = SITE_URL . '/my_purchases.php';
    if ($status === 'completed') {
        $title = 'Payment confirmed: ' . $book;
        $text = "Thank you! We have received your payment of MWK " . number_format((float)$o['amount'], 2) . " for \"$book\" (order $ref). You can now read and download it.";
        $body = "Receipt\n\nOrder: $ref\nBook: $book\nAmount paid: MWK " . number_format((float)$o['amount'], 2)
              . "\nConfirmed: " . date('j M Y', strtotime((string)($o['completed_at'] ?: 'now')))
              . "\n\nRead or download it from My purchases: $link\n\nBlessings,\nAngelWrites";
    } else {
        $title = 'Order not confirmed: ' . $book;
        $text = "We could not match a payment to order $ref for \"$book\". If you did pay, please reply with your transaction ID.";
        $body = "$text\n\nYour orders: $link\n\nAngelWrites";
    }
    awNotify($db, $o['uid'], 'payment_' . $status, $title, $text, $link);
    if (!empty($o['email'])) {
        sendEmail($o['email'], $title, $body, 'angelwrites@zohomail.com', 'AngelWrites', false);
    }
}
