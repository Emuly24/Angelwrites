<?php if (!defined('AW_NEWSLETTER_ADMIN')) { http_response_code(403); exit('Access denied.'); } // S13 (F175): admin/manage_newsletter.php only ?>
<?php
// Campaigns: every letter as a card with its status, progress, results (delivered, clicks, failed),
// the most-clicked link and its actions. Cancel, Resume, Retry failed and Delete are CSRF-protected
// POSTs handled in manage_newsletter.php. Opens are not counted (no tracking image: it is a junk signal).
$labels = ['pending' => 'Scheduled', 'processing' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
$icons = ['pending' => 'fa-clock', 'processing' => 'fa-paper-plane', 'sent' => 'fa-circle-check', 'failed' => 'fa-triangle-exclamation', 'cancelled' => 'fa-ban'];
?>
<div class="nlq-top">
    <p class="field-hint" style="margin:0;"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Scheduled letters send <strong>by themselves</strong> at their time (<?php echo htmlspecialchars(aw_nl_tz_label()); ?>), usually within 10 minutes of it. A send left half-done also finishes by itself.</p>
    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="send_due"><button type="submit" class="btn btn-sm btn-outline" title="Starts the oldest letter whose time has come, without waiting"><i class="fas fa-forward"></i> Send due letters now</button></form>
</div>
<?php if (!$queue): ?>
    <div class="nlq-empty"><i class="fas fa-envelope-open-text" aria-hidden="true"></i><h3>No letters yet</h3><p>Your first letter is one click away.</p><a href="?tab=send" class="btn btn-primary btn-sm">Write a letter</a></div>
<?php else: ?>
<div class="nlq-list">
    <?php foreach ($queue as $q):
        $r = aw_nl_report($db, $q['id']);
        $total = count(array_filter(explode(',', (string)$q['recipient_emails'])));
        $done = $r['sent'] + $r['failed'] + $r['skipped'];
        $pct = $total ? min(100, round($done / $total * 100)) : 0;
        $top = $r['links'] ? array_key_first($r['links']) : '';
        $when = $q['sent_at'] ?: $q['scheduled_at']; ?>
        <article class="nlq-card is-<?php echo htmlspecialchars($q['status']); ?>">
            <div class="nlq-head">
                <span class="nlq-pill"><i class="fas <?php echo $icons[$q['status']] ?? 'fa-circle'; ?>" aria-hidden="true"></i> <?php echo $labels[$q['status']] ?? ucfirst($q['status']); ?></span>
                <span class="nlq-when"><?php echo $q['status'] === 'pending' ? 'Sends ' : ''; ?><?php echo htmlspecialchars(aw_nl_when($when)); ?></span>
            </div>
            <h3><?php echo htmlspecialchars($q['subject']); ?></h3>
            <?php if (!empty($q['preheader'])): ?><p class="nlq-pre"><?php echo htmlspecialchars($q['preheader']); ?></p><?php endif; ?>
            <p class="nlq-meta"><?php echo $total; ?> recipient<?php echo $total === 1 ? '' : 's'; ?> · <?php echo htmlspecialchars(['all' => 'everyone', 'segment' => 'a segment', 'selected' => 'chosen people', 'single' => 'one person'][$q['send_to_type']] ?? $q['send_to_type']); ?></p>
            <?php if ($q['status'] !== 'pending' && $q['status'] !== 'cancelled'): ?>
                <div class="nlq-bar" role="progressbar" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Delivery progress"><span style="width:<?php echo $pct; ?>%"></span></div>
            <?php endif; ?>
            <?php if ($done): ?>
                <div class="nlq-stats">
                    <div><b><?php echo $r['sent']; ?></b><span>delivered</span></div>
                    <div><b><?php echo $r['clicked']; ?></b><span>clicked (<?php echo $r['click_rate']; ?>%)</span></div>
                    <?php if ($r['failed']): ?><div class="is-bad"><b><?php echo $r['failed']; ?></b><span>failed</span></div><?php endif; ?>
                    <?php if ($r['skipped']): ?><div><b><?php echo $r['skipped']; ?></b><span>skipped</span></div><?php endif; ?>
                </div>
                <?php if ($top !== ''): ?><p class="nlq-top-link"><i class="fas fa-arrow-pointer" aria-hidden="true"></i> Most clicked: <a href="<?php echo htmlspecialchars($top); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(mb_strimwidth(preg_replace('#^https?://#', '', $top), 0, 60, '…')); ?></a> (<?php echo (int)$r['links'][$top]; ?>)</p><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($q['last_error']) && $q['status'] !== 'sent'): ?><p class="nlq-err"><?php echo htmlspecialchars(mb_substr($q['last_error'], 0, 200)); ?></p><?php endif; ?>
            <?php if ($q['status'] === 'pending'):
                $due_in = strtotime($q['scheduled_at'] . ' UTC') - time(); ?>
                <p class="nlq-auto"><i class="fas fa-clock" aria-hidden="true"></i> <?php echo $due_in > 0 ? 'Goes out by itself in ' . htmlspecialchars(aw_nl_until($due_in)) . '.' : 'Its time has come: sending starts within a few minutes.'; ?></p>
                <details class="nlq-move">
                    <summary>Change the time</summary>
                    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="reschedule"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                        <label for="mv<?php echo (int)$q['id']; ?>" class="sr-only">New date and time</label>
                        <input type="datetime-local" id="mv<?php echo (int)$q['id']; ?>" name="schedule" required value="<?php echo htmlspecialchars(aw_nl_when($q['scheduled_at'], 'Y-m-d\TH:i')); ?>" min="<?php echo htmlspecialchars(aw_nl_local_now()); ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                </details>
            <?php endif; ?>
            <div class="nlq-acts">
                <?php if ($q['status'] === 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Send this letter now instead of at its scheduled time?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="send_now"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-paper-plane"></i> Send now</button></form>
                <?php endif; ?>
                <?php if ($q['status'] === 'processing'): ?>
                    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="send_batch"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-play"></i> Continue sending</button></form>
                <?php endif; ?>
                <?php if (in_array($q['status'], ['pending', 'processing'], true)): ?>
                    <form method="POST" onsubmit="return confirm('Cancel this letter? People not yet emailed will not get it.');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="cancel_queue"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-outline">Cancel</button></form>
                <?php endif; ?>
                <?php if ($q['status'] === 'failed' || ($q['status'] === 'sent' && $r['failed'])): ?>
                    <form method="POST"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="resend_failed"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-rotate"></i> Retry failed</button></form>
                <?php endif; ?>
                <?php if ($q['status'] !== 'pending'): ?><a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars(aw_nl_web_url($db, $q['id'])); ?>" target="_blank" rel="noopener"><i class="fas fa-globe"></i> View</a><?php endif; ?>
                <form method="POST" onsubmit="return confirm('Delete this letter and its results?');"><?php echo aw_csrf_field(); ?><input type="hidden" name="action" value="delete_queue"><input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>"><button type="submit" class="btn btn-sm btn-outline nlq-del" aria-label="Delete “<?php echo htmlspecialchars($q['subject']); ?>”"><i class="fas fa-trash"></i></button></form>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php echo aw_pager($queue_count, $q_page, $q_per, '', 'qpage'); // P18 ?>
<?php endif; ?>
<style>
.nlq-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.nlq-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.nlq-card{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:8px;box-shadow:0 2px 10px rgba(61,46,46,.04)}
.nlq-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.nlq-pill{display:inline-flex;align-items:center;gap:6px;font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:999px;background:#eee;color:#4a3c3c}
.is-sent .nlq-pill{background:#e3f1e7;color:#24553a}.is-processing .nlq-pill{background:#e6eefb;color:#2f4f86}.is-pending .nlq-pill{background:#fbf0de;color:#7a5410}
.is-failed .nlq-pill{background:#fbe5e3;color:#8a2d23}.is-cancelled .nlq-pill{background:#eee;color:#666}
.nlq-when{font-size:.8rem;color:var(--text-light)}
.nlq-card h3{margin:2px 0 0;font:700 1.12rem/1.35 'Playfair Display',Georgia,serif}
.nlq-pre{margin:0;color:var(--text-light);font-size:.88rem;font-style:italic}
.nlq-meta{margin:0;font-size:.82rem;color:var(--text-light)}
.nlq-bar{height:6px;border-radius:6px;background:var(--vanilla);overflow:hidden}.nlq-bar span{display:block;height:100%;background:linear-gradient(90deg,#c97c7f,#9c5457)}
.nlq-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:8px}
.nlq-stats div{background:var(--vanilla);border-radius:12px;padding:8px 10px}.nlq-stats b{display:block;font:700 1.25rem/1.1 'Playfair Display',Georgia,serif}.nlq-stats span{font-size:.75rem;color:var(--text-light)}
.nlq-stats .is-bad b{color:#b4505a}
.nlq-top-link{margin:0;font-size:.82rem;color:var(--text-light);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nlq-err{margin:0;font-size:.8rem;color:#8a2d23;background:#fbe5e3;border-radius:8px;padding:6px 10px}
.nlq-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:6px}.nlq-acts form{margin:0}
.nlq-del{margin-left:auto}
.nlq-auto{margin:0;font-size:.85rem;color:#7a5410;background:#fbf0de;border-radius:10px;padding:7px 10px}
.nlq-move summary{cursor:pointer;font-size:.85rem;color:var(--text-light)}.nlq-move form{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.nlq-move input{flex:1;min-width:180px}
html[data-theme="dark"] .nlq-auto{background:#3a2f1c;color:#f0d9a8}
.nlq-empty{text-align:center;padding:50px 16px;border:1px dashed var(--border);border-radius:18px}.nlq-empty i{font-size:2rem;color:#c97c7f}
html[data-theme="dark"] .nlq-pill{filter:brightness(.85)}
</style>
