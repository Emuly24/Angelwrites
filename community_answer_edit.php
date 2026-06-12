<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];
$answer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $db->prepare("SELECT * FROM answers WHERE id = ? AND user_id = ?");
$stmt->execute([$answer_id, $user_id]);
$answer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$answer) {
    header('Location: ' . SITE_URL . '/community.php');
    exit;
}

// Allow edit only within 24 hours (optional)
$time_limit = 24 * 3600;
if (time() - strtotime($answer['created_at']) > $time_limit) {
    $error = 'Answers can only be edited within 24 hours of posting.';
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    $body = trim($_POST['body']);
    if (empty($body)) {
        $error = 'Answer body cannot be empty.';
    } else {
        // Save edit history
        $stmt = $db->prepare("INSERT INTO answer_edit_history (answer_id, user_id, old_body, new_body) VALUES (?, ?, ?, ?)");
        $stmt->execute([$answer_id, $user_id, $answer['body'], $body]);
        
        // Update answer
        $stmt = $db->prepare("UPDATE answers SET body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$body, $answer_id]);
        
        $success = 'Answer updated successfully!';
    }
}

$pageTitle = 'Edit Answer';
?>
<?php require_once 'includes/header.php'; ?>

<div class="community-page">
    <div class="container">
        <div class="community-wrapper">
            <div class="community-header">
                <h1>Edit Answer</h1>
                <p>Update your answer below.</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="answer-form-container">
                <form method="POST">
                    <div class="form-group">
                        <textarea name="body" rows="6" required><?php echo htmlspecialchars($answer['body']); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Answer</button>
                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $answer['question_id']; ?>" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.answer-form-container { max-width: 600px; margin: 0 auto; }
.answer-form-container textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 120px; }
</style>
<?php require_once 'includes/footer.php'; ?>