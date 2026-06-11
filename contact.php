<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

$error = '';
$success = '';

// ===== RATE LIMITING (simple) =====
$ip = $_SERVER['REMOTE_ADDR'];
$limit_key = 'contact_submit_' . $ip;
$limit_file = sys_get_temp_dir() . '/' . $limit_key . '.tmp';
if (file_exists($limit_file) && (time() - filemtime($limit_file) < 300)) {
    $error = 'Please wait 5 minutes before sending another message.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    // ===== SIMPLE MATH CAPTCHA (optional) =====
    $captcha_result = isset($_POST['captcha_result']) ? (int)$_POST['captcha_result'] : 0;
    $captcha_expected = isset($_POST['captcha_expected']) ? (int)$_POST['captcha_expected'] : 0;
    if ($captcha_result !== $captcha_expected) {
        $error = 'Please solve the math puzzle correctly.';
    }

    if (empty($name)) {
        $error = 'Please enter your name.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($message)) {
        $error = 'Please enter your message.';
    } else {
        $stmt = $db->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $subject, $message])) {
            // ===== SEND ADMIN NOTIFICATION VIA ZOHO SMTP =====
            $admin_email = 'angelwrites@zohomail.com';
            $admin_subject = '📩 New Contact Message: ' . ($subject ?: 'No Subject');
            $admin_body = "You have received a new message from your website.\n\n";
            $admin_body .= "Name: $name\n";
            $admin_body .= "Email: $email\n";
            $admin_body .= "Subject: " . ($subject ?: 'No Subject') . "\n\n";
            $admin_body .= "Message:\n$message\n\n";
            $admin_body .= "---\n";
            $admin_body .= "To manage this message, login to your admin panel.\n";

            $emailSent = sendEmail($admin_email, $admin_subject, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites', false);
            
            // ===== SEND CONFIRMATION EMAIL TO USER (optional) =====
            $user_subject = "Thank you for contacting AngelWrites";
            $user_body = "Hello $name,\n\nThank you for reaching out to AngelWrites. We have received your message and will respond as soon as possible.\n\nHere's a copy of your message for your records:\n\n";
            $user_body .= "Subject: " . ($subject ?: 'No Subject') . "\n";
            $user_body .= "Message:\n$message\n\n";
            $user_body .= "Blessings,\nAngella Bottoman\nAngelWrites";
            $userEmailSent = sendEmail($email, $user_subject, $user_body, 'angelwrites@zohomail.com', 'AngelWrites', false);
            
            if ($emailSent) {
                $success = 'Your message has been sent! Thank you for reaching out.';
            } else {
                $success = 'Your message was saved but email notification could not be sent. We\'ll review it soon.';
            }
            
            // ===== SET RATE LIMIT =====
            file_put_contents($limit_file, time());
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
}

// Generate random numbers for captcha
$captcha_num1 = random_int(1, 10);
$captcha_num2 = random_int(1, 10);
$captcha_expected = $captcha_num1 + $captcha_num2;

$pageTitle = 'Contact';
?>
<?php require_once 'includes/header.php'; ?>

<div class="contact-page">
    <div class="container">
        <div class="contact-wrapper">
            <div class="contact-header">
                <h1>Get in Touch</h1>
                <p>Have a question, a prayer request, or just want to say hello? Reach out — Angella would love to hear from you.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="contact-layout">
                <div class="contact-form-container">
                    <form method="POST" class="contact-form">
                        <div class="form-group">
                            <label for="name">Your Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" placeholder="Enter your name" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input type="email" id="email" name="email" placeholder="you@example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input type="text" id="subject" name="subject" placeholder="What's this about?">
                        </div>
                        <div class="form-group">
                            <label for="message">Message <span class="required">*</span></label>
                            <textarea id="message" name="message" rows="5" placeholder="Write your message here..." required></textarea>
                        </div>
                        
                        <!-- ===== ADDED: Simple Math CAPTCHA ===== -->
                        <div class="form-group captcha-group">
                            <label for="captcha_result">Verify you are human</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span><?php echo $captcha_num1; ?> + <?php echo $captcha_num2; ?> = </span>
                                <input type="number" id="captcha_result" name="captcha_result" required style="width: 80px;">
                                <input type="hidden" name="captcha_expected" value="<?php echo $captcha_expected; ?>">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>
                <div class="contact-info">
                    <div class="info-card">
                        <h3><i class="fas fa-envelope" style="color: var(--rose);"></i> Email</h3>
                        <p><a href="mailto:angelwrites@zohomail.com">angelwrites@zohomail.com</a></p>
                    </div>
                    <div class="info-card">
                        <h3><i class="fas fa-map-marker-alt" style="color: var(--rose);"></i> Location</h3>
                        <p>Malawi</p>
                    </div>
                    <div class="info-card">
                        <h3><i class="fas fa-clock" style="color: var(--rose);"></i> Response Time</h3>
                        <p>Angella typically responds within 24–48 hours.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== ORIGINAL STYLES (PRESERVED) ===== */
.contact-page { padding: 32px 0 60px; }
.contact-wrapper { max-width: 900px; margin: 0 auto; }
.contact-header { text-align: center; margin-bottom: 32px; }
.contact-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.contact-header p { color: var(--text-light); font-size: 1.05rem; }
.contact-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
.contact-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.contact-form .form-group { margin-bottom: 20px; }
.contact-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.contact-form input, .contact-form textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; background: var(--input-bg); color: var(--text); transition: border-color var(--transition), box-shadow var(--transition); font-family: inherit; }
.contact-form input:focus, .contact-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.contact-form textarea { resize: vertical; min-height: 120px; }
.required { color: #e74c3c; }
.contact-form .btn-block { width: 100%; padding: 14px; font-size: 1.05rem; justify-content: center; }

.contact-info { display: flex; flex-direction: column; gap: 16px; }
.info-card { background: var(--card-bg); border-radius: 12px; padding: 20px 24px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.info-card h3 { font-size: 1.05rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.info-card h3 i { font-size: 1.2rem; }
.info-card p { color: var(--text); line-height: 1.6; font-size: 0.95rem; }
.info-card a { color: var(--rose); transition: color var(--transition); }
.info-card a:hover { color: var(--rose-dark); }

@media (max-width: 768px) {
    .contact-layout { grid-template-columns: 1fr; gap: 24px; }
    .contact-info { order: -1; }
    .contact-form-container { padding: 20px; }
    .info-card { padding: 16px 20px; }
}

/* ===== ADDED: CAPTCHA STYLES ===== */
.captcha-group { margin-top: 4px; }
.captcha-group input[type="number"] { width: 80px; text-align: center; }
.captcha-group input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; }
</style>

<?php require_once 'includes/footer.php'; ?>