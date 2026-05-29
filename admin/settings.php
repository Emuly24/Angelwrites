<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE SETTINGS UPDATE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_name = trim($_POST['site_name']);
    $admin_email = trim($_POST['admin_email']);
    $site_description = trim($_POST['site_description']);
    
    // Save settings to a settings table (create if needed)
    // For simplicity, we'll store in a JSON file or config.php
    // But since you're using SQLite, let's create a settings table
    
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    
    $settings = [
        'site_name' => $site_name,
        'admin_email' => $admin_email,
        'site_description' => $site_description
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }
    
    // Update constants in config.php (optional)
    // For now, just store in DB
    
    $success = 'Settings updated successfully.';
}

// ===== FETCH CURRENT SETTINGS =====
$settings = [];
$stmt = $db->query("SELECT key, value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$pageTitle = 'Settings';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Site Settings</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
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
                <form method="POST" class="settings-form">
                    <div class="form-group">
                        <label for="site_name">Site Name</label>
                        <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'AngellaWrites'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_email">Admin Email</label>
                        <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email'] ?? 'admin@angelawrites.com'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="site_description">Site Description</label>
                        <textarea id="site_description" name="site_description" rows="3"><?php echo htmlspecialchars($settings['site_description'] ?? 'Writing with purpose, faith, and passion.'); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>