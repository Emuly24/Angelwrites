<div class="card">
    <div class="card-header">
        <h2>🕵️ Audit Log</h2>
    </div>
    <div class="card-body">
        <?php if (count($audit_log) > 0): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_log as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['user_id'] ?? 'System'); ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                                <td><?php echo date('M j, Y, g:i a', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">No audit log entries.</p>
        <?php endif; ?>
    </div>
</div>