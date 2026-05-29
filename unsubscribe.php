<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$error = '';
$success = '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

// ===== HANDLE UNSUBSCRIBE CONFIRMATION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Please provide an email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } else {
        // Check if email exists and is active
        $stmt = $db->prepare("SELECT id, is_active FROM newsletter WHERE email = ?");
        $stmt->execute([$email]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subscriber) {
            $error = 'This email is not subscribed to our newsletter.';
        } elseif ($subscriber['is_active'] == 0) {
            $success = 'This email is already unsubscribed.';
        } else {
            // Update status to inactive
            $stmt = $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt->execute([$subscriber['id']])) {
                $success = 'You have been successfully unsubscribed from the newsletter. We\'re sorry to see you go, but we respect your decision.';
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Unsubscribe';
?>
<?php require_once 'includes/header.php'; ?>

<div class="unsubscribe-page">
    <div class="container">
        <div class="unsubscribe-wrapper">
            <!-- Page Header -->
            <div class="unsubscribe-header">
                <h1>Unsubscribe</h1>
                <p>We're sorry to see you go, but we respect your decision.</p>
            </div>

            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <div class="success-actions">
                        <a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> Return Home
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Unsubscribe Form -->
            <?php if (!$success): ?>
                <div class="unsubscribe-form-container">
                    <div class="info-box">
                        <p>
                            <i class="fas fa-info-circle" style="color: var(--rose);"></i>
                            To unsubscribe from our newsletter, please enter your email address below.
                        </p>
                    </div>
                    
                    <form method="POST" class="unsubscribe-form">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" required>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-times-circle"></i> Unsubscribe
                            </button>
                        </div>
                    </form>
                    
                    <div class="unsubscribe-footer">
                        <p>
                            <a href="<?php echo SITE_URL; ?>/index.php">
                                <i class="fas fa-arrow-left"></i> Return to Home
                            </a>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.unsubscribe-page {
    padding: 32px 0 60px;
}

.unsubscribe-wrapper {
    max-width: 480px;
    margin: 0 auto;
}

.unsubscribe-header {
    text-align: center;
    margin-bottom: 32px;
}
.unsubscribe-header h1 {
    font-size: 2.2rem;
    margin-bottom: 4px;
}
.unsubscribe-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

.unsubscribe-form-container {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 32px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.info-box {
    background: var(--vanilla);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 20px;
    text-align: center;
    color: var(--text);
    font-size: 0.95rem;
}
.info-box i {
    margin-right: 6px;
}

.unsubscribe-form .form-group {
    margin-bottom: 20px;
}
.unsubscribe-form label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: var(--text);
}
.unsubscribe-form input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color var(--transition), box-shadow var(--transition);
}
.unsubscribe-form input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}

.unsubscribe-form .btn-block {
    width: 100%;
    padding: 14px;
    font-size: 1.05rem;
    justify-content: center;
}

.unsubscribe-footer {
    text-align: center;
    margin-top: 20px;
}
.unsubscribe-footer a {
    color: var(--text-light);
    transition: color var(--transition);
    font-size: 0.95rem;
}
.unsubscribe-footer a:hover {
    color: var(--rose);
}
.unsubscribe-footer a i {
    margin-right: 4px;
}

.success-actions {
    margin-top: 16px;
}

.alert-success .success-actions .btn {
    margin-top: 8px;
}

@media (max-width: 480px) {
    .unsubscribe-form-container {
        padding: 20px;
    }
    .unsubscribe-header h1 {
        font-size: 1.8rem;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>