<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can edit sessions
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

// Only allow editing of pending sessions
if ($session['status'] !== 'pending') {
    $error = 'Only pending sessions can be edited.';
}

// ===== FETCH AVAILABLE TIME SLOTS =====
$time_slots = [
    '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '13:00', '13:30', '14:00', '14:30', '15:00', '15:30',
    '16:00', '16:30', '17:00', '17:30'
];

// ===== HANDLE EDIT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $date = trim($_POST['date']);
    $time = trim($_POST['time']);
    $message = trim($_POST['message']);
    $duration = (int)($_POST['duration'] ?? 60);

    if (empty($date)) {
        $error = 'Please select a date.';
    } elseif (empty($time)) {
        $error = 'Please select a time.';
    } elseif (strtotime($date) < strtotime('today')) {
        $error = 'Please select a future date.';
    } else {
        // Check if another session conflicts (excluding this one)
        $stmt = $db->prepare("
            SELECT id FROM sessions 
            WHERE user_id = ? AND date = ? AND time = ? AND id != ? AND status IN ('pending', 'confirmed')
        ");
        $stmt->execute([$user_id, $date, $time, $session_id]);
        if ($stmt->fetch()) {
            $error = 'You already have a session booked for this date and time.';
        } else {
            $stmt = $db->prepare("UPDATE sessions SET date = ?, time = ?, duration = ?, message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt->execute([$date, $time, $duration, $message, $session_id])) {
                $success = 'Session updated successfully!';
                
                // ===== SEND ADMIN NOTIFICATION =====
                $admin_email = 'angelwrites@zohomail.com';
                $subject = '📅 Session Updated';
                $body = "A session has been updated by the user.\n\n";
                $body .= "Session ID: $session_id\n";
                $body .= "New Date: $date\n";
                $body .= "New Time: $time\n";
                $body .= "New Duration: $duration min\n";
                $body .= "Message: " . ($message ?: 'None') . "\n\n";
                $body .= "View in admin: " . SITE_URL . "/admin/manage_sessions.php";
                sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Edit Session';
?>
<?php require_once 'includes/header.php'; ?>

<div class="session-page">
    <div class="container">
        <div class="session-wrapper">
            <div class="session-header">
                <h1>Edit Session</h1>
                <p>Update your session details below.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!$error && !$success): ?>
                <div class="session-form-container">
                    <form method="POST" class="session-form">
                        <div class="form-group">
                            <label for="date">Select Date <span class="required">*</span></label>
                            <input type="date" id="date" name="date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo htmlspecialchars($session['date']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="time">Select Time <span class="required">*</span></label>
                            <select id="time" name="time" required>
                                <option value="">Choose a time</option>
                                <?php foreach ($time_slots as $slot): ?>
                                    <option value="<?php echo $slot; ?>" <?php echo $session['time'] === $slot ? 'selected' : ''; ?>>
                                        <?php echo $slot; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="duration">Duration</label>
                            <select id="duration" name="duration">
                                <option value="30" <?php echo $session['duration'] == 30 ? 'selected' : ''; ?>>30 minutes</option>
                                <option value="60" <?php echo $session['duration'] == 60 ? 'selected' : ''; ?>>1 hour</option>
                                <option value="90" <?php echo $session['duration'] == 90 ? 'selected' : ''; ?>>1.5 hours</option>
                                <option value="120" <?php echo $session['duration'] == 120 ? 'selected' : ''; ?>>2 hours</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="message">What would you like to talk about?</label>
                            <textarea id="message" name="message" rows="4" placeholder="Share briefly what you'd like to discuss..."><?php echo htmlspecialchars($session['message'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-save"></i>
                                Update Session
                            </button>
                            <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-outline btn-block">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.session-page { padding: 32px 0 60px; }
.session-wrapper { max-width: 600px; margin: 0 auto; }
.session-header { text-align: center; margin-bottom: 32px; }
.session-header h1 { font-size: 2.4rem; margin-bottom: 8px; }
.session-header p { color: var(--text-light); font-size: 1.05rem; }
.session-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.session-form .form-group { margin-bottom: 20px; }
.session-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.session-form input, .session-form select, .session-form textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; background: var(--input-bg); color: var(--text); }
.session-form input:focus, .session-form select:focus, .session-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.session-form textarea { resize: vertical; min-height: 100px; }
.session-form .btn-block { width: 100%; padding: 14px; font-size: 1.05rem; justify-content: center; }
@media (max-width: 480px) { .session-form-container { padding: 20px; } .session-header h1 { font-size: 1.8rem; } }
</style>

<?php require_once 'includes/footer.php'; ?>