<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

// ===== HANDLE FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    // Validation
    if (empty($name)) {
        $error = 'Please enter your name.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($message)) {
        $error = 'Please enter your message.';
    } else {
        // Store in database
        $stmt = $db->prepare("
            INSERT INTO contact_messages (name, email, subject, message) 
            VALUES (?, ?, ?, ?)
        ");
        if ($stmt->execute([$name, $email, $subject, $message])) {
            // Send email notification to admin
            $to = 'admin@angelawrites.com'; // Change to Angella's email
            $email_subject = 'New Contact Message: ' . ($subject ?: 'No Subject');
            $email_body = "You have received a new message from your website.\n\n";
            $email_body .= "Name: $name\n";
            $email_body .= "Email: $email\n";
            $email_body .= "Subject: " . ($subject ?: 'No Subject') . "\n\n";
            $email_body .= "Message:\n$message\n\n";
            $email_body .= "---\n";
            $email_body .= "To manage this message, login to your admin panel.\n";
            $headers = "From: " . SITE_NAME . " <no-reply@angelawrites.com>";
            mail($to, $email_subject, $email_body, $headers);

            $success = 'Your message has been sent! Thank you for reaching out.';
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Contact';
?>
<?php require_once 'includes/header.php'; ?>

<div class="contact-page">
    <div class="container">
        <div class="contact-wrapper">
            <!-- Page Header -->
            <div class="contact-header">
                <h1>Get in Touch</h1>
                <p>Have a question, a prayer request, or just want to say hello? Reach out — Angella would love to hear from you.</p>
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

            <!-- Contact Layout -->
            <div class="contact-layout">
                <!-- Contact Form -->
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

                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>

                <!-- Contact Information -->
                <div class="contact-info">
                    <div class="info-card">
                        <h3><i class="fas fa-envelope" style="color: var(--rose);"></i> Email</h3>
                        <p><a href="mailto:admin@angelawrites.com">admin@angelawrites.com</a></p>
                    </div>

                    <div class="info-card">
                        <h3><i class="fas fa-map-marker-alt" style="color: var(--rose);"></i> Location</h3>
                        <p>Malawi</p>
                    </div>

                    <div class="info-card">
                        <h3><i class="fas fa-clock" style="color: var(--rose);"></i> Response Time</h3>
                        <p>Angella typically responds within 24–48 hours.</p>
                    </div>

                    <div class="info-card">
                        <h3><i class="fas fa-hands-praying" style="color: var(--rose);"></i> Prayer Requests</h3>
                        <p>If you have a prayer request, feel free to include it in your message — Angella would be honored to pray for you.</p>
                    </div>

                    <div class="info-card social-links">
                        <h3><i class="fas fa-share-alt" style="color: var(--rose);"></i> Connect</h3>
                        <div class="social-icons">
                            <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                            <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.contact-page {
    padding: 32px 0 60px;
}

.contact-wrapper {
    max-width: 900px;
    margin: 0 auto;
}

.contact-header {
    text-align: center;
    margin-bottom: 32px;
}
.contact-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.contact-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

/* ===== Contact Layout ===== */
.contact-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
}

/* ===== Contact Form ===== */
.contact-form-container {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 32px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.contact-form .form-group {
    margin-bottom: 20px;
}
.contact-form label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: var(--text);
}
.contact-form input,
.contact-form textarea {
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
.contact-form input:focus,
.contact-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.contact-form textarea {
    resize: vertical;
    min-height: 120px;
}
.required {
    color: #e74c3c;
}

.contact-form .btn-block {
    width: 100%;
    padding: 14px;
    font-size: 1.05rem;
    justify-content: center;
}

/* ===== Contact Info ===== */
.contact-info {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.info-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.info-card h3 {
    font-size: 1.05rem;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-card h3 i {
    font-size: 1.2rem;
}
.info-card p {
    color: var(--text);
    line-height: 1.6;
    font-size: 0.95rem;
}
.info-card a {
    color: var(--rose);
    transition: color var(--transition);
}
.info-card a:hover {
    color: var(--rose-dark);
}

.social-icons {
    display: flex;
    gap: 12px;
    margin-top: 4px;
}
.social-icons a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--vanilla);
    color: var(--text);
    font-size: 1rem;
    transition: all var(--transition);
}
.social-icons a:hover {
    background: var(--rose);
    color: white;
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .contact-layout {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .contact-info {
        order: -1;
    }
    .contact-form-container {
        padding: 20px;
    }
    .info-card {
        padding: 16px 20px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>