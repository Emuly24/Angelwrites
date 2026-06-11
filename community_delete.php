<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

// Only logged-in users can delete questions
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$question_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirmed = isset($_GET['confirm']) ? (int)$_GET['confirm'] : 0;

// Fetch the question
$stmt = $db->prepare("SELECT * FROM questions WHERE id = ? AND user_id = ?");
$stmt->execute([$question_id, $user_id]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    header('Location: ' . SITE_URL . '/community.php');
    exit;
}

// Only allow deletion if no answers exist
$stmt = $db->prepare("SELECT COUNT(*) FROM answers WHERE question_id = ?");
$stmt->execute([$question_id]);
$answer_count = $stmt->fetchColumn();
if ($answer_count > 0) {
    header('Location: ' . SITE_URL . '/community.php?error=has_answers');
    exit;
}

if ($confirmed === 1) {
    // Delete the question
    $stmt = $db->prepare("DELETE FROM questions WHERE id = ?");
    if ($stmt->execute([$question_id])) {
        // ===== SEND ADMIN NOTIFICATION =====
        $admin_email = 'angelwrites@zohomail.com';
        $subject = '🗑️ Question Deleted';
        $body = "A question has been deleted by the user.\n\n";
        $body .= "Question ID: $question_id\n";
        $body .= "Title: " . $question['title'] . "\n";
        $body .= "User ID: $user_id\n\n";
        sendEmail($admin_email, $subject, $body, 'angelwrites@zohomail.com', 'AngelWrites Admin');
        
        header('Location: ' . SITE_URL . '/community.php?deleted=1');
        exit;
    } else {
        $error = 'Failed to delete question. Please try again.';
    }
}

$pageTitle = 'Delete Question';
?>
<?php require_once 'includes/header.php'; ?>

<div class="community-page">
    <div class="container">
        <div class="community-wrapper">
            <div class="community-header">
                <h1>Delete Question</h1>
                <p>Are you sure you want to delete this question?</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="question-details-card">
                <div class="question-detail">
                    <strong>Title:</strong> <?php echo htmlspecialchars($question['title']); ?>
                </div>
                <div class="question-detail">
                    <strong>Body:</strong> <?php echo htmlspecialchars(substr($question['body'], 0, 200)); ?>...
                </div>
                <div class="question-detail">
                    <strong>Posted:</strong> <?php echo date('M j, Y', strtotime($question['created_at'])); ?>
                </div>
            </div>

            <div class="delete-actions">
                <a href="<?php echo SITE_URL; ?>/community_delete.php?id=<?php echo $question_id; ?>&confirm=1" class="btn btn-danger btn-large" onclick="return confirm('Are you absolutely sure you want to delete this question?');">
                    <i class="fas fa-trash-alt"></i> Yes, Delete Question
                </a>
                <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $question_id; ?>" class="btn btn-outline btn-large">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.community-page { padding: 32px 0 60px; }
.community-wrapper { max-width: 600px; margin: 0 auto; }
.community-header { text-align: center; margin-bottom: 32px; }
.community-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.community-header p { color: var(--text-light); font-size: 1.05rem; }
.question-details-card { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.question-detail { padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 1rem; }
.question-detail:last-child { border-bottom: none; }
.question-detail strong { color: var(--text); }
.delete-actions { display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; }
.delete-actions .btn-large { padding: 14px 32px; font-size: 1.05rem; border-radius: 30px; }
.btn-danger { background: #e74c3c; color: white; transition: background 0.3s; }
.btn-danger:hover { background: #c0392b; }
@media (max-width: 480px) { .delete-actions { flex-direction: column; align-items: center; } .delete-actions .btn-large { width: 100%; } }
</style>

<?php require_once 'includes/footer.php'; ?>