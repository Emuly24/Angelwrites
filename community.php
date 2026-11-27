<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/site_texts.php'; // page text can be changed on the site
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/views.php'; // one way to count views
require_once 'includes/mail_helper.php';

require_once 'includes/csrf.php';
require_once 'includes/qa.php'; // answer discussions, following, helpful toggle, answer reactions
require_once 'includes/moderation.php';

$error = '';
$success = '';
$user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
$token = generate_csrf_token();

// Every change on this page carries the page token (none did; another site could post for a member).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aw_sent = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!validate_csrf_token($aw_sent)) {
        $aw_ajax = isset($_POST['accept_answer']) || isset($_POST['upvote_answer']) || isset($_POST['reaction_answer']) || isset($_POST['follow_question']);
        if ($aw_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'This page has expired. Please reload it and try again.']); exit; }
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}

// ===== answer discussions, following and answer reactions (includes/qa.php) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id && (isset($_POST['add_answer_comment']) || isset($_POST['qa_reply']))) {
    $r = aw_qa_add_comment($db, (int)($_POST['answer_id'] ?? 0), (int)$user_id, (string)($_POST['comment'] ?? ''), (int)($_POST['parent_id'] ?? 0));
    $qid_back = (int)($_POST['question_id'] ?? 0);
    if (!$r['ok']) $_SESSION['qa_flash'] = $r['message'];
    header('Location: ' . SITE_URL . '/community.php?id=' . $qid_back . ($r['ok'] ? '#ac' . (int)$r['id'] : '#answer-' . (int)($_POST['answer_id'] ?? 0)));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id && isset($_POST['delete_answer_comment'])) {
    aw_qa_delete_comment($db, (int)$_POST['delete_answer_comment'], (int)$user_id, function_exists('aw_cp_staff_mod') && aw_cp_staff_mod());
    header('Location: ' . SITE_URL . '/community.php?id=' . (int)($_POST['question_id'] ?? 0) . '#answers');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['follow_question'])) {
    header('Content-Type: application/json');
    if (!$user_id) { echo json_encode(['success' => false, 'error' => 'Please sign in to follow questions.']); exit; }
    $fq = (int)$_POST['follow_question'];
    $on = !aw_qa_following($db, $fq, (int)$user_id);
    $ok = aw_qa_follow($db, $fq, (int)$user_id, $on);
    echo json_encode(['success' => $ok, 'on' => $on, 'count' => aw_qa_follower_count($db, $fq), 'error' => $ok ? null : 'That did not work. Please try again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reaction_answer'])) {
    header('Content-Type: application/json');
    if (!$user_id) { echo json_encode(['success' => false, 'error' => 'Please sign in to react.']); exit; }
    $r = aw_qa_toggle_reaction($db, (int)($_POST['answer_id'] ?? 0), (int)$user_id, (string)($_POST['reaction_type'] ?? ''));
    echo json_encode(['success' => $r['ok']] + $r);
    exit;
}

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
                aw_qa_follow($db, (int)$question_id, (int)$user_id); // you hear about answers to your question
                
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
                $admin_email = aw_admin_email();
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

                // F114: tell the person who asked
                require_once __DIR__ . '/includes/notifications.php';
                $qstmt = $db->prepare("SELECT user_id, title FROM questions WHERE id = ?");
                $qstmt->execute([$question_id]);
                $qrow = $qstmt->fetch(PDO::FETCH_ASSOC);
                $qstmt->closeCursor();
                // N2: mentions in the answer
                require_once __DIR__ . '/includes/mentions.php';
                if ($qrow) aw_mentions_save($db, $body, 'answer', (int)$answer_id, 'the question “' . mb_substr($qrow['title'], 0, 80) . '”',
                    SITE_URL . '/community.php?id=' . $question_id, awDisplayName(['name' => $_SESSION['name'] ?? '', 'username' => $_SESSION['username'] ?? '']));
                aw_qa_follow($db, (int)$question_id, (int)$user_id); // answering follows the question
                if ($qrow) aw_qa_tell_followers($db, (int)$question_id, $qrow['title'], (int)$user_id, (int)$qrow['user_id']);
                if ($qrow && (int)$qrow['user_id'] !== (int)$user_id) {
                    awNotify($db, $qrow['user_id'], 'answer', 'New answer to your question',
                        'Someone answered "' . mb_substr($qrow['title'], 0, 80) . '".', SITE_URL . '/community.php?id=' . $question_id);
                }
                
                // Admin notification
                $admin_email = aw_admin_email();
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
                        $user_body = "Hello " . $author['name'] . ",\n\nYour question \"" . $question['title'] . "\" has received a new answer.\n\nAnswer: $body";
                        // ET4: conversational. The reply to an answer belongs under the question, so the
                        // button opens the question's own page.
                        sendConversationalEmail($author['email'], $user_subject, $user_body,
                            SITE_URL . '/community.php?id=' . (int)$question_id, 'View and reply', SITE_NAME . ' Community');
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
        echo json_encode(['success' => false, 'error' => 'Please sign in first.']);
        exit;
    }
    // F148 + toggle: one mark per member per answer (not on your own), and it can be taken back
    $r = aw_qa_toggle_helpful($db, (int)($_POST['answer_id'] ?? 0), (int)$user_id);
    echo json_encode(['success' => $r['ok']] + $r);
    exit;
}

// ================================================================
// 5. FETCH QUESTIONS
// ================================================================
$sql = "
    SELECT q.*, COALESCE(NULLIF(TRIM(u.name), ''), u.username) AS author_name, u.avatar 
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
        SELECT q.*, COALESCE(NULLIF(TRIM(u.name), ''), u.username) AS author_name, u.avatar 
        FROM questions q
        JOIN users u ON q.user_id = u.id
        WHERE q.id = ?
    ");
    $stmt->execute([$qid]);
    $single_question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($single_question) {
        aw_view_record($db, 'question', (int)$qid); // one way to count views (includes/views.php)
        
        // the reader chooses the order; the accepted answer always comes first
        $asort = in_array($_GET['asort'] ?? '', ['newest', 'oldest'], true) ? $_GET['asort'] : 'helpful';
        $aw_order = ['helpful' => 'a.upvotes DESC, a.created_at ASC', 'newest' => 'a.created_at DESC, a.id DESC', 'oldest' => 'a.created_at ASC, a.id ASC'][$asort];
        $stmt = $db->prepare("
            SELECT a.*, COALESCE(NULLIF(TRIM(u.name), ''), u.username) AS author_name, u.avatar
            FROM answers a
            JOIN users u ON a.user_id = u.id
            WHERE a.question_id = ?
            ORDER BY a.is_accepted DESC, $aw_order
        ");
        $stmt->execute([$qid]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // the discussion under each answer, reactions, and following
        $aw_aids = array_map('intval', array_column($answers, 'id'));
        $qa_comments = aw_qa_comments($db, $aw_aids);
        $qa_rx = aw_qa_reactions($db, $aw_aids, (int)$user_id);
        $qa_following = aw_qa_following($db, (int)$qid, (int)$user_id);
        $qa_followers = aw_qa_follower_count($db, (int)$qid);
        $qa_staff = function_exists('aw_cp_staff_mod') && aw_cp_staff_mod();
        $qa_flash = (string)($_SESSION['qa_flash'] ?? ''); unset($_SESSION['qa_flash']);

        // C3: which answers this member has already marked helpful (one mark each, F148)
        $my_helpful = [];
        if ($user_id && $answers) {
            try {
                $ids = array_map('intval', array_column($answers, 'id'));
                $st = $db->prepare("SELECT target_id FROM reactions WHERE user_id = ? AND target_type = 'answer' AND reaction_type = 'upvote' AND target_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
                $st->execute(array_merge([(int)$user_id], $ids));
                $my_helpful = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
            } catch (Throwable $e) {
                error_log('community: helpful marks: ' . $e->getMessage());
            }
        }
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
                    <?php echo aw_qa_assets(); ?>
                    <div class="qa-follow" style="margin-top:12px;">
                        <?php if ($user_id): ?>
                            <button type="button" class="btn btn-sm btn-outline<?php echo $qa_following ? ' active' : ''; ?>" id="qaFollow" data-q="<?php echo (int)$single_question['id']; ?>" aria-pressed="<?php echo $qa_following ? 'true' : 'false'; ?>"><i class="fas fa-bell" aria-hidden="true"></i> <span><?php echo $qa_following ? 'Following' : 'Follow'; ?></span></button>
                        <?php endif; ?>
                        <span class="field-hint" id="qaFollowers"><?php echo $qa_followers ? ($qa_followers === 1 ? '1 person follows this question' : $qa_followers . ' people follow this question') : ''; ?></span>
                    </div>
                    <?php if ($qa_flash !== ''): ?><div class="alert alert-error" role="alert" style="margin-top:12px;"><?php echo htmlspecialchars($qa_flash); ?></div><?php endif; ?>
                    <?php if ($user_id && (int)$single_question['user_id'] === (int)$user_id): // F149: the edit/delete pages existed but nothing linked to them ?>
                        <div class="question-owner-actions" style="margin-top:12px;display:flex;gap:8px;">
                            <a href="<?php echo SITE_URL; ?>/community_edit.php?id=<?php echo (int)$single_question['id']; ?>" class="btn btn-sm btn-outline">✏️ Edit question</a>
                            <a href="<?php echo SITE_URL; ?>/community_delete.php?id=<?php echo (int)$single_question['id']; ?>" class="btn btn-sm btn-danger">🗑️ Delete</a>
                        </div>
                    <?php endif; ?>
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
                <div class="answers-section" id="answers">
                    <div class="qa-answers-head"><h3>Answers (<?php echo count($answers); ?>)</h3>
                    <?php if (count($answers) > 1): ?><nav class="qa-sort" aria-label="Order of answers"><span>Show:</span>
                        <?php foreach (['helpful' => 'Most helpful', 'newest' => 'Newest', 'oldest' => 'Oldest'] as $k => $l): ?>
                            <a href="?id=<?php echo (int)$single_question['id']; ?>&amp;asort=<?php echo $k; ?>#answers"<?php echo $asort === $k ? ' aria-current="true"' : ''; ?>><?php echo $l; ?></a>
                        <?php endforeach; ?></nav><?php endif; ?></div>
                    
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
                                    <div class="answer-body"><?php require_once __DIR__ . '/includes/mentions.php'; echo aw_mentions_render($db, nl2br(htmlspecialchars($answer['body'])), 'answer', (int)$answer['id']); /* N2 */ ?></div>
                                    <div class="answer-footer">
                                        <?php // C3: "found this helpful", not a score; no totals or ranks
                                            $aw_h = (int)($answer['upvotes'] ?? 0); $aw_mine = isset($my_helpful[(int)$answer['id']]); $aw_own = $answer['user_id'] == $user_id; ?>
                                        <?php if ($user_id && !$aw_own): ?>
                                        <button type="button" class="upvote-btn<?php echo $aw_mine ? ' active' : ''; ?>" aria-pressed="<?php echo $aw_mine ? 'true' : 'false'; ?>"onclick="upvoteAnswer(<?php echo (int)$answer['id']; ?>, this)">
                                            <i class="fas fa-hands-helping" aria-hidden="true"></i> <span class="upvote-label"><?php echo $aw_mine ? 'You found this helpful' : 'This helped me'; ?></span>
                                        </button>
                                        <?php endif; ?>
                                        <span class="helpful-note" id="upvotes-<?php echo (int)$answer['id']; ?>" data-count="<?php echo $aw_h; ?>"><?php echo $aw_h > 0 ? ($aw_h === 1 ? '1 person found this helpful' : number_format($aw_h) . ' people found this helpful') : ''; ?></span>
                                        <?php $aw_rx = $qa_rx[(int)$answer['id']] ?? ['counts' => ['like' => 0, 'love' => 0, 'pray' => 0], 'mine' => []]; ?>
                                        <div class="qa-rx" role="group" aria-label="React to this answer" data-answer="<?php echo (int)$answer['id']; ?>">
                                            <?php foreach (['like' => ['👍', 'Like'], 'love' => ['❤️', 'Love'], 'pray' => ['🙏', 'Praying']] as $rk => [$em, $rl]): $on = in_array($rk, $aw_rx['mine'], true); ?>
                                                <button type="button" data-rx="<?php echo $rk; ?>" class="<?php echo $on ? 'active' : ''; ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>" aria-label="<?php echo $rl; ?>"><?php echo $em; ?> <span class="n"><?php echo (int)$aw_rx['counts'][$rk]; ?></span></button>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($user_id == $single_question['user_id'] && !$answer['is_accepted']): ?>
                                            <button class="btn btn-sm btn-success" onclick="acceptAnswer(<?php echo $answer['id']; ?>)">✓ Accept as Answer</button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline" onclick="showReportModal('answer', <?php echo $answer['id']; ?>)">🚩 Report</button>
                                    </div>
                                    <?php $aw_th = $qa_comments[(int)$answer['id']] ?? []; $aw_n = aw_qa_count($aw_th);
                                    // open when it has a few comments, or when a link points into it ?>
                                    <details class="qa-discuss"<?php echo ($aw_n && $aw_n <= 3) ? ' open' : ''; ?> data-answer="<?php echo (int)$answer['id']; ?>">
                                        <summary><i class="fas fa-comments" aria-hidden="true"></i> <?php echo $aw_n ? ($aw_n === 1 ? '1 comment' : $aw_n . ' comments') : 'Discuss this answer'; ?></summary>
                                        <?php if ($aw_th): ?><ul class="qa-c-list"><?php foreach ($aw_th as $c) echo aw_qa_render_comment($c, (int)$single_question['id'], (int)$user_id, $qa_staff, $token); ?></ul><?php endif; ?>
                                        <?php if ($user_id): ?>
                                            <form method="POST" class="qa-add">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                                                <input type="hidden" name="add_answer_comment" value="1"><input type="hidden" name="answer_id" value="<?php echo (int)$answer['id']; ?>"><input type="hidden" name="question_id" value="<?php echo (int)$single_question['id']; ?>">
                                                <label class="sr-only" for="qaNew<?php echo (int)$answer['id']; ?>">Comment on this answer</label>
                                                <textarea id="qaNew<?php echo (int)$answer['id']; ?>" name="comment" rows="1" maxlength="2000" required placeholder="Add a comment…"></textarea>
                                                <button type="submit" class="btn btn-sm btn-primary">Comment</button>
                                            </form>
                                        <?php else: ?><p class="field-hint"><a href="<?php echo SITE_URL; ?>/login.php?redirect=<?php echo urlencode(SITE_URL . '/community.php?id=' . (int)$single_question['id']); ?>">Sign in</a> to join the discussion.</p><?php endif; ?>
                                    </details>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><p><?php echo aw_text('community.p1', 'No answers yet. Be the first to answer!', 'text'); ?></p></div>
                    <?php endif; ?>

                    <!-- Answer Form -->
                    <?php if ($user_id): ?>
                        <div class="answer-form-container">
                            <h4><?php echo aw_text('community.h41', 'Write an Answer', 'line'); ?></h4>
                            <form method="POST" class="answer-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
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
                                <div class="card-header"><h3><?php echo aw_text('community.h31', 'Ask a Question', 'line'); ?></h3></div>
                                <div class="card-body">
                                    <form method="POST" class="ask-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
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
                            <h3><?php echo aw_text('community.h32', 'No Questions Yet', 'line'); ?></h3>
                            <p><?php echo aw_text('community.p2', 'Be the first to ask a question and start the conversation!', 'text'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="community-sidebar">
                    <?php // J1: this form posted to community.php, which never handled it; it is now the shared control
                        require_once 'includes/subscribe_control.php'; $aw_sub_html = aw_subscribe_control($db, 'sidebar'); ?>
                    <?php if ($aw_sub_html !== ''): ?>
                    <div class="card">
                        <div class="card-header"><h4><i class="fas fa-envelope" style="color: var(--rose);" aria-hidden="true"></i> Stay Updated</h4></div>
                        <div class="card-body"><?php echo aw_subscribe_assets(); echo $aw_sub_html; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== REPORT MODAL ===== -->
<div id="reportModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><?php echo aw_text('community.h33', '🚩 Report Content', 'line'); ?></h3>
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

    // every change carries the page token
    const AW_TOKEN = <?php echo json_encode($token); ?>, AW_URL = <?php echo json_encode(SITE_URL . '/community.php'); ?>;
    function post(fields) {
        const body = new URLSearchParams(fields); body.append('csrf_token', AW_TOKEN);
        return fetch(AW_URL, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(r => r.json().catch(() => ({ success: false, error: 'That did not work. Please try again.' })))
            .catch(() => ({ success: false, error: 'No connection. Please try again.' }));
    }

    // ===== "This helped me" (can be taken back) =====
    window.upvoteAnswer = function(answerId, btn) {
        post({ upvote_answer: 1, answer_id: answerId }).then(data => {
            if (!data.success) { alert(data.error || 'That did not work. Please try again.'); return; }
            const span = document.getElementById('upvotes-' + answerId), n = data.count;
            span.dataset.count = n;
            span.textContent = n > 0 ? (n === 1 ? '1 person found this helpful' : n + ' people found this helpful') : '';
            if (btn) { btn.classList.toggle('active', data.on); btn.setAttribute('aria-pressed', data.on ? 'true' : 'false'); const l = btn.querySelector('.upvote-label'); if (l) l.textContent = data.on ? 'You found this helpful' : 'This helped me'; }
        });
    };

    // ===== Accept Answer =====
    window.acceptAnswer = function(answerId) {
        if (!confirm('Mark this answer as the accepted answer?')) return;
        post({ accept_answer: 1, answer_id: answerId }).then(data => { if (data.success) location.reload(); else alert(data.error || 'Failed to accept answer.'); });
    };

    // ===== like / love / pray on answers =====
    document.querySelectorAll('.qa-rx').forEach(group => {
        group.addEventListener('click', e => {
            const b = e.target.closest('button[data-rx]'); if (!b || b.dataset.busy) return; b.dataset.busy = '1';
            post({ reaction_answer: 1, answer_id: group.dataset.answer, reaction_type: b.dataset.rx }).then(data => {
                delete b.dataset.busy;
                if (!data.success) { alert(data.error || 'That did not work. Please try again.'); return; }
                group.querySelectorAll('button[data-rx]').forEach(x => { const k = x.dataset.rx, on = (data.mine || []).includes(k); x.classList.toggle('active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); x.querySelector('.n').textContent = data.counts[k]; });
            });
        });
    });

    // ===== Follow a question =====
    const fb = document.getElementById('qaFollow');
    if (fb) fb.addEventListener('click', () => {
        post({ follow_question: fb.dataset.q }).then(data => {
            if (!data.success) { alert(data.error || 'That did not work. Please try again.'); return; }
            fb.classList.toggle('active', data.on); fb.setAttribute('aria-pressed', data.on ? 'true' : 'false'); fb.querySelector('span').textContent = data.on ? 'Following' : 'Follow';
            const f = document.getElementById('qaFollowers'); f.textContent = data.count ? (data.count === 1 ? '1 person follows this question' : data.count + ' people follow this question') : '';
        });
    });

    // a link to a comment opens its discussion
    if (location.hash.indexOf('#ac') === 0) { const t = document.querySelector(location.hash); if (t) { const d = t.closest('details'); if (d) d.open = true; t.scrollIntoView({ block: 'center' }); } }

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
    --rose: #94404a;
    --rose-dark: #94404a;
    --vanilla: #fdf5e6;
    --dark: #1a1212;
    --text-light: #6b5a5a;
    --input-bg: #fdf9f8;
    --card-bg: #ffffff;
    --border: #ece0de;
    --shadow: 0 4px 20px rgba(0,0,0,0.06);
    --shadow-hover: 0 12px 40px rgba(0,0,0,0.10);
    --bg: #fdf9f8;
}
body.dark-mode {
    --bg: #1a1212;
    --card-bg: #2c1e1e;
    --border: #4a3a3a;
    --text-light: #b8a8a8;
    --input-bg: #3d2e2e;
    --vanilla: #2c1e1e;
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
.upvote-btn.active, .upvote-btn:disabled { background: var(--vanilla, #EFD8D6); border-color: var(--rose); color: var(--text); cursor: default; }
.helpful-note { font-size: 0.85rem; color: var(--text-light); }
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
.empty-state { text-align: center; padding: 40px 20px; color: var(--text-light); }
.empty-state h3 { font-size: 1.3rem; margin-bottom: 6px; }

/* ===== MODAL ===== */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
.modal-content { background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #a08a8a; }
.modal-close:hover { color: #3d2e2e; }
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

<?php echo aw_report_assets(); /* Report and Block in the answer discussions */ ?>
<?php require_once 'includes/footer.php'; ?>