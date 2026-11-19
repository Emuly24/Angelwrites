<?php
/**
 * Share your book (member page). See includes/member_books.php for the whole flow:
 * upload -> team review -> Community books shelf -> read on the site only -> remove at any time.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/community_posts.php'; // the form styles (aw_cp_assets) and posting pauses
require_once 'includes/upload.php';
require_once 'includes/member_books.php';
if (file_exists(__DIR__ . '/includes/notifications.php')) require_once 'includes/notifications.php';

if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode('/share_book.php')); exit; }
$uid = (int)$_SESSION['user_id'];
aw_mb_tables($db);

$errors = [];
$v = ['title' => '', 'author_name' => '', 'description' => '', 'attest' => []];
try {
    $st = $db->prepare("SELECT display_name, username FROM users WHERE id = ?");
    $st->execute([$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $v['author_name'] = trim((string)($me['display_name'] ?? '')) ?: (string)($me['username'] ?? '');
} catch (Throwable $e) { error_log('share book me: ' . $e->getMessage()); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['form'] = 'This form has expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'remove') {
        $ok = aw_mb_member_remove($db, $uid, (int)($_POST['id'] ?? 0));
        $_SESSION['aw_mb_flash'] = $ok ? 'Done. Your book has been removed from AngelWrites and its file deleted.' : 'That book could not be removed. Please try again.';
        header('Location: ' . SITE_URL . '/share_book.php#mine');
        exit;
    } else {
        $v = ['title' => (string)($_POST['title'] ?? ''), 'author_name' => (string)($_POST['author_name'] ?? ''), 'description' => (string)($_POST['description'] ?? ''), 'attest' => (array)($_POST['attest'] ?? [])];
        $r = aw_mb_submit($db, $uid, $v, $_FILES['book_file'] ?? null, $_FILES['cover'] ?? null);
        if (!empty($r['ok'])) {
            $_SESSION['aw_mb_flash'] = 'Thank you! "' . trim($v['title']) . '" is with the team for review. We will let you know here and in your notifications.';
            header('Location: ' . SITE_URL . '/share_book.php#mine');
            exit;
        }
        $errors = $r['errors'] ?? ['form' => 'Something went wrong. Please try again.'];
    }
}
$flash = $_SESSION['aw_mb_flash'] ?? '';
unset($_SESSION['aw_mb_flash']);
$mine = aw_mb_mine($db, $uid);
$blocked = aw_mb_can_submit($db, $uid);

$err = function ($k) use ($errors) { return isset($errors[$k]) ? '<span class="cp-err" id="err_' . $k . '">' . htmlspecialchars($errors[$k]) . '</span>' : ''; };
$cls = function ($k) use ($errors) { return isset($errors[$k]) ? ' has-err' : ''; };
$desc = function ($k, $hint = '') use ($errors) { $ids = trim(($hint ? $hint . ' ' : '') . (isset($errors[$k]) ? 'err_' . $k : '')); return $ids ? ' aria-describedby="' . $ids . '"' : ''; };

$pageTitle = 'Share your book';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
?>
<style>
.mb-steps { list-style: none; margin: 0 0 22px; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.mb-steps li { background: var(--cp-card); border: 1px solid var(--cp-line); border-radius: 16px; padding: 14px; font-size: .9rem; line-height: 1.5; }
.mb-steps strong { display: block; margin-bottom: 2px; }
.mb-steps i { color: var(--cp-accent); margin-right: 6px; }
.mb-file input[type=file] { display: block; width: 100%; padding: 12px; border: 1px dashed var(--cp-line); border-radius: 12px; background: var(--cp-card); font: inherit; }
.mb-attest { display: grid; gap: 8px; }
.mb-attest .cp-check { border-radius: 14px; align-items: flex-start; padding: 10px 14px; min-height: 44px; line-height: 1.45; }
.mb-attest .cp-check input { margin-top: 3px; flex: 0 0 auto; }
.mb-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.mb-item { background: var(--cp-card); border: 1px solid var(--cp-line); border-radius: 16px; padding: 16px; display: grid; gap: 6px; }
.mb-item h3 { margin: 0; font-size: 1.05rem; }
.mb-state { display: inline-flex; align-items: center; gap: 6px; width: fit-content; padding: 3px 10px; border-radius: 999px; font-size: .8rem; font-weight: 700; background: var(--cp-soft); color: var(--text); }
.mb-state.published { background: #e3efe6; color: #2d5039; }
.mb-state.declined, .mb-state.removed { background: #f6e1e3; color: #6e2a31; }
.mb-meta { color: var(--cp-muted); font-size: .86rem; }
.mb-note { background: var(--cp-soft); border-radius: 12px; padding: 10px 12px; font-size: .9rem; }
html[data-theme="dark"] .mb-state.published { background: #1f3527; color: #b9e2c5; }
html[data-theme="dark"] .mb-state.declined, html[data-theme="dark"] .mb-state.removed { background: #3a2023; color: #f2c7cb; }
</style>
<main class="cp" id="main">
<div class="container cp-narrow">
    <p><a href="<?php echo SITE_URL; ?>/books.php?filter=community"><i class="fas fa-arrow-left" aria-hidden="true"></i> Community books</a></p>
    <header class="cp-head"><div>
        <h1>Share your book</h1>
        <p>Have you written a book? Share it with the AngelWrites family. Members read it here, on the site.</p>
    </div></header>

    <?php if ($flash): ?><div class="cp-banner cp-banner-calm" role="status"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <ol class="mb-steps">
        <li><strong><i class="fas fa-upload" aria-hidden="true"></i>1. Upload</strong>Your book as PDF, EPUB, Word, ODT, RTF or text.</li>
        <li><strong><i class="fas fa-user-check" aria-hidden="true"></i>2. The team reads it</strong>Nobody else sees it until it is approved.</li>
        <li><strong><i class="fas fa-book-open" aria-hidden="true"></i>3. Members read it</strong>On the Community books shelf, free, on the site only.</li>
        <li><strong><i class="fas fa-trash-can" aria-hidden="true"></i>4. Your choice</strong>Remove it at any time; its file is deleted.</li>
    </ol>

    <?php if ($blocked !== '' && empty($errors)): ?>
        <?php echo aw_alert('info', $blocked); ?>
    <?php else: ?>
    <?php if (isset($errors['form'])): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div><?php endif; ?>
    <?php if (count($errors) > (isset($errors['form']) ? 1 : 0)): ?><div class="alert alert-error" role="alert">Please check the <?php echo count($errors) - (isset($errors['form']) ? 1 : 0) === 1 ? 'item' : 'items'; ?> marked below.</div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="cp-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <div class="cp-field<?php echo $cls('title'); ?>">
            <label for="mbTitle">Title</label>
            <input type="text" id="mbTitle" name="title" maxlength="160" required value="<?php echo htmlspecialchars($v['title']); ?>"<?php echo $desc('title'); ?>>
            <?php echo $err('title'); ?>
        </div>
        <div class="cp-field<?php echo $cls('author_name'); ?>">
            <label for="mbAuthor">Author name to show</label>
            <span class="cp-hint" id="mbAuthorHint">Your name or the pen name you write under.</span>
            <input type="text" id="mbAuthor" name="author_name" maxlength="100" required value="<?php echo htmlspecialchars($v['author_name']); ?>"<?php echo $desc('author_name', 'mbAuthorHint'); ?>>
            <?php echo $err('author_name'); ?>
        </div>
        <div class="cp-field<?php echo $cls('description'); ?>">
            <label for="mbDesc">About the book <span class="cp-hint" style="display:inline">(optional)</span></label>
            <textarea id="mbDesc" name="description" maxlength="2000" rows="5"<?php echo $desc('description'); ?>><?php echo htmlspecialchars($v['description']); ?></textarea>
            <?php echo $err('description'); ?>
        </div>
        <div class="cp-field mb-file<?php echo $cls('book_file'); ?>">
            <label for="mbFile">The book file</label>
            <span class="cp-hint" id="mbFileHint"><?php echo htmlspecialchars(aw_upload_hint('book')); ?>. It must have real text (a scanned PDF of page pictures cannot be read).</span>
            <input type="file" id="mbFile" name="book_file" required<?php echo aw_upload_attrs('book'); ?><?php echo $desc('book_file', 'mbFileHint'); ?>>
            <?php echo $err('book_file'); ?>
        </div>
        <div class="cp-field mb-file<?php echo $cls('cover'); ?>">
            <label for="mbCover">Cover picture <span class="cp-hint" style="display:inline">(optional)</span></label>
            <input type="file" id="mbCover" name="cover"<?php echo aw_upload_attrs('image'); ?><?php echo $desc('cover'); ?>>
            <?php echo $err('cover'); ?>
        </div>
        <fieldset class="cp-field<?php echo $cls('attest'); ?>">
            <legend class="cp-label">Before you send it</legend>
            <div class="mb-attest">
                <?php foreach (AW_MB_ATTEST as $k => $text): ?>
                <label class="cp-check"><input type="checkbox" name="attest[]" value="<?php echo $k; ?>"<?php echo in_array($k, $v['attest'], true) ? ' checked' : ''; ?> required> <span><?php echo htmlspecialchars($text); ?><?php if ($k === 'rules'): ?> <a href="<?php echo SITE_URL; ?>/community_guidelines.php" target="_blank" rel="noopener">Read them</a><?php endif; ?></span></label>
                <?php endforeach; ?>
            </div>
            <?php echo $err('attest'); ?>
        </fieldset>
        <div class="cp-actions">
            <button type="submit" class="cp-btn"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send for review</button>
            <span class="cp-hint">Up to <?php echo AW_MB_MAX_WAITING; ?> books can wait for review at once.</span>
        </div>
    </form>
    <?php endif; ?>

    <section id="mine" style="margin-top:36px;" aria-labelledby="mbMineTitle">
        <h2 id="mbMineTitle" style="font-size:1.3rem;">Books you have shared</h2>
        <?php if (!$mine): ?>
            <p class="mb-meta">Nothing yet. Your books will appear here, with what the team decided.</p>
        <?php else: ?>
        <ul class="mb-list" role="list">
            <?php foreach ($mine as $m): ?>
            <li class="mb-item">
                <span class="mb-state <?php echo htmlspecialchars($m['status']); ?>"><?php echo htmlspecialchars(aw_mb_status_label($m['status'])); ?></span>
                <h3><?php if ($m['status'] === 'published' && $m['book_id']): ?><a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$m['book_id']; ?>"><?php echo htmlspecialchars($m['title']); ?></a><?php else: echo htmlspecialchars($m['title']); endif; ?></h3>
                <span class="mb-meta">by <?php echo htmlspecialchars($m['author_name']); ?> · shared <?php echo htmlspecialchars(date('j M Y', strtotime($m['created_at']))); ?><?php if ($m['words']): ?> · <?php echo number_format((int)$m['words']); ?> words<?php endif; ?></span>
                <?php if (trim((string)$m['review_note']) !== ''): ?><div class="mb-note"><strong>From the team:</strong> <?php echo htmlspecialchars($m['review_note']); ?></div>
                <?php elseif ($m['status'] === 'removed'): ?><div class="mb-meta">You removed this book; its file was deleted.</div><?php endif; ?>
                <?php if (in_array($m['status'], ['waiting', 'published'], true)): ?>
                <form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($m['status'] === 'published' ? 'Remove this book from AngelWrites? Members will no longer be able to read it, and its file is deleted.' : 'Withdraw this book? Its file is deleted.'), ENT_QUOTES); ?>);" style="margin:4px 0 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                    <button type="submit" class="cp-btn cp-btn-danger cp-btn-sm"><?php echo $m['status'] === 'published' ? 'Remove my book' : 'Withdraw'; ?></button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
