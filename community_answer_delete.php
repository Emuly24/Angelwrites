<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

redirectIfNotLoggedIn();
$user_id = $_SESSION['user_id'];
$answer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirmed = isset($_GET['confirm']) ? (int)$_GET['confirm'] : 0;

$stmt = $db->prepare("SELECT * FROM answers WHERE id = ? AND user_id = ?");
$stmt->execute([$answer_id, $user_id]);
$answer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$answer) {
    header('Location: ' . SITE_URL . '/community.php');
    exit;
}

if ($confirmed === 1) {
    // Delete edit history first
    $stmt = $db->prepare("DELETE FROM answer_edit_history WHERE answer_id = ?");
    $stmt->execute([$answer_id]);
    
    // Delete the answer
    $stmt = $db->prepare("DELETE FROM answers WHERE id = ?");
    $stmt->execute([$answer_id]);
    
    header('Location: ' . SITE_URL . '/community.php?id=' . $answer['question_id']);
    exit;
}

$pageTitle = 'Delete Answer';
?>
<?php require_once 'includes/header.php'; ?>

<div class="community-page">
    <div class="container">
        <div class="community-wrapper">
            <div class="community-header">
                <h1>Delete Answer</h1>
                <p>Are you sure you want to delete this answer?</p>
            </div>
            
            <div class="question-details-card">
                <div class="question-detail">
                    <strong>Answer:</strong> <?php echo htmlspecialchars(substr($answer['body'], 0, 200)); ?>...
                </div>
                <div class="question-detail">
                    <strong>Posted:</strong> <?php echo date('M j, Y', strtotime($answer['created_at'])); ?>
                </div>
                <div class="question-detail">
                    <strong>Upvotes:</strong> <?php echo number_format($answer['upvotes'] ?? 0); ?>
                </div>
            </div>
            
            <div class="delete-actions">
                <a href="<?php echo SITE_URL; ?>/community_answer_delete.php?id=<?php echo $answer_id; ?>&confirm=1" class="btn btn-danger btn-large" onclick="return confirm('Are you absolutely sure you want to delete this answer?');">
                    <i class="fas fa-trash-alt"></i> Yes, Delete Answer
                </a>
                <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $answer['question_id']; ?>" class="btn btn-outline btn-large">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.delete-actions { display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; }
.delete-actions .btn-large { padding: 14px 32px; font-size: 1.05rem; border-radius: 30px; }
.btn-danger { background: #e74c3c; color: white; }
.btn-danger:hover { background: #c0392b; }
@media (max-width: 480px) { .delete-actions { flex-direction: column; align-items: center; } }
</style>
<?php require_once 'includes/footer.php'; ?>