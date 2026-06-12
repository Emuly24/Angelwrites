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
        header('Location: ' . SITE_URL . '/library.php');
    }
    exit;
}

$error = '';
$success = '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';

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

if ($attempts >= 5 && (time() - $last_attempt < 900)) {
    $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
}

// ===== HANDLE LOGIN SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login']) && !$error) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with this email address.';
            // Increment failed attempts
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Incorrect password. Please try again.';
            // Increment failed attempts
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
        } elseif ($user['is_verified'] != 1) {
            $error = 'Please verify your email address before logging in. <a href="resend_verification.php?email=' . urlencode($email) . '">Resend verification code</a>.';
        } else {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            
            // Reset attempts on success
            if (file_exists($attempts_file)) {
                unlink($attempts_file);
            }
            
            // Set remember me cookie (30 days)
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $expiry = time() + (30 * 24 * 60 * 60);
                setcookie('remember_token', $token, $expiry, '/', '', true, true);
                setcookie('remember_user', $user['id'], $expiry, '/', '', true, true);
                // Store token in database
                $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
            }
            
            // Redirect based on role
            if ($user['role'] === 'admin') {
                header('Location: ' . SITE_URL . '/admin/dashboard.php');
            } else {
                header('Location: ' . SITE_URL . '/library.php');
            }
            exit;
        }
    }
}

$pageTitle = 'Login';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-header">
                    <h1>Welcome Back</h1>
                    <p>Sign in to continue your journey with AngelWrites.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form" id="loginForm">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            <span class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <div class="form-options">
                            <a href="<?php echo SITE_URL; ?>/forgot_password.php" class="forgot-link">Forgot password?</a>
                        </div>
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="remember" id="remember">
                        <label for="remember">Remember me</label>
                    </div>

                    <button type="submit" name="login" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Sign In
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

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('loginTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('loginTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== TOGGLE PASSWORD VISIBILITY =====
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    // ===== AJAX EMAIL VALIDATION ON BLUR =====
    const emailInput = document.getElementById('email');
    emailInput.addEventListener('blur', function() {
        const email = this.value.trim();
        if (email.length > 0 && email.includes('@')) {
            fetch('<?php echo SITE_URL; ?>/check_email.php?email=' . encodeURIComponent(email))
                .then(r => r.json())
                .then(data => {
                    if (data.available) {
                        this.style.borderColor = '#f39c12';
                    } else {
                        this.style.borderColor = '#2ecc71';
                    }
                });
        }
    });

    // ===== FORM VALIDATION =====
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;
        if (email.length === 0 || password.length === 0) {
            e.preventDefault();
            alert('Please fill in all fields.');
        }
    });
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

/* ===== AUTH PAGE ===== */
.auth-page { padding: 32px 0 60px; }
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

.password-wrapper { position: relative; }
.password-wrapper input { padding-right: 40px; }
.password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666; }
.password-toggle:hover { color: #333; }

.form-options { text-align: right; margin-top: 4px; }
.forgot-link { font-size: 0.85rem; color: var(--text-light); text-decoration: none; }
.forgot-link:hover { color: var(--rose); }

.checkbox-group { display: flex; align-items: center; gap: 8px; }
.checkbox-group input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--rose); }

/* ===== BUTTONS ===== */
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

/* ===== SOCIAL LOGIN ===== */
.social-login-section { text-align: center; margin: 20px 0; }
.btn-google { background: #DB4437; color: white; display: block; text-align: center; padding: 10px; border-radius: 8px; text-decoration: none; margin: 8px 0; }
.btn-google:hover { background: #c23321; }

/* ===== FOOTER ===== */
.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }

/* ===== ALERT ===== */
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }

@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>