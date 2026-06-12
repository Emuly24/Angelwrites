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
$error = '';
$success = '';

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
        $success = 'Session cancelled successfully.';
        
        // ===== SEND ADMIN NOTIFICATION =====
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '❌ Session Cancelled';
        $body = "<h2>Session Cancelled</h2>";
        $body .= "<p><strong>Session ID:</strong> $session_id</p>";
        $body .= "<p><strong>User:</strong> " . $_SESSION['name'] . " (ID: $user_id)</p>";
        $body .= "<p><strong>Original Date:</strong> " . $session['date'] . "</p>";
        $body .= "<p><strong>Original Time:</strong> " . $session['time'] . "</p>";
        $body .= "<p><a href='" . SITE_URL . "/admin/manage_sessions.php'>View in admin panel</a></p>";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
        
        header('Location: ' . SITE_URL . '/book_session.php');
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <div class="session-wrapper">
            <div class="session-header">
                <h1>Cancel Session</h1>
                <p>Are you sure you want to cancel this session?</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
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
                    <strong>Status:</strong> 
                    <span class="status-badge <?php echo $session['status']; ?>">
                        <?php echo ucfirst($session['status']); ?>
                    </span>
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

<script>
// ===== THEME TOGGLE =====
const themeToggle = document.getElementById('themeToggle');
const currentTheme = localStorage.getItem('sessionTheme') || 'light';
if (currentTheme === 'dark') {
    document.body.classList.add('dark-mode');
    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
}

function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('sessionTheme', isDark ? 'dark' : 'light');
    themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
}
</script>

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

.status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
.status-badge.pending { background: #f1c40f; color: white; }
.status-badge.confirmed { background: #2ecc71; color: white; }
.status-badge.cancelled { background: #e74c3c; color: white; }
.status-badge.completed { background: #3498db; color: white; }

.cancel-actions { display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; }
.cancel-actions .btn-large { padding: 14px 32px; font-size: 1.05rem; border-radius: 30px; }
.btn-danger { background: #e74c3c; color: white; transition: background 0.3s; }
.btn-danger:hover { background: #c0392b; }

@media (max-width: 480px) {
    .cancel-actions { flex-direction: column; align-items: center; }
    .cancel-actions .btn-large { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>