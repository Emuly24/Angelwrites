<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login to report.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$target_type = $_POST['target_type'];
$target_id = (int)$_POST['target_id'];
$reason = $_POST['reason'];
$details = trim($_POST['details'] ?? '');

if (!in_array($target_type, ['question', 'answer'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid target type.']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Please select a reason.']);
    exit;
}

// Prevent self-reporting
if ($target_type === 'question') {
    $stmt = $db->prepare("SELECT user_id FROM questions WHERE id = ?");
    $stmt->execute([$target_id]);
    $owner = $stmt->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT user_id FROM answers WHERE id = ?");
    $stmt->execute([$target_id]);
    $owner = $stmt->fetchColumn();
}

if ($owner == $user_id) {
    echo json_encode(['success' => false, 'error' => 'You cannot report your own content.']);
    exit;
}

// Insert report
$stmt = $db->prepare("INSERT INTO reports (reporter_user_id, target_type, target_id, reason, details) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$user_id, $target_type, $target_id, $reason, $details]);

// Notify admin via email
$admin_email = 'angelwrites@zohomail.com';
$subject = '🚩 New Report: ' . ucfirst($target_type) . ' #' . $target_id;
$body = "<h2>New Report Submitted</h2>";
$body .= "<p><strong>Reporter:</strong> User #$user_id</p>";
$body .= "<p><strong>Target:</strong> " . ucfirst($target_type) . " #$target_id</p>";
$body .= "<p><strong>Reason:</strong> $reason</p>";
if ($details) {
    $body .= "<p><strong>Details:</strong><br>" . nl2br(htmlspecialchars($details)) . "</p>";
}
$body .= "<p><a href='" . SITE_URL . "/admin/manage_community.php'>Manage Reports</a></p>";
sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');

echo json_encode(['success' => true]);
exit;