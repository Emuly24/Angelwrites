<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/mail_helper.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

            // ===== FIX: Extract first word from first_name =====
            $rawName = trim($user['first_name'] ?? 'there');
            // Split on space, dash, or underscore, or capitalize transition
            $nameParts = preg_split('/[\s_\-]+/', $rawName);
            $greetingName = ucfirst($nameParts[0] ?? 'there');
            
            // If the first name is a compound like "Blessingsemulyn", we can also split on capital letters
            // as a fallback for cases without separators:
            if (count($nameParts) === 1 && strlen($greetingName) > 12) {
                // Split on capital letters (e.g., Blessingsemulyn -> Blessings emulyn)
                $camelParts = preg_split('/(?=[A-Z])/', $greetingName);
                if (count($camelParts) > 1) {
                    $greetingName = ucfirst($camelParts[0]);
                }
            }

            $subject = "Your AngelWrites verification code";
            $body = "Hello $greetingName,\n\nYour new verification code is: $code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.\n\n— AngelWrites Team";

            if (sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites')) {
                $message = "A new verification code has been sent to your email address. Please check your inbox and spam folder.";
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
                    <p>Enter your email address and we will send you a new 6-digit code.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
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
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
@media (max-width: 480px) { .auth-card { padding: 20px; } }
</style>

<?php require_once 'includes/footer.php'; ?>