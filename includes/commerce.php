<?php
// Shared helpers for book orders (COMMERCE batch). Payments are confirmed by hand: the member pays
// by mobile money or bank using the order reference AW-<payment id>, and the admin confirms the
// order on admin/manage_payments.php.

// F75: the payment details members are shown (numbers / account to pay to). Saved by the admin on
// admin/manage_payments.php in the settings table. Empty means ordering is not open yet.
function aw_payment_instructions() {
    return trim((string)aw_setting('payment_instructions', ''));
}

// F35: the price a member is charged for a book, from the database (never from the browser).
function aw_book_price(array $book) {
    return round((float)($book['price'] ?? 0), 2);
}
