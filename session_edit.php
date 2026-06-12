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

// ===== FETCH EXISTING SESSION TYPES =====
$session_types = [
    'spiritual_guidance' => 'Spiritual Guidance',
    'writing_mentorship' => 'Writing Mentorship',
    'prayer_session' => 'Prayer Session',
    'life_coaching' => 'Life Coaching',
    'faith_discussion' => 'Faith Discussion'
];

// ===== HANDLE EDIT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $date = trim($_POST['date']);
    $time = trim($_POST['time']);
    $session_type = $_POST['session_type'] ?? 'spiritual_guidance';
    $message = trim($_POST['message']);
    $duration = (int)($_POST['duration'] ?? 60);

    if (empty($date)) {
        $error = 'Please select a date.';
    } elseif (empty($time)) {
        $error = 'Please select a time.';
    } elseif (strtotime($date) < strtotime('today')) {
        $error = 'Please select a future date.';
    } elseif (!array_key_exists($session_type, $session_types)) {
        $error = 'Invalid session type.';
    } else {
        $stmt = $db->prepare("
            SELECT id FROM sessions 
            WHERE user_id = ? AND date = ? AND time = ? AND id != ? AND status IN ('pending', 'confirmed')
        ");
        $stmt->execute([$user_id, $date, $time, $session_id]);
        if ($stmt->fetch()) {
            $error = 'You already have a session booked for this date and time.';
        } else {
            $stmt = $db->prepare("
                UPDATE sessions SET date = ?, time = ?, duration = ?, session_type = ?, message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
            ");
            if ($stmt->execute([$date, $time, $duration, $session_type, $message, $session_id])) {
                $success = 'Session updated successfully!';
                
                // ===== SEND ADMIN NOTIFICATION =====
                $admin_email = 'angelwrites@zohomail.com';
                $subject = '📅 Session Updated';
                $body = "<h2>Session Updated</h2>";
                $body .= "<p><strong>Session ID:</strong> $session_id</p>";
                $body .= "<p><strong>User:</strong> " . $_SESSION['name'] . " (ID: $user_id)</p>";
                $body .= "<p><strong>New Date:</strong> $date</p>";
                $body .= "<p><strong>New Time:</strong> $time</p>";
                $body .= "<p><strong>New Duration:</strong> $duration min</p>";
                $body .= "<p><strong>Session Type:</strong> " . $session_types[$session_type] . "</p>";
                $body .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
                $body .= "<p><a href='" . SITE_URL . "/admin/manage_sessions.php'>View in admin panel</a></p>";
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <div class="session-wrapper">
            <div class="session-header">
                <h1>Edit Session</h1>
                <p>Update your session details below.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
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
                            <label for="session_type">Session Type</label>
                            <select id="session_type" name="session_type">
                                <?php foreach ($session_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($session['session_type'] ?? 'spiritual_guidance') === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
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
                            <textarea id="message" name="message" rows="4"><?php echo htmlspecialchars($session['message'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-save"></i> Update Session
                            </button>
                            <a href="<?php echo SITE_URL; ?>/book_session.php" class="btn btn-outline btn-block">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
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

.session-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.session-form .form-group { margin-bottom: 20px; }
.session-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.session-form input, .session-form select, .session-form textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.session-form input:focus, .session-form select:focus, .session-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219,161,162,0.15);
}
.session-form textarea { resize: vertical; min-height: 100px; }
.required { color: #e74c3c; }
.session-form .btn-block { width: 100%; padding: 14px; font-size: 1.05rem; justify-content: center; }

@media (max-width: 480px) {
    .session-form-container { padding: 20px; }
    .session-header h1 { font-size: 1.8rem; }
}
</style>

<?php require_once 'includes/footer.php'; ?>