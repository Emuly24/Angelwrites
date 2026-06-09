<?php
require_once 'includes/mail_helper.php';

// Then call it like this:
if (sendEmail($to, $subject, $message)) {
    echo "Email sent!";
} else {
    echo "Email failed.";
}
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    die('Invalid reset token.');
}

$stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > CURRENT_TIMESTAMP");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    die('Invalid or expired reset token.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
        $stmt->execute([$hashed, $user['id']]);
        $success = true;
    }
}
?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-header">
                    <h1>Reset Password</h1>
                    <p>Enter your new password below.</p>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        Your password has been reset successfully.
                    </div>
                <?php endif; ?>
                <form method="POST" action="" class="auth-form">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your new password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your new password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </form>
            </div>
        </div>
    </div>
</div>