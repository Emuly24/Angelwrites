<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // Make sure your mail helper is here

// If already logged in, redirect to appropriate page
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . SITE_URL . '/dashboard.php');
    }
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login_submit'])) {
        $login = trim($_POST['login']);
        $password = $_POST['password'];

        if (empty($login) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $stmt = $db->prepare("SELECT id, username, email, password, role, is_verified FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if ($user['is_verified'] == 0) {
                    $error = 'Please verify your email address before logging in. <a href="#" onclick="document.getElementById(\'resend-form\').submit(); return false;">Resend verification email</a>';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    $stmt = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$user['id']]);

                    if ($user['role'] === 'admin') {
                        header('Location: ' . SITE_URL . '/admin/dashboard.php');
                    } else {
                        header('Location: ' . SITE_URL . '/dashboard.php');
                    }
                    exit;
                }
            } else {
                $error = 'Invalid username/email or password.';
            }
        }
    }

    // Handle Resend Verification Email
    if (isset($_POST['resend_verification'])) {
        $login = trim($_POST['resend_login']);
        
        $stmt = $db->prepare("SELECT id, username, email, is_verified FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['is_verified'] == 0) {
            // Generate a new verification token
            $verification_token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
            $stmt->execute([$verification_token, $user['id']]);

            // Send new verification email
            $verify_link = SITE_URL . '/verify.php?token=' . $verification_token;
            $subject = "Verify your AngelWrites account";
            $message = "Hello " . $user['username'] . ",\n\nPlease click the link below to verify your email address:\n\n$verify_link\n\nIf you did not create an account, please ignore this email.";
            
            // Use your mail helper
            $emailSent = sendEmail($user['email'], $subject, $message, 'no-reply@angelwrites.gt.tc', 'AngelWrites');

            if ($emailSent) {
                $success = 'A new verification link has been sent to your email address. Please check your inbox.';
            } else {
                $error = 'Failed to send verification email. Please try again later.';
            }
        } else {
            $error = 'No unverified account found with that email/username.';
        }
    }
}

$pageTitle = 'Sign In';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-header">
                    <h1>Welcome Back</h1>
                    <p>Sign in to access your books, poems, and community.</p>
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
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">
                    <div class="form-group">
                        <label for="login">Username or Email</label>
                        <input type="text" id="login" name="login" placeholder="Enter your username or email" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-group-wrapper">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            <span class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <a href="<?php echo SITE_URL; ?>/forgot_password.php" style="font-size: 0.9rem; color: var(--rose);">Forgot password?</a>
                    </div>

                    <button type="submit" name="login_submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </button>
                </form>

                <!-- Hidden form to handle resend verification -->
                <form id="resend-form" method="POST" style="display: none;">
                    <input type="hidden" name="resend_verification" value="1">
                    <input type="hidden" name="resend_login" id="resend-login-input" value="">
                </form>

                <!-- ===== SOCIAL LOGIN BUTTONS ===== -->
                <div class="social-login-section">
                    <p>Or continue with:</p>
                    <a href="<?php echo SITE_URL; ?>/social_login.php?provider=Google" class="btn btn-google">
                        <i class="fab fa-google"></i> Google
                    </a>
                    <a href="<?php echo SITE_URL; ?>/social_login.php?provider=Facebook" class="btn btn-facebook">
                        <i class="fab fa-facebook-f"></i> Facebook
                    </a>
                </div>

                <div class="auth-footer">
                    <p>Don't have an account? <a href="<?php echo SITE_URL; ?>/register.php">Sign up here</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // Resend verification: fill the hidden form with the current login input
    const resendForm = document.getElementById('resend-form');
    const resendInput = document.getElementById('resend-login-input');
    const loginInput = document.getElementById('login');
    
    // When a user clicks the "Resend verification email" link in the error message
    // we pre-fill the hidden form with whatever they typed in the login field
    document.querySelector('.alert-error a')?.addEventListener('click', function() {
        if (loginInput) {
            resendInput.value = loginInput.value;
            resendForm.submit();
        }
    });
});
</script>

<style>
/* ... your existing styles ... */
</style>

<?php require_once 'includes/footer.php'; ?>