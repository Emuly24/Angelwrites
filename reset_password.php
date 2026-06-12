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

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$error = '';
$success = '';
$step = 1; // 1 = request code, 2 = verify and reset

// ===== RATE LIMITING =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'reset_password_attempts_' . $ip;
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
    $error = 'Too many failed reset attempts. Please wait 15 minutes and try again.';
}

// ===== STEP 1: REQUEST RESET CODE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset']) && !$error) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare("SELECT id, first_name, is_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] != 1) {
            $error = 'Please verify your email address before resetting your password. <a href="verify.php?email=' . urlencode($email) . '">Verify now</a>.';
        } else {
            // Generate 6-digit reset code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $stmt = $db->prepare("UPDATE users SET reset_code = ?, reset_code_expiry = ? WHERE id = ?");
            $stmt->execute([$code, $expiry, $user['id']]);

            // Extract first name for email greeting
            $rawName = trim($user['first_name'] ?? 'there');
            $nameParts = preg_split('/[\s_\-]+/', $rawName);
            $greetingName = ucfirst($nameParts[0] ?? 'there');
            if (count($nameParts) === 1 && strlen($greetingName) > 12) {
                $camelParts = preg_split('/(?=[A-Z])/', $greetingName);
                if (count($camelParts) > 1) {
                    $greetingName = ucfirst($camelParts[0]);
                }
            }

            $subject = "Reset your AngelWrites password";
            $body = "Hello $greetingName,\n\nYour password reset code is: $code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.\n\n— AngelWrites Team";

            if (sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites')) {
                $step = 2;
                $success = 'A reset code has been sent to your email address.';
            } else {
                $error = 'Failed to send reset code. Please try again later.';
            }
        }
    }
}

// ===== STEP 2: VERIFY CODE AND RESET PASSWORD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && !$error) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($email) || empty($code) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $db->prepare("SELECT id, reset_code, reset_code_expiry, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
        } elseif ($user['reset_code'] !== $code) {
            $error = 'Invalid reset code. Please try again.';
            $attempts++;
            $last_attempt = time();
            file_put_contents($attempts_file, $attempts . '|' . $last_attempt);
        } elseif (strtotime($user['reset_code_expiry']) < time()) {
            $error = 'Reset code has expired. Please request a new code.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_code_expiry = NULL WHERE id = ?");
            $stmt->execute([$hashed, $user['id']]);
            $success = 'Password reset successfully! You can now <a href="login.php">log in</a>.';
            
            // Reset attempts on success
            if (file_exists($attempts_file)) {
                unlink($attempts_file);
            }
            
            // ===== SEND ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $subject = '🔐 Password Reset Completed';
            $body = "<h2>Password Reset Completed</h2>";
            $body .= "<p><strong>Email:</strong> $email</p>";
            $body .= "<p><strong>Name:</strong> " . $user['name'] . "</p>";
            $body .= "<p><a href='" . SITE_URL . "/admin/manage_users.php'>View user in admin panel</a></p>";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
        }
    }
}

$pageTitle = 'Reset Password';
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
                    <h1>Reset Password</h1>
                    <p><?php echo $step === 1 ? 'Enter your email to receive a reset code.' : 'Enter the 6-digit code and set a new password.'; ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <form method="POST" action="" class="auth-form" id="requestForm">
                        <input type="hidden" name="request_reset" value="1">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required autofocus>
                            <div id="emailStatus" class="field-status"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send Reset Code
                        </button>
                    </form>
                    <div class="auth-footer">
                        <p><a href="<?php echo SITE_URL; ?>/login.php">Back to Login</a></p>
                    </div>
                <?php elseif ($step === 2): ?>
                    <form method="POST" action="" class="auth-form" id="resetForm">
                        <input type="hidden" name="reset_password" value="1">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="code">Reset Code</label>
                            <input type="text" id="code" name="code" placeholder="Enter 6-digit code" pattern="[0-9]{6}" maxlength="6" required>
                            <div id="codeStatus" class="field-status"></div>
                        </div>
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" placeholder="At least 8 characters" required>
                                <span class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <div class="password-strength-meter">
                                <div class="strength-bar" id="strengthBar"></div>
                                <span id="strengthText">Strength: None</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                            <div id="passwordMatchStatus" class="field-status"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-save"></i> Reset Password
                        </button>
                    </form>
                    <div class="auth-footer">
                        <p><a href="<?php echo SITE_URL; ?>/forgot_password.php">Request a new code</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('resetTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('resetTheme', isDark ? 'dark' : 'light');
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

    // ===== PASSWORD STRENGTH METER =====
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');

    function checkPasswordStrength(password) {
        let strength = 0;
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]/)) strength++;
        if (password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        if (password.match(/[^a-zA-Z0-9]/)) strength++;

        const levels = ['None', 'Weak', 'Fair', 'Good', 'Strong'];
        const colors = ['#ddd', '#e74c3c', '#f39c12', '#3498db', '#2ecc71'];
        const widths = ['0%', '20%', '40%', '60%', '100%'];

        strengthBar.style.width = widths[strength];
        strengthBar.style.background = colors[strength];
        strengthText.textContent = 'Strength: ' + levels[strength];
    }

    passwordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });

    // ===== PASSWORD MATCH =====
    const confirmInput = document.getElementById('confirm_password');
    const matchStatus = document.getElementById('passwordMatchStatus');

    function checkPasswordMatch() {
        const pass = passwordInput.value;
        const confirm = confirmInput.value;
        if (confirm.length === 0) {
            matchStatus.textContent = '';
            matchStatus.className = '';
        } else if (pass === confirm) {
            matchStatus.textContent = '✅ Passwords match';
            matchStatus.className = 'field-status success';
        } else {
            matchStatus.textContent = '❌ Passwords do not match';
            matchStatus.className = 'field-status error';
        }
    }

    confirmInput.addEventListener('input', checkPasswordMatch);

    // ===== CODE INPUT VALIDATION =====
    const codeInput = document.getElementById('code');
    const codeStatus = document.getElementById('codeStatus');

    codeInput.addEventListener('input', function() {
        const code = this.value.trim();
        if (code.length === 6 && /^\d{6}$/.test(code)) {
            codeStatus.textContent = '✅ Valid format';
            codeStatus.className = 'field-status success';
        } else if (code.length > 0) {
            codeStatus.textContent = '❌ Must be 6 digits';
            codeStatus.className = 'field-status error';
        } else {
            codeStatus.textContent = '';
            codeStatus.className = '';
        }
    });

    // ===== AJAX EMAIL VALIDATION =====
    const emailInput = document.getElementById('email');
    const emailStatus = document.getElementById('emailStatus');
    let emailTimer;

    emailInput.addEventListener('input', function() {
        clearTimeout(emailTimer);
        const email = this.value.trim();
        if (email.length > 0 && email.includes('@')) {
            emailTimer = setTimeout(() => {
                fetch('<?php echo SITE_URL; ?>/check_email.php?email=' + encodeURIComponent(email))
                    .then(r => r.json())
                    .then(data => {
                        if (data.available) {
                            emailStatus.textContent = '⚠️ Email not registered';
                            emailStatus.className = 'field-status info';
                        } else {
                            emailStatus.textContent = '✅ Email found';
                            emailStatus.className = 'field-status success';
                        }
                    });
            }, 500);
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

.auth-page { padding: 32px 0 60px; }
.auth-wrapper { display: flex; justify-content: center; }
.auth-card { max-width: 420px; width: 100%; background: var(--card-bg); border-radius: 16px; padding: 32px; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }

.auth-header { text-align: center; margin-bottom: 24px; }
.auth-header h1 { font-size: 1.8rem; margin: 0 0 4px; }
.auth-header p { color: var(--text-light); }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-group input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }

.field-status { font-size: 0.8rem; margin-top: 4px; }
.field-status.success { color: #27ae60; }
.field-status.error { color: #e74c3c; }
.field-status.info { color: #3498db; }

.password-wrapper { position: relative; }
.password-wrapper input { padding-right: 40px; }
.password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666; }
.password-toggle:hover { color: #333; }

.password-strength-meter { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.strength-bar { height: 4px; width: 0%; background: #ddd; border-radius: 2px; transition: width 0.3s; }
#strengthText { font-size: 0.8rem; color: var(--text-light); }

.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }

.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }

@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>