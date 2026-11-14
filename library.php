<?php
/**
 * F3: My Library (PROGRAM_PLAN.md Phase 4, wish F).
 *   Continue reading   books you have open, straight back into the reader (no progress bars)
 *   Saved              everything you saved, by collection (includes/saves.php)
 *   Book lists         want to read, finished
 *   Groups             your reading groups, and open groups you can join
 *   Your contributions what you have shared and written
 * The book page's "My library" form still posts here (update_status / remove_status), now with CSRF.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/components.php';
require_once 'includes/saves.php';
require_once 'includes/book_card.php';

redirectIfNotLoggedIn();
$uid = (int)$_SESSION['user_id'];
aw_save_tables($db);
$error = ''; $success = '';
$back = function ($q = '', $anchor = '') { header('Location: ' . SITE_URL . '/library.php' . ($q !== '' ? '?' . $q : '') . ($anchor !== '' ? '#' . $anchor : '')); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['update_status'])) {
        $bid = (int)($_POST['book_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($bid && in_array($status, ['currently reading', 'want to read', 'finished'], true)) {
            try {
                $st = $db->prepare("SELECT id FROM reading_status WHERE user_id = ? AND book_id = ?");
                $st->execute([$uid, $bid]);
                if ($st->fetchColumn()) $db->prepare("UPDATE reading_status SET status = ?, updated_at = CURRENT_TIMESTAMP" . ($status === 'finished' ? ", finished_at = CURRENT_TIMESTAMP" : '') . " WHERE user_id = ? AND book_id = ?")->execute([$status, $uid, $bid]);
                else $db->prepare("INSERT INTO reading_status (user_id, book_id, status) VALUES (?, ?, ?)")->execute([$uid, $bid, $status]);
            } catch (Throwable $e) { error_log('library status: ' . $e->getMessage()); }
            $ret = (string)($_POST['return'] ?? '');
            if (preg_match('#^/book\.php\?id=\d+$#', $ret)) { header('Location: ' . SITE_URL . $ret . '&library=1'); exit; }
            $back('msg=status', 'books');
        }
    } elseif (isset($_POST['remove_status'])) {
        try { $db->prepare("DELETE FROM reading_status WHERE user_id = ? AND book_id = ?")->execute([$uid, (int)($_POST['book_id'] ?? 0)]); } catch (Throwable $e) { error_log('library remove: ' . $e->getMessage()); }
        $back('msg=removed', 'books');
    } elseif (isset($_POST['new_collection'])) {
        $err = '';
        $cid = aw_collection_create($db, $uid, (string)$_POST['new_collection'], $err);
        if ($cid) $back('c=' . $cid . '&msg=created', 'saved');
        $error = $err;
    } elseif (isset($_POST['rename_collection'])) {
        aw_collection_rename($db, $uid, (int)$_POST['rename_collection'], (string)($_POST['name'] ?? '')) ? $back('c=' . (int)$_POST['rename_collection'] . '&msg=renamed', 'saved') : $error = 'Please give the collection a name (up to 60 characters).';
    } elseif (isset($_POST['delete_collection'])) {
        aw_collection_delete($db, $uid, (int)$_POST['delete_collection']);
        $back('msg=deleted', 'saved');
    }
}
$success = ['status' => 'Your book list was updated.', 'removed' => 'The book was taken off your lists.', 'created' => 'Collection created. Use Save on anything to file it here.',
            'renamed' => 'Collection renamed.', 'deleted' => 'Collection deleted. What was in it is still in your library.'][$_GET['msg'] ?? ''] ?? '';
if (!empty($_GET['save_error'])) $error = 'That could not be saved.';

// ----- Continue reading: books open in the reader, most recent first -----
$continue = [];
try {
    $st = $db->prepare("SELECT b.*, rp.last_accessed_at, COALESCE(CAST(rp.position_section AS INTEGER), rs.last_read_page) AS page
                        FROM books b
                        LEFT JOIN reading_progress rp ON rp.book_id = b.id AND rp.user_id = ?
                        LEFT JOIN reading_status rs ON rs.book_id = b.id AND rs.user_id = ?
                        WHERE (rs.status = 'currently reading' OR (rp.id IS NOT NULL AND rp.finished_at IS NULL AND COALESCE(rs.status, '') <> 'finished'))
                        ORDER BY COALESCE(rp.last_accessed_at, rs.updated_at) DESC LIMIT 12");
    $st->execute([$uid, $uid]);
    $continue = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('library continue: ' . $e->getMessage()); }

// planned reads (reader v1) that are due
$plans = [];
if (is_file(__DIR__ . '/includes/reading_plans.php')) {
    require_once __DIR__ . '/includes/reading_plans.php';
    $plans = aw_plans_upcoming($db, $uid, 6);
}

// ----- Saved -----
$collections = aw_collections($db, $uid);
$cid = isset($_GET['c']) ? (int)$_GET['c'] : null;   // null all, 0 not in a collection
if ($cid && !array_filter($collections, function ($c) use ($cid) { return (int)$c['id'] === $cid; })) $cid = null;
$saves = aw_saves_list($db, $uid, $cid);
$curName = $cid === null ? 'Everything you saved' : ($cid === 0 ? 'Not in a collection' : '');
foreach ($collections as $c) if ((int)$c['id'] === $cid) $curName = $c['name'];

// ----- Book lists -----
$lists = ['want to read' => [], 'finished' => []];
try {
    $st = $db->prepare("SELECT b.*, rs.status, rs.finished_at, rs.updated_at FROM reading_status rs JOIN books b ON b.id = rs.book_id
                        WHERE rs.user_id = ? AND rs.status IN ('want to read', 'finished') ORDER BY rs.updated_at DESC");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $lists[$b['status']][] = $b;
} catch (Throwable $e) { error_log('library lists: ' . $e->getMessage()); }

// ----- Groups -----
$myGroups = []; $openGroups = [];
try {
    $st = $db->prepare("SELECT g.id, g.name, g.description, (SELECT COUNT(*) FROM group_members m2 WHERE m2.group_id = g.id) AS members
                        FROM reading_groups g JOIN group_members m ON m.group_id = g.id AND m.user_id = ? ORDER BY g.name");
    $st->execute([$uid]);
    $myGroups = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT g.id, g.name, g.description, (SELECT COUNT(*) FROM group_members m2 WHERE m2.group_id = g.id) AS members
                        FROM reading_groups g WHERE COALESCE(g.is_private, 0) = 0 AND g.id NOT IN (SELECT group_id FROM group_members WHERE user_id = ?)
                        ORDER BY g.created_at DESC LIMIT 6");
    $st->execute([$uid]);
    $openGroups = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('library groups: ' . $e->getMessage()); }

// ----- Your contributions -----
$contrib = ['stories' => 0, 'comments' => []];
try { $st = $db->prepare("SELECT COUNT(*) FROM community_posts WHERE author_id = ? AND status <> 'removed'"); $st->execute([$uid]); $contrib['stories'] = (int)$st->fetchColumn(); } catch (Throwable $e) { /* no Stories & Help yet */ }
try {
    $st = $db->prepare("SELECT 'review' AS kind, comment AS text, created_at, CASE target_type WHEN 'poem' THEN 'poem_view.php?id=' || target_id WHEN 'book' THEN 'book.php?id=' || target_id ELSE 'index.php' END || '#comments' AS link
                        FROM reviews WHERE user_id = ? AND deleted_at IS NULL
                        UNION ALL SELECT 'reflection', comment, created_at, 'blog_post.php?slug=' || (SELECT slug FROM blog_posts b WHERE b.id = reflection_id) || '#comments' FROM reflection_comments WHERE user_id = ?
                        UNION ALL SELECT 'video', content, created_at, 'video_watch.php?id=' || video_id FROM video_comments WHERE user_id = ?
                        ORDER BY created_at DESC LIMIT 6");
    $st->execute([$uid, $uid, $uid]);
    $contrib['comments'] = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('library contributions: ' . $e->getMessage()); }

$token = generate_csrf_token();
$pageTitle = 'My Library';
require_once 'includes/header.php';
echo aw_book_assets();
echo aw_save_assets();
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/aw-library.css?v=1">
<main class="lib" id="main">
<div class="container">
    <header class="lib-head">
        <h1>My Library</h1>
        <p>Everything you are reading and everything you saved, in one quiet place.</p>
        <nav class="lib-jump" aria-label="Sections">
            <a href="#continue">Continue reading</a><a href="#saved">Saved</a><a href="#books">Book lists</a><a href="#groups">Groups</a><a href="#contrib">Your contributions</a>
        </nav>
    </header>
    <?php echo aw_alert('error', $error); echo aw_alert('success', $success); ?>

    <section class="lib-sec" id="continue" aria-labelledby="hContinue">
        <h2 id="hContinue">Continue reading</h2>
        <?php if ($plans): ?>
            <ul class="lib-plans" aria-label="Your planned reads">
            <?php foreach ($plans as $pl): ?>
                <li><i class="far fa-calendar" aria-hidden="true"></i> <span><strong><?php echo htmlspecialchars(aw_plan_when($pl['plan_date'])); ?>:</strong> <?php echo htmlspecialchars($pl['book_title']); ?><?php echo $pl['label'] ? ' — ' . htmlspecialchars($pl['label']) : ''; ?></span>
                    <a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$pl['book_id']; ?><?php echo $pl['page'] ? '&page=' . (int)$pl['page'] : ''; ?>">Read now</a></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!$continue): ?>
            <?php echo aw_empty('Nothing open yet', 'When you start reading a book, it waits for you here, at the page where you stopped.', [SITE_URL . '/books.php', 'Browse books'], 'fa-book-open'); ?>
        <?php else: ?>
            <div class="bk-grid">
            <?php foreach ($continue as $b): ?>
                <article class="bk-card">
                    <a class="bk-cover" href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$b['id']; ?>" tabindex="-1" aria-hidden="true">
                        <?php if ($u = aw_book_cover_url($b)): ?><img src="<?php echo htmlspecialchars($u); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                        <span class="bk-cover-ph"><span><?php echo htmlspecialchars($b['title']); ?></span></span>
                    </a>
                    <div class="bk-body">
                        <h3 class="bk-title"><a href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></a></h3>
                        <p class="bk-author"><?php echo $b['page'] ? 'Page ' . (int)$b['page'] : 'Not started'; ?><?php echo $b['last_accessed_at'] ? ' · ' . htmlspecialchars(date('j M', strtotime($b['last_accessed_at']))) : ''; ?></p>
                        <div class="bk-foot"><span class="bk-actions"><a class="bk-btn bk-btn-primary" href="<?php echo SITE_URL; ?>/reader/reader.php?id=<?php echo (int)$b['id']; ?>" aria-label="Continue reading <?php echo htmlspecialchars($b['title']); ?>">Continue</a></span></div>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="lib-sec" id="saved" aria-labelledby="hSaved">
        <div class="lib-sec-head">
            <h2 id="hSaved">Saved</h2>
            <form method="post" class="lib-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                <label class="sr-only" for="newCol">New collection name</label>
                <input type="text" id="newCol" name="new_collection" maxlength="60" placeholder="New collection" required>
                <button type="submit" class="lib-btn">Create</button>
            </form>
        </div>
        <nav class="lib-tabs" aria-label="Collections">
            <a href="?#saved"<?php echo $cid === null ? ' aria-current="page"' : ''; ?>>Everything</a>
            <?php foreach ($collections as $c): ?><a href="?c=<?php echo (int)$c['id']; ?>#saved"<?php echo $cid === (int)$c['id'] ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($c['name']); ?> <small><?php echo (int)$c['n']; ?></small></a><?php endforeach; ?>
            <?php if ($collections): ?><a href="?c=0#saved"<?php echo $cid === 0 ? ' aria-current="page"' : ''; ?>>Not in a collection</a><?php endif; ?>
        </nav>
        <?php if ($cid): ?>
            <details class="lib-manage"><summary>Rename or delete “<?php echo htmlspecialchars($curName); ?>”</summary>
                <form method="post" class="lib-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                    <label class="sr-only" for="renCol">New name</label><input type="text" id="renCol" name="name" maxlength="60" value="<?php echo htmlspecialchars($curName); ?>" required>
                    <button type="submit" name="rename_collection" value="<?php echo (int)$cid; ?>" class="lib-btn">Rename</button></form>
                <form method="post" class="lib-inline" onsubmit="return confirm('Delete this collection? What is in it stays in your library.');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                    <button type="submit" name="delete_collection" value="<?php echo (int)$cid; ?>" class="lib-btn lib-btn-quiet">Delete collection</button></form>
            </details>
        <?php endif; ?>
        <?php if (!$saves): ?>
            <?php echo aw_empty($cid === null ? 'Nothing saved yet' : 'This collection is empty', 'Use the Save button on a poem, a story, a reflection, a video, a book, a verse or a reading group, and it appears here.', [SITE_URL . '/poetry.php', 'Find something to save'], 'fa-bookmark'); ?>
        <?php else: ?>
            <ul class="lib-saves">
            <?php foreach ($saves as $s): ?>
                <li class="lib-save">
                    <a class="lib-thumb" href="<?php echo htmlspecialchars($s['link']); ?>" tabindex="-1" aria-hidden="true"><?php if ($s['image']): ?><img src="<?php echo htmlspecialchars($s['image']); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?><i class="fas <?php echo htmlspecialchars($s['icon']); ?>"></i></a>
                    <div class="lib-save-body">
                        <p class="lib-kind"><?php echo htmlspecialchars($s['label']); ?><?php echo $s['type'] !== 'verse' && $s['sub'] !== '' ? ' · ' . htmlspecialchars($s['sub']) : ''; ?></p>
                        <h3><a href="<?php echo htmlspecialchars($s['link']); ?>"><?php echo htmlspecialchars($s['title']); ?></a></h3>
                        <?php if ($s['type'] === 'verse'): ?><p class="lib-verse">“<?php echo htmlspecialchars(mb_strimwidth($s['sub'], 0, 220, '…')); ?>”</p><?php endif; ?>
                    </div>
                    <div class="lib-save-acts">
                        <form method="post" action="<?php echo SITE_URL; ?>/save.php" class="lib-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="type" value="<?php echo htmlspecialchars($s['type']); ?>"><input type="hidden" name="key" value="<?php echo htmlspecialchars($s['key']); ?>">
                            <input type="hidden" name="action" value="save"><input type="hidden" name="back" value="/library.php<?php echo $cid !== null ? '?c=' . (int)$cid : ''; ?>#saved">
                            <label class="sr-only" for="mv<?php echo $s['save_id']; ?>">Collection for <?php echo htmlspecialchars($s['title']); ?></label>
                            <select id="mv<?php echo $s['save_id']; ?>" name="collection_id" onchange="this.form.submit()">
                                <option value="0"<?php echo !$s['collection_id'] ? ' selected' : ''; ?>>No collection</option>
                                <?php foreach ($collections as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $s['collection_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                            </select>
                            <noscript><button type="submit" class="lib-btn">Move</button></noscript>
                        </form>
                        <form method="post" action="<?php echo SITE_URL; ?>/save.php" class="lib-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="type" value="<?php echo htmlspecialchars($s['type']); ?>"><input type="hidden" name="key" value="<?php echo htmlspecialchars($s['key']); ?>">
                            <input type="hidden" name="action" value="remove"><input type="hidden" name="back" value="/library.php<?php echo $cid !== null ? '?c=' . (int)$cid : ''; ?>#saved">
                            <button type="submit" class="lib-link" aria-label="Remove <?php echo htmlspecialchars($s['title']); ?> from your library">Remove</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="lib-sec" id="books" aria-labelledby="hBooks">
        <h2 id="hBooks">Book lists</h2>
        <div class="lib-cols">
        <?php foreach (['want to read' => 'Want to read', 'finished' => 'Finished'] as $k => $title): ?>
            <div class="lib-col">
                <h3><?php echo $title; ?> <small><?php echo count($lists[$k]); ?></small></h3>
                <?php if (!$lists[$k]): ?><p class="lib-muted"><?php echo $k === 'finished' ? 'Books you finish are kept here.' : 'Choose “Want to read” on a book page to keep it for later.'; ?></p>
                <?php else: ?><ul class="lib-rows">
                    <?php foreach ($lists[$k] as $b): ?>
                    <li>
                        <a href="<?php echo SITE_URL; ?>/book.php?id=<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['title']); ?></a>
                        <span class="lib-row-acts">
                            <?php if ($k === 'want to read'): ?>
                            <form method="post" class="lib-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="book_id" value="<?php echo (int)$b['id']; ?>"><input type="hidden" name="status" value="currently reading"><button class="lib-link" name="update_status" value="1">Start reading</button></form>
                            <?php endif; ?>
                            <form method="post" class="lib-inline" onsubmit="return confirm('Take this book off your lists?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>"><input type="hidden" name="book_id" value="<?php echo (int)$b['id']; ?>"><button class="lib-link" name="remove_status" value="1" aria-label="Take <?php echo htmlspecialchars($b['title']); ?> off your lists">Remove</button></form>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="lib-sec" id="groups" aria-labelledby="hGroups">
        <h2 id="hGroups">Groups</h2>
        <div class="lib-cols">
            <div class="lib-col">
                <h3>Your groups</h3>
                <?php if (!$myGroups): ?><p class="lib-muted">You are not in a reading group yet.</p>
                <?php else: ?><ul class="lib-rows"><?php foreach ($myGroups as $g): ?><li><a href="<?php echo SITE_URL; ?>/group.php?id=<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></a><span class="lib-muted"><?php echo (int)$g['members']; ?> members</span></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
            <div class="lib-col">
                <h3>Groups you can join</h3>
                <?php if (!$openGroups): ?><p class="lib-muted">No open groups right now. <a href="<?php echo SITE_URL; ?>/create_group.php">Start one</a>.</p>
                <?php else: ?><ul class="lib-rows"><?php foreach ($openGroups as $g): ?><li><a href="<?php echo SITE_URL; ?>/group.php?id=<?php echo (int)$g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></a><span class="lib-muted"><?php echo (int)$g['members']; ?> members</span></li><?php endforeach; ?></ul>
                <p><a href="<?php echo SITE_URL; ?>/groups.php">See all groups</a></p><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="lib-sec" id="contrib" aria-labelledby="hContrib">
        <h2 id="hContrib">Your contributions</h2>
        <div class="lib-cols">
            <div class="lib-col">
                <h3>Stories &amp; Help</h3>
                <p class="lib-muted"><?php echo $contrib['stories'] ? 'You have shared ' . $contrib['stories'] . ($contrib['stories'] === 1 ? ' post' : ' posts') . '. Only you can see this list.' : 'Nothing shared yet.'; ?></p>
                <p><a href="<?php echo SITE_URL; ?>/my_stories.php">My Stories &amp; Help</a></p>
            </div>
            <div class="lib-col">
                <h3>Recent comments</h3>
                <?php if (!$contrib['comments']): ?><p class="lib-muted">Your comments on poems, books, reflections and videos appear here.</p>
                <?php else: ?><ul class="lib-rows"><?php foreach ($contrib['comments'] as $c): ?><li><a href="<?php echo htmlspecialchars(SITE_URL . '/' . $c['link']); ?>"><?php echo htmlspecialchars(mb_strimwidth(trim(strip_tags((string)$c['text'])), 0, 80, '…')); ?></a><span class="lib-muted"><?php echo htmlspecialchars(date('j M', strtotime($c['created_at']))); ?></span></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
        </div>
    </section>
</div>
</main>
<?php require_once 'includes/footer.php'; ?>
