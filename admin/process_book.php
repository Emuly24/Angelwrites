<?php
/**
 * Reader copy (admin/process_book.php).
 *
 * Books no longer need correcting and publishing by hand: every uploaded file (PDF, EPUB, DOCX, ODT,
 * RTF or TXT) is prepared for the reader automatically by includes/book_extract.php, when it is
 * uploaded and, if needed, the first time someone opens it. The reader then fits the pages to each
 * screen itself, in scroll or page-turn mode.
 *
 * This page only shows how each book was prepared and lets the team prepare one again from its
 * file. The old editor (drafts, versions, search and replace, exports, backups, collaborators) is
 * retired; its saved versions stay in book_content_history.
 *
 * Books are read on the site only: nothing here offers the file for download.
 */

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/book_extract.php';

aw_require('manage_content'); // K1; also checks the CSRF token on every POST (K3)

$rc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$rc_notice = null; // [kind, text]

function aw_rc_status(array $rc_row = null) {
    if (!$rc_row) return ['none', 'Not prepared yet', 'It is prepared the first time someone opens it.'];
    $rc_meta = json_decode((string)($rc_row['metadata_json'] ?? ''), true) ?: [];
    $ready = (int)($rc_row['is_processed'] ?? 0) === 1 && trim((string)($rc_row['content_html'] ?? '')) !== '';
    if (!$ready && ($rc_row['processing_status'] ?? '') === 'failed') return ['fail', 'Could not be prepared', (string)($rc_meta['error'] ?? '')];
    if (!$ready) return ['none', 'Not prepared yet', 'It is prepared the first time someone opens it.'];
    if (!empty($rc_meta['auto'])) return ['auto', 'Ready', 'Prepared automatically' . (!empty($rc_meta['at']) ? ' on ' . date('j M Y', strtotime($rc_meta['at'])) : '') . '.'];
    return ['hand', 'Ready (corrected by hand)', 'This text was corrected and published by hand earlier. It is kept until a new file is uploaded or you prepare it again.'];
}

// ===== PREPARE AGAIN =====
if ($rc_id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prepare') {
    $r = aw_book_prepare($db, $rc_id, true);
    if (function_exists('aw_audit')) aw_audit('book_prepare', 'book', $rc_id, !empty($r['ok']) ? 'ok' : (string)($r['error'] ?? ''));
    $_SESSION['aw_rc_notice'] = !empty($r['ok'])
        ? ['ok', 'Prepared again: ' . (int)$r['pages'] . ' pages, ' . number_format((int)$r['words']) . ' words, ' . (int)$r['chapters'] . ' chapters or sections.']
        : ['error', 'It could not be prepared: ' . ($r['error'] ?? 'unknown problem') . ' The text readers had before is unchanged.'];
    header('Location: ' . SITE_URL . '/admin/process_book.php?id=' . $rc_id);
    exit;
}
if (!empty($_SESSION['aw_rc_notice'])) { $rc_notice = $_SESSION['aw_rc_notice']; unset($_SESSION['aw_rc_notice']); }

$rc_book = null; $rc_row = null; $rc_books = [];
try {
    if ($rc_id) {
        $st = $db->prepare("SELECT * FROM books WHERE id = ?");
        $st->execute([$rc_id]);
        $rc_book = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rc_book) {
            $st = $db->prepare("SELECT * FROM book_content WHERE book_id = ?");
            $st->execute([$rc_id]);
            $rc_row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (!$rc_book) {
        $rc_books = $db->query("SELECT b.id, b.title, b.file_path, b.file_type, c.is_processed, c.processing_status, c.metadata_json, c.content_html IS NOT NULL AND c.content_html <> '' AS has_text
                             FROM books b LEFT JOIN book_content c ON c.book_id = b.id ORDER BY b.title COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('reader copy: ' . $e->getMessage());
}

$pageTitle = $rc_book ? 'Reader copy · ' . $rc_book['title'] : 'Reader copies';
require_once '../includes/header.php';
$rc_formats = implode(', ', aw_book_formats());
?>
<style>
.rc { max-width: 980px; margin: 0 auto; padding: 24px 16px 64px; color: var(--text); }
.rc h1 { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem, 3vw, 2.1rem); margin: 0 0 6px; }
.rc-lead { color: var(--text-light); margin: 0 0 22px; max-width: 46em; line-height: 1.6; }
.rc-card { background: var(--card-bg, #fff); border: 1px solid var(--border); border-radius: 18px; padding: 22px; box-shadow: 0 6px 20px rgba(44,30,30,.06); margin-bottom: 18px; }
.rc-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: .82rem; font-weight: 700; }
.rc-auto, .rc-hand { background: #e3efe6; color: #2d5039; }
.rc-none { background: #f1ece6; color: #5c4a3a; }
.rc-fail { background: #f6e1e3; color: #6e2a31; }
.rc-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 16px 0; }
.rc-fact { background: var(--bg, #f7f3ed); border-radius: 12px; padding: 12px 14px; }
.rc-fact strong { display: block; font-size: 1.3rem; font-family: 'Playfair Display', Georgia, serif; }
.rc-fact span { color: var(--text-light); font-size: .85rem; }
.rc-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.rc-actions .btn, .rc-actions button { min-height: 44px; }
.rc-table { width: 100%; border-collapse: collapse; }
.rc-table th, .rc-table td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
.rc-table th { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-light); }
.rc-table a { color: #9c5457; font-weight: 600; }
.rc-note { font-size: .9rem; color: var(--text-light); line-height: 1.6; }
.rc-alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 500; }
.rc-alert.ok { background: #e3efe6; color: #2d5039; }
.rc-alert.error { background: #f6e1e3; color: #6e2a31; }
@media (max-width: 640px) { .rc-table thead { display: none; } .rc-table tr { display: block; border-bottom: 1px solid var(--border); padding: 8px 0; } .rc-table td { display: block; border: 0; padding: 4px 0; } }
</style>

<div class="rc">
<?php if ($rc_notice): ?>
    <div class="rc-alert <?php echo $rc_notice[0] === 'ok' ? 'ok' : 'error'; ?>" role="status"><?php echo htmlspecialchars($rc_notice[1]); ?></div>
<?php endif; ?>

<?php if ($rc_book): list($k, $label, $why) = aw_rc_status($rc_row); $rc_meta = $rc_row ? (json_decode((string)($rc_row['metadata_json'] ?? ''), true) ?: []) : []; ?>
    <p><a href="<?php echo SITE_URL; ?>/admin/process_book.php">&larr; All reader copies</a></p>
    <h1><?php echo htmlspecialchars($rc_book['title']); ?></h1>
    <p class="rc-lead">Books are prepared for the reader automatically; there is nothing to correct or publish. Members read in scroll or page-turn mode, with the pages fitted to their screen and text size, and the book never leaves the site.</p>

    <div class="rc-card">
        <span class="rc-badge rc-<?php echo $k; ?>"><?php echo htmlspecialchars($label); ?></span>
        <?php if ($why !== ''): ?><p class="rc-note" style="margin:10px 0 0;"><?php echo htmlspecialchars($why); ?></p><?php endif; ?>
        <?php
        $rc_pages = $rc_row ? (int)preg_match_all('/<div class="page-content" data-page="\d+">/', (string)$rc_row['content_html']) : 0;
        $rc_words = $rc_row ? str_word_count(strip_tags((string)$rc_row['content_html'])) : 0;
        $rc_chap = $rc_row ? substr_count((string)$rc_row['content_html'], 'chapter-heading') : 0;
        ?>
        <?php if ($rc_pages): ?>
        <div class="rc-facts">
            <div class="rc-fact"><strong><?php echo number_format($rc_pages); ?></strong><span>book pages (the reader fits them to each screen)</span></div>
            <div class="rc-fact"><strong><?php echo number_format($rc_words); ?></strong><span>words</span></div>
            <div class="rc-fact"><strong><?php echo number_format($rc_chap); ?></strong><span>chapters or sections</span></div>
            <div class="rc-fact"><strong><?php echo htmlspecialchars(strtoupper((string)($rc_meta['type'] ?? $rc_book['file_type'] ?? '—'))); ?></strong><span>from the file</span></div>
        </div>
        <?php endif; ?>
        <div class="rc-actions">
            <?php if ($rc_pages): ?><a class="btn btn-primary" href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$rc_id; ?>" target="_blank" rel="noopener">Open in the reader</a><?php endif; ?>
            <?php if (aw_book_file_abs($rc_book['file_path'] ?? '') !== ''): ?>
            <form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($k === 'hand' ? 'Prepare this book again from its file? The text corrected by hand will be replaced (a copy is kept in the history).' : 'Prepare this book again from its file?'), ENT_QUOTES); ?>);">
                <input type="hidden" name="action" value="prepare">
                <button type="submit" class="btn btn-outline">Prepare again from the file</button>
            </form>
            <?php endif; ?>
            <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/admin/manage_books.php?edit=<?php echo (int)$rc_id; ?>">Replace the file</a>
        </div>
        <?php if (aw_book_file_abs($rc_book['file_path'] ?? '') === ''): ?>
            <p class="rc-note">This book has no file on the server. Upload one with <a href="<?php echo SITE_URL; ?>/admin/manage_books.php?edit=<?php echo (int)$rc_id; ?>">Replace the file</a>.</p>
        <?php endif; ?>
    </div>
    <p class="rc-note">Formats the reader prepares: <?php echo htmlspecialchars($rc_formats); ?>. A scanned PDF (pictures of pages) has no text to show; upload a PDF exported from Word, or a DOCX or EPUB, instead.</p>

<?php else: ?>
    <h1>Reader copies</h1>
    <p class="rc-lead">Every book is prepared for the reader automatically when its file is uploaded. Members read on the site only, in scroll or page-turn mode; files are never offered for download. Formats: <?php echo htmlspecialchars($rc_formats); ?>.</p>
    <div class="rc-card" style="padding:8px 18px;">
        <?php if (!$rc_books): ?>
            <p class="rc-note">No books yet. <a href="<?php echo SITE_URL; ?>/admin/manage_books.php">Add a book</a>.</p>
        <?php else: ?>
        <table class="rc-table">
            <thead><tr><th scope="col">Book</th><th scope="col">Reader copy</th><th scope="col">File</th></tr></thead>
            <tbody>
            <?php foreach ($rc_books as $b): list($k, $label, $why) = aw_rc_status(['is_processed' => $b['is_processed'], 'processing_status' => $b['processing_status'], 'metadata_json' => $b['metadata_json'], 'content_html' => $b['has_text'] ? 'x' : '']); if ($b['is_processed'] === null && $b['processing_status'] === null) list($k, $label, $why) = aw_rc_status(null); ?>
                <tr>
                    <td><a href="<?php echo SITE_URL; ?>/admin/process_book.php?id=<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></a></td>
                    <td><span class="rc-badge rc-<?php echo $k; ?>"><?php echo htmlspecialchars($label); ?></span><?php if ($k === 'fail' && $why !== ''): ?><div class="rc-note"><?php echo htmlspecialchars($why); ?></div><?php endif; ?></td>
                    <td><?php echo !empty($b['file_path']) ? htmlspecialchars(strtoupper((string)($b['file_type'] ?: pathinfo($b['file_path'], PATHINFO_EXTENSION)))) : '<span class="rc-note">No file</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
