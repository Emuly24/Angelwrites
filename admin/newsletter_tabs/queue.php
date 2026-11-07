<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<div class="card">
<?php
// N2/N5: campaigns with their numbers; Cancel, Resume, Retry failed and Delete now work (they were
// links to ?cancel_queue= / ?delete_queue= that nothing handled) and are CSRF-protected POSTs.
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
$labels = ['pending' => 'Scheduled', 'processing' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
?>
    <div class="card-header">
        <h2>⏳ Campaigns</h2>
        <form method="POST" style="display:inline;">
            <?php echo aw_csrf_field(); ?>
            <input type="hidden" name="action" value="send_due">
            <button type="submit" class="btn btn-sm btn-primary">📤 Send queued now</button>
        </form>
    </div>
    <div class="card-body">
        <p class="field-hint" style="margin-top:0;">Scheduled newsletters are not sent automatically (this host has no timer). Press <strong>Send queued now</strong> on or after their time; it sends the oldest one that is due.</p>
        <?php if ($queue): ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Subject</th><th>Status</th><th>When</th><th>Results</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($queue as $q):
                        $r = aw_nl_report($db, $q['id']);
                        $total = count(array_filter(explode(',', (string)$q['recipient_emails']))); ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($q['subject']); ?></strong><br><small class="field-hint"><?php echo $total; ?> recipient<?php echo $total === 1 ? '' : 's'; ?> · <?php echo htmlspecialchars(['all' => 'everyone', 'segment' => 'segment', 'selected' => 'chosen', 'single' => 'one person'][$q['send_to_type']] ?? $q['send_to_type']); ?></small></td>
                            <td><span class="status-badge <?php echo htmlspecialchars($q['status']); ?>"><?php echo $labels[$q['status']] ?? ucfirst($q['status']); ?></span></td>
                            <td><?php echo date('M j, Y, g:i a', strtotime($q['sent_at'] ?: $q['scheduled_at'])); ?></td>
                            <td style="min-width:220px;">
                                <?php if ($r['sent'] || $r['failed'] || $r['skipped']): ?>
                                    <div class="nl-report">
                                        <div><b><?php echo $r['sent']; ?></b>sent</div>
                                        <div><b><?php echo $r['open_rate']; ?>%</b>opened</div>
                                        <div><b><?php echo $r['click_rate']; ?>%</b>clicked</div>
                                        <?php if ($r['failed']): ?><div><b><?php echo $r['failed']; ?></b>failed</div><?php endif; ?>
                                    </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><div class="nl-row">
                                <?php if ($q['status'] === 'processing'): ?>
                                    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="send_batch"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-primary" title="Continue an interrupted send">▶ Resume</button></form>
                                <?php endif; ?>
                                <?php if (in_array($q['status'], ['pending', 'processing'], true)): ?>
                                    <form method="POST" onsubmit="return confirm('Cancel this campaign? People not yet emailed will not get it.');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="cancel_queue"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-secondary" aria-label="Cancel campaign"><i class="fas fa-ban"></i></button></form>
                                <?php endif; ?>
                                <?php if ($q['status'] === 'failed' || ($q['status'] === 'sent' && $r['failed'])): ?>
                                    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="resend_failed"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-warning" title="Send again only to the addresses that failed">🔄 Retry failed</button></form>
                                <?php endif; ?>
                                <?php if ($q['status'] === 'sent'): ?>
                                    <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars(aw_nl_web_url($db, $q['id'])); ?>" target="_blank" rel="noopener" aria-label="Web version"><i class="fas fa-globe"></i></a>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Delete this campaign and its statistics?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_queue"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-danger" aria-label="Delete campaign"><i class="fas fa-trash"></i></button></form>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php echo aw_pager($queue_count, $q_page, $q_per, '', 'qpage'); // P18 ?>
            </div>
        <?php else: ?>
            <p class="no-items">No campaigns yet.</p>
        <?php endif; ?>
    </div>
</div>
