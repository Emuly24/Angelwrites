<?php
/**
 * Share your book (member page). See includes/member_books.php for the whole flow:
 * upload -> team review -> Community books shelf -> read on the site only -> remove at any time.
 */
require_once 'includes/config.php';
require_once __DIR__ . '/includes/site_texts.php'; // page text can be changed on the site
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
$v = ['title' => '', 'author_name' => '', 'description' => '', 'why' => '', 'kind' => 'file', 'rights' => '', 'rights_detail' => '', 'link' => '', 'allow_download' => '0', 'attest' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['form'] = 'This form has expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'remove') {
        $ok = aw_mb_member_remove($db, $uid, (int)($_POST['id'] ?? 0));
        $_SESSION['aw_mb_flash'] = $ok ? 'Done. Your book has been removed from AngelWrites and its file deleted.' : 'That book could not be removed. Please try again.';
        header('Location: ' . SITE_URL . '/share_book.php#mine');
        exit;
    } elseif (($_POST['action'] ?? '') === 'download') {
        // the author changes whether members may download their book
        $on = ($_POST['allow'] ?? '') === '1';
        $ok = aw_mb_set_download($db, $uid, (int)($_POST['id'] ?? 0), $on);
        $_SESSION['aw_mb_flash'] = $ok ? ($on ? 'Done. Members who can read your book can now download a copy.' : 'Done. Your book is read on AngelWrites only; nobody can download it.') : 'That could not be changed. Please try again.';
        header('Location: ' . SITE_URL . '/share_book.php#mine');
        exit;
    } else {
        $v = ['attest' => (array)($_POST['attest'] ?? [])];
        foreach (['title', 'author_name', 'description', 'why', 'kind', 'rights', 'rights_detail', 'link', 'allow_download'] as $f) $v[$f] = (string)($_POST[$f] ?? '');
        $r = aw_mb_submit($db, $uid, $v, $v['kind'] === 'suggestion' ? null : ($_FILES['book_file'] ?? null));
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

$pageTitle = 'Share a book';
$aw_noindex = true;
require_once 'includes/header.php';
echo aw_cp_assets();
?>
<style>
.mb-dl { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; margin: 8px 0 0; }
.mb-steps { list-style: none; margin: 0 0 22px; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.mb-steps li { background: var(--cp-card); border: 1px solid var(--cp-line); border-radius: 16px; padding: 14px; font-size: .9rem; line-height: 1.5; }
.mb-steps strong { display: block; margin-bottom: 2px; }
.mb-steps i { color: var(--cp-accent); margin-right: 6px; }
.cp-field input[type=url] { width: 100%; min-height: 46px; padding: 10px 14px; border-radius: 12px; border: 1px solid var(--cp-line); background: var(--cp-card); color: var(--text); font: inherit; }
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
        <h1><?php echo aw_text('sharebook.h11', 'Share a book', 'line'); ?></h1>
        <p><?php echo aw_text('sharebook.p1', 'Has a book helped you, or would you love to read it together with others? Share it with the AngelWrites family: one you wrote, or one by another author.', 'text'); ?></p>
    </div></header>

    <?php if ($flash): ?><div class="cp-banner cp-banner-calm" role="status"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <ol class="mb-steps">
        <li><strong><i class="fas fa-book" aria-hidden="true"></i>1. Share it</strong>Upload the file when it may be shared, or suggest the book without one.</li>
        <li><strong><i class="fas fa-user-check" aria-hidden="true"></i>2. The team looks at it</strong>Nobody else sees it until it is approved.</li>
        <li><strong><i class="fas fa-people-group" aria-hidden="true"></i>3. Read together</strong>On the Community books shelf, and in reading groups.</li>
        <li><strong><i class="fas fa-trash-can" aria-hidden="true"></i>4. Your choice</strong>Remove it at any time; any file is deleted.</li>
    </ol>

    <?php if ($blocked !== '' && empty($errors)): ?>
        <?php echo aw_alert('info', $blocked); ?>
    <?php else: ?>
    <?php if (isset($errors['form'])): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errors['form']); ?></div><?php endif; ?>
    <?php if (count($errors) > (isset($errors['form']) ? 1 : 0)): ?><div class="alert alert-error" role="alert">Please check the <?php echo count($errors) - (isset($errors['form']) ? 1 : 0) === 1 ? 'item' : 'items'; ?> marked below.</div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="cp-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <div class="cp-field<?php echo $cls('title'); ?>">
            <label for="mbTitle">Book title</label>
            <input type="text" id="mbTitle" name="title" maxlength="160" required value="<?php echo htmlspecialchars($v['title']); ?>"<?php echo $desc('title'); ?>>
            <?php echo $err('title'); ?>
        </div>
        <div class="cp-field<?php echo $cls('author_name'); ?>">
            <label for="mbAuthor">Who wrote it</label>
            <span class="cp-hint" id="mbAuthorHint">The author's name (yours or a pen name, if you wrote it).</span>
            <input type="text" id="mbAuthor" name="author_name" maxlength="100" required value="<?php echo htmlspecialchars($v['author_name']); ?>"<?php echo $desc('author_name', 'mbAuthorHint'); ?>>
            <?php echo $err('author_name'); ?>
        </div>
        <div class="cp-field<?php echo $cls('description'); ?>">
            <label for="mbDesc">About the book <span class="cp-hint" style="display:inline">(optional)</span></label>
            <textarea id="mbDesc" name="description" maxlength="2000" rows="5"<?php echo $desc('description'); ?>><?php echo htmlspecialchars($v['description']); ?></textarea>
            <?php echo $err('description'); ?>
        </div>
        <div class="cp-field<?php echo $cls('why'); ?>">
            <label for="mbWhy">How will it help others here?</label>
            <span class="cp-hint" id="mbWhyHint">A sentence or two: what it gave you, or why you would like to read it together. The team looks for books that fit the community's goals.</span>
            <textarea id="mbWhy" name="why" maxlength="1000" rows="3" required<?php echo $desc('why', 'mbWhyHint'); ?>><?php echo htmlspecialchars($v['why']); ?></textarea>
            <?php echo $err('why'); ?>
        </div>
        <fieldset class="cp-field">
            <legend class="cp-label">How would you like to share it?</legend>
            <div class="cp-options">
                <label class="cp-option"><input type="radio" name="kind" value="file"<?php echo $v['kind'] !== 'suggestion' ? ' checked' : ''; ?>><span><strong>Upload the book</strong><small>Members read it here. Only when sharing the file is allowed: see below.</small></span></label>
                <label class="cp-option"><input type="radio" name="kind" value="suggestion"<?php echo $v['kind'] === 'suggestion' ? ' checked' : ''; ?>><span><strong>Suggest it without a file</strong><small>For any other book, such as one you bought. Members get their own copy and read it together.</small></span></label>
            </div>
        </fieldset>
        <div id="mbFilePart">
            <fieldset class="cp-field<?php echo $cls('rights'); ?>">
                <legend class="cp-label">Why may this book be shared here?</legend>
                <span class="cp-hint" id="mbRightsHint">A book someone else wrote can only be uploaded when its author or publisher allows sharing; otherwise it would be copyright infringement. If none of these fit, choose "Suggest it without a file" above.</span>
                <div class="cp-options">
                    <?php foreach (AW_MB_RIGHTS as $k => $r): ?>
                    <label class="cp-option"><input type="radio" name="rights" value="<?php echo $k; ?>"<?php echo $v['rights'] === $k ? ' checked' : ''; ?> data-detail="<?php echo $r[2] ? '1' : '0'; ?>"><span><strong><?php echo htmlspecialchars($r[0]); ?></strong><small><?php echo htmlspecialchars($r[1]); ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <?php echo $err('rights'); ?>
            </fieldset>
            <div class="cp-field<?php echo $cls('rights_detail'); ?>" id="mbDetailPart">
                <label for="mbDetail">Tell the team more</label>
                <span class="cp-hint" id="mbDetailHint">The licence, where the free copy is offered (a web address is best), when the author died, or who gave permission and how.</span>
                <textarea id="mbDetail" name="rights_detail" maxlength="500" rows="2"<?php echo $desc('rights_detail', 'mbDetailHint'); ?>><?php echo htmlspecialchars($v['rights_detail']); ?></textarea>
                <?php echo $err('rights_detail'); ?>
            </div>
            <fieldset class="cp-field" id="mbDownloadPart">
                <legend class="cp-label">You wrote it: may members download a copy?</legend>
                <span class="cp-hint">You can change this later in "Books you have shared".</span>
                <div class="cp-options">
                    <label class="cp-option"><input type="radio" name="allow_download" value="0"<?php echo $v['allow_download'] !== '1' ? ' checked' : ''; ?>><span><strong>No, read on AngelWrites only</strong><small>Members read it in the reader here; the file is never sent to anyone.</small></span></label>
                    <label class="cp-option"><input type="radio" name="allow_download" value="1"<?php echo $v['allow_download'] === '1' ? ' checked' : ''; ?>><span><strong>Yes, members may download it</strong><small>Signed-in members get a Download button on the book's page and can keep a copy to read offline.</small></span></label>
                </div>
            </fieldset>
            <div class="cp-field mb-file<?php echo $cls('book_file'); ?>">
                <label for="mbFile">The book file</label>
                <span class="cp-hint" id="mbFileHint"><?php echo htmlspecialchars(aw_upload_hint('book')); ?>. It must have real text (a scanned PDF of page pictures cannot be read).</span>
                <input type="file" id="mbFile" name="book_file"<?php echo aw_upload_attrs('book'); ?><?php echo $desc('book_file', 'mbFileHint'); ?>>
                <?php echo $err('book_file'); ?>
            </div>
        </div>
        <div class="cp-field<?php echo $cls('link'); ?>">
            <label for="mbLink">Where can members get it? <span class="cp-hint" style="display:inline">(optional)</span></label>
            <span class="cp-hint" id="mbLinkHint">A web address for the book: a shop, the publisher, or a free copy.</span>
            <input type="url" id="mbLink" name="link" maxlength="300" placeholder="https://" value="<?php echo htmlspecialchars($v['link']); ?>"<?php echo $desc('link', 'mbLinkHint'); ?>>
            <?php echo $err('link'); ?>
        </div>
        <p class="cp-hint mb-cover-note"><i class="fas fa-image" aria-hidden="true"></i> No cover needed: the book's own cover is used when its file has one, otherwise AngelWrites designs one from the title.</p>
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
    <script>
    (function () {
        // show the parts that apply: the file and its rights for an upload, the details when needed
        var form = document.querySelector('.cp-form'); if (!form) return;
        function sync() {
            var sug = form.querySelector('input[name="kind"]:checked');
            var isFile = !sug || sug.value !== 'suggestion';
            document.getElementById('mbFilePart').hidden = !isFile;
            var r = form.querySelector('input[name="rights"]:checked');
            document.getElementById('mbDetailPart').hidden = !(isFile && r && r.dataset.detail === '1');
            document.getElementById('mbDownloadPart').hidden = !(isFile && r && r.value === 'own'); // only the author decides
        }
        form.addEventListener('change', sync); sync();
    })();
    </script>
    <?php endif; ?>

    <section id="mine" style="margin-top:36px;" aria-labelledby="mbMineTitle">
        <h2 id="mbMineTitle" style="font-size:1.3rem;"><?php echo aw_text('sharebook.h21', 'Books you have shared', 'line'); ?></h2>
        <?php if (!$mine): ?>
            <p class="mb-meta"><?php echo aw_text('sharebook.p2', 'Nothing yet. Your books will appear here, with what the team decided.', 'text'); ?></p>
        <?php else: ?>
        <ul class="mb-list" role="list">
            <?php foreach ($mine as $m): ?>
            <li class="mb-item">
                <span class="mb-state <?php echo htmlspecialchars($m['status']); ?>"><?php echo htmlspecialchars(aw_mb_status_label($m['status'])); ?></span>
                <h3><?php if ($m['status'] === 'published' && $m['book_id']): ?><a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$m['book_id']; ?>"><?php echo htmlspecialchars($m['title']); ?></a><?php else: echo htmlspecialchars($m['title']); endif; ?></h3>
                <span class="mb-meta">by <?php echo htmlspecialchars($m['author_name']); ?> · <?php echo htmlspecialchars(aw_mb_rights_label((string)($m['rights'] ?? '')) ?: 'Shared'); ?> · <?php echo htmlspecialchars(date('j M Y', strtotime($m['created_at']))); ?><?php if ($m['words']): ?> · <?php echo number_format((int)$m['words']); ?> words<?php endif; ?></span>
                <?php if (trim((string)$m['review_note']) !== ''): ?><div class="mb-note"><strong>From the team:</strong> <?php echo htmlspecialchars($m['review_note']); ?></div>
                <?php elseif ($m['status'] === 'removed'): ?><div class="mb-meta">You removed this book; its file was deleted.</div><?php endif; ?>
                <?php if (in_array($m['status'], ['waiting', 'published'], true) && ($m['rights'] ?? '') === 'own' && ($m['kind'] ?? 'file') !== 'suggestion'): $dl = !empty($m['allow_download']); ?>
                <form method="post" class="mb-dl">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="download"><input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>"><input type="hidden" name="allow" value="<?php echo $dl ? '0' : '1'; ?>">
                    <span class="mb-meta"><i class="fas <?php echo $dl ? 'fa-download' : 'fa-book-open-reader'; ?>" aria-hidden="true"></i> <?php echo $dl ? 'Members may download it.' : 'Read on AngelWrites only.'; ?></span>
                    <button type="submit" class="cp-btn cp-btn-sm cp-btn-soft"><?php echo $dl ? 'Stop downloads' : 'Allow downloads'; ?></button>
                </form>
                <?php endif; ?>
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
