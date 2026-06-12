<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/mail_helper.php';

$error = '';
$success = '';
$user_id = isLoggedIn() ? $_SESSION['user_id'] : null;

// ===== PAGINATION, SEARCH, FILTER, SORT, TAG =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$tag_filter = isset($_GET['tag']) ? trim($_GET['tag']) : '';

// ================================================================
// 1. ANSWER ACCEPTANCE (AJAX)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_answer'])) {
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Please login to accept an answer.']);
        exit;
    }
    $answer_id = (int)$_POST['answer_id'];
    
    $stmt = $db->prepare("
        SELECT q.id, q.user_id FROM questions q
        JOIN answers a ON q.id = a.question_id
        WHERE a.id = ?
    ");
    $stmt->execute([$answer_id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        echo json_encode(['success' => false, 'error' => 'Answer not found.']);
        exit;
    }
    
    if ($question['user_id'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'Only the question author can accept answers.']);
        exit;
    }
    
    $stmt = $db->prepare("UPDATE answers SET is_accepted = 0 WHERE question_id = ?");
    $stmt->execute([$question['id']]);
    
    $stmt = $db->prepare("UPDATE answers SET is_accepted = 1 WHERE id = ?");
    $stmt->execute([$answer_id]);
    
    $stmt = $db->prepare("SELECT user_id FROM answers WHERE id = ?");
    $stmt->execute([$answer_id]);
    $answer_author = $stmt->fetchColumn();
    if ($answer_author) {
        awardReputation($answer_author, 15, 'Answer accepted');
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ================================================================
// 2. HANDLE NEW QUESTION
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_question'])) {
    if (!$user_id) {
        $error = 'Please login to ask a question.';
    } else {
        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        $tags = isset($_POST['tags']) ? trim($_POST['tags']) : '';
        
        if (empty($title)) {
            $error = 'Please enter a question title.';
        } elseif (empty($body)) {
            $error = 'Please enter your question details.';
        } else {
            $stmt = $db->prepare("INSERT INTO questions (user_id, title, body) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $title, $body])) {
                $question_id = $db->lastInsertId();
                $success = 'Your question has been posted!';
                
                // Process tags
                if (!empty($tags)) {
                    $tag_names = array_map('trim', explode(',', $tags));
                    foreach ($tag_names as $tag_name) {
                        if (!empty($tag_name)) {
                            $stmt = $db->prepare("INSERT OR IGNORE INTO tags (name) VALUES (?)");
                            $stmt->execute([$tag_name]);
                            $stmt = $db->prepare("SELECT id FROM tags WHERE name = ?");
                            $stmt->execute([$tag_name]);
                            $tag_id = $stmt->fetchColumn();
                            if ($tag_id) {
                                $stmt = $db->prepare("INSERT INTO question_tags (question_id, tag_id) VALUES (?, ?)");
                                $stmt->execute([$question_id, $tag_id]);
                            }
                        }
                    }
                }
                
                // Admin notification
                $admin_email = 'angelwrites@zohomail.com';
                $subject = 'New Community Question: ' . $title;
                $body_msg = "A new question has been posted.\n\nTitle: $title\nBody: $body\n\nView question: " . SITE_URL . "/community.php?id=" . $question_id;
                sendEmail($admin_email, $subject, $body_msg, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ================================================================
// 3. HANDLE NEW ANSWER
// ================================================================
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
                $answer_id = $db->lastInsertId();
                $stmt = $db->prepare("UPDATE questions SET answers_count = answers_count + 1 WHERE id = ?");
                $stmt->execute([$question_id]);
                $success = 'Your answer has been posted!';
                
                // Award reputation to answer author
                awardReputation($user_id, 5, 'Answered a question');
                
                // Admin notification
                $admin_email = 'angelwrites@zohomail.com';
                $subject = 'New Answer to Question #' . $question_id;
                $stmt = $db->prepare("SELECT title, user_id FROM questions WHERE id = ?");
                $stmt->execute([$question_id]);
                $question = $stmt->fetch(PDO::FETCH_ASSOC);
                $body_msg = "A new answer was posted.\n\nQuestion: " . $question['title'] . "\nAnswer: $body\n\nView question: " . SITE_URL . "/community.php?id=" . $question_id;
                sendEmail($admin_email, $subject, $body_msg, 'angelwrites@zohomail.com', SITE_NAME . ' Admin');
                
                // Notify question author if not same
                if ($question && $question['user_id'] != $user_id) {
                    $stmt = $db->prepare("SELECT email, name FROM users WHERE id = ?");
                    $stmt->execute([$question['user_id']]);
                    $author = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($author) {
                        $user_subject = 'Your question got an answer!';
                        $user_body = "Hello " . $author['name'] . ",\n\nYour question \"" . $question['title'] . "\" has received a new answer.\n\nAnswer: $body\n\nView answer: " . SITE_URL . "/community.php?id=" . $question_id;
                        sendEmail($author['email'], $user_subject, $user_body, 'angelwrites@zohomail.com', SITE_NAME . ' Community');
                    }
                }
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// ================================================================
// 4. HANDLE UPVOTE (AJAX)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upvote_answer'])) {
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Please login to upvote.']);
        exit;
    }
    $answer_id = (int)$_POST['answer_id'];
    $stmt = $db->prepare("UPDATE answers SET upvotes = upvotes + 1 WHERE id = ?");
    if ($stmt->execute([$answer_id])) {
        // Award reputation to answer author for upvotes
        $stmt = $db->prepare("SELECT user_id FROM answers WHERE id = ?");
        $stmt->execute([$answer_id]);
        $author_id = $stmt->fetchColumn();
        if ($author_id) {
            awardReputation($author_id, 1, 'Received an upvote');
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upvote.']);
    }
    exit;
}

// ================================================================
// 5. FETCH QUESTIONS
// ================================================================
$sql = "
    SELECT q.*, u.name AS author_name, u.avatar 
    FROM questions q
    JOIN users u ON q.user_id = u.id
    WHERE 1=1
";
$count_sql = "SELECT COUNT(*) FROM questions q WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (q.title LIKE ? OR q.body LIKE ?)";
    $count_sql .= " AND (q.title LIKE ? OR q.body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($tag_filter) {
    $sql .= " AND q.id IN (SELECT qt.question_id FROM question_tags qt JOIN tags t ON qt.tag_id = t.id WHERE t.name = ?)";
    $count_sql .= " AND q.id IN (SELECT qt.question_id FROM question_tags qt JOIN tags t ON qt.tag_id = t.id WHERE t.name = ?)";
    $params[] = $tag_filter;
}

if ($filter === 'answered') {
    $sql .= " AND q.is_answered = 1";
    $count_sql .= " AND q.is_answered = 1";
} elseif ($filter === 'unanswered') {
    $sql .= " AND q.is_answered = 0";
    $count_sql .= " AND q.is_answered = 0";
}

switch ($sort) {
    case 'oldest':
        $sql .= " ORDER BY q.created_at ASC";
        break;
    case 'most_views':
        $sql .= " ORDER BY q.views DESC";
        break;
    case 'most_answers':
        $sql .= " ORDER BY q.answers_count DESC";
        break;
    default:
        $sql .= " ORDER BY q.created_at DESC";
        break;
}

$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_questions = $stmt->fetchColumn();
$total_pages = ceil($total_questions / $limit);

$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================================================================
// 6. FETCH SINGLE QUESTION WITH ANSWERS
// ================================================================
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
        $stmt = $db->prepare("UPDATE questions SET views = views + 1 WHERE id = ?");
        $stmt->execute([$qid]);
        
        $stmt = $db->prepare("
            SELECT a.*, u.name AS author_name, u.avatar 
            FROM answers a
            JOIN users u ON a.user_id = u.id
            WHERE a.question_id = ?
            ORDER BY a.is_accepted DESC, a.upvotes DESC, a.created_at ASC
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
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($single_question): ?>
            <!-- ===== SINGLE QUESTION VIEW ===== -->
            <div class="single-question">
                <div class="question-detail">
                    <h2><?php echo htmlspecialchars($single_question['title']); ?></h2>
                    <div class="question-meta">
                        <span class="author"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($single_question['author_name']); ?></span>
                        <span class="date"><i class="fas fa-clock"></i> <?php echo date('M j, Y', strtotime($single_question['created_at'])); ?></span>
                        <span class="views"><i class="fas fa-eye"></i> <?php echo number_format($single_question['views'] ?? 0); ?></span>
                        <span class="answers-count"><i class="fas fa-comments"></i> <?php echo number_format($single_question['answers_count'] ?? 0); ?></span>
                    </div>
                    <div class="question-body"><?php echo nl2br(htmlspecialchars($single_question['body'])); ?></div>
                    <?php
                    $stmt = $db->prepare("
                        SELECT t.name FROM tags t
                        JOIN question_tags qt ON t.id = qt.tag_id
                        WHERE qt.question_id = ?
                    ");
                    $stmt->execute([$single_question['id']]);
                    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if ($tags): ?>
                        <div class="question-tags">
                            <?php foreach ($tags as $tag): ?>
                                <a href="<?php echo SITE_URL; ?>/community.php?tag=<?php echo urlencode($tag); ?>" class="tag">#<?php echo htmlspecialchars($tag); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Answers Section -->
                <div class="answers-section">
                    <h3>Answers (<?php echo count($answers); ?>)</h3>
                    
                    <?php if (count($answers) > 0): ?>
                        <div class="answers-list">
                            <?php foreach ($answers as $answer): ?>
                                <div class="answer-item <?php echo $answer['is_accepted'] ? 'accepted' : ''; ?>" id="answer-<?php echo $answer['id']; ?>">
                                    <?php if ($answer['is_accepted']): ?>
                                        <div class="accepted-badge">✓ Accepted Answer</div>
                                    <?php endif; ?>
                                    <div class="answer-header">
                                        <span class="author"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($answer['author_name']); ?></span>
                                        <span class="date"><?php echo date('M j, Y', strtotime($answer['created_at'])); ?></span>
                                        <?php if ($answer['user_id'] == $user_id): ?>
                                            <div class="answer-actions">
                                                <a href="<?php echo SITE_URL; ?>/community_answer_edit.php?id=<?php echo $answer['id']; ?>" class="btn btn-sm btn-outline">✏️ Edit</a>
                                                <a href="<?php echo SITE_URL; ?>/community_answer_delete.php?id=<?php echo $answer['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this answer?')">🗑️</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="answer-body"><?php echo nl2br(htmlspecialchars($answer['body'])); ?></div>
                                    <div class="answer-footer">
                                        <button class="upvote-btn" onclick="upvoteAnswer(<?php echo $answer['id']; ?>)">
                                            <i class="fas fa-thumbs-up"></i> <span id="upvotes-<?php echo $answer['id']; ?>"><?php echo number_format($answer['upvotes'] ?? 0); ?></span>
                                        </button>
                                        <div class="answer-reactions">
                                            <button onclick="reactAnswer(<?php echo $answer['id']; ?>, 'like')">👍</button>
                                            <button onclick="reactAnswer(<?php echo $answer['id']; ?>, 'love')">❤️</button>
                                            <button onclick="reactAnswer(<?php echo $answer['id']; ?>, 'pray')">🙏</button>
                                        </div>
                                        <?php if ($user_id == $single_question['user_id'] && !$answer['is_accepted']): ?>
                                            <button class="btn btn-sm btn-success" onclick="acceptAnswer(<?php echo $answer['id']; ?>)">✓ Accept as Answer</button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline" onclick="showReportModal('answer', <?php echo $answer['id']; ?>)">🚩 Report</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><p>No answers yet. Be the first to answer!</p></div>
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
                                <button type="submit" name="submit_answer" class="btn btn-primary"><i class="fas fa-pen"></i> Post Answer</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="login-prompt"><p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to answer this question.</p></div>
                    <?php endif; ?>
                </div>

                <div class="back-link-container">
                    <a href="<?php echo SITE_URL; ?>/community.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to all questions</a>
                </div>
            </div>

        <?php else: ?>
            <!-- ===== ALL QUESTIONS LIST ===== -->
            <div class="questions-layout">
                <div class="questions-main">
                    <!-- Search & Filter Bar -->
                    <div class="search-filter-bar">
                        <form method="GET" action="<?php echo SITE_URL; ?>/community.php" class="filter-form">
                            <input type="text" name="search" placeholder="Search questions..." value="<?php echo htmlspecialchars($search); ?>" class="search-input">
                            <input type="text" name="tag" placeholder="Filter by tag..." value="<?php echo htmlspecialchars($tag_filter); ?>" class="tag-input">
                            <select name="filter" class="filter-select">
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="answered" <?php echo $filter === 'answered' ? 'selected' : ''; ?>>Answered</option>
                                <option value="unanswered" <?php echo $filter === 'unanswered' ? 'selected' : ''; ?>>Unanswered</option>
                            </select>
                            <select name="sort" class="sort-select">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                                <option value="most_views" <?php echo $sort === 'most_views' ? 'selected' : ''; ?>>Most Views</option>
                                <option value="most_answers" <?php echo $sort === 'most_answers' ? 'selected' : ''; ?>>Most Answers</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                            <a href="<?php echo SITE_URL; ?>/community.php" class="btn btn-outline btn-sm">Clear</a>
                        </form>
                    </div>

                    <!-- Ask Question Button -->
                    <?php if ($user_id): ?>
                        <div class="ask-question-container">
                            <button id="showAskForm" class="btn btn-primary"><i class="fas fa-question-circle"></i> Ask a Question</button>
                        </div>
                        <div class="ask-form-wrapper" id="askFormWrapper" style="display: none;">
                            <div class="card">
                                <div class="card-header"><h3>Ask a Question</h3></div>
                                <div class="card-body">
                                    <form method="POST" class="ask-form">
                                        <div class="form-group">
                                            <input type="text" name="title" placeholder="Question title" required>
                                        </div>
                                        <div class="form-group">
                                            <textarea name="body" rows="4" placeholder="Details..." required></textarea>
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="tags" placeholder="Tags (comma separated, e.g., faith, prayer)">
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" name="submit_question" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post Question</button>
                                            <button type="button" id="cancelAskForm" class="btn btn-outline">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="login-prompt"><p><a href="<?php echo SITE_URL; ?>/login.php">Login</a> to ask a question.</p></div>
                    <?php endif; ?>

                    <!-- Questions List -->
                    <?php if (count($questions) > 0): ?>
                        <div class="questions-list">
                            <?php foreach ($questions as $q): ?>
                                <div class="question-card">
                                    <div class="question-card-header">
                                        <h3><a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>"><?php echo htmlspecialchars($q['title']); ?></a></h3>
                                        <span class="question-author"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($q['author_name']); ?></span>
                                    </div>
                                    <div class="question-card-body">
                                        <p><?php echo htmlspecialchars(substr($q['body'], 0, 150)); ?><?php if (strlen($q['body']) > 150) echo '...'; ?></p>
                                        <?php
                                        $stmt = $db->prepare("
                                            SELECT t.name FROM tags t
                                            JOIN question_tags qt ON t.id = qt.tag_id
                                            WHERE qt.question_id = ?
                                        ");
                                        $stmt->execute([$q['id']]);
                                        $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        if ($tags): ?>
                                            <div class="question-tags">
                                                <?php foreach ($tags as $tag): ?>
                                                    <a href="<?php echo SITE_URL; ?>/community.php?tag=<?php echo urlencode($tag); ?>" class="tag">#<?php echo htmlspecialchars($tag); ?></a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="question-card-footer">
                                        <span class="q-meta"><i class="fas fa-clock"></i> <?php echo date('M j, Y', strtotime($q['created_at'])); ?></span>
                                        <span class="q-meta"><i class="fas fa-eye"></i> <?php echo number_format($q['views'] ?? 0); ?></span>
                                        <span class="q-meta"><i class="fas fa-comments"></i> <?php echo number_format($q['answers_count'] ?? 0); ?></span>
                                        <a href="<?php echo SITE_URL; ?>/community.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-reply"></i> Answer</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo $filter; ?>&sort=<?php echo $sort; ?>&tag=<?php echo urlencode($tag_filter); ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo $filter; ?>&sort=<?php echo $sort; ?>&tag=<?php echo urlencode($tag_filter); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo $filter; ?>&sort=<?php echo $sort; ?>&tag=<?php echo urlencode($tag_filter); ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comments" style="font-size: 3rem; color: var(--rose); margin-bottom: 16px;"></i>
                            <h3>No Questions Yet</h3>
                            <p>Be the first to ask a question and start the conversation!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar: User Reputation & Newsletter -->
                <div class="community-sidebar">
                    <?php if ($user_id): ?>
                        <?php
                        $stmt = $db->prepare("SELECT points, level, badges FROM user_reputations WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $rep = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($rep):
                            $badges = json_decode($rep['badges'] ?? '[]', true);
                        ?>
                            <div class="card">
                                <div class="card-header"><h4>🏆 Your Reputation</h4></div>
                                <div class="card-body" style="text-align:center;">
                                    <div style="font-size:2rem; font-weight:700; color:var(--rose);"><?php echo $rep['points'] ?? 0; ?></div>
                                    <p>Level <?php echo $rep['level'] ?? 1; ?></p>
                                    <?php if ($badges): ?>
                                        <div class="badge-container">
                                            <?php foreach ($badges as $badge): ?>
                                                <span class="badge"><?php echo $badge; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header"><h4><i class="fas fa-envelope" style="color: var(--rose);"></i> Stay Updated</h4></div>
                        <div class="card-body">
                            <p>Subscribe to receive free email updates when new questions are answered.</p>
                            <form method="POST" class="sidebar-newsletter-form">
                                <div class="form-group">
                                    <input type="email" name="email" placeholder="Your email address" required>
                                </div>
                                <button type="submit" name="subscribe_newsletter" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Subscribe Free</button>
                            </form>
                            <small>No spam. Unsubscribe anytime.</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== REPORT MODAL ===== -->
<div id="reportModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>🚩 Report Content</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" name="target_type" id="report_target_type">
                <input type="hidden" name="target_id" id="report_target_id">
                <div class="form-group">
                    <label>Reason</label>
                    <select name="reason" id="report_reason" required>
                        <option value="">Select a reason</option>
                        <option value="spam">Spam</option>
                        <option value="offensive">Offensive or inappropriate</option>
                        <option value="misinformation">Misinformation</option>
                        <option value="duplicate">Duplicate</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Details (optional)</label>
                    <textarea name="details" id="report_details" rows="3" placeholder="Provide additional details..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit Report</button>
            </form>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== Toggle Ask Question Form =====
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

    // ===== Upvote Answer =====
    window.upvoteAnswer = function(answerId) {
        fetch('<?php echo SITE_URL; ?>/community.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'upvote_answer=1&answer_id=' + answerId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const span = document.getElementById('upvotes-' + answerId);
                let count = parseInt(span.textContent) || 0;
                span.textContent = count + 1;
            } else {
                alert(data.error || 'Failed to upvote.');
            }
        })
        .catch(() => alert('Failed to upvote.'));
    };

    // ===== Accept Answer =====
    window.acceptAnswer = function(answerId) {
        if (!confirm('Mark this answer as the accepted answer?')) return;
        fetch('<?php echo SITE_URL; ?>/community.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'accept_answer=1&answer_id=' + answerId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to accept answer.');
            }
        });
    };

    // ===== React to Answer =====
    window.reactAnswer = function(answerId, reactionType) {
        fetch('<?php echo SITE_URL; ?>/community.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'reaction_answer=1&answer_id=' + answerId + '&reaction_type=' + reactionType
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Reacted with ' + reactionType);
            } else {
                alert(data.error || 'Failed to react.');
            }
        })
        .catch(() => alert('Failed to react.'));
    };

    // ===== Report Modal =====
    window.showReportModal = function(targetType, targetId) {
        document.getElementById('report_target_type').value = targetType;
        document.getElementById('report_target_id').value = targetId;
        document.getElementById('reportModal').style.display = 'flex';
    };

    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });

    document.getElementById('reportForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('<?php echo SITE_URL; ?>/community_report.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Report submitted. Thank you for helping keep the community safe.');
                document.getElementById('reportModal').style.display = 'none';
            } else {
                alert(data.error || 'Failed to submit report.');
            }
        })
        .catch(() => alert('Failed to submit report.'));
    });

    // ===== Close modal on outside click =====
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
});
</script>

<style>
/* ===== DARK MODE SUPPORT ===== */
:root {
    --rose: #c0392b;
    --rose-dark: #a93226;
    --vanilla: #fdf5e6;
    --dark: #1a1a1a;
    --text-light: #666;
    --input-bg: #f9f9f9;
    --card-bg: #ffffff;
    --border: #e0e0e0;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdfdfd;
}
body.dark-mode {
    --bg: #1a1a1a;
    --card-bg: #2a2a2a;
    --border: #444;
    --text-light: #aaa;
    --input-bg: #333;
    --vanilla: #2a2a2a;
    --shadow: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text); transition: background 0.3s, color 0.3s; }

.community-page { padding: 32px 0 60px; }
.community-header { text-align: center; margin-bottom: 32px; }
.community-header h1 { font-size: 2.4rem; margin-bottom: 4px; }
.community-header p { color: var(--text-light); font-size: 1.05rem; }

/* ===== SEARCH & FILTER BAR ===== */
.search-filter-bar { margin-bottom: 20px; }
.filter-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.filter-form input, .filter-form select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.filter-form input:focus, .filter-form select:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.filter-form input { flex: 1; min-width: 160px; }
.filter-form .btn-sm { padding: 8px 16px; }

/* ===== PAGINATION ===== */
.pagination { display: flex; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 8px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); font-size: 0.9rem; transition: all 0.2s; min-width: 36px; text-decoration: none; }
.page-link:hover { border-color: var(--rose); }
.page-link.active { background: var(--rose); color: white; border-color: var(--rose); }

/* ===== SINGLE QUESTION ===== */
.single-question { max-width: 800px; margin: 0 auto; }
.question-detail { background: var(--card-bg); border-radius: 12px; padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 24px; }
.question-detail h2 { font-size: 1.8rem; margin-bottom: 8px; }
.question-meta { display: flex; flex-wrap: wrap; gap: 16px; color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px; }
.question-meta span { display: flex; align-items: center; gap: 4px; }
.question-body { line-height: 1.7; color: var(--text); }
.question-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.question-tags .tag { background: var(--vanilla); padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; color: var(--text); text-decoration: none; transition: background 0.2s, color 0.2s; }
.question-tags .tag:hover { background: var(--rose); color: white; }

/* ===== ANSWERS ===== */
.answers-section { margin-top: 24px; }
.answers-section h3 { font-size: 1.4rem; margin-bottom: 16px; }
.answers-list { display: flex; flex-direction: column; gap: 16px; }
.answer-item { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.answer-item.accepted { border-left: 4px solid var(--rose); background: rgba(192, 57, 43, 0.05); }
.accepted-badge { display: inline-block; background: var(--rose); color: white; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-bottom: 8px; }
.answer-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.answer-header .author { font-weight: 500; }
.answer-header .date { font-size: 0.85rem; color: var(--text-light); }
.answer-actions { display: flex; gap: 4px; }
.answer-body { line-height: 1.7; color: var(--text); margin-bottom: 12px; }
.answer-footer { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.upvote-btn { background: transparent; border: 1px solid var(--border); border-radius: 20px; padding: 4px 14px; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; color: var(--text); }
.upvote-btn:hover { background: var(--rose); border-color: var(--rose); color: white; }
.answer-reactions { display: flex; gap: 4px; }
.answer-reactions button { background: none; border: none; font-size: 1.2rem; cursor: pointer; transition: transform 0.2s; }
.answer-reactions button:hover { transform: scale(1.2); }
.answer-form-container { margin-top: 24px; background: var(--vanilla); border-radius: 12px; padding: 20px; }
.answer-form textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 80px; background: var(--input-bg); color: var(--text); }
.answer-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.login-prompt { background: var(--vanilla); border-radius: 12px; padding: 16px; text-align: center; margin: 16px 0; }
.login-prompt a { font-weight: 600; }
.back-link { color: var(--text-light); transition: color 0.2s; }
.back-link:hover { color: var(--rose); }
.back-link i { margin-right: 6px; }

/* ===== QUESTIONS LIST ===== */
.questions-layout { display: grid; grid-template-columns: 1fr 300px; gap: 32px; }
.ask-question-container { margin-bottom: 20px; }
.ask-form-wrapper .card { border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.ask-form-wrapper .card-header { background: var(--vanilla); padding: 14px 20px; border-bottom: 1px solid var(--border); }
.ask-form-wrapper .card-body { padding: 20px; }
.ask-form input, .ask-form textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--input-bg); color: var(--text); }
.ask-form input:focus, .ask-form textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.ask-form textarea { resize: vertical; min-height: 80px; }
.questions-list { display: flex; flex-direction: column; gap: 16px; }
.question-card { background: var(--card-bg); border-radius: 12px; padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); transition: transform 0.2s; }
.question-card:hover { transform: translateY(-2px); }
.question-card-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
.question-card-header h3 { font-size: 1.15rem; }
.question-card-header h3 a { color: var(--text); transition: color 0.2s; }
.question-card-header h3 a:hover { color: var(--rose); }
.question-author { font-size: 0.85rem; color: var(--text-light); }
.question-card-body { color: var(--text-light); font-size: 0.95rem; margin-bottom: 12px; line-height: 1.5; }
.question-card-footer { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 0.85rem; color: var(--text-light); }
.question-card-footer .q-meta { display: flex; align-items: center; gap: 4px; }

/* ===== SIDEBAR ===== */
.community-sidebar { display: flex; flex-direction: column; gap: 16px; }
.community-sidebar .card { background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; }
.community-sidebar .card-header { background: var(--vanilla); padding: 16px 20px; border-bottom: 1px solid var(--border); }
.community-sidebar .card-header h4 { margin: 0; font-size: 1.05rem; }
.community-sidebar .card-body { padding: 20px; }
.badge-container { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; }
.badge-container .badge { background: var(--rose); color: white; padding: 2px 10px; border-radius: 12px; font-size: 0.7rem; }
.sidebar-newsletter-form input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 12px; background: var(--input-bg); color: var(--text); }
.sidebar-newsletter-form input:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }
.sidebar-newsletter-form .btn-block { width: 100%; }
.sidebar-newsletter-form small { display: block; text-align: center; margin-top: 8px; color: var(--text-light); font-size: 0.8rem; }
.empty-state { text-align: center; padding: 40px 20px; color: var(--text-light); }
.empty-state h3 { font-size: 1.3rem; margin-bottom: 6px; }

/* ===== MODAL ===== */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
.modal-content { background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999; }
.modal-close:hover { color: #333; }
.modal-body .form-group { margin-bottom: 12px; }
.modal-body select, .modal-body textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--input-bg); color: var(--text); }
.modal-body select:focus, .modal-body textarea:focus { outline: none; border-color: var(--rose); box-shadow: 0 0 0 3px rgba(219,161,162,0.15); }

@media (max-width: 768px) {
    .questions-layout { grid-template-columns: 1fr; }
    .community-sidebar { order: -1; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form input, .filter-form select { width: 100%; }
}
</style>

<?php require_once 'includes/footer.php'; ?>