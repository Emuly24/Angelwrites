<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== REDIRECT IF ALREADY LOGGED IN =====
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

// ===== RATE LIMITING =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'login_attempts_' . $ip;
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
    $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
}

// ===== HANDLE LOGIN FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit']) && !$error) {
    $login = trim($_POST['login']);
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;

    if (empty($login) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $db->prepare("SELECT id, username, email, password, role, is_verified, name FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_verified'] == 0) {
                $error = 'Please verify your email address before logging in. <a href="resend_verification.php">Resend verification email</a>';
            } else {
                // Reset login attempts on success
                if (file_exists($attempts_file)) {
                    unlink($attempts_file);
                }

                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];

                // ===== REMEMBER ME LOGIC =====
                if ($remember_me) {
                    // Generate a secure token
                    $token = bin2hex(random_bytes(32));
                    $expires = time() + (30 * 24 * 60 * 60); // 30 days

                    // Store token in database (create remember_tokens table if not exists)
                    try {
                        $db->exec("
                            CREATE TABLE IF NOT EXISTS remember_tokens (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                user_id INTEGER NOT NULL,
                                token TEXT NOT NULL UNIQUE,
                                expires_at DATETIME NOT NULL,
                                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                            )
                        ");
                    } catch (Exception $e) {
                        // Table already exists or other error — continue
                    }

                    // Insert the token
                    $expiry_date = date('Y-m-d H:i:s', $expires);
                    $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['id'], $token, $expiry_date]);

                    // Set the cookie
                    setcookie('remember_token', $token, $expires, '/', '', false, true);
                }

                // Update last login timestamp
                $stmt = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);

                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header('Location: ' . SITE_URL . '/admin/dashboard.php');
                } else {
                    header('Location: ' . SITE_URL . '/dashboard.php');
                }
                exit;
            }
        } else {
            // Increment failed attempts
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
            
            $error = 'Invalid username/email or password.';
            
            // ===== SEND ADMIN NOTIFICATION FOR SUSPICIOUS ACTIVITY =====
            if ($attempts >= 3) {
                $admin_email = 'angelwrites@zohomail.com';
                $admin_subject = '⚠️ Multiple Failed Login Attempts';
                $admin_body = "There have been $attempts failed login attempts for IP: $ip\n\nLogin attempted: $login\n\nTime: " . date('Y-m-d H:i:s');
                sendEmail($admin_email, $admin_subject, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
            }
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

                    <div class="checkbox-group">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <label for="remember_me">Remember me</label>
                    </div>

                    <div class="form-group">
                        <a href="<?php echo SITE_URL; ?>/forgot_password.php" style="font-size: 0.9rem; color: var(--rose);">Forgot password?</a>
                    </div>

                    <button type="submit" name="login_submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </button>
                </form>

                <!-- ===== SOCIAL LOGIN BUTTONS ===== -->
                <div class="social-login-section">
                    <p>Or continue with:</p>
                    <a href="<?php echo SITE_URL; ?>/social_login.php?provider=Google" class="btn btn-google">
                        <i class="fab fa-google"></i> Google
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
    // ===== PASSWORD TOGGLE =====
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }
});
</script>

<style>
/* ===== AUTH PAGE ===== */
.auth-page { padding: 40px 0; }
.auth-wrapper { display: flex; justify-content: center; }
.auth-card { max-width: 420px; width: 100%; background: var(--card-bg); border-radius: 16px; padding: 32px; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }
.auth-header { text-align: center; margin-bottom: 24px; }
.auth-header h1 { font-size: 1.8rem; margin: 0 0 4px; }
.auth-header p { color: var(--text-light); }

/* ===== FORM ===== */
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }

/* ===== PASSWORD TOGGLE ===== */
.input-group-wrapper { position: relative; }
.input-group-wrapper input { padding-right: 40px; }
.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: var(--text-light);
    z-index: 10;
    transition: color 0.2s;
    background: transparent;
    padding: 4px;
}
.password-toggle:hover { color: var(--text); }

/* ===== CHECKBOX ===== */
.checkbox-group { display: flex; align-items: center; gap: 8px; margin: 8px 0 16px; }
.checkbox-group input[type="checkbox"] { width: auto; margin: 0; cursor: pointer; }
.checkbox-group label { font-size: 0.95rem; color: var(--text); cursor: pointer; margin: 0; }

/* ===== BUTTON ===== */
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

/* ===== SOCIAL LOGIN ===== */
.social-login-section { text-align: center; margin: 20px 0; }
.social-login-section .btn { display: inline-block; margin: 4px; padding: 10px 20px; border-radius: 6px; color: white; text-decoration: none; font-size: 0.95rem; }
.btn-google { background: #DB4437; }
.btn-facebook { background: #1877F2; }
.btn-google:hover { background: #c23321; }
.btn-facebook:hover { background: #1559c4; }

/* ===== FOOTER ===== */
.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }

/* ===== ALERTS ===== */
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.alert-error a { color: #991b1b; font-weight: 600; text-decoration: underline; }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>