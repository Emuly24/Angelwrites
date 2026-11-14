<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';
require_once '../includes/csrf.php'; // VF3
require_once '../includes/upload.php'; // R13: shared upload limits

aw_require('manage_settings'); // K1

$error = '';
$success = '';

// ===== ENSURE SETTINGS TABLE HAS LOGO COLUMN =====
$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
$stmt = $db->query("PRAGMA table_info(settings)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$column_names = array_column($columns, 'name');
if (!in_array('logo_path', $column_names)) {
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

    // ===== LOGO (camera capture or chosen file) =====
    // R13: a new logo goes through the shared helper as an 'image': it must really be an image (any
    // file type was accepted before, under its uploaded name), is resized to 1600 px at most and
    // keeps its transparency. Brand assets are exempt from member quotas (count_quota = false), and
    // logo files already on the server are not touched.
    foreach (['live_logo' => 'site_logo_', 'logo' => 'logo_'] as $aw_f => $aw_prefix) {
        if (!empty($error)) break;
        $aw_up = aw_upload_store($_FILES[$aw_f] ?? null, 'image', 'assets/uploads', ['prefix' => $aw_prefix, 'count_quota' => false]);
        if (!empty($aw_up['ok'])) { $logo_path = $aw_up['path']; }
        elseif (empty($aw_up['empty'])) { $error = 'Logo: ' . $aw_up['error']; }
    }

    if (!empty($error)) {
        // the logo was refused: say why and save nothing
    } elseif (empty($site_name)) {
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

// ===== R14: MEMBER UPLOAD ALLOWANCE (can be raised when the site moves to a bigger host) =====
$aw_quota_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_upload_quota'])) {
    $aw_t = (int)($_POST['upload_member_total_mb'] ?? 0);
    $aw_d = (int)($_POST['upload_member_daily_mb'] ?? 0);
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $aw_quota_msg = ['ok' => false, 'msg' => 'Your session expired. Please reload the page and try again.'];
    } elseif ($aw_t < 10 || $aw_t > 10000 || $aw_d < 5 || $aw_d > 2000 || $aw_d > $aw_t) {
        $aw_quota_msg = ['ok' => false, 'msg' => 'Please enter a total between 10 and 10,000 MB and a daily amount between 5 and 2,000 MB that is not larger than the total.'];
    } else {
        $aw_up = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $aw_up->execute(['upload_member_total_mb', (string)$aw_t]);
        $aw_up->execute(['upload_member_daily_mb', (string)$aw_d]);
        try {
            $db->prepare("INSERT INTO newsletter_audit_log (user_id, action, details) VALUES (?, ?, ?)")
               ->execute([$_SESSION['user_id'], 'upload_quota_update', "Member upload allowance set to $aw_t MB in total, $aw_d MB a day"]);
        } catch (Throwable $e) { /* audit table is optional */ }
        $_SESSION['aw_quota_flash'] = "Saved: each member may store $aw_t MB in total and upload $aw_d MB a day.";
        header('Location: ' . SITE_URL . '/admin/settings.php?tab=uploads');
        exit;
    }
}
if (!empty($_SESSION['aw_quota_flash'])) { $aw_quota_msg = ['ok' => true, 'msg' => $_SESSION['aw_quota_flash']]; unset($_SESSION['aw_quota_flash']); }

// ===== VF3: EMAIL DELIVERY CHECK =====
// Members reported verification codes not arriving. This sends a real test email and shows the
// exact reason when it fails, plus the last failures the site recorded.
$aw_mail_test = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $aw_to = trim($_POST['test_to'] ?? '');
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $aw_mail_test = ['ok' => false, 'msg' => 'Your session expired. Please reload the page and try again.'];
    } elseif (!filter_var($aw_to, FILTER_VALIDATE_EMAIL)) {
        $aw_mail_test = ['ok' => false, 'msg' => 'Please enter a valid email address to send the test to.'];
    } else {
        $sent = sendEmail($aw_to, 'AngelWrites test email',
            "Hello,\n\nThis is a test from AngelWrites (Admin > Settings > Email). If you can read this, verification codes and other emails can be delivered.\n\n— AngelWrites",
            'angelwrites@zohomail.com', 'AngelWrites');
        $aw_mail_test = $sent
            ? ['ok' => true, 'msg' => 'Test email sent to ' . $aw_to . '. If it does not arrive within a few minutes, check the spam folder.']
            : ['ok' => false, 'msg' => 'The test email could not be sent. Reason: ' . ($GLOBALS['aw_mail_last_error'] ?? 'unknown')];
    }
}
$aw_smtp_user = (string)getenv('GMAIL_USERNAME');
$aw_smtp_ready = $aw_smtp_user !== '' && (string)getenv('GMAIL_APP_PASSWORD') !== '';
$aw_smtp_shown = $aw_smtp_user !== '' ? preg_replace('/^(.).*(@.*)$/', '$1•••$2', $aw_smtp_user) : '';
$aw_mail_failures = aw_mail_recent_failures(10);

$pageTitle = 'Site Settings';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <!-- ===== HERO / HEADER ===== -->
        <div class="admin-hero">
            <div class="admin-hero-content">
                <h1>Site Settings</h1>
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
            <button class="tab-btn<?php echo $aw_mail_test ? ' aw-open-email' : ''; ?>" data-tab="email">Email</button><!-- VF3 -->
            <button class="tab-btn<?php echo ($aw_quota_msg || ($_GET['tab'] ?? '') === 'uploads') ? ' aw-open-email' : ''; ?>" data-tab="uploads">Uploads</button><!-- R13 -->
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
                            <input type="file" id="logo" name="logo"<?php echo aw_upload_attrs('image'); ?>>
                            <small class="field-hint"><?php echo htmlspecialchars(aw_upload_hint('image')); ?>.</small><!-- R13 -->
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

        <!-- ===== R13: UPLOADS TAB: what this server can do, the limits in force, and recent refusals ===== -->
        <div class="tab-content" id="tab-uploads">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-cloud-arrow-up"></i> Uploads on this server</h2>
                </div>
                <div class="card-body">
                    <?php $aw_tools = aw_upload_image_tools(); $aw_yes = function ($b) { return $b ? '<strong style="color:#3e6f4f;">Yes</strong>' : '<strong style="color:#94404a;">No</strong>'; }; ?>
                    <?php if (aw_upload_can_process_images()): ?>
                        <div class="alert alert-success">This server can resize images (GD is available). Large photos are resized, re-encoded and stripped of location data automatically.</div>
                    <?php else: ?>
                        <div class="alert alert-error">This server cannot resize images (neither GD nor Imagick is usable). Images over the limit are refused; nothing is compressed.</div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <tbody>
                                <tr><td>GD (image resizing)</td><td><?php echo $aw_yes($aw_tools['gd']); ?> · JPEG <?php echo $aw_yes($aw_tools['jpeg']); ?> · PNG <?php echo $aw_yes($aw_tools['png']); ?> · WebP <?php echo $aw_yes($aw_tools['webp']); ?></td></tr>
                                <tr><td>Imagick</td><td><?php echo $aw_yes($aw_tools['imagick']); ?> <small>(not needed when GD is present)</small></td></tr>
                                <tr><td>EXIF reader / file-type detection / zip</td><td><?php echo $aw_yes($aw_tools['exif']); ?> · <?php echo $aw_yes($aw_tools['finfo']); ?> · <?php echo $aw_yes($aw_tools['zip']); ?></td></tr>
                                <tr><td>Largest single file the server accepts</td><td><strong><?php echo htmlspecialchars(aw_upload_mb(aw_upload_host_max())); ?></strong> <small>(upload_max_filesize <?php echo htmlspecialchars((string)ini_get('upload_max_filesize')); ?>, post_max_size <?php echo htmlspecialchars((string)ini_get('post_max_size')); ?>, memory_limit <?php echo htmlspecialchars((string)ini_get('memory_limit')); ?>)</small></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin:24px 0 10px;">Limits in force</h3>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead><tr><th>Type</th><th>Configured</th><th>Effective on this server</th></tr></thead>
                            <tbody>
                            <?php foreach (aw_upload_limits() as $aw_k => $aw_c): if ($aw_k[0] === '_') continue; $aw_e = aw_upload_effective($aw_k); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(ucfirst($aw_c['label'])); ?></td>
                                    <td><?php echo htmlspecialchars(aw_upload_mb((int)$aw_c['max_bytes'])); ?></td>
                                    <td><?php echo htmlspecialchars(aw_upload_hint($aw_k)); ?><?php if ($aw_e['stored_bytes'] < $aw_c['max_bytes']): ?> <small style="color:#94404a;">(the server's own limit is lower than the configured one)</small><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php $aw_q = aw_upload_limits()['_quota']; ?>
                                <tr><td>Per member</td><td colspan="2"><?php echo htmlspecialchars(aw_upload_mb($aw_q['per_day_bytes'])); ?> a day, <?php echo htmlspecialchars(aw_upload_mb($aw_q['total_bytes'])); ?> in total. Admins, Bible databases and logo files are exempt.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="field-hint">All numbers live in <code>includes/upload_limits.php</code>.</p>

                    <h3 style="margin:24px 0 10px;">Member upload allowance</h3><!-- R14 -->
                    <?php if ($aw_quota_msg): ?>
                        <div class="alert <?php echo $aw_quota_msg['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($aw_quota_msg['msg']); ?></div>
                    <?php endif; ?>
                    <p style="color:var(--text-light);margin:0 0 12px;">The standard allowance is <?php echo (int)$aw_q['default_total_mb']; ?> MB per member, which suits the free hosting plan. Raise it here when the site moves to a plan with more space; no code change is needed.</p>
                    <form method="POST" class="admin-form" action="<?php echo SITE_URL; ?>/admin/settings.php?tab=uploads">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <div class="form-group" style="flex:1;min-width:200px;">
                                <label for="upload_member_total_mb">Total per member (MB)</label>
                                <input type="number" id="upload_member_total_mb" name="upload_member_total_mb" min="10" max="10000" step="10" value="<?php echo (int)round($aw_q['total_bytes'] / 1048576); ?>" required>
                            </div>
                            <div class="form-group" style="flex:1;min-width:200px;">
                                <label for="upload_member_daily_mb">Per member per day (MB)</label>
                                <input type="number" id="upload_member_daily_mb" name="upload_member_daily_mb" min="5" max="2000" step="5" value="<?php echo (int)round($aw_q['per_day_bytes'] / 1048576); ?>" required>
                            </div>
                        </div>
                        <button type="submit" name="save_upload_quota" value="1" class="btn btn-primary"><i class="fas fa-save"></i> Save allowance</button>
                    </form>

                    <h3 style="margin:24px 0 10px;">Recent refused uploads</h3>
                    <?php
                    $aw_rej = [];
                    $aw_rej_file = __DIR__ . '/../data/upload_rejections.log';
                    if (is_file($aw_rej_file)) {
                        foreach (array_slice(array_reverse((array)@file($aw_rej_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), 0, 15) as $aw_l) {
                            $aw_r = json_decode($aw_l, true); if (is_array($aw_r)) $aw_rej[] = $aw_r;
                        }
                    }
                    ?>
                    <?php if (!$aw_rej): ?>
                        <p class="no-items">No uploads have been refused.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead><tr><th>When</th><th>Member</th><th>Type</th><th>Size</th><th>Reason</th></tr></thead>
                                <tbody>
                                <?php foreach ($aw_rej as $aw_r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($aw_r['at'] ?? '')); ?></td>
                                        <td><?php echo $aw_r['member'] === null ? '—' : '#' . (int)$aw_r['member']; ?></td>
                                        <td><?php echo htmlspecialchars((string)($aw_r['type'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(aw_upload_mb((int)($aw_r['size'] ?? 0))); ?></td>
                                        <td style="white-space:normal;max-width:420px;"><?php echo htmlspecialchars((string)($aw_r['reason'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== VF3: EMAIL TAB ===== -->
        <div class="tab-content" id="tab-email">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-envelope-circle-check"></i> Email delivery</h2>
                </div>
                <div class="card-body">
                    <?php if ($aw_smtp_ready): ?>
                        <div class="alert alert-success">Email is set up. Messages are sent through Gmail as <strong><?php echo htmlspecialchars($aw_smtp_shown); ?></strong>.</div>
                    <?php else: ?>
                        <div class="alert alert-error">Email is not set up on the server: <code>GMAIL_USERNAME</code> and/or <code>GMAIL_APP_PASSWORD</code> are missing from the <code>.env</code> file in the site folder. Until they are added, no verification codes or other emails can be sent.</div>
                    <?php endif; ?>

                    <?php if ($aw_mail_test): ?>
                        <div class="alert <?php echo $aw_mail_test['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($aw_mail_test['msg']); ?></div>
                    <?php endif; ?>

                    <form method="POST" class="admin-form" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <div class="form-group">
                            <label for="test_to">Send a test email to</label>
                            <input type="email" id="test_to" name="test_to" value="<?php echo htmlspecialchars($_POST['test_to'] ?? $admin_email); ?>" required>
                            <small class="field-hint">If it fails, the reason is shown here, word for word.</small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="send_test_email" value="1" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send test email</button>
                        </div>
                    </form>

                    <h3 style="margin:28px 0 10px;">Recent failed emails</h3>
                    <?php if (!$aw_mail_failures): ?>
                        <p class="no-items">No failed emails recorded.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Reason</th></tr></thead>
                                <tbody>
                                <?php foreach ($aw_mail_failures as $f): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($f['at'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($f['to'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($f['subject'] ?? ''); ?></td>
                                        <td style="white-space:normal;max-width:420px;"><?php echo htmlspecialchars($f['error'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <p class="field-hint" style="margin-top:14px;">Members who cannot get a code can be verified by hand in <a href="<?php echo SITE_URL; ?>/admin/manage_users.php?filter=unverified">Manage Users › Unverified</a>.</p>
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
    // VF3: after a test email, show the Email tab with its result
    const awEmailTab = document.querySelector('.tab-btn.aw-open-email');
    if (awEmailTab) setTimeout(() => awEmailTab.click(), 0);

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
            cameraStatus.style.color = '#4f8a63';
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
            cameraStatus.style.color = '#8a6f86';
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
        cameraStatus.style.color = '#4f8a63';
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
.btn-success { background:#4f8a63; color:white; border:2px solid #4f8a63; }
.btn-success:hover { background:#3e6f4f; border-color:#3e6f4f; }
.btn-warning { background:#c9965a; color:#212529; border:2px solid #c9965a; }
.btn-warning:hover { background:#a8783f; border-color:#a8783f; }

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
.alert-error { background:#f6e1e3; color:#6e2a31; border:1px solid #ecc5c9; }
.alert-success { background:#e3efe6; color:#2d5039; border:1px solid #c5dccb; }

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
.admin-form .required { color:#b4505a; }
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

<?php aw_upload_script(); /* R13 */ ?>
<?php require_once '../includes/footer.php'; ?>