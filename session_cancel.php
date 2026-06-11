<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can cancel sessions
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirmed = isset($_GET['confirm']) ? (int)$_GET['confirm'] : 0;

// Fetch the session
$stmt = $db->prepare("SELECT * FROM sessions WHERE id = ? AND user_id = ?");
$stmt->execute([$session_id, $user_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    header('Location: ' . SITE_URL . '/book_session.php');
    exit;
}

// Only allow cancellation of pending or confirmed sessions
if (!in_array($session['status'], ['pending', 'confirmed'])) {
    header('Location: ' . SITE_URL . '/book_session.php');
    exit;
}

if ($confirmed === 1) {
    // Cancel the session
    $stmt = $db->prepare("UPDATE sessions SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    if ($stmt->execute([$session_id])) {
        // ===== SEND ADMIN NOTIFICATION =====
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '❌ Session Cancelled';
        $body = "A session has been cancelled by the user.\n\n";
        $body .= "Session ID: $session_id\n";
        $body .= "Original Date: " . $session['date'] . "\n";
        $body .= "Original Time: " . $session['time'] . "\n";
        $body .= "User ID: $user_id\n\n";
        $body .= "View in admin: " . SITE_URL . "/admin/manage_sessions.php";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
        
        header('Location: ' . SITE_URL . '/book_session.php?cancelled=1');
        exit;
    } else {
        $error = 'Failed to cancel session. Please try again.';
    }
}

$pageTitle = 'Cancel Session';
?>
<?php require_once 'includes/header.php'; ?>

<div class="session-page">
    <div class="container">
        <div class="session-wrapper">
            <div class="session-header">
                <h1>Cancel Session</h1>
                <p>Are you sure you want to cancel this session?</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="session-details-card">
                <div class="session-detail">
                    <strong>Date:</strong> <?php echo htmlspecialchars($session['date']); ?>
                </div>
                <div class="session-detail">
                    <strong>Time:</strong> <?php echo htmlspecialchars($session['time']); ?>
                </div>
                <div class="session-detail">
                    <strong>Duration:</strong> <?php echo $session['duration']; ?> minutes
                </div>
                <div class="session-detail">
                    <strong>Status:</strong> <?php echo ucfirst($session['status']); ?>
                </div>
                <?php if (!empty($session['message'])): ?>
                    <div class="session-detail">
                        <strong>Message:</strong> <?php echo htmlspecialchars($session['message']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cancel-actions">
                <a href="<?php echo SITE_URL; ?>/session_cancel.php?id=<?php echo $session_id; ?>&confirm=1" class="btn btn-danger btn-large" onclick="return confirm('Are you absolutely sure you want to cancel this session?');">
                    <i class="fas fa-times-circle"></i> Yes, Cancel Session
                </a>
                <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-outline btn-large">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.session-page { padding: 32px 0 60px; }
.session-wrapper { max-width: 600px; margin: 0 auto; }
.session-header { text-align: center; margin-bottom: 32px; }
.session-header h1 { font-size: 2.4rem; margin-bottom: 8px; }
.session-header p { color: var(--text-light); font-size: 1.05rem; }
.session-details-card { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.session-detail { padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 1rem; }
.session-detail:last-child { border-bottom: none; }
.session-detail strong { color: var(--text); }
.cancel-actions { display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; }
.cancel-actions .btn-large { padding: 14px 32px; font-size: 1.05rem; border-radius: 30px; }
.btn-danger { background: #e74c3c; color: white; transition: background 0.3s; }
.btn-danger:hover { background: #c0392b; }
@media (max-width: 480px) { .cancel-actions { flex-direction: column; align-items: center; } .cancel-actions .btn-large { width: 100%; } }
</style>

<?php require_once 'includes/footer.php'; ?>