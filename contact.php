<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

$error = '';
$success = '';

// ===== RATE LIMITING =====
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

    // ===== SIMPLE MATH CAPTCHA =====
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
            // ===== ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $admin_subject = '📩 New Contact Message: ' . ($subject ?: 'No Subject');
            $admin_body = "You have received a new message.\n\n";
            $admin_body .= "Name: $name\n";
            $admin_body .= "Email: $email\n";
            $admin_body .= "Subject: " . ($subject ?: 'No Subject') . "\n\n";
            $admin_body .= "Message:\n$message\n\n";
            $admin_body .= "---\n";
            $admin_body .= "To manage this message, login to your admin panel.\n";
            sendEmail($admin_email, $admin_subject, $admin_body, 'angelwrites@zohomail.com', 'AngelWrites', false);
            
            // ===== USER CONFIRMATION EMAIL =====
            $user_subject = "Thank you for contacting AngelWrites";
            $user_body = "Hello $name,\n\nThank you for reaching out. We have received your message and will respond as soon as possible.\n\nBlessings,\nAngella Bottoman\nAngelWrites";
            sendEmail($email, $user_subject, $user_body, 'angelwrites@zohomail.com', 'AngelWrites', false);
            
            $success = 'Your message has been sent! Thank you for reaching out.';
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
        <!-- ===== DARK MODE TOGGLE ===== -->
        <button id="themeToggle" class="btn btn-sm btn-outline" onclick="toggleTheme()" style="position:fixed;bottom:20px;right:20px;z-index:1000;">
            <i class="fas fa-moon"></i>
        </button>

        <!-- ===== HERO / PAGE HEADER ===== -->
        <div class="contact-hero">
            <h1>Get in Touch</h1>
            <p>Have a question, a prayer request, or just want to say hello? Reach out — Angella would love to hear from you.</p>
            <div class="hero-divider"></div>
        </div>

        <!-- ===== ALERT MESSAGES ===== -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ===== CONTENT LAYOUT ===== -->
        <div class="contact-layout">
            <!-- ===== FORM ===== -->
            <div class="contact-form-container">
                <form method="POST" class="contact-form" id="contactForm">
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
                    
                    <!-- ===== MATH CAPTCHA ===== -->
                    <div class="form-group captcha-group">
                        <label for="captcha_result">Verify you are human</label>
                        <div class="captcha-wrapper">
                            <span class="captcha-question"><?php echo $captcha_num1; ?> + <?php echo $captcha_num2; ?> =</span>
                            <input type="number" id="captcha_result" name="captcha_result" placeholder="?" required>
                            <input type="hidden" name="captcha_expected" value="<?php echo $captcha_expected; ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>

            <!-- ===== INFO CARDS ===== -->
            <div class="contact-info">
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div>
                        <h3>Email</h3>
                        <p><a href="mailto:angelwrites@zohomail.com">angelwrites@zohomail.com</a></p>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div>
                        <h3>Location</h3>
                        <p>Malawi</p>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-icon"><i class="fas fa-clock"></i></div>
                    <div>
                        <h3>Response Time</h3>
                        <p>Angella typically responds within 24–48 hours.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== THEME TOGGLE =====
    const themeToggle = document.getElementById('themeToggle');
    const currentTheme = localStorage.getItem('contactTheme') || 'light';
    if (currentTheme === 'dark') {
        document.body.classList.add('dark-mode');
        themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
    }

    window.toggleTheme = function() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('contactTheme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    };
});
</script>

<!-- ===== STYLES ===== -->
<style>
/* ===== BRAND VARIABLES ===== */
:root {
    --rose: #DBA1A2;
    --rose-dark: #c08a8b;
    --rose-light: #e8c0c0;
    --vanilla: #EFD8D6;
    --fantasy: #F7F3ED;
    --white: #ffffff;
    --dark: #2c1e1e;
    --text: #3d2e2e;
    --text-light: #6b5a5a;
    --bg: #F7F3ED;
    --card-bg: #ffffff;
    --border: #e5d5d5;
    --shadow: 0 4px 16px rgba(44, 30, 30, 0.08);
    --shadow-hover: 0 8px 30px rgba(44, 30, 30, 0.15);
    --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); transition: background var(--transition), color var(--transition); }

/* ===== DARK MODE ===== */
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --text: #e8dddd;
    --text-light: #a08a8a;
    --border: #4a3a3a;
    --vanilla: #2c1e1e;
    --shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
    --shadow-hover: 0 12px 40px rgba(0, 0, 0, 0.5);
}

/* ===== TYPOGRAPHY ===== */
h1, h2, h3, h4 { font-family: 'Playfair Display', Georgia, serif; color: var(--dark); line-height: 1.3; }
p { line-height: 1.6; }
.rose-text { color: var(--rose); }

/* ===== BUTTONS (Unified) ===== */
.btn {
    display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px;
    border-radius: 50px; font-weight: 700; font-size: 0.95rem; border: none;
    cursor: pointer; text-decoration: none; transition: all var(--transition);
    box-shadow: 0 3px 10px rgba(44, 30, 30, 0.12); letter-spacing: 0.3px;
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
.btn-primary { background: var(--rose); color: var(--white); border: 2px solid var(--rose); }
.btn-primary:hover { background: var(--rose-dark); border-color: var(--rose-dark); }
.btn-secondary { background: var(--vanilla); color: var(--dark); border: 2px solid var(--vanilla); }
.btn-secondary:hover { background: var(--rose-light); border-color: var(--rose-light); }
.btn-outline { background: transparent; border: 2px solid var(--rose); color: var(--rose); }
.btn-outline:hover { background: var(--rose); color: var(--white); }
.btn-block { width: 100%; justify-content: center; }
.btn-sm { padding: 8px 20px; font-size: 0.85rem; }

/* ===== PAGE ===== */
.contact-page { padding: 40px 0 80px; }

/* ===== HERO ===== */
.contact-hero {
    text-align: center; margin-bottom: 32px; padding: 20px 0;
}
.contact-hero h1 { font-size: 2.8rem; margin-bottom: 8px; }
.contact-hero p { font-size: 1.15rem; color: var(--text-light); max-width: 600px; margin: 0 auto; }
.hero-divider { width: 60px; height: 3px; background: var(--rose); margin: 16px auto 0; border-radius: 4px; }

/* ===== ALERTS ===== */
.alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; font-weight: 500; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

/* ===== LAYOUT ===== */
.contact-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; max-width: 1000px; margin: 0 auto; }

/* ===== FORM ===== */
.contact-form-container {
    background: var(--card-bg); border-radius: 20px; padding: 32px;
    border: 1px solid var(--border); box-shadow: var(--shadow); transition: all var(--transition);
}
.contact-form-container:hover { box-shadow: var(--shadow-hover); }

.contact-form .form-group { margin-bottom: 16px; }
.contact-form label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; color: var(--text); }
.contact-form .required { color: #dc3545; }
.contact-form input, .contact-form textarea {
    width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px;
    font-size: 0.95rem; background: var(--input-bg); color: var(--text); transition: border-color 0.2s;
}
.contact-form input:focus, .contact-form textarea:focus {
    outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.contact-form textarea { resize: vertical; min-height: 120px; font-family: 'Inter', sans-serif; }

/* ===== CAPTCHA ===== */
.captcha-group { margin-top: 8px; }
.captcha-wrapper { display: flex; align-items: center; gap: 10px; }
.captcha-question { font-weight: 600; font-size: 1rem; color: var(--text); }
.captcha-wrapper input[type="number"] {
    width: 80px; padding: 8px 12px; text-align: center;
    border: 1px solid var(--border); border-radius: 12px;
    background: var(--input-bg); color: var(--text); font-size: 0.95rem;
}
.captcha-wrapper input[type="number"]:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }

/* ===== INFO CARDS ===== */
.contact-info { display: flex; flex-direction: column; gap: 16px; }
.info-card {
    background: var(--card-bg); border-radius: 16px; padding: 20px 24px;
    border: 1px solid var(--border); box-shadow: var(--shadow); transition: all var(--transition);
    display: flex; align-items: center; gap: 16px;
}
.info-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: var(--rose-light); }
.info-icon {
    width: 48px; height: 48px; border-radius: 50%; background: rgba(219, 161, 162, 0.12);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.info-icon i { font-size: 1.2rem; color: var(--rose); }
.info-card h3 { font-size: 1.1rem; margin: 0 0 2px; }
.info-card p { margin: 0; font-size: 0.95rem; color: var(--text-light); }
.info-card a { color: var(--rose); text-decoration: none; transition: color 0.2s; }
.info-card a:hover { color: var(--rose-dark); text-decoration: underline; }

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .contact-hero h1 { font-size: 2.2rem; }
}
@media (max-width: 768px) {
    .contact-layout { grid-template-columns: 1fr; gap: 24px; }
    .contact-info { order: -1; }
    .contact-form-container { padding: 24px; }
    .info-card { padding: 16px 20px; }
    .info-icon { width: 40px; height: 40px; }
    .info-icon i { font-size: 1rem; }
}
@media (max-width: 480px) {
    .contact-hero h1 { font-size: 1.8rem; }
    .captcha-wrapper { flex-wrap: wrap; }
}
</style>

<?php require_once 'includes/footer.php'; ?>