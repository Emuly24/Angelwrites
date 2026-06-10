<?php
// ===== LOAD CONFIGURATION FIRST =====
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

// ===== LIST OF COUNTRIES WITH PHONE CODES =====
$countries = [
    'Afghanistan' => '+93', 'Albania' => '+355', 'Algeria' => '+213', 'Andorra' => '+376', 'Angola' => '+244',
    'Antigua and Barbuda' => '+1', 'Argentina' => '+54', 'Armenia' => '+374', 'Australia' => '+61', 'Austria' => '+43',
    'Azerbaijan' => '+994', 'Bahamas' => '+1', 'Bahrain' => '+973', 'Bangladesh' => '+880', 'Barbados' => '+1',
    'Belarus' => '+375', 'Belgium' => '+32', 'Belize' => '+501', 'Benin' => '+229', 'Bhutan' => '+975',
    'Bolivia' => '+591', 'Bosnia and Herzegovina' => '+387', 'Botswana' => '+267', 'Brazil' => '+55', 'Brunei' => '+673',
    'Bulgaria' => '+359', 'Burkina Faso' => '+226', 'Burundi' => '+257', 'Cabo Verde' => '+238', 'Cambodia' => '+855',
    'Cameroon' => '+237', 'Canada' => '+1', 'Central African Republic' => '+236', 'Chad' => '+235', 'Chile' => '+56',
    'China' => '+86', 'Colombia' => '+57', 'Comoros' => '+269', 'Congo (DRC)' => '+243', 'Congo (Republic)' => '+242',
    'Costa Rica' => '+506', 'Croatia' => '+385', 'Cuba' => '+53', 'Cyprus' => '+357', 'Czech Republic' => '+420',
    'Denmark' => '+45', 'Djibouti' => '+253', 'Dominica' => '+1', 'Dominican Republic' => '+1', 'Ecuador' => '+593',
    'Egypt' => '+20', 'El Salvador' => '+503', 'Equatorial Guinea' => '+240', 'Eritrea' => '+291', 'Estonia' => '+372',
    'Eswatini' => '+268', 'Ethiopia' => '+251', 'Fiji' => '+679', 'Finland' => '+358', 'France' => '+33',
    'Gabon' => '+241', 'Gambia' => '+220', 'Georgia' => '+995', 'Germany' => '+49', 'Ghana' => '+233',
    'Greece' => '+30', 'Grenada' => '+1', 'Guatemala' => '+502', 'Guinea' => '+224', 'Guinea-Bissau' => '+245',
    'Guyana' => '+592', 'Haiti' => '+509', 'Honduras' => '+504', 'Hungary' => '+36', 'Iceland' => '+354',
    'India' => '+91', 'Indonesia' => '+62', 'Iran' => '+98', 'Iraq' => '+964', 'Ireland' => '+353',
    'Israel' => '+972', 'Italy' => '+39', 'Ivory Coast' => '+225', 'Jamaica' => '+1', 'Japan' => '+81',
    'Jordan' => '+962', 'Kazakhstan' => '+7', 'Kenya' => '+254', 'Kiribati' => '+686', 'Kuwait' => '+965',
    'Kyrgyzstan' => '+996', 'Laos' => '+856', 'Latvia' => '+371', 'Lebanon' => '+961', 'Lesotho' => '+266',
    'Liberia' => '+231', 'Libya' => '+218', 'Liechtenstein' => '+423', 'Lithuania' => '+370', 'Luxembourg' => '+352',
    'Madagascar' => '+261', 'Malawi' => '+265', 'Malaysia' => '+60', 'Maldives' => '+960', 'Mali' => '+223',
    'Malta' => '+356', 'Marshall Islands' => '+692', 'Mauritania' => '+222', 'Mauritius' => '+230', 'Mexico' => '+52',
    'Micronesia' => '+691', 'Moldova' => '+373', 'Monaco' => '+377', 'Mongolia' => '+976', 'Montenegro' => '+382',
    'Morocco' => '+212', 'Mozambique' => '+258', 'Myanmar' => '+95', 'Namibia' => '+264', 'Nauru' => '+674',
    'Nepal' => '+977', 'Netherlands' => '+31', 'New Zealand' => '+64', 'Nicaragua' => '+505', 'Niger' => '+227',
    'Nigeria' => '+234', 'North Korea' => '+850', 'North Macedonia' => '+389', 'Norway' => '+47', 'Oman' => '+968',
    'Pakistan' => '+92', 'Palau' => '+680', 'Palestine' => '+970', 'Panama' => '+507', 'Papua New Guinea' => '+675',
    'Paraguay' => '+595', 'Peru' => '+51', 'Philippines' => '+63', 'Poland' => '+48', 'Portugal' => '+351',
    'Qatar' => '+974', 'Romania' => '+40', 'Russia' => '+7', 'Rwanda' => '+250', 'Saint Kitts and Nevis' => '+1',
    'Saint Lucia' => '+1', 'Saint Vincent' => '+1', 'Samoa' => '+685', 'San Marino' => '+378', 'Sao Tome and Principe' => '+239',
    'Saudi Arabia' => '+966', 'Senegal' => '+221', 'Serbia' => '+381', 'Seychelles' => '+248', 'Sierra Leone' => '+232',
    'Singapore' => '+65', 'Slovakia' => '+421', 'Slovenia' => '+386', 'Solomon Islands' => '+677', 'Somalia' => '+252',
    'South Africa' => '+27', 'South Korea' => '+82', 'South Sudan' => '+211', 'Spain' => '+34', 'Sri Lanka' => '+94',
    'Sudan' => '+249', 'Suriname' => '+597', 'Sweden' => '+46', 'Switzerland' => '+41', 'Syria' => '+963',
    'Taiwan' => '+886', 'Tajikistan' => '+992', 'Tanzania' => '+255', 'Thailand' => '+66', 'Timor-Leste' => '+670',
    'Togo' => '+228', 'Tonga' => '+676', 'Trinidad and Tobago' => '+1', 'Tunisia' => '+216', 'Turkey' => '+90',
    'Turkmenistan' => '+993', 'Tuvalu' => '+688', 'Uganda' => '+256', 'Ukraine' => '+380', 'United Arab Emirates' => '+971',
    'United Kingdom' => '+44', 'United States' => '+1', 'Uruguay' => '+598', 'Uzbekistan' => '+998', 'Vanuatu' => '+678',
    'Vatican City' => '+379', 'Venezuela' => '+58', 'Vietnam' => '+84', 'Yemen' => '+967', 'Zambia' => '+260',
    'Zimbabwe' => '+263'
];

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
    'Event/Conference',
    'Other'
];

// ===== HANDLE REGISTRATION FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $gender = $_POST['gender'] ?? '';
    $country = $_POST['country'] ?? '';
    $contact = trim($_POST['contact']);
    $dob = trim($_POST['dob']);
    $referral_source = $_POST['referral_source'] ?? '';

    // Validation
    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($gender) || empty($country) || empty($contact) || empty($dob) || empty($referral_source)) {
        $error = 'Please fill in all fields.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = 'Username must be 3-20 characters (letters, numbers, underscore only).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'This email is already registered. You already have an account. Please <a href="login.php">go to login</a>.';
        } else {
            // Check if username already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'This username is already taken. Please choose another.';
            } else {
                // Create new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (first_name, last_name, username, email, password, gender, country, contact_number, date_of_birth, referral_source, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reader', CURRENT_TIMESTAMP)");
                if ($stmt->execute([$first_name, $last_name, $username, $email, $hashed_password, $gender, $country, $contact, $dob, $referral_source])) {
                    $user_id = $db->lastInsertId();
                    
                    // Generate verification token
                    $verification_token = bin2hex(random_bytes(32));
                    $stmt = $db->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                    $stmt->execute([$verification_token, $user_id]);

                    // Generate referral code
                    $ref_code = strtoupper(substr(md5($username . time()), 0, 8));
                    $stmt = $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
                    $stmt->execute([$ref_code, $user_id]);

                    // ===== SEND VERIFICATION EMAIL =====
                    $verify_link = SITE_URL . '/verify.php?token=' . $verification_token;
                    $subject = "Verify your AngelWrites account";
                    $message = "Hello $first_name,\n\nPlease click the link below to verify your email address:\n\n$verify_link\n\nIf you did not create an account, please ignore this email.";
                    
                    // Use the mail helper function
                    $emailSent = sendEmail($email, $subject, $message, 'no-reply@angelwrites.gt.tc', 'AngelWrites');

                    if ($emailSent) {
                        $success = true;
                    } else {
                        $error = 'Unable to send verification email. Please try again later.';
                    }
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Sign Up';
?>
<?php require_once 'includes/header.php'; ?>

<div class="auth-page">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <?php if (!$success): ?>
                    <div class="auth-header">
                        <h1>Join AngelWrites</h1>
                        <p>Create your free account to access books, poems, and community.</p>
                    </div>
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="auth-form" id="registerForm">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" placeholder="Enter your first name" required autofocus>
                        </div>

                        <div class="form-group">
                            <label for="last_name">Surname / Last Name</label>
                            <input type="text" id="last_name" name="last_name" placeholder="Enter your last name" required>
                        </div>

                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" placeholder="Choose a username (3-20 chars)" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="you@example.com" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                                <div class="password-wrapper" style="position: relative;">
                                <input type="password" id="password" name="password" placeholder="Must be at least 8 characters" required>
                                <button type="button" id="generatePassword" class="btn btn-sm btn-secondary" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); z-index: 2; padding: 4px 8px; font-size: 0.7rem;">
                                    <i class="fas fa-sync-alt"></i> Suggest
                                </button>
                                <span class="password-toggle" id="togglePassword" style="position: absolute; right: 110px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666; z-index: 1; background: var(--input-bg); padding: 4px; border-radius: 4px;">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <small class="field-hint">Use 8+ characters with a mix of letters, numbers, and symbols.</small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                        </div>

                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select your gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                                <option value="prefer not to say">Prefer not to say</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dob">Date of Birth</label>
                            <input type="date" id="dob" name="dob" required>
                        </div>

                        <div class="form-group">
                            <label for="country">Country</label>
                            <select id="country" name="country" required>
                                <option value="">Select your country</option>
                                <?php foreach ($countries as $name => $code): ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" data-code="<?php echo htmlspecialchars($code); ?>">
                                        <?php echo htmlspecialchars($name) . ' (' . htmlspecialchars($code) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="contact">Contact Number</label>
                            <div style="display: flex; gap: 4px;">
                                <span id="countryCodeDisplay" style="padding: 10px 14px; background: var(--input-bg); border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; color: var(--text); min-width: 60px; display: flex; align-items: center;">+265</span>
                                <input type="text" id="contact" name="contact" placeholder="e.g. 999 123 456" required style="flex: 1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="referral_source">How did you hear about AngelWrites?</label>
                            <select id="referral_source" name="referral_source" required>
                                <option value="">Select a source</option>
                                <?php foreach ($referral_sources as $source): ?>
                                    <option value="<?php echo htmlspecialchars($source); ?>"><?php echo htmlspecialchars($source); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">
                                I agree to the <a href="/terms.php">Terms of Service</a> and <a href="/privacy.php">Privacy Policy</a>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-user-plus"></i>
                            Create Account
                        </button>
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
                        <p>Already have an account? <a href="<?php echo SITE_URL; ?>/login.php">Sign in here</a></p>
                    </div>
                <?php else: ?>
                    <!-- SUCCESS POPUP -->
                    <div class="success-popup">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>Account Created! 🎉</h2>
                        <p class="success-message">
                            Welcome to the AngelWrites community! 
                            <strong>A verification link has been sent to your email address.</strong>
                            Please check your inbox and click the link to verify your account before logging in.
                        </p>
                        <div class="success-actions">
                            <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-primary btn-large btn-block">
                                <i class="fas fa-sign-in-alt"></i>
                                Go to Login
                            </a>
                            <p class="small-note" style="margin-top: 12px; color: var(--text-muted);">
                                📧 Didn't receive the email? Check your spam folder or 
                                <a href="#" onclick="alert('Please contact support or request a new verification link on the login page.')">contact support</a>.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ---- Auto-generate strong password ----
    const genBtn = document.getElementById('generatePassword');
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');

    function generateStrongPassword(length = 16) {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~`|}{[]:;?><,./-=';
        let password = '';
        for (let i = 0; i < length; i++) {
            const randomIndex = Math.floor(Math.random() * chars.length);
            password += chars[randomIndex];
        }
        return password;
    }

    genBtn.addEventListener('click', function() {
        const newPassword = generateStrongPassword();
        passwordInput.value = newPassword;
        confirmInput.value = newPassword;
        passwordInput.style.borderColor = '#27ae60';
        setTimeout(() => { passwordInput.style.borderColor = ''; }, 1500);
    });

    // ---- Country Code Detection ----
    const countrySelect = document.getElementById('country');
    const codeDisplay = document.getElementById('countryCodeDisplay');

    countrySelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const code = selected.getAttribute('data-code');
        if (code) {
            codeDisplay.textContent = code;
        }
    });

    // Trigger change event on page load to set default code
    if (countrySelect.value) {
        countrySelect.dispatchEvent(new Event('change'));
    }

    // ---- Show/Hide Password Toggle ----
    const togglePassword = document.getElementById('togglePassword');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.querySelector('i').classList.toggle('fa-eye');
            this.querySelector('i').classList.toggle('fa-eye-slash');
        });
    }
});
</script>

<style>
/* Auth Styles */
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
.field-hint { display: block; margin-top: 4px; font-size: 0.8rem; color: var(--text-light); }

.password-wrapper input {
    padding-right: 160px; /* Make room for button + icon */
}
.password-toggle {
    color: #888 !important; /* Always visible grey */
}
.password-toggle:hover {
    color: #333 !important;
}
.password-toggle i {
    font-size: 1.1rem;
    transition: color 0.2s;
}

.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }

.social-login-section { text-align: center; margin: 20px 0; }
.social-login-section .btn { display: inline-block; margin: 4px; padding: 10px 20px; border-radius: 6px; color: white; text-decoration: none; font-size: 0.95rem; }
.btn-google { background: #DB4437; }
.btn-facebook { background: #1877F2; }
.btn-google:hover { background: #c23321; }
.btn-facebook:hover { background: #1559c4; }

.auth-footer { text-align: center; margin-top: 20px; font-size: 0.95rem; }
.auth-footer a { color: var(--rose); font-weight: 600; }

.success-popup { text-align: center; padding: 30px 20px; animation: fadeInUp 0.6s ease-out; }
.success-icon { font-size: 4rem; color: #28a745; margin-bottom: 15px; animation: popIn 0.5s ease-out; }
.success-popup h2 { margin-bottom: 10px; color: var(--text); }
.success-popup .success-message { font-size: 1.1rem; color: var(--text-light); margin-bottom: 25px; line-height: 1.6; }
.success-popup .small-note { font-size: 0.85rem; color: var(--text-muted); margin-top: 15px; }

@keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes popIn { 0% { transform: scale(0); } 80% { transform: scale(1.2); } 100% { transform: scale(1); } }

@media (max-width: 480px) {
    .auth-card { padding: 20px; }
}
</style>

<?php require_once 'includes/footer.php'; ?>