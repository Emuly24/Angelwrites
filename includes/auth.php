<?php
/**
 * Authentication & Authorization Functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the logged-in user is an admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Redirect to login if not logged in
 */
function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

/**
 * Redirect to dashboard if user is not an admin
 */
function redirectIfNotAdmin() {
    redirectIfNotLoggedIn();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

/**
 * Get the current user's ID (returns 0 if not logged in)
 */
function getUserId() {
    return $_SESSION['user_id'] ?? 0;
}

/**
 * Get the current user's name (returns 'Guest' if not logged in)
 */
function getUserName() {
    return $_SESSION['name'] ?? 'Guest';
}

/**
 * Get the current user's role (returns 'guest' if not logged in)
 */
function getUserRole() {
    return $_SESSION['role'] ?? 'guest';
}
?>