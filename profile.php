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

    // ===== CROPPED IMAGE UPLOAD (from Croppie) =====
    if (!empty($_POST['cropped_image_data'])) {
        $upload_dir = 'assets/uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $data = $_POST['cropped_image_data'];
        list($type, $data) = explode(';', $data);
        list(, $data) = explode(',', $data);
        $data = base64_decode($data);
        
        $filename = 'user_' . $user_id . '_' . time() . '.jpg';
        $filepath = $upload_dir . $filename;
        
        if (file_put_contents($filepath, $data)) {
            $profile_pic = $upload_dir . $filename;
        } else {
            $error = 'Failed to save cropped image.';
        }
    }
    
    // ===== STANDARD PROFILE PICTURE UPLOAD (if no crop) =====
    if (empty($error) && empty($_POST['cropped_image_data']) && !empty($_FILES['profile_pic']['name'])) {
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
                    <input type="hidden" id="croppedImageData" name="cropped_image_data" value="">
                    
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

                    <!-- ===== PROFILE PICTURE UPLOAD WITH CROPPING ===== -->
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <div class="upload-section">
                            <div class="upload-zone" id="uploadZone">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Click or drag &amp; drop an image</p>
                                <input type="file" id="fileInput" accept="image/*" style="display:none;">
                            </div>
                            <div id="previewContainer" style="display:none; margin-top:12px;">
                                <img id="previewImage" style="max-width:200px; max-height:200px; border-radius:8px;">
                                <button type="button" class="btn btn-sm btn-primary" onclick="openCropModal()">✂️ Crop</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="clearUpload()">Remove</button>
                            </div>
                        </div>
                        <?php if ($user['profile_pic']): ?>
                            <div class="current-file">
                                <img src="<?php echo SITE_URL . '/' . $user['profile_pic']; ?>" alt="Current profile" style="max-width:100px; max-height:100px; border-radius:8px;">
                                <small>Current profile picture. Upload new to replace.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- ===== LIVE PHOTO CAPTURE (also goes to crop) ===== -->
                    <div class="form-group">
                        <label>Or Capture with Camera</label>
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

<!-- ===== CROP MODAL ===== -->
<div id="cropModal" class="crop-modal" style="display:none;">
    <div class="crop-modal-content">
        <div class="crop-modal-header">
            <h3>✂️ Crop Your Profile Picture</h3>
            <button class="crop-modal-close" onclick="closeCropModal()">&times;</button>
        </div>
        <div class="crop-modal-body">
            <div class="crop-container">
                <img id="cropImage" src="" alt="Image to crop">
            </div>
            <div class="crop-preview">
                <div id="cropPreview"></div>
            </div>
            <div class="crop-actions">
                <button type="button" class="btn btn-primary" onclick="applyCrop()">Apply Crop</button>
                <button type="button" class="btn btn-secondary" onclick="closeCropModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== INCLUDE CROPPIE LIBRARY ===== -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>

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

    // ============================================================
    // CROPPIE SETUP
    // ============================================================
    let croppieInstance = null;
    let currentFileForCrop = null;

    function initCroppie(imageUrl) {
        const cropImage = document.getElementById('cropImage');
        cropImage.src = imageUrl;
        
        if (croppieInstance) {
            croppieInstance.destroy();
            croppieInstance = null;
        }
        
        croppieInstance = new Croppie(cropImage, {
            viewport: { width: 200, height: 200, type: 'circle' },
            boundary: { width: 300, height: 300 },
            showZoomer: true,
            enableOrientation: true,
            enforceBoundary: true,
        });
        
        document.getElementById('cropModal').style.display = 'flex';
    }

    function openCropModal() {
        if (!currentFileForCrop) {
            alert('Please select or capture an image first.');
            return;
        }
        const url = URL.createObjectURL(currentFileForCrop);
        initCroppie(url);
    }

    function closeCropModal() {
        document.getElementById('cropModal').style.display = 'none';
        if (croppieInstance) {
            croppieInstance.destroy();
            croppieInstance = null;
        }
    }

    function applyCrop() {
        if (!croppieInstance) return;
        croppieInstance.result({ type: 'base64', size: 'viewport', format: 'jpeg', quality: 0.9 })
            .then(function(base64) {
                // Set the hidden input with cropped data
                document.getElementById('croppedImageData').value = base64;
                // Update preview
                const preview = document.getElementById('previewImage');
                preview.src = base64;
                document.getElementById('previewContainer').style.display = 'block';
                document.getElementById('uploadZone').style.display = 'none';
                closeCropModal();
                // Revoke object URL to free memory
                if (currentFileForCrop) {
                    URL.revokeObjectURL(currentFileForCrop);
                    currentFileForCrop = null;
                }
            });
    }

    // ============================================================
    // UPLOAD ZONE (Drag & Drop + Click)
    // ============================================================
    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('fileInput');
    const previewContainer = document.getElementById('previewContainer');
    const previewImage = document.getElementById('previewImage');

    uploadZone.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            handleFile(e.target.files[0]);
        }
    });

    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--rose)';
        uploadZone.style.background = 'rgba(219,161,162,0.1)';
    });

    uploadZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--border)';
        uploadZone.style.background = 'transparent';
    });

    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--border)';
        uploadZone.style.background = 'transparent';
        if (e.dataTransfer.files.length > 0) {
            handleFile(e.dataTransfer.files[0]);
        }
    });

    function handleFile(file) {
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file.');
            return;
        }
        currentFileForCrop = file;
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
            uploadZone.style.display = 'none';
            // Automatically open crop modal
            openCropModal();
        };
        reader.readAsDataURL(file);
    }

    function clearUpload() {
        previewContainer.style.display = 'none';
        uploadZone.style.display = 'block';
        previewImage.src = '';
        document.getElementById('croppedImageData').value = '';
        fileInput.value = '';
        if (currentFileForCrop) {
            URL.revokeObjectURL(currentFileForCrop);
            currentFileForCrop = null;
        }
    }

    window.openCropModal = openCropModal;
    window.closeCropModal = closeCropModal;
    window.applyCrop = applyCrop;
    window.clearUpload = clearUpload;

    // ============================================================
    // CAMERA CAPTURE (with crop integration)
    // ============================================================
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
        // Treat as a file and pass to crop
        const file = new File([capturedBlob], 'profile_photo.jpg', { type: 'image/jpeg' });
        currentFileForCrop = file;
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
            uploadZone.style.display = 'none';
            openCropModal();
        };
        reader.readAsDataURL(file);
        // Reset camera UI
        capturedPhotoContainer.style.display = 'none';
        capturedPhotoPreview.src = '';
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        capturePhotoBtn.disabled = true;
        cameraStatus.textContent = 'Photo sent to crop';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);

    // ============================================================
    // PASSWORD STRENGTH & MATCH (unchanged)
    // ============================================================
    const newPasswordInput = document.getElementById('new_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const confirmInput = document.getElementById('confirm_password');
    const matchStatus = document.getElementById('passwordMatchStatus');

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

    newPasswordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
    });
    confirmInput.addEventListener('input', checkPasswordMatch);

    const togglePassword = document.getElementById('togglePassword');
    togglePassword.addEventListener('click', function() {
        const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        newPasswordInput.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    // ============================================================
    // AJAX CHECKS (unchanged)
    // ============================================================
    const usernameInput = document.getElementById('username');
    const usernameStatus = document.getElementById('usernameStatus');
    const emailInput = document.getElementById('email');
    const emailStatus = document.getElementById('emailStatus');

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
/* ===== BRAND VARIABLES (AngelWrites) ===== */
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

/* ===== DARK MODE ===== */
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

/* ===== BUTTONS ===== */
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
.btn-danger { background:#dc3545; color:white; border:2px solid #dc3545; }
.btn-danger:hover { background:#c82333; border-color:#c82333; }

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

/* ===== UPLOAD ZONE ===== */
.upload-section { margin:12px 0; }
.upload-zone {
    border:2px dashed var(--border); border-radius:16px; padding:40px 20px;
    text-align:center; cursor:pointer; transition:all 0.3s; background:var(--fantasy);
}
.upload-zone i { font-size:2.5rem; color:var(--rose); margin-bottom:8px; display:block; }
.upload-zone p { margin:0; color:var(--text-light); }
.upload-zone:hover { border-color:var(--rose); background:rgba(219,161,162,0.05); }
#previewContainer { display:flex; flex-direction:column; align-items:center; gap:8px; }
#previewContainer img { border:2px solid var(--rose); border-radius:12px; }

.current-file { display:flex; align-items:center; gap:12px; margin-top:8px; font-size:0.85rem; color:var(--text-light); }
.current-file img { border:1px solid var(--border); border-radius:8px; }

/* ===== CROP MODAL ===== */
.crop-modal {
    position:fixed; top:0; left:0; width:100%; height:100%;
    background:rgba(30,20,20,0.7); backdrop-filter:blur(6px);
    display:none; align-items:center; justify-content:center; z-index:999999;
}
.crop-modal-content {
    background:var(--card-bg); border-radius:24px; padding:32px;
    max-width:700px; width:90%; max-height:90vh; overflow-y:auto;
    border:1px solid var(--rose-light); box-shadow:0 24px 80px rgba(0,0,0,0.35);
}
.crop-modal-header {
    display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;
}
.crop-modal-header h3 { margin:0; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.crop-modal-close {
    background:transparent; border:none; font-size:1.5rem; cursor:pointer;
    color:var(--text-light); transition:color 0.2s;
}
.crop-modal-close:hover { color:var(--rose); }
.crop-modal-body { display:flex; flex-direction:column; gap:16px; }
.crop-container { width:100%; text-align:center; }
.crop-container img { max-width:100%; }
.crop-preview { text-align:center; }
#cropPreview { width:100px; height:100px; border-radius:50%; margin:0 auto; border:3px solid var(--rose); overflow:hidden; }
.crop-actions { display:flex; gap:12px; justify-content:center; margin-top:16px; }

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

/* ===== FORMS ===== */
.edit-card h4, .password-card h4 { font-size:1.2rem; margin-bottom:16px; color:var(--dark); }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-weight:600; margin-bottom:6px; font-size:0.9rem; color:var(--text); }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:12px 16px; border:1px solid var(--border); border-radius:12px;
    font-size:0.95rem; background:var(--input-bg); color:var(--text); transition:border-color 0.2s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15);
}
.form-group textarea { resize:vertical; min-height:80px; font-family:'Inter',sans-serif; }
.field-status { font-size:0.8rem; margin-top:4px; }
.field-status.success { color:#27ae60; }
.field-status.error { color:#e74c3c; }
.field-status.info { color:#3498db; }

/* ===== PASSWORD ===== */
.password-wrapper { position:relative; }
.password-wrapper input { padding-right:44px; }
.password-toggle { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-light); transition:color 0.2s; }
.password-toggle:hover { color:var(--text); }
.password-strength-meter { display:flex; align-items:center; gap:10px; margin-top:6px; }
.strength-bar { height:4px; width:0%; background:#ddd; border-radius:4px; transition:width 0.3s; }
#strengthText { font-size:0.8rem; color:var(--text-light); }

/* ===== RESPONSIVE ===== */
@media (max-width:992px) {
    .profile-grid { grid-template-columns:1fr; }
    .profile-header h1 { font-size:2rem; }
    .crop-modal-content { padding:24px; }
}
@media (max-width:768px) {
    .user-stats { grid-template-columns:repeat(3, 1fr); }
    .crop-modal-content { padding:16px; }
}
@media (max-width:480px) {
    .user-stats { grid-template-columns:repeat(2, 1fr); }
}
</style>

<?php require_once 'includes/footer.php'; ?>