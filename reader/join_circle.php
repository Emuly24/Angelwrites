<?php
// F11: reading-circle invitation emails link here (reader_circles.php invite_member), but the
// page did not exist, so every invite was a 404. It accepts a pending invite addressed to the
// logged-in member's email, adds them to the book's circle and opens the book in the reader.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

redirectIfNotLoggedIn(); // returns here after login (F103)
$user_id = (int)$_SESSION['user_id'];
$token = trim((string)($_GET['token'] ?? ''));
$message = '';

$stmt = $db->prepare("SELECT id, book_id, email, status FROM reading_circle_invites WHERE token = ?");
$stmt->execute([$token]);
$invite = $token !== '' ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
$stmt->closeCursor();

$me = $db->prepare("SELECT email FROM users WHERE id = ?");
$me->execute([$user_id]);
$my_email = (string)$me->fetchColumn();
$me->closeCursor();

if (!$invite) {
    $message = 'This invitation link is not valid.';
} elseif ($invite['status'] !== 'pending') {
    $message = 'This invitation has already been used.';
} elseif (strcasecmp($invite['email'], $my_email) !== 0) {
    $message = 'This invitation was sent to a different email address. Log in with the account for ' . $invite['email'] . ' to accept it.';
} else {
    $in = $db->prepare("SELECT 1 FROM reading_circles WHERE book_id = ? AND user_id = ?");
    $in->execute([$invite['book_id'], $user_id]);
    $already = $in->fetchColumn();
    $in->closeCursor();
    if (!$already) {
        $db->prepare("INSERT INTO reading_circles (book_id, user_id, joined_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
           ->execute([$invite['book_id'], $user_id]);
    }
    $db->prepare("UPDATE reading_circle_invites SET status = 'accepted' WHERE id = ?")->execute([$invite['id']]);
    $_SESSION['notification'] = 'You joined the reading circle for this book.';
    header('Location: ' . SITE_URL . '/reader/reader.php?id=' . (int)$invite['book_id']);
    exit;
}

$pageTitle = 'Reading circle invitation';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:640px;margin:60px auto;padding:0 16px;">
    <div class="alert alert-error"><?php echo htmlspecialchars($message); ?></div>
    <p><a class="btn btn-primary" href="<?php echo SITE_URL; ?>/books.php">Browse books</a></p>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
