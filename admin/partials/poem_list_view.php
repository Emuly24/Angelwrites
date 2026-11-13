<?php
// The poem list (admin/manage_poems.php). Table on wide screens, stacked cards below 900 px.
defined('AW_POEMS_ADMIN') or exit;
$csrf = htmlspecialchars(generate_csrf_token());
?>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/admin-poems.css?v=3">
<div class="pe-page">
    <div class="pe-head">
        <div>
            <h1>Poems</h1>
            <p class="pe-sub"><?php echo number_format($poems_total); ?> poem<?php echo $poems_total === 1 ? '' : 's'; ?><?php echo $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : ''; ?></p>
        </div>
        <div class="pe-head-actions">
            <a class="btn btn-outline btn-sm" href="<?php echo SITE_URL; ?>/admin/dashboard.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
            <a class="btn btn-primary btn-sm" href="<?php echo SITE_URL; ?>/admin/manage_poems.php?new=1"><i class="fas fa-plus" aria-hidden="true"></i> New poem</a>
        </div>
    </div>

    <?php if ($flash_message !== ''): ?>
        <div class="alert alert-<?php echo $flash_type === 'error' ? 'error' : 'success'; ?>" role="<?php echo $flash_type === 'error' ? 'alert' : 'status'; ?>"><?php echo htmlspecialchars($flash_message); ?></div>
    <?php endif; ?>

    <form method="GET" class="pe-search" role="search">
        <label class="sr-only" for="poemSearch">Search poems</label>
        <input type="search" id="poemSearch" name="search" placeholder="Search by title, purpose or words in the poem" value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search !== ''): ?><a class="btn btn-outline btn-sm" href="<?php echo SITE_URL; ?>/admin/manage_poems.php">Clear</a><?php endif; ?>
    </form>

    <?php if (!$poems): ?>
        <div class="empty-state">
            <h3><?php echo $search !== '' ? 'No poems match that search' : 'No poems yet'; ?></h3>
            <p><?php echo $search !== '' ? 'Try fewer words, or clear the search.' : 'Add the first poem; it starts as a draft until you publish it.'; ?></p>
            <a class="btn btn-primary" href="<?php echo SITE_URL; ?>/admin/manage_poems.php?new=1">New poem</a>
        </div>
    <?php else: ?>
    <form method="POST" class="pe-list-form" id="poemListForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="return_page" value="<?php echo (int)$page; ?>">
        <div class="pe-bulk">
            <label class="pe-check"><input type="checkbox" id="selectAll"> <span>Select all on this page</span></label>
            <label class="sr-only" for="bulkAction">With selected poems</label>
            <select name="bulk_action" id="bulkAction">
                <option value="">With selected…</option>
                <option value="publish">Publish</option>
                <option value="draft">Move to draft</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm" id="bulkApply" disabled>Apply</button>
        </div>

        <table class="pe-table">
            <thead>
                <tr>
                    <th scope="col" class="pe-c-check"><span class="sr-only">Select</span></th>
                    <th scope="col" class="pe-c-img"><span class="sr-only">Image</span></th>
                    <th scope="col">Poem</th>
                    <th scope="col">Status</th>
                    <th scope="col">Recording</th>
                    <th scope="col">Reads</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($poems as $p):
                $pid = (int)$p['id']; $live = $p['status'] === 'published';
                $wr = trim((string)($p['written_on'] ?? ''));
            ?>
                <tr>
                    <td class="pe-c-check"><label class="pe-check"><input type="checkbox" name="ids[]" value="<?php echo $pid; ?>" class="row-select"><span class="sr-only">Select <?php echo htmlspecialchars($p['title']); ?></span></label></td>
                    <td class="pe-c-img">
                        <div class="pe-thumb"><i class="fas fa-feather-alt" aria-hidden="true"></i><?php if (!empty($p['image_path'])): ?><img src="<?php echo htmlspecialchars(SITE_URL . '/' . ltrim($p['image_path'], '/')); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
                    </td>
                    <td class="pe-c-title">
                        <a href="<?php echo SITE_URL; ?>/admin/manage_poems.php?edit=<?php echo $pid; ?>" class="pe-title"><?php echo htmlspecialchars($p['title']); ?></a>
                        <span class="pe-dates">
                            <?php if ($wr !== ''): ?>Written <?php echo htmlspecialchars(date('M j, Y', strtotime($wr))); ?> · <?php endif; ?>Added <?php echo htmlspecialchars(date('M j, Y', strtotime($p['created_at']))); ?>
                        </span>
                    </td>
                    <td data-label="Status"><span class="pe-badge <?php echo $live ? 'is-live' : 'is-draft'; ?>"><?php echo $live ? 'Live' : 'Draft'; ?></span></td>
                    <td data-label="Recording"><?php echo !empty($p['audio_path']) ? '<i class="fas fa-headphones" aria-hidden="true"></i> Yes' : 'No'; ?></td>
                    <td data-label="Reads"><?php echo number_format((int)$p['view_count']); ?></td>
                    <td class="pe-c-actions">
                        <a class="btn btn-sm btn-primary" href="<?php echo SITE_URL; ?>/admin/manage_poems.php?edit=<?php echo $pid; ?>"><i class="fas fa-pen" aria-hidden="true"></i> Edit</a>
                        <?php if ($live): ?>
                            <button type="submit" class="btn btn-sm btn-outline" name="row_action" value="draft:<?php echo $pid; ?>">Unpublish</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-outline" name="row_action" value="publish:<?php echo $pid; ?>">Publish</button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="<?php echo SITE_URL; ?>/poem_view.php?id=<?php echo $pid; ?>" target="_blank" rel="noopener"><i class="fas fa-eye" aria-hidden="true"></i><span class="sr-only"> View <?php echo htmlspecialchars($p['title']); ?> (opens in a new tab)</span></a>
                        <button type="submit" class="btn btn-sm pe-danger" name="row_action" value="delete:<?php echo $pid; ?>" data-confirm="Delete “<?php echo htmlspecialchars($p['title']); ?>” and its comments for good?"><i class="fas fa-trash" aria-hidden="true"></i><span class="sr-only"> Delete <?php echo htmlspecialchars($p['title']); ?></span></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <?php echo aw_pager($poems_total, $page, $per_page); // P8 ?>
    <?php endif; ?>
</div>
<script>
(function () {
    var form = document.getElementById('poemListForm');
    if (!form) return;
    var all = document.getElementById('selectAll'), apply = document.getElementById('bulkApply'), action = document.getElementById('bulkAction');
    var boxes = form.querySelectorAll('.row-select');
    function sync() { apply.disabled = !action.value || !form.querySelector('.row-select:checked'); }
    all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    action.addEventListener('change', sync);
    form.addEventListener('submit', function (e) {
        var btn = e.submitter;
        if (btn && btn.dataset.confirm) { if (!confirm(btn.dataset.confirm)) e.preventDefault(); return; }
        if (btn && btn.name === 'row_action') { action.value = ''; return; } // a row button: ignore the bulk choice
        var n = form.querySelectorAll('.row-select:checked').length;
        if (action.value === 'delete' && !confirm('Delete ' + n + ' poem' + (n === 1 ? '' : 's') + ' and their comments for good?')) e.preventDefault();
    });
})();
</script>
