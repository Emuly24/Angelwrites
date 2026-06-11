<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$error = '';
$success = '';

// ===== RATE LIMITING =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'verify_attempts_' . $ip;
$attempts_file = sys_get_temp_dir() . '/' . $limit_key . '.tmp';
$attempts = 0;
$last_attempt = 0;

if (file_exists($attempts_file)) {
    $data = file_get_contents($attempts_file);
    if ($data) {
        list($attempts, $last_attempt) = explode('|', $data);
        $attempts = (int)$attempts;
        $last_attempt = (int)$last_attempt;
    }
}

// If more than 5 attempts in 15 minutes, block
if ($attempts >= 5 && (time() - $last_attempt < 900)) {
    $error = 'Too many failed verification attempts. Please wait 15 minutes and try again.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);

    if (empty($email) || empty($code)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $db->prepare("SELECT id, verification_code, verification_code_expiry, is_verified, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] == 1) {
            $success = 'Your account is already verified. You can <a href="login.php">log in</a>.';
        } elseif ($user['verification_code'] !== $code) {
            $error = 'Invalid verification code. Please try again.';
            // Increment failed attempts
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
        } elseif (strtotime($user['verification_code_expiry']) < time()) {
            $error = 'Verification code has expired. Please request a new code.';
        } else {
            // Mark account as verified
            $stmt = $db->prepare("UPDATE users SET is_verified = 1, verification_code = NULL, verification_code_expiry = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            $success = 'Account verified successfully! You can now <a href="login.php">log in</a>.';
            
            // Reset attempts on success
            if (file_exists($attempts_file)) {
                unlink($attempts_file);
            }
            
            // ===== SEND ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $admin_subject = '✅ Account Verified';
            $admin_body = "A user has verified their account.\n\nEmail: $email\nName: " . $user['name'];
            sendEmail($admin_email, $admin_subject, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites');
        }
    }
}

$pageTitle = 'Verify Account';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-header">
                    <h1>Verify Your Account</h1>
                    <p>Enter the 6-digit code sent to your email address.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required>
                    </div>
                    <div class="form-group">
                        <label for="code">Verification Code</label>
                        <input type="text" id="code" name="code" placeholder="Enter 6-digit code" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Verify Account</button>
                </form>

                <div class="auth-footer">
                    <p>Didn't receive a code? <a href="resend_verification.php">Resend code</a></p>
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
</style>

<?php require_once 'includes/footer.php'; ?>