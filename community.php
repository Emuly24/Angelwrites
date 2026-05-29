<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$error = '';
$success = '';
$user_id = isLoggedIn() ? $_SESSION['user_id'] : null;

// ===== HANDLE NEW QUESTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_question'])) {
    if (!$user_id) {
        $error = 'Please login to ask a question.';
    } else {
        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        
        if (empty($title)) {
            $error = 'Please enter a question title.';
        } elseif (empty($body)) {
            $error = 'Please enter your question details.';
        } else {
            $stmt = $db->prepare("INSERT INTO questions (user_id, title, body) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $title, $body])) {
                $success = 'Your question has been posted!';
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ===== HANDLE NEW ANSWER =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_answer'])) {
    if (!$user_id) {
        $error = 'Please login to answer.';
    } else {
        $question_id = (int)$_POST['question_id'];
        $body = trim($_POST['body']);
        
        if (empty($body)) {
            $error = 'Please write an answer.';
        } else {
            $stmt = $db->prepare("INSERT INTO answers (question_id, user_id, body) VALUES (?, ?, ?)");
            if ($stmt->execute([$question_id, $user_id, $body])) {
                // Increment answers count on question
                $stmt = $db->prepare("UPDATE questions SET answers_count = answers_count + 1 WHERE id = ?");
                $stmt->execute([$question_id]);
                $success = 'Your answer has been posted!';
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ===== HANDLE UPVOTE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upvote_answer'])) {
    if (!$user_id) {
        $error = 'Please login to upvote.';
    } else {
        $answer_id = (int)$_POST['answer_id'];
        $stmt = $db->prepare("UPDATE answers SET upvotes = upvotes + 1 WHERE id = ?");
        if ($stmt->execute([$answer_id])) {
            $success = 'Answer upvoted!';
        } else {
            $error = 'Failed to upvote.';
        }
    }
}

// ===== HANDLE NEWSLETTER SUBSCRIPTION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_newsletter'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $db->prepare("INSERT INTO newsletter (email) VALUES (?)");
            $stmt->execute([$email]);
            $success = 'You have been subscribed to the newsletter!';
        } catch (PDOException $e) {
            $error = 'This email is already subscribed.';
        }
    } else {
        $error = 'Please enter a valid email address.';
    }
}

// ===== FETCH ALL QUESTIONS =====
$stmt = $db->prepare("
    SELECT q.*, u.name AS author_name, u.avatar 
    FROM questions q
    JOIN users u ON q.user_id = u.id
    ORDER BY q.created_at DESC
");
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH SINGLE QUESTION WITH ANSWERS =====
$single_question = null;
$answers = [];
if (isset($_GET['id'])) {
    $qid = (int)$_GET['id'];
    $stmt = $db->prepare("
        SELECT q.*, u.name AS author_name, u.avatar 
        FROM questions q
        JOIN users u ON q.user_id = u.id
        WHERE q.id = ?
    ");
    $stmt->execute([$qid]);
    $single_question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($single_question) {
        // Increment view count
        $stmt = $db->prepare("UPDATE questions SET views = views + 1 WHERE id = ?");
        $stmt->execute([$qid]);
        
        // Fetch answers
        $stmt = $db->prepare("
            SELECT a.*, u.name AS author_name, u.avatar 
            FROM answers a
            JOIN users u ON a.user_id = u.id
            WHERE a.question_id = ?
            ORDER BY a.upvotes DESC, a.created_at ASC
        ");
        $stmt->execute([$qid]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = $single_question ? htmlspecialchars($single_question['title']) . ' — Q&A' : 'Community Q&A';
?>
<?php require_once 'includes/header.php'; ?>

<div class="community-page">
    <div class="container">
        <!-- Page Header -->
        <div class="community-header">
            <h1><?php echo $single_question ? 'Q&A' : 'Community Q&A'; ?></h1>
            <p><?php echo $single_question ? 'Read answers and join the conversation.' : 'Ask questions, share wisdom, and grow together.'; ?></p>
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

        <?php if ($single_question): ?>
            <!-- ===== SINGLE QUESTION VIEW ===== -->
            <div class="single-question">
                <div class="question-detail">
                    <h2><?php echo htmlspecialchars($single_question['title']); ?></h2>
                    <div class="question-meta">
                        <span class="author">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($single_question['author_name']); ?>
                        </span>
                        <span class="date">
                            <i class="fas fa-clock"></i>
                            <?php echo date('M j, Y', strtotime($single_question['created_at'])); ?>
                        </span>
                        <span class="views">
                            <i class="fas fa-eye"></i>
                            <?php echo number_format($single_question['views'] ?? 0); ?> views
                        </span>
                        <span class="answers-count">
                            <i class="fas fa-comments"></i>
                            <?php echo number_format($single_question['answers_count'] ?? 0); ?> answers
                        </span>
                    </div>
                    <div class="question-body">
                        <?php echo nl2br(htmlspecialchars($single_question['body'])); ?>
                    </div>
                </div>

                <!-- Answers Section -->
                <div class="answers-section">
                    <h3>Answers (<?php echo count($answers); ?>)</h3>
                    
                    <?php if (count($answers) > 0): ?>
                        <div class="answers-list">
                            <?php foreach ($answers as $answer): ?>
                                <div class="answer-item">
                                    <div class="answer-header">
                                        <span class="author">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars($answer['author_name']); ?>
                                        </span>
                                        <span class="date">
                                            <?php echo date('M j, Y', strtotime($answer['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="answer-body">
                                        <?php echo nl2br(htmlspecialchars($answer['body'])); ?>
                                    </div>
                                    <div class="answer-footer">
                                        <div class="upvote-section">
                                            <form method="POST" class="upvote-form">
                                                <input type="hidden" name="answer_id" value="<?php echo $answer['id']; ?>">
                                                <input type="hidden" name="upvote_answer" value="1">
                                                <button type="submit" class="upvote-btn" onclick="return confirm('Upvote this answer?');">
                                                    <i class="fas fa-thumbs-up"></i>
                                                    <span><?php echo number_format($answer['upvotes'] ?? 0); ?></span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No answers yet. Be the first to answer!</p>
                        </div>
                    <?php endif; ?>

                    <!-- Answer Form -->
                    <?php if ($user_id): ?>
                        <div class="answer-form-container">
                            <h4>Write an Answer</h4>
                            <form method="POST" class="answer-form">
                                <input type="hidden" name="question_id" value="<?php echo $single_question['id']; ?>">
                                <div class="form-group">
                                    <textarea name="body" rows="4" placeholder="Share your answer..." required></textarea>
                                </div>
                                <button type="submit" name="submit_answer" class="btn btn-primary">
                                    <i class="fas fa-pen"></i> Post Answer
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="login-prompt">
                            <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to answer this question.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="back-link-container">
                    <a href="<?php echo SITE_URL; ?>/community.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to all questions
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- ===== ALL QUESTIONS LIST ===== -->
            <div class="questions-layout">
                <!-- Main Questions List -->
                <div class="questions-main">
                    <!-- Ask Question Button -->
                    <?php if ($user_id): ?>
                        <div class="ask-question-container">
                            <button id="showAskForm" class="btn btn-primary">
                                <i class="fas fa-question-circle"></i> Ask a Question
                            </button>
                        </div>
                        
                        <!-- Ask Question Form (hidden) -->
                        <div class="ask-form-wrapper" id="askFormWrapper" style="display: none;">
                            <div class="card">
                                <div class="card-header">
                                    <h3>Ask a Question</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="ask-form">
                                        <div class="form-group">
                                            <label for="title">Question Title</label>
                                            <input type="text" id="title" name="title" placeholder="What would you like to know?" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="body">Details</label>
                                            <textarea id="body" name="body" rows="4" placeholder="Provide more details about your question..." required></textarea>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" name="submit_question" class="btn btn-primary">
                                                <i class="fas fa-paper-plane"></i> Post Question
                                            </button>
                                            <button type="button" id="cancelAskForm" class="btn btn-outline">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="login-prompt">
                            <p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to ask a question.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Questions List -->
                    <?php if (count($questions) > 0): ?>
                        <div class="questions-list">
                            <?php foreach ($questions as $q): ?>
                                <div class="question-card">
                                    <div class="question-card-header">
                                        <h3>
                                            <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>">
                                                <?php echo htmlspecialchars($q['title']); ?>
                                            </a>
                                        </h3>
                                        <span class="question-author">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars($q['author_name']); ?>
                                        </span>
                                    </div>
                                    <div class="question-card-body">
                                        <p><?php echo htmlspecialchars(substr($q['body'], 0, 150)); ?><?php if (strlen($q['body']) > 150) echo '...'; ?></p>
                                    </div>
                                    <div class="question-card-footer">
                                        <span class="q-meta">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('M j, Y', strtotime($q['created_at'])); ?>
                                        </span>
                                        <span class="q-meta">
                                            <i class="fas fa-eye"></i>
                                            <?php echo number_format($q['views'] ?? 0); ?>
                                        </span>
                                        <span class="q-meta">
                                            <i class="fas fa-comments"></i>
                                            <?php echo number_format($q['answers_count'] ?? 0); ?>
                                        </span>
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-reply"></i> Answer
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comments" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                            <h3>No Questions Yet</h3>
                            <p>Be the first to ask a question and start the conversation!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar: Newsletter Subscription -->
                <div class="community-sidebar">
                    <div class="card">
                        <div class="card-header">
                            <h4><i class="fas fa-envelope" style="color: var(--rose);"></i> Stay Updated</h4>
                        </div>
                        <div class="card-body">
                            <p>Subscribe to receive free email updates when new questions are answered.</p>
                            <form method="POST" class="sidebar-newsletter-form">
                                <div class="form-group">
                                    <input type="email" name="email" placeholder="Your email address" required>
                                </div>
                                <button type="submit" name="subscribe_newsletter" class="btn btn-primary btn-block">
                                    <i class="fas fa-paper-plane"></i> Subscribe Free
                                </button>
                            </form>
                            <small>No spam. Unsubscribe anytime.</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== STYLES ===== -->
<style>
.community-page {
    padding: 32px 0 60px;
}

.community-header {
    text-align: center;
    margin-bottom: 32px;
}
.community-header h1 {
    font-size: 2.4rem;
    margin-bottom: 4px;
}
.community-header p {
    color: var(--text-light);
    font-size: 1.05rem;
}

/* ===== Single Question View ===== */
.single-question {
    max-width: 800px;
    margin: 0 auto;
}

.question-detail {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    margin-bottom: 24px;
}
.question-detail h2 {
    font-size: 1.8rem;
    margin-bottom: 8px;
}
.question-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    color: var(--text-light);
    font-size: 0.9rem;
    margin-bottom: 16px;
}
.question-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.question-body {
    line-height: 1.7;
    color: var(--text);
}

.answers-section {
    margin-top: 24px;
}
.answers-section h3 {
    font-size: 1.4rem;
    margin-bottom: 16px;
}

.answers-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.answer-item {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
}
.answer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.answer-header .author {
    font-weight: 500;
}
.answer-header .date {
    font-size: 0.85rem;
    color: var(--text-light);
}
.answer-body {
    line-height: 1.7;
    color: var(--text);
    margin-bottom: 12px;
}
.answer-footer {
    display: flex;
    justify-content: flex-end;
}

.upvote-form {
    display: inline;
}
.upvote-btn {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 14px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--text);
}
.upvote-btn:hover {
    background: var(--rose);
    border-color: var(--rose);
    color: white;
}
.upvote-btn i {
    margin-right: 2px;
}

.answer-form-container {
    margin-top: 24px;
    background: var(--vanilla);
    border-radius: 12px;
    padding: 20px;
}
.answer-form-container h4 {
    margin-bottom: 12px;
}
.answer-form textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    resize: vertical;
    min-height: 80px;
    background: var(--input-bg);
    color: var(--text);
}
.answer-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}

.login-prompt {
    background: var(--vanilla);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    margin: 16px 0;
}
.login-prompt a {
    font-weight: 600;
}
.login-prompt a:hover {
    text-decoration: underline;
}

.back-link-container {
    margin-top: 24px;
}
.back-link {
    color: var(--text-light);
    transition: color var(--transition);
}
.back-link:hover {
    color: var(--rose);
}
.back-link i {
    margin-right: 6px;
}

/* ===== Questions List ===== */
.questions-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 32px;
}

.ask-question-container {
    margin-bottom: 20px;
}
.ask-form-wrapper {
    margin-bottom: 24px;
}
.ask-form .form-group {
    margin-bottom: 16px;
}
.ask-form input, .ask-form textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--input-bg);
    color: var(--text);
}
.ask-form input:focus, .ask-form textarea:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.ask-form textarea {
    resize: vertical;
    min-height: 80px;
}

.questions-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.question-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform var(--transition), box-shadow var(--transition);
}
.question-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}
.question-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
}
.question-card-header h3 {
    font-size: 1.15rem;
}
.question-card-header h3 a {
    color: var(--text);
    transition: color var(--transition);
}
.question-card-header h3 a:hover {
    color: var(--rose);
}
.question-author {
    font-size: 0.85rem;
    color: var(--text-light);
}
.question-card-body {
    color: var(--text-light);
    font-size: 0.95rem;
    margin-bottom: 12px;
    line-height: 1.5;
}
.question-card-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    font-size: 0.85rem;
    color: var(--text-light);
}
.question-card-footer .q-meta {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* ===== Sidebar ===== */
.community-sidebar .card {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.community-sidebar .card-header {
    background: var(--vanilla);
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}
.community-sidebar .card-header h4 {
    margin: 0;
    font-size: 1.05rem;
}
.community-sidebar .card-body {
    padding: 20px;
}
.sidebar-newsletter-form input {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 12px;
    background: var(--input-bg);
    color: var(--text);
}
.sidebar-newsletter-form input:focus {
    outline: none;
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(219, 161, 162, 0.15);
}
.sidebar-newsletter-form .btn-block {
    width: 100%;
}
.sidebar-newsletter-form small {
    display: block;
    text-align: center;
    margin-top: 8px;
    color: var(--text-light);
    font-size: 0.8rem;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-light);
}
.empty-state h3 {
    font-size: 1.3rem;
    margin-bottom: 6px;
}

@media (max-width: 768px) {
    .questions-layout {
        grid-template-columns: 1fr;
    }
    .community-sidebar {
        order: -1;
    }
    .question-card-header {
        flex-direction: column;
    }
    .question-card-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<!-- ===== INLINE JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const showAskBtn = document.getElementById('showAskForm');
    const askWrapper = document.getElementById('askFormWrapper');
    const cancelBtn = document.getElementById('cancelAskForm');

    if (showAskBtn) {
        showAskBtn.addEventListener('click', function() {
            askWrapper.style.display = 'block';
            showAskBtn.style.display = 'none';
            askWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            askWrapper.style.display = 'none';
            if (showAskBtn) showAskBtn.style.display = 'inline-block';
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>