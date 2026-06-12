<div class="card">
    <div class="card-header">
        <h2>⏳ Email Queue (<?php echo count($queue); ?>)</h2>
    </div>
    <div class="card-body">
        <?php if (count($queue) > 0): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Recipients</th>
                            <th>Status</th>
                            <th>Scheduled</th>
                            <th>Attempts</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue as $q): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($q['subject']); ?></td>
                                <td><?php echo count(explode(',', $q['recipient_emails'])); ?> recipients</td>
                                <td>
                                    <span class="status-badge <?php echo $q['status']; ?>">
                                        <?php echo ucfirst($q['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y, g:i a', strtotime($q['scheduled_at'])); ?></td>
                                <td><?php echo $q['attempt_count'] ?? 0; ?></td>
                                <td>
                                    <?php if ($q['status'] === 'pending' || $q['status'] === 'processing'): ?>
                                        <a href="?cancel_queue=<?php echo $q['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Cancel this email?');">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($q['status'] === 'failed'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="resend_failed">
                                            <input type="hidden" name="queue_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning">🔄 Resend</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="?delete_queue=<?php echo $q['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this queue entry?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="no-items">No emails in the queue.</p>
        <?php endif; ?>
    </div>
</div>