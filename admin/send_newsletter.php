<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/mail_helper.php';

// Only admin can access
if (!isAdmin()) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$message = '';
$error = '';
$subscribers = [];

// Fetch all active subscribers (table is 'newsletter', not 'subscribers')
$stmt = $db->query("SELECT id, email, name, unsubscribe_token FROM newsletter WHERE is_active = 1 ORDER BY subscribed_at DESC");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle sending newsletter
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject']);
    $content = trim($_POST['content']);
    $send_to = $_POST['send_to'] ?? 'all'; // 'all' or a specific email

    if (empty($subject) || empty($content)) {
        $error = 'Please fill in both subject and content.';
    } else {
        $sent_count = 0;
        $failed_count = 0;

        if ($send_to === 'all') {
            foreach ($subscribers as $sub) {
                $unsubscribe_link = SITE_URL . '/newsletter.php?unsubscribe=1&token=' . $sub['unsubscribe_token'];
                $full_message = $content . "\n\n---\nTo unsubscribe, click here: " . $unsubscribe_link;
                // Use sendEmail() from mail_helper.php (Zoho SMTP)
                if (sendEmail($sub['email'], $subject, $full_message, 'no-reply@angelwrites.gt.tc', SITE_NAME . ' Newsletter')) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
                // Rate limiting – sleep 0.5 seconds to avoid hitting Zoho limits
                usleep(500000);
            }
        } else {
            // Send to a single email
            $email = trim($_POST['single_email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $unsubscribe_link = SITE_URL . '/newsletter.php?unsubscribe=1&token=' . bin2hex(random_bytes(32));
                $full_message = $content . "\n\n---\nTo unsubscribe, click here: " . $unsubscribe_link;
                if (sendEmail($email, $subject, $full_message, 'no-reply@angelwrites.gt.tc', SITE_NAME . ' Newsletter')) {
                    $sent_count = 1;
                } else {
                    $failed_count = 1;
                }
            } else {
                $error = 'Invalid email address for single send.';
            }
        }

        if ($sent_count > 0) {
            $message = "Newsletter sent successfully! Sent: $sent_count, Failed: $failed_count.";
        } else {
            $error = 'Failed to send any emails. Please check your mail settings.';
        }
    }
}

$pageTitle = 'Send Newsletter';
?>
<?php require_once '../includes/header.php'; ?>

<div class="container" style="max-width: 800px; margin: 40px auto; padding: 0 20px;">
    <h1>📨 Send Newsletter</h1>
    <p>Total active subscribers: <strong><?php echo count($subscribers); ?></strong></p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="subject">Subject</label>
            <input type="text" id="subject" name="subject" required placeholder="e.g. New Book Release Announcement">
        </div>

        <div class="form-group">
            <label for="content">Message Content (plain text)</label>
            <textarea id="content" name="content" rows="8" required placeholder="Write your newsletter content here..."></textarea>
        </div>

        <div class="form-group">
            <label>Send to</label>
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 4px;">
                    <input type="radio" name="send_to" value="all" checked> All Subscribers (<?php echo count($subscribers); ?>)
                </label>
                <label style="display: flex; align-items: center; gap: 4px;">
                    <input type="radio" name="send_to" value="single"> Single Subscriber
                </label>
            </div>
        </div>

        <div class="form-group" id="singleEmailGroup" style="display: none;">
            <label for="single_email">Enter Subscriber Email</label>
            <input type="email" id="single_email" name="single_email" placeholder="subscriber@example.com">
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            <i class="fas fa-paper-plane"></i> Send Newsletter
        </button>
    </form>

    <hr style="margin: 32px 0;">

    <h3>Subscriber List</h3>
    <div style="background: var(--card-bg); border-radius: 8px; padding: 16px; border: 1px solid var(--border);">
        <?php if (count($subscribers) > 0): ?>
            <ul style="list-style: none; padding: 0;">
                <?php foreach ($subscribers as $sub): ?>
                    <li style="padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between;">
                        <span><?php echo htmlspecialchars($sub['email']); ?></span>
                        <small><?php echo htmlspecialchars($sub['name'] ?? 'No name'); ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-light);">No subscribers yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const radioAll = document.querySelector('input[name="send_to"][value="all"]');
    const radioSingle = document.querySelector('input[name="send_to"][value="single"]');
    const singleGroup = document.getElementById('singleEmailGroup');

    radioAll.addEventListener('change', function() {
        singleGroup.style.display = 'none';
    });
    radioSingle.addEventListener('change', function() {
        singleGroup.style.display = 'block';
    });
});
</script>

<style>
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: var(--input-bg); color: var(--text); }
.form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.btn-block { width: 100%; justify-content: center; padding: 12px; font-size: 1rem; }
</style>

<?php require_once '../includes/footer.php'; ?>