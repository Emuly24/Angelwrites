<div class="card">
<?php
// N2: who did what (with names, not ids); the last 100 entries.
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
?>
    <div class="card-header"><h2>🕵️ Audit log</h2></div>
    <div class="card-body">
        <?php if ($audit_log): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit_log as $log): ?>
                        <tr>
                            <td><?php echo date('M j, Y, g:i a', strtotime($log['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($log['user_name'] ?: ($log['user_id'] ? '#' . $log['user_id'] : 'Subscriber / system')); ?></td>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></td>
                            <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">Nothing logged yet.</p>
        <?php endif; ?>
    </div>
</div>
