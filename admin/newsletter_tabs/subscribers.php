<?php
// F168: this tab was required by admin/manage_newsletter.php but never existed (fatal).
// Direct requests are refused, as for the other tab partials (fix/security-2 S13 defines
// AW_NEWSLETTER_ADMIN; the script-name check keeps this working before that merges).
if (!defined('AW_NEWSLETTER_ADMIN') && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'manage_newsletter.php') {
    http_response_code(403);
    exit('Access denied.');
}
// N3: search and filter, add a subscriber (with their consent, or by sending a confirmation email),
// edit name and segments, send to one person, and bulk actions (incl. resending the confirmation).
$status_labels = ['active' => ['sent', 'Active'], 'pending' => ['awaiting', 'Awaiting confirmation'], 'unsubscribed' => ['failed', 'Unsubscribed']];
$filters = array_filter(['q' => $f_q, 'status' => $f_status, 'segment' => $f_segment ?: '']);
?>
<div class="card">
    <div class="card-header">
        <h2>👥 Subscribers</h2>
        <button type="button" class="btn btn-sm btn-primary" onclick="var d=document.getElementById('addSub');d.hidden=!d.hidden;if(!d.hidden)d.querySelector('input[type=email]').focus();"><i class="fas fa-user-plus"></i> Add subscriber</button>
    </div>
    <div class="card-body">
        <div id="addSub" hidden style="margin-bottom:18px;padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--fantasy);">
            <form method="POST" class="admin-form">
                <?php echo aw_csrf_field(); ?>
                <input type="hidden" name="action" value="add_subscriber">
                <div class="nl-row">
                    <div style="flex:2;min-width:220px;"><label for="new_email">Email</label><input type="email" id="new_email" name="email" required></div>
                    <div style="flex:2;min-width:180px;"><label for="new_name">Name (optional)</label><input type="text" id="new_name" name="name" maxlength="100"></div>
                    <div style="flex:1;min-width:160px;"><label for="new_seg">Segment</label>
                        <select id="new_seg" name="segment_id"><option value="">None</option><?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>"><?php echo htmlspecialchars($seg['name']); ?></option><?php endforeach; ?></select></div>
                </div>
                <fieldset style="border:0;padding:0;margin:10px 0;">
                    <legend class="sr-only" style="position:absolute;left:-9999px;">How to add</legend>
                    <label style="display:block;"><input type="radio" name="mode" value="confirm" checked> Send them a confirmation email (recommended – they become active when they click it)</label>
                    <label style="display:block;"><input type="radio" name="mode" value="consented"> Add as active now</label>
                    <label style="display:block;margin-left:22px;font-size:.9rem;"><input type="checkbox" name="consent" value="1"> This person agreed to receive the newsletter (e.g. signed up in person)</label>
                </fieldset>
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
        </div>

        <form method="GET" class="nl-row" style="margin-bottom:12px;" role="search">
            <input type="hidden" name="tab" value="subscribers">
            <input type="search" name="q" value="<?php echo htmlspecialchars($f_q); ?>" placeholder="Search email or name…" aria-label="Search subscribers" style="flex:1;min-width:200px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;">
            <select name="status" aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($status_labels as $k => [, $l]): ?><option value="<?php echo $k; ?>" <?php echo $f_status === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
            </select>
            <select name="segment" aria-label="Segment">
                <option value="">All segments</option>
                <?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>" <?php echo $f_segment === (int)$seg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($seg['name']); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <?php if ($filters): ?><a href="?tab=subscribers" class="btn btn-sm btn-outline">Clear</a><?php endif; ?>
        </form>
        <p class="field-hint" style="margin:0 0 10px;"><?php echo number_format($sub_total); ?> <?php echo $filters ? 'matching' : 'in total'; ?>.</p>

        <?php if ($subscribers): ?>
            <form method="POST" id="bulkSubscribersForm" onsubmit="return collectSelectedSubscribers();">
                <?php echo aw_csrf_field(); ?>
                <input type="hidden" name="action" value="bulk_subscribers">
                <input type="hidden" name="selected_ids" id="selectedSubscriberIds" value="">
                <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars((string)$v); ?>"><?php endforeach; ?>
                <div class="nl-row" style="margin-bottom:12px;">
                    <select name="bulk_action" id="bulkSubscriberAction" required aria-label="Bulk action">
                        <option value="">With selected…</option>
                        <option value="add_segment">Add to segment</option>
                        <option value="remove_segment">Remove from segment</option>
                        <option value="resend_confirmation">Resend confirmation email</option>
                        <option value="unsubscribe">Unsubscribe</option>
                        <option value="delete">Delete permanently</option>
                    </select>
                    <select name="segment_id" id="bulkSegmentId" aria-label="Segment">
                        <option value="">Segment…</option>
                        <?php foreach ($segments as $seg): ?><option value="<?php echo (int)$seg['id']; ?>"><?php echo htmlspecialchars($seg['name']); ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead><tr>
                            <th><input type="checkbox" id="selectAllSubscribers" aria-label="Select all on this page"></th>
                            <th>Subscriber</th><th>Status</th><th>Segments</th><th>Received</th><th>Last opened</th><th>Joined</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($subscribers as $sub):
                            $stt = aw_nl_status($sub); [$cls, $lbl] = $status_labels[$stt]; $mine = $segment_names_by_subscriber[$sub['id']] ?? []; ?>
                            <tr>
                                <td><input type="checkbox" class="bulk-subscriber" value="<?php echo (int)$sub['id']; ?>" aria-label="Select <?php echo htmlspecialchars($sub['email']); ?>"></td>
                                <td><strong><?php echo htmlspecialchars($sub['email']); ?></strong><?php if ($sub['name']): ?><br><small><?php echo htmlspecialchars($sub['name']); ?></small><?php endif; ?><br><small class="field-hint"><?php echo htmlspecialchars($sub['source'] ?: 'website'); ?></small></td>
                                <td><span class="status-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span><?php if ($stt === 'unsubscribed' && $sub['unsubscribed_at']): ?><br><small><?php echo date('M j, Y', strtotime($sub['unsubscribed_at'])); ?></small><?php endif; ?></td>
                                <td><?php foreach ($mine as $n): ?><span class="nl-chip"><?php echo htmlspecialchars($n); ?></span><?php endforeach; ?><?php echo $mine ? '' : '—'; ?></td>
                                <td><?php echo (int)$sub['received']; ?></td>
                                <td><?php echo $sub['last_open'] ? date('M j, Y', strtotime($sub['last_open'])) : '—'; ?></td>
                                <td><?php echo $sub['subscribed_at'] ? date('M j, Y', strtotime($sub['subscribed_at'])) : '—'; ?></td>
                                <td>
                                    <?php if ($stt === 'active'): ?><a class="btn btn-sm btn-outline" href="?tab=send&amp;to=<?php echo urlencode($sub['email']); ?>" title="Write to <?php echo htmlspecialchars($sub['email']); ?>" aria-label="Write to <?php echo htmlspecialchars($sub['email']); ?>"><i class="fas fa-envelope"></i></a><?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('edit<?php echo (int)$sub['id']; ?>').hidden = !document.getElementById('edit<?php echo (int)$sub['id']; ?>').hidden" aria-label="Edit <?php echo htmlspecialchars($sub['email']); ?>"><i class="fas fa-pen"></i></button>
                                </td>
                            </tr>
                            <tr id="edit<?php echo (int)$sub['id']; ?>" hidden><td></td><td colspan="7">
                                <div class="nl-row" data-edit-form="<?php echo (int)$sub['id']; ?>">
                                    <label>Name <input type="text" data-f="name" value="<?php echo htmlspecialchars($sub['name'] ?? ''); ?>" maxlength="100"></label>
                                    <?php foreach ($segments as $seg): ?><label><input type="checkbox" data-f="segments" value="<?php echo (int)$seg['id']; ?>" <?php echo isset($mine[$seg['id']]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($seg['name']); ?></label><?php endforeach; ?>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="saveSubscriber(<?php echo (int)$sub['id']; ?>)">Save</button>
                                </div>
                            </td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php echo aw_pager($sub_total, $sub_page, $sub_per, '', 'spage'); // P18 ?>
                </div>
            </form>
            <form method="POST" id="editSubscriberForm" hidden>
                <?php echo aw_csrf_field(); ?>
                <input type="hidden" name="action" value="edit_subscriber"><input type="hidden" name="subscriber_id"><input type="hidden" name="name">
                <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars((string)$v); ?>"><?php endforeach; ?>
            </form>
            <script>
            document.getElementById('selectAllSubscribers').addEventListener('change', function () {
                document.querySelectorAll('.bulk-subscriber').forEach(cb => { cb.checked = this.checked; });
            });
            function collectSelectedSubscribers() {
                const ids = Array.from(document.querySelectorAll('.bulk-subscriber:checked')).map(cb => cb.value);
                if (ids.length === 0) { alert('Select at least one subscriber.'); return false; }
                const action = document.getElementById('bulkSubscriberAction').value;
                if ((action === 'add_segment' || action === 'remove_segment') && !document.getElementById('bulkSegmentId').value) { alert('Choose a segment.'); return false; }
                if (action === 'delete' && !confirm('Delete ' + ids.length + ' subscriber(s) permanently? To stop emailing someone but keep the record, choose Unsubscribe instead.')) return false;
                if (action === 'unsubscribe' && !confirm('Unsubscribe ' + ids.length + ' subscriber(s)?')) return false;
                document.getElementById('selectedSubscriberIds').value = ids.join(',');
                return true;
            }
            function saveSubscriber(id) {
                const box = document.querySelector('[data-edit-form="' + id + '"]'), f = document.getElementById('editSubscriberForm');
                f.subscriber_id.value = id; f.name.value = box.querySelector('[data-f=name]').value;
                f.querySelectorAll('input[name="segments[]"]').forEach(i => i.remove());
                box.querySelectorAll('[data-f=segments]:checked').forEach(c => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'segments[]'; i.value = c.value; f.appendChild(i); });
                f.submit();
            }
            </script>
        <?php else: ?>
            <p class="no-items"><?php echo $filters ? 'No subscribers match these filters.' : 'No subscribers yet.'; ?></p>
        <?php endif; ?>
    </div>
</div>
