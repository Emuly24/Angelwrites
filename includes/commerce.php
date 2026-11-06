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
function aw_confirm_payment(PDO $db, $payment_id) {
    $stmt = $db->prepare("UPDATE payments SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
    $stmt->execute([(int)$payment_id]);
    return $stmt->rowCount() === 1;
}

// F78: the admin rejects a pending order (no payment arrived).
function aw_reject_payment(PDO $db, $payment_id) {
    $stmt = $db->prepare("UPDATE payments SET status = 'rejected' WHERE id = ? AND status = 'pending'");
    $stmt->execute([(int)$payment_id]);
    return $stmt->rowCount() === 1;
}
