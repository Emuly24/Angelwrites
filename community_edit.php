<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can edit questions
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$question_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch the question
$stmt = $db->prepare("SELECT * FROM questions WHERE id = ? AND user_id = ?");
$stmt->execute([$question_id, $user_id]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    header('Location: ' . SITE_URL . '/community.php');
    exit;
}

// Only allow editing if no answers exist
$stmt = $db->prepare("SELECT COUNT(*) FROM answers WHERE question_id = ?");
$stmt->execute([$question_id]);
$answer_count = $stmt->fetchColumn();
if ($answer_count > 0) {
    $error = 'This question has answers and cannot be edited.';
}

// ===== HANDLE EDIT SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $title = trim($_POST['title']);
    $body = trim($_POST['body']);

    if (empty($title)) {
        $error = 'Please enter a question title.';
    } elseif (empty($body)) {
        $error = 'Please enter your question details.';
    } else {
        $stmt = $db->prepare("UPDATE questions SET title = ?, body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$title, $body, $question_id])) {
            $success = 'Your question has been updated!';
            
            // ===== SEND ADMIN NOTIFICATION =====
            $admin_email = 'angelwrites@zohomail.com';
            $subject = '📝 Question Edited';
            $body_msg = "A question has been edited by the user.\n\n";
            $body_msg .= "Question ID: $question_id\n";
            $body_msg .= "New Title: $title\n";
            $body_msg .= "New Body: $body\n\n";
            $body_msg .= "View question: " . SITE_URL . "/community.php?id=$question_id";
            sendEmail($admin_email, $subject, $body_msg, 'angelwrites@zohomail.com', 'AngelWrites Admin');
            
            header('Location: ' . SITE_URL . '/community.php?id=' . $question_id);
            exit;
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Edit Question';
?>
<?php require_once 'includes/header.php'; ?>

<div class="community-page">
    <div class="container">
        <div class="community-wrapper">
            <div class="community-header">
                <h1>Edit Question</h1>
                <p>Update your question below.</p>
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
                <div class="question-form-container">
                    <form method="POST" class="question-form">
                        <div class="form-group">
                            <label for="title">Question Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($question['title']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="body">Details <span class="required">*</span></label>
                            <textarea id="body" name="body" rows="5" required><?php echo htmlspecialchars($question['body']); ?></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Question
                            </button>
                            <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $question_id; ?>" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.community-page { padding: 32px 0 60px; }
.community-wrapper { max-width: 600px; margin: 0 auto; }
.community-header { text-align: center; margin-bottom: 32px; }
.community-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.community-header p { color: var(--text-light); font-size: 1.05rem; }
.question-form-container { background: var(--card-bg); border-radius: 16px; padding: 32px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.question-form .form-group { margin-bottom: 20px; }
.question-form label { display: block; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.question-form input, .question-form textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; background: var(--input-bg); color: var(--text); }
.question-form input:focus, .question-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15); }
.question-form textarea { resize: vertical; min-height: 100px; }
.question-form .form-actions { display: flex; gap: 12px; margin-top: 16px; }
.question-form .form-actions .btn { flex: 1; justify-content: center; padding: 12px; border-radius: 30px; }
@media (max-width: 480px) { .question-form-container { padding: 20px; } }
</style>

<?php require_once 'includes/footer.php'; ?>