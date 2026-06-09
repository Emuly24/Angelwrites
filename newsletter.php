<?php
// ===== LOAD CONFIGURATION FIRST =====
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // Now safely loaded after config

// ===== REDIRECT IF ALREADY LOGGED IN =====
if (isLoggedIn()) {
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
        // Check if email already exists
        $stmt = $db->prepare("SELECT id, is_active, unsubscribe_token FROM newsletter WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['is_active'] == 1) {
                $error = 'This email is already subscribed.';
            } else {
                // Reactivate
                $stmt = $db->prepare("UPDATE newsletter SET is_active = 1, unsubscribed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$existing['id']]);
                $success = 'Your subscription has been reactivated. Welcome back!';
            }
        } else {
            // Generate unique unsubscribe token
            $token = bin2hex(random_bytes(32));

            // Insert new subscriber
            $stmt = $db->prepare("INSERT INTO newsletter (email, name, is_active, unsubscribe_token) VALUES (?, ?, 1, ?)");
            if ($stmt->execute([$email, $name, $token])) {
                $success = 'Thank you for subscribing! You will receive updates from Angella.';
            } else {
                $error = 'Something went wrong. Please try again.';
            }
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