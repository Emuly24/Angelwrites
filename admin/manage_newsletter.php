<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

redirectIfNotAdmin();

$error = '';
$success = '';

// ===== HANDLE DELETE =====
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM newsletter WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Subscriber removed.';
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
    exit;
}

// ===== HANDLE UNSUBSCRIBE =====
if (isset($_GET['unsubscribe'])) {
    $id = (int)$_GET['unsubscribe'];
    $stmt = $db->prepare("UPDATE newsletter SET is_active = 0, unsubscribed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$id]);
    $success = 'Subscriber unsubscribed.';
    header('Location: ' . SITE_URL . '/admin/manage_newsletter.php');
    exit;
}

// ===== FETCH SUBSCRIBERS =====
$stmt = $db->query("SELECT * FROM newsletter ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Newsletter';
?>
<?php require_once '../includes/header.php'; ?>

<div class="admin-page">
    <div class="container">
        <div class="admin-header">
            <h1>Newsletter Subscribers</h1>
            <div class="admin-actions">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back
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
                <h2>Subscribers (<?php echo count($subscribers); ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (count($subscribers) > 0): ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Subscribed</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subscribers as $sub): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sub['email']); ?></td>
                                        <td><?php echo htmlspecialchars($sub['name'] ?? '—'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $sub['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $sub['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($sub['subscribed_at'])); ?></td>
                                        <td class="actions">
                                            <?php if ($sub['is_active']): ?>
                                                <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?unsubscribe=<?php echo $sub['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Unsubscribe this user?');">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo SITE_URL; ?>/admin/manage_newsletter.php?delete=<?php echo $sub['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove this subscriber?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-items">No subscribers yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* ===== SMART BOOK FORM STYLING ===== */
    .book-form-container .card {
        border-radius: 16px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        margin-top: 16px;
    }
    .book-form-container .card-header {
        background: var(--vanilla);
        padding: 16px 24px;
        border-radius: 16px 16px 0 0;
        border-bottom: 1px solid var(--border);
    }
    .book-form-container .card-header h2 {
        font-size: 1.3rem;
        margin: 0;
        color: var(--dark);
    }
    .book-form-container .card-body {
        padding: 24px;
    }

    /* ===== FORM ROW & GROUP ===== */
    .form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 12px;
    }
    .form-row .form-group {
        flex: 1;
        min-width: 200px;
    }

    .admin-form .form-group {
        margin-bottom: 16px;
    }
    .admin-form label {
        display: block;
        font-weight: 600;
        margin-bottom: 4px;
        color: var(--text);
        font-size: 0.95rem;
    }
    .admin-form .required {
        color: #e74c3c;
    }

    /* ===== INPUTS ===== */
    .admin-form input[type="text"],
    .admin-form input[type="number"],
    .admin-form textarea,
    .admin-form select {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid var(--border);
        border-radius: 10px;
        font-size: 0.95rem;
        background: var(--input-bg);
        color: var(--text);
        transition: all 0.3s ease;
    }
    .admin-form input[type="text"]:focus,
    .admin-form input[type="number"]:focus,
    .admin-form textarea:focus,
    .admin-form select:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 4px rgba(219, 161, 162, 0.15);
    }
    .admin-form textarea {
        resize: vertical;
        min-height: 80px;
    }

    /* ===== CHECKBOXES ===== */
    .admin-form .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 6px 0;
        padding: 4px 0;
    }
    .admin-form .checkbox-group input[type="checkbox"] {
        appearance: none;
        -webkit-appearance: none;
        width: 20px;
        height: 20px;
        border: 2px solid var(--border);
        border-radius: 6px;
        background: var(--input-bg);
        cursor: pointer;
        transition: all 0.2s ease;
        flex-shrink: 0;
        position: relative;
    }
    .admin-form .checkbox-group input[type="checkbox"]:checked {
        background: var(--rose);
        border-color: var(--rose);
    }
    .admin-form .checkbox-group input[type="checkbox"]:checked::after {
        content: '✓';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: white;
        font-size: 14px;
        font-weight: 700;
    }
    .admin-form .checkbox-group input[type="checkbox"]:focus {
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }
    .admin-form .checkbox-group label {
        font-weight: 400;
        font-size: 0.95rem;
        margin: 0;
        cursor: pointer;
    }

    /* ===== FILE INPUTS ===== */
    .admin-form input[type="file"] {
        padding: 8px 12px;
        border: 2px dashed var(--border);
        border-radius: 10px;
        background: var(--vanilla);
        width: 100%;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 0.9rem;
        color: var(--text);
    }
    .admin-form input[type="file"]:hover {
        border-color: var(--rose);
        background: rgba(219, 161, 162, 0.05);
    }
    .admin-form input[type="file"]:focus {
        outline: none;
        border-color: var(--rose);
        box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
    }

    /* ===== CURRENT FILE PREVIEW ===== */
    .admin-form .current-file {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 6px;
        font-size: 0.85rem;
        color: var(--text-light);
        padding: 6px 12px;
        background: var(--fantasy);
        border-radius: 6px;
        border: 1px solid var(--border);
    }
    .admin-form .current-file img {
        border-radius: 4px;
        border: 1px solid var(--border);
    }
    .admin-form .current-file small {
        color: var(--text-light);
    }

    /* ===== FIELD HINT ===== */
    .admin-form .field-hint {
        display: block;
        margin-top: 4px;
        font-size: 0.8rem;
        color: var(--text-light);
        font-style: italic;
    }

    /* ===== FORM ACTIONS ===== */
    .admin-form .form-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
    }
    .admin-form .form-actions .btn {
        min-width: 120px;
        justify-content: center;
        padding: 10px 24px;
        font-weight: 600;
        border-radius: 30px;
        transition: all 0.3s ease;
    }
    .admin-form .form-actions .btn-primary {
        background: var(--rose);
        color: white;
    }
    .admin-form .form-actions .btn-primary:hover {
        background: var(--rose-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(219, 161, 162, 0.3);
    }
    .admin-form .form-actions .btn-outline {
        border: 2px solid var(--border);
        background: transparent;
        color: var(--text);
    }
    .admin-form .form-actions .btn-outline:hover {
        border-color: var(--rose);
        background: var(--rose);
        color: white;
        transform: translateY(-2px);
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 600px) {
        .form-row {
            flex-direction: column;
            gap: 0;
        }
        .form-row .form-group {
            min-width: 100%;
        }
        .admin-form .form-actions {
            flex-direction: column;
        }
        .admin-form .form-actions .btn {
            width: 100%;
        }
    }
</style>

<?php require_once '../includes/footer.php'; ?>