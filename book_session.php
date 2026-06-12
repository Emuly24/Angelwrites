<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can book a session
redirectIfNotLoggedIn();

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];

// Fetch user name for email
$stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_name = $user['name'] ?? 'A user';

// ===== FETCH USER'S EXISTING SESSIONS =====
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE user_id = ? AND date >= DATE('now') AND status IN ('pending', 'confirmed')
    ORDER BY date ASC, time ASC
");
$stmt->execute([$user_id]);
$my_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH SESSION TYPES =====
$session_types = [
    'spiritual_guidance' => 'Spiritual Guidance',
    'writing_mentorship' => 'Writing Mentorship',
    'prayer_session' => 'Prayer Session',
    'life_coaching' => 'Life Coaching',
    'faith_discussion' => 'Faith Discussion'
];

// ===== FETCH AVAILABLE TIME SLOTS =====
$time_slots = [
    '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '13:00', '13:30', '14:00', '14:30', '15:00', '15:30',
    '16:00', '16:30', '17:00', '17:30'
];

// Fetch already booked slots for future dates
$stmt = $db->prepare("
    SELECT date, time FROM sessions 
    WHERE date >= DATE('now') AND status IN ('pending', 'confirmed')
");
$stmt->execute();
$booked_slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
$booked_slots_keyed = [];
foreach ($booked_slots as $slot) {
    $booked_slots_keyed[$slot['date'] . '_' . $slot['time']] = true;
}

// ===== HANDLE BOOKING SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date']);
    $time = trim($_POST['time']);
    $session_type = $_POST['session_type'] ?? 'spiritual_guidance';
    $message = trim($_POST['message']);
    $duration = (int)($_POST['duration'] ?? 60);
    $timezone = $_POST['timezone'] ?? 'UTC';

    // Check if session type is valid
    if (!array_key_exists($session_type, $session_types)) {
        $error = 'Invalid session type.';
    }

    // Basic validation
    if (empty($date)) {
        $error = 'Please select a date.';
    } elseif (empty($time)) {
        $error = 'Please select a time.';
    } elseif (strtotime($date) < strtotime('today')) {
        $error = 'Please select a future date.';
    } elseif (!$error) {
        // Check if slot is available
        $slot_key = $date . '_' . $time;
        if (isset($booked_slots_keyed[$slot_key])) {
            $error = 'This time slot is already booked. Please choose another.';
        } else {
            // Insert the booking
            $stmt = $db->prepare("
                INSERT INTO sessions (user_id, date, time, duration, message, status, session_type)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
            ");
            if ($stmt->execute([$user_id, $date, $time, $duration, $message, $session_type])) {
                $session_id = $db->lastInsertId();
                $success = 'Your session has been booked successfully! Angella will confirm it soon.';
                
                // ===== SEND ADMIN NOTIFICATION =====
                $admin_email = 'angelwrites@zohomail.com';
                $subject = '📅 New Session Booking';
                $body = "<h2>New Session Booking</h2>";
                $body .= "<p><strong>Client:</strong> {$user_name}</p>";
                $body .= "<p><strong>Date:</strong> {$date}</p>";
                $body .= "<p><strong>Time:</strong> {$time} ({$timezone})</p>";
                $body .= "<p><strong>Duration:</strong> {$duration} min</p>";
                $body .= "<p><strong>Session Type:</strong> " . $session_types[$session_type] . "</p>";
                if (!empty($message)) {
                    $body .= "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
                }
                $body .= "<p><a href='" . SITE_URL . "/admin/manage_sessions.php'>Manage this session</a></p>";
                sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites');
                
                // ===== SEND CONFIRMATION EMAIL TO USER =====
                $user_subject = "✅ Session Booking Confirmation – AngelWrites";
                $user_body = "Hello {$user_name},\n\nThank you for booking a session with Angella.\n\nHere are your session details:\n\n";
                $user_body .= "Date: {$date}\n";
                $user_body .= "Time: {$time} ({$timezone})\n";
                $user_body .= "Duration: {$duration} min\n";
                $user_body .= "Session Type: " . $session_types[$session_type] . "\n";
                if (!empty($message)) {
                    $user_body .= "Your message: {$message}\n\n";
                }
                $user_body .= "Angella will confirm your session within 24 hours. You will receive another email with the meeting link once confirmed.\n\n";
                $user_body .= "If you need to reschedule or cancel, please contact us directly at angelwrites@zohomail.com.\n\n";
                $user_body .= "Blessings,\nAngella Bottoman\nAngelWrites";
                sendEmail($user['email'], $user_subject, $user_body, 'angelwrites@zohomail.com', 'AngelWrites');
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ===== FETCH USER'S SESSION HISTORY (completed/cancelled) =====
$stmt = $db->prepare("
    SELECT * FROM sessions 
    WHERE user_id = ? AND date < DATE('now')
    ORDER BY date DESC, time DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$session_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Book a Session';
?>
<?php require_once 'includes/header.php'; ?>

<div class="session-page">
    <div class="container">
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== READING PROGRESS BAR ===== -->
        <div id="readingProgressBar" style="position:fixed;top:0;left:0;width:0%;height:4px;background:var(--rose);z-index:9999;transition:width 0.3s;"></div>

        <!-- Page Header -->
        <div class="session-header">
            <h1>📅 Book a Session</h1>
            <p>Connect with Angella for guidance, encouragement, or a conversation about faith, writing, or life's journey.</p>
        </div>

        <!-- Upcoming Sessions -->
        <?php if (count($my_sessions) > 0): ?>
            <div class="my-sessions-section">
                <h3>Your Upcoming Sessions</h3>
                <div class="my-sessions-list">
                    <?php foreach ($my_sessions as $s): ?>
                        <div class="my-session-item">
                            <span class="session-date"><?php echo date('M j, Y', strtotime($s['date'])); ?></span>
                            <span class="session-time"><?php echo date('g:i a', strtotime($s['time'])); ?></span>
                            <span class="session-type"><?php echo htmlspecialchars($session_types[$s['session_type']] ?? 'General'); ?></span>
                            <span class="session-status <?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span>
                            <?php if (!empty($s['message'])): ?>
                                <p class="session-message"><?php echo htmlspecialchars($s['message']); ?></p>
                            <?php endif; ?>
                            <div class="session-actions">
                                <a href="<?php echo SITE_URL; ?>/session_edit.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline">Edit</a>
                                <a href="<?php echo SITE_URL; ?>/session_cancel.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this session?');">Cancel</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Session History (completed/cancelled) -->
        <?php if (count($session_history) > 0): ?>
            <div class="session-history-section">
                <h3>Session History</h3>
                <div class="session-history-list">
                    <?php foreach ($session_history as $h): ?>
                        <div class="session-history-item">
                            <span class="history-date"><?php echo date('M j, Y', strtotime($h['date'])); ?></span>
                            <span class="history-time"><?php echo date('g:i a', strtotime($h['time'])); ?></span>
                            <span class="history-status <?php echo $h['status']; ?>"><?php echo ucfirst($h['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Booking Form -->
        <?php if (!$success): ?>
            <div class="booking-form-container">
                <form method="POST" class="booking-form" id="bookingForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date">Select Date <span class="required">*</span></label>
                            <input type="date" id="date" name="date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="time">Select Time <span class="required">*</span></label>
                            <div class="time-slots-container">
                                <?php foreach ($time_slots as $slot): ?>
                                    <?php 
                                    $date_value = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d', strtotime('+1 day'));
                                    $slot_key = $date_value . '_' . $slot;
                                    $disabled = isset($booked_slots_keyed[$slot_key]) ? 'disabled' : '';
                                    ?>
                                    <button type="button" class="time-slot-btn <?php echo $disabled ? 'booked' : ''; ?>" 
                                            data-time="<?php echo $slot; ?>" 
                                            onclick="selectTime('<?php echo $slot; ?>')"
                                            <?php echo $disabled ? 'disabled' : ''; ?>>
                                        <?php echo $slot; ?>
                                        <?php if ($disabled): ?><small>(Booked)</small><?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="selectedTime" name="time" required>
                            <small class="field-hint">Times shown are in your local time zone.</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="session_type">Session Type</label>
                            <select id="session_type" name="session_type">
                                <?php foreach ($session_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="duration">Duration</label>
                            <select id="duration" name="duration">
                                <option value="30">30 minutes</option>
                                <option value="60" selected>1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="timezone">Time Zone</label>
                        <select id="timezone" name="timezone">
                            <option value="Africa/Blantyre">Malawi (CAT)</option>
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">Eastern Time (ET)</option>
                            <option value="Europe/London">London (GMT)</option>
                            <option value="Asia/Shanghai">Shanghai (CST)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="message">What would you like to talk about?</label>
                        <textarea id="message" name="message" rows="4" placeholder="Share briefly what you'd like to discuss so Angella can prepare for your session..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-large">
                            <i class="fas fa-calendar-check"></i> Book Session
                        </button>
                    </div>
                </form>
            </div>

            <!-- Booking Info -->
            <div class="booking-info">
                <h4>💡 What to expect</h4>
                <ul>
                    <li><strong>Confirmation:</strong> Angella will confirm your session within 24 hours.</li>
                    <li><strong>Platform:</strong> Sessions are typically held via Zoom or Google Meet. Details will be shared upon confirmation.</li>
                    <li><strong>Duration:</strong> Choose a duration that works for you. Most sessions last 1 hour.</li>
                    <li><strong>Preparation:</strong> Share a brief message above to help Angella prepare.</li>
                    <li><strong>Rescheduling:</strong> To reschedule or cancel, please contact Angella directly.</li>
                </ul>
            </div>
        <?php else: ?>
            <div class="booking-success-actions">
                <a href="<?php echo SITE_URL; ?>/dashboard.php" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                </a>
                <a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-outline">
                    <i class="fas fa-home"></i> Home
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('sessionTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('sessionTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };

    // ===== READING PROGRESS BAR =====
    window.addEventListener('scroll', function() {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        document.getElementById('readingProgressBar').style.width = scrollPercent + '%';
    });

    // ===== TIME SLOT SELECTION =====
    let selectedTime = null;

    window.selectTime = function(time) {
        // Remove active class from all time slots
        document.querySelectorAll('.time-slot-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        // Add active class to selected
        const selectedBtn = document.querySelector(`.time-slot-btn[data-time="${time}"]`);
        if (selectedBtn && !selectedBtn.disabled) {
            selectedBtn.classList.add('active');
            selectedTime = time;
            document.getElementById('selectedTime').value = time;
        }
    };

    // ===== DATE CHANGE – UPDATE AVAILABILITY =====
    const dateInput = document.getElementById('date');
    dateInput.addEventListener('change', function() {
        const selectedDate = this.value;
        // Disable all time slots and show loading indicator
        document.querySelectorAll('.time-slot-btn').forEach(btn => {
            btn.disabled = true;
            btn.textContent = '⏳';
        });

        // Fetch availability for the selected date
        fetch('<?php echo SITE_URL; ?>/session_availability.php?date=' + selectedDate)
            .then(response => response.json())
            .then(data => {
                document.querySelectorAll('.time-slot-btn').forEach(btn => {
                    const slot = btn.dataset.time;
                    const slotKey = selectedDate + '_' + slot;
                    if (data.booked[slotKey]) {
                        btn.disabled = true;
                        btn.textContent = slot + ' (Booked)';
                        btn.classList.add('booked');
                    } else {
                        btn.disabled = false;
                        btn.textContent = slot;
                        btn.classList.remove('booked');
                    }
                });
            })
            .catch(error => {
                console.error('Error fetching availability:', error);
                // Reload page on error
                location.reload();
            });
    });

    // ===== FORM VALIDATION =====
    document.getElementById('bookingForm').addEventListener('submit', function(e) {
        if (!document.getElementById('selectedTime').value) {
            e.preventDefault();
            alert('Please select a time slot.');
        }
    });
});
</script>

<style>
/* ===== SESSION PAGE ===== */
.session-page { padding: 32px 0 60px; }
.session-header { text-align: center; margin-bottom: 32px; }
.session-header h1 { font-size: 2.4rem; margin-bottom: 8px; }
.session-header p { color: var(--text-light); font-size: 1.05rem; }

/* ===== MY SESSIONS ===== */
.my-sessions-section, .session-history-section { background: var(--vanilla); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; border-left: 4px solid var(--rose); }
.my-sessions-section h3, .session-history-section h3 { font-size: 1.05rem; margin-bottom: 12px; }

.my-sessions-list, .session-history-list { display: flex; flex-direction: column; gap: 8px; }
.my-session-item, .session-history-item { background: var(--card-bg); padding: 12px; border-radius: 8px; border: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

.session-date, .session-time, .session-type { font-weight: 500; font-size: 0.9rem; }
.session-status { padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.session-status.pending { background: #f1c40f; color: white; }
.session-status.confirmed { background: #2ecc71; color: white; }
.session-status.cancelled { background: #e74c3c; color: white; }
.session-status.completed { background: #3498db; color: white; }

.session-message { width: 100%; font-size: 0.85rem; color: var(--text-light); margin: 4px 0 0; }
.session-actions { display: flex; gap: 6px; }
.session-actions .btn { padding: 4px 12px; font-size: 0.75rem; }

.history-status { padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }

/* ===== BOOKING FORM ===== */
.booking-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }

.form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 16px; }
.form-row .form-group { flex: 1; min-width: 200px; }

.booking-form .form-group { margin-bottom: 16px; }
.booking-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.booking-form input, .booking-form select, .booking-form textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.booking-form input:focus, .booking-form select:focus, .booking-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219,161,162,0.15);
}
.booking-form textarea { resize: vertical; min-height: 80px; }

/* ===== TIME SLOTS ===== */
.time-slots-container { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.time-slot-btn {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--input-bg);
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
    color: var(--text);
    min-width: 60px;
    text-align: center;
}
.time-slot-btn:hover:not(:disabled) { border-color: var(--rose); background: rgba(219,161,162,0.1); }
.time-slot-btn.active { background: var(--rose); color: white; border-color: var(--rose); }
.time-slot-btn.booked { background: var(--vanilla); color: var(--text-light); cursor: not-allowed; opacity: 0.6; }
.time-slot-btn.booked small { display: block; font-size: 0.6rem; }

.required { color: #e74c3c; }
.field-hint { display: block; margin-top: 4px; font-size: 0.8rem; color: var(--text-light); }

.booking-form .btn-large { padding: 14px 32px; font-size: 1.05rem; border-radius: 30px; width: 100%; justify-content: center; }

/* ===== BOOKING SUCCESS ===== */
.booking-success-actions { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; margin-top: 24px; }
.booking-success-actions .btn { padding: 10px 28px; border-radius: 30px; }

/* ===== BOOKING INFO ===== */
.booking-info { background: var(--vanilla); border-radius: 12px; padding: 24px; margin-top: 32px; }
.booking-info h4 { font-size: 1.1rem; margin-bottom: 12px; color: var(--text); }
.booking-info ul { list-style: none; padding: 0; margin: 0; }
.booking-info ul li { padding: 6px 0; color: var(--text); font-size: 0.95rem; line-height: 1.6; border-bottom: 1px solid rgba(0,0,0,0.05); }
.booking-info ul li:last-child { border-bottom: none; }
.booking-info ul li strong { color: var(--dark); }

@media (max-width: 480px) {
    .booking-form-container { padding: 20px; }
    .form-row { flex-direction: column; }
    .form-row .form-group { min-width: auto; }
    .time-slots-container { justify-content: center; }
    .time-slot-btn { min-width: 50px; }
    .booking-success-actions { flex-direction: column; }
    .booking-success-actions .btn { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>