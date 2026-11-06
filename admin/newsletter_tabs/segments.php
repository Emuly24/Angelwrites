<div class="card">
<?php
// N3: segments with active / total members; create and delete are CSRF-protected.
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
?>
    <div class="card-header">
        <h2>🏷️ Segments</h2>
        <button class="btn btn-sm btn-primary" type="button" onclick="var f=document.getElementById('createSegmentForm');f.hidden=!f.hidden;">+ New segment</button>
    </div>
    <div class="card-body">
        <p class="field-hint" style="margin-top:0;">Segments are groups of subscribers (e.g. "Book club", "Prayer partners") so a newsletter can go to just that group. Add people from the Subscribers tab.</p>
        <div id="createSegmentForm" hidden style="margin-bottom:20px;padding:16px;border:1px solid var(--border);border-radius:8px;">
            <form method="POST" class="admin-form">
                <?php echo aw_csrf_field(); ?>
                <input type="hidden" name="action" value="create_segment">
                <div class="form-group"><label for="segment_name">Name</label><input type="text" id="segment_name" name="segment_name" maxlength="60" required></div>
                <div class="form-group"><label for="segment_description">Description</label><textarea id="segment_description" name="segment_description" rows="2"></textarea></div>
                <button type="submit" class="btn btn-primary">Create segment</button>
            </form>
        </div>
        <?php if ($segments): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Name</th><th>Description</th><th>Members</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($segments as $seg): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($seg['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($seg['description'] ?: '—'); ?></td>
                            <td><?php echo (int)$seg['active_members']; ?> active<?php if ($seg['members'] > $seg['active_members']): ?> · <?php echo (int)$seg['members']; ?> in total<?php endif; ?></td>
                            <td><?php echo date('M j, Y', strtotime($seg['created_at'])); ?></td>
                            <td><div class="nl-row">
                                <a class="btn btn-sm btn-outline" href="?tab=subscribers&amp;segment=<?php echo (int)$seg['id']; ?>">View</a>
                                <form method="POST" onsubmit="return confirm('Delete this segment? Its subscribers stay on the list.');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_segment"><input type="hidden" name="segment_id" value="<?php echo (int)$seg['id']; ?>"><button type="submit" class="btn btn-sm btn-danger" aria-label="Delete segment <?php echo htmlspecialchars($seg['name']); ?>"><i class="fas fa-trash"></i></button></form>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">No segments yet.</p>
        <?php endif; ?>
    </div>
</div>
