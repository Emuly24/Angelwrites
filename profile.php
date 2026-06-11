<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php'; // Added for Zoho SMTP

redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== UPDATE PROFILE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $gender = $_POST['gender'] ?? '';
    $country = trim($_POST['country']);
    $contact_number = trim($_POST['contact_number']);
    $bio = trim($_POST['bio']);

    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email exists for another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error = 'This email is already in use by another account.';
        } else {
            // Handle profile picture upload
            $profile_pic = $user['profile_pic'] ?? '';
            if (!empty($_FILES['profile_pic']['name'])) {
                $upload_dir = 'assets/uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
                $filename = 'user_' . $user_id . '.' . $ext;
                $target = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
                    $profile_pic = $target;
                }
            }

            // Update database
            $stmt = $db->prepare("
                UPDATE users SET 
                    name = ?, email = ?, gender = ?, country = ?, contact_number = ?, bio = ?, profile_pic = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $gender, $country, $contact_number, $bio, $profile_pic, $user_id]);
            $_SESSION['name'] = $name;
            $success = 'Profile updated successfully!';

            // ===== ZOHO SMTP NOTIFICATION =====
            if ($email !== $user['email']) {
                // Notify admin about email change
                $admin_email = 'angelwrites@zohomail.com';
                $subject = 'User Email Changed';
                $body = "User {$name} (ID: {$user_id}) changed their email from {$user['email']} to {$email}.";
                sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                
                // Mark email as unverified until they verify OTP
                // (Generate and send OTP logic can be added here)
            }
        }
    }
}

// ===== CHANGE PASSWORD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (empty($current) || empty($new) || empty($confirm)) {
        $error = 'Please fill in all password fields.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $user_id]);
        $success = 'Password changed successfully!';
    }
}

$pageTitle = 'My Profile';
?>
<?php require_once 'includes/header.php'; ?>

<div class="profile-page">
    <div class="container">
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Manage your personal information and account settings.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <!-- Profile Summary -->
            <div class="profile-card">
                <div class="profile-pic">
                    <?php if ($user['profile_pic']): ?>
                        <img src="<?php echo SITE_URL . '/' . $user['profile_pic']; ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                    <?php else: ?>
                        <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </div>
                <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                <p class="user-email"><?php echo htmlspecialchars($user['email']); ?></p>
                <p class="user-username"><strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                <?php if ($user['bio']): ?>
                    <p class="user-bio"><?php echo htmlspecialchars($user['bio']); ?></p>
                <?php endif; ?>
            </div>

            <!-- Edit Profile Form -->
            <div class="profile-card">
                <h4>Edit Profile</h4>
                <form method="POST" enctype="multipart/form-data" class="profile-form">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="" <?php echo empty($user['gender']) ? 'selected' : ''; ?>>Select your gender</option>
                            <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($user['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            <option value="prefer not to say" <?php echo ($user['gender'] ?? '') === 'prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($user['country'] ?? ''); ?>" placeholder="Enter your country">
                    </div>
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" placeholder="e.g. +265 999 123 456">
                    </div>
                    <div class="form-group">
                        <label for="bio">Bio</label>
                        <textarea id="bio" name="bio" rows="3"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="profile_pic">Profile Picture</label>
                        <input type="file" id="profile_pic" name="profile_pic" accept="image/*">
                        <?php if ($user['profile_pic']): ?>
                            <p><small>Current: <?php echo basename($user['profile_pic']); ?></small></p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Update Profile</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="profile-card">
                <h4>Change Password</h4>
                <form method="POST" class="password-form">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-block">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.profile-page { padding: 32px 0 60px; }
.profile-header { text-align: center; margin-bottom: 32px; }
.profile-header h1 { font-size: 2.2rem; }

.profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.profile-card { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); }
.profile-card h4 { margin-bottom: 16px; }

.profile-pic { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 12px; overflow: hidden; background: var(--vanilla); display: flex; align-items: center; justify-content: center; }
.profile-pic img { width: 100%; height: 100%; object-fit: cover; }
.profile-pic i { font-size: 5rem; color: var(--rose); }
.profile-card h3 { text-align: center; margin-bottom: 4px; }
.user-email { text-align: center; color: var(--text-light); font-size: 0.9rem; }
.user-username { text-align: center; color: var(--text); font-size: 0.95rem; font-weight: 500; margin-top: 4px; }
.user-bio { text-align: center; color: var(--text); font-size: 0.9rem; margin-top: 8px; line-height: 1.5; }

.profile-form .form-group, .password-form .form-group { margin-bottom: 12px; }
.profile-form label, .password-form label { display: block; font-weight: 500; margin-bottom: 4px; }
.profile-form input, .password-form input, .profile-form textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; }
.profile-form input:focus, .password-form input:focus, .profile-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.profile-form textarea { resize: vertical; min-height: 60px; }
.profile-form .btn-block, .password-form .btn-block { width: 100%; }

@media (max-width: 768px) {
    .profile-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once 'includes/footer.php'; ?>