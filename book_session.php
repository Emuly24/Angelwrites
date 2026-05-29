<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Only logged-in users can book a session
redirectIfNotLoggedIn();

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];

// ===== HANDLE BOOKING SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date']);
    $time = trim($_POST['time']);
    $message = trim($_POST['message']);
    $duration = (int)($_POST['duration'] ?? 60);

    // Basic validation
    if (empty($date)) {
        $error = 'Please select a date.';
    } elseif (empty($time)) {
        $error = 'Please select a time.';
    } elseif (strtotime($date) < strtotime('today')) {
        $error = 'Please select a future date.';
    } else {
        // Check if user already has a pending or confirmed session on this date/time
        $stmt = $db->prepare("
            SELECT id FROM sessions 
            WHERE user_id = ? AND date = ? AND time = ? AND status IN ('pending', 'confirmed')
        ");
        $stmt->execute([$user_id, $date, $time]);
        if ($stmt->fetch()) {
            $error = 'You already have a session booked for this date and time.';
        } else {
            // Insert the booking
            $stmt = $db->prepare("
                INSERT INTO sessions (user_id, date, time, duration, message, status) 
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            if ($stmt->execute([$user_id, $date, $time, $duration, $message])) {
                $success = 'Your session has been booked successfully! Angella will confirm it soon.';
                
                // Optional: Send email notification to admin (using PHP mail)
                $to = 'admin@angelawrites.com'; // Change to Angella's email
                $subject = 'New Session Booking';
                $email_body = "A new session has been booked by {$_SESSION['name']} ({$_SESSION['email']}).\n\n";
                $email_body .= "Date: $date\nTime: $time\nDuration: $duration min\n";
                $email_body .= "Message: $message\n\n";
                $email_body .= "Login to the admin panel to manage this session.";
                $headers = "From: " . SITE_NAME . " <no-reply@angelawrites.com>";
                mail($to, $subject, $email_body, $headers);
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ===== FETCH AVAILABLE TIME SLOTS (sample) =====
// In a real system, you might have a table of available slots.
// For now, we'll generate a list of common time slots.
$time_slots = [
    '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '13:00', '13:30', '14:00', '14:30', '15:00', '15:30',
    '16:00', '16:30', '17:00', '17:30'
];

// Fetch already booked slots for today and future dates (to disable them)
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

$pageTitle = 'Book a Session';
?>
<?php require_once 'includes/header.php'; ?>

<div class="session-page">
    <div class="container">
        <div class="session-wrapper">
            <!-- Page Header -->
            <div class="session-header">
                <h1>Book a 1-on-1 Session</h1>
                <p>Connect with Angella for guidance, encouragement, or a conversation about faith, writing, or life's journey.</p>
            </div>

            <!-- Alert Messages -->
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

            <!-- Booking Form -->
            <?php if (!$success): ?>
                <div class="session-form-container">
                    <form method="POST" class="session-form">
                        <div class="form-group">
                            <label for="date">Select Date <span class="required">*</span></label>
                            <input type="date" id="date" name="date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="time">Select Time <span class="required">*</span></label>
                            <select id="time" name="time" required>
                                <option value="">Choose a time</option>
                                <?php foreach ($time_slots as $slot): ?>
                                    <?php 
                                    $disabled = isset($booked_slots_keyed[date('Y-m-d', strtotime('+1 day')) . '_' . $slot]) 
                                        ? 'disabled' : ''; 
                                    ?>
                                    <option value="<?php echo $slot; ?>" <?php echo $disabled; ?>>
                                        <?php echo $slot; ?>
                                        <?php if ($disabled): ?>(Booked)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="field-hint">Times shown are in your local time zone.</small>
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

                        <div class="form-group">
                            <label for="message">What would you like to talk about?</label>
                            <textarea id="message" name="message" rows="4" placeholder="Share briefly what you'd like to discuss so Angella can prepare for your session..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-calendar-check"></i>
                                Book Session
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="booking-success-actions">
                    <a href="<?php echo SITE_URL; ?>/library.php" class="btn btn-primary">
                        <i class="fas fa-book"></i> Go to My Library
                    </a>
                    <a href="<?php echo SITE_URL; ?>/index.php" class="btn btn-outline">
                        <i class="fas fa-home"></i> Home
                    </a>
                </div>
            <?php endif; ?>

            <!-- Session Info -->
            <div class="session-info">
                <h3>💡 What to expect</h3>
                <ul>
                    <li><strong>Confirmation:</strong> Angella will confirm your session within 24 hours.</li>
                    <li><strong>Platform:</strong> Sessions are typically held via Zoom or Google Meet. Details will be shared upon confirmation.</li>
                    <li><strong>Duration:</strong> Choose a duration that works for you. Most sessions last 1 hour.</li>
                    <li><strong>Preparation:</strong> Share a brief message above to help Angella prepare.</li>
                    <li><strong>Rescheduling:</strong> To reschedule or cancel, please contact Angella directly.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.session-page {
    padding: 32px 0 60px;
}

.session-wrapper {
    max-width: 600px;
    margin: 0 auto;
}

.session-header {
    text-align: center;
    margin-bottom: 32px;
}
.session-header h1 {
    font-size: 2.4rem;
    margin-bottom: 8px;
}
.session-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

.session-form-container {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 32px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.session-form .form-group {
    margin-bottom: 20px;
}
.session-form label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: var(--text);
}
.session-form input,
.session-form select,
.session-form textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    background: var(--input-bg);
    color: var(--text);
    transition: border-color var(--transition), box-shadow var(--transition);
    font-family: inherit;
}
.session-form input:focus,
.session-form select:focus,
.session-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.session-form textarea {
    resize: vertical;
    min-height: 100px;
}
.field-hint {
    display: block;
    margin-top: 4px;
    font-size: 0.8rem;
    color: var(--text-light);
}

.session-form .btn-block {
    width: 100%;
    padding: 14px;
    font-size: 1.05rem;
    justify-content: center;
}

.booking-success-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 24px;
}

.session-info {
    background: var(--vanilla);
    border-radius: 12px;
    padding: 24px;
    margin-top: 32px;
}
.session-info h3 {
    font-size: 1.1rem;
    margin-bottom: 12px;
    color: var(--text);
}
.session-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.session-info ul li {
    padding: 6px 0;
    color: var(--text);
    font-size: 0.95rem;
    line-height: 1.6;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}
.session-info ul li:last-child {
    border-bottom: none;
}
.session-info ul li strong {
    color: var(--dark);
}

@media (max-width: 480px) {
    .session-form-container {
        padding: 20px;
    }
    .session-header h1 {
        font-size: 1.8rem;
    }
    .booking-success-actions {
        flex-direction: column;
    }
    .booking-success-actions .btn {
        width: 100%;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>