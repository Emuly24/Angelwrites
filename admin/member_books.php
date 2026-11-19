<?php
/**
 * Member books: the review queue (owner and admins). See includes/member_books.php.
 * Waiting books can be read here first, then approved (onto the Community books shelf) or declined
 * with a reason. Books on the site can be taken down, for example after a concern; the member is
 * told every decision.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/member_books.php';
if (file_exists(__DIR__ . '/../includes/notifications.php')) require_once '../includes/notifications.php';

aw_require('manage_content'); // K1: approving member books is an owner/admin task; checks CSRF on POST (K3)
aw_mb_tables($db);

$mb_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mb_tab = in_array($_GET['tab'] ?? '', ['waiting', 'published', 'declined', 'removed'], true) ? $_GET['tab'] : 'waiting';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mb_id) {
    $r = aw_mb_decide($db, $mb_id, (string)($_POST['action'] ?? ''), (string)($_POST['reason'] ?? ''), (int)$_SESSION['user_id']);
    $_SESSION['aw_mbq_flash'] = [!empty($r['ok']) ? 'ok' : 'error', $r['message']];
    header('Location: ' . SITE_URL . '/admin/member_books.php?id=' . $mb_id);
    exit;
}
$mb_flash = $_SESSION['aw_mbq_flash'] ?? null;
unset($_SESSION['aw_mbq_flash']);

$mb_one = $mb_id ? aw_mb_get($db, $mb_id) : null;
$mb_rows = [];
$mb_counts = ['waiting' => 0, 'published' => 0, 'declined' => 0, 'removed' => 0];
try {
    foreach ($db->query("SELECT status, COUNT(*) n FROM member_books GROUP BY status") as $c) if (isset($mb_counts[$c['status']])) $mb_counts[$c['status']] = (int)$c['n'];
    if (!$mb_one) {
        $st = $db->prepare("SELECT m.*, u.username, u.display_name FROM member_books m LEFT JOIN users u ON u.id = m.user_id WHERE m.status = ? ORDER BY " . ($mb_tab === 'waiting' ? 'm.created_at ASC' : 'm.updated_at DESC') . " LIMIT 100");
        $st->execute([$mb_tab]);
        $mb_rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('member books queue: ' . $e->getMessage());
}

// read the book before deciding: the text exactly as the reader will show it
$mb_preview = '';
$mb_preview_note = '';
if ($mb_one && $mb_one['status'] === 'waiting') {
    $ex = aw_book_extract(aw_book_file_abs($mb_one['file_path']));
    if (!empty($ex['ok'])) {
        $all = count($ex['pages']);
        list($mb_preview) = aw_book_render(array_slice($ex['pages'], 0, 60), $mb_one['title'], $mb_one['author_name']);
        if ($all > 60) $mb_preview_note = 'Showing the first 60 of ' . $all . ' pages.';
    } else {
        $mb_preview_note = $ex['error'] ?? 'The file could not be read.';
    }
}

$pageTitle = $mb_one ? 'Member book · ' . $mb_one['title'] : 'Member books';
require_once '../includes/header.php';
$who = function ($r) { return trim((string)($r['display_name'] ?? '')) ?: (string)($r['username'] ?? 'A member'); };
?>
<style>
.mbq { max-width: 1000px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.mbq h1 { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem, 3vw, 2.1rem); margin: 0 0 6px; }
.mbq-lead { color: var(--text-light); margin: 0 0 18px; line-height: 1.6; max-width: 48em; }
.mbq-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
.mbq-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--border); text-decoration: none; color: var(--text); font-weight: 600; font-size: .9rem; }
.mbq-tabs a[aria-current="page"] { background: #9c5457; border-color: #9c5457; color: #fff; }
.mbq-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; padding: 20px; box-shadow: 0 6px 20px rgba(44,30,30,.06); margin-bottom: 16px; }
.mbq-list { list-style: none; margin: 0; padding: 0; }
.mbq-list li { display: flex; gap: 14px; align-items: center; padding: 14px 4px; border-bottom: 1px solid var(--border); }
.mbq-list li:last-child { border-bottom: 0; }
.mbq-cover { width: 46px; height: 64px; border-radius: 6px; object-fit: cover; background: var(--bg, #f7f3ed); flex: 0 0 auto; display: grid; place-items: center; color: var(--text-light); }
.mbq-list a { color: #9c5457; font-weight: 700; }
.mbq-meta { color: var(--text-light); font-size: .86rem; }
.mbq-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; align-items: start; }
.mbq-preview { max-height: 70vh; overflow: auto; background: var(--bg, #f7f3ed); border-radius: 14px; padding: 18px 22px; font-family: Georgia, serif; line-height: 1.75; }
.mbq-preview .page-content { padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px dashed var(--border); }
.mbq-preview h1, .mbq-preview h2 { font-size: 1.25rem; text-align: center; }
.mbq form { margin: 0 0 14px; }
.mbq textarea { width: 100%; min-height: 90px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--border); background: var(--card-bg, #fff); color: var(--text); font: inherit; }
.mbq label { display: block; font-weight: 700; margin: 0 0 6px; }
.mbq .btn { min-height: 44px; }
.mbq-attest { white-space: pre-line; font-size: .88rem; background: var(--bg, #f7f3ed); border-radius: 12px; padding: 10px 12px; }
.mbq-alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 500; }
.mbq-alert.ok { background: #e3efe6; color: #2d5039; }
.mbq-alert.error { background: #f6e1e3; color: #6e2a31; }
@media (max-width: 860px) { .mbq-grid { grid-template-columns: 1fr; } }
</style>
<div class="mbq">
<?php if ($mb_flash): ?><div class="mbq-alert <?php echo $mb_flash[0] === 'ok' ? 'ok' : 'error'; ?>" role="status"><?php echo htmlspecialchars($mb_flash[1]); ?></div><?php endif; ?>

<?php if ($mb_one): ?>
    <p><a href="<?php echo SITE_URL; ?>/admin/member_books.php?tab=<?php echo htmlspecialchars($mb_one['status']); ?>">&larr; Member books</a></p>
    <h1><?php echo htmlspecialchars($mb_one['title']); ?></h1>
    <p class="mbq-lead">by <?php echo htmlspecialchars($mb_one['author_name']); ?> · shared by <strong><?php echo htmlspecialchars($who($mb_one)); ?></strong> on <?php echo htmlspecialchars(date('j M Y, g:i a', strtotime($mb_one['created_at']))); ?> · <?php echo htmlspecialchars(aw_mb_status_label($mb_one['status'])); ?></p>
    <div class="mbq-grid">
        <div>
            <?php if ($mb_one['status'] === 'waiting'): ?>
            <div class="mbq-card">
                <h2 style="font-size:1.1rem;margin:0 0 10px;">Read it first</h2>
                <?php if ($mb_preview_note): ?><p class="mbq-meta"><?php echo htmlspecialchars($mb_preview_note); ?></p><?php endif; ?>
                <?php if ($mb_preview): ?><div class="mbq-preview" tabindex="0" aria-label="The book's text"><?php echo $mb_preview; /* escaped by aw_book_render */ ?></div><?php endif; ?>
            </div>
            <?php elseif ($mb_one['status'] === 'published' && $mb_one['book_id']): ?>
            <div class="mbq-card"><p style="margin:0;">It is on the Community books shelf. <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$mb_one['book_id']; ?>">Book page</a> · <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$mb_one['book_id']; ?>" target="_blank" rel="noopener">Open in the reader</a></p></div>
            <?php endif; ?>
            <?php if (trim((string)$mb_one['description']) !== ''): ?><div class="mbq-card"><h2 style="font-size:1.1rem;margin:0 0 8px;">About the book</h2><p style="white-space:pre-line;margin:0;"><?php echo htmlspecialchars($mb_one['description']); ?></p></div><?php endif; ?>
        </div>
        <aside>
            <div class="mbq-card">
                <p class="mbq-meta" style="margin-top:0;"><?php echo number_format((int)$mb_one['words']); ?> words · <?php echo (int)$mb_one['pages']; ?> pages · <?php echo htmlspecialchars(strtoupper((string)$mb_one['file_type'])); ?></p>
                <p style="font-weight:700;margin:0 0 6px;">The member confirmed</p>
                <div class="mbq-attest"><?php echo htmlspecialchars((string)$mb_one['attest_text']); ?></div>
                <?php if (trim((string)$mb_one['review_note']) !== ''): ?><p class="mbq-meta"><strong>Note sent:</strong> <?php echo htmlspecialchars($mb_one['review_note']); ?></p>
                <?php elseif ($mb_one['status'] === 'removed'): ?><p class="mbq-meta">The member removed it.</p><?php endif; ?>
            </div>
            <?php if ($mb_one['status'] === 'waiting'): ?>
            <div class="mbq-card">
                <form method="post">
                    <input type="hidden" name="action" value="approve">
                    <label for="mbqNoteOk">Note to the member <span class="mbq-meta">(optional)</span></label>
                    <textarea id="mbqNoteOk" name="reason" maxlength="1000"></textarea>
                    <button type="submit" class="btn btn-primary" style="margin-top:8px;width:100%;">Approve: add to Community books</button>
                </form>
                <form method="post" onsubmit="return this.reason.value.trim() !== '' || (alert('Please give the member a reason.'), false);">
                    <input type="hidden" name="action" value="decline">
                    <label for="mbqNoteNo">Reason for not adding it</label>
                    <textarea id="mbqNoteNo" name="reason" maxlength="1000" required placeholder="e.g. It looks like someone else's published book; we can only add books you wrote."></textarea>
                    <button type="submit" class="btn btn-outline" style="margin-top:8px;width:100%;">Decline and tell the member</button>
                </form>
            </div>
            <?php elseif ($mb_one['status'] === 'published'): ?>
            <div class="mbq-card">
                <form method="post" onsubmit="return this.reason.value.trim() !== '' && confirm('Take this book down? It is removed from the site with its files.');">
                    <input type="hidden" name="action" value="takedown">
                    <label for="mbqTake">Reason for taking it down</label>
                    <textarea id="mbqTake" name="reason" maxlength="1000" required placeholder="e.g. A concern was raised about the rights to this book."></textarea>
                    <button type="submit" class="btn btn-outline" style="margin-top:8px;width:100%;color:#a3262e;border-color:#e6b9bd;">Take down and tell the member</button>
                </form>
            </div>
            <?php endif; ?>
        </aside>
    </div>

<?php else: ?>
    <h1>Member books</h1>
    <p class="mbq-lead">Books members share wait here until you read and approve them. Approved books go on the Community books shelf, free and read on the site only. Members can remove their own books; you can take any book down after a concern.</p>
    <nav class="mbq-tabs" aria-label="Member books">
        <?php foreach (['waiting' => 'Waiting', 'published' => 'On the site', 'declined' => 'Declined', 'removed' => 'Taken down'] as $k => $l): ?>
        <a href="?tab=<?php echo $k; ?>"<?php echo $mb_tab === $k ? ' aria-current="page"' : ''; ?>><?php echo $l; ?> <span>(<?php echo (int)$mb_counts[$k]; ?>)</span></a>
        <?php endforeach; ?>
    </nav>
    <div class="mbq-card" style="padding:6px 18px;">
        <?php if (!$mb_rows): ?>
            <p class="mbq-meta" style="padding:12px 0;"><?php echo $mb_tab === 'waiting' ? 'No books are waiting. When a member shares one, you will get a notification.' : 'Nothing here.'; ?></p>
        <?php else: ?>
        <ul class="mbq-list" role="list">
            <?php foreach ($mb_rows as $r): ?>
            <li>
                <?php if (!empty($r['cover_path']) && aw_book_file_abs($r['cover_path']) !== ''): ?><img class="mbq-cover" src="<?php echo htmlspecialchars(SITE_URL . '/' . $r['cover_path']); ?>" alt=""><?php else: ?><span class="mbq-cover" aria-hidden="true"><i class="fas fa-book"></i></span><?php endif; ?>
                <div>
                    <a href="<?php echo SITE_URL; ?>/admin/member_books.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['title']); ?></a>
                    <div class="mbq-meta">by <?php echo htmlspecialchars($r['author_name']); ?> · shared by <?php echo htmlspecialchars($who($r)); ?> · <?php echo htmlspecialchars(date('j M Y', strtotime($r['created_at']))); ?> · <?php echo number_format((int)$r['words']); ?> words</div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php require_once '../includes/footer.php'; ?>
