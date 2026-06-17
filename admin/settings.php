<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== ENSURE SETTINGS TABLE HAS LOGO COLUMN =====
$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
$stmt = $db->query("PRAGMA table_info(settings)");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('logo_path', $columns)) {
    $db->exec("ALTER TABLE settings ADD COLUMN logo_path TEXT");
}

// ===== FETCH CURRENT SETTINGS =====
$settings = [];
$stmt = $db->query("SELECT key, value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$site_name = $settings['site_name'] ?? 'AngelWrites';
$admin_email = $settings['admin_email'] ?? 'admin@angelwrites.gt.tc';
$site_description = $settings['site_description'] ?? 'Writing with purpose, faith, and passion.';
$logo_path = $settings['logo_path'] ?? '';

// ===== HANDLE FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $site_name = trim($_POST['site_name']);
    $admin_email = trim($_POST['admin_email']);
    $site_description = trim($_POST['site_description']);

    // ===== LIVE PHOTO CAPTURE =====
    if (!empty($_FILES['live_logo']['name'])) {
        $upload_dir = '../assets/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $photo_filename = 'site_logo_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['live_logo']['tmp_name'], $upload_dir . $photo_filename)) {
            $logo_path = 'assets/uploads/' . $photo_filename;
        } else {
            $error = 'Failed to upload captured logo.';
        }
    }

    // ===== STANDARD LOGO UPLOAD (FALLBACK) =====
    if (empty($error) && !empty($_FILES['logo']['name'])) {
        $upload_dir = '../assets/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $logo_filename = 'logo_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['logo']['name']);
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_filename)) {
            $logo_path = 'assets/uploads/' . $logo_filename;
        } else {
            $error = 'Failed to upload logo.';
        }
    }

    if (empty($site_name)) {
        $error = 'Site name is required.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $settings_data = [
            'site_name' => $site_name,
            'admin_email' => $admin_email,
            'site_description' => $site_description,
            'logo_path' => $logo_path
        ];

        foreach ($settings_data as $key => $value) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }

        // ===== AUDIT LOG =====
        $stmt = $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], 'settings_update', 'Site settings updated']);

        $success = 'Settings updated successfully!';
    }
}

$pageTitle = 'Site Settings';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- ===== HERO / HEADER ===== -->
        <div class="admin-hero">
            <div class="admin-hero-content">
                <h1>⚙️ Site Settings</h1>
                <p class="admin-hero-sub">Manage your site's general settings and branding.</p>
            </div>
            <div class="admin-hero-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ===== SETTINGS TABS ===== -->
        <div class="settings-tabs">
            <button class="tab-btn active" data-tab="general">General</button>
            <button class="tab-btn" data-tab="branding">Branding</button>
        </div>

        <!-- ===== GENERAL TAB ===== -->
        <div class="tab-content active" id="tab-general">
            <div class="card">
                <div class="card-header">
                    <h2>General Settings</h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="admin-form" id="settingsForm">
                        <div class="form-group">
                            <label for="site_name">Site Name <span class="required">*</span></label>
                            <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="admin_email">Admin Email <span class="required">*</span></label>
                            <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($admin_email); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="site_description">Site Description</label>
                            <textarea id="site_description" name="site_description" rows="3"><?php echo htmlspecialchars($site_description); ?></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="save_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===== BRANDING TAB ===== -->
        <div class="tab-content" id="tab-branding">
            <div class="card">
                <div class="card-header">
                    <h2>Branding</h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="admin-form">
                        <!-- ===== LIVE PHOTO CAPTURE ===== -->
                        <div class="form-group">
                            <label>Live Logo (capture with camera)</label>
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
                                <input type="file" id="liveLogoInput" name="live_logo" accept="image/*" style="display:none;">
                            </div>
                        </div>

                        <!-- ===== STANDARD LOGO UPLOAD ===== -->
                        <div class="form-group">
                            <label for="logo">Or Upload Logo</label>
                            <input type="file" id="logo" name="logo" accept="image/*">
                            <?php if ($logo_path): ?>
                                <div class="current-file">
                                    <img src="<?php echo SITE_URL . '/' . $logo_path; ?>" alt="Current logo" style="max-width:150px; max-height:150px; border-radius:12px;">
                                    <small>Current logo. Upload new to replace.</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="save_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Branding
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== TABS =====
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('tab-' + this.dataset.tab).classList.add('active');
        });
    });

    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('settingsTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('settingsTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

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
    const liveLogoInput = document.getElementById('liveLogoInput');

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
        const file = new File([capturedBlob], 'live_logo.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        liveLogoInput.files = dt.files;
        confirmPhotoBtn.disabled = true;
        retakePhotoBtn.disabled = true;
        cameraStatus.textContent = '✅ Photo confirmed!';
        cameraStatus.style.color = '#2ecc71';
    }

    startCameraBtn.addEventListener('click', startCamera);
    capturePhotoBtn.addEventListener('click', capturePhoto);
    retakePhotoBtn.addEventListener('click', retakePhoto);
    confirmPhotoBtn.addEventListener('click', confirmPhoto);
});
</script>

<!-- ===== STYLES ===== -->
<style>
/* ===== BRAND VARIABLES ===== */
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
.rose-text { color:var(--rose); }

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
.btn-sm { padding:8px 20px; font-size:0.85rem; }
.btn-success { background:#28a745; color:white; border:2px solid #28a745; }
.btn-success:hover { background:#218838; border-color:#218838; }
.btn-warning { background:#ffc107; color:#212529; border:2px solid #ffc107; }
.btn-warning:hover { background:#e0a800; border-color:#e0a800; }

/* ===== PAGE ===== */
.admin-page { padding:32px 0 60px; }

/* ===== HERO ===== */
.admin-hero {
    background:linear-gradient(135deg, var(--vanilla), var(--fantasy));
    border-radius:20px; padding:24px 32px; margin-bottom:24px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;
    border:1px solid var(--rose-light); box-shadow:var(--shadow);
}
.admin-hero-content h1 { font-size:2rem; margin:0 0 4px 0; color:var(--dark); }
.admin-hero-sub { color:var(--text-light); font-size:1.05rem; margin:0; }
.admin-hero-actions { display:flex; gap:12px; flex-wrap:wrap; }

/* ===== ALERTS ===== */
.alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; font-weight:500; }
.alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }

/* ===== TABS ===== */
.settings-tabs { display:flex; gap:4px; margin-bottom:24px; border-bottom:2px solid var(--border); flex-wrap:wrap; }
.tab-btn { padding:10px 24px; border:none; background:transparent; cursor:pointer; font-size:0.95rem; border-radius:8px 8px 0 0; transition:all var(--transition); font-weight:600; color:var(--text); }
.tab-btn:hover { background:var(--vanilla); }
.tab-btn.active { background:var(--rose); color:white; }
.tab-content { display:none; }
.tab-content.active { display:block; }

/* ===== CARD ===== */
.card { background:var(--card-bg); border-radius:20px; border:1px solid var(--border); box-shadow:var(--shadow); overflow:hidden; margin-bottom:24px; transition:all var(--transition); }
.card:hover { box-shadow:var(--shadow-hover); }
.card-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:var(--vanilla); }
.card-header h2 { font-size:1.3rem; margin:0; font-family:'Playfair Display',Georgia,serif; color:var(--dark); }
.card-body { padding:24px; }

/* ===== FORM ===== */
.admin-form .form-group { margin-bottom:16px; }
.admin-form label { display:block; font-weight:600; margin-bottom:6px; color:var(--text); font-size:0.9rem; }
.admin-form .required { color:#dc3545; }
.admin-form input[type="text"], .admin-form input[type="email"], .admin-form textarea {
    width:100%; padding:12px 16px; border:1px solid var(--border); border-radius:12px;
    font-size:0.95rem; background:var(--input-bg); color:var(--text); transition:border-color 0.2s;
}
.admin-form input:focus, .admin-form textarea:focus { outline:none; border-color:var(--rose); box-shadow:0 0 0 3px rgba(219,161,162,0.15); }
.admin-form textarea { resize:vertical; min-height:80px; font-family:'Inter',sans-serif; }
.admin-form input[type="file"] { padding:10px; border:2px dashed var(--border); border-radius:12px; background:var(--vanilla); cursor:pointer; }
.admin-form input[type="file"]:hover { border-color:var(--rose); background:rgba(219,161,162,0.05); }
.admin-form .current-file { display:flex; align-items:center; gap:10px; margin-top:8px; font-size:0.85rem; color:var(--text-light); padding:8px 12px; background:var(--fantasy); border-radius:8px; border:1px solid var(--border); }
.admin-form .current-file img { border-radius:12px; }
.admin-form .form-actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:20px; padding-top:20px; border-top:1px solid var(--border); }
.admin-form .form-actions .btn { min-width:120px; justify-content:center; }

/* ===== CAMERA ===== */
.camera-section { border:1px solid var(--border); border-radius:16px; padding:16px; background:var(--fantasy); margin-top:8px; }
.camera-preview-container { width:100%; max-width:400px; height:220px; background:var(--vanilla); border-radius:12px; overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; margin:0 auto; }
.camera-preview-container video { width:100%; height:100%; object-fit:cover; display:none; }
.camera-placeholder { display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-light); text-align:center; padding:24px; }
.camera-placeholder i { font-size:2.5rem; color:var(--rose); margin-bottom:8px; }
.camera-placeholder p { margin:0; font-size:0.9rem; }
.camera-controls { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; align-items:center; margin-top:12px; }
.camera-controls .btn { padding:6px 16px; font-size:0.85rem; border-radius:50px; }
.captured-photo-container { text-align:center; margin-top:12px; }
.captured-photo-container img { border:3px solid var(--rose); border-radius:12px; }
.status-indicator { font-size:0.85rem; color:var(--text-light); margin-left:8px; font-weight:500; }

/* ===== RESPONSIVE ===== */
@media (max-width:992px) {
    .admin-hero { flex-direction:column; text-align:center; align-items:center; }
    .admin-hero-content h1 { font-size:1.6rem; }
    .admin-hero-actions { justify-content:center; }
}
@media (max-width:480px) {
    .settings-tabs { flex-direction:column; border-bottom:none; }
    .tab-btn { border-radius:12px; border:1px solid var(--border); text-align:center; }
    .tab-btn.active { border-color:var(--rose); background:var(--rose); color:white; }
}
</style>

<?php require_once '../includes/footer.php'; ?>