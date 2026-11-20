<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';
require_once 'includes/human_check.php'; // a built-in human check instead of an email code
require_once 'includes/social_auth.php';
require_once 'includes/twofactor.php';    // aw_finish_login()
require_once 'includes/member_profile.php'; // country, phone, age range, sex, recovery question

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
$success = false;

// Coming back from "Continue with Google/Facebook/...": only what the provider did not tell us is asked.
$social = (isset($_GET['social']) || isset($_POST['social'])) && !empty($_SESSION['aw_social_new']) && time() - (int)$_SESSION['aw_social_new']['at'] < 1800
    ? $_SESSION['aw_social_new'] : null;
if (isset($_GET['social']) && !$social) { header('Location: ' . SITE_URL . '/login.php?social=expired'); exit; }

$countries = AW_COUNTRIES; // includes/member_profile.php

// ===== LIST OF REFERRAL SOURCES =====
$referral_sources = [
    'Friend/Family',
    'Google Search',
    'Facebook',
    'WhatsApp',
    'TikTok',
    'Instagram',
    'Twitter/X',
    'Angella (direct referral)',
    'Telegram',
    'YouTube',
    'Event/Conference',
    'Other'
];

// ===== HANDLE REGISTRATION FORM SUBMISSION =====
// No email code any more: a built-in human check (includes/human_check.php) and per-address limits keep
// bots out, and the new member is signed in straight away. Accounts made through a provider skip the
// check (the provider has already confirmed a real person) and need no password.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? ''))); // VF1: stored in lower case; every lookup ignores case
    if ($social && !empty($social['email_verified'])) $email = $social['email']; // confirmed by the provider: not editable
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    // country (gives the phone's code), phone, WhatsApp, age range, sex, town, contact choices, recovery question
    $aw_pf = aw_profile_collect($_POST, true, true);
    $gender = (string)($aw_pf['data']['gender'] ?? '');
    $country = (string)($aw_pf['data']['country'] ?? '');
    $contact = (string)($aw_pf['data']['contact_number'] ?? '');
    $dob = '';
    $referral_source = trim($_POST['referral_source'] ?? '');

    if ($referral_source === 'Other' && !empty(trim($_POST['other_referral'] ?? ''))) {
        $referral_source = trim($_POST['other_referral']);
    }

    // Validation
    // Q24 (decision D-Q2): only name, username, email and password are required to join; the rest is
    // optional and can be added later on the profile. Empty ones are stored as NULL.
    foreach (['gender', 'country', 'contact', 'dob', 'referral_source'] as $aw_opt) {
        if ($$aw_opt === '') $$aw_opt = null;
    }
    $aw_hc_error = $social ? '' : aw_hc_verify('register', $_POST);
    if ($aw_hc_error !== '') {
        $error = $aw_hc_error;
    } elseif (!aw_hc_limit('register', 5, 3600, false)) {
        $error = 'Several accounts were just created from your network. Please try again in an hour, or sign in with an account you already have.';
    } elseif (empty($first_name) || empty($last_name) || empty($username) || empty($email) || (!$social && (empty($password) || empty($confirm_password)))) {
        $error = $social ? 'Please fill in your name, a username and your email.' : 'Please fill in your first and last name, username, email and password.';
    } elseif ($aw_pf['error'] !== '') {
        $error = htmlspecialchars($aw_pf['error']);
    } elseif (empty($_POST['terms'])) {
        $error = 'Please agree to the Terms of Service and Privacy Policy.';
    } elseif ($dob !== null && (!($aw_d = DateTime::createFromFormat('Y-m-d', $dob)) || $aw_d->format('Y-m-d') !== $dob || $dob > date('Y-m-d'))) {
        $error = 'Please enter a valid date of birth, or leave it empty.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = 'Username must be 3-20 characters (letters, numbers, underscore only).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$social && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!$social && $password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? COLLATE NOCASE");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered. You already have an account. Please <a href="login.php">go to login</a>.';
        } else {
            // Check if username already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? COLLATE NOCASE");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'This username is already taken. Please choose another.';
            } else {
                // Create new user (is_verified = 1: there is no email code step any more)
                $hashed_password = password_hash($social ? bin2hex(random_bytes(24)) : $password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (first_name, last_name, username, email, password, gender, country, contact_number, date_of_birth, referral_source, role, is_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reader', 1, CURRENT_TIMESTAMP)");
                if ($stmt->execute([$first_name, $last_name, $username, $email, $hashed_password, $gender, $country, $contact, $dob, $referral_source])) {
                    $user_id = (int)$db->lastInsertId();
                    aw_hc_limit('register', 5, 3600, true);
                    aw_profile_save($db, $user_id, $aw_pf['data']); // WhatsApp, age range, town, contact choices, recovery answer

                    // Generate referral code
                    $ref_code = strtoupper(substr(md5($username . time()), 0, 8));
                    $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$ref_code, $user_id]);
                    if ($social) {
                        aw_social_link($db, $user_id, $social['provider'], $social['uid'], $email);
                        unset($_SESSION['aw_social_new']);
                    }

                    // the team hears about new members (a failed email never stops the sign-up)
                    $admin_body = "A new member joined AngelWrites.\n\nName: $first_name $last_name\nUsername: $username\nEmail: $email\nCountry: " . ($country ?: 'not given')
                        . "\nJoined with: " . ($social ? $social['label'] : 'email and password') . "\nHeard about us: " . ($referral_source ?: 'not given')
                        . "\n\nMembers: " . SITE_URL . "/admin/manage_users.php";
                    @sendEmail(aw_admin_email(), 'New member: ' . $first_name . ' ' . $last_name, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites');

                    // signed in straight away, with a welcome on the dashboard
                    aw_finish_login($db, ['id' => $user_id, 'username' => $username, 'role' => 'reader', 'first_name' => $first_name, 'last_name' => $last_name, 'name' => null], false);
                    $_SESSION['notification'] = 'Welcome to AngelWrites, ' . htmlspecialchars($first_name) . '! Your account is ready.';
                    $aw_back = (string)($social['back'] ?? '');
                    header('Location: ' . SITE_URL . ($aw_back !== '' ? $aw_back : '/dashboard.php?welcome=1'));
                    exit;
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}
// values to show again after an error, or from the provider
$aw_v = function ($k) use ($social) {
    if (isset($_POST[$k])) return (string)$_POST[$k];
    if (!$social) return '';
    if ($k === 'email') return (string)$social['email'];
    if ($k === 'first_name') return $social['first_name'] !== '' ? $social['first_name'] : (string)strtok($social['display'], ' ');
    if ($k === 'last_name') return $social['last_name'] !== '' ? $social['last_name'] : trim((string)substr($social['display'], strlen((string)strtok($social['display'], ' '))));
    return '';
};
$aw_suggest_user = $social && !isset($_POST['username']) ? aw_social_username($db, $social['display'] ?: ($social['first_name'] . ' ' . $social['last_name']) ?: strtok($social['email'], '@')) : '';

$pageTitle = 'Sign Up';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <?php if (!$success): ?>
                    <div class="auth-header">
                        <?php if ($social): ?>
                        <h1>Almost there</h1>
                        <p>You are joining with <strong><?php echo htmlspecialchars($social['label']); ?></strong>. Just check these details and you are in.</p>
                        <?php else: ?>
                        <h1>Join AngelWrites</h1>
                        <p>Create your free account to read books, poems and reflections, and to join the community. It takes a minute.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$social) echo aw_social_buttons('register'); ?>
                    <form method="POST" action="<?php echo $social ? '?social=1' : ''; ?>" class="auth-form" id="registerForm">
                        <?php if ($social): ?><input type="hidden" name="social" value="1"><?php endif; ?>
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" placeholder="Enter your first name" required autofocus autocomplete="given-name" value="<?php echo htmlspecialchars($aw_v('first_name')); ?>">
                        </div>

                        <div class="form-group">
                            <label for="last_name">Surname / Last Name</label>
                            <input type="text" id="last_name" name="last_name" placeholder="Enter your last name" required autocomplete="family-name" value="<?php echo htmlspecialchars($aw_v('last_name')); ?>">
                        </div>

                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" placeholder="Choose a username (3-20 chars)" required autocomplete="username" value="<?php echo htmlspecialchars($aw_suggest_user !== '' ? $aw_suggest_user : $aw_v('username')); ?>">
                            <div id="usernameStatus" class="field-status"></div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="email" value="<?php echo htmlspecialchars($aw_v('email')); ?>"<?php echo ($social && !empty($social['email_verified'])) ? ' readonly aria-readonly="true"' : ''; ?>>
                            <?php if ($social && !empty($social['email_verified'])): ?><small class="field-hint">Confirmed by <?php echo htmlspecialchars($social['label']); ?>.</small><?php elseif ($social): ?><small class="field-hint"><?php echo htmlspecialchars($social['label']); ?> did not share an email address; please add yours (for password resets and anything you ask us to send).</small><?php endif; ?>
                            <div id="emailStatus" class="field-status"></div>
                        </div>

<?php if (!$social): ?>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" placeholder="Must be at least 8 characters" required>
                                <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i><span class="pw-toggle-text">Show</span></button><!-- Q27 -->
                            </div>
                            <div class="password-strength-meter">
                                <div class="strength-bar" id="strengthBar"></div>
                                <span id="strengthText">Strength: None</span>
                            </div>
                            <button type="button" id="generatePassword" class="btn btn-sm btn-secondary">
                                <i class="fas fa-sync-alt"></i> Suggest Strong Password
                            </button>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                            <div id="passwordMatchStatus" class="field-status"></div>
                        </div>

<?php endif; ?>
                        <h2 class="auth-sub">About you</h2>
                        <p class="field-hint" style="margin:-4px 0 12px">These help the team look after you: reaching you if you ask for help, age-appropriate groups and mentoring, and inviting volunteers. Only the team sees them.</p>
                        <?php echo aw_profile_fields_html($_POST ?: [], ['require' => true, 'recovery' => true, 'prefix' => 'rg']); ?>

                        <div class="form-group">
                            <label for="referral_source">How did you hear about AngelWrites? <small>(optional)</small></label>
                            <select id="referral_source" name="referral_source">
                                <option value="">Select a source</option>
                                <?php foreach ($referral_sources as $source): ?>
                                    <option value="<?php echo htmlspecialchars($source); ?>"<?php echo ($_POST['referral_source'] ?? '') === $source ? ' selected' : ''; ?>><?php echo htmlspecialchars($source); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="otherReferralWrapper" style="display: none; margin-top: 8px;">
                                <label for="other_referral">Please specify:</label>
                                <input type="text" id="other_referral" name="other_referral" placeholder="Where did you hear about us?">
                            </div>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">
                                I agree to the <a href="/terms.php">Terms of Service</a> and <a href="/privacy.php">Privacy Policy</a>
                            </label>
                        </div>

                        <?php if (!$social) echo aw_hc_field('register'); ?>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-user-plus"></i>
                            <?php echo $social ? 'Finish and join' : 'Create Account'; ?>
                        </button>
                    </form>

                    <div class="auth-footer">
                        <p>Already have an account? <a href="<?php echo SITE_URL; ?>/login.php">Sign in here</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('registerForm')) return;
    if (document.getElementById('password')) { // joining with Google, Facebook...: no password fields

    // ===== GENERATE STRONG PASSWORD =====
    const genBtn = document.getElementById('generatePassword');
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');

    function generateStrongPassword(length = 16) {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~`|}{[]:;?><,./-=';
        let password = '';
        const rnd = new Uint32Array(length);
        crypto.getRandomValues(rnd); // not Math.random(): a password must be unpredictable
        for (let i = 0; i < length; i++) password += chars[rnd[i] % chars.length];
        return password;
    }

    genBtn.addEventListener('click', function() {
        const newPassword = generateStrongPassword();
        passwordInput.value = newPassword;
        confirmInput.value = newPassword;
        checkPasswordStrength(newPassword);
        checkPasswordMatch();
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

        // Q27: the score runs 0-5 but there were only five labels, so a strong password showed
        // "Strength: undefined" and no bar. Six levels now.
        const levels = ['None', 'Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
        const colors = ['#e5d5d5', '#b4505a', '#a8783f', '#c9965a', '#8a6f86', '#4f8a63'];
        const widths = ['0%', '20%', '40%', '60%', '80%', '100%'];

        strengthBar.style.width = widths[strength];
        strengthBar.style.background = colors[strength];
        strengthText.textContent = 'Strength: ' + levels[strength];
    }

    passwordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });

    // ===== PASSWORD MATCH CHECK =====
    function checkPasswordMatch() {
        const status = document.getElementById('passwordMatchStatus');
        const pass = passwordInput.value;
        const confirm = confirmInput.value;
        if (confirm.length === 0) {
            status.textContent = '';
            status.className = '';
        } else if (pass === confirm) {
            status.textContent = '✅ Passwords match';
            status.className = 'field-status success';
        } else {
            status.textContent = '❌ Passwords do not match';
            status.className = 'field-status error';
        }
    }

    confirmInput.addEventListener('input', checkPasswordMatch);

    // ===== TOGGLE PASSWORD VISIBILITY =====
    const togglePassword = document.getElementById('togglePassword');
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

    } // end of the password fields

    // ===== REFERRAL 'OTHER' FIELD =====
    const referralSelect = document.getElementById('referral_source');
    const otherWrapper = document.getElementById('otherReferralWrapper');

    referralSelect.addEventListener('change', function() {
        if (this.value === 'Other') {
            otherWrapper.style.display = 'block';
        } else {
            otherWrapper.style.display = 'none';
        }
    });

    // ===== AJAX USERNAME AVAILABILITY =====
    const usernameInput = document.getElementById('username');
    const usernameStatus = document.getElementById('usernameStatus');

    let usernameTimer;
    usernameInput.addEventListener('input', function() {
        clearTimeout(usernameTimer);
        const username = this.value.trim();
        if (username.length < 3) {
            usernameStatus.textContent = 'Minimum 3 characters';
            usernameStatus.className = 'field-status info';
            return;
        }
        usernameTimer = setTimeout(() => {
            fetch('<?php echo SITE_URL; ?>/check_username.php?username=' + encodeURIComponent(username))
                .then(r => r.json())
                .then(data => {
                    if (data.available) {
                        usernameStatus.textContent = '✅ Username available';
                        usernameStatus.className = 'field-status success';
                    } else {
                        usernameStatus.textContent = '❌ Username not available';
                        usernameStatus.className = 'field-status error';
                    }
                });
        }, 500);
    });

    // ===== AJAX EMAIL AVAILABILITY =====
    const emailInput = document.getElementById('email');
    const emailStatus = document.getElementById('emailStatus');

    let emailTimer;
    emailInput.addEventListener('input', function() {
        clearTimeout(emailTimer);
        const email = this.value.trim();
        if (!email.includes('@')) {
            emailStatus.textContent = '';
            emailStatus.className = '';
            return;
        }
        emailTimer = setTimeout(() => {
            fetch('<?php echo SITE_URL; ?>/check_email.php?email=' + encodeURIComponent(email))
                .then(r => r.json())
                .then(data => {
                    if (data.available) {
                        emailStatus.textContent = '✅ Email available';
                        emailStatus.className = 'field-status success';
                    } else {
                        emailStatus.textContent = '❌ Email already registered';
                        emailStatus.className = 'field-status error';
                    }
                });
        }, 500);
    });
});
</script>

<style>
.auth-page { padding: 40px 0; }
.auth-wrapper { display: flex; justify-content: center; }
.auth-card { max-width: 480px; width: 100%; background: var(--card-bg); border-radius: 16px; padding: 32px; box-shadow: var(--shadow-hover); border: 1px solid var(--border); }
.auth-header { text-align: center; margin-bottom: 24px; }
.auth-header h1 { font-size: 1.8rem; margin: 0 0 4px; }
.auth-header p { color: var(--text-light); }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input, .form-group select { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }

.field-status { font-size: 0.8rem; margin-top: 4px; }
.field-status.success { color: #4f8a63; }
.field-status.error { color: #b4505a; }
.field-status.info { color: #8a6f86; }

.password-wrapper { position: relative; }
.password-wrapper input { padding-right: 40px; }
.password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #6b5a5a; }
.password-toggle:hover { color: #3d2e2e; }

.password-strength-meter { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.strength-bar { height: 4px; width: 0%; background: #e5d5d5; border-radius: 2px; transition: width 0.3s; }
#strengthText { font-size: 0.8rem; color: var(--text-light); }

#generatePassword { margin-top: 4px; padding: 4px 12px; font-size: 0.8rem; }

.contact-wrapper { display: flex; gap: 4px; }
.country-code-display { padding: 10px 14px; background: var(--input-bg); border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; color: var(--text); min-width: 60px; display: flex; align-items: center; justify-content: center; }
.contact-wrapper input { flex: 1; }

.checkbox-group { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
.checkbox-group input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--rose); }
.checkbox-group a { color: var(--rose); text-decoration: none; }
.checkbox-group a:hover { text-decoration: underline; }

.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }
.btn-google { background: #DB4437; color: white; display: block; text-align: center; padding: 10px; border-radius: 8px; text-decoration: none; margin: 8px 0; }
.btn-google:hover { background: #c23321; }

.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }

.social-login-section { text-align: center; margin: 20px 0; }

.success-popup { text-align: center; padding: 30px 20px; animation: fadeInUp 0.6s ease-out; }
.success-icon { font-size: 4rem; color: #4f8a63; margin-bottom: 15px; animation: popIn 0.5s ease-out; }
.success-popup h2 { margin-bottom: 10px; color: var(--text); }
.success-popup .success-message { font-size: 1.1rem; color: var(--text-light); margin-bottom: 25px; line-height: 1.6; }
.success-popup .small-note { font-size: 0.85rem; color: var(--text-muted); margin-top: 15px; }
.success-actions .btn-block { padding: 14px; font-size: 1.05rem; }

@keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { 0% { transform: scale(0); } 80% { transform: scale(1.2); } 100% { transform: scale(1); } }

@media (max-width: 480px) {
    .auth-card { padding: 20px; }
    .success-popup { padding: 20px; }
}

.auth-sub{font-family:'Playfair Display',Georgia,serif;font-size:1.15rem;margin:20px 0 6px;}
</style>

<?php require_once 'includes/footer.php'; ?>