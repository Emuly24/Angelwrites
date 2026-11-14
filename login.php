<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== CAPTURE REDIRECT URL (if present) =====
$redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : '';
// F38: accept a same-site path ("/download.php?book_id=1", as download.php sends) as well as
// an absolute SITE_URL link. The old prefix test ignored relative paths (members landed on
// the dashboard) and also accepted look-alike hosts such as SITE_URL . '.evil.example'.
$redirect_target = '';
if ($redirect !== '' && !preg_match('/[\\\x00-\x1f\x7f]/', $redirect)) {
    if ($redirect[0] === '/' && substr($redirect, 0, 2) !== '//') {
        $redirect_target = SITE_URL . $redirect;
    } elseif (strpos($redirect, SITE_URL . '/') === 0) {
        $redirect_target = $redirect;
    }
}

// ===== REDIRECT IF ALREADY LOGGED IN =====
if (isLoggedIn()) {
    // If already logged in, obey the redirect or default dashboard
    if ($redirect_target !== '') {
        header('Location: ' . $redirect_target);
    } elseif (isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . SITE_URL . '/dashboard.php');
    }
    exit;
}

$error = '';
// F102: message from social_login.php when Google sign-in is off or failed
if (isset($_GET['social']) && $_GET['social'] === 'unavailable') {
    $error = 'Google sign-in is not available right now. Please log in with your email or username.';
}
$success = '';
$login_value = isset($_POST['login']) ? trim($_POST['login']) : '';

// ===== RATE LIMITING (FILE-BASED) =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'login_attempts_' . $ip;
$attempts_file = aw_ratelimit_file($limit_key) /* Q49 */;
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
        $stmt = $db->prepare("SELECT id, username, email, password, role, is_verified, name, first_name, last_name FROM users WHERE username = ? OR email = ? COLLATE NOCASE");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // S6 (F109): no username is exempt from email verification. (Usernames "admin" and
            // "Test User" used to skip it.)
            // AV1: accounts with the admin role do not need to verify their email. The exemption
            // follows the role stored in the database, never the username, and registration only
            // ever creates 'reader' accounts, so nobody can sign up into it.
            if ($user['is_verified'] == 0 && $user['role'] !== 'admin') {
                // F232: send the member straight to the verify page for their address, where a new code can be requested
                $error = 'Please verify your email address before logging in. <a href="verify.php?email=' . urlencode($user['email']) . '">Enter your code or get a new one</a>';
            }

            // If error is set, stop here
            if (!$error) {
                // Reset login attempts on success
                if (file_exists($attempts_file)) {
                    unlink($attempts_file);
                }

                // K5: staff with two-step sign-in give their code first (login_2fa.php finishes the sign-in)
                require_once __DIR__ . '/includes/twofactor.php';
                if (aw_is_staff_role($user['role']) && aw_2fa_enabled($db, (int)$user['id'])) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['uid' => (int)$user['id'], 'remember' => (bool)$remember_me, 'redirect' => $redirect_target, 'at' => time(), 'tries' => 0];
                    header('Location: ' . SITE_URL . '/login_2fa.php');
                    exit;
                }
                aw_finish_login($db, $user, (bool)$remember_me); // session, "remember me", last login

                // ===== 🚀 REDIRECT LOGIC =====
                // If a valid redirect URL was passed, send them there; otherwise go to dashboard
                if ($redirect_target !== '') {
                    header('Location: ' . $redirect_target);
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
                $admin_email = aw_admin_email();
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
        <!-- Q26: a page-only dark-mode button (its own 'loginTheme' setting, separate from the site theme)
             and a reading progress bar on a one-screen form were left over here; removed. -->
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
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i><span class="pw-toggle-text">Show</span></button><!-- Q27 -->
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
                <?php if (getenv('SOCIAL_LOGIN_ENABLED') === '1'): // F102: off unless enabled (decision D-M3) ?>
                <div class="social-login-section">
                    <p>Or continue with:</p>
                    <a href="<?php echo SITE_URL; ?>/social_login.php?provider=Google" class="btn btn-google">
                        <i class="fab fa-google"></i> Google
                    </a>
                </div>
                <?php endif; ?>

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
    // ===== PASSWORD TOGGLE =====
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
        // Q27: a labelled button with visible text (the icon alone was invisible without the icon font)
        this.setAttribute('aria-pressed', String(type === 'text'));
        this.setAttribute('aria-label', type === 'text' ? 'Hide password' : 'Show password');
        const awT = this.querySelector('.pw-toggle-text'); if (awT) awT.textContent = type === 'text' ? 'Hide' : 'Show';
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
    --rose: #94404a;
    --rose-dark: #94404a;
    --vanilla: #fdf5e6;
    --dark: #1a1212;
    --text-light: #6b5a5a;
    --input-bg: #fdf9f8;
    --card-bg: #ffffff;
    --border: #ece0de;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdf9f8;
}
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --border: #4a3a3a;
    --text-light: #b8a8a8;
    --input-bg: #3d2e2e;
    --vanilla: #2c1e1e;
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
.field-status.success { color: #4f8a63; }
.field-status.error { color: #b4505a; }
.field-status.info { color: #8a6f86; }

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
.alert-error { background: #f6e1e3; color: #94404a; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.alert-error a { color: #94404a; font-weight: 600; text-decoration: underline; }

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>