<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/mail_helper.php';

$message = '';
$error = '';

// ===== RATE LIMITING =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'resend_attempts_' . $ip;
$attempts_file = sys_get_temp_dir() . '/' . $limit_key . '.tmp';
if (file_exists($attempts_file) && (time() - filemtime($attempts_file) < 300)) {
    $error = 'Please wait 5 minutes before requesting another code.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare("SELECT id, first_name, is_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] == 1) {
            $error = 'This account is already verified. You can log in now.';
        } else {
            // Generate new 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $stmt = $db->prepare("UPDATE users SET verification_code = ?, verification_code_expiry = ? WHERE id = ?");
            $stmt->execute([$code, $expiry, $user['id']]);

            $subject = "Your AngelWrites verification code";
            $body = "Hello " . $user['first_name'] . ",\n\nYour new verification code is: $code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.";

            if (sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites')) {
                $message = "A new verification code has been sent to your email address. Please check your inbox and spam folder.";
                // Set rate limit
                file_put_contents($attempts_file, time());
                
                // ===== SEND ADMIN NOTIFICATION =====
                $admin_email = 'angelwrites@zohomail.com';
                $admin_subject = '🔄 Verification Code Resent';
                $admin_body = "A user requested a new verification code.\n\nEmail: $email\nName: " . $user['first_name'];
                sendEmail($admin_email, $admin_subject, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites');
            } else {
                $error = "Failed to send verification code. Please try again later.";
            }
        }
    }
}

$pageTitle = 'Resend Verification';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-header">
                    <h1>Resend Verification Code</h1>
                    <p>Enter your email address and we'll send you a new 6-digit verification code.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <div class="success-actions" style="text-align: center; margin-top: 16px;">
                        <a href="<?php echo SITE_URL; ?>/verify.php" class="btn btn-primary">Go to Verification</a>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Resend Code
                    </button>
                </form>

                <div class="auth-footer">
                    <p><a href="<?php echo SITE_URL; ?>/login.php">Back to Login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.auth-page { padding: 40px 0; }
.auth-wrapper { display: flex; justify-content: center; }
.auth-card { max-width: 420px; width: 100%; background: var(--card-bg); border-radius: 16px; padding: 32px; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }
.auth-header { text-align: center; margin-bottom: 24px; }
.auth-header h1 { font-size: 1.8rem; margin: 0 0 4px; }
.auth-header p { color: var(--text-light); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }
.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }
.success-actions .btn { padding: 10px 24px; border-radius: 30px; }
</style>

<?php require_once 'includes/footer.php'; ?>