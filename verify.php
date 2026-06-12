<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// ===== REDIRECT IF ALREADY VERIFIED OR LOGGED IN =====
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT is_verified FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if ($stmt->fetchColumn() == 1) {
        header('Location: ' . SITE_URL . '/library.php');
        exit;
    }
}

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$error = '';
$success = false;
$resend_success = false;

// ===== RATE LIMITING =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'verify_attempts_' . $ip;
$attempts_file = sys_get_temp_dir() . '/' . $limit_key . '.tmp';
$attempts = 0;
if (file_exists($attempts_file)) {
    $attempts = (int)file_get_contents($attempts_file);
}
if ($attempts >= 5) {
    $error = 'Too many verification attempts. Please wait 15 minutes and try again.';
}

// ===== HANDLE VERIFICATION (AJAX or POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');

    if (empty($email) || empty($code)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $db->prepare("SELECT id, verification_code, verification_code_expiry, is_verified, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] == 1) {
            $success = true;
        } elseif ($user['verification_code'] !== $code) {
            $attempts++;
            file_put_contents($attempts_file, $attempts);
            $error = 'Invalid verification code. Please try again.';
        } elseif (strtotime($user['verification_code_expiry']) < time()) {
            $error = 'Verification code has expired. Please request a new code.';
        } else {
            // Mark account as verified
            $stmt = $db->prepare("UPDATE users SET is_verified = 1, verification_code = NULL, verification_code_expiry = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            $success = true;
            
            // Reset attempts on success
            if (file_exists($attempts_file)) {
                unlink($attempts_file);
            }
            
            // ===== SEND ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $subject = '✅ User Verified';
            $body = "<h2>User Verified</h2>";
            $body .= "<p><strong>Email:</strong> $email</p>";
            $body .= "<p><strong>Name:</strong> " . $user['name'] . "</p>";
            $body .= "<p><strong>User ID:</strong> {$user['id']}</p>";
            $body .= "<p><a href='" . SITE_URL . "/admin/manage_users.php'>View user in admin panel</a></p>";
            sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
            
            // Also log the user in automatically
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $email;
            $_SESSION['role'] = 'reader';
        }
    }
}

// ===== HANDLE RESEND CODE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_code'])) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $stmt = $db->prepare("SELECT id, first_name, is_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif ($user['is_verified'] == 1) {
            $success = true;
        } else {
            // Generate new 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $stmt = $db->prepare("UPDATE users SET verification_code = ?, verification_code_expiry = ? WHERE id = ?");
            $stmt->execute([$code, $expiry, $user['id']]);

            // Extract first name
            $rawName = trim($user['first_name'] ?? 'there');
            $nameParts = preg_split('/[\s_\-]+/', $rawName);
            $greetingName = ucfirst($nameParts[0] ?? 'there');
            if (count($nameParts) === 1 && strlen($greetingName) > 12) {
                $camelParts = preg_split('/(?=[A-Z])/', $greetingName);
                if (count($camelParts) > 1) {
                    $greetingName = ucfirst($camelParts[0]);
                }
            }

            $subject = "Your AngelWrites verification code";
            $body = "Hello $greetingName,\n\nYour new verification code is: $code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.\n\n— AngelWrites Team";

            if (sendEmail($email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites')) {
                $resend_success = true;
            } else {
                $error = "Failed to send verification code. Please try again later.";
            }
        }
    }
}

$pageTitle = 'Verify Account';
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
                    <h1>Verify Your Account</h1>
                    <p>Enter the 6-digit code sent to your email address.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($resend_success): ?>
                    <div class="alert alert-success">A new verification code has been sent to your email address.</div>
                <?php endif; ?>

                <?php if (!$success): ?>
                    <form method="POST" action="" class="auth-form" id="verifyForm">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="code">Verification Code</label>
                            <input type="text" id="code" name="code" placeholder="Enter 6-digit code" pattern="[0-9]{6}" maxlength="6" required>
                            <div id="codeStatus" class="field-status"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block" id="verifyBtn">
                            <i class="fas fa-check-circle"></i> Verify Account
                        </button>
                    </form>

                    <div class="auth-footer">
                        <button id="resendBtn" class="btn btn-sm btn-outline" style="margin-top:12px;">
                            <i class="fas fa-paper-plane"></i> Resend Code
                        </button>
                        <span id="resendTimer" style="display:inline-block;margin-left:8px;font-size:0.85rem;color:var(--text-light);"></span>
                        <p style="margin-top:16px;"><a href="<?php echo SITE_URL; ?>/login.php">Back to Login</a></p>
                    </div>
                <?php else: ?>
                    <div class="success-popup">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>Account Verified! 🎉</h2>
                        <p class="success-message">Your account has been successfully verified. You can now log in and start exploring AngelWrites.</p>
                        <div class="success-actions">
                            <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-primary btn-block">
                                <i class="fas fa-sign-in-alt"></i> Log In Now
                            </a>
                        </div>
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
    const currentTheme = localStorage.getItem('verifyTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('verifyTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== AJAX VERIFICATION =====
    const verifyForm = document.getElementById('verifyForm');
    const verifyBtn = document.getElementById('verifyBtn');
    const codeInput = document.getElementById('code');
    const codeStatus = document.getElementById('codeStatus');

    verifyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const email = document.getElementById('email').value.trim();
        const code = codeInput.value.trim();

        if (email.length === 0 || code.length === 0) {
            alert('Please fill in all fields.');
            return;
        }

        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        codeStatus.textContent = '';

        const formData = new FormData();
        formData.append('email', email);
        formData.append('code', code);

        fetch('<?php echo SITE_URL; ?>/verify.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.text())
        .then(html => {
            // Check if the response contains the success popup
            if (html.includes('success-popup')) {
                document.querySelector('.auth-card').innerHTML = new DOMParser()
                    .parseFromString(html, 'text/html')
                    .querySelector('.auth-card').innerHTML;
            } else {
                // Reload the page to show error
                location.reload();
            }
        })
        .catch(() => {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = '<i class="fas fa-check-circle"></i> Verify Account';
            alert('Verification failed. Please try again.');
        });
    });

    // ===== CODE INPUT VALIDATION =====
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

    // ===== RESEND CODE =====
    const resendBtn = document.getElementById('resendBtn');
    const resendTimer = document.getElementById('resendTimer');
    let countdown = 0;
    let timerInterval = null;

    resendBtn.addEventListener('click', function() {
        const email = document.getElementById('email').value.trim();
        if (email.length === 0 || !email.includes('@')) {
            alert('Please enter a valid email address.');
            return;
        }

        // Start countdown timer
        countdown = 60;
        resendBtn.disabled = true;
        updateTimer();

        timerInterval = setInterval(() => {
            countdown--;
            updateTimer();
            if (countdown <= 0) {
                clearInterval(timerInterval);
                resendBtn.disabled = false;
                resendTimer.textContent = 'You can now request a new code.';
            }
        }, 1000);

        // Submit resend
        const formData = new FormData();
        formData.append('email', email);
        formData.append('resend_code', '1');

        fetch('<?php echo SITE_URL; ?>/verify.php', {
            method: 'POST',
            body: formData
        })
        .then(() => {
            alert('A new verification code has been sent to your email address.');
        })
        .catch(() => {
            alert('Failed to resend code. Please try again.');
        });
    });

    function updateTimer() {
        if (countdown > 0) {
            resendTimer.textContent = `Wait ${countdown}s before resending.`;
        } else {
            resendTimer.textContent = '';
        }
    }
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

.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }

.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }

.success-popup { text-align: center; padding: 30px 20px; animation: fadeInUp 0.6s ease-out; }
.success-icon { font-size: 4rem; color: #28a745; margin-bottom: 15px; animation: popIn 0.5s ease-out; }
.success-popup h2 { margin-bottom: 10px; color: var(--text); }
.success-popup .success-message { font-size: 1.1rem; color: var(--text-light); margin-bottom: 25px; line-height: 1.6; }
.success-actions .btn-block { padding: 14px; font-size: 1.05rem; }

@keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { 0% { transform: scale(0); } 80% { transform: scale(1.2); } 100% { transform: scale(1); } }

@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>