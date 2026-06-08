<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// If already logged in, redirect to appropriate page
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

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
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
            $error = 'This email is already registered. Please <a href="login.php">login</a> instead.';
        } else {
            // Create new user (default role: reader)
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'reader', CURRENT_TIMESTAMP)");
            
            if ($stmt->execute([$name, $email, $hashed_password])) {
                $success = true;
            } else {
                $error = 'Something went wrong. Please try again.';
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
                    <!-- REGISTRATION FORM (Visible only if success is false) -->
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

                    <form method="POST" action="" class="auth-form">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" placeholder="Angella Bottoman" required autofocus>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="you@example.com" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" placeholder="Must be at least 8 characters" required>
                            <small class="field-hint">Use 8+ characters with a mix of letters, numbers, and symbols.</small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
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

                    <div class="auth-footer">
                        <p>Already have an account? <a href="<?php echo SITE_URL; ?>/login.php">Sign in here</a></p>
                    </div>
                <?php else: ?>
                    <!-- SUCCESS POPUP (Visible only when account is created) -->
                    <div class="success-popup">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>Account Created! 🎉</h2>
                        <p class="success-message">Welcome to the AngelWrites community! Your account is ready. You can now log in and start exploring.</p>
                        <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-primary btn-large btn-block">
                            <i class="fas fa-sign-in-alt"></i>
                            Log In Now
                        </a>
                        <p class="small-note">You will be redirected to the login page.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* Success Popup Styling */
    .success-popup {
        text-align: center;
        padding: 30px 20px;
        animation: fadeInUp 0.6s ease-out;
    }
    .success-icon {
        font-size: 4rem;
        color: #28a745;
        margin-bottom: 15px;
        animation: popIn 0.5s ease-out;
    }
    .success-icon i {
        display: block;
    }
    .success-popup h2 {
        margin-bottom: 10px;
        color: var(--text);
    }
    .success-popup .success-message {
        font-size: 1.1rem;
        color: var(--text-light);
        margin-bottom: 25px;
        line-height: 1.6;
    }
    .success-popup .small-note {
        font-size: 0.85rem;
        color: var(--text-muted);
        margin-top: 15px;
    }
    .btn-block {
        width: 100%;
        justify-content: center;
    }
    
    /* Animations */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes popIn {
        0% { transform: scale(0); }
        80% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }
</style>

<?php require_once 'includes/footer.php'; ?>