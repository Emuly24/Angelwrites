<?php
// F168: this tab was required by admin/manage_newsletter.php but never existed (fatal).
// Direct requests are refused, as for the other tab partials (fix/security-2 S13 defines
// AW_NEWSLETTER_ADMIN; the script-name check keeps this working before that merges).
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') {
    http_response_code(403);
    exit('Access denied.');
}
?>
<div class="card">
    <div class="card-header">
        <h2>👥 Subscribers (<?php echo count($subscribers); ?>, <?php echo (int)$active_count; ?> active)</h2>
    </div>
    <div class="card-body">
        <?php if (count($subscribers) > 0): ?>
            <form method="POST" id="bulkSubscribersForm" onsubmit="return collectSelectedSubscribers();">
                <input type="hidden" name="action" value="bulk_subscribers">
                <input type="hidden" name="selected_ids" id="selectedSubscriberIds" value="">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">
                    <select name="bulk_action" id="bulkSubscriberAction" required>
                        <option value="">Bulk action…</option>
                        <option value="unsubscribe">Unsubscribe</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Apply to selected</button>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllSubscribers" aria-label="Select all"></th>
                                <th>Email</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Subscribed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscribers as $sub): ?>
                                <tr>
                                    <td><input type="checkbox" class="bulk-subscriber" value="<?php echo (int)$sub['id']; ?>" aria-label="Select <?php echo htmlspecialchars($sub['email']); ?>"></td>
                                    <td><?php echo htmlspecialchars($sub['email']); ?></td>
                                    <td><?php echo htmlspecialchars($sub['name'] ?? ''); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $sub['is_active'] ? 'sent' : 'failed'; ?>">
                                            <?php echo $sub['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $sub['subscribed_at'] ? date('M j, Y', strtotime($sub['subscribed_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <script>
            document.getElementById('selectAllSubscribers').addEventListener('change', function () {
                document.querySelectorAll('.bulk-subscriber').forEach(cb => { cb.checked = this.checked; });
            });
            function collectSelectedSubscribers() {
                const ids = Array.from(document.querySelectorAll('.bulk-subscriber:checked')).map(cb => cb.value);
                if (ids.length === 0) { alert('Select at least one subscriber.'); return false; }
                const action = document.getElementById('bulkSubscriberAction').value;
                if (action === 'delete' && !confirm('Delete ' + ids.length + ' subscriber(s)? This cannot be undone.')) return false;
                document.getElementById('selectedSubscriberIds').value = ids.join(',');
                return true;
            }
            </script>
        <?php else: ?>
            <p class="no-items">No subscribers yet.</p>
        <?php endif; ?>
    </div>
</div>
