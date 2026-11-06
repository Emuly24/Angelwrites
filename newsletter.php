<?php
// ===== LOAD CONFIGURATION FIRST =====
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // Now safely loaded after config
require_once 'includes/newsletter_optin.php';

// ===== F176: CONFIRM A SUBSCRIPTION FROM THE EMAILED LINK =====
if (isset($_GET['confirm'])) {
    $stmt = $db->prepare("SELECT id, email, name, is_active FROM newsletter WHERE unsubscribe_token = ?");
    $stmt->execute([trim((string)$_GET['confirm'])]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        $confirm_error = 'This confirmation link is not valid. Please subscribe again.';
    } else {
        if ($sub['is_active'] != 1) {
            $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL WHERE id = ?")->execute([$sub['id']]);
            sendEmail(aw_admin_email(), 'New Newsletter Subscriber (confirmed)',
                "A subscriber confirmed their newsletter subscription.\n\nEmail: {$sub['email']}\nName: " . ($sub['name'] ?: 'Not provided'),
                'angelwrites@zohomail.com', 'AngelWrites', false);
        }
        $confirm_success = 'Thank you! Your subscription is confirmed. You can unsubscribe at any time from the link in each newsletter.';
    }
    $pageTitle = 'Newsletter';
    require_once 'includes/header.php';
    echo '<div class="container" style="max-width:640px;margin:60px auto;padding:0 16px;">';
    if (!empty($confirm_error)) {
        echo '<div class="alert alert-error">' . htmlspecialchars($confirm_error) . '</div>';
    } else {
        echo '<div class="alert alert-success">' . htmlspecialchars($confirm_success) . '</div>';
    }
    echo '<p><a class="btn btn-primary" href="' . SITE_URL . '/index.php">Back to the homepage</a></p></div>';
    require_once 'includes/footer.php';
    exit;
}

// ===== REDIRECT IF ALREADY LOGGED IN =====
// F163: only plain visits are redirected. A subscription POST (the footer form) is handled
// for members too; it used to be redirected away before it was processed.
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . SITE_URL . '/library.php');
    }
    exit;
}

$error = '';
$success = '';

// ===== HANDLE SUBSCRIPTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // F176: double opt-in; the address is activated from the emailed link (?confirm= handler above).
        $optin = newsletter_request_optin($db, $email, $name);
        if ($optin['ok']) {
            $success = $optin['message'];
        } else {
            $error = $optin['message'];
        }
    }
}

// ===== DETERMINE WHERE TO REDIRECT =====
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : '/index.php';

// Store message in session to display after redirect
session_start();
if ($error) {
    $_SESSION['newsletter_error'] = $error;
} elseif ($success) {
    $_SESSION['newsletter_success'] = $success;
}

// Redirect back to the page
header('Location: ' . SITE_URL . $redirect);
exit;