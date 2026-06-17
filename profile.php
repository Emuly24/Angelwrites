<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== FETCH USER STATS =====
$stmt = $db->prepare("SELECT COUNT(*) FROM reading_status WHERE user_id = ? AND status = 'finished'");
$stmt->execute([$user_id]);
$books_finished = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM poem_reads WHERE user_id = ?");
$stmt->execute([$user_id]);
$poems_read = $stmt->fetchColumn();

// FIXED: videos table does NOT have user_id — video_watches does
$stmt = $db->prepare("SELECT COUNT(*) FROM video_watches WHERE user_id = ?");
$stmt->execute([$user_id]);
$videos_watched = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT current_streak FROM reading_streaks WHERE user_id = ?");
$stmt->execute([$user_id]);
$reading_streak = $stmt->fetchColumn() ?? 0;

$stmt = $db->prepare("SELECT points, level FROM user_reputations WHERE user_id = ?");
$stmt->execute([$user_id]);
$rep = $stmt->fetch(PDO::FETCH_ASSOC);
$rep_points = $rep['points'] ?? 0;
$rep_level = $rep['level'] ?? 1;

// ===== UPDATE PROFILE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $gender = $_POST['gender'] ?? '';
    $country = trim($_POST['country']);
    $contact_number = trim($_POST['contact_number']);
    $bio = trim($_POST['bio']);
    $profile_pic = $user['profile_pic'] ?? '';

    // ===== LIVE PHOTO CAPTURE =====
    if (!empty($_FILES['live_photo']['name'])) {
        $upload_dir = 'assets/uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'user_' . $user_id . '_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_photo']['tmp_name'], $upload_dir . $photo_filename)) {
            $profile_pic = $upload_dir . $photo_filename;
        } else {
            $error = 'Failed to upload profile photo.';
        }
    }

    // ===== STANDARD PROFILE PICTURE UPLOAD =====
    if (empty($error) && !empty($_FILES['profile_pic']['name'])) {
        $upload_dir = 'assets/uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . $user_id . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
            $profile_pic = $upload_dir . $filename;
        } else {
            $error = 'Failed to upload profile picture.';
        }
    }

    if (empty($name) || empty($email) || empty($username)) {
        $error = 'Name, email, and username are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email exists for another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error = 'This email is already in use by another account.';
        } else {
            // Check if username exists for another user
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                $error = 'This username is already taken.';
            } else {
                $stmt = $db->prepare("
                    UPDATE users SET 
                        name = ?, email = ?, username = ?, gender = ?, country = ?, contact_number = ?, bio = ?, profile_pic = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $email, $username, $gender, $country, $contact_number, $bio, $profile_pic, $user_id]);
                $_SESSION['name'] = $name;
                $success = 'Profile updated successfully!';

                // ===== NOTIFY ADMIN ON EMAIL CHANGE =====
                if ($email !== $user['email']) {
                    $admin_email = 'angelwrites@zohomail.com';
                    $subject = 'User Email Changed';
                    $body = "User {$name} (ID: {$user_id}) changed their email from {$user['email']} to {$email}.";
                    sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
                }
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Page Header -->
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Manage your personal information and account settings.</p>
        </div>

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Profile Grid -->
        <div class="profile-grid">
            <!-- Profile Summary -->
            <div class="profile-card summary-card">
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
                
                <!-- Stats -->
                <div class="user-stats">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $books_finished; ?></span>
                        <span class="stat-label">Books Finished</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $poems_read; ?></span>
                        <span class="stat-label">Poems Read</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $videos_watched; ?></span>
                        <span class="stat-label">Videos Watched</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $reading_streak; ?></span>
                        <span class="stat-label">Day Streak</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $rep_points; ?></span>
                        <span class="stat-label">Reputation Points</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $rep_level; ?></span>
                        <span class="stat-label">Level</span>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="profile-card edit-card">
                <h4>Edit Profile</h4>
                <form method="POST" enctype="multipart/form-data" class="profile-form" id="profileForm">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        <div id="usernameStatus" class="field-status"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        <div id="emailStatus" class="field-status"></div>
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

                    <!-- ===== LIVE PHOTO CAPTURE ===== -->
                    <div class="form-group">
                        <label>Live Profile Photo (capture with camera)</label>
                        <div class="camera-section">
                            <div class="camera-preview-container">
                                <video id="cameraPreview" autoplay muted playsinline></video>
                                <div class="camera-placeholder" id="cameraPlaceholder">
                                    <i class="fas fa-camera"></i>
                                    <p>Camera preview will appear here.</p>
                                </div>
                            </div>
                            <div class="camera-controls">
                                <button type="button" id="startCameraBtn" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-camera"></i> Start Camera
                                </button>
                                <button type="button" id="capturePhotoBtn" class="btn btn-primary btn-sm" disabled>
                                    <i class="fas fa-camera-retro"></i> Capture
                                </button>
                                <button type="button" id="retakePhotoBtn" class="btn btn-warning btn-sm" disabled>
                                    <i class="fas fa-redo"></i> Retake
                                </button>
                                <button type="button" id="confirmPhotoBtn" class="btn btn-success btn-sm" disabled>
                                    <i class="fas fa-check"></i> Use This Photo
                                </button>
                                <span id="cameraStatus" class="status-indicator">Camera ready</span>
                            </div>
                            <div class="captured-photo-container" id="capturedPhotoContainer" style="display:none;">
                                <img id="capturedPhotoPreview" style="max-width:200px; max-height:200px; border-radius:8px;">
                            </div>
                            <input type="file" id="livePhotoInput" name="live_photo" accept="image/*" style="display:none;">
                        </div>
                    </div>

                    <!-- Standard Profile Picture Upload -->
                    <div class="form-group">
                        <label for="profile_pic">Or Upload Profile Picture</label>
                        <input type="file" id="profile_pic" name="profile_pic" accept="image/*">
                        <?php if ($user['profile_pic']): ?>
                            <div class="current-file">
                                <img src="<?php echo SITE_URL . '/' . $user['profile_pic']; ?>" alt="Current profile" style="max-width:100px; max-height:100px; border-radius:8px;">
                                <small>Current profile picture. Upload new to replace.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">Update Profile</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="profile-card password-card">
                <h4>Change Password</h4>
                <form method="POST" class="password-form" id="passwordForm">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" required>
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
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <div id="passwordMatchStatus" class="field-status"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-secondary btn-block">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('profileTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('profileTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== CAMERA =====
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraPlaceholder = document.getElementById('cameraPlaceholder');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const capturePhotoBtn = document.getElementById('capturePhotoBtn');
    const retakePhotoBtn = document.getElementById('retakePhotoBtn');
    const confirmPhotoBtn = document.getElementById('confirmPhotoBtn');
    const cameraStatus = document.getElementById('cameraStatus');
    const capturedPhotoContainer = document.getElementById('capturedPhotoContainer');
    const capturedPhotoPreview = document.getElementById('capturedPhotoPreview');
    const livePhotoInput = document.getElementById('livePhotoInput');

    let cameraStream = null;
    let capturedBlob = null;

    async function startCamera() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Your browser does not support camera access.');
                return;
            }
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            cameraPreview.srcObject = cameraStream;
            cameraPreview.style.display = 'block';
            cameraPlaceholder.style.display = 'none';
            startCameraBtn.disabled = true;
            capturePhotoBtn.disabled = false;
            cameraStatus.textContent = 'Camera active';
            cameraStatus.style.color = '#27ae60';
        } catch (error) {
            alert('Camera access denied: ' + error.message);
        }
    }

    function capturePhoto() {
        if (!cameraStream) return;
        const canvas = document.createElement('canvas');
        canvas.width = cameraPreview.videoWidth;
        canvas.height = cameraPreview.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            capturedBlob = blob;
            const url = URL.createObjectURL(blob);
            capturedPhotoPreview.src = url;
            capturedPhotoContainer.style.display = 'block';
            capturePhotoBtn.disabled = true;
            retakePhotoBtn.disabled = false;
            confirmPhotoBtn.disabled = false;
            cameraStatus.textContent = 'Photo captured';
            cameraStatus.style.color = '#3498db';
            cameraStream.getTracks().forEach(track => track.stop());
            cameraPreview.srcObject = null;
            cameraPreview.style.display = 'none';
            cameraPlaceholder.style.display = 'flex';
            startCameraBtn.disabled = false;
        }, 'image/jpeg');
    }

    function retakePhoto() {
        capturedBlob = null;
        capturedPhotoContainer.style.display = 'none';
        capturedPhotoPreview.src = '';
        capturePhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        confirmPhotoBtn.disabled = true;
        cameraStatus.textContent = 'Camera ready';
        cameraStatus.style.color = 'var(--text-light)';
        startCameraBtn.disabled = false;
    }

    function confirmPhoto() {
        if (!capturedBlob) return;
        const file = new File([capturedBlob], 'profile_photo.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        livePhotoInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);

    // ===== PASSWORD STRENGTH METER =====
    const newPasswordInput = document.getElementById('new_password');
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

    newPasswordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });

    // ===== PASSWORD MATCH =====
    const confirmInput = document.getElementById('confirm_password');
    const matchStatus = document.getElementById('passwordMatchStatus');

    function checkPasswordMatch() {
        const pass = newPasswordInput.value;
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

    // ===== TOGGLE PASSWORD VISIBILITY =====
    const togglePassword = document.getElementById('togglePassword');
    togglePassword.addEventListener('click', function() {
        const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        newPasswordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
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

    // ===== FORM VALIDATION =====
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        if (document.getElementById('usernameStatus').textContent.includes('not available')) {
            e.preventDefault();
            alert('Please choose a different username.');
        }
        if (document.getElementById('emailStatus').textContent.includes('already registered')) {
            e.preventDefault();
            alert('Please use a different email address.');
        }
    });
});
</script>

<style>
/* ===== BRAND VARIABLES (Matches index, dashboard, about, etc.) ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44,30,30,0.08);
    --shadow-hover: 0 8px 30px rgba(44,30,30,0.15);
    --transition: 0.3s cubic-bezier(0.4,0,0.2,1);
}

* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); transition:background 0.3s, color 0.3s; }

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family:'Playfair Display',Georgia,serif; color:var(--dark); line-height:1.3; }
p { line-height:1.6; }

/* ===== DARK MODE SUPPORT ===== */
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --text: #e8dddd;
    --text-light: #a08a8a;
    --border: #4a3a3a;
    --vanilla: #2c1e1e;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}

/* ===== BUTTONS (Unified with all other AngelWrites pages) ===== */
.btn {
    display:inline-flex; align-items:center; gap:8px; padding:12px 28px;
    border-radius:50px; font-weight:700; font-size:0.95rem; border:none;
    cursor:pointer; text-decoration:none; transition:all var(--transition);
    box-shadow:0 3px 10px rgba(44,30,30,0.12); letter-spacing:0.3px;
}
.btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-hover); }
.btn-primary { background:var(--rose); color:var(--white); border:2px solid var(--rose); }
.btn-primary:hover { background:var(--rose-dark); border-color:var(--rose-dark); }
.btn-secondary { background:var(--vanilla); color:var(--dark); border:2px solid var(--vanilla); }
.btn-secondary:hover { background:var(--rose-light); border-color:var(--rose-light); }
.btn-outline { background:transparent; border:2px solid var(--rose); color:var(--rose); }
.btn-outline:hover { background:var(--rose); color:var(--white); }
.btn-block { width:100%; justify-content:center; }
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-success { background:#28a745; color:white; border:2px solid #28a745; }
.btn-success:hover { background:#218838; border-color:#218838; }
.btn-warning { background:#f39c12; color:white; border:2px solid #f39c12; }
.btn-warning:hover { background:#e67e22; border-color:#e67e22; }

/* ===== PROFILE PAGE ===== */
.profile-page { padding:40px 0 80px; }
.profile-header { text-align:center; margin-bottom:32px; }
.profile-header h1 { font-size:2.4rem; margin-bottom:4px; }
.profile-header p { color:var(--text-light); font-size:1.05rem; }

/* ===== ALERTS ===== */
.alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; font-weight:500; }
.alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }

/* ===== PROFILE GRID ===== */
.profile-grid { display:grid; grid-template-columns:1fr 1fr; gap:32px; max-width:1100px; margin:0 auto; }

/* ===== PROFILE CARDS ===== */
.profile-card { background:var(--card-bg); border-radius:20px; padding:24px; border:1px solid var(--border); box-shadow:var(--shadow); transition:all var(--transition); }
.profile-card:hover { box-shadow:var(--shadow-hover); }

/* ===== SUMMARY CARD ===== */
.summary-card { text-align:center; position:relative; overflow:hidden; }
.summary-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:var(--rose); }
.profile-pic { width:130px; height:130px; border-radius:50%; margin:0 auto 16px; overflow:hidden; background:var(--vanilla); display:flex; align-items:center; justify-content:center; border:3px solid var(--rose-light); box-shadow:var(--shadow); }
.profile-pic img { width:100%; height:100%; object-fit:cover; }
.profile-pic i { font-size:5rem; color:var(--rose); }
.summary-card h3 { font-size:1.4rem; margin-bottom:2px; }
.user-email { color:var(--text-light); font-size:0.9rem; }
.user-username { color:var(--text); font-size:0.95rem; font-weight:500; margin-top:4px; }
.user-bio { color:var(--text); font-size:0.9rem; margin-top:8px; line-height:1.5; }

/* ===== STATS ===== */
.user-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(80px, 1fr)); gap:10px; margin-top:16px; }
.stat-item { background:var(--vanilla); border-radius:12px; padding:12px 8px; text-align:center; border:1px solid var(--border); transition:all var(--transition); }
.stat-item:hover { border-color:var(--rose); transform:translateY(-2px); }
.stat-number { font-size:1.4rem; font-weight:700; color:var(--rose); display:block; }
.stat-label { font-size:0.7rem; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px; font-weight:600; }

/* ===== FORMS ===== */
.edit-card h4, .password-card h4 { font-size:1.2rem; margin-bottom:16px; color:var(--dark); }
.profile-form .form-group, .password-form .form-group { margin-bottom:16px; }
.profile-form label, .password-form label { display:block; font-weight:600; margin-bottom:6px; font-size:0.9rem; color:var(--text); }
.profile-form input, .password-form input, .profile-form textarea, .profile-form select {
    width:100%; padding:12px 16px; border:1px solid var(--border); border-radius:12px;
    font-size:0.95rem; background:var(--input-bg); color:var(--text); transition:border-color 0.2s;
}
.profile-form input:focus, .password-form input:focus, .profile-form textarea:focus, .profile-form select:focus {
    outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15);
}
.profile-form textarea { resize:vertical; min-height:80px; font-family:'Inter',sans-serif; }

.field-status { font-size:0.8rem; margin-top:4px; }
.field-status.success { color:#27ae60; }
.field-status.error { color:#e74c3c; }
.field-status.info { color:#3498db; }

.current-file { display:flex; align-items:center; gap:12px; margin-top:8px; font-size:0.85rem; color:var(--text-light); }
.current-file img { border:1px solid var(--border); border-radius:8px; }

/* ===== PASSWORD ===== */
.password-wrapper { position:relative; }
.password-wrapper input { padding-right:44px; }
.password-toggle { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-light); transition:color 0.2s; }
.password-toggle:hover { color:var(--text); }

.password-strength-meter { display:flex; align-items:center; gap:10px; margin-top:6px; }
.strength-bar { height:4px; width:0%; background:#ddd; border-radius:4px; transition:width 0.3s; }
#strengthText { font-size:0.8rem; color:var(--text-light); }

/* ===== CAMERA SECTION ===== */
.camera-section { border:1px solid var(--border); border-radius:16px; padding:20px; background:var(--fantasy); margin-top:8px; }
.camera-preview-container { width:100%; max-width:400px; height:220px; background:var(--vanilla); border-radius:16px; overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; margin:0 auto; }
.camera-preview-container video { width:100%; height:100%; object-fit:cover; display:none; }
.camera-placeholder { display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-light); text-align:center; padding:24px; }
.camera-placeholder i { font-size:2.8rem; color:var(--rose); margin-bottom:8px; }
.camera-placeholder p { margin:0; font-size:0.9rem; }
.camera-controls { display:flex; flex-wrap:wrap; justify-content:center; gap:10px; align-items:center; margin-top:16px; }
.camera-controls .btn { padding:6px 16px; font-size:0.85rem; }
.captured-photo-container { text-align:center; margin-top:16px; }
.captured-photo-container img { border:3px solid var(--rose); border-radius:12px; }
.status-indicator { font-size:0.85rem; color:var(--text-light); margin-left:8px; font-weight:500; }

/* ===== RESPONSIVE ===== */
@media (max-width:992px) {
    .profile-grid { grid-template-columns:1fr; }
    .profile-header h1 { font-size:2rem; }
}
@media (max-width:768px) {
    .user-stats { grid-template-columns:repeat(3, 1fr); }
}
@media (max-width:480px) {
    .user-stats { grid-template-columns:repeat(2, 1fr); }
}
</style>

<?php require_once 'includes/footer.php'; ?>