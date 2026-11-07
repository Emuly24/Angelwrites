<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';
require_once 'includes/commerce.php';

// ===== AUTHENTICATION =====
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];

// ===== ONLY ACCEPT POST REQUESTS =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ===== GET POST DATA =====
$book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
$phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
// F75: the member's own mobile-money / bank transaction ID, if they already have one (optional).
$payer_reference = isset($_POST['payer_reference']) ? mb_substr(trim($_POST['payer_reference']), 0, 60) : '';

// ===== VALIDATE INPUT =====
if (!$book_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid book ID']);
    exit;
}

if (!$payment_method || !in_array($payment_method, ['airtel', 'mpamba', 'nbm'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

if (!$phone_number || strlen($phone_number) < 10) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid phone number']);
    exit;
}

// F35: the amount used to be whatever the browser sent, so a member could "pay" MWK 1 for any
// book. It is now the book's price from the database; the posted amount is ignored.
$stmt = $db->prepare("SELECT id, title, price, is_free, is_sale, sale_price FROM books WHERE id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    echo json_encode(['success' => false, 'error' => 'Book not found']);
    exit;
}
if (!empty($book['is_free'])) {
    echo json_encode(['success' => false, 'error' => 'This book is free; no payment is needed.']);
    exit;
}
$amount = aw_book_price($book);

if ($amount <= 0) {
    // F80: a book that is not free but has no price set cannot be ordered; say so plainly.
    echo json_encode(['success' => false, 'error' => 'This book is not on sale yet. Please check back soon.']);
    exit;
}

// ===== CHECK IF USER ALREADY PURCHASED THIS BOOK =====
// F34: ownership is read from `purchases`, the table the reader and download gates use.
if (aw_has_purchased($db, $user_id, $book_id)) {
    echo json_encode(['success' => false, 'error' => 'You have already purchased this book']);
    exit;
}

// F75: one open order per book; the member is shown it again instead of creating another.
$stmt = $db->prepare("SELECT id, amount FROM payments WHERE user_id = ? AND book_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id, $book_id]);
if ($open = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode([
        'success' => true,
        'pending' => true,
        'reference' => 'AW-' . $open['id'],
        'amount' => (float)$open['amount'],
        'instructions' => aw_payment_instructions(),
        'message' => 'You already have an order for this book waiting for confirmation.'
    ]);
    exit;
}

// F75: no payment provider is connected, so nothing was ever charged, yet the old code stored
// every "payment" as completed. The order is now stored as pending; the member pays by mobile
// money or bank using the order reference AW-<id>, and the admin confirms it on
// admin/manage_payments.php once the money has arrived. transaction_id holds the member's own
// transaction ID when they give one.
if (!aw_payment_instructions()) {
    echo json_encode(['success' => false, 'error' => 'Online ordering is not open yet. Please contact us to buy this book.']);
    exit;
}

// ===== INSERT PAYMENT RECORD =====
try {
    $stmt = $db->prepare("
        INSERT INTO payments (user_id, book_id, amount, payment_method, transaction_id, phone_number, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP)
    ");
    // F76: the payer's phone number is stored with the order (it was only in the admin email).
    $stmt->execute([$user_id, $book_id, $amount, $payment_method, $payer_reference !== '' ? $payer_reference : null, mb_substr($phone_number, 0, 30)]);
    $payment_id = $db->lastInsertId();
    $reference = 'AW-' . $payment_id;

    // ===== LOG PAYMENT FOR ADMIN =====
    $admin_email = aw_admin_email();
    $subject = '💳 New book order waiting for payment confirmation (' . $reference . ')';
    $body = "<h2>New order: check for the payment, then confirm it</h2>";
    $body .= "<p><strong>Order reference:</strong> $reference</p>";
    $body .= "<p><strong>Member's transaction ID:</strong> " . htmlspecialchars($payer_reference !== '' ? $payer_reference : 'not given') . "</p>";
    $body .= "<p><strong>User ID:</strong> $user_id</p>";
    $body .= "<p><strong>Book ID:</strong> $book_id</p>";
    $body .= "<p><strong>Amount:</strong> MWK " . number_format($amount, 2) . "</p>";
    $body .= "<p><strong>Payment Method:</strong> " . ucfirst($payment_method) . "</p>";
    $body .= "<p><strong>Phone Number:</strong> " . htmlspecialchars($phone_number) . "</p>";
    $body .= "<p>Confirm or reject it at " . SITE_URL . "/admin/manage_payments.php</p>";
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');

    // ===== RETURN SUCCESS RESPONSE =====
    echo json_encode([
        'success' => true,
        'pending' => true,
        'payment_id' => $payment_id,
        'reference' => $reference,
        'amount' => $amount,
        'instructions' => aw_payment_instructions(),
        'message' => 'Order received. Your book unlocks as soon as we confirm your payment.'
    ]);

} catch (Exception $e) {
    // ===== RETURN ERROR RESPONSE =====
    echo json_encode([
        'success' => false,
        'error' => 'Payment processing failed. Please try again.'
    ]);
}
exit;