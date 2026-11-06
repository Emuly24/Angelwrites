<div class="card">
<?php
// N5: every sent issue with its report: delivered, opens, clicks, the most-clicked links, a preview
// and the public web version. Delete is a CSRF-protected POST (the old ?delete_archive= link did nothing).
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') { http_response_code(403); exit('Access denied.'); }
?>
    <div class="card-header"><h2>📊 Sent issues &amp; reports</h2></div>
    <div class="card-body">
        <p class="field-hint" style="margin-top:0;">Opens are counted when the reader's email app shows images, so the real number is usually higher. Clicks are counted exactly.</p>
        <?php if ($archive): ?>
            <?php foreach ($archive as $item):
                $r = $item['email_queue_id'] ? aw_nl_report($db, $item['email_queue_id']) : null; ?>
                <article style="border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:12px;">
                    <div class="nl-row" style="justify-content:space-between;">
                        <div><strong><?php echo htmlspecialchars($item['subject']); ?></strong><br><small class="field-hint">Sent <?php echo date('M j, Y, g:i a', strtotime($item['sent_at'])); ?> to <?php echo (int)$item['recipient_count']; ?></small></div>
                        <div class="nl-row">
                            <a class="btn btn-sm btn-outline" href="<?php echo SITE_URL; ?>/admin/newsletter_preview.php?id=<?php echo (int)$item['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i> Preview</a>
                            <?php if ($item['email_queue_id']): ?><a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars(aw_nl_web_url($db, $item['email_queue_id'])); ?>" target="_blank" rel="noopener"><i class="fas fa-globe"></i> Web version</a><?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Remove this issue from the archive?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_archive"><input type="hidden" name="archive_id" value="<?php echo (int)$item['id']; ?>"><button type="submit" class="btn btn-sm btn-danger" aria-label="Remove from archive"><i class="fas fa-trash"></i></button></form>
                        </div>
                    </div>
                    <?php if ($r): ?>
                        <div class="nl-report" style="margin-top:10px;">
                            <div><b><?php echo $r['sent']; ?></b>delivered</div>
                            <div><b><?php echo $r['opened']; ?> · <?php echo $r['open_rate']; ?>%</b>opened</div>
                            <div><b><?php echo $r['clicked']; ?> · <?php echo $r['click_rate']; ?>%</b>clicked</div>
                            <div><b><?php echo $r['failed']; ?></b>failed</div>
                            <div><b><?php echo $r['skipped']; ?></b>skipped</div>
                        </div>
                        <?php if ($r['links']): ?>
                            <details style="margin-top:8px;"><summary>Most-clicked links</summary><ol style="margin:6px 0 0 18px;">
                                <?php foreach (array_slice($r['links'], 0, 5, true) as $url => $n): ?><li><a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(mb_strimwidth($url, 0, 70, '…')); ?></a> – <?php echo $n; ?> reader<?php echo $n === 1 ? '' : 's'; ?></li><?php endforeach; ?>
                            </ol></details>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php echo aw_pager($archive_total, $a_page, $a_per, '', 'apage'); // P18 ?>
        <?php else: ?>
            <p class="no-items">No newsletters have been sent yet.</p>
        <?php endif; ?>
    </div>
</div>
