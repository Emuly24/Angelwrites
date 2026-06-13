<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

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

if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount']);
    exit;
}

// ===== CHECK IF USER ALREADY PURCHASED THIS BOOK =====
$stmt = $db->prepare("SELECT id FROM payments WHERE user_id = ? AND book_id = ? AND status = 'completed'");
$stmt->execute([$user_id, $book_id]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'You have already purchased this book']);
    exit;
}

// ===== GENERATE UNIQUE TRANSACTION ID =====
$transaction_id = 'TXN_' . strtoupper(bin2hex(random_bytes(8))) . '_' . time();

// ===== INSERT PAYMENT RECORD =====
try {
    $stmt = $db->prepare("
        INSERT INTO payments (user_id, book_id, amount, payment_method, transaction_id, status, created_at, completed_at)
        VALUES (?, ?, ?, ?, ?, 'completed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$user_id, $book_id, $amount, $payment_method, $transaction_id]);
    $payment_id = $db->lastInsertId();

    // ===== LOG PAYMENT FOR ADMIN =====
    $admin_email = 'angelwrites@zohomail.com';
    $subject = '💳 New Payment Received';
    $body = "<h2>New Payment Completed</h2>";
    $body .= "<p><strong>Payment ID:</strong> $payment_id</p>";
    $body .= "<p><strong>Transaction ID:</strong> $transaction_id</p>";
    $body .= "<p><strong>User ID:</strong> $user_id</p>";
    $body .= "<p><strong>Book ID:</strong> $book_id</p>";
    $body .= "<p><strong>Amount:</strong> MWK " . number_format($amount, 2) . "</p>";
    $body .= "<p><strong>Payment Method:</strong> " . ucfirst($payment_method) . "</p>";
    $body .= "<p><strong>Phone Number:</strong> $phone_number</p>";
    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');

    // ===== RETURN SUCCESS RESPONSE =====
    echo json_encode([
        'success' => true,
        'payment_id' => $payment_id,
        'transaction_id' => $transaction_id,
        'message' => 'Payment completed successfully. You can now download your book.'
    ]);

} catch (Exception $e) {
    // ===== RETURN ERROR RESPONSE =====
    echo json_encode([
        'success' => false,
        'error' => 'Payment processing failed. Please try again.'
    ]);
}
exit;