<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== CAPTURE REDIRECT URL (if present) =====
$redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : '';

// ===== REDIRECT IF ALREADY LOGGED IN =====
if (isLoggedIn()) {
    // If already logged in, obey the redirect or default dashboard
    if (!empty($redirect) && strpos($redirect, SITE_URL) === 0) {
        header('Location: ' . $redirect);
    } elseif (isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . SITE_URL . '/dashboard.php');
    }
    exit;
}

$error = '';
$success = '';
$login_value = isset($_POST['login']) ? trim($_POST['login']) : '';

// ===== RATE LIMITING (FILE-BASED) =====
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

// Block if more than 5 attempts in 15 minutes
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
            // ===== BYPASS VERIFICATION FOR ADMIN & TEST USER =====
            $is_exempt = ($user['username'] === 'admin' || $user['username'] === 'Test User');
            if (!$is_exempt && $user['is_verified'] == 0) {
                $error = 'Please verify your email address before logging in. <a href="resend_verification.php">Resend verification email</a>';
            }

            // If error is set, stop here
            if (!$error) {
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
                    // Create remember_tokens table if not exists
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
                        // Table already exists – continue
                    }

                    // Generate secure token
                    $token = bin2hex(random_bytes(32));
                    $expires = time() + (30 * 24 * 60 * 60); // 30 days
                    $expiry_date = date('Y-m-d H:i:s', $expires);

                    $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['id'], $token, $expiry_date]);

                    // Set secure HTTP-only cookie
                    setcookie('remember_token', $token, $expires, '/', '', false, true);
                }

                // Update last login timestamp
                $stmt = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);

                // ===== 🚀 REDIRECT LOGIC =====
                // If a valid redirect URL was passed, send them there; otherwise go to dashboard
                if (!empty($redirect) && strpos($redirect, SITE_URL) === 0) {
                    header('Location: ' . $redirect);
                } elseif ($user['role'] === 'admin') {
                    header('Location: ' . SITE_URL . '/admin/dashboard.php');
                } else {
                    header('Location: ' . SITE_URL . '/dashboard.php');
                }
                exit;
            }
        } else {
            // Failed login
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

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

                <form method="POST" action="" class="auth-form" id="loginForm">
                    <div class="form-group">
                        <label for="login">Username or Email</label>
                        <input type="text" id="login" name="login" value="<?php echo htmlspecialchars($login_value); ?>" placeholder="Enter your username or email" required autofocus>
                        <div id="loginStatus" class="field-status"></div>
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

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== PASSWORD TOGGLE =====
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    // ===== AJAX LOGIN FIELD VALIDATION (EXISTENCE CHECK) =====
    const loginInput = document.getElementById('login');
    const loginStatus = document.getElementById('loginStatus');
    let loginTimer;

    loginInput.addEventListener('input', function() {
        clearTimeout(loginTimer);
        const value = this.value.trim();
        if (value.length < 3) {
            loginStatus.textContent = '';
            loginStatus.className = '';
            return;
        }
        loginTimer = setTimeout(() => {
            fetch('<?php echo SITE_URL; ?>/check_login_exists.php?q=' + encodeURIComponent(value))
                .then(r => r.json())
                .then(data => {
                    if (data.exists) {
                        loginStatus.textContent = '✅ Account found';
                        loginStatus.className = 'field-status success';
                    } else {
                        loginStatus.textContent = '⚠️ No account found with this username/email';
                        loginStatus.className = 'field-status info';
                    }
                })
                .catch(() => {
                    loginStatus.textContent = '';
                    loginStatus.className = '';
                });
        }, 500);
    });

    // ===== FORM VALIDATION =====
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const login = document.getElementById('login').value.trim();
        const password = document.getElementById('password').value;
        if (login.length === 0 || password.length === 0) {
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

.field-status { font-size: 0.8rem; margin-top: 4px; }
.field-status.success { color: #27ae60; }
.field-status.error { color: #e74c3c; }
.field-status.info { color: #3498db; }

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
.checkbox-group input[type="checkbox"] { width: auto; margin: 0; cursor: pointer; accent-color: var(--rose); }
.checkbox-group label { font-size: 0.95rem; color: var(--text); cursor: pointer; margin: 0; }

/* ===== BUTTON ===== */
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

/* ===== SOCIAL LOGIN ===== */
.social-login-section { text-align: center; margin: 20px 0; }
.social-login-section .btn { display: inline-block; margin: 4px; padding: 10px 20px; border-radius: 6px; color: white; text-decoration: none; font-size: 0.95rem; }
.btn-google { background: #DB4437; }
.btn-google:hover { background: #c23321; }

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