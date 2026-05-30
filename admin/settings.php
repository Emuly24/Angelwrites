<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Only admin can access
redirectIfNotAdmin();

$error = '';
$success = '';

// ===== Ensure settings table exists =====
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

// ===== Handle form submission =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $site_name = trim($_POST['site_name']);
    $admin_email = trim($_POST['admin_email']);
    $site_description = trim($_POST['site_description']);
    
    if (empty($site_name)) {
        $error = 'Site name is required.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $settings = [
            'site_name' => $site_name,
            'admin_email' => $admin_email,
            'site_description' => $site_description
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
        $success = 'Settings updated successfully!';
    }
}

// ===== Fetch current settings =====
$settings = [];
$stmt = $db->query("SELECT key, value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$site_name = $settings['site_name'] ?? 'AngelWrites';
$admin_email = $settings['admin_email'] ?? 'admin@angelawrites.com';
$site_description = $settings['site_description'] ?? 'Writing with purpose, faith, and passion.';

$pageTitle = 'Site Settings';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Site Settings</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>General Settings</h2>
            </div>
            <div class="card-body">
                <form method="POST" class="admin-form">
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
</div>

<style>
.admin-page { padding: 32px 0 60px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.admin-header h1 { font-size: 2rem; margin: 0; }
.admin-actions { display: flex; gap: 12px; }
.admin-form .form-group { margin-bottom: 16px; }
.admin-form label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--text); }
.admin-form input[type="text"], .admin-form input[type="email"], .admin-form textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); resize: vertical; }
.admin-form input:focus, .admin-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.admin-form textarea { min-height: 60px; }
.required { color: #dc2626; }
.form-actions { display: flex; gap: 12px; margin-top: 16px; }
.form-actions .btn { min-width: 120px; justify-content: center; }
.card { margin-bottom: 24px; }
.card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); border-radius: 12px 12px 0 0; }
.card-body { padding: 20px; }
</style>

<?php require_once '../includes/footer.php'; ?>