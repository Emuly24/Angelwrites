<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$email = 'admin@angelawrites.com';
$new_password = 'angel@2026';

// Hash the new password
$hashed = password_hash($new_password, PASSWORD_DEFAULT);

// Update or insert admin user
$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$hashed, $email]);
    echo "Password updated for $email to '$new_password'";
} else {
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES ('Admin', ?, ?, 'admin')");
    $stmt->execute([$email, $hashed]);
    echo "Admin account created for $email with password '$new_password'";
}
?>