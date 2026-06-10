<?php
// resend_verification.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
        $stmt = $db->prepare("SELECT id, username, is_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] == 1) {
            $error = 'This account is already verified. You can log in now.';
        } else {
            // Generate a new verification token
            $verification_token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
            $stmt->execute([$verification_token, $user['id']]);

            // Send the email
            $verify_link = SITE_URL . '/verify.php?token=' . $verification_token;
            $subject = "Verify your AngelWrites account";
            $body = "Hello " . $user['username'] . ",\n\nPlease click the link below to verify your email address:\n\n$verify_link\n\nIf you did not create an account, please ignore this email.";

            try {
                sendEmail($email, $subject, $body, 'no-reply@angelwrites.gt.tc', 'AngelWrites');
                $message = "A new verification link has been sent to your email address. Please check your inbox and spam folder.";
            } catch (Exception $e) {
                $error = "We were unable to send the verification email. Please contact support.";
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
                    <h1>Resend Verification</h1>
                    <p>Enter the email address you used to sign up.</p>
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
                        <i class="fas fa-paper-plane"></i> Send Verification Link
                    </button>
                </form>
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
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
</style>

<?php require_once 'includes/footer.php'; ?>