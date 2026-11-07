<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<div class="card">
    <div class="card-header">
        <h2>⏳ Email Queue (<?php echo count($queue); ?>)</h2>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="send_due">
            <button type="submit" class="btn btn-sm btn-primary">📤 Send queued now</button>
        </form>
    </div>
    <div class="card-body">
        <p class="field-hint" style="margin-top:0;">Scheduled newsletters are not sent automatically (this host has no timer). Press <strong>Send queued now</strong> on or after their date; it sends the oldest one that is due.</p>
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
                                    <?php if ($q['status'] === 'processing'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="send_batch">
                                            <input type="hidden" name="queue_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" title="Continue an interrupted send">▶ Resume</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($q['status'] === 'failed' || ($q['status'] === 'sent' && !empty($q['last_error']))): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="resend_failed">
                                            <input type="hidden" name="queue_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Re-send only to the recipients that failed">🔄 Retry failed</button>
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